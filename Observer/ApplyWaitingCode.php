<?php

namespace Bluebarry\Bluebarry\Observer;

use Bluebarry\Bluebarry\Model\Discount\Cart;
use Magento\Checkout\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * A bluebarry code that the cart could not take when it was given (the cart was empty, or below the
 * code's minimum) waits in the shopper's session, and goes on the cart with the totals Magento is
 * collecting anyway: before them it is set, after them it is known whether the cart took it.
 *
 * Only on the storefront, only for a shopper who has such a code (one read of the session otherwise),
 * and only on a cart without a code: the shopper's own code is never pushed out.
 */
class ApplyWaitingCode implements ObserverInterface
{
    /** A code no cart of this shopper takes is given up on after this many totals. */
    private const MAX_TRIES = 30;

    /**
     * @var Session
     */
    private $checkoutSession;

    /**
     * @var Cart
     */
    private $cart;

    /** @var string|null the code set on the totals being collected */
    private $trying;

    /**
     * @param Session $checkoutSession
     * @param Cart $cart
     */
    public function __construct(Session $checkoutSession, Cart $cart)
    {
        $this->checkoutSession = $checkoutSession;
        $this->cart = $cart;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            $waiting = $this->checkoutSession->getData(Cart::SESSION_WAITING);
            if (!is_array($waiting) || !is_string($waiting['code'] ?? null)) {
                return;
            }
            /** @var \Magento\Quote\Model\Quote $quote */
            $quote = $observer->getEvent()->getData('quote');
            // A shopper's first product is added to a cart that is not saved yet, and so has no id.
            if (!$quote || ($quote->getId() && $this->checkoutSession->getQuoteId()
                && (int) $quote->getId() !== (int) $this->checkoutSession->getQuoteId())) {
                return; // not this shopper's cart
            }
            if ($observer->getEvent()->getName() === 'sales_quote_collect_totals_before') {
                $this->trying = null;
                // The lines themselves: the cart's count of them is only set by the totals.
                if ((string) $quote->getCouponCode() === '' && $quote->getAllVisibleItems()) {
                    $quote->setCouponCode($waiting['code']);
                    $this->trying = $waiting['code'];
                }
                return;
            }
            if ($this->trying === null) {
                return;
            }
            $code = $this->trying;
            $this->trying = null;
            if (strcasecmp((string) $quote->getCouponCode(), $code) === 0) {
                $this->cart->remember($code);
                $this->checkoutSession->unsetData(Cart::SESSION_WAITING);
            } elseif ((int) ($waiting['tries'] ?? 0) >= self::MAX_TRIES) {
                $this->checkoutSession->unsetData(Cart::SESSION_WAITING);
            } else {
                $this->checkoutSession->setData(Cart::SESSION_WAITING, ['code' => $code, 'tries' => (int) ($waiting['tries'] ?? 0) + 1]);
            }
        } catch (\Exception $e) {
            // Never in the way of the cart's totals.
            $this->trying = null;
        }
    }
}
