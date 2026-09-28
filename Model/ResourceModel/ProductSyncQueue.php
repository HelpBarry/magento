<?php

namespace Bluebarry\Bluebarry\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * The bluebarry_product_sync table: products whose change still has to reach bluebarry. Queuing is
 * one statement however many products changed; the cron sends them in batches.
 *
 * A row is claimed by the run sending it and only removed with that claim, so a product changed again
 * while its batch was on its way stays queued.
 */
class ProductSyncQueue
{
    public const TABLE = 'bluebarry_product_sync';

    /** A product that cannot be read this many times is given up on (and logged). */
    public const MAX_ATTEMPTS = 5;

    /** Rows per insert when a mass action or an import queues many products. */
    private const CHUNK = 1000;

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
     * Queues products; a product already queued is queued afresh.
     *
     * @param int[] $productIds
     * @return void
     */
    public function enqueue(array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $now = gmdate('Y-m-d H:i:s');
        foreach (array_chunk($productIds, self::CHUNK) as $chunk) {
            $rows = [];
            foreach ($chunk as $id) {
                $rows[] = ['product_id' => $id, 'claim' => null, 'attempts' => 0, 'queued_at' => $now];
            }
            $this->connection()->insertOnDuplicate($this->table(), $rows, ['claim', 'attempts', 'queued_at']);
        }
    }

    /**
     * Queues every product in the catalog, in one statement.
     *
     * @return void
     */
    public function enqueueAll(): void
    {
        $this->connection()->query(sprintf(
            'INSERT INTO %s (product_id, claim, attempts, queued_at) SELECT entity_id, NULL, 0, UTC_TIMESTAMP() FROM %s'
            . ' ON DUPLICATE KEY UPDATE claim = NULL, attempts = 0, queued_at = VALUES(queued_at)',
            $this->connection()->quoteIdentifier($this->table()),
            $this->connection()->quoteIdentifier($this->resource->getTableName('catalog_product_entity'))
        ));
    }

    /**
     * The next queued products after a product id, in id order.
     *
     * @param int $limit
     * @param int $afterId
     * @return array<int, string> product id => queued_at (UTC)
     */
    public function next(int $limit, int $afterId = 0): array
    {
        $select = $this->connection()->select()
            ->from($this->table(), ['product_id', 'queued_at'])
            ->where('product_id > ?', $afterId)
            ->order('product_id')
            ->limit($limit);
        return array_map('strval', $this->connection()->fetchPairs($select));
    }

    /**
     * @param int[] $productIds
     * @param string $claim
     * @return void
     */
    public function claim(array $productIds, string $claim): void
    {
        if ($productIds) {
            $this->connection()->update($this->table(), ['claim' => $claim], ['product_id IN (?)' => $productIds]);
        }
    }

    /**
     * Removes the products a run sent, unless one changed again meanwhile.
     *
     * @param int[] $productIds
     * @param string $claim
     * @return void
     */
    public function remove(array $productIds, string $claim): void
    {
        if ($productIds) {
            $this->connection()->delete($this->table(), ['product_id IN (?)' => $productIds, 'claim = ?' => $claim]);
        }
    }

    /**
     * Counts a failed read; products that failed MAX_ATTEMPTS times are removed.
     *
     * @param int[] $productIds
     * @return int[] the products given up on
     */
    public function fail(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $connection = $this->connection();
        $connection->update(
            $this->table(),
            ['attempts' => new \Zend_Db_Expr('attempts + 1'), 'claim' => null],
            ['product_id IN (?)' => $productIds]
        );
        $where = ['product_id IN (?)' => $productIds, 'attempts >= ?' => self::MAX_ATTEMPTS];
        $dropped = $connection->fetchCol($connection->select()->from($this->table(), 'product_id')
            ->where('product_id IN (?)', $productIds)->where('attempts >= ?', self::MAX_ATTEMPTS));
        $connection->delete($this->table(), $where);
        return array_map('intval', $dropped);
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return (int) $this->connection()->fetchOne($this->connection()->select()->from($this->table(), 'COUNT(*)'));
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
}
