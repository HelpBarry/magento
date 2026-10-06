<?php

namespace Bluebarry\Bluebarry\Test\Unit\Double;

use Magento\Checkout\Model\Session;

/**
 * The shopper's checkout session for tests: its cart, and what the module keeps in it.
 */
class CheckoutSessionDouble extends Session
{
    /** @var QuoteDouble */
    public $quote;

    /** @var array */
    public $data = [];

    /** @var bool whether the session was let go (written and unlocked) */
    public $closed = false;

    /** @var string[] what was set after that, and so lost */
    public $lost = [];

    public function __construct(QuoteDouble $quote)
    {
        $this->quote = $quote;
    }

    public function getQuote()
    {
        return $this->quote;
    }

    public function getQuoteId()
    {
        return $this->quote->quoteId;
    }

    public function getData($key = '', $clear = false)
    {
        return $this->data[$key] ?? null;
    }

    public function setData($key, $value = null)
    {
        if ($this->closed) {
            $this->lost[] = (string) $key;
            return $this;
        }
        $this->data[$key] = $value;
        return $this;
    }

    public function writeClose()
    {
        $this->closed = true;
    }

    public function unsetData($key = null)
    {
        unset($this->data[$key]);
        return $this;
    }
}
