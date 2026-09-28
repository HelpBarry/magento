<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Conversion;

use Bluebarry\Bluebarry\Model\Conversion\PayloadBuilder;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use PHPUnit\Framework\TestCase;

class PayloadBuilderTest extends TestCase
{
    private const USER = '11111111-1111-4111-8111-111111111111';
    private const SESSION = '22222222-2222-4222-8222-222222222222';
    private const ADVISOR = '33333333-3333-4333-8333-333333333333';

    public function testSimpleOrderUsesTheSharedContract(): void
    {
        $order = $this->order([$this->item(1, null, 'simple', '7', 2, 200.0, 42.0)], 247.0, 42.0);

        $payload = (new PayloadBuilder())->build($order, $this->visitor(), 'shop.example');

        $this->assertSame(self::USER, $payload['userId']);
        $this->assertSame(self::SESSION, $payload['sessionId']);
        $this->assertSame(self::ADVISOR, $payload['advisorId']);
        $this->assertSame('Magento', $payload['commerceSource']);
        $this->assertSame('shop.example', $payload['commerceStoreKey']);
        $this->assertSame('1042', $payload['conversionId']);
        $this->assertSame('2026-09-28T12:30:00+00:00', $payload['occurredAtUtc']);
        $this->assertSame('EUR', $payload['currencyIso']);
        $this->assertSame(200.0, $payload['orderProductTotal']);
        $this->assertSame(42.0, $payload['orderTaxTotal']);
        $this->assertSame(247.0, $payload['orderGrandTotal']); // shipping included
        $this->assertSame(247.0, $payload['value']);
        $this->assertSame([[
            'itemId' => '7',
            'quantity' => 2.0,
            'priceExclTax' => 100.0,
            'priceInclTax' => 121.0,
            'taxPercentage' => 21.0,
            'value' => 121.0,
        ]], $payload['items']);
    }

    public function testDiscountsComeOffTheLines(): void
    {
        // EUR 100 line, EUR 10 off; tax on the discounted amount.
        $item = $this->item(1, null, 'simple', '7', 1, 100.0, 18.9, 10.0);
        $payload = (new PayloadBuilder())->build($this->order([$item], 113.9, 18.9), $this->visitor(), 'shop.example');

        $this->assertSame(90.0, $payload['orderProductTotal']);
        $this->assertSame(90.0, $payload['items'][0]['priceExclTax']);
        $this->assertSame(108.9, $payload['items'][0]['priceInclTax']);
        $this->assertSame(21.0, $payload['items'][0]['taxPercentage']);
    }

    public function testConfigurableIsReportedAsTheVariantPicked(): void
    {
        $order = $this->order([
            $this->item(39, null, 'configurable', '10', 1, 80.0, 16.8),
            $this->item(40, 39, 'simple', '12', 1, 0.0, 0.0),
        ], 101.8, 16.8);

        $items = (new PayloadBuilder())->build($order, $this->visitor(), 'shop.example')['items'];

        $this->assertCount(1, $items);
        $this->assertSame('12', $items[0]['itemId']);
        $this->assertSame(80.0, $items[0]['priceExclTax']);
    }

    public function testDynamicBundleCountsTheBundleLineOnce(): void
    {
        $order = $this->order([
            $this->item(41, null, 'bundle', '20', 1, 50.0, 10.5),
            $this->item(42, 41, 'simple', '21', 1, 30.0, 6.3),
            $this->item(43, 41, 'simple', '22', 1, 20.0, 4.2),
        ], 65.5, 10.5);

        $payload = (new PayloadBuilder())->build($order, $this->visitor(), 'shop.example');

        $this->assertSame(50.0, $payload['orderProductTotal']);
        $this->assertCount(1, $payload['items']);
        $this->assertSame('20', $payload['items'][0]['itemId']);
        $this->assertSame(60.5, $payload['items'][0]['priceInclTax']);
    }

    public function testVisitorWithoutQuizSendsOnlyTheVisitor(): void
    {
        $visitor = ['user_id' => self::USER, 'session_id' => null, 'advisor_id' => null, 'experiments' => null];
        $payload = (new PayloadBuilder())->build($this->order([], 0.0, 0.0), $visitor, 'shop.example');

        $this->assertArrayNotHasKey('sessionId', $payload);
        $this->assertArrayNotHasKey('advisorId', $payload);
        $this->assertArrayNotHasKey('experimentContexts', $payload);
    }

    public function testExperimentsRideAlong(): void
    {
        $experiments = [['domain' => 'search', 'targetId' => self::SESSION, 'exposureId' => self::ADVISOR]];
        $visitor = ['experiments' => json_encode($experiments)] + $this->visitor();

        $payload = (new PayloadBuilder())->build($this->order([], 0.0, 0.0), $visitor, 'shop.example');

        $this->assertSame($experiments, $payload['experimentContexts']);
    }

    private function visitor(): array
    {
        return ['user_id' => self::USER, 'session_id' => self::SESSION, 'advisor_id' => self::ADVISOR, 'experiments' => null];
    }

    private function order(array $items, float $grandTotal, float $taxAmount): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getItems')->willReturn($items);
        $order->method('getEntityId')->willReturn('1042');
        $order->method('getCreatedAt')->willReturn('2026-09-28 12:30:00');
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getTaxAmount')->willReturn($taxAmount);
        return $order;
    }

    private function item(int $id, ?int $parentId, string $type, string $productId, float $qty, float $rowTotal, float $tax, float $discount = 0.0): Item
    {
        $item = $this->createStub(Item::class);
        $item->method('getItemId')->willReturn($id);
        $item->method('getParentItemId')->willReturn($parentId);
        $item->method('getProductType')->willReturn($type);
        $item->method('getProductId')->willReturn($productId);
        $item->method('getQtyOrdered')->willReturn($qty);
        $item->method('getRowTotal')->willReturn($rowTotal);
        $item->method('getTaxAmount')->willReturn($tax);
        $item->method('getDiscountAmount')->willReturn($discount);
        $item->method('getDiscountTaxCompensationAmount')->willReturn(0.0);
        return $item;
    }
}
