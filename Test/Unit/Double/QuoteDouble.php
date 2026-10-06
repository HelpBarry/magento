<?php

namespace Bluebarry\Bluebarry\Test\Unit\Double;

use Magento\Quote\Model\Quote;

/**
 * A cart for tests. Its coupon code is a magic method on the real one (Quote::__call), which PHPUnit
 * 12 can no longer mock, so this double implements what the module asks of a cart for real. The cart
 * takes a code when $accepts says so, the way Magento drops a code no rule answers to.
 */
class QuoteDouble extends Quote
{
    /** @var string */
    public $coupon = '';

    /** @var array the cart's lines */
    public $lines = [];

    /** @var int|null */
    public $quoteId = 7;

    /** @var callable(string): bool whether the cart takes a code */
    public $accepts;

    /** @var string[] every code the totals were collected with */
    public array $collected = [];

    /** @var bool */
    public $active = true;

    public function __construct()
    {
        $this->accepts = fn (string $code): bool => true;
    }

    public function getId()
    {
        return $this->quoteId;
    }

    public function getStoreId()
    {
        return 1;
    }

    public function setStoreId($storeId)
    {
        return $this;
    }

    public function loadByIdWithoutStore($quoteId)
    {
        return $this;
    }

    public function getIsActive()
    {
        return $this->active;
    }

    public function getCouponCode()
    {
        return $this->coupon;
    }

    public function setCouponCode($code)
    {
        $this->coupon = (string) $code;
        return $this;
    }

    public function getAllVisibleItems()
    {
        return $this->lines;
    }

    public function getItemsCount()
    {
        return count($this->lines);
    }

    public function getShippingAddress()
    {
        return new \Magento\Framework\DataObject();
    }

    public function setTotalsCollectedFlag($flag)
    {
        return $this;
    }

    public function collectTotals()
    {
        $this->collected[] = $this->coupon;
        if ($this->coupon !== '' && !($this->accepts)($this->coupon)) {
            $this->coupon = '';
        }
        return $this;
    }
}
