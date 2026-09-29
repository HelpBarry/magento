<?php

namespace Bluebarry\Bluebarry\Observer;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\ResourceModel\ProductSyncQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Queues the products a change touched, for the catalog sync: saves and deletes, mass actions, imports,
 * category assignments, stock updates, and the products of an order placed, cancelled or refunded
 * (their stock changed).
 *
 * One insert on the module's own table; nothing here calls bluebarry. While no website is connected
 * only deletions are queued: a deleted product cannot be found again when the catalog is resent.
 */
class QueueChangedProducts implements ObserverInterface
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
     * @var ProductSyncQueue
     */
    private $queue;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param ProductSyncQueue $queue
     * @param LoggerInterface $logger
     */
    public function __construct(Config $config, StoreManagerInterface $storeManager, ProductSyncQueue $queue, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->queue = $queue;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $event = $observer->getEvent();
        $deletion = in_array($event->getName(), ['catalog_product_delete_before', 'catalog_product_import_bunch_delete_commit_before'], true);
        try {
            if (!$deletion && !$this->anyWebsiteConnected()) {
                return;
            }
            $this->queue($this->productIds($event), $deletion);
        } catch (\Exception $e) {
            // Never in the way of a save, an import or an order; the nightly sync catches up.
            $this->logger->error('bluebarry: could not queue changed products: ' . $e->getMessage());
        }
    }

    /**
     * Queues products changed without an event after the change (a mass attribute update).
     *
     * @param int[] $ids
     * @return void
     */
    public function queueChanged(array $ids): void
    {
        try {
            if ($this->anyWebsiteConnected()) {
                $this->queue($ids, false);
            }
        } catch (\Exception $e) {
            $this->logger->error('bluebarry: could not queue changed products: ' . $e->getMessage());
        }
    }

    /**
     * @param int[] $ids
     * @param bool $deletion
     * @return void
     */
    private function queue(array $ids, bool $deletion): void
    {
        if (!$ids) {
            return;
        }
        $this->queue->enqueue($ids);
        if ($deletion) {
            // A bundle's price and availability come from its selections; once the product is gone,
            // nothing links it to the bundle any more.
            $this->queue->enqueueBundlesWith($ids);
        }
    }

    /**
     * Reads cached configuration only.
     *
     * @return bool
     */
    private function anyWebsiteConnected(): bool
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            if ($this->config->getWebsiteTenantId($website->getId()) !== null && $this->config->hasWebsiteApiKey($website->getId())) {
                return true;
            }
        }
        return false;
    }

    /**
     * The products of order lines whose stock changed: a configurable product's or a bundle's own line
     * holds none (its variant's or parts' lines do). Queuing a configurable product would resend every
     * variant; the variant and the parts bring the bundles they are in themselves.
     *
     * @param \Magento\Sales\Model\Order\Item[] $items
     * @return int[]
     */
    private static function stockBearing(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (!in_array($item->getProductType(), ['configurable', 'bundle'], true)) {
                $ids[] = (int) $item->getProductId();
            }
        }
        return $ids;
    }

    /**
     * @param \Magento\Framework\Event $event
     * @return int[]
     */
    private function productIds($event): array
    {
        switch ($event->getName()) {
            case 'catalog_product_save_before':
                // A configurable product's children before the save: one it loses is a product of its own again.
                $product = $event->getData('product');
                if ($product->getTypeId() === 'configurable' && $product->getId()) {
                    $product->setData('bluebarry_children', $this->queue->configurableChildren((int) $product->getId()));
                }
                return [];
            case 'catalog_product_save_after':
                $product = $event->getData('product');
                $ids = [(int) $product->getId()];
                if (is_array($product->getData('bluebarry_children'))) {
                    $ids = array_merge($ids, array_diff($product->getData('bluebarry_children'), $this->queue->configurableChildren((int) $product->getId())));
                    $product->unsetData('bluebarry_children');
                }
                return $ids;
            case 'catalog_product_delete_before':
                return [(int) $event->getData('product')->getId()];
            case 'catalog_category_change_products':
                return (array) $event->getData('product_ids');
            case 'catalog_product_to_website_change':
                return (array) $event->getData('products');
            case 'catalog_product_import_bunch_delete_commit_before':
                return (array) $event->getData('ids_to_delete');
            case 'catalog_product_import_bunch_save_after':
                $adapter = $event->getData('adapter');
                $ids = [];
                foreach ((array) $event->getData('bunch') as $row) {
                    $sku = isset($row['sku']) ? (string) $row['sku'] : '';
                    $known = $sku !== '' ? $adapter->getNewSku($sku) : null;
                    if (!empty($known['entity_id'])) {
                        $ids[] = (int) $known['entity_id'];
                    }
                }
                return $ids;
            case 'cataloginventory_stock_item_save_after':
                return [(int) $event->getData('item')->getProductId()];
            case 'sales_model_service_quote_submit_success':
            case 'order_cancel_after':
                return self::stockBearing($event->getData('order')->getAllItems());
            case 'checkout_submit_all_after':
                // Multi-address checkout; a single order is queued on quote submit.
                $ids = [];
                foreach ((array) $event->getData('orders') as $order) {
                    $ids = array_merge($ids, self::stockBearing($order->getAllItems()));
                }
                return $ids;
            case 'sales_order_creditmemo_save_after':
                return self::stockBearing(array_filter(array_map(function ($item) {
                    return $item->getOrderItem();
                }, $event->getData('creditmemo')->getAllItems())));
            default:
                return [];
        }
    }
}
