<?php

namespace Bluebarry\Bluebarry\Model\Discount;

use Bluebarry\Bluebarry\Model\Api\Client;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\ResourceModel\DiscountRules;
use Bluebarry\Bluebarry\Model\Visitor;
use Magento\Checkout\Model\Session;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * bluebarry's discounts on the shopper's cart: a code (a quiz's, a popup's, a reward's) and the signed
 * offers of quiz results and recommendation blocks, which become one-time codes once the cart holds
 * the products they cover.
 *
 * A Magento cart takes one coupon code. A code the shopper entered themselves is never pushed out: the
 * bluebarry code then waits (and is shown to the shopper, who can choose it). A newer bluebarry code
 * does replace an older bluebarry code.
 *
 * What the cart cannot take yet (a code for an empty cart or one below the code's minimum, an offer
 * whose products are not in it) waits in the shopper's browser, not here: the SDK holds it and asks
 * again when the cart changed. Nothing of it is kept in the session, where a store without session
 * locking can lose it to another request of the same shopper.
 *
 * Nothing here runs on a page a shopper waits for, nor with Magento's own totals: the SDK asks from
 * the browser (Controller\Cart\Discount), after the page and the add to cart are done.
 */
class Cart
{
    /** Codes this module put on this shopper's cart: only these, and bluebarry's own, give way to a newer one. */
    public const SESSION_APPLIED = 'bluebarry_applied_codes';

    /** A real offer is a few hundred characters; the caps bound what one request can be made to look at. */
    private const MAX_GRANT = 4096;
    private const MAX_OFFERS = 20;

    /** When bluebarry did not answer an offer: no offer of this shopper is asked about again before this time. */
    public const SESSION_OFFERS_AFTER = 'bluebarry_offers_after';

    /**
     * Seconds bluebarry gets to check an offer. No page waits on it, but the shopper's session is held
     * meanwhile, which can make their next request wait: so not long, and after a miss not again for a while.
     */
    private const OFFER_TIMEOUT = 2;
    private const OFFER_BACK_OFF = 120;

    /**
     * @var Session
     */
    private $checkoutSession;

    /**
     * @var CartRepositoryInterface
     */
    private $quotes;

    /**
     * @var DiscountRules
     */
    private $rules;

    /**
     * @var Coupons
     */
    private $coupons;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var CookieManagerInterface
     */
    private $cookies;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Session $checkoutSession
     * @param CartRepositoryInterface $quotes
     * @param DiscountRules $rules
     * @param Coupons $coupons
     * @param Client $client
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param CookieManagerInterface $cookies
     * @param LoggerInterface $logger
     */
    public function __construct(
        Session $checkoutSession,
        CartRepositoryInterface $quotes,
        DiscountRules $rules,
        Coupons $coupons,
        Client $client,
        Config $config,
        StoreManagerInterface $storeManager,
        CookieManagerInterface $cookies,
        LoggerInterface $logger
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->quotes = $quotes;
        $this->rules = $rules;
        $this->coupons = $coupons;
        $this->client = $client;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->cookies = $cookies;
        $this->logger = $logger;
    }

    /**
     * Puts a coupon code on the shopper's cart.
     *
     * @param string $code
     * @return string applied (the cart has the code now), waiting (it cannot take it yet: an empty
     *     cart, or one below the code's minimum), other_code (the shopper's own code stays) or
     *     unknown (no such code in this store)
     */
    public function applyCode(string $code): string
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 255 || !$this->rules->codeExists($code)) {
            return 'unknown';
        }
        $quote = $this->checkoutSession->getQuote();
        $current = (string) $quote->getCouponCode();
        if ($current !== '' && strcasecmp($current, $code) === 0) {
            // Already there, whoever put it there: a code the shopper entered themselves stays theirs,
            // also when a popup happens to offer the same one.
            return 'applied';
        }
        if ($current !== '' && !$this->isOurs($current)) {
            return 'other_code';
        }
        if ($quote->getItemsCount() && $this->put($quote, $code)) {
            $this->remember($code);
            return 'applied';
        }
        if ($current !== '' && $quote->getItemsCount()) {
            // The cart cannot take the new code yet: the bluebarry code it had stays meanwhile.
            $this->put($quote, $current);
        }
        return 'waiting';
    }

    /**
     * Redeems the newest of the shopper's offers that the cart now covers: bluebarry checks it and
     * describes its one-time code, which is made and put on the cart. The whole set in the cart gets
     * the bundle rule, part of it the per-product rule (or nothing, and the offer waits, when it has
     * none). One offer at a time: the cart takes one code.
     *
     * @param string[] $grants the offers the browser holds, the newest last
     * @return array{applied: bool, drop: string[], reason?: string} drop: the offers the browser can
     *     forget (redeemed, passed over for a newer one, or never giving anything); the others wait on
     */
    public function redeemOffers(array $grants): array
    {
        $result = ['applied' => false, 'drop' => []];
        // The newest first. What is no offer at all is forgotten right away.
        $offers = [];
        foreach (array_reverse(array_slice(array_values($grants), -self::MAX_OFFERS)) as $grant) {
            $payload = is_string($grant) && $grant !== '' && strlen($grant) <= self::MAX_GRANT ? self::grantPayload($grant) : null;
            $covered = $payload ? array_map('strval', (array) ($payload['variantIds'] ?? [])) : [];
            if ($covered) {
                $offers[] = ['grant' => $grant, 'payload' => $payload, 'covered' => $covered];
            } elseif (is_string($grant)) {
                $result['drop'][] = $grant;
            }
        }
        if (!$offers) {
            return $result;
        }
        $quote = $this->checkoutSession->getQuote();
        $current = (string) $quote->getCouponCode();
        if ($current !== '' && !$this->isOurs($current)) {
            // The shopper's own code stays, and bluebarry is not even asked while it does.
            return $result + ['reason' => 'other_code'];
        }
        $website = $this->storeManager->getWebsite();
        $apiKey = $this->config->getWebsiteApiKey($website->getId());
        $tenantId = $this->config->getWebsiteTenantId($website->getId());
        $inCart = array_flip($this->references($quote));
        // bluebarry did not answer a moment ago: it is not asked again right away, so a slow bluebarry
        // holds this shopper's session once, not with every change of their cart.
        if ($apiKey === null || $tenantId === null || !$inCart || (int) $this->checkoutSession->getData(self::SESSION_OFFERS_AFTER) > time()) {
            return $result;
        }
        // The visitor is the one this browser's bluebarry cookie names, never what the request says:
        // an offer made for one shopper must not be redeemable by someone who copied it.
        $uid = (string) $this->cookies->getCookie('bb_uid');
        $uid = Visitor::isUuid($uid) ? $uid : '';
        foreach ($offers as $index => $offer) {
            $payload = $offer['payload'];
            $covered = $offer['covered'];
            if (!array_intersect_key(array_flip($covered), $inCart)) {
                continue; // nothing of it in the cart yet
            }
            // A bundle built around a product gives nothing unless that product is being bought too.
            $required = array_map('strval', (array) ($payload['requiredVariantIds'] ?? []));
            if (array_diff_key(array_flip($required), $inCart)) {
                continue;
            }
            $wholeSet = !array_diff_key(array_flip($covered), $inCart);
            if (!$wholeSet && !self::hasRule($payload, 'single')) {
                continue; // a bundle-only offer waits for the whole set
            }
            // A whole-set discount needs the whole set to stay in the cart, not only what it was built around.
            $keep = $wholeSet && self::hasRule($payload, 'kit') ? array_values(array_unique(array_merge($required, $covered))) : $required;
            $outcome = $this->redeem($quote, $website, ['grant' => $offer['grant'], 'uid' => $uid], $wholeSet, $keep, $apiKey, $tenantId);
            if ($outcome === 'applied') {
                // The newest offer the cart covers is on it. The older ones are passed over for good:
                // kept, the next change of the cart would put one of them in its place.
                $result['drop'] = array_merge($result['drop'], array_column(array_slice($offers, $index), 'grant'));
            } elseif ($outcome !== 'retry') {
                $result['drop'][] = $offer['grant'];
            }
            $result['applied'] = $outcome === 'applied';
            break; // one offer at a time: the newest the cart covers
        }
        return $result;
    }

    /**
     * Whether a code on the cart may give way to a newer bluebarry code: bluebarry made it, or this
     * module put it there for this shopper (a quiz's own code is the merchant's coupon).
     *
     * @param string $code
     * @return bool
     */
    public function isOurs(string $code): bool
    {
        foreach ((array) $this->checkoutSession->getData(self::SESSION_APPLIED) as $applied) {
            if (is_string($applied) && strcasecmp($applied, $code) === 0) {
                return true;
            }
        }
        return $this->rules->isOurs($code);
    }

    /**
     * What an offer says it covers. Unverified: bluebarry checks the signature before anything is given.
     *
     * @param string $grant
     * @return array|null
     */
    public static function grantPayload(string $grant): ?array
    {
        $parts = explode('.', $grant);
        if (count($parts) !== 2) {
            return null;
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/') . str_repeat('=', (4 - strlen($parts[0]) % 4) % 4), true);
        $data = $json === false ? null : json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array $payload
     * @param string $scope single or kit
     * @return bool
     */
    private static function hasRule(array $payload, string $scope): bool
    {
        foreach ((array) ($payload['rules'] ?? []) as $rule) {
            if (is_array($rule) && strtolower((string) ($rule['scope'] ?? '')) === $scope && (float) ($rule['value'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param Quote $quote
     * @param \Magento\Store\Api\Data\WebsiteInterface $website
     * @param array $offer
     * @param bool $wholeSet
     * @param string[] $required
     * @param string $apiKey
     * @param string $tenantId
     * @return string applied, invalid (never gives anything) or retry (try after a later cart change)
     */
    private function redeem(Quote $quote, $website, array $offer, bool $wholeSet, array $required, string $apiKey, string $tenantId): string
    {
        $response = $this->client->post('/data/magento/offers/coupon', [
            'grant' => $offer['grant'],
            'userId' => $offer['uid'] !== '' ? $offer['uid'] : null,
            'wholeSet' => $wholeSet,
        ], $tenantId, $apiKey, self::OFFER_TIMEOUT);
        $status = $response->getStatus();
        if ($status === 204 || ($status >= 400 && $status < 500 && $status !== 429)) {
            return 'invalid';
        }
        $spec = $response->isSuccess() ? json_decode($response->getBody(), true) : null;
        if (!is_array($spec)) {
            $this->checkoutSession->setData(self::SESSION_OFFERS_AFTER, time() + self::OFFER_BACK_OFF);
            return 'retry';
        }
        try {
            $this->coupons->ensure($spec + ['requiredProductIds' => $required], $website, DiscountRules::KIND_OFFER);
        } catch (RefusedException $e) {
            $this->logger->info('bluebarry: an offer gives nothing here: ' . $e->getMessage());
            return 'invalid';
        } catch (\Exception $e) {
            $this->logger->warning('bluebarry: could not make the code for an offer: ' . $e->getMessage());
            return 'retry';
        }
        $code = (string) ($spec['code'] ?? '');
        $before = (string) $quote->getCouponCode();
        if ($this->put($quote, $code)) {
            $this->remember($code);
            return 'applied';
        }
        if ($before !== '') {
            $this->put($quote, $before);
        }
        return 'retry';
    }

    /**
     * Sets the cart's coupon code the way the cart page's own coupon field does.
     *
     * @param Quote $quote
     * @param string $code
     * @return bool whether the cart took it
     */
    private function put(Quote $quote, string $code): bool
    {
        $quote->getShippingAddress()->setCollectShippingRates(true);
        // Collected afresh also the second time in one request (a code the cart refused, then the one
        // it had before): Magento otherwise keeps the totals of the first.
        $quote->setTotalsCollectedFlag(false);
        $quote->setCouponCode($code)->collectTotals();
        $this->quotes->save($quote);
        return strcasecmp((string) $quote->getCouponCode(), $code) === 0;
    }

    /**
     * @param string $code
     * @return void
     */
    public function remember(string $code): void
    {
        $applied = array_values(array_filter((array) $this->checkoutSession->getData(self::SESSION_APPLIED), 'is_string'));
        if (!in_array($code, $applied, true)) {
            $applied[] = $code;
            $this->checkoutSession->setData(self::SESSION_APPLIED, array_slice($applied, -10));
        }
    }

    /**
     * Every product in the cart as the catalog sync names it: the variant for a configurable product.
     *
     * @param Quote $quote
     * @return string[]
     */
    private function references(Quote $quote): array
    {
        $references = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $variant = $item->getOptionByCode('simple_product');
            $reference = (int) ($variant ? $variant->getValue() : $item->getProductId());
            if ($reference > 0) {
                $references[(string) $reference] = true;
            }
        }
        return array_map('strval', array_keys($references));
    }
}
