<?php

namespace Bluebarry\Bluebarry\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * The bluebarry_order_sync table: orders waiting to go to bluebarry, and when each was last sent. A
 * row is claimed by the run sending it and only marked sent with that claim, so an order that changed
 * again while its batch was on its way goes again.
 */
class OrderSyncQueue
{
    public const TABLE = 'bluebarry_order_sync';

    /** An order bluebarry refused this many times is given up on (and logged). */
    public const MAX_ATTEMPTS = 10;

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
     * Queues an order. A waiting order stays a new purchase unless it stopped being a sale ($notLive).
     *
     * @param int $orderId
     * @param bool $live
     * @param bool $notLive
     * @return void
     */
    public function enqueue(int $orderId, bool $live, bool $notLive = false): void
    {
        $connection = $this->connection();
        $connection->query(sprintf(
            'INSERT INTO %s (order_id, queued_at, live) VALUES (?, UTC_TIMESTAMP(), ?)'
            . ' ON DUPLICATE KEY UPDATE queued_at = UTC_TIMESTAMP(), claim = NULL, attempts = 0,'
            // Sent (or imported) once, it is never a new purchase again, whatever the caller read before.
            . ' live = IF(? OR synced_at IS NOT NULL, 0, GREATEST(live, VALUES(live)))',
            $connection->quoteIdentifier($this->table())
        ), [$orderId, $live ? 1 : 0, $notLive ? 1 : 0]);
    }

    /**
     * @param int $orderId
     * @return array{synced: bool, queued: bool}
     */
    public function state(int $orderId): array
    {
        $row = $this->connection()->fetchRow($this->connection()->select()
            ->from($this->table(), ['synced_at', 'queued_at'])->where('order_id = ?', $orderId));
        return ['synced' => !empty($row['synced_at']), 'queued' => !empty($row['queued_at'])];
    }

    /**
     * Claims the oldest waiting orders and returns them. Orders are read after they are claimed, so a
     * change saved meanwhile is in what goes, or clears the claim and goes again.
     *
     * @param int $limit
     * @param string $claim
     * @param int[] $skipStoreIds store views whose website is waiting to try again
     * @return array<int, bool> order id => live
     */
    public function claimNext(int $limit, string $claim, array $skipStoreIds = [], array $skipOrderIds = []): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(['queue' => $this->table()], 'order_id')
            ->where('queue.queued_at IS NOT NULL')
            ->order(['queue.queued_at', 'queue.order_id'])
            ->limit($limit);
        if ($skipOrderIds) {
            $select->where('queue.order_id NOT IN (?)', $skipOrderIds);
        }
        if ($skipStoreIds) {
            $select->join(['sales_order' => $this->resource->getTableName('sales_order')], 'sales_order.entity_id = queue.order_id', [])
                ->where('sales_order.store_id NOT IN (?)', $skipStoreIds);
        }
        $ids = $connection->fetchCol($select);
        if (!$ids) {
            return [];
        }
        $connection->update($this->table(), ['claim' => $claim], ['order_id IN (?)' => $ids]);
        $rows = $connection->fetchPairs($connection->select()
            ->from($this->table(), ['order_id', 'live'])->where('claim = ?', $claim)->order('order_id'));
        return array_map(function ($live) {
            return (bool) (int) $live;
        }, $rows);
    }

    /**
     * Gives claimed orders back for a later run.
     *
     * @param int[] $orderIds
     * @param string $claim
     * @return void
     */
    public function release(array $orderIds, string $claim): void
    {
        if ($orderIds) {
            $this->connection()->update($this->table(), ['claim' => null], ['order_id IN (?)' => $orderIds, 'claim = ?' => $claim]);
        }
    }

    /**
     * The orders a run sent, unless one changed again meanwhile.
     *
     * @param int[] $orderIds
     * @param string $claim
     * @return void
     */
    public function markSent(array $orderIds, string $claim): void
    {
        if ($orderIds) {
            $this->connection()->update(
                $this->table(),
                ['queued_at' => null, 'claim' => null, 'live' => 0, 'attempts' => 0, 'synced_at' => gmdate('Y-m-d H:i:s')],
                ['order_id IN (?)' => $orderIds, 'claim = ?' => $claim]
            );
            // Changed while on its way: the change goes too, but bluebarry has the order now, so it is no
            // new purchase any more.
            $this->connection()->update(
                $this->table(),
                ['live' => 0, 'synced_at' => new \Zend_Db_Expr('COALESCE(synced_at, UTC_TIMESTAMP())')],
                ['order_id IN (?)' => $orderIds, 'claim IS NULL']
            );
        }
    }

    /**
     * Which of these orders this run still holds, and whether each is a new purchase now: a change
     * saved since it was claimed takes it back (and may have made it no sale).
     *
     * @param int[] $orderIds
     * @param string $claim
     * @return array<int, bool> order id => live
     */
    public function claimed(array $orderIds, string $claim): array
    {
        if (!$orderIds) {
            return [];
        }
        $connection = $this->connection();
        return array_map(function ($live) {
            return (bool) (int) $live;
        }, $connection->fetchPairs($connection->select()->from($this->table(), ['order_id', 'live'])
            ->where('order_id IN (?)', $orderIds)->where('claim = ?', $claim)));
    }

    /**
     * Orders the history import sent: known to bluebarry from now on, without being queued.
     *
     * @param int[] $orderIds
     * @return void
     */
    public function markImported(array $orderIds): void
    {
        $rows = [];
        foreach ($orderIds as $id) {
            $rows[] = ['order_id' => (int) $id, 'synced_at' => gmdate('Y-m-d H:i:s'), 'live' => 0];
        }
        if ($rows) {
            // Imported is known to bluebarry: a change queued meanwhile still goes, but as no new purchase.
            $this->connection()->insertOnDuplicate($this->table(), $rows, ['synced_at', 'live']);
        }
    }

    /**
     * Counts a refusal; orders refused MAX_ATTEMPTS times stop waiting.
     *
     * @param int[] $orderIds
     * @param string $claim
     * @return int[] the orders given up on
     */
    public function fail(array $orderIds, string $claim): array
    {
        if (!$orderIds) {
            return [];
        }
        $connection = $this->connection();
        $connection->update(
            $this->table(),
            ['attempts' => new \Zend_Db_Expr('attempts + 1'), 'claim' => null],
            ['order_id IN (?)' => $orderIds, 'claim = ?' => $claim]
        );
        $dropped = array_map('intval', $connection->fetchCol($connection->select()->from($this->table(), 'order_id')
            ->where('order_id IN (?)', $orderIds)->where('attempts >= ?', self::MAX_ATTEMPTS)->where('queued_at IS NOT NULL')));
        if ($dropped) {
            // Still failed: a change saved meanwhile reset its attempts, and stays queued.
            $connection->update($this->table(), ['queued_at' => null, 'live' => 0], ['order_id IN (?)' => $dropped, 'attempts >= ?' => self::MAX_ATTEMPTS]);
        }
        return $dropped;
    }

    /**
     * @return int
     */
    public function waiting(): int
    {
        return (int) $this->connection()->fetchOne($this->connection()->select()
            ->from($this->table(), 'COUNT(*)')->where('queued_at IS NOT NULL'));
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
    public function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
