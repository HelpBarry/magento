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

        $this->assertSame('applied', $this->cart()->applyCode(' QUIZ10 '));

        $this->assertSame('QUIZ10', $this->quote->coupon);
        // The merchant's own coupon, put there by this module: it may give way to a newer bluebarry code.
        $this->assertTrue($this->cart()->isOurs('quiz10'));
    }

    public function testACodeTheShopperEnteredThemselvesStaysTheirs_AlsoWhenAPopupOffersTheSameOne(): void
    {
        $this->quote->lines = [new QuoteItemDouble(10)];
        $this->quote->coupon = 'MERCHANT5';

        // The popup's code is the very code on the cart: given, and nothing changes hands.
        $this->assertSame('applied', $this->cart()->applyCode('merchant5'));
        $this->assertFalse($this->cart()->isOurs('MERCHANT5'));

        // So a later bluebarry code still does not push it out.
        $this->assertSame('other_code', $this->cart()->applyCode('BB-NEWER'));
        $this->assertSame('MERCHANT5', $this->quote->coupon);
        $this->assertSame([], $this->quote->collected);
    }

    public function testACodeThisStoreDoesNotHaveIsUnknown(): void
    {
        $this->quote->lines = [new QuoteItemDouble(10)];

        $this->assertSame('unknown', $this->cart()->applyCode('NO-SUCH'));
        $this->assertSame('unknown', $this->cart()->applyCode(''));
        $this->assertSame([], $this->quote->collected);
    }

    public function testAnEmptyCartCannotTakeACodeYet_AndNothingOfItIsKeptHere(): void
    {
        $this->assertSame('waiting', $this->cart()->applyCode('QUIZ10'));

        $this->assertSame('', $this->quote->coupon);
        // The browser holds what waits: a session can lose it to another request of the same shopper.
        $this->assertSame([], $this->session->data);
    }

    public function testANewerBluebarryCodeReplacesAnOlderOne_AndOneTheCartRefusesLeavesTheOlderOneOn(): void
    {
        $this->quote->lines = [new QuoteItemDouble(10)];
        $this->quote->coupon = 'BB-OLDER';

        $this->assertSame('applied', $this->cart()->applyCode('BB-NEWER'));
        $this->assertSame('BB-NEWER', $this->quote->coupon);

        // Below its minimum: the cart drops it, and the code it had goes back on.
        $this->quote->coupon = 'BB-OLDER';
        $this->quote->accepts = fn (string $code) => $code !== 'BB-NEWER';
        $this->assertSame('waiting', $this->cart()->applyCode('BB-NEWER'));
        $this->assertSame('BB-OLDER', $this->quote->coupon);

        // Asked again once the cart can take it: it takes over.
        $this->quote->accepts = fn () => true;
        $this->assertSame('applied', $this->cart()->applyCode('BB-NEWER'));
        $this->assertSame('BB-NEWER', $this->quote->coupon);
    }

    public function testAnOfferWaitsForItsProducts_ThenBecomesACodeOnTheCart(): void
    {
        $cart = $this->cart();
        $grant = self::offer(['variantIds' => ['17', '18'], 'rules' => [['scope' => 'Single', 'value' => 10]]]);
        $this->quote->lines = [new QuoteItemDouble(10)];

        // Nothing of it in the cart: bluebarry is not asked, and the browser keeps it. What is no
        // offer at all is forgotten.
        $this->assertSame(['applied' => false, 'drop' => ['', 'not-an-offer']], $cart->redeemOffers(['not-an-offer', $grant, '']));
        $this->assertSame([], $this->asked);

        // The variant of a configurable product counts, not the product the line shows.
        $this->quote->lines[] = new QuoteItemDouble(16, 17);
        $this->answers = [new Response(200, (string) json_encode(['code' => 'BB-OFFER', 'discountType' => 'percent', 'amount' => 10, 'productIds' => ['17', '18']]))];
        $this->assertSame(['applied' => true, 'drop' => [$grant]], $cart->redeemOffers([$grant]));

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
        $this->assertSame(['applied' => false, 'drop' => []], $this->cart()->redeemOffers([$grant]));
        $this->assertSame([], $this->asked);

        $this->quote->lines[] = new QuoteItemDouble(16, 18);
        $this->answers = [new Response(200, (string) json_encode(['code' => 'BB-KIT', 'discountType' => 'percent', 'amount' => 15, 'productIds' => ['17', '18']]))];
        $this->assertTrue($this->cart()->redeemOffers([$grant])['applied']);

        $this->assertTrue($this->asked[0]['wholeSet']);
        // What it was built around and the set itself: with one of them gone, the code gives nothing.
        $this->assertSame(['10', '17', '18'], $this->made[0]['spec']['requiredProductIds']);
    }

    public function testAnOfferBuiltAroundAProductWaitsForIt(): void
    {
        $grant = self::offer(['variantIds' => ['17'], 'requiredVariantIds' => ['10'], 'rules' => [['scope' => 'Single', 'value' => 5]]]);
        $this->quote->lines = [new QuoteItemDouble(16, 17)];

        $this->assertSame(['applied' => false, 'drop' => []], $this->cart()->redeemOffers([$grant]));
        $this->assertSame([], $this->asked);
    }

    public function testARefusedOfferIsForgotten_OneBluebarryCouldNotCheckWaitsOn(): void
    {
        $grant = self::offer(['variantIds' => ['10'], 'rules' => [['scope' => 'Single', 'value' => 10]]]);
        $this->quote->lines = [new QuoteItemDouble(10)];

        $this->answers = [new Response(0, ''), new Response(503, ''), new Response(429, ''), new Response(400, 'Invalid or expired offer.')];
        $again = function () use ($grant) {
            unset($this->session->data[Cart::SESSION_OFFERS_AFTER]); // two minutes later
            return $this->cart()->redeemOffers([$grant])['drop'];
        };
        $this->assertSame([], $this->cart()->redeemOffers([$grant])['drop']); // no answer
        // A bluebarry that did not answer is not asked again with the very next change of the cart.
        $this->assertSame([], $this->cart()->redeemOffers([$grant])['drop']);
        $this->assertCount(1, $this->asked);
        $this->assertSame([], $again());       // bluebarry is down
        $this->assertSame([], $again());       // asked too often
        $this->assertSame([$grant], $again()); // not an offer
        $this->assertSame('', $this->quote->coupon);
        unset($this->session->data[Cart::SESSION_OFFERS_AFTER]);

        // An offer for nothing this store sells, and one whose code cannot be made here.
        $this->answers = [new Response(204, '')];
        $this->assertSame([$grant], $this->cart()->redeemOffers([$grant])['drop']);
        $this->answers = [new Response(200, (string) json_encode(['code' => 'BB-X', 'discountType' => 'percent', 'amount' => 10]))];
        $this->makingFails = new RefusedException('None of the products exist in this store.');
        $this->assertSame([$grant], $this->cart()->redeemOffers([$grant])['drop']);
        // The store could not make it right now: tried again after the next cart change.
        $this->answers = [new Response(200, (string) json_encode(['code' => 'BB-X', 'discountType' => 'percent', 'amount' => 10]))];
        $this->makingFails = new \RuntimeException('deadlock');
        $this->assertSame([], $this->cart()->redeemOffers([$grant])['drop']);
    }

    public function testWithTheShoppersOwnCodeOnTheCart_AnOfferWaitsAndBluebarryIsNotAsked(): void
    {
        $grant = self::offer(['variantIds' => ['10'], 'rules' => [['scope' => 'Single', 'value' => 10]]]);
        $this->quote->lines = [new QuoteItemDouble(10)];
        $this->quote->coupon = 'MERCHANT5';

        $this->assertSame(['applied' => false, 'drop' => [], 'reason' => 'other_code'], $this->cart()->redeemOffers([$grant]));
        $this->assertSame([], $this->asked);
        $this->assertSame('MERCHANT5', $this->quote->coupon);
    }

    public function testTheNewestOfferTheCartCoversIsRedeemed_AndOlderOnesNeverTakeItsPlace(): void
    {
        $older = self::offer(['variantIds' => ['10'], 'rules' => [['scope' => 'Single', 'value' => 5]], 'nonce' => 'a']);
        $newer = self::offer(['variantIds' => ['10'], 'rules' => [['scope' => 'Single', 'value' => 10]], 'nonce' => 'b']);
        $newest = self::offer(['variantIds' => ['77'], 'rules' => [['scope' => 'Single', 'value' => 20]], 'nonce' => 'c']);
        $this->quote->lines = [new QuoteItemDouble(10)];
        $this->answers = [new Response(200, (string) json_encode(['code' => 'BB-TEN', 'discountType' => 'percent', 'amount' => 10]))];

        // The browser's offers, the newest last. The newest waits for its product, which is not in the cart.
        $result = $this->cart()->redeemOffers([$older, $newer, $newest]);

        // The newest the cart covers is on it; the older one is passed over for good, so the next
        // change of the cart cannot put it in the newer one's place. The newest waits on.
        $this->assertSame(['applied' => true, 'drop' => [$newer, $older]], $result);
        $this->assertSame($newer, $this->asked[0]['grant']);
        $this->assertSame('BB-TEN', $this->quote->coupon);

        // The newest offer's product is added: that one may take over.
        $this->quote->lines[] = new QuoteItemDouble(77);
        $this->answers = [new Response(200, (string) json_encode(['code' => 'BB-TWENTY', 'discountType' => 'percent', 'amount' => 20]))];
        $this->assertSame(['applied' => true, 'drop' => [$newest]], $this->cart()->redeemOffers([$newest]));
        $this->assertSame('BB-TWENTY', $this->quote->coupon);
    }

    public function testOneRequestCannotMakeTheStoreLookAtMoreThanTwentyOffers(): void
    {
        $grants = [];
        for ($i = 0; $i < 30; $i++) {
            $grants[] = self::offer(['variantIds' => ['99'], 'rules' => [['scope' => 'Single', 'value' => 5]], 'nonce' => (string) $i]);
        }
        // The oldest covers the cart, but is beyond the twenty newest.
        array_unshift($grants, self::offer(['variantIds' => ['10'], 'rules' => [['scope' => 'Single', 'value' => 5]]]));
        $grants[] = str_repeat('a', 5000) . '.sig';
        $this->quote->lines = [new QuoteItemDouble(10)];

        // The oversized one is no offer; the others wait on in the browser.
        $this->assertSame([str_repeat('a', 5000) . '.sig'], $this->cart()->redeemOffers($grants)['drop']);
        $this->assertSame([], $this->asked);
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
