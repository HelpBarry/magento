<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Orders;

use Bluebarry\Bluebarry\Model\Orders\OrderPayload;
use Bluebarry\Bluebarry\Model\Orders\Sync;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use PHPUnit\Framework\TestCase;

class OrderPayloadTest extends TestCase
{
    public function testAConfigurableLineIsItsVariant_AtThePriceTheShopperSaw(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, [
            $this->item(10, null, 'configurable', '4', 2, 121.0),
            $this->item(11, 10, 'simple', '3', 2, 0.0),
            $this->item(12, null, 'simple', '1', 1, 60.5),
        ]);

        $payload = (new OrderPayload())->build($order, true);

        $this->assertSame([
            ['id' => '10', 'reference' => '3', 'name' => 'Line 10', 'quantity' => 2, 'unitPrice' => 121.0],
            ['id' => '12', 'reference' => '1', 'name' => 'Line 12', 'quantity' => 1, 'unitPrice' => 60.5],
        ], $payload['lines']);
        $this->assertSame(['000001042', 'PAID', true, '77', '2026-09-28T12:30:00+00:00'], [$payload['id'], $payload['financialStatus'], $payload['live'], $payload['checkoutToken'], $payload['createdAt']]);
    }

    public function testRefundsAndCancellationsAreNamedAsShopifyNamesThem(): void
    {
        $this->assertSame('PARTIALLY_REFUNDED', (new OrderPayload())->build($this->order(Order::STATE_PROCESSING, [], refunded: 50.0), false)['financialStatus']);
        $refunded = (new OrderPayload())->build($this->order(Order::STATE_CLOSED, [], refunded: 302.5), false);
        $this->assertSame(['REFUNDED', 0.0], [$refunded['financialStatus'], $refunded['currentTotalPrice']]);
        $this->assertSame('VOIDED', (new OrderPayload())->build($this->order(Order::STATE_CANCELED, []), false)['financialStatus']);
        $this->assertSame('PENDING', (new OrderPayload())->build($this->order(Order::STATE_NEW, []), false)['financialStatus']);
    }

    public function testANewPurchaseIsOneNeverSentAndPlacedRecently(): void
    {
        $recent = $this->order(Order::STATE_PROCESSING, [], createdAt: gmdate('Y-m-d H:i:s', time() - 3600));
        $this->assertTrue(Sync::isNewPurchase($recent, false));
        $this->assertFalse(Sync::isNewPurchase($recent, true));
        // An old order the merchant finally marks paid is history.
        $this->assertFalse(Sync::isNewPurchase($this->order(Order::STATE_PROCESSING, [], createdAt: gmdate('Y-m-d H:i:s', time() - 30 * 86400)), false));
    }

    private function order(string $state, array $items, float $refunded = 0.0, string $createdAt = '2026-09-28 12:30:00'): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getAllItems')->willReturn($items);
        $order->method('getIncrementId')->willReturn('000001042');
        $order->method('getState')->willReturn($state);
        $order->method('getCreatedAt')->willReturn($createdAt);
        $order->method('getGrandTotal')->willReturn(302.5);
        $order->method('getTotalRefunded')->willReturn($refunded);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getCustomerEmail')->willReturn('buyer@example.com');
        $order->method('getQuoteId')->willReturn('77');
        return $order;
    }

    private function item(int $id, ?int $parentId, string $type, string $productId, float $qty, float $priceInclTax): Item
    {
        $item = $this->createStub(Item::class);
        $item->method('getItemId')->willReturn($id);
        $item->method('getParentItemId')->willReturn($parentId);
        $item->method('getProductType')->willReturn($type);
        $item->method('getProductId')->willReturn($productId);
        $item->method('getQtyOrdered')->willReturn($qty);
        $item->method('getPriceInclTax')->willReturn($priceInclTax);
        $item->method('getName')->willReturn("Line $id");
        return $item;
    }
}
