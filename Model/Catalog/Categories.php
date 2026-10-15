<?php

namespace Bluebarry\Bluebarry\Model\Catalog;

use Bluebarry\Bluebarry\Model\Api\Client;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Url;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;

/**
 * The store's categories in bluebarry, where recommendations can be limited to one and search
 * suggests them. The catalog sync already names each product's categories; this sends the categories
 * themselves: their names (as the products name them), pages, images and product counts.
 *
 * One website per bluebarry company, like the catalog (Sync::targets()), and always the full set,
 * which replaces what bluebarry has. Sent from the cron when a category changed, when a company has
 * none yet (just connected, or the module was just upgraded), and once a day for the product counts,
 * and only when the set differs from what was last sent. A category save in the admin costs one flag
 * write; nothing runs on the storefront.
 */
class Categories
{
    public const FLAG = 'bluebarry_categories';

    private const LOCK = 'bluebarry_categories';

    /** As many as bluebarry takes. A store with more sends nothing: a partial set would delete the rest there. */
    private const MAX = 5000;

    /** Seconds between looks when nothing was changed in the admin: product counts move with the catalog. */
    private const DAILY = 86400;

    /**
     * @var Sync
     */
    private $sync;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var CategoryCollectionFactory
     */
    private $categories;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var FlagManager
     */
    private $flags;

    /**
     * @var LockManagerInterface
     */
    private $locks;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var TableMaintainer
     */
    private $categoryIndex;

    /** @var Url|null */
    private $storefrontUrl;

    /** @var bool a change was noted in this process already */
    private $noted = false;

    /**
     * @param Sync $sync
     * @param Client $client
     * @param CategoryCollectionFactory $categories
     * @param ResourceConnection $resource
     * @param FlagManager $flags
     * @param LockManagerInterface $locks
     * @param LoggerInterface $logger
     * @param TableMaintainer $categoryIndex
     * @param Url|null $storefrontUrl optional because a store upgrading from 2.1.0 can build this class
     *                                from 2.1.0's compiled DI
     */
    public function __construct(
        Sync $sync,
        Client $client,
        CategoryCollectionFactory $categories,
        ResourceConnection $resource,
        FlagManager $flags,
        LockManagerInterface $locks,
        LoggerInterface $logger,
        TableMaintainer $categoryIndex,
        ?Url $storefrontUrl = null
    ) {
        $this->sync = $sync;
        $this->client = $client;
        $this->categories = $categories;
        $this->resource = $resource;
        $this->flags = $flags;
        $this->locks = $locks;
        $this->logger = $logger;
        $this->categoryIndex = $categoryIndex;
        $this->storefrontUrl = $storefrontUrl;
    }

    /**
     * A category was saved, moved or deleted: the next cron run sends the set.
     *
     * @return void
     */
    public function changed(): void
    {
        if ($this->noted) {
            return; // an import saves many: noted once
        }
        $state = $this->state();
        if (empty($state['changed'])) {
            $this->flags->saveFlag(self::FLAG, ['changed' => true] + $state);
        }
        $this->noted = true;
    }

