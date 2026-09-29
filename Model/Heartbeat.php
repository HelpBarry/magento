<?php

namespace Bluebarry\Bluebarry\Model;

use Bluebarry\Bluebarry\Model\Api\Client;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Tells bluebarry which Magento websites run the module: one installation per website, connected
 * with the website's Tenant ID and API key. Sent when the settings are saved, after a module upgrade,
 * and daily from Magento's cron. The outcome per website is kept for the settings page.
 */
class Heartbeat
{
    public const FLAG = 'bluebarry_heartbeat';

    /** A website is pinged again after this many seconds, or sooner when forced. */
    private const INTERVAL = 86400;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var ModuleListInterface
     */
    private $moduleList;

    /**
     * @var FlagManager
     */
    private $flags;

    /**
     * @param Config $config
     * @param Client $client
     * @param StoreManagerInterface $storeManager
     * @param ProductMetadataInterface $productMetadata
     * @param ModuleListInterface $moduleList
     * @param FlagManager $flags
     */
    public function __construct(
        Config $config,
        Client $client,
        StoreManagerInterface $storeManager,
        ProductMetadataInterface $productMetadata,
        ModuleListInterface $moduleList,
        FlagManager $flags
    ) {
        $this->config = $config;
        $this->client = $client;
        $this->storeManager = $storeManager;
        $this->productMetadata = $productMetadata;
        $this->moduleList = $moduleList;
        $this->flags = $flags;
    }

    /**
     * Pings every connected website whose last heartbeat is older than a day, or all of them after
     * forceNext() (a module upgrade). Called from the cron.
     *
     * @return void
     */
    public function sendDue(): void
    {
        $state = $this->state();
        $force = !empty($state['force']);
        foreach ($this->storeManager->getWebsites() as $website) {
            $last = $state['websites'][(int) $website->getId()]['at'] ?? 0;
            if ($force || time() - $last >= self::INTERVAL) {
                $this->send($website);
            }
        }
        if ($force) {
            $state = $this->state();
            unset($state['force']);
            $this->flags->saveFlag(self::FLAG, $state);
        }
    }

    /**
     * Pings one website now. Does nothing for a website without a Tenant ID or API key.
     *
     * @param WebsiteInterface $website
     * @return array|null the outcome, or null when the website isn't connected
     */
    public function send(WebsiteInterface $website): ?array
    {
        $websiteId = (int) $website->getId();
        try {
            return $this->ping($website);
        } catch (\Exception $e) {
            // An undecryptable key or a website without a store: recorded like a refused heartbeat, so
            // the Connection status shows it and the other websites still report in. Tried again in a
            // day, or when its settings are saved.
            $outcome = ['at' => time(), 'site' => '', 'ok' => false, 'status' => 0, 'error' => $e->getMessage()];
            $this->record($websiteId, $outcome);
            return $outcome;
        }
    }

    /**
     * @param WebsiteInterface $website
     * @return array|null
     */
    private function ping(WebsiteInterface $website): ?array
    {
        $websiteId = (int) $website->getId();
        $tenantId = $this->config->getWebsiteTenantId($websiteId);
        $apiKey = $this->config->getWebsiteApiKey($websiteId);
        if ($tenantId === null || $apiKey === null) {
            $this->record($websiteId, null);
            return null;
        }

        $siteUrl = $this->siteUrl($website);
        $response = $this->client->post('/data/magento/ping', [
            // bluebarry refuses a key from another company before it registers anything.
            'tenantId' => $tenantId,
            'siteUrl' => $siteUrl,
            'siteName' => (string) $website->getName(),
            'moduleVersion' => (string) ($this->moduleList->getOne('Bluebarry_Bluebarry')['setup_version'] ?? ''),
            'magentoVersion' => $this->productMetadata->getVersion(),
            'magentoEdition' => $this->productMetadata->getEdition(),
            // Where bluebarry asks this website to reload its settings (Controller\Command\Index).
            'commandUrl' => $siteUrl . '/bluebarry/command/',
        ], $tenantId, $apiKey, 10);

        $outcome = ['at' => time(), 'site' => $siteUrl, 'ok' => false, 'status' => $response->getStatus(), 'error' => null];
        if ($response->isSuccess()) {
            $outcome['ok'] = true;
        } elseif ($response->getStatus() === 409) {
            $outcome['error'] = 'The API key belongs to another bluebarry company than the Tenant ID.';
        } elseif ($response->getStatus() === 401 || $response->getStatus() === 403) {
            $outcome['error'] = 'bluebarry refused the API key.';
        } else {
            $outcome['error'] = $response->getError() ?? ('bluebarry answered HTTP ' . $response->getStatus() . '.');
        }
        $this->record($websiteId, $outcome);
        return $outcome;
    }

    /**
     * Tells bluebarry the module is gone from every website (module:uninstall). Best effort.
     *
     * @return void
     */
    public function deactivateAll(): void
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            try {
                $tenantId = $this->config->getWebsiteTenantId($website->getId());
                $apiKey = $this->config->getWebsiteApiKey($website->getId());
                if ($tenantId !== null && $apiKey !== null) {
                    $this->client->post('/data/magento/deactivate', ['siteUrl' => $this->siteUrl($website)], $tenantId, $apiKey, 5);
                }
            } catch (\Exception $e) {
                // One broken website does not keep the others registered.
            }
        }
    }

    /**
     * Pings every website on the next cron run (after a module upgrade: never during the deploy).
     *
     * @return void
     */
    public function forceNext(): void
    {
        $state = $this->state();
        $state['force'] = true;
        $this->flags->saveFlag(self::FLAG, $state);
    }

    /**
     * The last outcome per website, for the settings page.
     *
     * @return array<int, array{at: int, site: string, ok: bool, status: int, error: ?string}>
     */
    public function outcomes(): array
    {
        return $this->state()['websites'] ?? [];
    }

    /**
     * @param WebsiteInterface $website
     * @return string
     */
    private function siteUrl(WebsiteInterface $website): string
    {
        $group = $this->storeManager->getGroup((string) $website->getDefaultGroupId());
        $store = $this->storeManager->getStore($group->getDefaultStoreId());
        return rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true), '/');
    }

    /**
     * @param int $websiteId
     * @param array|null $outcome
     * @return void
     */
    private function record(int $websiteId, ?array $outcome): void
    {
        $state = $this->state();
        if ($outcome === null) {
            if (!isset($state['websites'][$websiteId])) {
                return; // an unconnected website stays one flag read
            }
            unset($state['websites'][$websiteId]);
        } else {
            $state['websites'][$websiteId] = $outcome;
        }
        $this->flags->saveFlag(self::FLAG, $state);
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
