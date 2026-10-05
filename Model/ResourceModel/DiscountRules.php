<?php

namespace Bluebarry\Bluebarry\Model\ResourceModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * The bluebarry_discount_rule table: which cart price rules are bluebarry's, by the hash of their
 * terms. Everything bluebarry does to coupon codes goes through it, so it never touches a rule or a
 * code the merchant made. And bluebarry_discount_code: when each code ends, which Magento keeps on
 * the coupon but does not act on.
 */
class DiscountRules
{
    public const TABLE = 'bluebarry_discount_rule';
    public const CODES_TABLE = 'bluebarry_discount_code';

    public const KIND_CODE = 'code';
    public const KIND_OFFER = 'offer';

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
     * The connection every write of a code goes through, for the caller's transaction.
     *
     * @return AdapterInterface
     */
    public function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * The rule for a set of terms, when it still exists in Magento.
     *
     * @param string $termsHash
     * @return int|null
     */
    public function ruleFor(string $termsHash): ?int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['ours' => $this->table()], ['rule_id'])
            ->join(['rule' => $this->resource->getTableName('salesrule')], 'rule.rule_id = ours.rule_id', [])
            ->where('ours.terms_hash = ?', $termsHash)
            ->limit(1);
        $ruleId = $connection->fetchOne($select);
        return $ruleId ? (int) $ruleId : null;
    }

    /**
     * Records a new rule under its terms. A row left by a rule that was deleted gives way.
     *
     * @param int $ruleId
     * @param string $termsHash
     * @param string $kind
     * @param string $tenantId the bluebarry account its codes are for
     * @return void
     */
    public function record(int $ruleId, string $termsHash, string $kind, string $tenantId): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->table(),
            ['rule_id' => $ruleId, 'terms_hash' => $termsHash, 'kind' => $kind, 'tenant_id' => $tenantId],
            ['rule_id', 'kind', 'tenant_id']
        );
    }

    /**
     * Whether a coupon code is one of bluebarry's: it lives under one of bluebarry's rules.
     *
     * @param string $code
     * @return bool
     */
    public function isOurs(string $code): bool
    {
        return $this->coupon($code) !== null;
    }

    /**
     * One of bluebarry's coupon codes with its rule's state, or null for any other code.
     *
     * @param string $code
     * @param string|null $tenantId only a code of this bluebarry account: two accounts can share one
     *                              Magento, and neither may read or end the other's codes
     * @return array{coupon_id: string, rule_id: string, code: string, times_used: string, created_at: string|null, expires_at: string|null, revoked: string|null, is_active: string}|null
     */
    public function coupon(string $code, ?string $tenantId = null): ?array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['coupon' => $this->resource->getTableName('salesrule_coupon')], ['coupon_id', 'rule_id', 'code', 'times_used', 'created_at'])
            ->join(['ours' => $this->table()], 'ours.rule_id = coupon.rule_id', [])
            ->join(['rule' => $this->resource->getTableName('salesrule')], 'rule.rule_id = coupon.rule_id', ['is_active'])
            ->joinLeft(['ends' => $this->resource->getTableName(self::CODES_TABLE)], 'ends.coupon_id = coupon.coupon_id', ['expires_at', 'revoked'])
            ->where('coupon.code = ?', $code)
            ->limit(1);
        if ($tenantId !== null) {
            $select->where('ours.tenant_id = ?', $tenantId);
        }
        $row = $connection->fetchRow($select);
        return $row ?: null;
    }

    /**
     * How many orders that were not cancelled carry a code. Magento counts a coupon's uses from a queue,
     * after the order: an order placed a moment ago may not be in the coupon's own count yet.
     *
     * @param string $code
     * @param string|null $since the code's creation (UTC), which bounds the orders looked at by their date index
     * @param int[] $storeIds only orders of these store views: the asking account's own
     * @return int
     */
    public function ordersWith(string $code, ?string $since, array $storeIds): int
    {
        if ($code === '' || !$storeIds) {
            return 0;
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('sales_order'), ['orders' => new \Zend_Db_Expr('COUNT(*)')])
            ->where('coupon_code = ?', $code)
            ->where('store_id IN (?)', $storeIds)
            ->where('state <> ?', 'canceled');
        if ($since !== null && $since !== '') {
            $select->where('created_at >= ?', $since);
        }
        return (int) $connection->fetchOne($select);
    }

    /**
     * Takes a coupon back while Magento counts no use of it: it is spent, not deleted, in one statement.
     * A checkout that already holds the code finds its one use gone when it counts it, and is refused
     * by Magento itself; a deleted coupon it would not find at all, and the order would go through with
     * the discount. The cron removes it after $until.
     *
     * @param int $couponId
     * @param string $until UTC, Y-m-d H:i:s
     * @return bool whether it was taken back
     */
    public function revokeUnused(int $couponId, string $until): bool
    {
        $connection = $this->resource->getConnection();
        $spent = $connection->update(
            $this->resource->getTableName('salesrule_coupon'),
            ['times_used' => new \Zend_Db_Expr('usage_limit')],
            ['coupon_id = ?' => $couponId, 'times_used = ?' => 0, 'usage_limit > ?' => 0]
        );
        if ($spent < 1) {
            return false;
        }
        $connection->insertOnDuplicate(
            $this->resource->getTableName(self::CODES_TABLE),
            ['coupon_id' => $couponId, 'expires_at' => $until, 'revoked' => 1],
            ['expires_at', 'revoked']
        );
        return true;
    }

    /**
     * @param int $ruleId
     * @return bool whether any code lives under the rule
     */
    public function hasCodes(int $ruleId): bool
    {
        $connection = $this->resource->getConnection();
        return (bool) $connection->fetchOne(
            $connection->select()->from($this->resource->getTableName('salesrule_coupon'), ['coupon_id'])->where('rule_id = ?', $ruleId)->limit(1)
        );
    }

    /**
     * The SKUs of the configurable products each product is a variant of, by product id.
     *
     * @param string[] $productIds
     * @return array<string, string[]>
     */
    public function configurableParents(array $productIds): array
    {
        $ids = array_values(array_filter($productIds, 'ctype_digit'));
        if (!$ids) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $rows = $connection->fetchAll(
            $connection->select()->distinct()
                ->from(['link' => $this->resource->getTableName('catalog_product_super_link')], ['child' => 'product_id'])
                ->join(['parent' => $this->resource->getTableName('catalog_product_entity')], "parent.$linkField = link.parent_id", ['sku'])
                ->where('link.product_id IN (?)', $ids)
        );
        $parents = [];
        foreach ($rows as $row) {
            $parents[(string) $row['child']][] = (string) $row['sku'];
        }
        return $parents;
    }

    /**
     * Whether any coupon code, the merchant's own included, has this code.
     *
     * @param string $code
     * @return bool
     */
    public function codeExists(string $code): bool
    {
        $connection = $this->resource->getConnection();
        return (bool) $connection->fetchOne(
            $connection->select()->from($this->resource->getTableName('salesrule_coupon'), ['coupon_id'])->where('code = ?', $code)->limit(1)
        );
    }

    /**
     * Notes when a code ends.
     *
     * @param int $couponId
     * @param string $expiresAt UTC, Y-m-d H:i:s
     * @return void
     */
    public function endsAt(int $couponId, string $expiresAt): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::CODES_TABLE),
            ['coupon_id' => $couponId, 'expires_at' => $expiresAt],
            ['expires_at']
        );
    }

    /**
     * Removes bluebarry's codes whose last moment has passed, a batch at a time: this is what ends a
     * code. One indexed query when none has.
     *
     * @param string $now UTC, Y-m-d H:i:s
     * @param int $limit
     * @return int how many ended
     */
    public function deleteExpiredCodes(string $now, int $limit): int
    {
        $connection = $this->resource->getConnection();
        $codes = $this->resource->getTableName(self::CODES_TABLE);
        $ids = $connection->fetchCol($connection->select()->from($codes, ['coupon_id'])->where('expires_at < ?', $now)->limit($limit));
        if (!$ids) {
            return 0;
        }
        // Only a coupon that is still under one of bluebarry's rules: an id is never taken on trust.
        $ours = $connection->fetchCol(
            $connection->select()
                ->from(['coupon' => $this->resource->getTableName('salesrule_coupon')], ['coupon_id'])
                ->join(['ours' => $this->table()], 'ours.rule_id = coupon.rule_id', [])
                ->where('coupon.coupon_id IN (?)', $ids)
        );
        if ($ours) {
            $connection->delete($this->resource->getTableName('salesrule_coupon'), ['coupon_id IN (?)' => $ours]);
        }
        $connection->delete($codes, ['coupon_id IN (?)' => $ids]);
        return count($ids);
    }

    /**
     * Offer rules that hold no code any more: each was made for one set of offered products.
     *
     * @param string $before UTC, Y-m-d H:i:s: only rules older than this, so a rule is never taken
     *                       from under the code being made for it
     * @param int $limit
     * @return int[]
     */
    public function emptyOfferRules(string $before, int $limit): array
    {
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from(['ours' => $this->table()], ['rule_id'])
                ->joinLeft(['coupon' => $this->resource->getTableName('salesrule_coupon')], 'coupon.rule_id = ours.rule_id', [])
                ->where('ours.kind = ?', self::KIND_OFFER)
                ->where('ours.created_at < ?', $before)
                ->where('coupon.coupon_id IS NULL')
                ->limit($limit)
        ));
    }

    /**
     * Rows whose rule the merchant deleted.
     *
     * @param int $limit
     * @return int how many were cleared
     */
    public function forgetDeletedRules(int $limit): int
    {
        $connection = $this->resource->getConnection();
        $ids = $connection->fetchCol(
            $connection->select()
                ->from(['ours' => $this->table()], ['rule_id'])
                ->joinLeft(['rule' => $this->resource->getTableName('salesrule')], 'rule.rule_id = ours.rule_id', [])
                ->where('rule.rule_id IS NULL')
                ->limit($limit)
        );
        return $ids ? (int) $connection->delete($this->table(), ['rule_id IN (?)' => $ids]) : 0;
    }

    /**
     * @param int $ruleId
     * @return void
     */
    public function forget(int $ruleId): void
    {
        $this->resource->getConnection()->delete($this->table(), ['rule_id = ?' => $ruleId]);
    }

    /**
     * Every rule of bluebarry's (the uninstall removes them).
     *
     * @return int[]
     */
    public function allRules(): array
    {
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchCol($connection->select()->from($this->table(), ['rule_id'])));
    }

    /**
     * The SKUs of products, by product id.
     *
     * @param string[] $productIds
     * @return array<string, string>
     */
    public function skus(array $productIds): array
    {
        $ids = array_values(array_filter($productIds, 'ctype_digit'));
        if (!$ids) {
            return [];
        }
        $connection = $this->resource->getConnection();
        return array_map('strval', $connection->fetchPairs(
            $connection->select()->from($this->resource->getTableName('catalog_product_entity'), ['entity_id', 'sku'])->where('entity_id IN (?)', $ids)
        ));
    }

    /**
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
