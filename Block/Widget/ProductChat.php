<?php

namespace Bluebarry\Bluebarry\Block\Widget;

use Bluebarry\Bluebarry\Block\Placement;
use Magento\Widget\Block\BlockInterface;

/**
 * Product chat inline, placed as a widget.
 */
class ProductChat extends Placement implements BlockInterface
{
    /**
     * @var string
     */
    protected $_template = 'Bluebarry_Bluebarry::placement/product-chat.phtml';
}
