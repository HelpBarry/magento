<?php

namespace Bluebarry\Bluebarry\Model\Orders;

use Magento\Sales\Model\Order;

/**
 * An order as bluebarry stores it: the WooCommerce plugin's shape (DataApi WooCommerceOrder), so best
 * sellers, units sold, bought together, loyalty points and purchase-based segments read Magento's
 * orders the same way. Lines name products by the catalog's reference: a configurable product's variant.
 */
class OrderPayload
{
    private const PRECISION = 2;

    /**
     * @param Order $order
     * @param bool $live paid just now and not sent before: a new purchase
     * @return array
     */
    public function build(Order $order, bool $live): array
    {
        $variantByParent = [];
        foreach ($order->getAllItems() as $item) {
            if ($item->getParentItemId()) {
                $variantByParent[(int) $item->getParentItemId()] = (string) $item->getProductId();
            }
        }
        $lines = [];
        foreach ($order->getAllItems() as $item) {
            if ($item->getParentItemId()) {
                continue;
            }
            $quantity = max(1, (int) round((float) $item->getQtyOrdered()));
            $lines[] = [
                'id' => (string) $item->getItemId(),
                'reference' => $item->getProductType() === 'configurable'
                    ? ($variantByParent[(int) $item->getItemId()] ?? (string) $item->getProductId())
                    : (string) $item->getProductId(),
                'name' => mb_substr(trim(strip_tags((string) $item->getName())), 0, 512),
                'quantity' => $quantity,
                // As the shopper saw it: with tax, before discounts, like the WooCommerce plugin's.
                'unitPrice' => round((float) $item->getPriceInclTax(), self::PRECISION),
            ];
        }

        $total = (float) $order->getGrandTotal();
        $refunded = (float) $order->getTotalRefunded();
        $canceled = (float) $order->getTotalCanceled();
        $state = (string) $order->getState();
        $paid = in_array($state, [Order::STATE_PROCESSING, Order::STATE_COMPLETE, Order::STATE_CLOSED], true);
        if ($state === Order::STATE_CANCELED) {
            $status = 'VOIDED';
        } elseif ($refunded > 0 && $refunded >= $total - $canceled - 0.005) {
            $status = 'REFUNDED';
        } elseif (!$paid) {
            $status = 'PENDING';
        } else {
            $status = $refunded > 0 ? 'PARTIALLY_REFUNDED' : 'PAID';
        }

        $payload = [
            // The order number, as the merchant and the shopper know it, and as the conversion is keyed.
            'id' => (string) $order->getIncrementId(),
            'customerId' => $order->getCustomerId() ? (string) $order->getCustomerId() : null,
            'email' => $order->getCustomerEmail() ?: null,
            'firstName' => $order->getCustomerFirstname() ?: ($order->getBillingAddress() ? $order->getBillingAddress()->getFirstname() : null),
            'lastName' => $order->getCustomerLastname() ?: ($order->getBillingAddress() ? $order->getBillingAddress()->getLastname() : null),
            'createdAt' => $this->utc((string) $order->getCreatedAt()),
            'updatedAt' => $order->getUpdatedAt() ? $this->utc((string) $order->getUpdatedAt()) : null,
            'currency' => $order->getOrderCurrencyCode(),
            'totalPrice' => round($total, self::PRECISION),
            'currentTotalPrice' => round(max(0.0, $total - $refunded - $canceled), self::PRECISION),
            'financialStatus' => $status,
            'cancelledAt' => $state === Order::STATE_CANCELED && $order->getUpdatedAt() ? $this->utc((string) $order->getUpdatedAt()) : null,
            'discountCodes' => $order->getCouponCode() ? [(string) $order->getCouponCode()] : [],
            'totalDiscounts' => round(abs((float) $order->getDiscountAmount()), self::PRECISION),
            'live' => $live,
            'lines' => $lines,
        ];
        if ($order->getQuoteId()) {
            // The checkout it came from, so its abandoned checkout reminder stops.
            $payload['checkoutToken'] = (string) $order->getQuoteId();
        }
        return $payload;
    }

    /**
     * @param string $mysqlDate Magento stores order dates in UTC
     * @return string
     */
    private function utc(string $mysqlDate): string
    {
        return (new \DateTimeImmutable($mysqlDate, new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
