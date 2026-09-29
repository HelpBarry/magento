<?php

namespace Bluebarry\Bluebarry\Observer;

use Bluebarry\Bluebarry\Model\Conversion\Queue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * An order became paid (processing or complete): queue its conversion. Runs on every order save, so
 * it only acts on the change of state, and then does one update by primary key.
 */
class QueuePaidOrder implements ObserverInterface
{
    /**
     * @var Queue
     */
    private $queue;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Queue $queue
     * @param LoggerInterface $logger
     */
    public function __construct(Queue $queue, LoggerInterface $logger)
    {
        $this->queue = $queue;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();
        // Paid by the state change (an invoice at checkout), by a later invoice on an order that was
        // already processing (shipped first), or by cancelling the part that was not invoiced.
        if (!$order || !$order->getId()
            || (!$order->dataHasChangedFor('state') && !$order->dataHasChangedFor('total_paid') && !$order->dataHasChangedFor('total_canceled'))
            || !$this->queue->isPaid($order)) {
            return;
        }
        try {
            $this->queue->queue((int) $order->getId());
        } catch (\Exception $e) {
            $this->logger->error('bluebarry: could not queue the conversion: ' . $e->getMessage(), ['order_id' => $order->getId()]);
        }
    }
}
