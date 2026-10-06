<?php

namespace Bluebarry\Bluebarry\Test\Unit\Double;

use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote\Item;

/**
 * A cart line for tests: a product, and the variant chosen when it is a configurable product.
 */
class QuoteItemDouble extends Item
{
    /** @var int */
    private $productId;

    /** @var int|null */
    private $variantId;

    public function __construct(int $productId, ?int $variantId = null)
    {
        $this->productId = $productId;
        $this->variantId = $variantId;
    }

    public function getProductId()
    {
        return $this->productId;
    }

    public function getOptionByCode($code)
    {
        return $code === 'simple_product' && $this->variantId !== null ? new DataObject(['value' => (string) $this->variantId]) : null;
    }
}
