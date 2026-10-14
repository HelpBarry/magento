<?php

namespace Bluebarry\Bluebarry\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The module's settings in one place.
 *
 * Where bluebarry lives can be pointed elsewhere (a local bluebarry for development, or bluebarry's
 * staging) through app/etc/env.php, never from the admin. Each key is optional:
 *
 *     'bluebarry' => [
 *         'api_url' => 'http://localhost:63104',
 *         'advisor_url' => 'http://localhost:3000',
 *         'sdk_url' => 'https://staging.api.bluebarry.ai/sdk/{tenantId}/advisor.js',
 *     ]
 *
 * etc/csp_whitelist.xml only allows bluebarry's production hosts. A store pointed elsewhere allows the
 * other hosts in a csp_whitelist.xml of its own, as bluebarry's staging test store does.
 */
class Config
{
    public const XML_TENANT_ID = 'bluebarry_module/general/tenantid';
    public const XML_DEBUG_LOG = 'bluebarry_module/general/write_to_debug_file';
    public const XML_API_KEY = 'bluebarry_module/general/api_key';

    public const DEFAULT_API_URL = 'https://data.bluebarry.ai';
    public const DEFAULT_ADVISOR_URL = 'https://advisor.bluebarry.ai';
    public const DEFAULT_SDK_URL = 'https://cdn.bluebarry.ai/advisor/advisor.js';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param DeploymentConfig $deploymentConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(ScopeConfigInterface $scopeConfig, DeploymentConfig $deploymentConfig, EncryptorInterface $encryptor)
    {
        $this->scopeConfig = $scopeConfig;
        $this->deploymentConfig = $deploymentConfig;
        $this->encryptor = $encryptor;
    }

    /**
     * The tenant a website is connected to (one bluebarry installation per website).
     *
     * @param int|string $website
     * @return string|null
     */
    public function getWebsiteTenantId($website): ?string
    {
        $tenantId = trim((string) $this->scopeConfig->getValue(self::XML_TENANT_ID, ScopeInterface::SCOPE_WEBSITE, $website));
        return $tenantId === '' ? null : $tenantId;
    }

    /**
     * The API key a website connects with, decrypted. Never printed on a page.
     *
     * @param int|string $website
     * @return string|null
     */
    public function getWebsiteApiKey($website): ?string
    {
        $stored = (string) $this->scopeConfig->getValue(self::XML_API_KEY, ScopeInterface::SCOPE_WEBSITE, $website);
        $key = $stored !== '' ? trim($this->encryptor->decrypt($stored)) : '';
        return $key === '' ? null : $key;
    }

    /**
     * Whether a website has an API key, without decrypting it: cheap enough for every product save.
     *
     * @param int|string $website
     * @return bool
     */
    public function hasWebsiteApiKey($website): bool
    {
        return (string) $this->scopeConfig->getValue(self::XML_API_KEY, ScopeInterface::SCOPE_WEBSITE, $website) !== '';
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

    /**
     * Where the quiz runs (the advisor iframe's origin), without a trailing slash.
     *
     * @return string
     */
    public function getAdvisorUrl(): string
    {
        $url = trim((string) $this->deploymentConfig->get('bluebarry/advisor_url'));
        return rtrim($url !== '' ? $url : self::DEFAULT_ADVISOR_URL, '/');
    }

    /**
     * The SDK script a storefront loads. `{tenantId}` in a configured URL becomes the store's tenant.
     *
     * @param string $tenantId
     * @return string
     */
    public function getSdkUrl(string $tenantId): string
    {
        $url = trim((string) $this->deploymentConfig->get('bluebarry/sdk_url'));
        return $url !== '' ? str_replace('{tenantId}', rawurlencode($tenantId), $url) : self::DEFAULT_SDK_URL;
    }
}
