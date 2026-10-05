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
        $existing = $this->state($code);
        if ($existing['exists']) {
            return $existing;
        }
        if ($this->rules->codeExists($code)) {
            // The merchant's own code, or another extension's: never taken over.
            throw new RefusedException('This store already has a coupon with that code.');
        }
        $terms = $this->terms($spec, $website);
        $expires = $this->expiry($spec['expiresAt'] ?? null);

        $ruleId = $this->ruleFor($terms, $kind);
        $coupon = $this->couponFactory->create();
        $coupon->setRuleId($ruleId)
            ->setCode($code)
            ->setUsageLimit(1)
            ->setUsagePerCustomer(1)
            ->setType(CouponHelper::COUPON_TYPE_SPECIFIC_AUTOGENERATED)
            ->setCreatedAt(gmdate('Y-m-d H:i:s'));
        if ($expires !== null) {
            // For whoever reads the coupon; what ends the code is the note below.
            $coupon->setExpirationDate($expires);
        }
        try {
            $this->couponResource->save($coupon);
        } catch (\Exception $e) {
            // Two requests for the same code at once: the other one made it.
            $made = $this->state($code);
            if ($made['exists']) {
                return $made;
            }
            throw $e;
        }
        if ($expires !== null) {
            $this->rules->endsAt((int) $coupon->getId(), $expires);
        }
        return $this->state($code);
    }

    /**
     * What became of one of bluebarry's codes. A code that is not bluebarry's does not exist here.
     *
     * @param string $code
     * @return array{exists: bool, id?: int, status?: string, usageCount: int}
     */
    public function state(string $code): array
    {
        $coupon = $this->rules->coupon($code);
        if ($coupon === null) {
            return ['exists' => false, 'usageCount' => 0];
        }
        // Past its last moment and not removed by the cron yet.
        $expired = $coupon['expires_at'] !== null && strtotime($coupon['expires_at'] . ' UTC') < time();
        return [
            'exists' => true,
            'id' => (int) $coupon['coupon_id'],
            // The word bluebarry knows a usable code by, from the WooCommerce plugin.
            'status' => (int) $coupon['is_active'] === 1 && !$expired ? 'publish' : 'disabled',
            'usageCount' => (int) $coupon['times_used'],
        ];
    }

    /**
     * Removes one of bluebarry's codes that nobody spent, so it can't be. A spent one stays as it is.
     *
     * @param string $code
     * @return array{exists: bool, id?: int, status?: string, usageCount: int}
     */
    public function revoke(string $code): array
    {
        $coupon = $this->rules->coupon($code);
        if ($coupon !== null && (int) $coupon['times_used'] === 0) {
            $model = $this->couponFactory->create();
            $this->couponResource->load($model, (int) $coupon['coupon_id']);
            if ($model->getId()) {
                $this->couponResource->delete($model);
            }
        }
        return $this->state($code);
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
        foreach ($this->rules->emptyOfferRules(gmdate('Y-m-d H:i:s', time() - 86400), 50) as $ruleId) {
            $this->deleteRule($ruleId);
            $rules++;
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
     * The rule for these terms: the one made before, or a new one. One at a time, so two codes with
     * the same new terms end up under one rule.
     *
     * @param array $terms
     * @param string $kind
     * @return int
     * @throws RefusedException
     */
    private function ruleFor(array $terms, string $kind): int
    {
        $hash = hash('sha256', (string) json_encode($terms));
        $ruleId = $this->rules->ruleFor($hash);
        if ($ruleId !== null) {
            return $ruleId;
        }
        if (!$this->locks->lock(self::LOCK, 10)) {
            throw new \RuntimeException('Another discount code is being made; try again.');
        }
        try {
            $ruleId = $this->rules->ruleFor($hash);
            if ($ruleId === null) {
                $rule = $this->ruleFactory->create();
                $rule->loadPost($this->ruleData($terms));
                $this->ruleResource->save($rule);
                $ruleId = (int) $rule->getId();
                $this->rules->record($ruleId, $hash, $kind);
            }
            return $ruleId;
        } finally {
            $this->locks->unlock(self::LOCK);
        }
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
        $products = $this->skus($spec['productIds'] ?? [], 'None of the products exist in this store.');
        $required = $this->skus($spec['requiredProductIds'] ?? [], 'A product this offer needs does not exist in this store.', true);
        $minimum = round(max(0, (float) ($spec['minimumSpend'] ?? 0)), 4);
        return [
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
     * The SKUs of the products a description names: Magento's cart price rules know products by SKU.
     *
     * @param mixed $productIds
     * @param string $refusal said when products are named but none (or, with $all, not all) exist
     * @param bool $all
     * @return string[] sorted
     * @throws RefusedException
     */
    private function skus($productIds, string $refusal, bool $all = false): array
    {
        $ids = array_values(array_unique(array_map('strval', is_array($productIds) ? $productIds : [])));
        if (!$ids) {
            return [];
        }
        if (count($ids) > self::MAX_PRODUCTS) {
            throw new RefusedException('Too many products for one discount code.');
        }
        $skus = array_values(array_unique($this->rules->skus($ids)));
        // A restriction that names no product of this store must not become a discount on everything.
        if (!$skus || ($all && count($skus) < count($ids))) {
            throw new RefusedException($refusal);
        }
        sort($skus, SORT_STRING);
        return $skus;
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
        foreach ($terms['required'] as $sku) {
            $key = '1--' . $next++;
            $conditions[$key] = ['type' => ProductFound::class, 'value' => '1', 'aggregator' => 'all', 'new_child' => ''];
            $conditions[$key . '--1'] = ['type' => ProductCondition::class, 'attribute' => 'sku', 'operator' => '==', 'value' => $sku];
        }
        // Which lines the discount comes off: any of the products, or every line when none is named.
        // One condition per SKU, not a list: a SKU may hold a comma.
        $actions = ['1' => ['type' => ProductCombine::class, 'aggregator' => 'any', 'value' => '1', 'new_child' => '']];
        foreach ($terms['products'] as $index => $sku) {
            $actions['1--' . ($index + 1)] = ['type' => ProductCondition::class, 'attribute' => 'sku', 'operator' => '==', 'value' => $sku];
        }

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
