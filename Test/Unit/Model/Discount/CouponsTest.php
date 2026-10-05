<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Discount;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Discount\Coupons;
use Bluebarry\Bluebarry\Model\Discount\RefusedException;
use Bluebarry\Bluebarry\Model\ResourceModel\DiscountRules;
use Magento\Customer\Model\ResourceModel\Group\Collection as GroupCollection;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as GroupCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Condition\Address as AddressCondition;
use Magento\SalesRule\Model\Rule\Condition\Product as ProductCondition;
use Magento\SalesRule\Model\Rule\Condition\Product\Found as ProductFound;
use Magento\SalesRule\Model\RuleFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;

class CouponsTest extends TestCase
{
    /** @var array<string, array> code => coupon row, as bluebarry's rules hold them */
    private array $coupons = [];
    /** @var array<string, int> terms hash => rule id */
    private array $rules = [];
    /** @var array<int, array> the cart price rules made, as posted */
    private array $posted = [];
    /** @var array<int, string> coupon id => when it ends */
    private array $ends = [];
    private int $cartSubtotalDisplay = 1;

    public function testAPercentageCodeIsASingleUseCodeUnderARuleForItsTerms(): void
    {
        $state = $this->coupons()->ensure(['code' => 'WELCOME-7KQ2', 'discountType' => 'percent', 'amount' => 10, 'expiresAt' => '2030-01-15T12:00:00Z'], $this->website(1));

        // The merchant's own coupon has id 1.
        $this->assertSame(['exists' => true, 'id' => 2, 'status' => 'publish', 'usageCount' => 0], $state);
        $rule = $this->posted[0];
        $this->assertSame('bluebarry: 10% off', $rule['name']);
        $this->assertSame([Rule::BY_PERCENT_ACTION, 10.0, 0, 0, 0], [$rule['simple_action'], $rule['discount_amount'], $rule['discount_qty'], $rule['simple_free_shipping'], $rule['stop_rules_processing']]);
        $this->assertSame([Rule::COUPON_TYPE_SPECIFIC, 1, 1, 0], [$rule['coupon_type'], $rule['use_auto_generation'], $rule['uses_per_coupon'], $rule['uses_per_customer']]);
        // Every website of this Magento that reports to the same bluebarry account, and every customer group.
        $this->assertSame([1, 3], $rule['website_ids']);
        $this->assertSame([0, 1, 2], $rule['customer_group_ids']);
        // No condition, and every line discounted.
        $this->assertCount(1, $rule['conditions']);
        $this->assertCount(1, $rule['actions']);
        $this->assertSame([2 => '2030-01-15 12:00:00'], $this->ends);
    }

    public function testCodesWithTheSameTermsShareOneRule_OtherTermsGetTheirOwn(): void
    {
        $coupons = $this->coupons();
        $spec = ['discountType' => 'fixed_cart', 'amount' => 5, 'minimumSpend' => 50];

        $coupons->ensure($spec + ['code' => 'REWARD-AAAA'], $this->website(1));
        $coupons->ensure($spec + ['code' => 'REWARD-BBBB', 'expiresAt' => '2031-01-01T00:00:00Z'], $this->website(1));
        $this->assertCount(1, $this->posted);
        $this->assertSame($this->coupons['REWARD-AAAA']['rule_id'], $this->coupons['REWARD-BBBB']['rule_id']);

        $coupons->ensure(['code' => 'REWARD-CCCC', 'minimumSpend' => 60] + $spec, $this->website(1));
        $this->assertCount(2, $this->posted);

        // The same code asked again is the same code, whatever is asked for it now.
        $again = $coupons->ensure(['code' => 'REWARD-AAAA', 'discountType' => 'percent', 'amount' => 99], $this->website(1));
        $this->assertSame((int) $this->coupons['REWARD-AAAA']['coupon_id'], $again['id']);
        $this->assertCount(2, $this->posted);
    }

    public function testTheMinimumIsTheSubtotalTheCartShows(): void
    {
        $spec = ['discountType' => 'fixed_cart', 'amount' => 5, 'minimumSpend' => 50, 'individualUse' => true];
        $this->coupons()->ensure($spec + ['code' => 'EXCL-0001'], $this->website(1));
        $this->cartSubtotalDisplay = 2; // the cart shows its subtotal including tax
        $this->coupons()->ensure($spec + ['code' => 'INCL-0001'], $this->website(1));

        $this->assertSame('bluebarry: 5 off, from 50', $this->posted[0]['name']);
        $this->assertSame(Rule::CART_FIXED_ACTION, $this->posted[0]['simple_action']);
        // Kept from combining with other discounts: no other cart price rule applies after it.
        $this->assertSame(1, $this->posted[0]['stop_rules_processing']);
        $this->assertSame(['type' => AddressCondition::class, 'attribute' => 'base_subtotal', 'operator' => '>=', 'value' => '50'], $this->posted[0]['conditions']['1--1']);
        $this->assertSame('base_subtotal_total_incl_tax', $this->posted[1]['conditions']['1--1']['attribute']);
    }

