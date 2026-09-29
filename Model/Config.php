<?php

namespace Bluebarry\Bluebarry\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Store\Model\ScopeInterface;

/**
 * The module's settings in one place.
 *
 * Where bluebarry lives can be pointed elsewhere (a local bluebarry for development) through
 * app/etc/env.php, never from the admin:
 *
 *     'bluebarry' => ['api_url' => 'http://localhost:63104']
 */
class Config
{
    public const XML_TENANT_ID = 'bluebarry_module/general/tenantid';
    public const XML_DEBUG_LOG = 'bluebarry_module/general/write_to_debug_file';

    public const DEFAULT_API_URL = 'https://data.bluebarry.ai';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig, DeploymentConfig $deploymentConfig)
    {
        $this->scopeConfig = $scopeConfig;
        $this->deploymentConfig = $deploymentConfig;
    }

    /**
     * The tenant a store view reports to, or null when none is set.
     *
     * @param int|string|null $store
     * @return string|null
     */
    public function getTenantId($store = null): ?string
    {
        $tenantId = trim((string) $this->scopeConfig->getValue(self::XML_TENANT_ID, ScopeInterface::SCOPE_STORE, $store));
        return $tenantId === '' ? null : $tenantId;
    }

    /**
     * @param int|string|null $store
     * @return bool
     */
    public function isDebugLogEnabled($store = null): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_DEBUG_LOG, ScopeInterface::SCOPE_STORE, $store);
    }

    /**
     * bluebarry's API root, without a trailing slash.
     *
     * @return string
     */
    public function getApiUrl(): string
    {
        $url = trim((string) $this->deploymentConfig->get('bluebarry/api_url'));
        return rtrim($url !== '' ? $url : self::DEFAULT_API_URL, '/');
    }
}
