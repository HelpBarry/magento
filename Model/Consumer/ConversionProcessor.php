<?php

namespace Bluebarry\Bluebarry\Model\Consumer;

use Bluebarry\Bluebarry\Model\Conversion\Queue;
use Bluebarry\Bluebarry\Model\Conversion\Sender;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderVisitor;
use Bluebarry\Bluebarry\Model\Visitor;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Queue consumer for bluebarry.conversion.process: delivers a paid order's conversion right away.
 * The message is the order id. Failures never reach the queue (no requeue loops): the Sender
 * schedules a retry, and the cron takes it from there.
 */
class ConversionProcessor
{
    /**
     * @var Sender
     */
    private $sender;

    /**
     * @var OrderVisitor
     */
    private $visitors;

    /**
     * @var Queue
     */
    private $queue;

    /**
     * @var OrderRepositoryInterface
     */
    private $orders;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Sender $sender
     * @param OrderVisitor $visitors
     * @param Queue $queue
     * @param OrderRepositoryInterface $orders
     * @param LoggerInterface $logger
     */
    public function __construct(
        Sender $sender,
        OrderVisitor $visitors,
        Queue $queue,
        OrderRepositoryInterface $orders,
        LoggerInterface $logger
    ) {
        $this->sender = $sender;
        $this->visitors = $visitors;
        $this->queue = $queue;
        $this->orders = $orders;
        $this->logger = $logger;
    }

    /**
     * @param string $message the order id; JSON with the quiz session from module versions before 1.1
     * @return void
     */
    public function processConversion($message)
    {
        try {
            $message = trim((string) $message);
            if (ctype_digit($message)) {
                $this->sender->send((int) $message);
                return;
            }
            $this->processLegacyMessage($message);
        } catch (\Exception $e) {
            $this->logger->error('bluebarry: conversion message not processed: ' . $e->getMessage());
        }
    }

    /**
     * A message queued by an older version before an upgrade: `{order_id, session_data, tenant_id}`.
     * Its quiz visitor is recorded like a new capture, and the order goes out once it is paid.
     *
     * @param string $message
     * @return void
     */
    private function processLegacyMessage(string $message): void
    {
        $data = json_decode($message, true);
        $orderId = (int) ($data['order_id'] ?? 0);
        $quiz = $data['session_data']['bluebarry'] ?? null;
        if ($orderId <= 0 || !is_array($quiz) || !Visitor::isUuid($quiz['user_id'] ?? null)) {
            $this->logger->error('bluebarry: unreadable conversion message', ['message' => substr($message, 0, 500)]);
            return;
        }
        $this->visitors->capture($orderId, [
            'user_id' => strtolower($quiz['user_id']),
            'session_id' => Visitor::isUuid($quiz['session_id'] ?? null) ? strtolower($quiz['session_id']) : null,
            'advisor_id' => Visitor::isUuid($quiz['advisor_id'] ?? null) ? strtolower($quiz['advisor_id']) : null,
            'experiments' => [],
        ]);
        if ($this->queue->isPaid($this->orders->get($orderId)) && $this->visitors->markQueued($orderId)) {
            $this->sender->send($orderId);
        }
    }
}
