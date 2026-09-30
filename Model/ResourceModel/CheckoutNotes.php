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
     * Notes the email of a checkout; a changed email, name, cart or store view waits to go again.
     *
     * @param int $quoteId
     * @param int $storeId
     * @param string $email
     * @param string|null $firstName
     * @param array $lines from lines()
     * @return void
     */
    public function note(int $quoteId, int $storeId, string $email, ?string $firstName, array $lines = []): void
    {
        $connection = $this->connection();
        $changed = 'email <> VALUES(email) OR NOT (first_name <=> VALUES(first_name)) OR NOT (cart <=> VALUES(cart))'
            . ' OR store_id <> VALUES(store_id)';
        $connection->query(sprintf(
            'INSERT INTO %s (quote_id, store_id, email, first_name, cart, noted_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            // In this order: MySQL evaluates each assignment with the ones before it applied.
            . " ON DUPLICATE KEY UPDATE revision = IF($changed, revision + 1, revision), sent_at = IF($changed, NULL, sent_at),"
            . " noted_at = IF($changed, VALUES(noted_at), noted_at),"
            . ' email = VALUES(email), first_name = VALUES(first_name), cart = VALUES(cart), store_id = VALUES(store_id)',
            $connection->quoteIdentifier($this->table())
        ), [
            $quoteId, $storeId, mb_substr($email, 0, 255), $firstName === null ? null : mb_substr($firstName, 0, 255),
            sha1((string) json_encode($lines)),
        ]);
    }

    /**
     * A guest removed their email before it was sent: that email is not sent, and the next one noted
     * counts as a change, the same one again included. A note bluebarry has already, or that is on its
     * way there (claim()), is left as it is: it still gets its completion.
     *
     * @param int $quoteId
     * @return void
     */
    public function withdraw(int $quoteId): void
    {
        $this->connection()->update(
            $this->table(),
            // Nothing left to send: an empty email is never sent, nor completed (complete()).
            ['email' => '', 'sent_at' => gmdate('Y-m-d H:i:s'), 'revision' => new \Zend_Db_Expr('revision + 1')],
            ['quote_id = ?' => $quoteId, 'completed = ?' => 0, 'sent_at IS NULL']
        );
    }

    /**
     * A cart's lines as bluebarry gets them: the catalog's reference (the variant for a configurable
     * product) and the quantity in whole units.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return array<int, array{reference: string, quantity: int}>
     */
    public static function lines($quote): array
    {
        $lines = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $variant = $item->getOptionByCode('simple_product');
            $lines[] = [
                'reference' => (string) ($variant ? $variant->getValue() : $item->getProductId()),
                'quantity' => max(1, (int) round((float) $item->getQty())),
            ];
        }
        return $lines;
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
            ['completed' => 1, 'sent_at' => null, 'noted_at' => gmdate('Y-m-d H:i:s'), 'revision' => new \Zend_Db_Expr('revision + 1')],
            ['quote_id = ?' => $quoteId, 'completed = ?' => 0, 'email <> ?' => '']
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
            // Completions first: a buyer is never reminded while older notes wait their turn.
            ->order(['completed DESC', 'noted_at'])
            ->limit($limit);
        if ($skipStoreIds) {
            $select->where('store_id NOT IN (?)', $skipStoreIds);
        }
        return $connection->fetchAll($select);
    }

    /**
     * Takes a due note for delivery, right before it goes: only while it is still the revision read (a
     * change or a withdrawal since wins), and marked sent from then on, so a withdrawal can no longer
     * take away an email that is on its way to bluebarry.
     *
     * @param int $quoteId
     * @param int $revision the one read with due()
     * @return bool whether it may go
     */
    public function claim(int $quoteId, int $revision): bool
    {
        return $this->connection()->update(
            $this->table(),
            ['sent_at' => gmdate('Y-m-d H:i:s')],
            ['quote_id = ?' => $quoteId, 'revision = ?' => $revision, 'sent_at IS NULL', "email <> ''"]
        ) === 1;
    }

    /**
     * A claimed note that did not arrive (bluebarry could not be reached): due again, unless it changed.
     *
     * @param int $quoteId
     * @param int $revision the one claimed
     * @return void
     */
    public function release(int $quoteId, int $revision): void
    {
        $this->connection()->update($this->table(), ['sent_at' => null], ['quote_id = ?' => $quoteId, 'revision = ?' => $revision]);
    }

    /**
     * @param int $quoteId
     * @param int $revision the one that was sent: a change since waits to go again
     * @return void
     */
    public function markSent(int $quoteId, int $revision): void
    {
        $this->connection()->update($this->table(), ['sent_at' => gmdate('Y-m-d H:i:s')], ['quote_id = ?' => $quoteId, 'revision = ?' => $revision]);
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
