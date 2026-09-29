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
        try {
            $event = $observer->getEvent();
            $deletion = in_array($event->getName(), ['catalog_product_delete_before', 'catalog_product_import_bunch_delete_commit_before'], true);
            if (!$deletion && !$this->anyWebsiteConnected()) {
                return;
            }
            $ids = $this->productIds($event);
            if ($ids) {
                $this->queue->enqueue($ids);
            }
        } catch (\Exception $e) {
            // Never in the way of a save, an import or an order; the nightly sync catches up.
            $this->logger->error('bluebarry: could not queue changed products: ' . $e->getMessage());
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
     * @param \Magento\Framework\Event $event
     * @return int[]
     */
    private function productIds($event): array
    {
        switch ($event->getName()) {
            case 'catalog_product_save_after':
            case 'catalog_product_delete_before':
                return [(int) $event->getData('product')->getId()];
            case 'catalog_product_attribute_update_before':
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
                $ids = [];
                foreach ($event->getData('order')->getAllItems() as $item) {
                    $ids[] = (int) $item->getProductId();
                }
                return $ids;
            case 'sales_order_creditmemo_save_after':
                $ids = [];
                foreach ($event->getData('creditmemo')->getAllItems() as $item) {
                    $ids[] = (int) $item->getProductId();
                }
                return $ids;
            default:
                return [];
        }
    }
}
