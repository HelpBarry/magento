<?php

namespace Bluebarry\Bluebarry\Block;

use Bluebarry\Bluebarry\Model\Storefront;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableLinks;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Csp\Helper\CspNonceProvider;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The storefront's bluebarry setup in the page head: the SDK, the platform and cart endpoints, and
 * search as chosen in Studio. Its pages carry Storefront::CACHE_TAG, so they leave the page cache when
 * those settings change.
 */
class Advisor extends Template implements IdentityInterface
{
    /** Magento's search box, Luma's and Hyvä's alike. */
    private const SEARCH_SELECTOR = 'input#search, input[name="q"]';

    /**
     * The results page's content to take over: the main column and its sidebars, so Magento's own
     * filters do not sit next to bluebarry's.
     */
    private const RESULTS_HOST = '#maincontent .columns';

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\Csp\Helper\CspNonceProvider
     */
    protected $cspNonceProvider;

    /**
     * @var Storefront
     */
    private $storefront;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var CategoryCollectionFactory
     */
    private $categories;

    /**
     * @var ProductCollectionFactory
     */
    private $products;

    /**
     * @var ConfigurableLinks
     */
    private $configurableLinks;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /** @var array<int, int|null> child => the configurable product the catalog sync groups it under */
    private $syncedParents = [];

    /**
     * @param Context $context
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param CspNonceProvider $cspNonceProvider
     * @param Storefront $storefront
     * @param Registry $registry
     * @param CategoryCollectionFactory $categories
     * @param ProductCollectionFactory $products
     * @param ConfigurableLinks $configurableLinks
     * @param MetadataPool $metadataPool
     * @param array $data
     */
    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        CspNonceProvider $cspNonceProvider,
        Storefront $storefront,
        Registry $registry,
        CategoryCollectionFactory $categories,
        ProductCollectionFactory $products,
        ConfigurableLinks $configurableLinks,
        MetadataPool $metadataPool,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->cspNonceProvider = $cspNonceProvider;
        $this->storefront = $storefront;
        $this->registry = $registry;
        $this->categories = $categories;
        $this->products = $products;
        $this->configurableLinks = $configurableLinks;
        $this->metadataPool = $metadataPool;

