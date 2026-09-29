<?php

namespace Bluebarry\Bluebarry\Observer;

use Bluebarry\Bluebarry\Model\ResourceModel\CheckoutNotes;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * A checkout became an order: bluebarry hears it, so no abandoned checkout reminder goes out. One
 * update on the module's own table, a no-op for checkouts that gave no email.
 */
class CompleteCheckout implements ObserverInterface
{
    /**
     * @var CheckoutNotes
     */
    private $checkouts;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CheckoutNotes $checkouts
     * @param LoggerInterface $logger
     */
    public function __construct(CheckoutNotes $checkouts, LoggerInterface $logger)
    {
        $this->checkouts = $checkouts;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $event = $observer->getEvent();
        // Multi-address checkout's orders come in one event, a normal checkout's in its own.
        $orders = $event->getName() === 'checkout_submit_all_after' ? (array) $event->getData('orders') : [$event->getData('order')];
        foreach ($orders as $order) {
            try {
                if ($order && $order->getQuoteId()) {
                    $this->checkouts->complete((int) $order->getQuoteId());
                }
            } catch (\Exception $e) {
                $this->logger->error('bluebarry: could not note the completed checkout: ' . $e->getMessage());
            }
        }
    }
}
