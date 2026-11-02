<?php

namespace Bluebarry\Bluebarry\Model;

use Bluebarry\Bluebarry\Model\Api\Client;
use Bluebarry\Bluebarry\Model\Catalog\Sync;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The collection pages chosen in bluebarry for this store's categories. bluebarry renders them; they are
 * kept here, fetched in the background by the cron, so a shopper's page never waits for bluebarry and a
 * page from the full page cache stays what it is. Only in the store view the catalog is read in, one per
 * bluebarry company (Sync::targets()): that is the only one whose names, prices and links they show.
 *
 * bluebarry's list of categories decides what has a page: a bad answer changes nothing. A page that cannot
 * be fetched stays what it was, and is tried again in its turn. A page that changed leaves the page
 * cache by its tag, and by the category's own: a page cached before the category had a collection page
 * only carries that one.
 */
class CollectionPages
{
    public const TABLE = 'bluebarry_collection_page';

    /** The cache tag of a category's page in a store view. */
    public const CACHE_TAG = 'bluebarry_collection_page';

    private const FLAG = 'bluebarry_collection_pages';

    private const LOCK = 'bluebarry_collection_pages';

    /** How often bluebarry's list of categories is read. */
    private const LIST_EVERY = 300;

    /** How long after its last check a page is due. A check interval: a large store takes longer to come round. */
    private const CHECK_EVERY = 900;

    /** At most this many pages a run, and for at most this long (the cron's own limits in 2.1.0). */
    private const BATCH = 50;

    private const BUDGET = 40;

    /** A page that rendered old content during a change can reach the page cache after its purge: purged once more, later. */
    private const SETTLE = 60;

    private const LIST_TIMEOUT = 10;

    private const FETCH_TIMEOUT = 10;

    private const MAX_BYTES = 2097152;

    /**
     * @var Sync
     */
    private $sync;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var EventManager
     */
    private $events;

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
     * @param Sync $sync
     * @param Client $client
     * @param Config $config
     * @param ResourceConnection $resource
     * @param CacheInterface $cache
     * @param EventManager $events
     * @param FlagManager $flags
     * @param LockManagerInterface $locks
     * @param LoggerInterface $logger
     */
    public function __construct(
        Sync $sync,
        Client $client,
        Config $config,
        ResourceConnection $resource,
        CacheInterface $cache,
        EventManager $events,
        FlagManager $flags,
        LockManagerInterface $locks,
        LoggerInterface $logger
    ) {
        $this->sync = $sync;
        $this->client = $client;
        $this->config = $config;
        $this->resource = $resource;
        $this->cache = $cache;
        $this->events = $events;
        $this->flags = $flags;
        $this->locks = $locks;
        $this->logger = $logger;
    }

    /**
     * What a category shows in a store view, as pages read it: from Magento's cache, so a category without
     * a page costs no query.
     *
     * @param int $storeId
     * @param int $categoryId
     * @return array{tenant: string, title: bool, description: bool}|null
     */
    public function active(int $storeId, int $categoryId): ?array
    {
        return $this->activeSet($storeId)[$categoryId] ?? null;
    }

