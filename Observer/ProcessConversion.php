<?php

namespace Bluebarry\Bluebarry\Observer;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Conversion\Queue;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderVisitor;
use Bluebarry\Bluebarry\Model\Visitor;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * At order placement: remember which bluebarry visitor placed the order. Every visitor counts, not
 * only quiz takers: search, recommendations, chat and popups attribute through the visitor id. The
 * conversion itself goes out once the order is paid, from the background (QueuePaidOrder).
 *
 * One insert on the module's own table; nothing here calls bluebarry.
 */
class ProcessConversion implements ObserverInterface
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var Visitor
     */
    private $visitor;

    /**
     * @var OrderVisitor
     */
    private $visitors;

    /**
     * @var Queue
     */
    private $queue;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var State
     */
    private $appState;

    /**
     * @param Config $config
     * @param Visitor $visitor
     * @param OrderVisitor $visitors
     * @param Queue $queue
     * @param LoggerInterface $logger
     * @param State $appState
     */
    public function __construct(
        Config $config,
        Visitor $visitor,
        OrderVisitor $visitors,
        Queue $queue,
        LoggerInterface $logger,
        State $appState
    ) {
        $this->config = $config;
        $this->visitor = $visitor;
        $this->visitors = $visitors;
        $this->queue = $queue;
        $this->logger = $logger;
        $this->appState = $appState;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $event = $observer->getEvent();
        // Multi-address checkout places several orders at once and only says so in
        // checkout_submit_all_after; a normal checkout fires that too, but with one order, handled
        // on sales_model_service_quote_submit_success already.
        if ($event->getName() === 'checkout_submit_all_after') {
            foreach ((array) $event->getData('orders') as $order) {
                $this->capture($order);
            }
            return;
        }
        $this->capture($event->getData('order'));
    }

    /**
     * @param \Magento\Sales\Model\Order|null $order
     * @return void
     */
    private function capture($order): void
    {
        if (!$order || !$order->getId()) {
            return;
        }
        $tenantId = $this->config->getTenantId($order->getStoreId());
        if ($tenantId === null) {
            return;
        }

        try {
            // An order entered in the admin carries the merchant's own cookies, not the shopper's.
            if ($this->appState->getAreaCode() === Area::AREA_ADMINHTML) {
                return;
            }
            $visitor = $this->visitor->current($tenantId);
            if ($visitor === null) {
                if ($this->config->isDebugLogEnabled($order->getStoreId())) {
                    $this->logger->debug('bluebarry: order has no bluebarry visitor (or no cookie consent)', ['order_id' => $order->getId()]);
                }
                return;
            }
            $this->visitors->capture((int) $order->getId(), $visitor);
            // Paid while it was placed (a card captured at checkout): queue it now.
            if ($this->queue->isPaid($order)) {
                $this->queue->queue((int) $order->getId());
            }
        } catch (\Exception $e) {
            // Never in the way of an order.
            $this->logger->error('bluebarry: could not link the order to its visitor: ' . $e->getMessage(), ['order_id' => $order->getId()]);
        }
    }
}
