<?php

namespace Bluebarry\Bluebarry\Observer;

use Bluebarry\Bluebarry\Model\Heartbeat;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Saving the Bluebarry settings connects the website(s) they apply to right away, and says in the
 * admin whether bluebarry accepted the Tenant ID and API key.
 */
class SendHeartbeatOnSave implements ObserverInterface
{
    /**
     * @var Heartbeat
     */
    private $heartbeat;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ManagerInterface
     */
    private $messages;

    /**
     * @param Heartbeat $heartbeat
     * @param StoreManagerInterface $storeManager
     * @param ManagerInterface $messages
     */
    public function __construct(Heartbeat $heartbeat, StoreManagerInterface $storeManager, ManagerInterface $messages)
    {
        $this->heartbeat = $heartbeat;
        $this->storeManager = $storeManager;
        $this->messages = $messages;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $websiteId = (string) $observer->getEvent()->getData('website');
        $storeId = (string) $observer->getEvent()->getData('store');
        if ($storeId !== '') {
            $websites = [$this->storeManager->getWebsite($this->storeManager->getStore($storeId)->getWebsiteId())];
        } elseif ($websiteId !== '') {
            $websites = [$this->storeManager->getWebsite($websiteId)];
        } else {
            $websites = $this->storeManager->getWebsites(); // default scope: every website inherits it
        }

        foreach ($websites as $website) {
            try {
                $outcome = $this->heartbeat->send($website);
            } catch (\Exception $e) {
                $outcome = ['ok' => false, 'error' => $e->getMessage()];
            }
            if ($outcome === null) {
                continue;
            }
            $name = (string) $website->getName();
            if ($outcome['ok']) {
                $this->messages->addSuccessMessage(__('%1 is connected to bluebarry.', $name));
            } else {
                $this->messages->addErrorMessage(__('%1 is not connected to bluebarry: %2', $name, $outcome['error']));
            }
        }
    }
}
