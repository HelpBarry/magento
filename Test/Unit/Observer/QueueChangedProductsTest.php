<?php

namespace Bluebarry\Bluebarry\Test\Unit\Observer;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\ResourceModel\ProductSyncQueue;
use Bluebarry\Bluebarry\Observer\QueueChangedProducts;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class QueueChangedProductsTest extends TestCase
{
    private array $queued = [];
    private array $bundlesOf = [];

    public function testEveryOrderOfAMultiAddressCheckoutIsQueued(): void
    {
        $this->observer()->execute($this->event('checkout_submit_all_after', ['orders' => [$this->order([3, 4]), $this->order([5])]]));

        $this->assertSame([[3, 4, 5]], $this->queued);
    }

    public function testADeletedProductQueuesTheBundlesItIsIn(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn(9);

        $this->observer(connected: false)->execute($this->event('catalog_product_delete_before', ['product' => $product]));

        $this->assertSame([[9]], $this->queued);
        $this->assertSame([[9]], $this->bundlesOf);
    }

    public function testADeletedConfigurableProductQueuesItsVariants(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn(20);
        $product->method('getTypeId')->willReturn('configurable');

        $this->observer()->execute($this->event('catalog_product_delete_before', ['product' => $product]));

        $this->assertSame([[20, 21, 22]], $this->queued);
    }

    public function testAMassUpdateIsQueuedOnlyWhileConnected(): void
    {
        $this->observer(connected: false)->queueChanged([1, 2]);
        $this->assertSame([], $this->queued);

        $this->observer()->queueChanged([1, 2]);
        $this->assertSame([[1, 2]], $this->queued);
        $this->assertSame([], $this->bundlesOf);
    }

    private function observer(bool $connected = true): QueueChangedProducts
    {
        $website = $this->createStub(Website::class);
        $website->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([1 => $website]);
        $config = $this->createStub(Config::class);
        $config->method('getWebsiteTenantId')->willReturn($connected ? 'tenant' : null);
        $config->method('hasWebsiteApiKey')->willReturn($connected);

        $queue = $this->createStub(ProductSyncQueue::class);
        $queue->method('enqueue')->willReturnCallback(function ($ids) {
            $this->queued[] = $ids;
        });
        $queue->method('configurableChildren')->willReturn([21, 22]);
        $queue->method('enqueueBundlesWith')->willReturnCallback(function ($ids) {
            $this->bundlesOf[] = $ids;
        });
        return new QueueChangedProducts($config, $storeManager, $queue, new NullLogger());
    }

    private function event(string $name, array $data): Observer
    {
        $event = new Event($data);
        $event->setName($name);
        return new Observer(['event' => $event]);
    }

    private function order(array $productIds): Order
    {
        $items = array_map(function ($id) {
            $item = $this->createStub(Item::class);
            $item->method('getProductId')->willReturn($id);
            return $item;
        }, $productIds);
        $order = $this->createStub(Order::class);
        $order->method('getAllItems')->willReturn($items);
        return $order;
    }
}