        parent::__construct($context, $data);
    }

    /**
     * Returns the tenant id from the settings from the current scope
     *
     * @return null|string
     */
    public function getTenantId() : ?string
    {
        return $this->scopeConfig->getValue("bluebarry_module/general/tenantid", 'stores', $this->storeManager->getStore()->getCode());
    }

    /**
     * Get CSP Nonce
     *
     * @return string
     */
    public function getNonce(): string
    {
        return $this->cspNonceProvider->generateNonce();
    }

    /**
     * window.barry.search for this page, or null while search is off for this website.
     *
     * @return array|null
     */
    public function getSearchConfig(): ?array
    {
        $tenantId = (string) $this->getTenantId();
        $search = $tenantId === '' ? null : $this->storefront->search($this->storeManager->getStore()->getWebsiteId(), $tenantId);
        if ($search === null) {
            return null;
        }
        $config = ['searchProfileId' => $search['profileId'], 'searchSelector' => self::SEARCH_SELECTOR];
        if ($search['resultsPage']) {
            $config['serpPage'] = [
                'enabled' => true,
                // "See all results" goes here with ?q=..., the parameter Magento's own search uses.
                'path' => $this->getUrl('catalogsearch/result'),
                'here' => $this->isResultsPage(),
                'hostSelector' => self::RESULTS_HOST,
            ];
        }
        return $config;
    }

    /**
     * The page as popups, product chat and the page tracker read it: only what the address decides,
     * so a page from the full page cache is right for every shopper. The cart comes from the shopper's
     * own customer data in the browser (the SDK).
     *
     * @return array
     */
    public function getPageContext(): array
    {
        switch ($this->fullActionName()) {
            case 'catalog_product_view':
                $product = $this->registry->registry('current_product');
                if (!$product) {
                    return ['type' => 'other'];
                }
                // Popup product rules name the product, like Shopify's product id.
                $page = ['type' => 'product', 'productId' => (string) $product->getId()];
                $reference = $this->defaultReference($product);
                return $reference === null ? $page : $page + ['productReference' => $reference];
            case 'catalog_category_view':
                $category = $this->registry->registry('current_category');
                return ['type' => 'collection'] + ($category ? ['collectionId' => (string) $category->getId()] : []);
            case 'catalogsearch_result_index':
            case 'catalogsearch_advanced_result':
                return ['type' => 'search'];
            case 'checkout_cart_index':
                return ['type' => 'cart'];
            case 'checkout_index_index':
                return ['type' => 'checkout'];
            default:
                // Multi-address checkout's steps, up to its order confirmation.
                $action = $this->fullActionName();
                return strpos($action, 'multishipping_checkout_') === 0 && $action !== 'multishipping_checkout_success'
                    ? ['type' => 'checkout']
                    : ['type' => 'other'];
        }
    }

    /**
     * Product chat's page: the product (and its variant) as the catalog sync names them, or the category.
     *
     * @return array
     */
    public function getChatConfig(): array
    {
        $page = $this->getPageContext();
        $context = ['pageType' => $page['type'] === 'collection' ? 'collection' : ($page['type'] === 'product' ? 'product' : 'other')];
        $config = [];
        if ($page['type'] === 'product') {
            $product = $this->registry->registry('current_product');
            // The product as the catalog sync sends it: a variant with a page of its own is still
            // grouped under its configurable product, with that product's categories.
            $group = $product->getTypeId() === 'configurable' ? (int) $product->getId() : $this->syncedParentId((int) $product->getId());
            if ($group !== null) {
                $config['groupReference'] = (string) $group;
            }
            if (isset($page['productReference'])) {
                $config['variantReference'] = $page['productReference'];
            }
            $categoryIds = $group !== null && $group !== (int) $product->getId()
                ? (array) $this->products->create()->addIdFilter([$group])->addCategoryIds()->getFirstItem()->getCategoryIds()
                : (array) $product->getCategoryIds();
            $context['productCollectionIds'] = $this->collectionIds($categoryIds);
        } elseif ($page['type'] === 'collection') {
            $category = $this->registry->registry('current_category');
            if ($category) {
                $context['collection'] = (string) $category->getName();
                $context['collectionId'] = (string) $category->getId();
            }
        }
        return $config + ['context' => $context];
    }

    /**
     * The website id Magento's cookie restriction mode keys the shopper's consent by, or null when the
     * mode is off (then tracking needs no consent from Magento).
     *
     * @return int|null
     */
    public function getCookieConsentWebsite(): ?int
    {
        $store = $this->storeManager->getStore();
        return $this->scopeConfig->isSetFlag('web/cookie/cookie_restriction', ScopeInterface::SCOPE_STORE, $store->getId())
            ? (int) $store->getWebsiteId()
            : null;
    }

    /**
     * The variant a product page opens with, for its product view: a configurable product's first
     * variant for sale (by id, as the catalog sync orders them) of the ones the sync groups under it
     * (a variant of several configurable products goes under one), else its first; any other product
     * itself. None for a configurable product without such variants or a grouped product: the catalog
     * sync sends neither, only their products.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string|null
     */
    private function defaultReference($product): ?string
    {
        if ($product->getTypeId() === 'grouped') {
            return null;
        }
        if ($product->getTypeId() !== 'configurable') {
            return (string) $product->getId();
        }
        // The page's own options already loaded these (the configurable type caches them on the product).
        $type = $product->getTypeInstance();
        $children = $type instanceof \Magento\ConfigurableProduct\Model\Product\Type\Configurable ? $type->getUsedProducts($product) : [];
        usort($children, function ($a, $b) {
            return (int) $a->getId() <=> (int) $b->getId();
        });
        $groups = $this->syncedParents(array_map(function ($child) {
            return (int) $child->getId();
        }, $children));
        $own = array_values(array_filter($children, function ($child) use ($product, $groups) {
            return ($groups[(int) $child->getId()] ?? null) === (int) $product->getId();
        }));
        foreach ($own as $child) {
            if ($child instanceof \Magento\Catalog\Model\Product && $child->isSalable()) {
                return (string) $child->getId();
            }
        }
        // Without variants there is nothing the catalog sync sends for it.
        return $own ? (string) $own[0]->getId() : null;
    }

    /**
     * The configurable product the catalog sync groups a product under: its lowest parent that is
     * enabled, has a page and is in this website (ProductBuilder::build()), or null.
     *
     * @param int $childId
     * @return int|null
     */
    private function syncedParentId(int $childId): ?int
    {
        return $this->syncedParents([$childId])[$childId] ?? null;
    }

    /**
     * syncedParentId() for many products at once: two queries however many there are.
     *
     * @param int[] $childIds
     * @return array<int, int|null>
     */
    private function syncedParents(array $childIds): array
    {
        $missing = array_values(array_diff($childIds, array_keys($this->syncedParents)));
        if ($missing) {
            foreach ($missing as $id) {
                $this->syncedParents[$id] = null;
            }
            $connection = $this->configurableLinks->getConnection();
            $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
            $entities = $this->configurableLinks->getTable('catalog_product_entity');
            $select = $connection->select()
                ->from(['link' => $this->configurableLinks->getTable('catalog_product_super_link')], ['child' => 'product_id'])
                ->join(['parent' => $entities], "parent.$linkField = link.parent_id", ['parent' => 'entity_id'])
                ->where('link.product_id IN (?)', $missing);
            if ($linkField !== 'entity_id' && $connection->tableColumnExists($entities, 'created_in')) {
                // Content staging: the version live now, as the catalog sync reads it.
                $now = time();
                $select->where('parent.created_in <= ?', $now)->where('parent.updated_in > ?', $now);
            }
            $links = $connection->fetchAll($select);
            $parentIds = array_values(array_unique(array_map('intval', array_column($links, 'parent'))));
            if ($parentIds) {
                $store = $this->storeManager->getStore();
                $shown = array_flip(array_map('intval', $this->products->create()
                    ->setStoreId((int) $store->getId())
                    ->addIdFilter($parentIds)
                    ->addWebsiteFilter([(int) $store->getWebsiteId()])
                    ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
                    ->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE])
                    ->getAllIds()));
                foreach ($links as $link) {
                    $child = (int) $link['child'];
                    $parent = (int) $link['parent'];
                    if (isset($shown[$parent]) && ($this->syncedParents[$child] === null || $parent < $this->syncedParents[$child])) {
                        $this->syncedParents[$child] = $parent;
                    }
                }
            }
        }
        return array_intersect_key($this->syncedParents, array_flip($childIds));
    }

    /**
     * The product's active categories in this store's category tree, as the catalog sync sends them:
     * category assignments are shared by every website.
     *
     * @param array $ids the product's category ids
     * @return string[]
     */
    private function collectionIds(array $ids): array
    {
        $store = $this->storeManager->getStore();
        if (!$ids || !$store instanceof \Magento\Store\Model\Store) {
            return [];
        }
        return array_map('strval', $this->categories->create()
            ->setStoreId((int) $store->getId())
            ->addIdFilter($ids)
            ->addAttributeToFilter('path', ['like' => '1/' . (int) $store->getRootCategoryId() . '/%'])
            ->addAttributeToFilter('is_active', ['eq' => 1])
            ->getAllIds(100));
    }

    /**
     * @return string
     */
    private function fullActionName(): string
    {
        $request = $this->getRequest();
        return $request instanceof \Magento\Framework\App\Request\Http ? (string) $request->getFullActionName() : '';
    }

    /**
     * Where the checkout page tells the module the shopper's email (Controller\Checkout\Email), or
     * null on any other page.
     *
     * @return string|null
     */
    public function getCheckoutEmailUrl(): ?string
    {
        // The one-page checkout and multi-address checkout's steps.
        return $this->getPageContext()['type'] === 'checkout' ? $this->getUrl('bluebarry/checkout/email') : null;
    }

    /**
     * Whether this page is Magento's catalog search results page.
     *
     * @return bool
     */
    public function isResultsPage(): bool
    {
        return $this->fullActionName() === 'catalogsearch_result_index';
    }

    /**
     * The selector of what the results page takeover hides until bluebarry has mounted.
     *
     * @return string
     */
    public function getResultsHost(): string
    {
        return self::RESULTS_HOST;
    }

    /**
     * @return string[]
     */
    public function getIdentities()
    {
        return [Storefront::cacheTag((int) $this->storeManager->getStore()->getWebsiteId())];
    }
}
