<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Discount;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Discount\Coupons;
use Bluebarry\Bluebarry\Model\Discount\RefusedException;
use Bluebarry\Bluebarry\Model\ResourceModel\DiscountRules;
use Magento\Customer\Model\ResourceModel\Group\Collection as GroupCollection;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as GroupCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Magento\SalesRule\Api\CouponRepositoryInterface;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\Coupon\Quote\UpdateCouponUsages;
use Magento\SalesRule\Model\Coupon\Usage\Processor;
use Magento\SalesRule\Model\Coupon\Usage\UpdateInfo;
use Magento\SalesRule\Model\Coupon\Usage\UpdateInfoFactory;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Condition\Address as AddressCondition;
use Magento\SalesRule\Model\Rule\Condition\Product as ProductCondition;
use Magento\SalesRule\Model\Rule\Condition\Product\Found as ProductFound;
use Magento\SalesRule\Model\RuleFactory;
use Magento\SalesRule\Plugin\CouponUsagesIncrement;
use Magento\Store\Model\Store;
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
    /** @var array<string, array<int, int>> code => store view => orders there that carry it */
    private array $orders = [];
    /** @var array<int, array{0: string, 1: ?string, 2: ?string}> the order searches made: code, since, until */
    private array $orderSearches = [];
    /** @var array<string, array> code => what is kept of it once removed */
    private array $removedCodes = [];
    /** @var string[] the locks taken */
    private array $lockNames = [];
    /** @var array<string, true> the locks held now: like Magento's cache and file locks, one is not taken twice */
    private array $held = [];
    /** A lock whose taking fails with an error (the lock's backend went away). */
    private ?string $lockThrows = null;
    /** @var array<int, string> website => its currency, where it is not euros */
    private array $currencies = [];
    private ?\Closure $whileWaitingForTheLock = null;
    private ?LockManagerInterface $locks = null;
    private int $openedToGroups = 0;
    /** @var array<string, string[]> product id => the configurable products it is a variant of */
    private array $parents = ['17' => ['mug'], '18' => ['mug', 'mug-gift-set']];
    private bool $endFails = false;
    private bool $locked = false;
    private int $transactions = 0;
    private array $snapshot = [];
    private int $rollbacks = 0;
    /** @var int[] how many expired codes each look-up found */
    private array $expiredBatches = [];
    /** @var int[] expired codes' rows whose coupon the merchant deleted (with its rule) */
    private array $orphans = [];
    private ?\Closure $whileEnding = null;
    private int $cleanupSeconds = 40;

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

    public function testACodeThatCannotBeMadeAsDescribedIsRefused_AndNothingIsMade(): void
    {
        // A loop, not a data provider: the PHPUnit versions of the supported Magento releases name those differently.
        foreach (self::refused() as $case => $spec) {
            try {
                $this->coupons()->ensure($spec, $this->website(1));
                $this->fail('made: ' . $case);
            } catch (RefusedException $e) {
                $this->assertSame([], $this->posted, $case);
                $this->assertSame(['MERCHANT5'], array_keys($this->coupons), $case);
            }
        }
    }

    private static function refused(): array
    {
        $ok = ['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10];
        return [
            'the merchant has a coupon with this code' => ['code' => 'MERCHANT5'] + $ok,
            'a code Magento would not take' => ['code' => 'has spaces'] + $ok,
            'an unknown kind of discount' => ['discountType' => 'buy_one_get_one'] + $ok,
            'a discount of nothing' => ['amount' => 0] + $ok,
            'products that are not in this store' => $ok + ['productIds' => ['999']],
            'a product the offer needs that is not in this store' => $ok + ['productIds' => ['17'], 'requiredProductIds' => ['10', '999']],
            'an expiry that is no date' => $ok + ['expiresAt' => 'soon'],
        ];
    }

    public function testAMerchantsCodeIsNeverLookedAtOrRemoved_AnUnusedBluebarryCodeIs(): void
    {
        $coupons = $this->coupons();
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->state('MERCHANT5', $this->website(1)));
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->revoke('MERCHANT5', $this->website(1)));
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->state('NEVER-MADE', $this->website(1)));
        $this->assertArrayHasKey('MERCHANT5', $this->coupons);
        // A code bluebarry never made is not searched for in the store's orders.
        $this->assertSame([], $this->orderSearches);

        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
        $coupons->ensure(['code' => 'CODE-0002', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
        $this->coupons['CODE-0002']['times_used'] = '1';

        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->revoke('CODE-0001', $this->website(1)));
        // Not deleted but spent, under the lock Magento counts a coupon's use under: a checkout that
        // already holds the code finds its one use gone, and is refused by Magento itself.
        $this->assertSame(['1', '1'], [$this->coupons['CODE-0001']['times_used'], $this->coupons['CODE-0001']['revoked']]);
        // By its code, and from Magento 2.4.9 by its id.
        $this->assertContains('coupon_code_CODE-0001', $this->lockNames);
        $this->assertContains('coupon_code_2', $this->lockNames);
        // Gone for bluebarry from then on, also when asked again.
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->state('CODE-0001', $this->website(1)));
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->revoke('CODE-0001', $this->website(1)));
        // Spent: it stays as it is.
        $this->assertSame(['exists' => true, 'id' => 3, 'status' => 'publish', 'usageCount' => 1], $coupons->revoke('CODE-0002', $this->website(1)));
    }

    public function testACodeDoesNotHoldOnASisterWebsiteThatSellsInAnotherCurrency(): void
    {
        // Website 3 reports to the same account as website 1, in pounds: 5 off would be another amount there.
        $this->currencies[3] = 'GBP';

        $this->coupons()->ensure(['code' => 'CODE-0001', 'discountType' => 'fixed_cart', 'amount' => 5], $this->website(1));

        $this->assertSame([1], $this->posted[0]['website_ids']);
    }

    public function testTheSameRequestDeliveredTwiceAtOnceMakesOneCode(): void
    {
        $coupons = $this->coupons();
        // The other delivery makes the code while this one waits for the lock.
        $this->whileWaitingForTheLock = function () {
            $this->coupons['CODE-0001'] = ['coupon_id' => '7', 'rule_id' => '101', 'times_used' => '0', 'created_at' => null, 'expires_at' => null, 'is_active' => '1', 'ours' => true, 'tenant' => 'tenant'];
        };

        $state = $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10], $this->website(1));

        $this->assertSame(['exists' => true, 'id' => 7, 'status' => 'publish', 'usageCount' => 0], $state);
        $this->assertSame([], $this->posted);
    }

    public function testTheCleanUpOpensTheRulesToCustomerGroupsMadeSince(): void
    {
        $this->coupons()->cleanUp();

        $this->assertSame(1, $this->openedToGroups);
    }

    public function testACodeThatCannotBeLockedIsNotTakenBack(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
        $this->locked = true;

        // A checkout is counting its use right now: the code stays, and bluebarry asks again later.
        $this->assertSame(['exists' => true, 'id' => 2, 'status' => 'publish', 'usageCount' => 0], $coupons->revoke('CODE-0001', $this->website(1)));
    }

    public function testACodeAnOrderCarriesIsSpent_AlsoBeforeMagentoCountedIt_AndAfterItWasRemoved(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10, 'expiresAt' => '2030-01-15T12:00:00Z'], $this->website(1));
        $made = $this->coupons['CODE-0001']['created_at'];
        // Magento counts a coupon's use from a queue: the order is there first.
        $this->orders['CODE-0001'] = [11 => 1];

        $this->assertSame(1, $coupons->state('CODE-0001', $this->website(1))['usageCount']);
        $this->assertSame(['exists' => true, 'id' => 2, 'status' => 'publish', 'usageCount' => 1], $coupons->revoke('CODE-0001', $this->website(1)));
        $this->assertArrayHasKey('CODE-0001', $this->coupons);

        // It ends unspent by Magento's count (the queue is behind), and is removed: the order still says
        // it was spent, found among the orders placed while the code existed. Its sister website's
        // orders count, another account's website learns nothing of them and searches nothing.
        $this->expire('CODE-0001');
        $coupons->cleanUp();
        $this->assertSame('1', $this->coupons['CODE-0001']['revoked']);
        $this->removeEnded($coupons);
        $this->orderSearches = [];
        $this->assertSame(['exists' => false, 'usageCount' => 1], $coupons->state('CODE-0001', $this->website(1)));
        $this->assertSame(['exists' => false, 'usageCount' => 1], $coupons->state('CODE-0001', $this->website(3)));
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->state('CODE-0001', $this->website(2)));
        $this->assertCount(2, $this->orderSearches);
        foreach ($this->orderSearches as [, $since, $until]) {
            $this->assertSame($made, $since);
            $this->assertNotNull($until);
        }
    }

    public function testAnExpiredCodeIsSpentUnderMagentosCouponLock_KeptAWhile_ThenRemoved(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10, 'expiresAt' => '2030-01-15T12:00:00Z'], $this->website(1));
        $coupons->ensure(['code' => 'CODE-0002', 'discountType' => 'percent', 'amount' => 10, 'expiresAt' => '2030-01-15T12:00:00Z'], $this->website(1));
        $this->expire('CODE-0001');

        $this->assertSame(['ended' => 1, 'codes' => 0, 'rules' => 0], $coupons->cleanUp());
        // Not deleted but spent, under the lock Magento counts a coupon's use under: a checkout that
        // already holds the code finds its one use gone, and is refused by Magento itself.
        $this->assertSame(['1', '1', 1], [$this->coupons['CODE-0001']['times_used'], $this->coupons['CODE-0001']['revoked'], $this->coupons['CODE-0001']['stage']]);
        $this->assertContains('coupon_code_CODE-0001', $this->lockNames);
        $this->assertContains('coupon_code_2', $this->lockNames);
        // Nobody spent it: not reported as used.
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->state('CODE-0001', $this->website(1)));
        // The code that has not ended is left as it is.
        $this->assertSame(['exists' => true, 'id' => 3, 'status' => 'publish', 'usageCount' => 0], $coupons->state('CODE-0002', $this->website(1)));

        // Removed ten minutes later: still not used.
        $this->assertSame(1, $this->removeEnded($coupons));
        $this->assertArrayNotHasKey('CODE-0001', $this->coupons);
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->state('CODE-0001', $this->website(1)));
    }

    public function testCodesThatAllExpireTogetherAllEndInOneRun_ABatchAtATime(): void
    {
        $coupons = $this->coupons();
        // A popup's codes, all made for the same weekend: more than two batches end at the same moment.
        $this->weekend(1201);

        $this->assertSame(1201, $coupons->cleanUp()['ended']);
        $this->assertSame([500, 500, 201], $this->expiredBatches);
        foreach ($this->coupons as $code => $row) {
            if (str_starts_with($code, 'WEEKEND-')) {
                $this->assertSame(DiscountRules::STAGE_ENDED, $row['stage'], $code);
            }
        }
    }

    public function testABatchOfCodesWhoseRuleTheMerchantDeletedDoesNotStopTheRun(): void
    {
        $coupons = $this->coupons();
        $this->orphans = range(5001, 5500);
        $this->weekend(3);

        $this->assertSame(3, $coupons->cleanUp()['ended']);
        $this->assertSame([500, 3], $this->expiredBatches);
        $this->assertSame([], $this->orphans);
    }

    public function testNoCodeIsStartedOnceTheRunsTimeIsSpent(): void
    {
        $this->cleanupSeconds = 2;
        $coupons = $this->coupons();
        $this->weekend(3);
        // The database is slow: ending the first code takes the whole run's time.
        $this->whileEnding = fn () => sleep(2);

        $this->assertSame(1, $coupons->cleanUp()['ended']);
        $this->assertSame([DiscountRules::STAGE_ENDED, 0, 0], array_map(fn ($i) => (int) ($this->coupons['WEEKEND-' . $i]['stage'] ?? 0), [1, 2, 3]));
        // The rest end on the next run.
        $this->assertSame(2, $coupons->cleanUp()['ended']);
    }

    public function testExpiredCodesThatCannotBeEndedNowDoNotKeepTheRunGoing(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10, 'expiresAt' => '2030-01-15T12:00:00Z'], $this->website(1));
        $this->expire('CODE-0001');

        // Held by a checkout: looked at once, and left for the next run.
        $this->locked = true;
        $this->assertSame(0, $coupons->cleanUp()['ended']);
        $this->assertSame([1], $this->expiredBatches);
    }

    public function testAnUnusedCodeIsLookedForInTheLastDaysOrdersOnly_NotInEveryOrderSinceItWasMade(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => 'LOYAL-1', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
        $coupons->ensure(['code' => 'LOYAL-2', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
        // A loyalty reward made months ago and never used, and one made an hour ago.
        $this->coupons['LOYAL-1']['created_at'] = gmdate('Y-m-d H:i:s', time() - 90 * 86400);
        $this->coupons['LOYAL-2']['created_at'] = gmdate('Y-m-d H:i:s', time() - 3600);
        $this->orderSearches = [];

        $before = time();
        $coupons->state('LOYAL-1', $this->website(1));
        $coupons->revoke('LOYAL-1', $this->website(1));
        $after = time();
        // Magento has counted every use older than that (at checkout from 2.4.6-p9, 2.4.7-p4 and 2.4.8;
        // from its queue before them): only the orders the count may still miss are looked at.
        $this->assertNotEmpty($this->orderSearches);
        // Also once it was taken back, until it is removed.
        $this->assertCount(3, $this->orderSearches);
        foreach ($this->orderSearches as [$code, $since, $until]) {
            $this->assertSame('LOYAL-1', $code);
            $this->assertGreaterThanOrEqual(gmdate('Y-m-d H:i:s', $before - 86400), $since);
            $this->assertLessThanOrEqual(gmdate('Y-m-d H:i:s', $after - 86400), $since);
            $this->assertNull($until);
        }

        // A code younger than a day: the orders since it was made.
        $this->orderSearches = [];
        $coupons->state('LOYAL-2', $this->website(1));
        $this->assertSame([['LOYAL-2', $this->coupons['LOYAL-2']['created_at'], null]], $this->orderSearches);
    }

    public function testAnExpiredCodeAShopperSpentStaysSpent_AlsoOnceRemoved(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10, 'expiresAt' => '2030-01-15T12:00:00Z'], $this->website(1));
        $this->coupons['CODE-0001']['times_used'] = '1';
        $this->expire('CODE-0001');

        $coupons->cleanUp();
        $this->assertSame(['exists' => true, 'id' => 2, 'status' => 'disabled', 'usageCount' => 1], $coupons->state('CODE-0001', $this->website(1)));
        $this->removeEnded($coupons);
        $this->orderSearches = [];
        $this->assertSame(['exists' => false, 'usageCount' => 1], $coupons->state('CODE-0001', $this->website(1)));
        // Magento's own count said so: no orders were searched.
        $this->assertSame([], $this->orderSearches);
    }

    public function testAnExpiredCodeACheckoutIsCountingRightNowEndsOnTheNextRun(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10, 'expiresAt' => '2030-01-15T12:00:00Z'], $this->website(1));
        $this->expire('CODE-0001');

        $this->locked = true;
        $this->assertSame(['ended' => 0, 'codes' => 0, 'rules' => 0], $coupons->cleanUp());
        $this->assertSame('0', $this->coupons['CODE-0001']['times_used']);

        $this->locked = false;
        $this->assertSame(['ended' => 1, 'codes' => 0, 'rules' => 0], $coupons->cleanUp());
    }

    public function testACodeOfDigitsNamedLikeItsCouponIdIsLockedOnce(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => '1234', 'discountType' => 'percent', 'amount' => 10, 'expiresAt' => '2030-01-15T12:00:00Z'], $this->website(1));
        // Its lock by code and by id are the same lock.
        $this->coupons['1234']['coupon_id'] = '1234';
        $this->expire('1234');

        $this->assertSame(1, $coupons->cleanUp()['ended']);
        $this->assertSame(['coupon_code_1234'], array_values(array_filter($this->lockNames, fn ($name) => str_starts_with($name, 'coupon_code_'))));
        $this->assertSame([], $this->held);
    }

    public function testALockTakenBeforeTheSecondFailedIsLetGo(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10, 'expiresAt' => '2030-01-15T12:00:00Z'], $this->website(1));

        $this->lockThrows = 'coupon_code_2';
        try {
            $coupons->revoke('CODE-0001', $this->website(1));
            $this->fail('the error was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('the lock backend went away', $e->getMessage());
        }
        // The lock by code was let go, and nothing was spent.
        $this->assertSame([], $this->held);
        $this->assertSame('0', $this->coupons['CODE-0001']['times_used']);

        // Held by a checkout instead: let go too, and the code ends on a later run.
        $this->lockThrows = null;
        $this->held['coupon_code_2'] = true;
        $this->expire('CODE-0001');
        $this->assertSame(0, $coupons->cleanUp()['ended']);
        $this->assertSame(['coupon_code_2' => true], $this->held);
        unset($this->held['coupon_code_2']);
        $this->assertSame(1, $coupons->cleanUp()['ended']);
        $this->assertSame([], $this->held);
    }

    public function testACheckoutThatHeldACodeWhenItExpiredIsRefusedByMagento(): void
    {
        if (!(new \ReflectionClass(Processor::class))->hasConstant('LOCK_NAME')) {
            $this->markTestSkipped('This Magento counts a coupon\'s use after the order (before 2.4.6-p9 and 2.4.7-p4).');
        }
        $coupons = $this->coupons();
        $spec = ['discountType' => 'percent', 'amount' => 10, 'expiresAt' => '2030-01-15T12:00:00Z'];
        $coupons->ensure($spec + ['code' => 'CODE-0001'], $this->website(1));
        $coupons->ensure($spec + ['code' => 'CODE-0002'], $this->website(1));
        // Both carts got their code and its discount (their totals were collected); then one code's last
        // moment passed and the cron ended it, before that checkout was submitted.
        $expired = $this->cart('CODE-0001');
        $usable = $this->cart('CODE-0002');
        $this->expire('CODE-0001');
        $coupons->cleanUp();

        // Magento's own checkout (SalesRule's plugin on submitting a quote) counts the use first.
        $placed = [];
        $checkout = $this->magentoCheckout();
        $checkout->aroundSubmit($this->createStub(QuoteManagement::class), function ($quote) use (&$placed) {
            $placed[] = $quote->getCouponCode();
            return null;
        }, $usable);
        try {
            $checkout->aroundSubmit($this->createStub(QuoteManagement::class), function ($quote) use (&$placed) {
                $placed[] = $quote->getCouponCode();
                return null;
            }, $expired);
            $this->fail('The order was placed with the expired code\'s discount.');
        } catch (CouldNotSaveException $e) {
            $this->assertStringContainsString('CODE-0001', $e->getMessage());
        }
        $this->assertSame(['CODE-0002'], $placed);
        $this->assertSame('1', $this->coupons['CODE-0002']['times_used']);
        // Still reported as never used.
        $this->assertSame(['exists' => false, 'usageCount' => 0], $coupons->state('CODE-0001', $this->website(1)));
    }

    public function testACheckoutThatCountsItsUseWhileTheCodeIsBeingRevokedKeepsItsCoupon(): void
    {
        $coupons = $this->coupons();
        $coupons->ensure(['code' => 'CODE-0001', 'discountType' => 'percent', 'amount' => 10], $this->website(1));
        // Read as unused, then counted by a checkout before it is taken back: that finds it used.
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

        $this->assertSame(['ended' => 0, 'codes' => 0, 'rules' => 1], $coupons->cleanUp());
        // 102 got a code between being found empty and being removed: it stays.
        $this->assertSame([101], $removed);

        // A code is being made right now: no rule is removed from under it.
        $removed = [];
        $this->locked = true;
        $this->assertSame(['ended' => 0, 'codes' => 0, 'rules' => 0], $coupons->cleanUp());
        $this->assertSame([], $removed);
    }

    /** Moves a code's last moment into the past. */
    /** A popup's codes, all made for the same weekend, which has passed. */
    private function weekend(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->coupons['WEEKEND-' . $i] = ['coupon_id' => (string) (1000 + $i), 'rule_id' => '101', 'times_used' => '0',
                'created_at' => gmdate('Y-m-d H:i:s', time() - 86400), 'expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
                'is_active' => '1', 'ours' => true, 'tenant' => 'tenant'];
        }
    }

    private function expire(string $code): void
    {
        $this->coupons[$code]['expires_at'] = gmdate('Y-m-d H:i:s', time() - 60);
    }

    /** The cron's run once the codes that ended were kept long enough. */
    private function removeEnded(Coupons $coupons): int
    {
        foreach ($this->coupons as $code => $row) {
            if (($row['stage'] ?? 0) === DiscountRules::STAGE_ENDED) {
                $this->coupons[$code]['expires_at'] = gmdate('Y-m-d H:i:s', time() - 60);
            }
        }
        return $coupons->cleanUp()['codes'];
    }

    /** A guest's cart whose totals were collected with the code on it. */
    private function cart(string $code): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $quote->setData('coupon_code', $code);
        $quote->setData('applied_rule_ids', $this->coupons[$code]['rule_id']);
        $quote->setData('customer_id', null);
        return $quote;
    }

    /**
     * Magento's own plugin that counts a coupon's use when a quote is submitted, with its own classes,
     * on the coupons this test holds and the same locks.
     */
    private function magentoCheckout(): CouponUsagesIncrement
    {
        $model = function (string $code) {
            $coupon = $this->createStub(Coupon::class);
            $coupon->method('getId')->willReturn((int) $this->coupons[$code]['coupon_id']);
            $coupon->method('getCode')->willReturn($code);
            $coupon->method('getUsageLimit')->willReturn(1);
            $coupon->method('getTimesUsed')->willReturnCallback(fn () => (int) $this->coupons[$code]['times_used']);
            $coupon->method('setTimesUsed')->willReturnCallback(function ($times) use ($code, $coupon) {
                $this->coupons[$code]['times_used'] = (string) $times;
                return $coupon;
            });
            $coupon->method('save')->willReturnSelf();
            return $coupon;
        };
        $codes = [];
        $criteria = $this->createStub(SearchCriteriaBuilder::class);
        $criteria->method('addFilter')->willReturnCallback(function ($field, $value) use (&$codes, $criteria) {
            $codes = (array) $value;
            return $criteria;
        });
        $criteria->method('create')->willReturn($this->createStub(SearchCriteria::class));
        $repository = $this->createStub(CouponRepositoryInterface::class);
        $repository->method('getList')->willReturnCallback(function () use (&$codes, $model) {
            $results = $this->createStub(SearchResultsInterface::class);
            $results->method('getItems')->willReturn(array_map($model, array_values(array_intersect($codes, array_keys($this->coupons)))));
            return $results;
        });
        $repository->method('getById')->willReturnCallback(function ($id) use ($model) {
            foreach ($this->coupons as $code => $row) {
                if ((int) $row['coupon_id'] === (int) $id) {
                    return $model($code);
                }
            }
            throw new \RuntimeException('no such coupon');
        });
        $updateInfo = $this->createStub(UpdateInfoFactory::class);
        $updateInfo->method('create')->willReturnCallback(fn () => new UpdateInfo());

        $processor = $this->make(Processor::class, ['couponRepository' => $repository, 'criteriaBuilder' => $criteria, 'lockManager' => $this->locks]);
        $usages = $this->make(UpdateCouponUsages::class, ['processor' => $processor, 'updateInfoFactory' => $updateInfo]);
        return $this->make(CouponUsagesIncrement::class, ['updateCouponUsages' => $usages]);
    }

    /**
     * One of Magento's classes, with what is given and stubs for the rest: their constructors differ
     * between the supported releases.
     */
    private function make(string $class, array $given): object
    {
        $args = [];
        foreach ((new \ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
            $type = $parameter->getType();
            $args[] = $given[$parameter->getName()]
                ?? ($type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $this->createStub($type->getName()) : null);
        }
        return new $class(...$args);
    }

    private function website(int $id): Website
    {
        $website = $this->createStub(Website::class);
        $website->method('getId')->willReturn($id);
        $website->method('getBaseCurrencyCode')->willReturnCallback(fn () => $this->currencies[$id] ?? 'EUR');
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
            return $row && $row['ours'] && ($tenant === null || $row['tenant'] === $tenant) ? $row + ['code' => $code, 'revoked' => null, 'stage' => null] : null;
        });
        $rules->method('removed')->willReturnCallback(function ($code, $tenant) {
            $row = $this->removedCodes[$code] ?? null;
            return $row && $row['tenant_id'] === $tenant ? $row : null;
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
            foreach ($this->coupons as $code => $row) {
                if ((int) $row['coupon_id'] === (int) $couponId) {
                    $this->coupons[$code]['expires_at'] = $at;
                }
            }
        });
        $rules->method('skus')->willReturnCallback(fn ($ids) => array_intersect_key(['10' => 'espresso', '17' => 'mug-red', '18' => 'mug, blue'], array_flip($ids)));
        $rules->method('configurableParents')->willReturnCallback(fn ($ids) => array_intersect_key($this->parents, array_flip($ids)));
        $rules->method('ordersWith')->willReturnCallback(function ($code, $since, $until, $storeIds) {
            $this->orderSearches[] = [$code, $since, $until];
            return array_sum(array_intersect_key($this->orders[$code] ?? [], array_flip($storeIds)));
        });
        // As the statements do: spent while unused, under the lock the caller holds, and marked as ended.
        $rules->method('end')->willReturnCallback(function ($coupon, $tenant, $removeAt, $onlyUnused) {
            if ($this->whileEnding) {
                ($this->whileEnding)();
                $this->whileEnding = null;
            }
            $code = $coupon['code'];
            $row = $this->coupons[$code] ?? null;
            if ($row === null) {
                return false;
            }
            $spent = (int) $row['times_used'] === 0 && empty($row['countedMeanwhile']);
            $this->coupons[$code]['times_used'] = '1';
            if ($onlyUnused ? !$spent : (int) ($row['stage'] ?? 0) !== DiscountRules::STAGE_USABLE) {
                return false;
            }
            $this->coupons[$code] = ['stage' => DiscountRules::STAGE_ENDED, 'revoked' => $spent ? '1' : '0', 'expires_at' => $removeAt, 'tenant' => $tenant] + $this->coupons[$code];
            return true;
        });
        $rules->method('expiredCodes')->willReturnCallback(function ($now, $limit) {
            // The rows of codes whose coupon the merchant deleted come first, and go.
            $due = array_map(fn ($id) => ['coupon_id' => (string) $id], $this->orphans);
            foreach ($this->coupons as $code => $row) {
                if ($row['ours'] && (int) ($row['stage'] ?? 0) === DiscountRules::STAGE_USABLE && $row['expires_at'] !== null && $row['expires_at'] < $now) {
                    $due[] = ['coupon_id' => $row['coupon_id'], 'code' => $code, 'created_at' => $row['created_at'], 'tenant_id' => $row['tenant']];
                }
            }
            $due = array_slice($due, 0, $limit);
            $codes = array_values(array_filter($due, fn ($row) => isset($row['code'])));
            $purged = array_column(array_filter($due, fn ($row) => !isset($row['code'])), 'coupon_id');
            $this->orphans = array_values(array_diff($this->orphans, array_map('intval', $purged)));
            $this->expiredBatches[] = count($due);
            return ['codes' => $codes, 'purged' => count($purged), 'full' => count($due) >= $limit];
        });
        $rules->method('removeEndedCodes')->willReturnCallback(function ($now) {
            $removed = 0;
            foreach ($this->coupons as $code => $row) {
                if (($row['stage'] ?? 0) === DiscountRules::STAGE_ENDED && $row['expires_at'] < $now) {
                    $this->removedCodes[$code] = ['revoked' => $row['revoked'], 'created_at' => $row['created_at'], 'expires_at' => $now, 'tenant_id' => $row['tenant']];
                    unset($this->coupons[$code]);
                    $removed++;
                }
            }
            return $removed;
        });
        $rules->method('openToNewCustomerGroups')->willReturnCallback(fn () => ++$this->openedToGroups);
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
        // One store view each: 11, 12 and 13.
        $stores->method('getStores')->willReturn(array_map(function (int $website) {
            $store = $this->createStub(Store::class);
            $store->method('getId')->willReturn(10 + $website);
            $store->method('getWebsiteId')->willReturn($website);
            return $store;
        }, [1, 2, 3]));
        $config = $this->createStub(Config::class);
        $config->method('getWebsiteTenantId')->willReturnCallback(fn ($id) => (int) $id === 2 ? 'other-tenant' : 'Tenant');
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn () => (string) $this->cartSubtotalDisplay);
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturnCallback(function ($name) {
            $this->lockNames[] = $name;
            if ($this->whileWaitingForTheLock) {
                ($this->whileWaitingForTheLock)();
                $this->whileWaitingForTheLock = null;
            }
            if ($name === $this->lockThrows) {
                throw new \RuntimeException('the lock backend went away');
            }
            if ($this->locked || isset($this->held[$name])) {
                return false;
            }
            $this->held[$name] = true;
            return true;
        });
        $locks->method('unlock')->willReturnCallback(function ($name) {
            $held = isset($this->held[$name]);
            unset($this->held[$name]);
            return $held;
        });

        $this->locks = $locks;
        return new Coupons($rules, $ruleFactory, $this->createStub(RuleResource::class), $couponFactory, $couponResource, $groupFactory, $stores, $config, $scopeConfig, $locks, $this->cleanupSeconds);
    }
}
