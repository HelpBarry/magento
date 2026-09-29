<?php

namespace Bluebarry\Bluebarry\Test\Unit\Observer;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Observer\NoteAddToCart;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\Option;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class NoteAddToCartTest extends TestCase
{
    public function testKeepsOnlyWellFormedAddsAndTheLastTwenty(): void
    {
        $adds = [['r' => '18', 'q' => 2, 't' => 1, 'i' => 'a1'], ['r' => 'x', 'q' => 1, 't' => 1, 'i' => 'a2'], ['r' => '19', 'q' => 1, 't' => 1, 'i' => '<script>']];
        for ($n = 0; $n < 25; $n++) {
            $adds[] = ['r' => (string) (100 + $n), 'q' => 1, 't' => 1, 'i' => "b$n"];
        }

        $pending = NoteAddToCart::pending((string) json_encode($adds));

        $this->assertCount(20, $pending);
        $this->assertSame('105', $pending[0]['r']);
        $this->assertSame([], NoteAddToCart::pending('not json'));
    }

    private ?string $cookie = null;

    public function testEveryAddOfARequestIsKept_WithTheQuantityItAdded(): void
    {
        $this->cookie = (string) json_encode([['r' => '7', 'q' => 1, 't' => 1, 'i' => 'old']]);
        $observer = $this->observer();

        // A kit of two lines, the second a variant: its parent line names the variant, its own line is skipped.
        $observer->execute($this->added([$this->item(18, 2.0)]));
        $parent = $this->item(30, 3.0, variant: 31);
        $observer->execute($this->added([$parent, $this->item(31, 3.0, parent: $parent)]));

        $this->assertSame([['7', 1], ['18', 2], ['31', 3]], array_map(fn ($a) => [$a['r'], $a['q']], json_decode((string) $this->cookie, true)));
    }

    private function observer(): NoteAddToCart
    {
        $config = $this->createStub(Config::class);
        $config->method('getTenantId')->willReturn('tenant');
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $original = $this->cookie;
        $cookies = $this->createStub(CookieManagerInterface::class);
        // The request's cookies stay as the browser sent them.
        $cookies->method('getCookie')->willReturnCallback(fn ($name) => $name === 'bb_uid' ? 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' : $original);
        $cookies->method('setPublicCookie')->willReturnCallback(function ($name, $value) {
            $this->cookie = $value;
        });
        $metadata = $this->createStub(CookieMetadataFactory::class);
        $metadata->method('createPublicCookieMetadata')->willReturn(new PublicCookieMetadata());
        return new NoteAddToCart($config, $storeManager, $cookies, $metadata, $this->createStub(CookieHelper::class), new NullLogger());
    }

    private function added(array $items): Observer
    {
        return new Observer(['event' => new Event(['items' => $items])]);
    }

    private function item(int $productId, float $added, ?int $variant = null, ?Item $parent = null): Item
    {
        $class = new \ReflectionClass(Item::class);
        /** @var Item $item */
        $item = $class->newInstanceWithoutConstructor();
        $item->setData(['product_id' => $productId, 'qty_to_add' => $added]);
        $class->getProperty('_parentItem')->setValue($item, $parent);
        if ($variant) {
            $option = (new \ReflectionClass(Option::class))->newInstanceWithoutConstructor();
            $option->setData('value', $variant);
            $class->getProperty('_optionsByCode')->setValue($item, ['simple_product' => $option]);
        }
        return $item;
    }
}
