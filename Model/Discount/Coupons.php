<?php

namespace Bluebarry\Bluebarry\Model\Discount;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\ResourceModel\DiscountRules;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as GroupCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\SalesRule\Helper\Coupon as CouponHelper;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Condition\Address as AddressCondition;
use Magento\SalesRule\Model\Rule\Condition\Combine as ConditionCombine;
use Magento\SalesRule\Model\Rule\Condition\Product as ProductCondition;
use Magento\SalesRule\Model\Rule\Condition\Product\Combine as ProductCombine;
use Magento\SalesRule\Model\Rule\Condition\Product\Found as ProductFound;
use Magento\SalesRule\Model\RuleFactory;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * bluebarry's discount codes in Magento: a loyalty reward's, a popup's or quiz's code for one person,
 * and the one-time code of a quiz or recommendation offer.
 *
 * Each is a single-use coupon code under a cart price rule made for its terms (10% off, 5.00 off these
 * products with a minimum of 50.00), so a thousand codes with the same terms are one rule in the
 * merchant's list. The rules use only Magento's own conditions, so they keep working, and can be read
 * and removed in the admin, also without this module. A rule holds on every website of this Magento
 * that reports to the same bluebarry account.
 *
 * Magento ends a code only by its rule's dates, and these rules have none (their codes each end at
 * their own moment). So a code ends by being removed: the cron does that within minutes of its last
 * moment (cleanUp()).
 */
class Coupons
{
    private const LOCK = 'bluebarry_discount_rule';

    /** The lock Magento counts a coupon's use under at checkout (SalesRule's Coupon\Usage\Processor), by its code. */
    private const COUPON_LOCK = 'coupon_code_';

    /** Seconds a code that was taken back stays, spent: longer than any checkout that still holds it. */
    private const REVOKED_KEPT = 600;

    /** More products than this is not one offer or reward; the rule would not be readable either. */
    private const MAX_PRODUCTS = 100;

    /**
     * @var DiscountRules
     */
    private $rules;

    /**
     * @var RuleFactory
     */
    private $ruleFactory;

    /**
     * @var RuleResource
     */
    private $ruleResource;

    /**
     * @var CouponFactory
     */
    private $couponFactory;

    /**
     * @var CouponResource
     */
    private $couponResource;

    /**
     * @var GroupCollectionFactory
     */
    private $customerGroups;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var LockManagerInterface
     */
    private $locks;

    /**
     * @param DiscountRules $rules
     * @param RuleFactory $ruleFactory
     * @param RuleResource $ruleResource
     * @param CouponFactory $couponFactory
     * @param CouponResource $couponResource
     * @param GroupCollectionFactory $customerGroups
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     * @param ScopeConfigInterface $scopeConfig
     * @param LockManagerInterface $locks
     */
    public function __construct(
        DiscountRules $rules,
        RuleFactory $ruleFactory,
        RuleResource $ruleResource,
        CouponFactory $couponFactory,
        CouponResource $couponResource,
        GroupCollectionFactory $customerGroups,
        StoreManagerInterface $storeManager,
        Config $config,
        ScopeConfigInterface $scopeConfig,
        LockManagerInterface $locks
    ) {
        $this->rules = $rules;
        $this->ruleFactory = $ruleFactory;
        $this->ruleResource = $ruleResource;
        $this->couponFactory = $couponFactory;
        $this->couponResource = $couponResource;
        $this->customerGroups = $customerGroups;
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->scopeConfig = $scopeConfig;
        $this->locks = $locks;
    }

