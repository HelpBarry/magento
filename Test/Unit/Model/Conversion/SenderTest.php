<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Conversion;

use Bluebarry\Bluebarry\Model\Api\Client;
use Bluebarry\Bluebarry\Model\Api\Response;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Conversion\PayloadBuilder;
use Bluebarry\Bluebarry\Model\Conversion\Sender;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderVisitor;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SenderTest extends TestCase
{
    private const USER = '11111111-1111-4111-8111-111111111111';

    private array $calls = [];

    public function testDeliversThenIdentifies(): void
    {
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->method('get')->willReturn($this->row());
        $visitors->expects($this->once())->method('markSent')->with(7);

        $this->sender($visitors, [201, 204], 'buyer@example.com')->send(7);

        $this->assertSame(['/data/conversionevents', '/data/identify'], array_column($this->calls, 'path'));
        $this->assertSame(['email' => 'buyer@example.com', 'userId' => self::USER], $this->calls[1]['body']);
        $this->assertSame('shop.example', $this->calls[0]['body']['commerceStoreKey']);
    }

    public function testNoAnswerIsRetriedWithGrowingDelay(): void
    {
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->method('get')->willReturn($this->row(attempts: 2));
        $visitors->expects($this->once())->method('scheduleRetry')->with(7, 3, 9);
        $visitors->expects($this->never())->method('markSent');

        $this->sender($visitors, [503])->send(7);
    }

    public function testGivesUpAfterTheLastAttempt(): void
    {
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->method('get')->willReturn($this->row(attempts: Sender::MAX_ATTEMPTS - 1));
        $visitors->expects($this->once())->method('markFailed')->with(7, Sender::MAX_ATTEMPTS);
        $visitors->expects($this->never())->method('scheduleRetry');

        $this->sender($visitors, [0])->send(7);
    }

    public function testRefusalIsNotRetried(): void
    {
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->method('get')->willReturn($this->row());
        $visitors->expects($this->once())->method('markFailed');
        $visitors->expects($this->never())->method('scheduleRetry');

        $this->sender($visitors, [400])->send(7);
    }

    public function testAnOrderThatCannotBeReadCountsAsAnAttempt(): void
    {
        // Its store view was deleted: counted and retried like a failed delivery, so it neither stops
        // the cron's batch nor comes back forever.
        $visitors = $this->createMock(OrderVisitor::class);
        $visitors->method('get')->willReturn($this->row(attempts: 1));
        $visitors->expects($this->once())->method('scheduleRetry')->with(7, 2, 4);

        $this->sender($visitors, [201], storeGone: true)->send(7);

        $this->assertSame([], $this->calls);
    }

    public function testOnlyQueuedConversionsAreSent(): void
    {
        $visitors = $this->createStub(OrderVisitor::class);
        $visitors->method('get')->willReturn($this->row(status: OrderVisitor::STATUS_SENT));

        $this->sender($visitors, [201])->send(7);

        $this->assertSame([], $this->calls);
    }

    private function row(int $attempts = 0, int $status = OrderVisitor::STATUS_QUEUED): array
    {
        return ['order_id' => 7, 'user_id' => self::USER, 'session_id' => null, 'advisor_id' => null,
            'experiments' => null, 'status' => $status, 'attempts' => $attempts];
    }

    private function sender(OrderVisitor $visitors, array $statuses, string $email = '', bool $storeGone = false): Sender
    {
        $order = $this->createStub(Order::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getEntityId')->willReturn('7');
        $order->method('getItems')->willReturn([]);
        $order->method('getCustomerEmail')->willReturn($email);
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('get')->willReturn($order);

        $config = $this->createStub(Config::class);
        $config->method('getTenantId')->willReturn('tenant');

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://Shop.example/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($storeGone) {
            $storeManager->method('getStore')->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException(__('The store that was requested wasn\'t found.')));
        } else {
            $storeManager->method('getStore')->willReturn($store);
        }

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturnCallback(function ($path, $body) use (&$statuses) {
            $this->calls[] = ['path' => $path, 'body' => $body];
            return new Response((int) array_shift($statuses), '');
        });

        return new Sender($visitors, $orders, new PayloadBuilder(), $client, $config, $storeManager, $this->createStub(LoggerInterface::class));
    }
}
