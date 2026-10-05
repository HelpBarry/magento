<?php

namespace Bluebarry\Bluebarry\Block;

use Bluebarry\Bluebarry\Model\Catalog\PageProduct;
use Bluebarry\Bluebarry\Model\Storefront;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
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
     * @var PageProduct
     */
    private $pageProduct;

    /**
     * @param Context $context
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param CspNonceProvider $cspNonceProvider
     * @param Storefront $storefront
     * @param Registry $registry
     * @param CategoryCollectionFactory $categories
     * @param ProductCollectionFactory $products
     * @param PageProduct $pageProduct
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
        PageProduct $pageProduct,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->cspNonceProvider = $cspNonceProvider;
        $this->storefront = $storefront;
        $this->registry = $registry;
        $this->categories = $categories;
        $this->products = $products;
        $this->pageProduct = $pageProduct;

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
     * Whether this website is connected with an API key, for the bluebarry account this store view's
     * pages are for: only then can the module vouch for a signed-in customer to bluebarry. A store view
     * given another Tenant ID than its website has no key of its own, and the website's key is for
     * another account.
     *
     * @return bool
     */
    public function hasApiKey(): bool
    {
        $store = $this->storeManager->getStore();
        $websiteTenant = (string) $this->scopeConfig->getValue(
            \Bluebarry\Bluebarry\Model\Config::XML_TENANT_ID,
            ScopeInterface::SCOPE_WEBSITE,
            $store->getWebsiteId()
        );
        return strcasecmp(trim($websiteTenant), trim((string) $this->getTenantId())) === 0
            && (string) $this->scopeConfig->getValue(
                \Bluebarry\Bluebarry\Model\Config::XML_API_KEY,
                ScopeInterface::SCOPE_WEBSITE,
                $store->getWebsiteId()
            ) !== '';
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
        // Trimmed like the Tenant ID the settings were read for.
        $tenantId = trim((string) $this->getTenantId());
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
                $reference = $this->pageProduct->defaultReference($product);
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
            // PayPal Express and Payflow Express: the order review the shopper places the order from.
            case 'paypal_express_review':
            case 'paypal_payflowexpress_review':
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
            // A configurable product is a group only with a variant the sync sends under it.
            $group = $this->pageProduct->groupId($product);
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
