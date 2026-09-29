<?php

namespace Bluebarry\Bluebarry\Plugin\Catalog;

use Bluebarry\Bluebarry\Observer\QueueChangedProducts;
use Magento\Catalog\Model\Product\Action;

/**
 * Queues the products of a mass attribute update (the admin's "Update attributes", also run by its
 * queue consumer) once their values are written: its only event comes before the write, when the sync
 * could still read and send the old values.
 */
class QueueMassUpdatedProducts
{
    /**
     * @var QueueChangedProducts
     */
    private $queue;

    /**
     * @param QueueChangedProducts $queue
     */
    public function __construct(QueueChangedProducts $queue)
    {
        $this->queue = $queue;
    }

    /**
     * @param Action $subject
     * @param Action $result
     * @return Action
     */
    public function afterUpdateAttributes(Action $subject, $result)
    {
        $this->queue->queueChanged(array_map('intval', (array) $subject->getData('product_ids')));
        return $result;
    }
}
