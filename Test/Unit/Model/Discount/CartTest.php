<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Discount;

use Bluebarry\Bluebarry\Model\Api\Client;
use Bluebarry\Bluebarry\Model\Api\Response;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Discount\Cart;
use Bluebarry\Bluebarry\Model\Discount\Coupons;
use Bluebarry\Bluebarry\Model\Discount\RefusedException;
use Bluebarry\Bluebarry\Model\ResourceModel\DiscountRules;
use Bluebarry\Bluebarry\Test\Unit\Double\CheckoutSessionDouble;
use Bluebarry\Bluebarry\Test\Unit\Double\QuoteDouble;
use Bluebarry\Bluebarry\Test\Unit\Double\QuoteItemDouble;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CartTest extends TestCase
{
    private const UID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private QuoteDouble $quote;
    private CheckoutSessionDouble $session;
    /** @var string[] coupon codes that exist in the store */
    private array $codes = ['MERCHANT5', 'BB-OLDER', 'BB-NEWER', 'QUIZ10'];
    /** @var string[] of those, bluebarry's own */
    private array $ours = ['BB-OLDER', 'BB-NEWER'];
    /** @var array<int, array> what bluebarry was asked about offers */
    private array $asked = [];
    /** @var Response[] what bluebarry answers, in turn */
    private array $answers = [];
    /** @var array<int, array> the codes made for offers */
    private array $made = [];
    private ?\Exception $makingFails = null;

    protected function setUp(): void
    {
        $this->quote = new QuoteDouble();
        $this->session = new CheckoutSessionDouble($this->quote);
    }

    public function testACodeGoesOnACartWithProducts(): void
    {
        $this->quote->lines = [new QuoteItemDouble(10)];

        $this->assertSame(['applied' => true, 'waiting' => false], $this->cart()->applyCode(' QUIZ10 '));

        $this->assertSame('QUIZ10', $this->quote->coupon);
        // The merchant's own coupon, put there by this module: it may give way to a newer bluebarry code.
        $this->assertTrue($this->cart()->isOurs('quiz10'));
    }

    public function testACodeThisStoreDoesNotHaveIsNotKeptWaiting(): void
    {
        $this->quote->lines = [new QuoteItemDouble(10)];

        $this->assertSame(['applied' => false, 'waiting' => false, 'reason' => 'unknown'], $this->cart()->applyCode('NO-SUCH'));
        $this->assertSame([], $this->session->data);
        $this->assertSame([], $this->quote->collected);
    }

    public function testAnEmptyCartKeepsTheCodeWaiting(): void
    {
        $this->assertSame(['applied' => false, 'waiting' => true], $this->cart()->applyCode('QUIZ10'));

        $this->assertSame(['code' => 'QUIZ10', 'tries' => 0], $this->session->data[Cart::SESSION_WAITING]);
        $this->assertSame('', $this->quote->coupon);
    }

    public function testTheShoppersOwnCodeIsNeverPushedOut(): void
    {
        $this->quote->lines = [new QuoteItemDouble(10)];
        $this->quote->coupon = 'MERCHANT5';

        $this->assertSame(['applied' => false, 'waiting' => false, 'reason' => 'other_code'], $this->cart()->applyCode('BB-NEWER'));

        $this->assertSame('MERCHANT5', $this->quote->coupon);
        $this->assertSame([], $this->quote->collected);
        $this->assertArrayNotHasKey(Cart::SESSION_WAITING, $this->session->data);
    }

    public function testANewerBluebarryCodeReplacesAnOlderOne_AndOneTheCartRefusesLeavesTheOlderOneOn(): void
    {
        $this->quote->lines = [new QuoteItemDouble(10)];
        $this->quote->coupon = 'BB-OLDER';

        $this->assertTrue($this->cart()->applyCode('BB-NEWER')['applied']);
        $this->assertSame('BB-NEWER', $this->quote->coupon);

        // Below its minimum: the cart drops it, the code it had goes back on, and this one waits.
        $this->quote->coupon = 'BB-OLDER';
        $this->quote->accepts = fn (string $code) => $code !== 'BB-NEWER';
        $this->assertSame(['applied' => false, 'waiting' => true], $this->cart()->applyCode('BB-NEWER'));
        $this->assertSame('BB-OLDER', $this->quote->coupon);
        $this->assertSame('BB-NEWER', $this->session->data[Cart::SESSION_WAITING]['code']);
    }

    public function testAnOfferWaitsForItsProducts_ThenBecomesACodeOnTheCart(): void
    {
        $cart = $this->cart();
        $grant = self::offer(['variantIds' => ['17', '18'], 'rules' => [['scope' => 'Single', 'value' => 10]]]);
        $this->quote->lines = [new QuoteItemDouble(10)];

        // Nothing of it in the cart: bluebarry is not asked.
        $this->assertSame(['applied' => false, 'waiting' => false, 'offers' => 1], $cart->keepOffers([$grant, 'not-an-offer', '']));
        $this->assertSame([], $this->asked);

        // The variant of a configurable product counts, not the product the line shows.
        $this->quote->lines[] = new QuoteItemDouble(16, 17);
        $this->answers = [new Response(200, (string) json_encode(['code' => 'BB-OFFER', 'discountType' => 'percent', 'amount' => 10, 'productIds' => ['17', '18']]))];
        $this->assertSame(['applied' => true, 'waiting' => false, 'offers' => 0], $cart->redeemOffers());

        // The shopper is the one the browser's cookie names; part of the set is in the cart.
        $this->assertSame([['grant' => $grant, 'userId' => self::UID, 'wholeSet' => false]], $this->asked);
        $this->assertSame('BB-OFFER', $this->quote->coupon);
        $this->assertSame(['17', '18'], $this->made[0]['spec']['productIds']);
        $this->assertSame([], $this->made[0]['spec']['requiredProductIds']);
        $this->assertSame(DiscountRules::KIND_OFFER, $this->made[0]['kind']);
    }

    public function testTheWholeSetGetsTheBundleRule_AndMustStayInTheCart(): void
    {
        $grant = self::offer(['variantIds' => ['17', '18'], 'requiredVariantIds' => ['10'], 'rules' => [['scope' => 'Kit', 'value' => 15]]]);
        $this->quote->lines = [new QuoteItemDouble(10), new QuoteItemDouble(16, 17)];

        // A bundle-only offer waits for the whole set.
        $this->assertSame(1, $this->cart()->keepOffers([$grant])['offers']);
        $this->assertSame([], $this->asked);

        $this->quote->lines[] = new QuoteItemDouble(16, 18);
        $this->answers = [new Response(200, (string) json_encode(['code' => 'BB-KIT', 'discountType' => 'percent', 'amount' => 15, 'productIds' => ['17', '18']]))];
        $this->assertTrue($this->cart()->redeemOffers()['applied']);

        $this->assertTrue($this->asked[0]['wholeSet']);
        // What it was built around and the set itself: with one of them gone, the code gives nothing.
        $this->assertSame(['10', '17', '18'], $this->made[0]['spec']['requiredProductIds']);
    }

    public function testAnOfferBuiltAroundAProductWaitsForIt(): void
    {
        $grant = self::offer(['variantIds' => ['17'], 'requiredVariantIds' => ['10'], 'rules' => [['scope' => 'Single', 'value' => 5]]]);
        $this->quote->lines = [new QuoteItemDouble(16, 17)];

        $this->assertSame(['applied' => false, 'waiting' => false, 'offers' => 1], $this->cart()->keepOffers([$grant]));
        $this->assertSame([], $this->asked);
    }

    public function testARefusedOfferIsDropped_OneBluebarryCouldNotCheckStays(): void
    {
        $grant = self::offer(['variantIds' => ['10'], 'rules' => [['scope' => 'Single', 'value' => 10]]]);
        $this->quote->lines = [new QuoteItemDouble(10)];

        $this->answers = [new Response(0, ''), new Response(503, ''), new Response(429, ''), new Response(400, 'Invalid or expired offer.')];
        $this->assertSame(1, $this->cart()->keepOffers([$grant])['offers']); // no answer
        $this->assertSame(1, $this->cart()->redeemOffers()['offers']);       // bluebarry is down
        $this->assertSame(1, $this->cart()->redeemOffers()['offers']);       // asked too often
        $this->assertSame(0, $this->cart()->redeemOffers()['offers']);       // not an offer
        $this->assertSame('', $this->quote->coupon);

        // An offer for nothing this store sells, and one whose code cannot be made here.
        $this->answers = [new Response(204, '')];
        $this->assertSame(0, $this->cart()->keepOffers([$grant])['offers']);
        $this->answers = [new Response(200, (string) json_encode(['code' => 'BB-X', 'discountType' => 'percent', 'amount' => 10]))];
        $this->makingFails = new RefusedException('None of the products exist in this store.');
        $this->assertSame(0, $this->cart()->keepOffers([$grant])['offers']);
        // The store could not make it right now: tried again after the next cart change.
        $this->answers = [new Response(200, (string) json_encode(['code' => 'BB-X', 'discountType' => 'percent', 'amount' => 10]))];
        $this->makingFails = new \RuntimeException('deadlock');
        $this->assertSame(1, $this->cart()->keepOffers([$grant])['offers']);
    }

    public function testWithTheShoppersOwnCodeOnTheCart_AnOfferWaitsAndBluebarryIsNotAsked(): void
    {
        $grant = self::offer(['variantIds' => ['10'], 'rules' => [['scope' => 'Single', 'value' => 10]]]);
        $this->quote->lines = [new QuoteItemDouble(10)];
        $this->quote->coupon = 'MERCHANT5';

        $this->assertSame(['applied' => false, 'waiting' => false, 'offers' => 1, 'reason' => 'other_code'], $this->cart()->keepOffers([$grant]));
        $this->assertSame([], $this->asked);
        $this->assertSame('MERCHANT5', $this->quote->coupon);
    }

    public function testTheNewestOfferTheCartCoversIsRedeemed_OneAtATime_AndTheSameOfferIsKeptOnce(): void
    {
        $older = self::offer(['variantIds' => ['10'], 'rules' => [['scope' => 'Single', 'value' => 5]], 'nonce' => 'a']);
        $newer = self::offer(['variantIds' => ['10'], 'rules' => [['scope' => 'Single', 'value' => 10]], 'nonce' => 'b']);
        $this->quote->lines = [new QuoteItemDouble(10)];
        $this->answers = [new Response(200, (string) json_encode(['code' => 'BB-NEWEST', 'discountType' => 'percent', 'amount' => 10]))];

        $result = $this->cart()->keepOffers([$older, $newer, $older, $newer]);

        $this->assertSame(['applied' => true, 'waiting' => false, 'offers' => 1], $result);
        $this->assertSame($newer, $this->asked[0]['grant']);
        $this->assertCount(1, $this->asked);
    }

    public function testASessionCannotBeMadeToHoldMoreThanTwentyOffers(): void
    {
        $grants = [];
        for ($i = 0; $i < 30; $i++) {
            $grants[] = self::offer(['variantIds' => ['99'], 'rules' => [['scope' => 'Single', 'value' => 5]], 'nonce' => (string) $i]);
        }
        $this->quote->lines = [new QuoteItemDouble(10)];

        $this->assertSame(20, $this->cart()->keepOffers($grants)['offers']);
        $this->assertSame(0, $this->cart()->keepOffers([str_repeat('a', 5000) . '.sig'])['offers'] - 20);
    }

    private static function offer(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=') . '.signature';
    }

    private function cart(): Cart
    {
        $rules = $this->createStub(DiscountRules::class);
        $rules->method('codeExists')->willReturnCallback(fn ($code) => in_array(strtoupper($code), $this->codes, true));
        $rules->method('isOurs')->willReturnCallback(fn ($code) => in_array(strtoupper($code), $this->ours, true));
        $coupons = $this->createStub(Coupons::class);
        $coupons->method('ensure')->willReturnCallback(function ($spec, $website, $kind) {
            if ($this->makingFails) {
                $failure = $this->makingFails;
                $this->makingFails = null;
                throw $failure;
            }
            $this->made[] = ['spec' => $spec, 'kind' => $kind];
            $this->ours[] = strtoupper($spec['code']);
            return ['exists' => true, 'id' => 1, 'status' => 'publish', 'usageCount' => 0];
        });
        $client = $this->createStub(Client::class);
        $client->method('post')->willReturnCallback(function ($path, $body) {
            $this->asked[] = $body;
            return array_shift($this->answers) ?? new Response(0, '');
        });
        $config = $this->createStub(Config::class);
        $config->method('getWebsiteApiKey')->willReturn('key');
        $config->method('getWebsiteTenantId')->willReturn('tenant');
        $website = $this->createStub(Website::class);
        $website->method('getId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getWebsite')->willReturn($website);
        $cookies = $this->createStub(CookieManagerInterface::class);
        $cookies->method('getCookie')->willReturn(self::UID);
        return new Cart($this->session, $this->createStub(CartRepositoryInterface::class), $rules, $coupons, $client, $config, $stores, $cookies, new NullLogger());
    }
}
