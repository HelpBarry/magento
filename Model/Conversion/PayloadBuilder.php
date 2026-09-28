<?php

namespace Bluebarry\Bluebarry\Model\Conversion;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * The conversion bluebarry records for a paid order: the same fields and meaning as the Shopify pixel
 * and the WooCommerce plugin, so revenue compares across platforms.
 */
class PayloadBuilder
{
    private const PRECISION = 2;

    /**
     * @param OrderInterface $order
     * @param array $visitor row from bluebarry_order_visitor
     * @param string $storeKey the store's host
     * @return array
     */
    public function build(OrderInterface $order, array $visitor, string $storeKey): array
    {
        $variantByParent = [];
        foreach ($order->getItems() as $item) {
            if ($item->getParentItemId()) {
                // A configurable line's child is the variant the shopper picked, which is what the
                // catalog lists; a bundle's children are its parts, which are not reported.
                $variantByParent[(int) $item->getParentItemId()] = (string) $item->getProductId();
            }
        }

        $items = [];
        $productTotal = 0.0;
        foreach ($order->getItems() as $item) {
            // Child lines repeat the product the shopper bought (at 0, or for a dynamic-price bundle at
            // the part prices already summed into the bundle line).
            if ($item->getParentItemId()) {
                continue;
            }
            $quantity = max(1.0, (float) $item->getQtyOrdered());
            $net = $this->netRowTotal($item);
            $gross = $net + (float) $item->getTaxAmount();
            $productTotal += $net;

            $reference = $item->getProductType() === 'configurable'
                ? ($variantByParent[(int) $item->getItemId()] ?? (string) $item->getProductId())
                : (string) $item->getProductId();

            $items[] = [
                'itemId' => $reference,
                'quantity' => $quantity,
                'priceExclTax' => round($net / $quantity, self::PRECISION),
                'priceInclTax' => round($gross / $quantity, self::PRECISION),
                'taxPercentage' => $net > 0 ? round(((float) $item->getTaxAmount()) / $net * 100, 2) : 0.0,
                'value' => round($gross / $quantity, self::PRECISION),
            ];
        }

        $grandTotal = round((float) $order->getGrandTotal(), self::PRECISION);
        $payload = [
            'userId' => $visitor['user_id'],
            'commerceSource' => 'Magento',
            'commerceStoreKey' => $storeKey,
            // Keyed like the order, so bluebarry's order history recognises this purchase as that order.
            'conversionId' => (string) $order->getEntityId(),
            'occurredAtUtc' => $this->utc((string) $order->getCreatedAt()),
            'currencyIso' => $order->getOrderCurrencyCode(),
            // The products after discounts, without tax or shipping.
            'orderProductTotal' => round($productTotal, self::PRECISION),
            'orderTaxTotal' => round((float) $order->getTaxAmount(), self::PRECISION),
            'orderGrandTotal' => $grandTotal,
            'value' => $grandTotal,
            'items' => $items,
        ];
        if (!empty($visitor['session_id'])) {
            $payload['sessionId'] = $visitor['session_id'];
        }
        if (!empty($visitor['advisor_id'])) {
            $payload['advisorId'] = $visitor['advisor_id'];
        }
        $experiments = json_decode((string) ($visitor['experiments'] ?? ''), true);
        if (is_array($experiments) && $experiments) {
            $payload['experimentContexts'] = $experiments;
        }
        return $payload;
    }

    /**
     * The line after discounts, without tax: Magento's row total minus the discount, plus the part of
     * the discount that was taken off tax (prices entered including tax).
     *
     * @param OrderItemInterface $item
     * @return float
     */
    private function netRowTotal(OrderItemInterface $item): float
    {
        return (float) $item->getRowTotal()
            - (float) $item->getDiscountAmount()
            + (float) $item->getDiscountTaxCompensationAmount();
    }

    /**
     * Magento stores order times in UTC as "Y-m-d H:i:s".
     *
     * @param string $createdAt
     * @return string ISO 8601
     */
    private function utc(string $createdAt): string
    {
        $time = $createdAt !== '' ? strtotime($createdAt . ' UTC') : false;
        return gmdate('c', $time !== false ? $time : time());
    }
}
