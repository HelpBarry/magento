<?php

namespace Bluebarry\Bluebarry\Block;

use Bluebarry\Bluebarry\Model\Storefront;
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
     * @param Context $context
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param CspNonceProvider $cspNonceProvider
     * @param Storefront $storefront
     * @param Registry $registry
     * @param array $data
     */
    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        CspNonceProvider $cspNonceProvider,
        Storefront $storefront,
        Registry $registry,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->cspNonceProvider = $cspNonceProvider;
        $this->storefront = $storefront;
        $this->registry = $registry;

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
                return $product ? [
                    'type' => 'product',
                    // Popup product rules name the product, like Shopify's product id.
                    'productId' => (string) $product->getId(),
                    'productReference' => $this->defaultReference($product),
                ] : ['type' => 'other'];
            case 'catalog_category_view':
                $category = $this->registry->registry('current_category');
                return ['type' => 'collection'] + ($category ? ['collectionId' => (string) $category->getId()] : []);
            case 'catalogsearch_result_index':
                return ['type' => 'search'];
            case 'checkout_cart_index':
                return ['type' => 'cart'];
            case 'checkout_index_index':
                return ['type' => 'checkout'];
            default:
                return ['type' => 'other'];
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
            if ($product->getTypeId() === 'configurable') {
                $config['groupReference'] = (string) $product->getId();
            }
            $config['variantReference'] = $page['productReference'];
            $context['productCollectionIds'] = array_map('strval', array_slice((array) $product->getCategoryIds(), 0, 100));
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
     * variant for sale (by id, as the catalog sync orders them), else its first; any other product itself.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string
     */
    private function defaultReference($product): string
    {
        if ($product->getTypeId() !== 'configurable') {
            return (string) $product->getId();
        }
        // The page's own options already loaded these (the configurable type caches them on the product).
        $type = $product->getTypeInstance();
        $children = $type instanceof \Magento\ConfigurableProduct\Model\Product\Type\Configurable ? $type->getUsedProducts($product) : [];
        usort($children, function ($a, $b) {
            return (int) $a->getId() <=> (int) $b->getId();
        });
        foreach ($children as $child) {
            if ($child instanceof \Magento\Catalog\Model\Product && $child->isSalable()) {
                return (string) $child->getId();
            }
        }
        return $children ? (string) $children[0]->getId() : (string) $product->getId();
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
        return [Storefront::CACHE_TAG];
    }
}
