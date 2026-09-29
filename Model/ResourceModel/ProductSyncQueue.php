<?php

namespace Bluebarry\Bluebarry\Model\ResourceModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\MetadataPool;

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
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @param ResourceConnection $resource
     * @param MetadataPool $metadataPool
     */
    public function __construct(ResourceConnection $resource, MetadataPool $metadataPool)
    {
        $this->resource = $resource;
        $this->metadataPool = $metadataPool;
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
     * The children of configurable products.
     *
     * @param int|int[] $parentIds
     * @return int[]
     */
    public function configurableChildren($parentIds): array
    {
        $parentIds = array_map('intval', (array) $parentIds);
        $links = $this->resource->getTableName('catalog_product_super_link');
        if (!$parentIds || !$this->connection()->isTableExists($links)) {
            return [];
        }
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        return array_map('intval', $this->connection()->fetchCol($this->connection()->select()->distinct()
            ->from(['link' => $links], ['product_id'])
            ->join(['parent' => $this->resource->getTableName('catalog_product_entity')], "parent.$linkField = link.parent_id", [])
            ->where('parent.entity_id IN (?)', $parentIds)));
    }

    /**
     * Queues the bundles that have one of these products as a selection, in one statement.
     *
     * @param int[] $productIds
     * @return void
     */
    public function enqueueBundlesWith(array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $selections = $this->resource->getTableName('catalog_product_bundle_selection');
        if (!$productIds || !$this->connection()->isTableExists($selections)) {
            return;
        }
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $select = $this->connection()->select()->distinct()
            ->from(['selection' => $selections], [])
            ->join(['bundle' => $this->resource->getTableName('catalog_product_entity')], "bundle.$linkField = selection.parent_product_id", [
                'product_id' => 'entity_id',
                'claim' => new \Zend_Db_Expr('NULL'),
                'attempts' => new \Zend_Db_Expr('0'),
                'queued_at' => new \Zend_Db_Expr('UTC_TIMESTAMP()'),
            ])
            ->where('selection.product_id IN (?)', $productIds);
        $this->connection()->query($this->connection()->insertFromSelect(
            $select,
            $this->table(),
            ['product_id', 'claim', 'attempts', 'queued_at'],
            AdapterInterface::INSERT_ON_DUPLICATE
        ));
    }

    /**
     * Claims the next queued products after a product id, in id order, and returns them. Claimed in
     * one statement before they are read, so a change saved after this is never taken for one already
     * handled: saving clears the claim, and remove() spares the row.
     *
     * @param int $limit
     * @param int $afterId
     * @param string $claim
     * @return array<int, string> product id => queued_at (UTC)
     */
    public function claimNext(int $limit, int $afterId, string $claim): array
    {
        $connection = $this->connection();
        $connection->query(sprintf(
            'UPDATE %s SET claim = ? WHERE product_id > ? ORDER BY product_id LIMIT %d',
            $connection->quoteIdentifier($this->table()),
            $limit
        ), [$claim, $afterId]);
        $select = $connection->select()
            ->from($this->table(), ['product_id', 'queued_at'])
            ->where('claim = ?', $claim)
            ->order('product_id');
        return array_map('strval', $connection->fetchPairs($select));
    }

    /**
     * Gives claimed products back to the queue, for a later run.
     *
     * @param int[] $productIds
     * @param string $claim
     * @return void
     */
    public function release(array $productIds, string $claim): void
    {
        if ($productIds) {
            $this->connection()->update($this->table(), ['claim' => null], ['product_id IN (?)' => $productIds, 'claim = ?' => $claim]);
        }
    }

    /**
     * Queues products that are not queued yet, leaving queued ones (and their attempts) as they are.
     *
     * @param int[] $productIds
     * @return void
     */
    public function ensureQueued(array $productIds): void
    {
        $rows = [];
        foreach (array_unique(array_map('intval', $productIds)) as $id) {
            $rows[] = ['product_id' => $id, 'claim' => null, 'attempts' => 0, 'queued_at' => gmdate('Y-m-d H:i:s')];
        }
        if ($rows) {
            // Updating only the key leaves a queued row as it was.
            $this->connection()->insertOnDuplicate($this->table(), $rows, ['product_id']);
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
