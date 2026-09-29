<?php

namespace Bluebarry\Bluebarry\Observer;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Conversion\Queue as ConversionQueue;
use Bluebarry\Bluebarry\Model\Orders\Sync;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderSyncQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Queues an order for bluebarry when it is paid, and when it changes after that (refunded, cancelled):
 * one insert on the module's own table, and only when the order's state or amounts changed. The cron
 * sends it (Model\Orders\Sync).
 */
class QueueOrderSync implements ObserverInterface
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var OrderSyncQueue
     */
    private $queue;

    /**
     * @var ConversionQueue
     */
    private $conversions;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param OrderSyncQueue $queue
     * @param ConversionQueue $conversions
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        OrderSyncQueue $queue,
        ConversionQueue $conversions,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->queue = $queue;
        $this->conversions = $conversions;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getId()) {
            return;
        }
        $changed = false;
        foreach (['state', 'total_paid', 'total_refunded', 'total_canceled'] as $field) {
            $changed = $changed || $order->dataHasChangedFor($field);
        }
        if (!$changed) {
            return;
        }
        try {
            $websiteId = (int) $this->storeManager->getStore((int) $order->getStoreId())->getWebsiteId();
            if ($this->config->getWebsiteTenantId($websiteId) === null || !$this->config->hasWebsiteApiKey($websiteId)) {
                return;
            }
            $sync = $this->queue->state((int) $order->getId());
            if ($this->conversions->isPaid($order)) {
                $this->queue->enqueue((int) $order->getId(), Sync::isNewPurchase($order, $sync['synced']));
            } elseif ($sync['synced'] || $sync['queued']) {
                // Cancelled, refunded or put on hold after bluebarry has it, or while it is on its way: the
                // stored order follows. An order that is no sale (any more) is no new purchase either.
                $this->queue->enqueue((int) $order->getId(), false, true);
            }
        } catch (\Exception $e) {
            // Never in the way of an order save; the history import catches up.
            $this->logger->error('bluebarry: could not queue the order: ' . $e->getMessage(), ['order_id' => $order->getId()]);
        }
    }
}
