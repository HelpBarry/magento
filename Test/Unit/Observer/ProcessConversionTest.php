<?php

namespace Bluebarry\Bluebarry\Test\Unit\Observer;

use Bluebarry\Bluebarry\Observer\ProcessConversion;
use Bluebarry\Bluebarry\Test\Unit\SessionDouble;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProcessConversionTest extends TestCase
{
    private const COMPLETE_SESSION = ['bluebarry' => ['session_id' => 's', 'advisor_id' => 'a', 'user_id' => 'u']];

    private SessionDouble $session;

    protected function setUp(): void
    {
        $this->session = new SessionDouble();
    }

    public function testPublishesWhenQuizSessionIsComplete(): void
    {
        $this->session->stored = self::COMPLETE_SESSION;
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with('bluebarry.conversion.process', json_encode([
                'order_id' => 42,
                'session_data' => self::COMPLETE_SESSION,
                'tenant_id' => 'tenant',
            ]));

        $this->observer('tenant', $publisher)->execute($this->eventFor(42));
    }

    public function testSkipsWithoutTenant(): void
    {
        $this->session->stored = self::COMPLETE_SESSION;
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $this->observer(null, $publisher)->execute($this->eventFor(42));
    }

    public function testSkipsWithoutQuizSession(): void
    {
        $this->session->stored = null;
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $this->observer('tenant', $publisher)->execute($this->eventFor(42));
    }

    /** A queue outage must never break order placement. */
    public function testPublishFailureDoesNotBreakCheckout(): void
    {
        $this->session->stored = self::COMPLETE_SESSION;
        $publisher = $this->createStub(PublisherInterface::class);
        $publisher->method('publish')->willThrowException(new \RuntimeException('broker down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->observer('tenant', $publisher, $logger)->execute($this->eventFor(42));
    }

    private function observer(?string $tenant, PublisherInterface $publisher, ?LoggerInterface $logger = null): ProcessConversion
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['bluebarry_module/general/tenantid', 'stores', 'default', $tenant],
            ['bluebarry_module/general/write_to_debug_file', 'stores', 'default', '0'],
        ]);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new ProcessConversion(
            $this->session,
            $scopeConfig,
            $storeManager,
            $logger ?? $this->createStub(LoggerInterface::class),
            $publisher
        );
    }

    private function eventFor(int $orderId): Observer
    {
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn($orderId);
        return new Observer(['order' => $order]);
    }
}
