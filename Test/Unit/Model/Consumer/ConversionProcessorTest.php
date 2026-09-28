<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Consumer;

use Bluebarry\Bluebarry\Model\Consumer\ConversionProcessor;
use Bluebarry\Bluebarry\Model\Conversion\Queue;
use Bluebarry\Bluebarry\Model\Conversion\Sender;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderVisitor;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConversionProcessorTest extends TestCase
{
    public function testSendsTheOrderInTheMessage(): void
    {
        $sender = $this->createMock(Sender::class);
        $sender->expects($this->once())->method('send')->with(42);

        $this->processor($sender, $this->createStub(OrderVisitor::class), Order::STATE_NEW)->processConversion('42');
    }

    /** A message from before the upgrade carries the quiz session instead of relying on the table. */
    public function testLegacyMessageIsRecordedAndSentWhenPaid(): void
    {
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->expects($this->once())->method('capture')->with(42, [
            'user_id' => '11111111-1111-4111-8111-111111111111',
            'session_id' => '22222222-2222-4222-8222-222222222222',
            'advisor_id' => null,
            'experiments' => [],
        ]);
        $visitors->method('markQueued')->willReturn(true);
        $sender = $this->createMock(Sender::class);
        $sender->expects($this->once())->method('send')->with(42);

        $this->processor($sender, $visitors, Order::STATE_PROCESSING)->processConversion(json_encode([
            'order_id' => 42,
            'tenant_id' => 'tenant',
            'session_data' => ['bluebarry' => [
                'user_id' => '11111111-1111-4111-8111-111111111111',
                'session_id' => '22222222-2222-4222-8222-222222222222',
                'advisor_id' => 'not-a-uuid',
            ]],
        ]));
    }

    public function testLegacyMessageOfAnUnpaidOrderWaits(): void
    {
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->expects($this->once())->method('capture');
        $sender = $this->createMock(Sender::class);
        $sender->expects($this->never())->method('send');

        $this->processor($sender, $visitors, Order::STATE_NEW)->processConversion(json_encode([
            'order_id' => 42,
            'session_data' => ['bluebarry' => ['user_id' => '11111111-1111-4111-8111-111111111111']],
        ]));
    }

    public function testUnreadableMessageIsLoggedNotThrown(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->processor($this->createStub(Sender::class), $this->createStub(OrderVisitor::class), Order::STATE_NEW, $logger)
            ->processConversion('{"nope":true}');
    }

    private function processor(Sender $sender, OrderVisitor $visitors, string $state, ?LoggerInterface $logger = null): ConversionProcessor
    {
        $order = $this->createStub(Order::class);
        $order->method('getState')->willReturn($state);
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('get')->willReturn($order);
        $queue = new Queue($visitors, $this->createStub(PublisherInterface::class), $this->createStub(LoggerInterface::class));
        return new ConversionProcessor($sender, $visitors, $queue, $orders, $logger ?? $this->createStub(LoggerInterface::class));
    }
}
