<?php

namespace Bluebarry\Bluebarry\Test\Unit\Observer;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Conversion\Queue;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderVisitor;
use Bluebarry\Bluebarry\Model\Visitor;
use Bluebarry\Bluebarry\Observer\ProcessConversion;
use Bluebarry\Bluebarry\Observer\QueuePaidOrder;
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

    public function testPaymentQueuesOnlyOnTheChangeToPaid(): void
    {
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->expects($this->once())->method('markQueued')->with(5)->willReturn(true);
        $observer = new QueuePaidOrder(new Queue($visitors, $this->createStub(PublisherInterface::class), $this->createStub(LoggerInterface::class)), $this->createStub(LoggerInterface::class));

        $observer->execute($this->event($this->order(Order::STATE_PROCESSING, stateChanged: true)));
        $observer->execute($this->event($this->order(Order::STATE_PROCESSING, stateChanged: false))); // an unrelated save
        $observer->execute($this->event($this->order(Order::STATE_NEW, stateChanged: true)));
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

    private function placement(OrderVisitor $visitors, ?array $visitor, ?PublisherInterface $publisher = null, ?LoggerInterface $logger = null): ProcessConversion
    {
        $config = $this->createStub(Config::class);
        $config->method('getTenantId')->willReturn('tenant');
        $reader = $this->createStub(Visitor::class);
        $reader->method('current')->willReturn($visitor);
        $queue = new Queue($visitors, $publisher ?? $this->createStub(PublisherInterface::class), $this->createStub(LoggerInterface::class));
        return new ProcessConversion($config, $reader, $visitors, $queue, $logger ?? $this->createStub(LoggerInterface::class));
    }

    private function order(string $state, bool $stateChanged = true): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(5);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getState')->willReturn($state);
        $order->method('dataHasChangedFor')->willReturn($stateChanged);
        return $order;
    }

    private function event(Order $order): Observer
    {
        return new Observer(['event' => new Event(['order' => $order])]);
    }
}
