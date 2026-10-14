<?php

namespace Bluebarry\Bluebarry\Model\ResourceModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\SalesRule\Api\Data\RuleInterface;

/**
 * The bluebarry_discount_rule table: which cart price rules are bluebarry's, by the hash of their
 * terms. Everything bluebarry does to coupon codes goes through it, so it never touches a rule or a
 * code the merchant made. And bluebarry_discount_code: when each code ends, which Magento keeps on
 * the coupon but does not act on, and what became of a code once it is removed.
 */
class DiscountRules
{
    public const TABLE = 'bluebarry_discount_rule';
    public const CODES_TABLE = 'bluebarry_discount_code';

    public const KIND_CODE = 'code';
    public const KIND_OFFER = 'offer';

    /** A code's stage in bluebarry_discount_code: usable, ended (spent and kept a little while), removed. */
    public const STAGE_USABLE = 0;
    public const STAGE_ENDED = 1;
    public const STAGE_REMOVED = 2;

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
     * @return array{coupon_id: string, rule_id: string, code: string, times_used: string, created_at: string|null, stage: string|null, expires_at: string|null, revoked: string|null, is_active: string}|null
     */
    public function coupon(string $code, ?string $tenantId = null): ?array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['coupon' => $this->resource->getTableName('salesrule_coupon')], ['coupon_id', 'rule_id', 'code', 'times_used', 'created_at'])
            ->join(['ours' => $this->table()], 'ours.rule_id = coupon.rule_id', [])
            ->join(['rule' => $this->resource->getTableName('salesrule')], 'rule.rule_id = coupon.rule_id', ['is_active'])
            ->joinLeft(['ends' => $this->resource->getTableName(self::CODES_TABLE)], 'ends.coupon_id = coupon.coupon_id', ['stage', 'expires_at', 'revoked'])
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
     * sales_order has no index on the coupon code: the orders looked at are only those placed while the
     * code existed, found by their date index. Without the code's creation nothing is looked at.
     *
     * @param string $code
     * @param string|null $since the code's creation (UTC)
     * @param string|null $until the code's removal (UTC), when it is gone
     * @param int[] $storeIds only orders of these store views: the asking account's own
     * @return int
     */
    public function ordersWith(string $code, ?string $since, ?string $until, array $storeIds): int
    {
        if ($code === '' || $since === null || $since === '' || !$storeIds) {
            return 0;
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('sales_order'), ['orders' => new \Zend_Db_Expr('COUNT(*)')])
            ->where('coupon_code = ?', $code)
            ->where('store_id IN (?)', $storeIds)
            ->where('state <> ?', 'canceled')
            ->where('created_at >= ?', $since);
        if ($until !== null && $until !== '') {
            $select->where('created_at <= ?', $until);
        }
        return (int) $connection->fetchOne($select);
    }

    /**
     * Ends a code: spent, unless a shopper spent it already, and kept until $removeAt. A checkout that
     * still holds the code finds its one use gone when it counts it, and is refused by Magento itself; a
     * deleted coupon it would not find at all, and the order would go through with the discount. The
     * caller holds the lock Magento counts the coupon's use under. The cron removes it after $removeAt.
     *
     * @param array $coupon coupon_id, code and created_at, from coupon() or expiredCodes()
     * @param string $tenantId the bluebarry account it is for
     * @param string $removeAt UTC, Y-m-d H:i:s
     * @param bool $onlyUnused taking a code back: one a shopper spent stays as it is. Otherwise (it
     *                         expired) it ends either way, unless it was taken back meanwhile.
     * @return bool whether it ended
     * @throws \Exception
     */
    public function end(array $coupon, string $tenantId, string $removeAt, bool $onlyUnused): bool
    {
        $couponId = (int) $coupon['coupon_id'];
        $connection = $this->resource->getConnection();
        $codes = $this->resource->getTableName(self::CODES_TABLE);
        // Spent and marked as ended together, or neither: spent without the mark, the code would count
        // as used for good.
        $connection->beginTransaction();
        try {
            $spent = $connection->update(
                $this->resource->getTableName('salesrule_coupon'),
                ['times_used' => new \Zend_Db_Expr('usage_limit')],
                ['coupon_id = ?' => $couponId, 'times_used = ?' => 0, 'usage_limit > ?' => 0]
            ) > 0;
            $row = [
                'stage' => self::STAGE_ENDED,
                'expires_at' => $removeAt,
                'revoked' => $spent ? 1 : 0,
                'code' => (string) $coupon['code'],
                'tenant_id' => $tenantId,
                'created_at' => $coupon['created_at'] ?? null,
            ];
            if ($onlyUnused) {
                // A code that would never end has no row yet.
                if ($spent) {
                    $connection->insertOnDuplicate($codes, ['coupon_id' => $couponId] + $row, array_keys($row));
                }
                $ended = $spent;
            } else {
                $ended = $connection->update($codes, $row, ['coupon_id = ?' => $couponId, 'stage = ?' => self::STAGE_USABLE]) > 0;
            }
            if (!$ended) {
                $connection->rollBack();
                return false;
            }
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }
        return true;
    }

    /**
     * What is known of one of an account's codes that was removed: whether a shopper spent it before it
     * ended, and when it was made and removed. Null for any code bluebarry did not remove for this account.
     *
     * @param string $code
     * @param string $tenantId
     * @return array{revoked: string, created_at: string|null, expires_at: string}|null
     */
    public function removed(string $code, string $tenantId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::CODES_TABLE), ['revoked', 'created_at', 'expires_at'])
                ->where('code = ?', $code)
                ->where('tenant_id = ?', $tenantId)
                ->where('stage = ?', self::STAGE_REMOVED)
                ->order('expires_at DESC')
                ->limit(1)
        );
        return $row ?: null;
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
        // A usable code's row, also over one left by a removed coupon that had the same id.
        $row = ['stage' => self::STAGE_USABLE, 'expires_at' => $expiresAt, 'revoked' => 0, 'code' => null, 'tenant_id' => null, 'created_at' => null];
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::CODES_TABLE),
            ['coupon_id' => $couponId] + $row,
            array_keys($row)
        );
    }

    /**
     * bluebarry's codes whose last moment has passed and that have not ended yet, a batch at a time, with
     * the account each is for. A row whose coupon is gone (the merchant deleted its rule) goes now. One
     * indexed query when none has.
     *
     * @param string $now UTC, Y-m-d H:i:s
     * @param int $limit
     * @return array<int, array{coupon_id: string, code: string, created_at: string|null, tenant_id: string}>
     */
    public function expiredCodes(string $now, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $codes = $this->resource->getTableName(self::CODES_TABLE);
        $ids = $connection->fetchCol(
            $connection->select()->from($codes, ['coupon_id'])
                ->where('stage = ?', self::STAGE_USABLE)
                ->where('expires_at < ?', $now)
                ->limit($limit)
        );
        if (!$ids) {
            return [];
        }
        // Only a coupon that is still under one of bluebarry's rules: an id is never taken on trust.
        $ours = $connection->fetchAll(
            $connection->select()
                ->from(['coupon' => $this->resource->getTableName('salesrule_coupon')], ['coupon_id', 'code', 'created_at'])
                ->join(['ours' => $this->table()], 'ours.rule_id = coupon.rule_id', ['tenant_id'])
                ->where('coupon.coupon_id IN (?)', $ids)
        );
        $gone = array_diff(array_map('intval', $ids), array_map('intval', array_column($ours, 'coupon_id')));
        if ($gone) {
            $connection->delete($codes, ['coupon_id IN (?)' => $gone, 'stage = ?' => self::STAGE_USABLE]);
        }
        return $ours;
    }

    /**
     * Removes the codes that ended and were kept long enough for any checkout that held one to have
     * counted it, a batch at a time. Their rows stay, as what became of them. One indexed query when none is due.
     *
     * @param string $now UTC, Y-m-d H:i:s
     * @param int $limit
     * @return int how many were removed
     */
    public function removeEndedCodes(string $now, int $limit): int
    {
        $connection = $this->resource->getConnection();
        $codes = $this->resource->getTableName(self::CODES_TABLE);
        $ids = $connection->fetchCol(
            $connection->select()->from($codes, ['coupon_id'])
                ->where('stage = ?', self::STAGE_ENDED)
                ->where('expires_at < ?', $now)
                ->limit($limit)
        );
        if (!$ids) {
            return 0;
        }
        $ours = $connection->fetchCol(
            $connection->select()
                ->from(['coupon' => $this->resource->getTableName('salesrule_coupon')], ['coupon_id'])
                ->join(['ours' => $this->table()], 'ours.rule_id = coupon.rule_id', [])
                ->where('coupon.coupon_id IN (?)', $ids)
        );
        $connection->beginTransaction();
        try {
            if ($ours) {
                $connection->delete($this->resource->getTableName('salesrule_coupon'), ['coupon_id IN (?)' => $ours]);
            }
            $connection->update(
                $codes,
                ['stage' => self::STAGE_REMOVED, 'expires_at' => $now],
                ['coupon_id IN (?)' => $ids, 'stage = ?' => self::STAGE_ENDED]
            );
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }
        return count($ids);
    }

    /**
     * Forgets codes removed before $before, a batch at a time.
     *
     * @param string $before UTC, Y-m-d H:i:s
     * @param int $limit
     * @return int how many were forgotten
     */
    public function forgetRemovedCodes(string $before, int $limit): int
    {
        $connection = $this->resource->getConnection();
        $codes = $this->resource->getTableName(self::CODES_TABLE);
        $ids = $connection->fetchCol(
            $connection->select()->from($codes, ['coupon_id'])
                ->where('stage = ?', self::STAGE_REMOVED)
                ->where('expires_at < ?', $before)
                ->limit($limit)
        );
        return $ids ? (int) $connection->delete($codes, ['coupon_id IN (?)' => $ids, 'stage = ?' => self::STAGE_REMOVED]) : 0;
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
     * Opens bluebarry's rules to customer groups made after them. A cart price rule holds for the groups
     * it was saved with, so a code would give nothing to a customer in a group the merchant added
     * later. One statement, which adds nothing when no group is missing.
     *
     * @return int how many rules and groups were paired
     */
    public function openToNewCustomerGroups(): int
    {
        $connection = $this->resource->getConnection();
        $linkField = $this->metadataPool->getMetadata(RuleInterface::class)->getLinkField();
        $links = $this->resource->getTableName('salesrule_customer_group');
        $select = $connection->select()
            ->from(['ours' => $this->table()], [])
            ->join(['rule' => $this->resource->getTableName('salesrule')], 'rule.rule_id = ours.rule_id', [$linkField])
            ->joinCross(['customers' => $this->resource->getTableName('customer_group')], ['customer_group_id'])
            ->joinLeft(['link' => $links], "link.$linkField = rule.$linkField AND link.customer_group_id = customers.customer_group_id", [])
            ->where('link.customer_group_id IS NULL');
        return (int) $connection->query(
            $connection->insertFromSelect($select, $links, [$linkField, 'customer_group_id'], AdapterInterface::INSERT_IGNORE)
        )->rowCount();
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
