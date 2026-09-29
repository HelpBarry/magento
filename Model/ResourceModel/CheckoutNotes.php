<?php

namespace Bluebarry\Bluebarry\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * The bluebarry_checkout table: checkouts where the shopper gave their email, waiting to be told to
 * bluebarry (a minute after the last change, so a shopper still typing is sent once), and again once
 * the checkout became an order.
 */
class CheckoutNotes
{
    public const TABLE = 'bluebarry_checkout';

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
     * Notes the email of a checkout; a changed email waits to go again.
     *
     * @param int $quoteId
     * @param int $storeId
     * @param string $email
     * @param string|null $firstName
     * @return void
     */
    public function note(int $quoteId, int $storeId, string $email, ?string $firstName): void
    {
        $connection = $this->connection();
        $connection->query(sprintf(
            'INSERT INTO %s (quote_id, store_id, email, first_name, noted_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
            . ' ON DUPLICATE KEY UPDATE sent_at = IF(email <> VALUES(email) OR NOT (first_name <=> VALUES(first_name)), NULL, sent_at),'
            . ' noted_at = IF(email <> VALUES(email) OR NOT (first_name <=> VALUES(first_name)), VALUES(noted_at), noted_at),'
            . ' email = VALUES(email), first_name = VALUES(first_name), store_id = VALUES(store_id)',
            $connection->quoteIdentifier($this->table())
        ), [$quoteId, $storeId, mb_substr($email, 0, 255), $firstName === null ? null : mb_substr($firstName, 0, 255)]);
    }

    /**
     * The checkout became an order: bluebarry hears it, so no reminder goes out.
     *
     * @param int $quoteId
     * @return void
     */
    public function complete(int $quoteId): void
    {
        $this->connection()->update(
            $this->table(),
            ['completed' => 1, 'sent_at' => null, 'noted_at' => gmdate('Y-m-d H:i:s')],
            ['quote_id = ?' => $quoteId, 'completed = ?' => 0]
        );
    }

    /**
     * Checkouts waiting to be sent: completed ones right away, the others once the email stopped changing.
     *
     * @param int $limit
     * @param int $settleSeconds
     * @param int[] $skipStoreIds store views whose website bluebarry cannot take them from now
     * @return array[]
     */
    public function due(int $limit, int $settleSeconds, array $skipStoreIds = []): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table())
            ->where('sent_at IS NULL')
            ->where('completed = 1 OR noted_at <= ?', gmdate('Y-m-d H:i:s', time() - $settleSeconds))
            ->order('noted_at')
            ->limit($limit);
        if ($skipStoreIds) {
            $select->where('store_id NOT IN (?)', $skipStoreIds);
        }
        return $connection->fetchAll($select);
    }

    /**
     * @param int $quoteId
     * @param string $notedAt the note that was sent: a newer one waits to go again
     * @return void
     */
    public function markSent(int $quoteId, string $notedAt): void
    {
        $this->connection()->update($this->table(), ['sent_at' => gmdate('Y-m-d H:i:s')], ['quote_id = ?' => $quoteId, 'noted_at = ?' => $notedAt]);
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
