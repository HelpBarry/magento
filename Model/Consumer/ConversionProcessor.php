<?php

namespace Bluebarry\Bluebarry\Model\Consumer;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

/**
 * Class ConversionProcessor
 * @package Bluebarry\Bluebarry\Model\Consumer
 */
class ConversionProcessor
{
    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param Curl $curl
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        Curl $curl
    ) {
        $this->orderRepository = $orderRepository;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->curl = $curl;
    }

    /**
     * Process conversion event from message queue
     *
     * @param string $conversionDataJson
     * @return void
     */
    public function processConversion($conversionDataJson)
    {
        try {
            $conversionData = json_decode($conversionDataJson, true);
            $loggingEnabled = $this->isWriteToLogEnabled();
            
            if (!$conversionData) {
                $this->logger->error('Invalid JSON data received in conversion consumer: ' . $conversionDataJson);
                return;
            }

            $orderId = $conversionData['order_id'] ?? null;
            $sessionData = $conversionData['session_data'] ?? null;
            $tenantId = $conversionData['tenant_id'] ?? null;

            if (!$orderId || !$sessionData || !$tenantId) {
                $this->logger->error('Missing required data in conversion message: ' . $conversionDataJson);
                return;
            }

            if ($loggingEnabled) {
                $this->logger->debug("--- Bluebarry async processing started ---");
                $this->logger->debug("Processing order ID: " . $orderId);
            }

            $order = $this->orderRepository->get($orderId);

            if (!is_array($sessionData) || 
                !isset($sessionData['bluebarry']) || 
                !isset($sessionData['bluebarry']['advisor_id']) || 
                !isset($sessionData['bluebarry']['user_id']) || 
                !isset($sessionData['bluebarry']['session_id'])
            ) {
                if ($loggingEnabled) {
                    $this->logger->debug("No valid bluebarry session found for order: " . $orderId);
                }
                return;
            }

            $itemTotalExlTax = 0;
            $itemTotalTax = 0;
            $items = [];
            foreach ($order->getAllItems() as $item) 
            {
                $itemTotalExlTax += ($item->getQtyOrdered() * $item->getPrice());

                if ($item->getTaxPercent() > 0) { 
                    $taxAmount = $item->getPrice() * ($item->getTaxPercent() / 100);
                    $itemTotalTax += ($item->getQtyOrdered() * $taxAmount);
                }

                $items[] = (object) [
                    "itemId" => $item->getItemId(),
                    "quantity" => (float) $item->getQtyOrdered(),
                    "value" => $item->getPrice(),
                    "taxPercentage" => (float) $item->getTaxPercent(),
                    "priceExclTax" => $item->getPrice(),
                    "priceInclTax" => (float) $item->getPriceInclTax(),
                ];
            }

            $request = (object) [
                "advisorId" => $sessionData['bluebarry']['advisor_id'],
                "userId" => $sessionData['bluebarry']['user_id'],
                "sessionId" => $sessionData['bluebarry']['session_id'],
                "value" => $itemTotalExlTax,
                "orderProductTotal" => $itemTotalExlTax, 
                "orderTaxTotal" => round(($itemTotalTax),2),
                "orderGrandTotal" => (round(($itemTotalTax),2) + $itemTotalExlTax), 
                "currencyIso" => "EUR",
                "conversionId" => (string) $order->getId(),
                "items" => $items
            ];

            if ($loggingEnabled) {
                $this->logger->debug("--- Bluebarry request ---");
                $this->logger->debug(json_encode($request));
                $this->logger->debug("--- Bluebarry request ---");
            }

            $result = $this->curlConversionRequest($request, $tenantId, $loggingEnabled);

            if ($loggingEnabled) {
                if (isset($result) && isset($result->id)) {
                    $this->logger->debug("Conversion successfully sent to Bluebarry. Response ID: " . $result->id);
                }
                else {
                    $this->logger->debug("Failed to send conversion to Bluebarry");
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('Error processing conversion in message queue: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Returns the write to debug file status
     * 
     * @return bool 
     */
    private function isWriteToLogEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(
            "bluebarry_module/general/write_to_debug_file"
        );
    }

    /**
     * Send conversion request to Bluebarry API
     *
     * @param object $data
     * @param string $tenantId
     * @return null
     */
    private function curlConversionRequest(object $data, string $tenantId, bool $loggingEnabled)
    {
        $url = "https://data.bluebarry.ai/data/conversionevents";
        
        $this->curl->addHeader("Content-Type", "application/json");
        $this->curl->addHeader("BB-Tenant-Id", $tenantId);
        $this->curl->setTimeout(30);
        
        $jsonData = json_encode($data);
        
        try {
            $this->curl->post($url, $jsonData);
            
            $responseBody = $this->curl->getBody();
            $httpStatus = $this->curl->getStatus();

            if ($loggingEnabled) {
                $this->logger->debug("--- Bluebarry API Response ---");
                $this->logger->debug("HTTP Status: " . $httpStatus);
                $this->logger->debug("Response Body: " . $responseBody);
                $this->logger->debug("--- Bluebarry API Response ---");
            }
            
            if ($httpStatus >= 200 && $httpStatus < 300) {
                return json_decode($responseBody);
            } else {
                if ($loggingEnabled) {
                    $this->logger->debug("--- Bluebarry API Error ---");
                    $this->logger->debug("HTTP Status: " . $httpStatus);
                    $this->logger->debug("Request URL: " . $url);
                    $this->logger->debug("Request Data: " . $jsonData);
                    $this->logger->debug("Response Body: " . $responseBody);
                    $this->logger->debug("--- Bluebarry API Error ---");
                }
                return null;
            }
            
        } catch (\Exception $e) {
            if ($loggingEnabled) {
                $this->logger->debug("--- Bluebarry API Exception ---");
                $this->logger->debug("Exception Message: " . $e->getMessage());
                $this->logger->debug("Request URL: " . $url);
                $this->logger->debug("Request Data: " . $jsonData);
                $this->logger->debug("--- Bluebarry API Exception ---");
            }
            return null;
        }
    }
}