    public function testFreeShippingIsARuleThatOnlyGivesFreeShipping(): void
    {
        $this->coupons()->ensure(['code' => 'SHIP-0001', 'discountType' => 'fixed_cart', 'amount' => 0, 'freeShipping' => true], $this->website(1));

        $this->assertSame('bluebarry: free shipping', $this->posted[0]['name']);
        $this->assertSame([0.0, 2], [$this->posted[0]['discount_amount'], $this->posted[0]['simple_free_shipping']]);
    }

    public function testAnOfferDiscountsOnlyItsProducts_OncePerProduct_AndNeedsWhatItWasBuiltAround(): void
    {
        $this->coupons()->ensure([
            'code' => 'BB-0123456789AB', 'discountType' => 'fixed_product', 'amount' => 5,
            'productIds' => ['18', '17', '17'], 'requiredProductIds' => ['10'],
        ], $this->website(1), DiscountRules::KIND_OFFER);

        $rule = $this->posted[0];
        $this->assertSame('bluebarry: 5 off per product, 2 products', $rule['name']);
        // An amount off each product comes off one unit of it.
        $this->assertSame([Rule::BY_FIXED_ACTION, 1], [$rule['simple_action'], $rule['discount_qty']]);
        // One condition per SKU (a SKU may hold a comma), any of them.
        $this->assertSame('any', $rule['actions']['1']['aggregator']);
        $this->assertSame(['type' => ProductCondition::class, 'attribute' => 'sku', 'operator' => '==', 'value' => 'mug, blue'], $rule['actions']['1--1']);
        $this->assertSame('mug-red', $rule['actions']['1--2']['value']);
        $this->assertSame(ProductFound::class, $rule['conditions']['1--1']['type']);
        $this->assertSame('espresso', $rule['conditions']['1--1--1']['value']);
        $this->assertSame(DiscountRules::KIND_OFFER, $this->rules['kind']);
    }

    /**
     * @dataProvider refused
     */
    public function testACodeThatCannotBeMadeAsDescribedIsRefused_AndNothingIsMade(array $spec): void
    {
        try {
            $this->coupons()->ensure($spec, $this->website(1));
            $this->fail('made');
        } catch (RefusedException $e) {
            $this->assertSame([], $this->posted);
            $this->assertSame(['MERCHANT5'], array_keys($this->coupons));
        }
    }

    public static function refused(): array
    {
        $ok = ['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10];
        return [
            'the merchant has a coupon with this code' => [['code' => 'MERCHANT5'] + $ok],
            'a code Magento would not take' => [['code' => 'has spaces'] + $ok],
            'an unknown kind of discount' => [['discountType' => 'buy_one_get_one'] + $ok],
            'a discount of nothing' => [['amount' => 0] + $ok],
            'products that are not in this store' => [$ok + ['productIds' => ['999']]],
            'a product the offer needs that is not in this store' => [$ok + ['productIds' => ['17'], 'requiredProductIds' => ['10', '999']]],
            'an expiry that is no date' => [$ok + ['expiresAt' => 'soon']],
        ];
    }

    public function testAMerchantsCodeIsNeverLookedAtOrRemoved_AnUnusedBluebarryCodeIs(): void
    {
        $coupons = $this->coupons();
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->state('MERCHANT5'));
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->revoke('MERCHANT5'));
        $this->assertArrayHasKey('MERCHANT5', $this->coupons);

        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
        $coupons->ensure(['code' => 'CODE-0002', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
        $this->coupons['CODE-0002']['times_used'] = '1';

        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->revoke('CODE-0001'));
        // Spent: it stays as it is.
        $this->assertSame(['exists' => true, 'id' => 3, 'status' => 'publish', 'usageCount' => 1], $coupons->revoke('CODE-0002'));
    }

    public function testACodePastItsLastMomentIsNoLongerUsable_NorOneWhoseRuleWasSwitchedOff(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
        $this->coupons['CODE-0001']['expires_at'] = gmdate('Y-m-d H:i:s', time() - 60);
        $this->assertSame('disabled', $coupons->state('CODE-0001')['status']);

        $this->coupons['CODE-0001']['expires_at'] = gmdate('Y-m-d H:i:s', time() + 60);
        $this->assertSame('publish', $coupons->state('CODE-0001')['status']);
        $this->coupons['CODE-0001']['is_active'] = '0';
        $this->assertSame('disabled', $coupons->state('CODE-0001')['status']);
    }

    private function website(int $id): Website
    {
        $website = $this->createStub(Website::class);
        $website->method('getId')->willReturn($id);
        return $website;
    }

