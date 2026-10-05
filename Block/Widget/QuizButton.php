<?php

namespace Bluebarry\Bluebarry\Block\Widget;

use Bluebarry\Bluebarry\Block\Placement;
use Magento\Widget\Block\BlockInterface;

/**
 * A button that opens a quiz, placed as a widget in a CMS page or block.
 */
class QuizButton extends Placement implements BlockInterface
{
    /**
     * @var string
     */
    protected $_template = 'Bluebarry_Bluebarry::widget/quiz-button.phtml';
}
