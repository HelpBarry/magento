<?php

namespace Bluebarry\Bluebarry\Model;

use Bluebarry\Bluebarry\Model\Api\Client;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
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

    /** The cache tag of every page that prints the settings (Block\Advisor), per website: cacheTag(). */
    public const CACHE_TAG = 'bluebarry_storefront';

    /** How long after a change a page may still have kept the old settings. */
    private const SETTLE = 1800;

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
     * @var LockManagerInterface
     */
    private $locks;

    /**
     * @param Config $config
     * @param Client $client
     * @param StoreManagerInterface $storeManager
     * @param FlagManager $flags
     * @param EventManager $events
     * @param CacheInterface $cache
     * @param LockManagerInterface $locks
     */
    public function __construct(
        Config $config,
        Client $client,
        StoreManagerInterface $storeManager,
        FlagManager $flags,
        EventManager $events,
        CacheInterface $cache,
        LockManagerInterface $locks
    ) {
        $this->config = $config;
        $this->client = $client;
        $this->storeManager = $storeManager;
        $this->flags = $flags;
        $this->events = $events;
        $this->cache = $cache;
        $this->locks = $locks;
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
            $this->save($websiteId, null, self::now());
            return null;
        }
        // When this read started: a read started later that saved already wins over this one.
        $started = self::now();
        $response = $this->client->get('/data/magento/storefront', $apiKey, 10);
        $answer = $response->isSuccess() ? json_decode($response->getBody(), true) : null;
        if (!is_array($answer) || strtolower((string) ($answer['tenantId'] ?? '')) !== strtolower($tenantId)) {
            return null; // kept as it was: a bad answer never switches a store's search off
        }
        if (!array_key_exists('search', $answer)) {
            return null;
        }
        // Only an explicit null switches search off; anything else unexpected keeps what the store has.
        $search = null;
        if (is_array($answer['search']) && Visitor::isUuid($answer['search']['profileId'] ?? null)
            && is_bool($answer['search']['resultsPage'] ?? null)) {
            $search = [
                'profileId' => strtolower($answer['search']['profileId']),
                'resultsPage' => $answer['search']['resultsPage'],
            ];
        } elseif ($answer['search'] !== null) {
            return null;
        }
        $version = (string) ($answer['version'] ?? '');
        $this->save($websiteId, ['tenantId' => strtolower($tenantId), 'version' => $version, 'search' => $search], $started);
        return $version;
    }

    /**
     * The cache tag of the pages that print a website's settings.
     *
     * @param int $websiteId
     * @return string
     */
    public static function cacheTag(int $websiteId): string
    {
        return self::CACHE_TAG . '_' . $websiteId;
    }

    /**
     * @param int $websiteId
     * @param array|null $settings
     * @param int $started when the read began, in milliseconds
     * @return void
     */
    private function save(int $websiteId, ?array $settings, int $started): void
    {
        // Websites refresh on their own (the cron, bluebarry's commands): one writes at a time, so
        // none saves over another's newer settings. Not now: the next refresh saves them.
        if (!$this->locks->lock(self::FLAG, 10)) {
            return;
        }
        try {
            $this->write($websiteId, $settings, $started);
        } finally {
            $this->locks->unlock(self::FLAG);
        }
    }

    /**
     * @param int $websiteId
     * @param array|null $settings
     * @param int $started when the read began, in milliseconds
     * @return void
     */
    private function write(int $websiteId, ?array $settings, int $started): void
    {
        $state = $this->state();
        $before = $state[$websiteId] ?? null;
        if ($settings !== null && (int) ($before['fetched'] ?? 0) > $started) {
            return; // a read that began later saved newer settings already
        }
        if (self::content($before) === self::content($settings)) {
            $cached = $this->cache->load(self::FLAG);
            if (is_string($cached) && json_decode($cached, true) == $state) {
                return; // pages read what is saved
            }
            // A page that read the settings while they changed may have kept the old ones: in the
            // settings cache (it differs) or only in the page cache (the settings cache is gone then).
            // Long after a change, a missing entry is just a cold cache.
            if (!is_string($cached) && time() - (int) ($before['written'] ?? 0) >= self::SETTLE) {
                return;
            }
        } else {
            if ($settings === null) {
                unset($state[$websiteId]);
            } else {
                $state[$websiteId] = $settings + ['fetched' => $started, 'written' => time()];
            }
            $this->flags->saveFlag(self::FLAG, $state);
        }
        $this->cache->remove(self::FLAG);
        $this->purge($websiteId);
    }

    /**
     * The website's pages leave the built-in page cache and Varnish: the same event Magento's own
     * saves use, with the tag only that website's pages carry.
     *
     * @param int $websiteId
     * @return void
     */
    private function purge(int $websiteId): void
    {
        $this->events->dispatch('clean_cache_by_tags', ['object' => new class ($websiteId) extends DataObject implements IdentityInterface {
            /**
             * @var int
             */
            private $websiteId;

            /**
             * @param int $websiteId
             */
            public function __construct(int $websiteId)
            {
                parent::__construct();
                $this->websiteId = $websiteId;
            }

            /**
             * @return string[]
             */
            public function getIdentities()
            {
                return [Storefront::cacheTag($this->websiteId)];
            }
        }]);
    }

    /**
     * Milliseconds: exact in the flag's and the cache's JSON, which Magento writes with 14 digits.
     *
     * @return int
     */
    private static function now(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * What a website shows, without when it was read and saved.
     *
     * @param array|null $settings
     * @return array|null
     */
    private static function content(?array $settings): ?array
    {
        return $settings === null ? null : array_diff_key($settings, ['fetched' => true, 'written' => true]);
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
