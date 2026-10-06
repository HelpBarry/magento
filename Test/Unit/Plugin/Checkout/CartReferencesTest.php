<?php

namespace Bluebarry\Bluebarry\Test\Unit\Plugin\Checkout;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Plugin\Checkout\CartReferences;
use Bluebarry\Bluebarry\Test\Unit\Double\QuoteDouble;
use Magento\Checkout\CustomerData\Cart;
use Magento\Checkout\Model\Session;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\Option;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class CartReferencesTest extends TestCase
{
    public function testNamesEachLineAsTheCatalogSyncDoes_TheVariantForAConfigurableProduct(): void
    {
        $plugin = $this->plugin([$this->line(16, 17), $this->line(10), $this->line(16, 17), $this->line(16, 18)]);

        $result = $plugin->afterGetSectionData($this->createStub(Cart::class), ['summary_count' => 4]);

        $this->assertSame(['summary_count' => 4, CartReferences::KEY => ['17', '10', '18'], CartReferences::COUPON_KEY => ''], $result);
    }

    public function testNamesTheCouponCodeOnTheCart_SoACodeTakenOffIsAChangeOfTheCartToo(): void
    {
        $result = $this->plugin([$this->line(10)], coupon: 'WELCOME10')->afterGetSectionData($this->createStub(Cart::class), ['summary_count' => 1]);

        $this->assertSame('WELCOME10', $result[CartReferences::COUPON_KEY]);
    }

    public function testAnEmptyCartNamesNone(): void
    {
        $result = $this->plugin([])->afterGetSectionData($this->createStub(Cart::class), ['summary_count' => 0]);

        $this->assertSame([], $result[CartReferences::KEY]);
    }

    public function testAStoreViewWithoutBluebarryIsLeftAsItIs(): void
    {
        $result = $this->plugin([$this->line(10)], connected: false)->afterGetSectionData($this->createStub(Cart::class), ['summary_count' => 1]);

        $this->assertSame(['summary_count' => 1], $result);
    }

    public function testACartThatCannotBeReadNeverBreaksTheMiniCart(): void
    {
        $session = $this->createStub(Session::class);
        $session->method('getQuote')->willThrowException(new \RuntimeException('no quote'));

        $result = (new CartReferences($session, $this->config(true), $this->stores()))->afterGetSectionData($this->createStub(Cart::class), ['summary_count' => 1]);

        $this->assertSame(['summary_count' => 1], $result);
    }

    private function plugin(array $items, bool $connected = true, string $coupon = ''): CartReferences
    {
        $quote = new QuoteDouble();
        $quote->lines = $items;
        $quote->coupon = $coupon;
        $session = $this->createStub(Session::class);
        $session->method('getQuote')->willReturn($quote);
        return new CartReferences($session, $this->config($connected), $this->stores());
    }

    private function config(bool $connected): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getTenantId')->willReturn($connected ? 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' : null);
        return $config;
    }

    private function stores(): StoreManagerInterface
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        return $stores;
    }

    private function line(int $productId, ?int $variantId = null): Item
    {
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()->onlyMethods(['getOptionByCode'])->getMock();
        $item->setData('product_id', $productId);
        $option = null;
        if ($variantId !== null) {
            $option = $this->getMockBuilder(Option::class)->disableOriginalConstructor()->onlyMethods(['getValue'])->getMock();
            $option->method('getValue')->willReturn((string) $variantId);
        }
        $item->method('getOptionByCode')->willReturn($option);
        return $item;
    }
}
