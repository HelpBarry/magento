<?php

namespace Bluebarry\Bluebarry\Test\Unit\Observer;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Conversion\Queue;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderVisitor;
use Bluebarry\Bluebarry\Model\Visitor;
use Bluebarry\Bluebarry\Observer\ProcessConversion;
use Bluebarry\Bluebarry\Observer\QueuePaidOrder;
use Magento\Framework\App\State;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConversionObserversTest extends TestCase
{
    private const VISITOR = ['user_id' => '11111111-1111-4111-8111-111111111111', 'session_id' => null, 'advisor_id' => null, 'experiments' => []];

    public function testPlacementCapturesTheVisitorAndWaitsForPayment(): void
    {
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->expects($this->once())->method('capture')->with(5, self::VISITOR);
        $visitors->expects($this->never())->method('markQueued');

        $this->placement($visitors, self::VISITOR)->execute($this->event($this->order(Order::STATE_NEW)));
    }

    public function testPlacementOfAnOrderPaidAtCheckoutQueuesIt(): void
    {
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->expects($this->once())->method('capture');
        $visitors->expects($this->once())->method('markQueued')->with(5)->willReturn(true);
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())->method('publish')->with(Queue::TOPIC, '5');

        $this->placement($visitors, self::VISITOR, $publisher)->execute($this->event($this->order(Order::STATE_PROCESSING)));
    }

    public function testPlacementWithoutVisitorRecordsNothing(): void
    {
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->expects($this->never())->method('capture');

        $this->placement($visitors, null)->execute($this->event($this->order(Order::STATE_PROCESSING)));
    }

    public function testPlacementNeverBreaksCheckout(): void
    {
        $visitors = $this->createStub(OrderVisitor::class);
        $visitors->method('capture')->willThrowException(new \RuntimeException('db down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->placement($visitors, self::VISITOR, null, $logger)->execute($this->event($this->order(Order::STATE_NEW)));
    }

    public function testAnOrderEnteredInTheAdminIsNotLinkedToTheMerchantsCookies(): void
    {
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->expects($this->never())->method('capture');

        $this->placement($visitors, self::VISITOR, area: 'adminhtml')->execute($this->event($this->order(Order::STATE_PROCESSING)));
    }

    public function testEveryOrderOfAMultiAddressCheckoutIsCaptured(): void
    {
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->expects($this->exactly(2))->method('capture');
        $event = new Event(['orders' => [$this->order(Order::STATE_NEW), $this->order(Order::STATE_NEW)]]);
        $event->setName('checkout_submit_all_after');

        $this->placement($visitors, self::VISITOR)->execute(new Observer(['event' => $event]));
    }

    public function testAnOrderWithACancelledPartIsPaidOnceTheRestIs(): void
    {
        $queue = new Queue($this->createStub(OrderVisitor::class), $this->createStub(PublisherInterface::class), $this->createStub(LoggerInterface::class));
        $order = $this->createStub(Order::class);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->method('getGrandTotal')->willReturn(100.0);
        $order->method('getTotalCanceled')->willReturn(30.0);
        $order->method('getTotalPaid')->willReturn(70.0);

        $this->assertTrue($queue->isPaid($order));
    }

    public function testPaymentQueuesOnlyOnTheChangeToPaid(): void
    {
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->expects($this->once())->method('markQueued')->with(5)->willReturn(true);
        $observer = new QueuePaidOrder(new Queue($visitors, $this->createStub(PublisherInterface::class), $this->createStub(LoggerInterface::class)), $this->createStub(LoggerInterface::class));

        $observer->execute($this->event($this->order(Order::STATE_PROCESSING, stateChanged: true)));
        $observer->execute($this->event($this->order(Order::STATE_PROCESSING, stateChanged: false))); // an unrelated save
        $observer->execute($this->event($this->order(Order::STATE_NEW, stateChanged: true)));
    }

    public function testAnOrderShippedBeforeItIsPaidWaitsForTheInvoice(): void
    {
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->expects($this->once())->method('markQueued')->with(5)->willReturn(true);
        $observer = new QueuePaidOrder(new Queue($visitors, $this->createStub(PublisherInterface::class), $this->createStub(LoggerInterface::class)), $this->createStub(LoggerInterface::class));

        // Shipped: processing, nothing paid yet. Then partly invoiced. Then invoiced in full.
        $observer->execute($this->event($this->order(Order::STATE_PROCESSING, stateChanged: true, paid: 0.0)));
        $observer->execute($this->event($this->order(Order::STATE_PROCESSING, stateChanged: false, paid: 40.0, paidChanged: true)));
        $observer->execute($this->event($this->order(Order::STATE_PROCESSING, stateChanged: false, paid: 100.0, paidChanged: true)));
    }

    public function testPublishFailureLeavesTheConversionToTheCron(): void
    {
        $visitors = $this->createStub(OrderVisitor::class);
        $visitors->method('markQueued')->willReturn(true);
        $publisher = $this->createStub(PublisherInterface::class);
        $publisher->method('publish')->willThrowException(new \RuntimeException('broker down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        (new Queue($visitors, $publisher, $logger))->queue(5);
    }

    private function placement(OrderVisitor $visitors, ?array $visitor, ?PublisherInterface $publisher = null, ?LoggerInterface $logger = null, string $area = 'webapi_rest'): ProcessConversion
    {
        $config = $this->createStub(Config::class);
        $config->method('getTenantId')->willReturn('tenant');
        $reader = $this->createStub(Visitor::class);
        $reader->method('current')->willReturn($visitor);
        $queue = new Queue($visitors, $publisher ?? $this->createStub(PublisherInterface::class), $this->createStub(LoggerInterface::class));
        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturn($area);
        return new ProcessConversion($config, $reader, $visitors, $queue, $logger ?? $this->createStub(LoggerInterface::class), $state);
    }

    private function order(string $state, bool $stateChanged = true, float $paid = 100.0, bool $paidChanged = false): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(5);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getState')->willReturn($state);
        $order->method('getGrandTotal')->willReturn(100.0);
        $order->method('getTotalPaid')->willReturn($paid);
        $order->method('dataHasChangedFor')->willReturnCallback(fn ($field) => $field === 'state' ? $stateChanged : (in_array($field, ['total_paid', 'total_canceled'], true) && $paidChanged));
        return $order;
    }

    private function event(Order $order): Observer
    {
        return new Observer(['event' => new Event(['order' => $order])]);
    }
}
