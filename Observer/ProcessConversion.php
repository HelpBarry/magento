<?php

namespace Bluebarry\Bluebarry\Observer;

use Bluebarry\Bluebarry\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

class ProcessConversion implements ObserverInterface
{
    const TOPIC_NAME = 'bluebarry.conversion.process';

     /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Bluebarry\Bluebarry\Model\Session
     */
    protected Session $session;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var PublisherInterface
     */
    protected $publisher;

    /**
     * @param Session $session
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param PublisherInterface $publisher
     */
    public function __construct(
        Session $session,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        PublisherInterface $publisher
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->session = $session;
        $this->logger = $logger;
        $this->publisher = $publisher;
    }

    /**
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getOrder();
        $session = $this->session->getSession();
        $tenantId = $this->getTenantId();

        if (!isset($tenantId) || 
            !is_array($session) || 
            !isset($session['bluebarry']) || 
            !isset($session['bluebarry']['advisor_id']) || 
            !isset($session['bluebarry']['user_id']) || 
            !isset($session['bluebarry']['session_id'])
        ) {

            if ($this->isWriteToLogEnabled()) {
                $this->logger->debug("--- Bluebarry request ---");
                $this->logger->debug("No bluebarry session found");
                $this->logger->debug("--- Bluebarry request ---");
            }

            return $this;
        }

        try {
            $conversionData = [
                'order_id' => $order->getId(),
                'session_data' => $session,
                'tenant_id' => $tenantId
            ];

            $this->publisher->publish(self::TOPIC_NAME, json_encode($conversionData));

            if ($this->isWriteToLogEnabled()) {
                $this->logger->debug("--- Bluebarry async processing ---");
                $this->logger->debug("Conversion published to queue for order ID: " . $order->getId());
                $this->logger->debug("--- Bluebarry async processing ---");
            }

        } catch (\Exception $e) {
            $this->logger->error('Error publishing conversion to message queue: ' . $e->getMessage());
        }

        return $this;
    }

    /**
     * Returns the tenant id
     * 
     * @return null|string 
     */
    private function getTenantId() : ?string
    {
        return $this->scopeConfig->getValue("bluebarry_module/general/tenantid", 'stores', $this->storeManager->getStore()->getCode());
    }

    /**
     * Returns the write to debug file status
     * 
     * @return bool 
     */
    private function isWriteToLogEnabled() : bool
    {
        return (bool) $this->scopeConfig->getValue("bluebarry_module/general/write_to_debug_file", 'stores', $this->storeManager->getStore()->getCode());
    }
}