    /**
     * The code bluebarry describes, made when it is not there yet: the same request again finds the
     * same code. The description is the one bluebarry sends every store's plugin:
     *
     *   code           the coupon code
     *   discountType   percent, fixed_cart (one amount off the cart) or fixed_product (an amount off
     *                  each of the products, once per product whatever its quantity)
     *   amount         the percentage or the amount, in the website's base currency
     *   productIds     the only products it discounts (Magento product ids), or none for all
     *   minimumSpend   the least the cart's subtotal must be, as the cart shows it
     *   freeShipping   free shipping instead of an amount
     *   expiresAt      its last moment (ISO 8601)
     *   individualUse  no other cart price rule applies after it
     *   requiredProductIds  products that must all be in the cart (an offer built around them)
     *
     * @param array $spec
     * @param WebsiteInterface $website the website asked; the code also holds on its sister websites
     * @param string $kind DiscountRules::KIND_CODE or KIND_OFFER
     * @return array{exists: bool, id?: int, status?: string, usageCount: int}
     * @throws RefusedException when the code cannot be made as described
     * @throws \Exception when the store could not make it right now
     */
    public function ensure(array $spec, WebsiteInterface $website, string $kind = DiscountRules::KIND_CODE): array
    {
        $code = trim((string) ($spec['code'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{3,63}$/', $code)) {
            throw new RefusedException('Invalid coupon code.');
        }
        $tenantId = $this->tenantId($website);
        $expires = $this->expiry($spec['expiresAt'] ?? null);
        $existing = $this->rules->coupon($code, $tenantId);
        if ($existing !== null) {
            // Asked again after an answer that never arrived. A code whose end was not noted the first
            // time (the request died in between) gets it now: without it the code would never end.
            if ($expires !== null && $existing['expires_at'] === null) {
                $this->rules->endsAt((int) $existing['coupon_id'], $expires);
            }
            return $this->state($code, $website);
        }
        if ($this->rules->codeExists($code)) {
            // The merchant's own code, another extension's, or another bluebarry account's: never taken over.
            throw new RefusedException('This store already has a coupon with that code.');
        }
        $terms = $this->terms($spec, $website);

        // One at a time: codes with the same new terms end up under one rule, and the cron never removes
        // a rule (cleanUp()) between finding it here and putting a code under it.
        if (!$this->locks->lock(self::LOCK, 10)) {
            throw new \RuntimeException('Another discount code is being made; try again.');
        }
        try {
            $ruleId = $this->ruleFor($terms, $kind, $tenantId);
            $coupon = $this->couponFactory->create();
            $coupon->setRuleId($ruleId)
                ->setCode($code)
                ->setUsageLimit(1)
                ->setUsagePerCustomer(1)
                ->setType(CouponHelper::COUPON_TYPE_SPECIFIC_AUTOGENERATED)
                ->setCreatedAt(gmdate('Y-m-d H:i:s'));
            if ($expires !== null) {
                // For whoever reads the coupon; what ends the code is the note made with it below.
                $coupon->setExpirationDate($expires);
            }
            // The code and its end together, or neither: a code without its end would never end.
            $connection = $this->rules->connection();
            $connection->beginTransaction();
            try {
                $this->couponResource->save($coupon);
                if ($expires !== null) {
                    $this->rules->endsAt((int) $coupon->getId(), $expires);
                }
                $connection->commit();
            } catch (\Exception $e) {
                $connection->rollBack();
                throw $e;
            }
        } finally {
            $this->locks->unlock(self::LOCK);
        }
        return $this->state($code, $website);
    }

    /**
     * What became of one of this account's codes. Any other code (the merchant's own, another bluebarry
     * account's on the same Magento) does not exist here.
     *
     * @param string $code
     * @param WebsiteInterface $website the website asked
     * @return array{exists: bool, id?: int, status?: string, usageCount: int}
     */
    public function state(string $code, WebsiteInterface $website): array
    {
        $coupon = $this->rules->coupon($code, $this->tenantId($website));
        if ($coupon === null || !empty($coupon['revoked'])) {
            // Removed after it ended, taken back, or never made: an order that carries it still says it
            // was spent. Only this account's own orders: another account's code is none of its business.
            return ['exists' => false, 'usageCount' => $this->rules->ordersWith($code, $coupon['created_at'] ?? null, $this->storeIds($website))];
        }
        // Past its last moment and not removed by the cron yet.
        $expired = $coupon['expires_at'] !== null && strtotime($coupon['expires_at'] . ' UTC') < time();
        return [
            'exists' => true,
            'id' => (int) $coupon['coupon_id'],
            // The word bluebarry knows a usable code by, from the WooCommerce plugin.
            'status' => (int) $coupon['is_active'] === 1 && !$expired ? 'publish' : 'disabled',
            'usageCount' => $this->uses($code, $coupon, $website),
        ];
    }

    /**
     * Takes back one of this account's codes that nobody spent, so it can't be. A spent one stays as it is.
     *
     * A checkout may be placing its order with the code at this very moment. So the code is not deleted
     * but spent, under the lock Magento counts a coupon's use under: the checkout either counted first
     * (the code is spent, and stays), or finds its one use gone and is refused. The cron removes it later.
     * Magento before 2.4.8 counts after the order, without that check: there the orders are looked at.
     *
     * @param string $code
     * @param WebsiteInterface $website the website asked
     * @return array{exists: bool, id?: int, status?: string, usageCount: int}
     */
    public function revoke(string $code, WebsiteInterface $website): array
    {
        $coupon = $this->rules->coupon($code, $this->tenantId($website));
        if ($coupon !== null && empty($coupon['revoked']) && $this->uses($code, $coupon, $website) === 0) {
            $lock = self::COUPON_LOCK . $coupon['code'];
            if ($this->locks->lock($lock, 5)) {
                try {
                    $this->rules->revokeUnused((int) $coupon['coupon_id'], gmdate('Y-m-d H:i:s', time() + self::REVOKED_KEPT));
                } finally {
                    $this->locks->unlock($lock);
                }
            }
        }
        return $this->state($code, $website);
    }

    /**
     * How often a code was spent: Magento's own count, or the orders that carry it when that is more.
     * Magento counts from a queue after the order, so an order placed a moment ago may be ahead of it.
     *
     * @param string $code
     * @param array $coupon from DiscountRules::coupon()
     * @param WebsiteInterface $website
     * @return int
     */
    private function uses(string $code, array $coupon, WebsiteInterface $website): int
    {
        $counted = (int) $coupon['times_used'];
        return $counted > 0 ? $counted : $this->rules->ordersWith($code, $coupon['created_at'], $this->storeIds($website));
    }

    /**
     * The store views of the websites a code holds on: where an order can carry it.
     *
     * @param WebsiteInterface $website
     * @return int[]
     */
    private function storeIds(WebsiteInterface $website): array
    {
        $websites = array_flip($this->sisterWebsites($website));
        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            if (isset($websites[(int) $store->getWebsiteId()])) {
                $ids[] = (int) $store->getId();
            }
        }
        return $ids;
    }

    /**
     * The bluebarry account a website reports to, as rules are kept by it.
     *
     * @param WebsiteInterface $website
     * @return string
     */
    private function tenantId(WebsiteInterface $website): string
    {
        return strtolower((string) $this->config->getWebsiteTenantId($website->getId()));
    }

    /**
     * From the cron, every few minutes: codes whose last moment has passed are removed, which is what
     * ends them (and keeps a busy store from holding one per person forever); then offer rules that
     * hold no code any more, then the notes of rules the merchant deleted. A batch a run.
     *
     * @return array{codes: int, rules: int}
     */
    public function cleanUp(): array
    {
        $codes = $this->rules->deleteExpiredCodes(gmdate('Y-m-d H:i:s'), 2000);
        $rules = 0;
        $empty = $this->rules->emptyOfferRules(gmdate('Y-m-d H:i:s', time() - 86400), 50);
        // Under the lock codes are made under, and looked at again there: a code that is being put under
        // a rule right now would go with the rule.
        if ($empty && $this->locks->lock(self::LOCK, 0)) {
            try {
                foreach ($empty as $ruleId) {
                    if (!$this->rules->hasCodes($ruleId)) {
                        $this->deleteRule($ruleId);
                        $rules++;
                    }
                }
            } finally {
                $this->locks->unlock(self::LOCK);
            }
        }
        $this->rules->forgetDeletedRules(500);
        return ['codes' => $codes, 'rules' => $rules];
    }

    /**
     * Removes every rule of bluebarry's with its codes (the uninstall).
     *
     * @return void
     */
    public function deleteAll(): void
    {
        foreach ($this->rules->allRules() as $ruleId) {
            $this->deleteRule($ruleId);
        }
    }

    /**
     * @param int $ruleId
     * @return void
     */
    private function deleteRule(int $ruleId): void
    {
        $rule = $this->ruleFactory->create();
        $this->ruleResource->load($rule, $ruleId);
        if ($rule->getId()) {
            $this->ruleResource->delete($rule);
        }
        $this->rules->forget($ruleId);
    }

    /**
     * The rule for these terms: the one made before, or a new one. The caller holds the lock.
     *
     * @param array $terms
     * @param string $kind
     * @param string $tenantId
     * @return int
     */
    private function ruleFor(array $terms, string $kind, string $tenantId): int
    {
        $hash = hash('sha256', (string) json_encode($terms));
        $ruleId = $this->rules->ruleFor($hash);
        if ($ruleId === null) {
            $rule = $this->ruleFactory->create();
            $rule->loadPost($this->ruleData($terms));
            $this->ruleResource->save($rule);
            $ruleId = (int) $rule->getId();
            $this->rules->record($ruleId, $hash, $kind, $tenantId);
        }
        return $ruleId;
    }

    /**
     * What a rule is made from, and found again by: only what decides the discount, in a fixed order.
     *
     * @param array $spec
     * @param WebsiteInterface $website
     * @return array
     * @throws RefusedException
     */
    private function terms(array $spec, WebsiteInterface $website): array
    {
        $type = (string) ($spec['discountType'] ?? '');
        if (!in_array($type, ['percent', 'fixed_cart', 'fixed_product'], true)) {
            throw new RefusedException('Unknown discount type.');
        }
        $freeShipping = !empty($spec['freeShipping']);
        $amount = round(max(0, (float) ($spec['amount'] ?? 0)), 4);
        if ($type === 'percent') {
            $amount = min(100, $amount);
        }
        if ($amount <= 0 && !$freeShipping) {
            throw new RefusedException('A discount needs an amount.');
        }
        $products = $this->products($spec['productIds'] ?? [], 'None of the products exist in this store.');
        $required = $this->products($spec['requiredProductIds'] ?? [], 'A product this offer needs does not exist in this store.', true);
        $minimum = round(max(0, (float) ($spec['minimumSpend'] ?? 0)), 4);
        return [
            'tenant' => $this->tenantId($website),
            'websites' => $this->sisterWebsites($website),
            'type' => $type,
            'amount' => $amount,
            'freeShipping' => $freeShipping,
            'products' => $products,
            'required' => $required,
            'minimum' => $minimum,
            // The subtotal the cart shows the shopper, which is what a merchant's minimum means.
            'minimumInclTax' => $minimum > 0 && in_array((int) $this->scopeConfig->getValue(
                'tax/cart_display/subtotal',
                ScopeInterface::SCOPE_WEBSITE,
                $website->getId()
            ), [2, 3], true),
            'individual' => !empty($spec['individualUse']),
        ];
    }

    /**
     * The products a description names, as a cart price rule can know them: by SKU, each with the SKUs
     * of the configurable products it is a variant of (see productConditions()).
     *
     * @param mixed $productIds
     * @param string $refusal said when products are named but none (or, with $all, not all) exist
     * @param bool $all
     * @return array<int, array{sku: string, parents: string[]}> sorted by SKU
     * @throws RefusedException
     */
    private function products($productIds, string $refusal, bool $all = false): array
    {
        $ids = array_values(array_unique(array_map('strval', is_array($productIds) ? $productIds : [])));
        if (!$ids) {
            return [];
        }
        if (count($ids) > self::MAX_PRODUCTS) {
            throw new RefusedException('Too many products for one discount code.');
        }
        $skus = $this->rules->skus($ids);
        // A restriction that names no product of this store must not become a discount on everything.
        if (!$skus || ($all && count($skus) < count($ids))) {
            throw new RefusedException($refusal);
        }
        $parents = $this->rules->configurableParents(array_map('strval', array_keys($skus)));
        $products = [];
        foreach ($skus as $id => $sku) {
            $of = array_values(array_unique($parents[(string) $id] ?? []));
            sort($of, SORT_STRING);
            $products[$sku] = ['sku' => $sku, 'parents' => $of];
        }
        ksort($products, SORT_STRING);
        return array_values($products);
    }

    /**
     * The websites a code holds on: every website of this Magento that reports to the same bluebarry
     * account as the one asked, so a code mailed to a shopper works on whichever of them they return to.
     *
     * @param WebsiteInterface $website
     * @return int[] sorted
     */
    private function sisterWebsites(WebsiteInterface $website): array
    {
        $tenantId = strtolower((string) $this->config->getWebsiteTenantId($website->getId()));
        $ids = [(int) $website->getId()];
        foreach ($this->storeManager->getWebsites() as $other) {
            if ($tenantId !== '' && strtolower((string) $this->config->getWebsiteTenantId($other->getId())) === $tenantId) {
                $ids[] = (int) $other->getId();
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    /**
     * @param mixed $expiresAt ISO 8601
     * @return string|null UTC, Y-m-d H:i:s
     * @throws RefusedException
     */
    private function expiry($expiresAt): ?string
    {
        if ($expiresAt === null || $expiresAt === '') {
            return null;
        }
        $time = is_string($expiresAt) ? strtotime($expiresAt) : false;
        if ($time === false) {
            throw new RefusedException('Invalid expiry.');
        }
        return gmdate('Y-m-d H:i:s', $time);
    }

    /**
     * The cart price rule for a set of terms, as the admin's own form would post it.
     *
     * @param array $terms
     * @return array
     */
    private function ruleData(array $terms): array
    {
        $conditions = ['1' => ['type' => ConditionCombine::class, 'aggregator' => 'all', 'value' => '1', 'new_child' => '']];
        $next = 1;
        if ($terms['minimum'] > 0) {
            $conditions['1--' . $next++] = [
                'type' => AddressCondition::class,
                'attribute' => $terms['minimumInclTax'] ? 'base_subtotal_total_incl_tax' : 'base_subtotal',
                'operator' => '>=',
                'value' => (string) $terms['minimum'],
            ];
        }
        // An offer built around products gives nothing unless each of them is in the cart.
        foreach ($terms['required'] as $product) {
            $key = '1--' . $next++;
            $conditions[$key] = ['type' => ProductFound::class, 'value' => '1', 'aggregator' => 'any', 'new_child' => ''];
            $conditions += self::productConditions($key, [$product]);
        }
        // Which lines the discount comes off: any of the products, or every line when none is named.
        $actions = ['1' => ['type' => ProductCombine::class, 'aggregator' => 'any', 'value' => '1', 'new_child' => '']];
        $actions += self::productConditions('1', $terms['products']);

        return [
            'name' => $this->name($terms),
            'description' => 'Made by bluebarry for its discount codes: popups, quizzes, recommendations and rewards.'
                . ' Every code under it is single-use. bluebarry adds the codes and removes expired ones; deleting this rule ends its codes.',
            'is_active' => 1,
            'website_ids' => $terms['websites'],
            'customer_group_ids' => array_map('intval', $this->customerGroups->create()->getAllIds()),
            'coupon_type' => Rule::COUPON_TYPE_SPECIFIC,
            'use_auto_generation' => 1,
            // Per code, set on each code: the rule itself is used once for every person it has a code for.
            'uses_per_coupon' => 1,
            'uses_per_customer' => 0,
            'from_date' => '',
            'to_date' => '',
            'sort_order' => 0,
            'is_rss' => 0,
            'simple_action' => ['percent' => Rule::BY_PERCENT_ACTION, 'fixed_cart' => Rule::CART_FIXED_ACTION, 'fixed_product' => Rule::BY_FIXED_ACTION][$terms['type']],
            'discount_amount' => $terms['amount'],
            // An amount off each product comes off one unit of it, whatever the quantity.
            'discount_qty' => $terms['type'] === 'fixed_product' ? 1 : 0,
            'discount_step' => 0,
            'apply_to_shipping' => 0,
            // 2: free shipping for the shipment that holds matching items (Magento's "For shipment with matching items").
            'simple_free_shipping' => $terms['freeShipping'] ? 2 : 0,
            'stop_rules_processing' => $terms['individual'] ? 1 : 0,
            'conditions' => $conditions,
            'actions' => $actions,
        ];
    }

    /**
     * Conditions that are true for a cart line that is one of the products, bought as itself: the line
     * of the product, or a configurable product's line with it as the chosen variant. Under $key, whose
     * own condition says "any of these".
     *
     * Not simply "SKU is one of": Magento also holds a line's parts against a product condition, so a
     * bundle with the product as one of its parts would count as the product, and a fixed-price bundle
     * would get the product's discount over its whole price. Each condition is therefore held to the
     * line itself ("parent") or to its parts ("children"), which Magento's own rules can say, and a
     * part only counts under the configurable product it is a variant of. One condition per SKU, not a
     * list: a SKU may hold a comma.
     *
     * @param string $key
     * @param array<int, array{sku: string, parents: string[]}> $products
     * @return array
     */
    private static function productConditions(string $key, array $products): array
    {
        $sku = function (string $scope, string $value): array {
            return ['type' => ProductCondition::class, 'attribute' => 'sku', 'attribute_scope' => $scope, 'operator' => '==', 'value' => $value];
        };
        $conditions = [];
        $next = 1;
        foreach ($products as $product) {
            // The product's own line.
            $conditions[$key . '--' . $next++] = $sku('parent', $product['sku']);
            if (!$product['parents']) {
                continue;
            }
            // A configurable product's line, with this product as its variant.
            $variant = $key . '--' . $next++;
            $conditions[$variant] = ['type' => ProductCombine::class, 'aggregator' => 'all', 'value' => '1', 'new_child' => ''];
            $conditions[$variant . '--1'] = $sku('children', $product['sku']);
            $conditions[$variant . '--2'] = ['type' => ProductCombine::class, 'aggregator' => 'any', 'value' => '1', 'new_child' => ''];
            foreach ($product['parents'] as $index => $parent) {
                $conditions[$variant . '--2--' . ($index + 1)] = $sku('parent', $parent);
            }
        }
        return $conditions;
    }

    /**
     * The rule's name in the merchant's list of cart price rules.
     *
     * @param array $terms
     * @return string
     */
    private function name(array $terms): string
    {
        $amount = rtrim(rtrim(number_format((float) $terms['amount'], 2, '.', ''), '0'), '.');
        if ($terms['amount'] <= 0) {
            $what = 'free shipping';
        } elseif ($terms['type'] === 'percent') {
            $what = $amount . '% off';
        } else {
            $what = $amount . ' off' . ($terms['type'] === 'fixed_product' ? ' per product' : '');
        }
        if ($terms['amount'] > 0 && $terms['freeShipping']) {
            $what .= ' and free shipping';
        }
        if ($terms['products']) {
            $what .= ', ' . count($terms['products']) . (count($terms['products']) === 1 ? ' product' : ' products');
        }
        if ($terms['minimum'] > 0) {
            $what .= ', from ' . rtrim(rtrim(number_format((float) $terms['minimum'], 2, '.', ''), '0'), '.');
        }
        return 'bluebarry: ' . $what;
    }
}
