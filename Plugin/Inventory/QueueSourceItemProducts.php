<?php

namespace Bluebarry\Bluebarry\Plugin\Inventory;

use Bluebarry\Bluebarry\Observer\QueueChangedProducts;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;

/**
 * Queues the products whose multi-source inventory changed: the source items API, inventory imports and
 * the admin's sources save them without saving the product or its legacy stock item, so no catalog
 * event says so. Declared on MSI's interfaces, so it simply never runs where MSI is not installed; no
 * MSI type appears in a signature here for the same reason.
 */
class QueueSourceItemProducts
{
    /**
     * @var QueueChangedProducts
     */
    private $queue;

    /**
     * @var ProductResource
     */
    private $products;

    /**
     * @param QueueChangedProducts $queue
     * @param ProductResource $products
     */
    public function __construct(QueueChangedProducts $queue, ProductResource $products)
    {
        $this->queue = $queue;
        $this->products = $products;
    }

    /**
     * SourceItemsSaveInterface::execute() and SourceItemsDeleteInterface::execute().
     *
     * @param object $subject
     * @param mixed $result
     * @param array $sourceItems
     * @return mixed
     */
    public function afterExecute($subject, $result, array $sourceItems)
    {
        $skus = [];
        foreach ($sourceItems as $sourceItem) {
            if (is_object($sourceItem) && method_exists($sourceItem, 'getSku') && (string) $sourceItem->getSku() !== '') {
                $skus[] = (string) $sourceItem->getSku();
            }
        }
        if ($skus) {
            try {
                $this->queue->queueChanged(array_values($this->products->getProductsIdsBySkus(array_unique($skus))));
            } catch (\Exception $e) {
                // Never in the way of an inventory update; the nightly sync catches up.
            }
        }
        return $result;
    }
}
