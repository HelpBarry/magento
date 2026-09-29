<?php

namespace Bluebarry\Bluebarry\Model\Orders;

use Bluebarry\Bluebarry\Model\Api\Client;
use Bluebarry\Bluebarry\Model\Api\Response;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\ResourceModel\CheckoutNotes;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderSyncQueue;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The store's orders in bluebarry: best sellers, units sold, bought together, loyalty points,
 * purchase-based segments, and the order placed and abandoned checkout emails. Mirrors the WooCommerce
 * plugin (DataApi's orders/sync and checkout-started contracts).
 *
 * A shopper's request never waits for bluebarry: a paid or changed order is one row in a queue table
 * (Observer\QueueOrderSync), a checkout's email one row (Controller\Checkout\Email), and the cron sends
 * them in batches. The 365-day history import runs the same way, one batch at a time, started by
 * bluebarry (Studio's Orders page) through a command or the 10-minute tasks check.
 *
 * Each website sends its own orders with its own API key. Orders are keyed by their store view's host,
 * like the conversions (Model\Conversion\Sender).
 */
class Sync
{
    public const IMPORT_FLAG = 'bluebarry_order_import';
    public const STATE_FLAG = 'bluebarry_order_sync';

    private const BATCH = 50;
    private const LOCK = 'bluebarry_order_sync';
    /** A new purchase is one paid within this long of being placed; a later payment is history. */
    private const LATE_PAYMENT_WINDOW = 14 * 86400;
    /** A checkout's email is sent once it stopped changing this long. */
    private const CHECKOUT_SETTLE = 60;
    /** After a website's request failed, its orders wait this long (growing, to an hour). */
    private const RETRY_AFTER = 300;
    /** An import that has not moved for this long is taken for dead and may be started again. */
    private const IMPORT_STALE = 3600;

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
     * @var OrderSyncQueue
     */
    private $queue;

    /**
     * @var CheckoutNotes
     */
    private $checkouts;

    /**
     * @var OrderPayload
     */
    private $payload;

    /**
     * @var OrderCollectionFactory
     */
    private $orders;

    /**
     * @var CartRepositoryInterface
     */
    private $quotes;

    /**
     * @var FlagManager
     */
    private $flags;

    /**
     * @var LockManagerInterface
     */
    private $locks;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param Client $client
     * @param StoreManagerInterface $storeManager
     * @param OrderSyncQueue $queue
     * @param CheckoutNotes $checkouts
     * @param OrderPayload $payload
     * @param OrderCollectionFactory $orders
     * @param CartRepositoryInterface $quotes
     * @param FlagManager $flags
     * @param LockManagerInterface $locks
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        Client $client,
        StoreManagerInterface $storeManager,
        OrderSyncQueue $queue,
        CheckoutNotes $checkouts,
        OrderPayload $payload,
        OrderCollectionFactory $orders,
        CartRepositoryInterface $quotes,
        FlagManager $flags,
        LockManagerInterface $locks,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->client = $client;
        $this->storeManager = $storeManager;
        $this->queue = $queue;
        $this->checkouts = $checkouts;
        $this->payload = $payload;
        $this->orders = $orders;
        $this->quotes = $quotes;
        $this->flags = $flags;
        $this->locks = $locks;
        $this->logger = $logger;
    }

    /**
     * Whether a paid order is a new purchase: never sent before, and placed recently. A merchant who
     * finally marks an old order paid adds history, so nobody gets an order placed email weeks later.
     *
     * @param Order $order
     * @param bool $sentBefore
     * @return bool
     */
    public static function isNewPurchase(Order $order, bool $sentBefore): bool
    {
        $created = strtotime((string) $order->getCreatedAt() . ' UTC');
        return !$sentBefore && $created !== false && time() - $created < self::LATE_PAYMENT_WINDOW;
    }

    /**
     * Sends waiting orders, the history import's next batches and settled checkouts, until the time is up.
     *
     * @param int $seconds 0 for no limit
     * @return array{orders: int, imported: int, checkouts: int}
     */
    public function run(int $seconds = 0): array
    {
        $done = ['orders' => 0, 'imported' => 0, 'checkouts' => 0];
        if (!$this->locks->lock(self::LOCK, 0)) {
            return $done;
        }
        try {
            $targets = $this->targets();
            if (!$targets) {
                return $done;
            }
            $start = time();
            $inTime = function () use ($start, $seconds) {
                return $seconds === 0 || time() - $start < $seconds;
            };
            $done['orders'] = $this->drain($targets, $inTime);
            // Before the history: a checkout reminder is timely, an import can take many runs.
            $done['checkouts'] = $this->sendCheckouts($targets, $inTime);
            foreach ($targets as $websiteId => $target) {
                while ($inTime() && $this->importBatch($websiteId, $target, $done['imported'])) {
                    // one batch at a time
                }
            }
            return $done;
        } finally {
            $this->locks->unlock(self::LOCK);
        }
    }

    /**
     * Starts the history import for a website unless one is running (bluebarry's command, or its tasks).
     *
     * @param int $websiteId
     * @param int $since unix time: orders placed since
     * @return bool
     */
    public function startImport(int $websiteId, int $since): bool
    {
        if (!isset($this->targets()[$websiteId])) {
            return false;
        }
        $running = $this->import($websiteId);
        if (is_array($running) && empty($running['done']) && time() - (int) ($running['touched'] ?? 0) < self::IMPORT_STALE) {
            return false;
        }
        $this->saveImport($websiteId, ['since' => $since, 'after' => 0, 'count' => 0, 'attempts' => 0, 'touched' => time()]);
        return true;
    }

    /**
     * Asks bluebarry what each website should do (the 10-minute cron): today, whether to import the
     * order history, for a store bluebarry could not reach with its command.
     *
     * @return void
     */
    public function pollTasks(): void
    {
        foreach ($this->targets() as $websiteId => $target) {
            $response = $this->client->get('/data/magento/tasks?storeKey=' . rawurlencode($target['storeKey']), $target['apiKey'], 10);
            $tasks = $response->isSuccess() ? json_decode($response->getBody(), true) : null;
            if (is_array($tasks) && !empty($tasks['importOrders'])) {
                $since = strtotime((string) ($tasks['importSinceUtc'] ?? ''));
                $this->startImport((int) $websiteId, $since ?: time() - 365 * 86400);
            }
        }
    }

    /**
     * @param array $targets
     * @param callable $inTime
     * @return int orders sent
     */
    private function drain(array $targets, callable $inTime): int
    {
        $sent = 0;
        $waiting = $this->waitingWebsites();
        $refused = []; // tried again on a later run, not over and over in this one
        while ($inTime()) {
            $skip = [];
            foreach (array_keys($waiting) as $websiteId) {
                $skip = array_merge($skip, $this->storeIds((int) $websiteId));
            }
            $claim = bin2hex(random_bytes(8));
            $claimed = $this->queue->claimNext(self::BATCH, $claim, $skip, $refused);
            if (!$claimed) {
                break;
            }
            $progress = false;
            foreach ($this->groups(array_keys($claimed), $targets) as $group) {
                // Each group can take a request's timeout: the time is checked again before each one.
                if (!call_user_func($inTime)) {
                    $this->queue->release($group['ids'], $claim); // the next run sends it
                    continue;
                }
                if ($group['target'] === null) {
                    // Not for bluebarry (a website without a connection, or a store view reporting to
                    // another company than its website's key): nothing to send, ever.
                    $this->queue->markSent($group['ids'], $claim);
                    $progress = true;
                    continue;
                }
                if (isset($waiting[$group['websiteId']])) {
                    $this->queue->release($group['ids'], $claim); // its website failed earlier in this batch
                    continue;
                }
                // Read again: a refund saved since the claim makes it no new purchase (and queues it anew).
                $live = $this->queue->claimed($group['ids'], $claim);
                $group['orders'] = array_values(array_filter($group['orders'], function ($order) use ($live) {
                    return isset($live[(int) $order->getId()]);
                }));
                $group['ids'] = array_keys($live);
                if (!$group['orders']) {
                    continue;
                }
                $payloads = [];
                foreach ($group['orders'] as $order) {
                    $payloads[] = $this->payload->build($order, $live[(int) $order->getId()]);
                }
                $response = $this->client->post('/data/magento/orders/sync', ['storeKey' => $group['storeKey'], 'orders' => $payloads],
                    $group['target']['tenantId'], $group['target']['apiKey'], 30);
                if ($response->isSuccess()) {
                    $this->queue->markSent($group['ids'], $claim);
                    $this->forgetFailures((int) $group['websiteId']);
                    $sent += count($group['ids']);
                    $progress = true;
                } elseif ($this->isOutage($response)) {
                    $waiting[$group['websiteId']] = true;
                    $this->queue->release($group['ids'], $claim);
                    $this->noteOutage((int) $group['websiteId'], $response);
                    $progress = true; // its orders are skipped from now on
                } else {
                    $refused = array_merge($refused, $group['ids']);
                    $dropped = $this->queue->fail($group['ids'], $claim);
                    if ($dropped) {
                        $this->logger->error('bluebarry: gave up sending orders it refused', ['order_ids' => $dropped, 'status' => $response->getStatus()]);
                    }
                    $progress = true;
                }
            }
            if (!$progress) {
                break;
            }
        }
        return $sent;
    }

    /**
     * One batch of a website's history, oldest first. Paid orders (and refunded ones) placed since the
     * import's start date, sent as history, never as new purchases.
     *
     * @param int $websiteId
     * @param array $target
     * @param int $imported running total, for the caller
     * @return bool whether another batch should follow now
     */
    private function importBatch(int $websiteId, array $target, int &$imported): bool
    {
        $state = $this->import($websiteId);
        if (!is_array($state) || !empty($state['done']) || isset($this->waitingWebsites()[$websiteId])) {
            return false;
        }
        // Where a failed batch starts again.
        $before = $state;
        $storeIds = $this->storeIds($websiteId);
        $collection = $this->orders->create();
        $collection->addFieldToFilter('store_id', ['in' => $storeIds ?: [-1]])
            ->addFieldToFilter('state', ['in' => [Order::STATE_PROCESSING, Order::STATE_COMPLETE, Order::STATE_CLOSED]])
            ->addFieldToFilter('created_at', ['gteq' => gmdate('Y-m-d H:i:s', (int) $state['since'])])
            ->addFieldToFilter('entity_id', ['gt' => (int) $state['after']])
            ->setOrder('entity_id', 'ASC')
            ->setPageSize(self::BATCH)
            ->setCurPage(1);
        // Paid: processing can also mean shipped before it was invoiced. As Conversion\Queue::isPaid().
        $collection->getSelect()->where('IFNULL(main_table.total_paid, 0) > main_table.grand_total - IFNULL(main_table.total_canceled, 0) - 0.005');
        $orders = [];
        foreach ($collection as $order) {
            if ($this->reportsTo($order, $target)) {
                $orders[] = $order;
            }
            $state['after'] = max((int) $state['after'], (int) $order->getId());
        }
        $finished = count($collection) < self::BATCH;

        $ok = true;
        $byStore = [];
        foreach ($orders as $order) {
            $byStore[$this->storeKey((int) $order->getStoreId())][] = $order;
        }
        foreach ($byStore as $storeKey => $group) {
            $payloads = array_map(function ($order) {
                return $this->payload->build($order, false);
            }, $group);
            $response = $this->client->post('/data/magento/orders/sync', ['storeKey' => $storeKey, 'orders' => $payloads],
                $target['tenantId'], $target['apiKey'], 30);
            if (!$response->isSuccess()) {
                $ok = false;
                break;
            }
            $this->queue->markImported(array_map(function ($order) {
                return (int) $order->getId();
            }, $group));
            $state['count'] = (int) $state['count'] + count($group);
            $imported += count($group);
        }
        $state['touched'] = time();
        if ($ok) {
            // Progress, under the website's own host (where bluebarry's Orders page looks).
            $status = $finished ? 'Completed' : 'Running';
            $response = $this->client->post('/data/magento/orders/sync', [
                'storeKey' => $target['storeKey'], 'orders' => [], 'import' => $status, 'importedCount' => (int) $state['count'],
            ], $target['tenantId'], $target['apiKey'], 30);
            $ok = $response->isSuccess();
        }
        if (!$ok) {
            // The whole batch again next time; bluebarry takes an order it has already as an update.
            $state['after'] = $before['after'];
            $state['count'] = $before['count'];
            $state['attempts'] = (int) $state['attempts'] + 1;
            if ($state['attempts'] >= 10) {
                $state['done'] = true;
                $this->client->post('/data/magento/orders/sync', [
                    'storeKey' => $target['storeKey'], 'orders' => [], 'import' => 'Failed', 'importedCount' => (int) $state['count'],
                ], $target['tenantId'], $target['apiKey'], 30);
                $this->logger->error('bluebarry: order history import stopped: bluebarry did not take it', ['website_id' => $websiteId]);
            }
            $this->saveImport($websiteId, $state);
            return false;
        }
        $state['attempts'] = 0;
        $state['done'] = $finished;
        $this->saveImport($websiteId, $state);
        return !$finished;
    }

    /**
     * Checkouts whose email settled, and ones that became an order.
     *
     * @param array $targets
     * @param callable $inTime
     * @return int
     */
    private function sendCheckouts(array $targets, callable $inTime): int
    {
        $sent = 0;
        $waiting = $this->waitingWebsites();
        $skip = [];
        foreach (array_keys($waiting) as $websiteId) {
            $skip = array_merge($skip, $this->storeIds((int) $websiteId));
        }
        foreach ($this->checkouts->due(self::BATCH, self::CHECKOUT_SETTLE, $skip) as $note) {
            if (!$inTime()) {
                break;
            }
            $storeId = (int) $note['store_id'];
            $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();
            $target = $targets[$websiteId] ?? null;
            if ($target === null || isset($waiting[$websiteId])
                || $this->config->getTenantId($storeId) === null
                || strtolower((string) $this->config->getTenantId($storeId)) !== strtolower($target['tenantId'])) {
                if ($target === null || !isset($waiting[$websiteId])) {
                    $this->checkouts->markSent((int) $note['quote_id'], (string) $note['noted_at']); // not for bluebarry
                }
                continue;
            }
            $lines = [];
            try {
                $quote = $this->quotes->get((int) $note['quote_id']);
                $lines = $quote instanceof \Magento\Quote\Model\Quote ? CheckoutNotes::lines($quote) : [];
            } catch (\Exception $e) {
                // The cart is gone: the email still goes, without products.
            }
            $response = $this->client->post('/data/magento/checkout-started', [
                'storeKey' => $this->storeKey($storeId),
                'token' => (string) $note['quote_id'],
                'email' => (string) $note['email'],
                'firstName' => $note['first_name'] ? mb_substr((string) $note['first_name'], 0, 128) : null,
                'completed' => (bool) (int) $note['completed'],
                'lines' => $lines,
            ], $target['tenantId'], $target['apiKey'], 15);
            if ($response->isSuccess() || !$this->isOutage($response)) {
                $this->checkouts->markSent((int) $note['quote_id'], (string) $note['noted_at']);
                if ($response->isSuccess()) {
                    $this->forgetFailures($websiteId);
                    $sent++;
                }
            } else {
                $waiting[$websiteId] = true;
                $this->noteOutage($websiteId, $response);
            }
        }
        return $sent;
    }

    /**
     * The claimed orders grouped by the website that sends them and the host they are keyed by.
     *
     * @param int[] $ids
     * @param array $targets
     * @return array[]
     */
    private function groups(array $ids, array $targets): array
    {
        $collection = $this->orders->create();
        $collection->addFieldToFilter('entity_id', ['in' => $ids]);
        $groups = [];
        $found = [];
        foreach ($collection as $order) {
            $found[] = (int) $order->getId();
            $websiteId = (int) $this->storeManager->getStore((int) $order->getStoreId())->getWebsiteId();
            $target = $targets[$websiteId] ?? null;
            if ($target !== null && !$this->reportsTo($order, $target)) {
                $target = null;
            }
            $key = $target === null ? 'none' : $websiteId . '|' . $this->storeKey((int) $order->getStoreId());
            $groups[$key] = $groups[$key] ?? [
                'websiteId' => $websiteId, 'target' => $target, 'ids' => [], 'orders' => [],
                'storeKey' => $target === null ? '' : $this->storeKey((int) $order->getStoreId()),
            ];
            $groups[$key]['ids'][] = (int) $order->getId();
            $groups[$key]['orders'][] = $order;
        }
        $gone = array_values(array_diff($ids, $found));
        if ($gone) {
            $groups['gone'] = ['websiteId' => 0, 'target' => null, 'ids' => $gone, 'orders' => [], 'storeKey' => ''];
        }
        return array_values($groups);
    }

    /**
     * An order's store view reports to the company its website's key belongs to.
     *
     * @param Order $order
     * @param array $target
     * @return bool
     */
    private function reportsTo(Order $order, array $target): bool
    {
        return strtolower((string) $this->config->getTenantId((int) $order->getStoreId())) === strtolower($target['tenantId']);
    }

    /**
     * The connected websites, with the host their import reports under (the heartbeat's site).
     *
     * @return array<int, array{tenantId: string, apiKey: string, storeKey: string}>
     */
    private function targets(): array
    {
        $targets = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $tenantId = $this->config->getWebsiteTenantId($website->getId());
            $apiKey = $this->config->getWebsiteApiKey($website->getId());
            if ($tenantId === null || $apiKey === null) {
                continue;
            }
            $group = $this->storeManager->getGroup((string) $website->getDefaultGroupId());
            $targets[(int) $website->getId()] = [
                'tenantId' => $tenantId,
                'apiKey' => $apiKey,
                'storeKey' => $this->storeKey((int) $group->getDefaultStoreId()),
            ];
        }
        return $targets;
    }

    /**
     * @param int $websiteId
     * @return int[]
     */
    private function storeIds(int $websiteId): array
    {
        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ((int) $store->getWebsiteId() === $websiteId) {
                $ids[] = (int) $store->getId();
            }
        }
        return $ids;
    }

    /**
     * The host a store view's orders are keyed by, like its conversions.
     *
     * @param int $storeId
     * @return string
     */
    private function storeKey(int $storeId): string
    {
        $host = parse_url((string) $this->storeManager->getStore($storeId)->getBaseUrl(), PHP_URL_HOST);
        return strtolower((string) $host);
    }

    /**
     * @param Response $response
     * @return bool bluebarry unreachable or not taking requests now (retry later), rather than refusing these
     */
    private function isOutage(Response $response): bool
    {
        return $response->isRetryable() || in_array($response->getStatus(), [401, 403], true);
    }

    /**
     * @param int $websiteId
     * @param Response $response
     * @return void
     */
    private function noteOutage(int $websiteId, Response $response): void
    {
        $state = $this->state();
        $outages = (int) ($state[$websiteId]['outages'] ?? 0) + 1;
        $state[$websiteId] = ['outages' => $outages, 'retry_at' => time() + min(3600, self::RETRY_AFTER * $outages)];
        $this->flags->saveFlag(self::STATE_FLAG, $state);
        $this->logger->warning('bluebarry: orders not sent, will retry', ['website_id' => $websiteId, 'status' => $response->getStatus(), 'error' => $response->getError()]);
    }

    /**
     * @param int $websiteId
     * @return void
     */
    private function forgetFailures(int $websiteId): void
    {
        $state = $this->state();
        if (isset($state[$websiteId])) {
            unset($state[$websiteId]);
            $this->flags->saveFlag(self::STATE_FLAG, $state);
        }
    }

    /**
     * Websites whose last request failed, still waiting to try again.
     *
     * @return array<int, true>
     */
    private function waitingWebsites(): array
    {
        $waiting = [];
        foreach ($this->state() as $websiteId => $row) {
            if ((int) ($row['retry_at'] ?? 0) > time()) {
                $waiting[(int) $websiteId] = true;
            }
        }
        return $waiting;
    }

    /**
     * @return array
     */
    private function state(): array
    {
        $state = $this->flags->getFlagData(self::STATE_FLAG);
        return is_array($state) ? $state : [];
    }

    /**
     * A website's history import, in a flag of its own: Studio can start one website's while the cron
     * imports another's.
     *
     * @param int $websiteId
     * @return array|null
     */
    private function import(int $websiteId): ?array
    {
        $state = $this->flags->getFlagData(self::IMPORT_FLAG . '_' . $websiteId);
        return is_array($state) ? $state : null;
    }

    /**
     * @param int $websiteId
     * @param array $state
     * @return void
     */
    private function saveImport(int $websiteId, array $state): void
    {
        $this->flags->saveFlag(self::IMPORT_FLAG . '_' . $websiteId, $state);
    }
}
