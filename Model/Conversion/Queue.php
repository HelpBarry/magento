<?php

namespace Bluebarry\Bluebarry\Model\Conversion;

use Bluebarry\Bluebarry\Model\ResourceModel\OrderVisitor;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Hands a paid order's conversion to the background: the message queue for right away, the cron
 * (SendDueConversions) as the fallback when no consumer runs. Never calls bluebarry itself.
 */
class Queue
{
    public const TOPIC = 'bluebarry.conversion.process';

    /**
     * @var OrderVisitor
     */
    private $visitors;

    /**
     * @var PublisherInterface
     */
    private $publisher;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param OrderVisitor $visitors
     * @param PublisherInterface $publisher
     * @param LoggerInterface $logger
     */
    public function __construct(OrderVisitor $visitors, PublisherInterface $publisher, LoggerInterface $logger)
    {
        $this->visitors = $visitors;
        $this->publisher = $publisher;
        $this->logger = $logger;
    }

    /**
     * An order counts as a sale once it is paid in full: processing or complete, with nothing left
     * due. Orders paid on delivery or by bank transfer wait until the merchant invoices them, also
     * when they were shipped first (which already makes them processing).
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isPaid(OrderInterface $order): bool
    {
        // A part the merchant cancelled is not due either.
        return in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true)
            && (float) $order->getGrandTotal() - (float) $order->getTotalCanceled() - (float) $order->getTotalPaid() < 0.005;
    }

    /**
     * Queues the conversion of a paid order that has a bluebarry visitor. Several payment events for
     * one order queue it once.
     *
     * @param int $orderId
     * @return void
     */
    public function queue(int $orderId): void
    {
        if (!$this->visitors->markQueued($orderId)) {
            return; // no visitor, or already queued
        }
        try {
            $this->publisher->publish(self::TOPIC, (string) $orderId);
        } catch (\Exception $e) {
            // The row is queued: the cron sends it once it is due.
            $this->logger->warning('bluebarry: conversion not published to the queue, the cron will send it', [
                'order_id' => $orderId, 'error' => $e->getMessage(),
            ]);
        }
    }
}
