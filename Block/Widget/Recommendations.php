<?php

namespace Bluebarry\Bluebarry\Block\Widget;

use Bluebarry\Bluebarry\Block\Placement;
use Magento\Widget\Block\BlockInterface;

/**
 * A recommendation block, placed as a widget: from the product on a product page, else from the cart.
 */
class Recommendations extends Placement implements BlockInterface
{
    /**
     * @var string
     */
    protected $_template = 'Bluebarry_Bluebarry::placement/recommendations.phtml';
}
