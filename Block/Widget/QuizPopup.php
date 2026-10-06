<?php

namespace Bluebarry\Bluebarry\Block\Widget;

use Bluebarry\Bluebarry\Block\Placement;
use Magento\Widget\Block\BlockInterface;

/**
 * Opens a quiz as a popup on the page the widget is on, once per cooldown.
 */
class QuizPopup extends Placement implements BlockInterface
{
    /**
     * @var string
     */
    protected $_template = 'Bluebarry_Bluebarry::widget/quiz-popup.phtml';
}
