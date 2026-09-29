<?php

namespace Bluebarry\Bluebarry\Block;

use Bluebarry\Bluebarry\Model\Storefront;
use Magento\Csp\Helper\CspNonceProvider;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject\IdentityInterface;
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
     * @param Context $context
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param CspNonceProvider $cspNonceProvider
     * @param Storefront $storefront
     * @param array $data
     */
    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        CspNonceProvider $cspNonceProvider,
        Storefront $storefront,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->cspNonceProvider = $cspNonceProvider;
        $this->storefront = $storefront;

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
     * Whether this page is Magento's catalog search results page.
     *
     * @return bool
     */
    public function isResultsPage(): bool
    {
        $request = $this->getRequest();
        return $request instanceof \Magento\Framework\App\Request\Http
            && $request->getFullActionName() === 'catalogsearch_result_index';
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
