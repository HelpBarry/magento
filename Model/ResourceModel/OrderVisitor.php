<?php

namespace Bluebarry\Bluebarry\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * The bluebarry_order_visitor table: which visitor placed an order, and whether its conversion has
 * been delivered. Every state change is a single statement on the primary key, so the queue consumer,
 * the cron and a second payment event can never both claim the same delivery.
 */
class OrderVisitor
{
    public const TABLE = 'bluebarry_order_visitor';

    /** Visitor known, order not paid yet. */
    public const STATUS_WAITING = 0;
    /** Paid; due at next_attempt_at (a retry, or the cron's catch-up when no consumer picked it up). */
    public const STATUS_QUEUED = 1;
    public const STATUS_SENT = 2;
    public const STATUS_FAILED = 3;

    /**
     * How long a queued conversion waits for the queue consumer before the cron sends it itself.
     */
    private const CONSUMER_GRACE_MINUTES = 10;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Records the visitor for an order. A second call for the same order changes nothing.
     *
     * @param int $orderId
     * @param array $visitor from \Bluebarry\Bluebarry\Model\Visitor::current()
     * @return void
     */
    public function capture(int $orderId, array $visitor): void
    {
        $this->connection()->insertOnDuplicate(
            $this->table(),
            [
                'order_id' => $orderId,
                'user_id' => $visitor['user_id'],
                'session_id' => $visitor['session_id'],
                'advisor_id' => $visitor['advisor_id'],
                'experiments' => $visitor['experiments'] ? json_encode($visitor['experiments']) : null,
            ],
            [] // keep the first capture
        );
    }

    /**
     * @param int $orderId
     * @return array|null
     */
    public function get(int $orderId): ?array
    {
        $row = $this->connection()->fetchRow(
            $this->connection()->select()->from($this->table())->where('order_id = ?', $orderId)
        );
        return $row ?: null;
    }

    /**
     * Marks a paid order's conversion as queued. True only for the call that made the change, so
     * several payment events for one order queue it once.
     *
     * @param int $orderId
     * @return bool
     */
    public function markQueued(int $orderId): bool
    {
        return $this->connection()->update(
            $this->table(),
            [
                'status' => self::STATUS_QUEUED,
                'next_attempt_at' => $this->minutesFromNow(self::CONSUMER_GRACE_MINUTES),
            ],
            ['order_id = ?' => $orderId, 'status = ?' => self::STATUS_WAITING]
        ) === 1;
    }

    /**
     * Claims a due conversion for the cron, so two overlapping cron runs don't both send it. True only
     * for the run that got it; an attempt that never finishes becomes due again after five minutes.
     * (The queue consumer sends without claiming: bluebarry keeps the first copy of an order.)
     *
     * @param int $orderId
     * @return bool
     */
    public function claimDue(int $orderId): bool
    {
        return $this->connection()->update(
            $this->table(),
            ['next_attempt_at' => $this->minutesFromNow(5)],
            [
                'order_id = ?' => $orderId,
                'status = ?' => self::STATUS_QUEUED,
                'next_attempt_at <= ?' => $this->minutesFromNow(0),
            ]
        ) === 1;
    }

    /**
     * @param int $orderId
     * @return void
     */
    public function markSent(int $orderId): void
    {
        $this->connection()->update(
            $this->table(),
            ['status' => self::STATUS_SENT, 'sent_at' => $this->minutesFromNow(0), 'next_attempt_at' => null],
            ['order_id = ?' => $orderId]
        );
    }

    /**
     * @param int $orderId
     * @param int $attempts
     * @param int $delayMinutes
     * @return void
     */
    public function scheduleRetry(int $orderId, int $attempts, int $delayMinutes): void
    {
        $this->connection()->update(
            $this->table(),
            ['attempts' => $attempts, 'next_attempt_at' => $this->minutesFromNow($delayMinutes)],
            ['order_id = ?' => $orderId]
        );
    }

    /**
     * @param int $orderId
     * @param int $attempts
     * @return void
     */
    public function markFailed(int $orderId, int $attempts): void
    {
        $this->connection()->update(
            $this->table(),
            ['status' => self::STATUS_FAILED, 'attempts' => $attempts, 'next_attempt_at' => null],
            ['order_id = ?' => $orderId]
        );
    }

    /**
     * Queued conversions that are due: retries, and paid orders the queue consumer did not pick up.
     *
     * @param int $limit
     * @return int[]
     */
    public function due(int $limit): array
    {
        return array_map('intval', $this->connection()->fetchCol(
            $this->connection()->select()
                ->from($this->table(), ['order_id'])
                ->where('status = ?', self::STATUS_QUEUED)
                ->where('next_attempt_at <= ?', $this->minutesFromNow(0))
                ->order('next_attempt_at ASC')
                ->limit($limit)
        ));
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private function connection()
    {
        return $this->resource->getConnection();
    }

    /**
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    /**
     * A UTC timestamp, as Magento stores datetimes.
     *
     * @param int $minutes
     * @return string
     */
    private function minutesFromNow(int $minutes): string
    {
        return gmdate('Y-m-d H:i:s', time() + $minutes * 60);
    }
}
