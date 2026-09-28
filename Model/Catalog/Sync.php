<?php

namespace Bluebarry\Bluebarry\Model\Catalog;

use Bluebarry\Bluebarry\Model\Api\Client;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\ResourceModel\ProductSyncQueue;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps bluebarry's copy of the catalog current. Product changes are queued as they happen (one
 * statement, see \Bluebarry\Bluebarry\Observer\QueueChangedProducts); the cron sends them in batches.
 * The whole catalog is queued when a website connects to a bluebarry company, and nightly, for what
 * changes without a save: special prices and catalog price rules starting or ending, category names.
 *
 * One catalog per bluebarry company: websites that share a Tenant ID send the default website's
 * (or else the first one's) names, prices and addresses.
 */
class Sync
{
    public const FLAG = 'bluebarry_catalog';

    private const BATCH = 100;
    private const LOCK = 'bluebarry_catalog_sync';

    /** After a failed request, the queue waits this long. */
    private const RETRY_AFTER = 300;

    /** The nightly full sync: at least this long after the last one, between 01:00 and 05:00 store time. */
    private const FULL_SYNC_INTERVAL = 72000;

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
     * @var ProductSyncQueue
     */
    private $queue;

    /**
     * @var ProductBuilder
     */
    private $builder;

    /**
     * @var FlagManager
     */
    private $flags;

    /**
     * @var LockManagerInterface
     */
    private $locks;

    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param Client $client
     * @param StoreManagerInterface $storeManager
     * @param ProductSyncQueue $queue
     * @param ProductBuilder $builder
     * @param FlagManager $flags
     * @param LockManagerInterface $locks
     * @param TimezoneInterface $timezone
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        Client $client,
        StoreManagerInterface $storeManager,
        ProductSyncQueue $queue,
        ProductBuilder $builder,
        FlagManager $flags,
        LockManagerInterface $locks,
        TimezoneInterface $timezone,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->client = $client;
        $this->storeManager = $storeManager;
        $this->queue = $queue;
        $this->builder = $builder;
        $this->flags = $flags;
        $this->locks = $locks;
        $this->timezone = $timezone;
        $this->logger = $logger;
    }

    /**
     * Where the catalog goes: one website per bluebarry company, read in its default store view.
     *
     * @return array<int, array{store: Store, tenantId: string, apiKey: string}>
     */
    public function targets(): array
    {
        $websites = $this->storeManager->getWebsites();
        $default = (int) $this->storeManager->getDefaultStoreView()->getWebsiteId();
        uasort($websites, function ($a, $b) use ($default) {
            return [(int) $a->getId() !== $default, (int) $a->getId()] <=> [(int) $b->getId() !== $default, (int) $b->getId()];
        });
        $targets = [];
        foreach ($websites as $website) {
            $tenantId = $this->config->getWebsiteTenantId($website->getId());
            $apiKey = $this->config->getWebsiteApiKey($website->getId());
            if ($tenantId === null || $apiKey === null || isset($targets[strtolower($tenantId)])) {
                continue;
            }
            $group = $this->storeManager->getGroup((string) $website->getDefaultGroupId());
            /** @var Store $store */
            $store = $this->storeManager->getStore($group->getDefaultStoreId());
            $targets[strtolower($tenantId)] = ['store' => $store, 'tenantId' => $tenantId, 'apiKey' => $apiKey];
        }
        return array_values($targets);
    }

    /**
     * Sends queued changes until the queue is empty or the time is up. Queues the whole catalog first
     * when a new company was connected, or at night once a day.
     *
     * @param int $seconds 0 for no limit
     * @return array{sent: int, queued: int}
     */
    public function run(int $seconds = 0): array
    {
        if (!$this->locks->lock(self::LOCK, 0)) {
            return ['sent' => 0, 'queued' => $this->queue->count()];
        }
        try {
            $targets = $this->targets();
            if (!$targets) {
                return ['sent' => 0, 'queued' => $this->queue->count()];
            }
            $this->queueCatalogIfDue($targets);
            $state = $this->state();
            if (($state['retry_at'] ?? 0) > time()) {
                return ['sent' => 0, 'queued' => $this->queue->count()];
            }
            $sent = $this->drain($targets, $seconds);
            return ['sent' => $sent, 'queued' => $this->queue->count()];
        } finally {
            $this->locks->unlock(self::LOCK);
        }
    }

    /**
     * Queues every product, and sends after a failure right away (bin/magento bluebarry:catalog:sync --all).
     *
     * @return void
     */
    public function queueAll(): void
    {
        $this->queue->enqueueAll();
        $tenants = array_map(function ($target) {
            return strtolower($target['tenantId']);
        }, $this->targets());
        $this->saveState(['tenants' => $tenants, 'full_at' => time(), 'retry_at' => null]);
    }

    /**
     * For the settings page.
     *
     * @return array{queued: int, sent_at: int|null, full_at: int|null, error: string|null}
     */
    public function status(): array
    {
        $state = $this->state();
        return [
            'queued' => $this->queue->count(),
            'sent_at' => $state['sent_at'] ?? null,
            'full_at' => $state['full_at'] ?? null,
            'error' => $state['error'] ?? null,
        ];
    }

    /**
     * @param array $targets
     * @param int $seconds
     * @return int products sent
     */
    private function drain(array $targets, int $seconds): int
    {
        $start = time();
        $after = 0;
        $sent = 0;
        while ($seconds === 0 || time() - $start < $seconds) {
            $queued = $this->queue->next(self::BATCH, $after);
            if (!$queued) {
                break;
            }
            $after = (int) max(array_keys($queued));
            $ids = array_values(array_diff(array_keys($queued), $this->builder->awaitingPriceIndex($queued)));
            if (!$ids) {
                continue;
            }

            $claim = bin2hex(random_bytes(8));
            $this->queue->claim($ids, $claim);
            $failed = [];
            foreach ($targets as $target) {
                $batch = $this->builder->build($ids, $target['store']);
                $failed = array_merge($failed, $batch['failed']);
                if (!$batch['products'] && !$batch['reconcileGroupIds']) {
                    continue;
                }
                $response = $this->client->post('/data/magento/products/sync', [
                    // bluebarry refuses a key from another company, so the catalog never lands there.
                    'tenantId' => $target['tenantId'],
                    'products' => $batch['products'],
                    'reconcileGroupIds' => $batch['reconcileGroupIds'],
                ], $target['tenantId'], $target['apiKey'], 60);
                if (!$response->isSuccess()) {
                    if ($response->getStatus() === 409) {
                        $error = 'The API key belongs to another bluebarry company than the Tenant ID.';
                    } elseif (in_array($response->getStatus(), [401, 403], true)) {
                        $error = 'bluebarry refused the API key.';
                    } else {
                        $error = $response->getError() ?? 'bluebarry answered HTTP ' . $response->getStatus() . '.';
                    }
                    $this->logger->warning('bluebarry: catalog sync failed, retrying in 5 minutes: ' . $error);
                    $this->saveState(['retry_at' => time() + self::RETRY_AFTER, 'error' => $error]);
                    return $sent;
                }
            }

            $failed = array_values(array_unique($failed));
            $dropped = $this->queue->fail($failed);
            if ($dropped) {
                $this->logger->error('bluebarry: gave up syncing products that could not be read', ['product_ids' => $dropped]);
            }
            $this->queue->remove(array_values(array_diff($ids, $failed)), $claim);
            $sent += count($ids) - count($failed);
        }
        if ($sent > 0) {
            $this->saveState(['sent_at' => time(), 'error' => null, 'retry_at' => null]);
        }
        return $sent;
    }

    /**
     * @param array $targets
     * @return void
     */
    private function queueCatalogIfDue(array $targets): void
    {
        $state = $this->state();
        $tenants = array_map(function ($target) {
            return strtolower($target['tenantId']);
        }, $targets);
        $newCompany = array_diff($tenants, $state['tenants'] ?? []) !== [];
        $hour = (int) $this->timezone->date()->format('G');
        $nightly = time() - ($state['full_at'] ?? 0) >= self::FULL_SYNC_INTERVAL && $hour >= 1 && $hour < 5;
        if (!$newCompany && !$nightly) {
            return;
        }
        $this->queue->enqueueAll();
        $this->saveState(['tenants' => $tenants, 'full_at' => time()] + ($newCompany ? ['retry_at' => null] : []));
    }

    /**
     * @param array $changes null removes a key
     * @return void
     */
    private function saveState(array $changes): void
    {
        $state = $this->state();
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($state[$key]);
            } else {
                $state[$key] = $value;
            }
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
