<?php

namespace Bluebarry\Bluebarry\Block\Adminhtml\System\Config;

use Bluebarry\Bluebarry\Model\Heartbeat;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Read-only "Connection" line on the settings page: per website, whether bluebarry accepted the last
 * heartbeat and when it was sent.
 */
class Connection extends Field
{
    /**
     * @var Heartbeat
     */
    private $heartbeat;

    /**
     * @var StoreManagerInterface
     */
    private $storeManagerForWebsites;

    /**
     * @param Context $context
     * @param Heartbeat $heartbeat
     * @param StoreManagerInterface $storeManager
     * @param array $data
     */
    public function __construct(Context $context, Heartbeat $heartbeat, StoreManagerInterface $storeManager, array $data = [])
    {
        $this->heartbeat = $heartbeat;
        $this->storeManagerForWebsites = $storeManager;
        parent::__construct($context, $data);
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $outcomes = $this->heartbeat->outcomes();
        $websiteParam = (string) $this->getRequest()->getParam('website');
        $lines = [];
        foreach ($this->storeManagerForWebsites->getWebsites() as $website) {
            if ($websiteParam !== '' && (string) $website->getId() !== $websiteParam) {
                continue;
            }
            $outcome = $outcomes[(int) $website->getId()] ?? null;
            if ($outcome === null) {
                $status = __('Not connected: enter a Tenant ID and API key.');
            } elseif ($outcome['ok']) {
                $status = __('Connected, last heartbeat %1.', $this->formatDate(
                    (new \DateTime('@' . $outcome['at'])),
                    \IntlDateFormatter::MEDIUM,
                    true
                ));
            } else {
                $status = __('Not connected: %1', $outcome['error']);
            }
            $lines[] = '<strong>' . $this->escapeHtml((string) $website->getName()) . '</strong>: ' . $this->escapeHtml((string) $status);
        }
        return implode('<br/>', $lines);
    }
}