    /**
     * Sends each company its categories when they differ from what it was last sent.
     *
     * @param bool $now also when nothing was noted as changed and the daily look is not due
     * @return array{sent: int, failed: int}
     */
    public function run(bool $now = false): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        $state = $this->state();
        $targets = $this->sync->targets();
        $known = (array) ($state['sent'] ?? []);
        $new = array_filter($targets, function ($target) use ($known) {
            return !isset($known[strtolower($target['tenantId'])]);
        });
        $due = $now || !empty($state['changed']) || $new || time() - (int) ($state['looked'] ?? 0) >= self::DAILY;
        if (!$targets || !$due || !$this->locks->lock(self::LOCK, 0)) {
            return $result;
        }
        try {
            // Changes from here on count for the next run.
            $this->flags->saveFlag(self::FLAG, ['changed' => false] + $state);
            $this->noted = false;
            $sent = [];
            foreach ($targets as $target) {
                $tenant = strtolower($target['tenantId']);
                $hash = $this->send($target, $known[$tenant] ?? null);
                if ($hash === null) {
                    $result['failed']++;
                    // Kept as it was, so the next run tries again.
                    if (isset($known[$tenant])) {
                        $sent[$tenant] = $known[$tenant];
                    }
                    continue;
                }
                $result['sent'] += $hash !== ($known[$tenant] ?? null) ? 1 : 0;
                $sent[$tenant] = $hash;
            }
            $latest = $this->state();
            $this->flags->saveFlag(self::FLAG, [
                // A category saved while this ran, or a company that could not be sent, is for the next run.
                'changed' => !empty($latest['changed']) || $result['failed'] > 0,
                'sent' => $sent,
                'looked' => time(),
            ]);
        } finally {
            $this->locks->unlock(self::LOCK);
        }
        return $result;
    }

    /**
     * The website's categories as bluebarry keeps them: the active ones in its store's category tree,
     * named as the catalog sync names them on its products. Null for a store with more than bluebarry takes.
     *
     * @param Store $store
     * @return array[]|null
     */
    public function payload(Store $store): ?array
    {
        $collection = $this->categories->create();
        $collection->setStoreId((int) $store->getId())
            ->addAttributeToSelect(['name', 'description', 'image'])
            ->addAttributeToFilter('is_active', ['eq' => 1])
            // Their pages' addresses in one query, not one each.
            ->addUrlRewriteToResult()
            ->addFieldToFilter('path', ['like' => '1/' . (int) $store->getRootCategoryId() . '/%'])
            ->setOrder('entity_id', 'ASC')
            ->setPageSize(self::MAX + 1);
        if ($collection->getSize() > self::MAX) {
            return null;
        }
        // Not secure: as the cron reads it, also when the admin's Sync now comes in over https.
        $base = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, false), '/');
        // As the products name them: by their path when another category has the same name.
        $names = CategoryNames::forStore($this->categories, $store);
        $categories = [];
        foreach ($collection as $category) {
            $name = $names[(int) $category->getId()] ?? '';
            if ($name === '') {
                continue;
            }
            $path = (string) $category->getData('request_path');
            $url = $path !== '' ? $base . '/' . ltrim($path, '/') : $this->pageWithoutRewrite($category, $store);
            $image = null;
            try {
                $image = $category->getImage() ? (string) $category->getImageUrl() : null;
            } catch (\Exception $e) {
                $image = null; // a broken image is no reason to hold the categories back
            }
            $description = self::text($category->getData('description'));
            $categories[] = [
                'id' => (string) $category->getId(),
                'name' => $name,
                'description' => function_exists('mb_substr') ? mb_substr($description, 0, 2000) : substr($description, 0, 2000),
                'imageUrl' => $image !== null && $image !== '' ? self::absolute($image, $base) : null,
                'url' => $url,
                'path' => (string) parse_url($url, PHP_URL_PATH),
                'count' => 0,
            ];
        }
        $counts = $this->productCounts((int) $store->getId(), array_column($categories, 'id'));
        foreach ($categories as $i => $category) {
            $categories[$i]['count'] = (int) ($counts[(int) $category['id']] ?? 0);
        }
        return $categories;
    }

    /**
     * A category page without a URL rewrite, as Category::getUrl() names it, but always on the
     * storefront: in the admin that method builds it with the admin's URL builder.
     *
     * @param \Magento\Catalog\Model\Category $category
     * @param Store $store
     * @return string
     */
    private function pageWithoutRewrite($category, Store $store): string
    {
        $url = $this->storefrontUrl ?? ObjectManager::getInstance()->get(Url::class);
        $key = (string) $category->getUrlKey() ?: (string) $category->formatUrlKey((string) $category->getName());
        return (string) $url->setScope((int) $store->getId())->getUrl('catalog/category/view', [
            's' => $key,
            'id' => (int) $category->getId(),
            '_nosid' => true,
            '_secure' => false,
        ]);
    }

    /**
     * @param array{store: Store, tenantId: string, apiKey: string} $target
     * @param string|null $known the hash of what this company has
     * @return string|null the hash of what it has now, or null when it could not be sent
     */
    private function send(array $target, ?string $known): ?string
    {
        try {
            $categories = $this->payload($target['store']);
        } catch (\Exception $e) {
            $this->logger->warning('bluebarry: could not read the categories: ' . $e->getMessage(), ['tenant' => $target['tenantId']]);
            return null;
        }
        if ($categories === null) {
            $this->logger->warning('bluebarry: more than ' . self::MAX . ' categories; they are not sent.', ['tenant' => $target['tenantId']]);
            return $known ?? 'too-many';
        }
        $hash = sha1((string) json_encode($categories));
        if ($hash === $known) {
            return $hash;
        }
        // The company is named, as the catalog sync names it: a key from another company would
        // otherwise put this store's categories in the place of that company's.
        $response = $this->client->post(
            '/data/magento/categories',
            ['tenantId' => $target['tenantId'], 'categories' => $categories],
            $target['tenantId'],
            $target['apiKey'],
            60
        );
        if (!$response->isSuccess()) {
            // A bluebarry from before categories answers 404: nothing to retry until it is updated.
            if ($response->getStatus() !== 404) {
                $this->logger->warning('bluebarry: could not send the categories: ' . ($response->getError() ?? 'HTTP ' . $response->getStatus()), ['tenant' => $target['tenantId']]);
            }
            return null;
        }
        return $hash;
    }

    /**
     * How many products each category shows in the store view, as its page counts them: from Magento's
     * category product index, so an anchor category counts its subcategories' products and a disabled
     * product, one of another website or one not visible in the catalog counts nowhere. One grouped query.
     *
     * @param int $storeId
     * @param array<int|string> $categoryIds
     * @return array<int, int>
     */
    private function productCounts(int $storeId, array $categoryIds): array
    {
        if (!$categoryIds) {
            return [];
        }
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchPairs(
            $connection->select()
                ->from($this->categoryIndex->getMainTable($storeId), ['category_id', 'products' => new \Zend_Db_Expr('COUNT(*)')])
                ->where('store_id = ?', $storeId)
                ->where('category_id IN (?)', array_map('intval', $categoryIds))
                ->where('visibility IN (?)', [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH])
                ->group('category_id')
        ));
    }

    /**
     * The same reading of a name as the catalog sync's (ProductBuilder::text()), so a product's
     * category is found by it.
     *
     * @param mixed $value
     * @return string
     */
    private static function text($value): string
    {
        return trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8'));
    }

    /**
     * @param string $url
     * @param string $base
     * @return string
     */
    private static function absolute(string $url, string $base): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $scheme = (string) parse_url($base, PHP_URL_SCHEME);
        $host = (string) parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT);
        return $scheme . '://' . $host . ($port ? ':' . $port : '') . '/' . ltrim($url, '/');
    }

    /**
     * @return array
     */
    private function state(): array
    {
        $state = $this->flags->getFlagData(self::FLAG);
        return is_array($state) ? $state : [];
    }
}
