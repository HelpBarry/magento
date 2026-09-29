<?php

namespace Bluebarry\Bluebarry\Block\Adminhtml\System\Config;

use Bluebarry\Bluebarry\Model\Catalog\Sync;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Read-only "Catalog" line on the settings page: whether the products reached bluebarry.
 */
class Catalog extends Field
{
    /**
     * @var Sync
     */
    private $sync;

    /**
     * @param Context $context
     * @param Sync $sync
     * @param array $data
     */
    public function __construct(Context $context, Sync $sync, array $data = [])
    {
        $this->sync = $sync;
        parent::__construct($context, $data);
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $status = $this->sync->status();
        if ($status['error'] !== null && $status['queued'] > 0) {
            $text = __('%1 products waiting: %2 Trying again every 5 minutes.', $status['queued'], $status['error']);
        } elseif ($status['error'] !== null) {
            // The others received the changes; this company gets the whole catalog once it works.
            $text = __('Not sent: %1 Trying again every 5 minutes.', $status['error']);
        } elseif ($status['queued'] > 0) {
            $text = __('%1 products waiting to be sent.', $status['queued']);
        } elseif ($status['sent_at'] !== null) {
            $text = __('Up to date, last sent %1.', $this->formatDate(new \DateTime('@' . $status['sent_at']), \IntlDateFormatter::MEDIUM, true));
        } else {
            $text = __('Sent once a website is connected.');
        }
        return $this->escapeHtml((string) $text);
    }
}