    private function coupons(): Coupons
    {
        $this->coupons += ['MERCHANT5' => ['coupon_id' => '1', 'rule_id' => '900', 'times_used' => '0', 'expires_at' => null, 'is_active' => '1', 'ours' => false]];
        $rules = $this->createStub(DiscountRules::class);
        $rules->method('coupon')->willReturnCallback(fn ($code) => ($this->coupons[$code]['ours'] ?? false) ? $this->coupons[$code] : null);
        $rules->method('codeExists')->willReturnCallback(fn ($code) => isset($this->coupons[$code]));
        $rules->method('ruleFor')->willReturnCallback(fn ($hash) => $this->rules[$hash] ?? null);
        $rules->method('record')->willReturnCallback(function ($ruleId, $hash, $kind) {
            $this->rules[$hash] = $ruleId;
            $this->rules['kind'] = $kind;
        });
        $rules->method('endsAt')->willReturnCallback(function ($couponId, $at) {
            $this->ends[$couponId] = $at;
        });
        $rules->method('skus')->willReturnCallback(fn ($ids) => array_intersect_key(['10' => 'espresso', '17' => 'mug-red', '18' => 'mug, blue'], array_flip($ids)));

        $ruleFactory = $this->createStub(RuleFactory::class);
        $ruleFactory->method('create')->willReturnCallback(function () {
            $rule = $this->createStub(Rule::class);
            $rule->method('loadPost')->willReturnCallback(function ($data) use ($rule) {
                $this->posted[] = $data;
                return $rule;
            });
            $rule->method('getId')->willReturnCallback(fn () => 100 + count($this->posted));
            return $rule;
        });

        $couponFactory = $this->createStub(CouponFactory::class);
        $couponFactory->method('create')->willReturnCallback(function () {
            $made = new \ArrayObject();
            $coupon = $this->createStub(Coupon::class);
            foreach (['setRuleId' => 'rule_id', 'setCode' => 'code', 'setUsageLimit' => 'usage_limit', 'setUsagePerCustomer' => 'usage_per_customer',
                'setType' => 'type', 'setCreatedAt' => 'created_at', 'setExpirationDate' => 'expiration_date'] as $setter => $field) {
                $coupon->method($setter)->willReturnCallback(function ($value) use ($made, $field, $coupon) {
                    $made[$field] = $value;
                    return $coupon;
                });
            }
            $coupon->method('getId')->willReturnCallback(fn () => $made['id'] ?? null);
            $coupon->method('getData')->willReturnCallback(fn () => $made->getArrayCopy());
            $coupon->method('setData')->willReturnCallback(function ($key, $value) use ($made, $coupon) {
                $made[$key] = $value;
                return $coupon;
            });
            return $coupon;
        });
        $couponResource = $this->createStub(CouponResource::class);
        $couponResource->method('save')->willReturnCallback(function ($coupon) use ($couponResource) {
            $data = $coupon->getData();
            // A single-use code of its own, generated under the rule.
            $this->assertSame([1, 1, 1], [$data['usage_limit'], $data['usage_per_customer'], $data['type']]);
            $id = count($this->coupons) + 1;
            $coupon->setData('id', $id);
            $this->coupons[$data['code']] = ['coupon_id' => (string) $id, 'rule_id' => (string) $data['rule_id'], 'times_used' => '0', 'expires_at' => null, 'is_active' => '1', 'ours' => true];
            return $couponResource;
        });
        $couponResource->method('load')->willReturnCallback(function ($coupon, $id) use ($couponResource) {
            $coupon->setData('id', $id);
            return $couponResource;
        });
        $couponResource->method('delete')->willReturnCallback(function ($coupon) use ($couponResource) {
            $this->coupons = array_filter($this->coupons, fn ($row) => (int) $row['coupon_id'] !== (int) $coupon->getId());
            return $couponResource;
        });

        $groups = $this->createStub(GroupCollection::class);
        $groups->method('getAllIds')->willReturn(['0', '1', '2']);
        $groupFactory = $this->createStub(GroupCollectionFactory::class);
        $groupFactory->method('create')->willReturn($groups);

        // Websites 1 and 3 report to the same bluebarry account, website 2 to another.
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getWebsites')->willReturn([$this->website(3), $this->website(2), $this->website(1)]);
        $config = $this->createStub(Config::class);
        $config->method('getWebsiteTenantId')->willReturnCallback(fn ($id) => (int) $id === 2 ? 'other-tenant' : 'Tenant');
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn () => (string) $this->cartSubtotalDisplay);
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);

        return new Coupons($rules, $ruleFactory, $this->createStub(RuleResource::class), $couponFactory, $couponResource, $groupFactory, $stores, $config, $scopeConfig, $locks);
    }
}
