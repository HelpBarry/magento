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

    /** DataApi's limit per request (MagentoProductSyncRequest.MaxProducts). */
    private const MAX_PER_REQUEST = 500;
    private const LOCK = 'bluebarry_catalog_sync';

    /** After a failed request, the queue waits this long. */
    private const RETRY_AFTER = 300;

    /** The nightly full sync: at least this long after the last one, between 01:00 and 05:00 store time. */

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
            try {
                $apiKey = $this->config->getWebsiteApiKey($website->getId());
            } catch (\Exception $e) {
                // An undecryptable key (a database restored under another crypt key): this website waits
                // until its key is saved again, the others still sync. The Connection status shows it.
                $this->logger->warning('bluebarry: skipping the catalog of a website whose API key cannot be read: ' . $e->getMessage(), ['website' => $website->getId()]);
                continue;
            }
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
     * when a company was (re)connected, or at night once a day.
     *
     * @param int $seconds 0 for no limit
     * @return array{sent: int, queued: int, busy?: bool} busy: another run holds the sync
     */
    public function run(int $seconds = 0): array
    {
        if (!$this->locks->lock(self::LOCK, 0)) {
            return ['sent' => 0, 'queued' => $this->queue->count(), 'busy' => true];
        }
        try {
            $targets = $this->targets();
            $this->queueCatalogIfDue($targets);
            if (!$targets) {
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
        $this->saveState(['tenants' => self::sources($this->targets()), 'full_at' => time(), 'targets' => null]);
    }

    /**
     * For the settings page.
     *
     * @return array{queued: int, sent_at: int|null, full_at: int|null, error: string|null}
     */
    public function status(): array
    {
        $state = $this->state();
        $error = null;
        foreach ($state['targets'] ?? [] as $target) {
            $error = $error ?? ($target['error'] ?? null);
        }
        return [
            'queued' => $this->queue->count(),
            'sent_at' => $state['sent_at'] ?? null,
            'full_at' => $state['full_at'] ?? null,
            'error' => $error,
        ];
    }

    /**
     * Sends batches to every company whose connection works. One that fails waits five minutes
     * without holding up the others; if they received changes it missed, it gets the whole catalog
     * again once it works.
     *
     * @param array $targets
     * @param int $seconds
     * @return int products sent
     */
    private function drain(array $targets, int $seconds): int
    {
        $active = [];
        foreach ($targets as $target) {
            if (($this->state()['targets'][strtolower($target['tenantId'])]['retry_at'] ?? 0) <= time()) {
                $active[strtolower($target['tenantId'])] = $target;
            }
        }
        // Companies waiting out an earlier failure: they miss what the others receive now.
        $cooling = [];
        foreach ($targets as $target) {
            if (!isset($active[strtolower($target['tenantId'])])) {
                $cooling[] = strtolower($target['tenantId']);
            }
        }
        foreach (array_keys($active) as $key) {
            if (!empty($this->state()['targets'][$key]['stale'])) {
                // It missed changes the others received: the whole catalog again once it works. Asked
                // with an empty request first, so a company that still fails never sends the others'
                // sync back to the first product every five minutes.
                $error = $this->post($active[$key], ['products' => [], 'reconcileGroupIds' => []]);
                if ($error !== null) {
                    $this->updateTarget($key, ['retry_at' => time() + self::RETRY_AFTER, 'error' => $error]);
                    unset($active[$key]);
                    continue;
                }
                $this->queue->enqueueAll();
                $this->updateTarget($key, ['stale' => false]);
            }
        }
        $start = time();
        $after = 0;
        $sent = 0;
        $failedThisRun = [];
        while ($active && ($seconds === 0 || time() - $start < $seconds)) {
            $claim = bin2hex(random_bytes(8));
            $queued = $this->queue->claimNext(self::BATCH, $after, $claim);
            if (!$queued) {
                break;
            }
            $after = (int) max(array_keys($queued));
            // A product that failed earlier in this run waits for the next: one attempt per run.
            $again = array_keys(array_intersect_key($queued, $failedThisRun));
            $this->queue->release($again, $claim);
            $queued = array_diff_key($queued, $failedThisRun);
            $waiting = $this->builder->awaitingIndexes($queued);
            $this->queue->release($waiting, $claim);
            $ids = array_values(array_diff(array_keys($queued), $waiting));
            if (!$ids) {
                continue;
            }

            $failed = [];
            $deleted = [];
            $delivered = [];
            $refused = [];
            foreach ($active as $key => $target) {
                $batch = $this->builder->build($ids, $target['store']);
                $failed = array_merge($failed, $batch['failed']);
                $deleted = array_merge($deleted, $batch['deleted'] ?? []);
                $error = $this->deliver($target, $batch);
                if ($error === null) {
                    $delivered[] = $key;
                } else {
                    $refused[$key] = $error;
                }
            }
            if (!$delivered) {
                // Nobody received this batch: it stays queued for the next attempt.
                foreach ($refused as $key => $error) {
                    $this->updateTarget($key, ['retry_at' => time() + self::RETRY_AFTER, 'error' => $error]);
                }
                return $sent;
            }
            foreach ($cooling as $key) {
                if (empty($this->state()['targets'][$key]['stale'])) {
                    $this->updateTarget($key, ['stale' => true]);
                }
            }
            $cooling = [];
            foreach ($refused as $key => $error) {
                // The others received changes this company missed.
                $this->updateTarget($key, ['retry_at' => time() + self::RETRY_AFTER, 'error' => $error, 'stale' => true]);
                unset($active[$key]);
            }
            foreach ($delivered as $key) {
                $this->updateTarget($key, null);
            }

            $failed = array_values(array_unique($failed));
            $failedThisRun += array_flip($failed);
            // A configurable product's child that could not be read has no queue row of its own.
            $this->queue->ensureQueued(array_diff($failed, $ids));
            $dropped = $this->queue->fail($failed);
            if ($dropped) {
                $this->logger->error('bluebarry: gave up syncing products that could not be read', ['product_ids' => $dropped]);
            }
            // A deleted product is only switched off by its own row (the whole catalog a company gets
            // back holds products that exist): kept until every company received it.
            $keep = count($delivered) < count($targets) ? array_values(array_unique($deleted)) : [];
            $this->queue->release($keep, $claim);
            $this->queue->remove(array_values(array_diff($ids, $failed, $keep)), $claim);
            $sent += count(array_diff($ids, $failed));
        }
        if ($sent > 0) {
            $this->saveState(['sent_at' => time()]);
        }
        return $sent;
    }

    /**
     * Sends one batch to one company, in requests the API takes (at most 500 products), keeping a
     * configurable product's children together with the switch-off of the ones it lost.
     *
     * @param array $target
     * @param array $batch from ProductBuilder::build()
     * @return string|null the error, or null when it all arrived
     */
    private function deliver(array $target, array $batch): ?string
    {
        foreach (self::requests($batch['products'], $batch['reconcileGroupIds']) as $request) {
            $error = $this->post($target, $request);
            if ($error !== null) {
                return $error;
            }
        }
        return null;
    }

    /**
     * @param array $target
     * @param array{products: array[], reconcileGroupIds: string[]} $request
     * @return string|null the error, or null when it arrived
     */
    private function post(array $target, array $request): ?string
    {
        $response = $this->client->post('/data/magento/products/sync', [
            // bluebarry refuses a key from another company, so the catalog never lands there.
            'tenantId' => $target['tenantId'],
            'products' => $request['products'],
            'reconcileGroupIds' => $request['reconcileGroupIds'],
        ], $target['tenantId'], $target['apiKey'], 60);
        if ($response->isSuccess()) {
            return null;
        }
        if ($response->getStatus() === 409) {
            $error = 'The API key belongs to another bluebarry company than the Tenant ID.';
        } elseif (in_array($response->getStatus(), [401, 403], true)) {
            $error = 'bluebarry refused the API key.';
        } else {
            $error = $response->getError() ?? 'bluebarry answered HTTP ' . $response->getStatus() . '.';
        }
        $this->logger->warning('bluebarry: catalog sync failed, retrying in 5 minutes: ' . $error, ['tenant' => $target['tenantId']]);
        return $error;
    }

    /**
     * Splits a batch into requests of at most MAX_PER_REQUEST products. A group (a configurable
     * product's children) goes in one request with its switch-off; a group too big for one request is
     * split and not switched off, since no single request holds all of its children.
     *
     * @param array[] $products
     * @param string[] $reconcileGroupIds
     * @return array<int, array{products: array[], reconcileGroupIds: string[]}>
     */
    public static function requests(array $products, array $reconcileGroupIds): array
    {
        $groups = [];
        foreach ($products as $product) {
            $groups[(string) ($product['groupId'] ?? '')][] = $product;
        }
        $reconcile = array_flip($reconcileGroupIds);
        $requests = [];
        $current = ['products' => [], 'reconcileGroupIds' => []];
        foreach ($groups as $groupId => $members) {
            if (count($members) > self::MAX_PER_REQUEST) {
                unset($reconcile[$groupId]);
                foreach (array_chunk($members, self::MAX_PER_REQUEST) as $part) {
                    $requests[] = ['products' => $part, 'reconcileGroupIds' => []];
                }
                continue;
            }
            if (count($current['products']) + count($members) > self::MAX_PER_REQUEST) {
                $requests[] = $current;
                $current = ['products' => [], 'reconcileGroupIds' => []];
            }
            $current['products'] = array_merge($current['products'], $members);
            if ($groupId !== '' && isset($reconcile[$groupId])) {
                $current['reconcileGroupIds'][] = (string) $groupId;
                unset($reconcile[$groupId]);
            }
        }
        // Groups without a product left in the batch (a deleted configurable product) ride along.
        $current['reconcileGroupIds'] = array_merge($current['reconcileGroupIds'], array_map('strval', array_keys($reconcile)));
        if ($current['products'] || $current['reconcileGroupIds']) {
            $requests[] = $current;
        }
        return $requests;
    }

    /**
     * The whole catalog goes to a company that is new, came back, or is now read from another store
     * view: changes made meanwhile never reached it, or it holds the other view's names and prices.
     * Everyone gets it at night once a day.
     *
     * @param array $targets
     * @return void
     */
    private function queueCatalogIfDue(array $targets): void
    {
        $state = $this->state();
        $sources = self::sources($targets);
        $saved = is_array($state['tenants'] ?? null) ? $state['tenants'] : [];
        $changed = array_diff_assoc($sources, $saved) !== [];
        // Once a night, between 1 and 5 in the store's time zone, whenever the last full sync was.
        $now = $this->timezone->date();
        $hour = (int) $now->format('G');
        $lastDay = isset($state['full_at']) ? $this->timezone->date(new \DateTime('@' . (int) $state['full_at']))->format('Y-m-d') : '';
        $nightly = $targets && $hour >= 1 && $hour < 5 && ($lastDay !== $now->format('Y-m-d') || $this->fullAtBefore($state, 1));
        // A company that left gets the whole catalog when it comes back, and its failure is no more.
        $failures = array_intersect_key($state['targets'] ?? [], $sources) ?: null;
        if ($changed || $nightly) {
            $this->queue->enqueueAll();
            $this->saveState(['tenants' => $sources, 'full_at' => time(), 'targets' => $failures]);
        } elseif ($sources != $saved) {
            $this->saveState(['tenants' => $sources, 'targets' => $failures]);
        }
    }

    /**
     * Whether the last full sync was today before the given hour (store time): one the day before at
     * noon counts as yesterday's, one at 00:30 as not yet tonight's.
     *
     * @param array $state
     * @param int $hour
     * @return bool
     */
    private function fullAtBefore(array $state, int $hour): bool
    {
        return (int) $this->timezone->date(new \DateTime('@' . (int) ($state['full_at'] ?? 0)))->format('G') < $hour;
    }

    /**
     * @param array $targets
     * @return array<string, int> company => the store view its catalog is read in
     */
    private static function sources(array $targets): array
    {
        $sources = [];
        foreach ($targets as $target) {
            $sources[strtolower($target['tenantId'])] = (int) $target['store']->getId();
        }
        return $sources;
    }

    /**
     * @param string $tenant
     * @param array|null $changes null forgets the company's failure
     * @return void
     */
    private function updateTarget(string $tenant, ?array $changes): void
    {
        $state = $this->state();
        if ($changes === null) {
            if (!isset($state['targets'][$tenant])) {
                return;
            }
            unset($state['targets'][$tenant]);
        } else {
            $state['targets'][$tenant] = $changes + ($state['targets'][$tenant] ?? []);
        }
        $this->flags->saveFlag(self::FLAG, $state);
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
