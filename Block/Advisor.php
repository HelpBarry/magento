<?php

namespace Bluebarry\Bluebarry\Block;

use Magento\Framework\App\Config\ScopeConfigInterface;

use Magento\Store\Model\StoreManagerInterface;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Csp\Helper\CspNonceProvider;

class Advisor extends Template
{	
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

    public function __construct(
        Context $context, 
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager, 
        CspNonceProvider $cspNonceProvider,
        array $data = [])
    {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->cspNonceProvider = $cspNonceProvider;

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
}