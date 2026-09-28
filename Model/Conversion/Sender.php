<?php

namespace Bluebarry\Bluebarry\Model\Conversion;

use Bluebarry\Bluebarry\Model\Api\Client;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderVisitor;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Delivers a paid order's conversion to bluebarry, then identifies the buyer. Runs from the queue
 * consumer and the cron only. A delivery that gets no answer or a server error is tried again after
 * 1, 4, 9 and 16 minutes, so a short outage at bluebarry loses no orders.
 */
class Sender
{
    public const MAX_ATTEMPTS = 5;

    /**
     * @var OrderVisitor
     */
    private $visitors;

    /**
     * @var OrderRepositoryInterface
     */
    private $orders;

    /**
     * @var PayloadBuilder
     */
    private $payloadBuilder;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param OrderVisitor $visitors
     * @param OrderRepositoryInterface $orders
     * @param PayloadBuilder $payloadBuilder
     * @param Client $client
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderVisitor $visitors,
        OrderRepositoryInterface $orders,
        PayloadBuilder $payloadBuilder,
        Client $client,
        Config $config,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->visitors = $visitors;
        $this->orders = $orders;
        $this->payloadBuilder = $payloadBuilder;
        $this->client = $client;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * @param int $orderId
     * @return void
     */
    public function send(int $orderId): void
    {
        $visitor = $this->visitors->get($orderId);
        if ($visitor === null || (int) $visitor['status'] !== OrderVisitor::STATUS_QUEUED) {
            return;
        }
        $attempt = (int) $visitor['attempts'] + 1;

        try {
            $order = $this->orders->get($orderId);
        } catch (NoSuchEntityException $e) {
            $this->visitors->markFailed($orderId, $attempt);
            return;
        }
        $storeId = (int) $order->getStoreId();
        $tenantId = $this->config->getTenantId($storeId);
        if ($tenantId === null) {
            $this->visitors->markFailed($orderId, $attempt);
            $this->logger->warning('bluebarry: no tenant id for the order\'s store view, conversion not sent', ['order_id' => $orderId]);
            return;
        }

        $payload = $this->payloadBuilder->build($order, $visitor, $this->storeKey($storeId));
        if ($this->config->isDebugLogEnabled($storeId)) {
            $this->logger->debug('--- Bluebarry request ---');
            $this->logger->debug((string) json_encode($payload));
        }

        $response = $this->client->post('/data/conversionevents', $payload, $tenantId);
        if ($this->config->isDebugLogEnabled($storeId)) {
            $this->logger->debug('--- Bluebarry API Response ---');
            $this->logger->debug('HTTP Status: ' . $response->getStatus());
            $this->logger->debug('Response Body: ' . $response->getBody());
        }

        if ($response->isSuccess()) {
            $this->visitors->markSent($orderId);
            $this->identify($order, $visitor, $tenantId);
            return;
        }

        if ($response->isRetryable() && $attempt < self::MAX_ATTEMPTS) {
            // 1, 4, 9, 16 minutes.
            $this->visitors->scheduleRetry($orderId, $attempt, $attempt * $attempt);
            $this->logger->warning('bluebarry: conversion not delivered, will retry', [
                'order_id' => $orderId, 'status' => $response->getStatus(), 'attempt' => $attempt, 'error' => $response->getError(),
            ]);
            return;
        }

        $this->visitors->markFailed($orderId, $attempt);
        $this->logger->error('bluebarry: conversion not delivered', [
            'order_id' => $orderId, 'status' => $response->getStatus(), 'attempt' => $attempt,
            'body' => substr($response->getBody(), 0, 500), 'error' => $response->getError(),
        ]);
    }

    /**
     * The buyer's email joins their browsing, searches and quiz sessions to their customer profile.
     * Every buyer, not only quiz takers; bluebarry never overwrites an email it already has.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array $visitor
     * @param string $tenantId
     * @return void
     */
    private function identify($order, array $visitor, string $tenantId): void
    {
        $email = (string) $order->getCustomerEmail();
        if ($email === '') {
            return;
        }
        $body = ['email' => $email, 'userId' => $visitor['user_id']];
        if (!empty($visitor['session_id'])) {
            $body['sessionId'] = $visitor['session_id'];
        }
        $response = $this->client->post('/data/identify', $body, $tenantId);
        if (!$response->isSuccess()) {
            $this->logger->warning('bluebarry: buyer not identified', [
                'order_id' => $order->getEntityId(), 'status' => $response->getStatus(), 'error' => $response->getError(),
            ]);
        }
    }

    /**
     * The store's host, as the conversions and (later) the order sync name the store.
     *
     * @param int $storeId
     * @return string
     */
    private function storeKey(int $storeId): string
    {
        $host = parse_url((string) $this->storeManager->getStore($storeId)->getBaseUrl(), PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }
}
