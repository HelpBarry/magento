<?php

namespace Bluebarry\Bluebarry\Block\Adminhtml\System\Config;

use Bluebarry\Bluebarry\Model\Catalog\Sync;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Catalog" line on the settings page: whether the products reached bluebarry, and Sync now, which
 * sends them right away (Controller\Adminhtml\Catalog\Sync).
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
        $id = $element->getHtmlId();
        // No inline script (the admin's Content Security Policy): view/adminhtml/web/js/catalog-sync.js.
        $init = [
            'Bluebarry_Bluebarry/js/catalog-sync' => [
                'url' => $this->getUrl('bluebarry/catalog/sync'),
                'messageId' => $id . '_sync_message',
                'failed' => (string) __('Could not reach the store. Try again.'),
            ],
        ];
        return '<span id="' . $this->escapeHtmlAttr($id) . '_status">' . $this->escapeHtml((string) $text) . '</span>'
            . '<div class="bluebarry-catalog-sync">'
            . '<button type="button" class="action-secondary" id="' . $this->escapeHtmlAttr($id) . '_sync"'
            . ' data-mage-init="' . $this->escapeHtmlAttr((string) json_encode($init)) . '">'
            . '<span>' . $this->escapeHtml((string) __('Sync now')) . '</span></button> '
            . '<span id="' . $this->escapeHtmlAttr($id) . '_sync_message" class="bluebarry-catalog-sync-message" role="status"></span>'
            . '</div>';
    }
}