    /**
     * The page and its stylesheets, for the one block that prints them.
     *
     * @param int $storeId
     * @param int $categoryId
     * @return array{html: string, css: string}|null
     */
    public function page(int $storeId, int $categoryId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName(self::TABLE), ['html', 'css'])
                ->where('store_id = ?', $storeId)->where('category_id = ?', $categoryId)->where('removed = 0')
        );
        return $row && is_string($row['html']) && $row['html'] !== ''
            ? ['html' => $row['html'], 'css' => (string) $row['css']]
            : null;
    }

    /**
     * The merchant may have changed something: the next run reads the list, and fetches every page.
     *
     * @return void
     */
    public function markDue(): void
    {
        $this->flags->saveFlag(self::FLAG, ['listed' => [], 'allDue' => true]);
    }

    /**
     * The cron: what no longer belongs here goes, each company's list is read when that is due, and the pages that are due are fetched.
     *
     * @return array{fetched: int, changed: int}
     */
    public function run(): array
    {
        $result = ['fetched' => 0, 'changed' => 0];
        if (!$this->locks->lock(self::LOCK, 0)) {
            return $result;
        }
        try {
            $started = time();
            $this->settle();
            $targets = $this->sync->targets();
            $this->removeOthers($targets);
            $state = $this->flags->getFlagData(self::FLAG);
            $state = is_array($state) ? $state : [];
            $listed = (array) ($state['listed'] ?? []);
            $allDue = !empty($state['allDue']);
            foreach ($targets as $target) {
                $store = (int) $target['store']->getId();
                $tenant = strtolower($target['tenantId']);
                if (time() - (int) ($listed[$tenant] ?? 0) >= self::LIST_EVERY) {
                    $listed[$tenant] = time();
                    $this->reconcile($target, $store, $tenant);
                }
            }
            if ($allDue) {
                $connection = $this->resource->getConnection();
                $connection->update($this->resource->getTableName(self::TABLE), ['checked_at' => null], 'removed = 0');
            }
            if ($allDue || $listed !== ($state['listed'] ?? [])) {
                // Reset before the fetches: a change noted from here on counts for the next run.
                $this->flags->saveFlag(self::FLAG, ['listed' => $listed, 'allDue' => false]);
            }
            $this->fetchDue($result, $started);
        } catch (\Exception $e) {
            $this->logger->warning('bluebarry: refreshing collection pages failed: ' . $e->getMessage());
        } finally {
            $this->locks->unlock(self::LOCK);
        }
        return $result;
    }

    /**
     * Pages of a store view that is no longer its company's catalog, or of a company it is no longer connected to.
     *
     * @param array<int, array{store: \Magento\Store\Model\Store, tenantId: string, apiKey: string}> $targets
     * @return void
     */
    private function removeOthers(array $targets): void
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()->from($this->resource->getTableName(self::TABLE), ['store_id', 'category_id', 'tenant_id'])->where('removed = 0');
        $current = [];
        foreach ($targets as $target) {
            $current[(int) $target['store']->getId() . ':' . strtolower($target['tenantId'])] = true;
        }
        foreach ($connection->fetchAll($select) as $row) {
            if (!isset($current[(int) $row['store_id'] . ':' . strtolower((string) $row['tenant_id'])])) {
                $this->remove((int) $row['store_id'], (int) $row['category_id']);
            }
        }
    }

    /**
     * Keeps the rows in step with bluebarry's list. Only a complete, well-formed answer changes anything.
     *
     * @param array{store: \Magento\Store\Model\Store, tenantId: string, apiKey: string} $target
     * @param int $store
     * @param string $tenant
     * @return void
     */
    private function reconcile(array $target, int $store, string $tenant): void
    {
        $response = $this->client->get('/data/magento/collection-pages', $target['apiKey'], self::LIST_TIMEOUT);
        $answer = $response->isSuccess() ? json_decode($response->getBody(), true) : null;
        // A key of another company than this store view reports to gets that company's list: not this one's.
        if (!is_array($answer) || !is_array($answer['pages'] ?? null) || strtolower((string) ($answer['tenantId'] ?? '')) !== $tenant) {
            return;
        }
        $wanted = [];
        foreach ($answer['pages'] as $page) {
            $category = is_array($page) ? (string) ($page['categoryId'] ?? '') : '';
            $url = is_array($page) ? (string) ($page['url'] ?? '') : '';
            if (!ctype_digit($category) || !$this->isOurAddress($url)) {
                return; // not a list this module understands: what it has stays
            }
            $wanted[(int) $category] = $url;
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $rows = $connection->fetchAssoc(
            $connection->select()->from($table, ['category_id', 'url', 'removed'])->where('store_id = ?', $store)
        );
        foreach ($rows as $category => $row) {
            if (!isset($wanted[(int) $category]) && !(int) $row['removed']) {
                $this->remove($store, (int) $category);
            }
        }
        foreach ($wanted as $category => $url) {
            $row = $rows[$category] ?? null;
            if ($row === null || (int) $row['removed'] || $row['url'] !== $url) {
                $connection->insertOnDuplicate($table, [
                    'store_id' => $store, 'category_id' => $category, 'tenant_id' => $tenant, 'url' => $url, 'removed' => 0, 'checked_at' => null,
                ], ['tenant_id', 'url', 'removed', 'checked_at']);
            }
        }
    }

    /**
     * bluebarry's collection host for the API this module talks to, exactly: https, no other host.
     *
     * @param string $url
     * @return bool
     */
    private function isOurAddress(string $url): bool
    {
        $apiHost = (string) parse_url($this->config->getApiUrl(), PHP_URL_HOST);
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['port'])
            && strpos($apiHost, 'data.') !== false && ($parts['host'] ?? '') === str_replace('data.', 'collections.', $apiHost);
    }

    /**
     * Fetches the longest-unchecked pages. Every attempt counts as a check, so one that keeps failing never holds the others up.
     *
     * @param array{fetched: int, changed: int} $result
     * @param int $started
     * @return void
     */
    private function fetchDue(array &$result, int $started): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $due = $connection->fetchAll(
            $connection->select()->from($table, ['store_id', 'category_id', 'url', 'hash'])
                ->where('removed = 0')
                ->where('checked_at IS NULL OR checked_at <= ?', gmdate('Y-m-d H:i:s', time() - self::CHECK_EVERY))
                ->order('checked_at ASC') // never checked first: MySQL sorts NULL first
                ->limit(self::BATCH)
        );
        foreach ($due as $row) {
            if (time() - $started >= self::BUDGET) {
                return;
            }
            $where = ['store_id = ?' => (int) $row['store_id'], 'category_id = ?' => (int) $row['category_id']];
            $page = $this->fetch((string) $row['url']);
            $result['fetched']++;
            $now = gmdate('Y-m-d H:i:s');
            if ($page === null || $page['hash'] === $row['hash']) {
                $connection->update($table, ['checked_at' => $now], $where);
                continue;
            }
            $connection->update($table, [
                'html' => $page['html'], 'css' => $page['css'], 'hash' => $page['hash'],
                'replaces_title' => $page['title'] ? 1 : 0, 'replaces_description' => $page['description'] ? 1 : 0,
                'checked_at' => $now, 'purge_again_at' => gmdate('Y-m-d H:i:s', time() + self::SETTLE),
            ], $where);
            $this->cache->remove(self::setKey((int) $row['store_id']));
            $this->purge((int) $row['store_id'], (int) $row['category_id']);
            $result['changed']++;
        }
    }

    /**
     * The page bluebarry answers, or null for anything but a whole one.
     *
     * @param string $url
     * @return array{html: string, css: string, hash: string, title: bool, description: bool}|null
     */
    private function fetch(string $url): ?array
    {
        if (!$this->isOurAddress($url)) {
            return null;
        }
        $response = $this->client->getPublic($url, self::FETCH_TIMEOUT, self::MAX_BYTES);
        $answer = $response->isSuccess() ? json_decode($response->getBody(), true) : null;
        if (!is_array($answer) || !is_string($answer['html'] ?? null) || !is_string($answer['css'] ?? null)
            || !is_string($answer['hash'] ?? null) || strlen($answer['hash']) !== 64
            || strpos($answer['html'], 'data-bluebarry-collection-grid') === false) {
            return null;
        }
        return [
            'html' => $answer['html'],
            'css' => $answer['css'],
            'hash' => $answer['hash'],
            'title' => ($answer['replaces']['title'] ?? false) === true,
            'description' => ($answer['replaces']['description'] ?? false) === true,
        ];
    }

    /**
     * A page that is gone shows nothing from now on, and leaves the page cache now and once more later.
     *
     * @param int $store
     * @param int $category
     * @return void
     */
    private function remove(int $store, int $category): void
    {
        $this->resource->getConnection()->update($this->resource->getTableName(self::TABLE), [
            'removed' => 1, 'html' => null, 'css' => null, 'hash' => null,
            'purge_again_at' => gmdate('Y-m-d H:i:s', time() + self::SETTLE),
        ], ['store_id = ?' => $store, 'category_id = ?' => $category]);
        $this->cache->remove(self::setKey($store));
        $this->purge($store, $category);
    }

    /**
     * The second purge of what changed a while ago, and with it the end of what was removed.
     *
     * @return void
     */
    private function settle(): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $rows = $connection->fetchAll(
            $connection->select()->from($table, ['store_id', 'category_id', 'removed'])
                ->where('purge_again_at IS NOT NULL')->where('purge_again_at <= ?', gmdate('Y-m-d H:i:s'))
        );
        foreach ($rows as $row) {
            $where = ['store_id = ?' => (int) $row['store_id'], 'category_id = ?' => (int) $row['category_id']];
            // A shopper's request that read the set during the change can have saved it after the change cleared it.
            $this->cache->remove(self::setKey((int) $row['store_id']));
            $this->purge((int) $row['store_id'], (int) $row['category_id']);
            if ((int) $row['removed']) {
                $connection->delete($table, $where);
            } else {
                $connection->update($table, ['purge_again_at' => null], $where);
            }
        }
    }

    /**
     * The category's pages leave the built-in page cache and Varnish: the same event Magento's own saves use.
     *
     * @param int $store
     * @param int $category
     * @return void
     */
    private function purge(int $store, int $category): void
    {
        $tags = [self::cacheTag($store, $category), 'cat_c_' . $category, 'cat_c_p_' . $category];
        $this->events->dispatch('clean_cache_by_tags', ['object' => new class ($tags) extends DataObject implements IdentityInterface {
            /**
             * @var string[]
             */
            private $tags;

            /**
             * @param string[] $tags
             */
            public function __construct(array $tags)
            {
                parent::__construct();
                $this->tags = $tags;
            }

            /**
             * @return string[]
             */
            public function getIdentities()
            {
                return $this->tags;
            }
        }]);
    }

    /**
     * The tag of a category's collection page in a store view.
     *
     * @param int $store
     * @param int $category
     * @return string
     */
    public static function cacheTag(int $store, int $category): string
    {
        return self::CACHE_TAG . '_' . $store . '_' . $category;
    }

    /**
     * @param int $store
     * @return string
     */
    private static function setKey(int $store): string
    {
        return self::FLAG . '_' . $store;
    }

    /**
     * The categories with something to show in a store view.
     *
     * @param int $store
     * @return array<int, array{tenant: string, title: bool, description: bool}>
     */
    private function activeSet(int $store): array
    {
        $key = self::setKey($store);
        $cached = $this->cache->load($key);
        if (is_string($cached) && is_array($decoded = json_decode($cached, true))) {
            return $decoded;
        }
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()->from($this->resource->getTableName(self::TABLE), ['category_id', 'tenant_id', 'replaces_title', 'replaces_description'])
                ->where('store_id = ?', $store)->where('removed = 0')->where('html IS NOT NULL')
        );
        $set = [];
        foreach ($rows as $row) {
            $set[(int) $row['category_id']] = [
                'tenant' => (string) $row['tenant_id'],
                'title' => (bool) $row['replaces_title'],
                'description' => (bool) $row['replaces_description'],
            ];
        }
        $this->cache->save((string) json_encode($set), $key, [ConfigCache::CACHE_TAG]);
        return $set;
    }
}
