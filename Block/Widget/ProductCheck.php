<?php

namespace Bluebarry\Bluebarry\Block\Widget;

use Bluebarry\Bluebarry\Block\Placement;
use Magento\Widget\Block\BlockInterface;

/**
 * The product check button, placed as a widget on a product page (a layout update or a product's content).
 */
class ProductCheck extends Placement implements BlockInterface
{
    /**
     * @var string
     */
    protected $_template = 'Bluebarry_Bluebarry::placement/product-check.phtml';
}
