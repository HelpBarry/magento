<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Discount;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Discount\Coupons;
use Bluebarry\Bluebarry\Model\Discount\RefusedException;
use Bluebarry\Bluebarry\Model\ResourceModel\DiscountRules;
use Magento\Customer\Model\ResourceModel\Group\Collection as GroupCollection;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as GroupCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
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
    /** @var array<string, int> code => orders that carry it */
    private array $orders = [];
    /** @var array<string, string[]> product id => the configurable products it is a variant of */
    private array $parents = ['17' => ['mug'], '18' => ['mug', 'mug-gift-set']];
    private bool $endFails = false;
    private bool $locked = false;
    private int $transactions = 0;
    private array $snapshot = [];
    private int $rollbacks = 0;

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
        // The rule is this account's.
        $this->assertSame('tenant', $this->rules['tenant']);
    }

    public function testACodeAndItsEndAreMadeTogether_AndAnEndThatWasNeverNotedIsNotedWhenAskedAgain(): void
    {
        $spec = ['code' => 'WELCOME-7KQ2', 'discountType' => 'percent', 'amount' => 10, 'expiresAt' => '2030-01-15T12:00:00Z'];
        $this->endFails = true;
        try {
            $this->coupons()->ensure($spec, $this->website(1));
            $this->fail('made without its end');
        } catch (\RuntimeException $e) {
            // The code went with it: one that could never end must not be left behind.
            $this->assertSame([1, 1], [$this->transactions, $this->rollbacks]);
        }

        // A code from before this was one transaction, or whose note was lost: noted when asked again.
        $this->endFails = false;
        $this->coupons['WELCOME-7KQ2'] = ['coupon_id' => '9', 'rule_id' => '101', 'times_used' => '0', 'created_at' => null, 'expires_at' => null, 'is_active' => '1', 'ours' => true, 'tenant' => 'tenant'];
        $this->coupons()->ensure($spec, $this->website(1));
        $this->assertSame([9 => '2030-01-15 12:00:00'], $this->ends);
    }

    public function testAnotherAccountOnTheSameMagentoNeitherSeesNorEndsThisAccountsCodes(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10], $this->website(1));

        // Website 2 reports to another bluebarry account.
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->state('CODE-0001', $this->website(2)));
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->revoke('CODE-0001', $this->website(2)));
        $this->assertArrayHasKey('CODE-0001', $this->coupons);
        // Nor does it get that code as its own when it asks for the same one.
        $this->expectException(RefusedException::class);
        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10], $this->website(2));
    }

    public function testAnotherRequestMakingACodeIsWaitedFor_NotRunInto(): void
    {
        $this->locked = true;
        $this->expectException(\RuntimeException::class);
        $this->coupons()->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
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
        $sku = fn (string $scope, string $value) => ['type' => ProductCondition::class, 'attribute' => 'sku', 'attribute_scope' => $scope, 'operator' => '==', 'value' => $value];
        // Any of the products, each as a line of its own or as the variant of a configurable product it
        // belongs to: never as a part of a bundle. One condition per SKU (a SKU may hold a comma).
        $this->assertSame('any', $rule['actions']['1']['aggregator']);
        $this->assertSame($sku('parent', 'mug, blue'), $rule['actions']['1--1']);
        $this->assertSame('all', $rule['actions']['1--2']['aggregator']);
        $this->assertSame($sku('children', 'mug, blue'), $rule['actions']['1--2--1']);
        $this->assertSame('any', $rule['actions']['1--2--2']['aggregator']);
        $this->assertSame($sku('parent', 'mug'), $rule['actions']['1--2--2--1']);
        $this->assertSame($sku('parent', 'mug-gift-set'), $rule['actions']['1--2--2--2']);
        $this->assertSame($sku('parent', 'mug-red'), $rule['actions']['1--3']);
        $this->assertSame($sku('children', 'mug-red'), $rule['actions']['1--4--1']);
        // What the offer was built around must be in the cart, as a line of its own.
        $this->assertSame([ProductFound::class, 'any'], [$rule['conditions']['1--1']['type'], $rule['conditions']['1--1']['aggregator']]);
        $this->assertSame($sku('parent', 'espresso'), $rule['conditions']['1--1--1']);
        $this->assertArrayNotHasKey('1--1--2', $rule['conditions']);
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
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->state('MERCHANT5', $this->website(1)));
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->revoke('MERCHANT5', $this->website(1)));
        $this->assertArrayHasKey('MERCHANT5', $this->coupons);

        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
        $coupons->ensure(['code' => 'CODE-0002', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
        $this->coupons['CODE-0002']['times_used'] = '1';

        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->revoke('CODE-0001', $this->website(1)));
        // Spent: it stays as it is.
        $this->assertSame(['exists' => true, 'id' => 3, 'status' => 'publish', 'usageCount' => 1], $coupons->revoke('CODE-0002', $this->website(1)));
    }

    public function testACodeAnOrderCarriesIsSpent_AlsoBeforeMagentoCountedIt_AndAfterItWasRemoved(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
        // Magento counts a coupon's use from a queue: the order is there first.
        $this->orders['CODE-0001'] = 1;

        $this->assertSame(1, $coupons->state('CODE-0001', $this->website(1))['usageCount']);
        $this->assertSame(['exists' => true, 'id' => 2, 'status' => 'publish', 'usageCount' => 1], $coupons->revoke('CODE-0001', $this->website(1)));
        $this->assertArrayHasKey('CODE-0001', $this->coupons);

        // Removed once it ended: the order still says it was spent.
        unset($this->coupons['CODE-0001']);
        $this->assertSame(['exists' => false, 'usageCount' => 1], $coupons->state('CODE-0001', $this->website(1)));
    }

    public function testACheckoutThatCountsItsUseWhileTheCodeIsBeingRevokedKeepsItsCoupon(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
        // Read as unused, then counted by a checkout before the removal: the removal finds it used.
        $this->coupons['CODE-0001']['countedMeanwhile'] = true;

        $state = $coupons->revoke('CODE-0001', $this->website(1));

        $this->assertSame(['exists' => true, 'id' => 2, 'status' => 'publish', 'usageCount' => 1], $state);
    }

    public function testACodePastItsLastMomentIsNoLongerUsable_NorOneWhoseRuleWasSwitchedOff(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
        $this->coupons['CODE-0001']['expires_at'] = gmdate('Y-m-d H:i:s', time() - 60);
        $this->assertSame('disabled', $coupons->state('CODE-0001', $this->website(1))['status']);

        $this->coupons['CODE-0001']['expires_at'] = gmdate('Y-m-d H:i:s', time() + 60);
        $this->assertSame('publish', $coupons->state('CODE-0001', $this->website(1))['status']);
        $this->coupons['CODE-0001']['is_active'] = '0';
        $this->assertSame('disabled', $coupons->state('CODE-0001', $this->website(1))['status']);
    }

    public function testAnOfferRuleIsOnlyRemovedWhileItStillHoldsNoCode(): void
    {
        $removed = [];
        $coupons = $this->coupons(emptyOfferRules: [101, 102], withCodes: [102], removed: $removed);

        $this->assertSame(['codes' => 0, 'rules' => 1], $coupons->cleanUp());
        // 102 got a code between being found empty and being removed: it stays.
        $this->assertSame([101], $removed);

        // A code is being made right now: no rule is removed from under it.
        $removed = [];
        $this->locked = true;
        $this->assertSame(['codes' => 0, 'rules' => 0], $coupons->cleanUp());
        $this->assertSame([], $removed);
    }

    private function website(int $id): Website
    {
        $website = $this->createStub(Website::class);
        $website->method('getId')->willReturn($id);
        return $website;
    }

    private function coupons(array $emptyOfferRules = [], array $withCodes = [], array &$removed = []): Coupons
    {
        $this->coupons += ['MERCHANT5' => ['coupon_id' => '1', 'rule_id' => '900', 'times_used' => '0', 'created_at' => null, 'expires_at' => null, 'is_active' => '1', 'ours' => false, 'tenant' => '']];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('beginTransaction')->willReturnCallback(function () use ($connection) {
            $this->transactions++;
            $this->snapshot = $this->coupons;
            return $connection;
        });
        $connection->method('rollBack')->willReturnCallback(function () use ($connection) {
            $this->rollbacks++;
            $this->coupons = $this->snapshot;
            return $connection;
        });
        $rules = $this->createStub(DiscountRules::class);
        $rules->method('connection')->willReturn($connection);
        $rules->method('coupon')->willReturnCallback(function ($code, $tenant = null) {
            $row = $this->coupons[$code] ?? null;
            return $row && $row['ours'] && ($tenant === null || $row['tenant'] === $tenant) ? $row : null;
        });
        $rules->method('codeExists')->willReturnCallback(fn ($code) => isset($this->coupons[$code]));
        $rules->method('ruleFor')->willReturnCallback(fn ($hash) => $this->rules[$hash] ?? null);
        $rules->method('record')->willReturnCallback(function ($ruleId, $hash, $kind, $tenant) {
            $this->rules[$hash] = $ruleId;
            $this->rules['kind'] = $kind;
            $this->rules['tenant'] = $tenant;
        });
        $rules->method('endsAt')->willReturnCallback(function ($couponId, $at) {
            if ($this->endFails) {
                throw new \RuntimeException('the database went away');
            }
            $this->ends[$couponId] = $at;
        });
        $rules->method('skus')->willReturnCallback(fn ($ids) => array_intersect_key(['10' => 'espresso', '17' => 'mug-red', '18' => 'mug, blue'], array_flip($ids)));
        $rules->method('configurableParents')->willReturnCallback(fn ($ids) => array_intersect_key($this->parents, array_flip($ids)));
        $rules->method('ordersWith')->willReturnCallback(fn ($code) => $this->orders[$code] ?? 0);
        $rules->method('deleteUnused')->willReturnCallback(function ($couponId) {
            foreach ($this->coupons as $code => $row) {
                if ((int) $row['coupon_id'] === (int) $couponId) {
                    if (!empty($row['countedMeanwhile'])) {
                        $this->coupons[$code]['times_used'] = '1';
                        return false;
                    }
                    unset($this->coupons[$code]);
                    return true;
                }
            }
            return false;
        });
        $rules->method('emptyOfferRules')->willReturn($emptyOfferRules);
        $rules->method('hasCodes')->willReturnCallback(fn ($ruleId) => in_array($ruleId, $withCodes, true));
        $rules->method('forget')->willReturnCallback(function ($ruleId) use (&$removed) {
            $removed[] = $ruleId;
        });

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
            $this->coupons[$data['code']] = ['coupon_id' => (string) $id, 'rule_id' => (string) $data['rule_id'], 'times_used' => '0',
                'created_at' => $data['created_at'], 'expires_at' => null, 'is_active' => '1', 'ours' => true, 'tenant' => $this->rules['tenant'] ?? 'tenant'];
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
        $locks->method('lock')->willReturnCallback(fn () => !$this->locked);

        return new Coupons($rules, $ruleFactory, $this->createStub(RuleResource::class), $couponFactory, $couponResource, $groupFactory, $stores, $config, $scopeConfig, $locks);
    }
}
