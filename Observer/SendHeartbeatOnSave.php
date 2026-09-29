<?php

namespace Bluebarry\Bluebarry\Observer;

use Bluebarry\Bluebarry\Model\Heartbeat;
use Bluebarry\Bluebarry\Model\Storefront;
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
    /** Seconds a save spends reading the websites' settings; the 10-minute cron reads the rest. */
    private const REFRESH_BUDGET = 15;

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
     * @var Storefront
     */
    private $storefront;

    /**
     * @param Heartbeat $heartbeat
     * @param StoreManagerInterface $storeManager
     * @param ManagerInterface $messages
     * @param Storefront $storefront
     */
    public function __construct(Heartbeat $heartbeat, StoreManagerInterface $storeManager, ManagerInterface $messages, Storefront $storefront)
    {
        $this->storefront = $storefront;
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
        $started = time();
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
                // Not connected (any more): its search goes off now. No call to bluebarry.
                try {
                    $this->storefront->refresh($website);
                } catch (\Exception $e) {
                    // The schedule catches up.
                }
                continue;
            }
            $name = (string) $website->getName();
            if ($outcome['ok']) {
                // What bluebarry has for it (search), right away rather than on the next schedule; for
                // at most about 15 seconds of the save, however many websites it covers.
                try {
                    // Each read only as long as the budget has left.
                    $left = self::REFRESH_BUDGET - (time() - $started);
                    if ($left >= 1) {
                        $this->storefront->refresh($website, $left);
                    }
                } catch (\Exception $e) {
                    // The schedule catches up.
                }
                $this->messages->addSuccessMessage(__('%1 is connected to bluebarry.', $name));
            } else {
                $this->messages->addErrorMessage(__('%1 is not connected to bluebarry: %2', $name, $outcome['error']));
            }
        }
    }
}
