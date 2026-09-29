<?php

namespace Bluebarry\Bluebarry\Model;

use Bluebarry\Bluebarry\Model\Api\Client;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\FlagManager;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * What each website shows from bluebarry, chosen in Studio: for now, search (the search box's
 * configuration and whether bluebarry's results replace the catalog search results page).
 *
 * Kept here, read from bluebarry with the website's API key every 10 minutes and whenever bluebarry
 * asks (Controller\Command\Index, "settings.refresh"), so a shopper's page never waits for bluebarry.
 * When it changes, the pages that print it leave the full page cache (and Varnish) by their tag.
 */
class Storefront
{
    public const FLAG = 'bluebarry_storefront';

    /** The cache tag of every page that prints the settings (Block\Advisor). */
    public const CACHE_TAG = 'bluebarry_storefront';

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
     * @var FlagManager
     */
    private $flags;

    /**
     * @var EventManager
     */
    private $events;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @param Config $config
     * @param Client $client
     * @param StoreManagerInterface $storeManager
     * @param FlagManager $flags
     * @param EventManager $events
     * @param CacheInterface $cache
     */
    public function __construct(
        Config $config,
        Client $client,
        StoreManagerInterface $storeManager,
        FlagManager $flags,
        EventManager $events,
        CacheInterface $cache
    ) {
        $this->config = $config;
        $this->client = $client;
        $this->storeManager = $storeManager;
        $this->flags = $flags;
        $this->events = $events;
        $this->cache = $cache;
    }

    /**
     * Search for a website: its profile and whether the results page is bluebarry's, or null while off.
     * Only for the Tenant ID the settings were read for.
     *
     * @param int|string $websiteId
     * @param string $tenantId the store view's
     * @return array{profileId: string, resultsPage: bool}|null
     */
    public function search($websiteId, string $tenantId): ?array
    {
        $settings = $this->cached()[(int) $websiteId] ?? null;
        if (!$settings || strtolower((string) ($settings['tenantId'] ?? '')) !== strtolower($tenantId)) {
            return null;
        }
        return $settings['search'] ?? null;
    }

    /**
     * Reads every connected website's settings (the cron).
     *
     * @return void
     */
    public function refreshAll(): void
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            try {
                $this->refresh($website);
            } catch (\Exception $e) {
                // One website's trouble never keeps the others' settings stale; tried again next run.
            }
        }
    }

    /**
     * Reads a website's settings from bluebarry, and clears the pages that print them when they changed.
     *
     * @param WebsiteInterface $website
     * @return string|null the settings' version, or null when they could not be read
     */
    public function refresh(WebsiteInterface $website): ?string
    {
        $websiteId = (int) $website->getId();
        $tenantId = $this->config->getWebsiteTenantId($websiteId);
        $apiKey = $this->config->getWebsiteApiKey($websiteId);
        if ($tenantId === null || $apiKey === null) {
            $this->save($websiteId, null);
            return null;
        }
        $response = $this->client->get('/data/magento/storefront', $apiKey, 10);
        $answer = $response->isSuccess() ? json_decode($response->getBody(), true) : null;
        if (!is_array($answer) || strtolower((string) ($answer['tenantId'] ?? '')) !== strtolower($tenantId)) {
            return null; // kept as it was: a bad answer never switches a store's search off
        }
        $search = null;
        if (is_array($answer['search'] ?? null) && Visitor::isUuid($answer['search']['profileId'] ?? null)) {
            $search = [
                'profileId' => strtolower($answer['search']['profileId']),
                'resultsPage' => !empty($answer['search']['resultsPage']),
            ];
        }
        $version = (string) ($answer['version'] ?? '');
        $this->save($websiteId, ['tenantId' => strtolower($tenantId), 'version' => $version, 'search' => $search]);
        return $version;
    }

    /**
     * @param int $websiteId
     * @param array|null $settings
     * @return void
     */
    private function save(int $websiteId, ?array $settings): void
    {
        $state = $this->state();
        $before = $state[$websiteId] ?? null;
        if ($settings === null) {
            unset($state[$websiteId]);
        } else {
            $state[$websiteId] = $settings;
        }
        if ($before === ($state[$websiteId] ?? null)) {
            return;
        }
        $this->flags->saveFlag(self::FLAG, $state);
        $this->cache->remove(self::FLAG);
        // Every page prints these, so every page carries the tag. The same event Magento's own saves
        // use: it cleans the built-in page cache and purges Varnish.
        $this->events->dispatch('clean_cache_by_tags', ['object' => new class extends DataObject implements IdentityInterface {
            /**
             * @return string[]
             */
            public function getIdentities()
            {
                return [Storefront::CACHE_TAG];
            }
        }]);
    }

    /**
     * The settings as pages read them: from Magento's cache, so rendering a page costs no query.
     *
     * @return array
     */
    private function cached(): array
    {
        $cached = $this->cache->load(self::FLAG);
        if (is_string($cached) && is_array($decoded = json_decode($cached, true))) {
            return $decoded;
        }
        $state = $this->state();
        $this->cache->save((string) json_encode($state), self::FLAG, [ConfigCache::CACHE_TAG]);
        return $state;
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
