<?php

namespace Bluebarry\Bluebarry\Model;

use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * The bluebarry visitor behind the current request, from the SDK's first-party cookies (names in
 * ReactShared/src/lib/visitor-identity.ts): bb_uid for every visitor, bb_session and bb_advisor after
 * a quiz, bb_experiments for the experiments they were shown. The cookies are on the store's own
 * domain, so they arrive with the checkout request itself.
 */
class Visitor
{
    /**
     * Experiment domains an order can count for; the quiz measures its own conversions.
     */
    public const EXPERIMENT_DOMAINS = ['recommendation', 'collection', 'search', 'popup'];

    /**
     * @var CookieManagerInterface
     */
    private $cookies;

    /**
     * @var CookieHelper
     */
    private $cookieHelper;

    /**
     * @var Session
     */
    private $session;

    /**
     * @param CookieManagerInterface $cookies
     * @param CookieHelper $cookieHelper
     * @param Session $session
     */
    public function __construct(CookieManagerInterface $cookies, CookieHelper $cookieHelper, Session $session)
    {
        $this->cookies = $cookies;
        $this->cookieHelper = $cookieHelper;
        $this->session = $session;
    }

    /**
     * The visitor, or null when there is none or the shopper did not allow cookies.
     *
     * @param string $tenantId
     * @return array{user_id: string, session_id: ?string, advisor_id: ?string, experiments: array}|null
     */
    public function current(string $tenantId): ?array
    {
        // Magento's cookie restriction mode: without the shopper's consent the order stays unlinked.
        if ($this->cookieHelper->isUserNotAllowSaveCookie()) {
            return null;
        }

        $userId = $this->uuidCookie('bb_uid');
        $sessionId = $this->uuidCookie('bb_session');
        $advisorId = $this->uuidCookie('bb_advisor');

        // A quiz taken before the cookies existed, or on the advisor's own domain, is only known from
        // the storefront script's session update (Controller\Session\Update). The order then goes to
        // that quiz's visitor, session and quiz together, so the buyer's email joins their answers. A
        // visitor cookie still wins: an older quiz in the session never takes the order from it.
        if ($userId === null || $sessionId === null) {
            $quiz = $this->session->getSession()['bluebarry'] ?? null;
            if (is_array($quiz) && self::isUuid($quiz['user_id'] ?? null)
                && ($userId === null || $userId === strtolower($quiz['user_id']))) {
                $userId = strtolower($quiz['user_id']);
                $sessionId = self::isUuid($quiz['session_id'] ?? null) ? strtolower($quiz['session_id']) : null;
                $advisorId = self::isUuid($quiz['advisor_id'] ?? null) ? strtolower($quiz['advisor_id']) : null;
            }
        }

        if ($userId === null) {
            return null;
        }

        return [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'advisor_id' => $advisorId,
            'experiments' => $this->experiments($tenantId),
        ];
    }

    /**
     * The experiments the shopper was shown: this tenant only, supported domains, valid ids, the last
     * eight. The same filter as the Shopify pixel and the WooCommerce plugin.
     *
     * @param string $tenantId
     * @return array<int, array{domain: string, targetId: string, exposureId: string}>
     */
    private function experiments(string $tenantId): array
    {
        $stored = json_decode((string) $this->cookies->getCookie('bb_experiments'), true);
        if (!is_array($stored) || !is_array($stored['contexts'] ?? null)
            || strtolower((string) ($stored['tenantId'] ?? '')) !== strtolower($tenantId)) {
            return [];
        }

        $contexts = [];
        foreach ($stored['contexts'] as $context) {
            if (!is_array($context) || !in_array($context['domain'] ?? null, self::EXPERIMENT_DOMAINS, true)
                || !self::isUuid($context['targetId'] ?? null) || !self::isUuid($context['exposureId'] ?? null)) {
                continue;
            }
            $contexts[] = [
                'domain' => $context['domain'],
                'targetId' => $context['targetId'],
                'exposureId' => $context['exposureId'],
            ];
        }
        return array_slice($contexts, -8);
    }

    /**
     * @param string $name
     * @return string|null
     */
    private function uuidCookie(string $name): ?string
    {
        $value = $this->cookies->getCookie($name);
        return self::isUuid($value) ? strtolower($value) : null;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public static function isUuid($value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
