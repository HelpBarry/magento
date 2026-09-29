<?php

namespace Bluebarry\Bluebarry\Observer;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Visitor;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every add to the cart passes here, whoever made it (the product page, a list's button, bluebarry's
 * own add): it is noted in the short-lived bb_cart_added cookie, and bluebarry's SDK reports it from
 * the shopper's browser on the next page or right after the cart changed, then clears it. The same
 * cookie and format as the WooCommerce plugin's, so the store never calls bluebarry while a shopper
 * waits, and an add that reloads the page counts like one that doesn't.
 *
 * Only for a shopper bluebarry knows (bb_uid) who allowed cookies (Magento's cookie restriction mode).
 */
class NoteAddToCart implements ObserverInterface
{
    public const COOKIE = 'bb_cart_added';
    private const KEEP = 20;

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
     * @var CookieMetadataFactory
     */
    private $cookieMetadata;

    /**
     * @var CookieHelper
     */
    private $cookieHelper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array|null the adds noted so far in this request
     */
    private $pending;

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param CookieManagerInterface $cookies
     * @param CookieMetadataFactory $cookieMetadata
     * @param CookieHelper $cookieHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        CookieManagerInterface $cookies,
        CookieMetadataFactory $cookieMetadata,
        CookieHelper $cookieHelper,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->cookies = $cookies;
        $this->cookieMetadata = $cookieMetadata;
        $this->cookieHelper = $cookieHelper;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            if ($this->config->getTenantId($this->storeManager->getStore()->getId()) === null
                || $this->cookieHelper->isUserNotAllowSaveCookie()
                || !Visitor::isUuid($this->cookies->getCookie('bb_uid'))) {
                return;
            }
            $added = [];
            // Every line one add made: a grouped product's products each have their own.
            foreach ((array) $observer->getEvent()->getData('items') as $item) {
                /** @var \Magento\Quote\Model\Quote\Item $item */
                if ($item->getParentItem()) {
                    continue; // a configurable product's variant or a bundle's part: its line is the parent's
                }
                // The catalog's reference: the variant for a configurable product.
                $variant = $item->getOptionByCode('simple_product');
                $reference = (int) ($variant ? $variant->getValue() : $item->getProductId());
                if ($reference > 0) {
                    $added[] = [
                        'r' => (string) $reference,
                        // What this add put in, not the line's total; bluebarry counts whole units.
                        'q' => max(1, min(999, (int) round((float) $item->getQtyToAdd() ?: 1))),
                        't' => time(),
                        'i' => bin2hex(random_bytes(8)),
                    ];
                }
            }
            if (!$added) {
                return;
            }
            // Several adds in one request (a kit from bluebarry) all go in: the request's cookie does
            // not show what this request already set.
            if ($this->pending === null) {
                $this->pending = self::pending((string) $this->cookies->getCookie(self::COOKIE));
            }
            $this->pending = array_slice(array_merge($this->pending, $added), -self::KEEP);
            $metadata = $this->cookieMetadata->createPublicCookieMetadata()
                // Host-only on the whole host, where the SDK clears it.
                ->setPath('/')
                ->setDuration(86400)
                ->setHttpOnly(false)
                ->setSameSite('Lax');
            $this->cookies->setPublicCookie(self::COOKIE, (string) json_encode($this->pending), $metadata);
        } catch (\Exception $e) {
            // Never in the way of an add to the cart.
            $this->logger->warning('bluebarry: could not note the add to the cart: ' . $e->getMessage());
        }
    }

    /**
     * The adds noted before and not reported yet, rebuilt field by field.
     *
     * @param string $cookie
     * @return array<int, array{r: string, q: int, t: int, i: string}>
     */
    public static function pending(string $cookie): array
    {
        $decoded = json_decode($cookie, true);
        $pending = [];
        foreach (is_array($decoded) ? $decoded : [] as $add) {
            if (is_array($add) && isset($add['r'], $add['q'], $add['t'], $add['i']) && ctype_digit((string) $add['r'])
                && preg_match('/^[a-z0-9]{1,64}$/', (string) $add['i'])) {
                $pending[] = ['r' => (string) $add['r'], 'q' => (int) $add['q'], 't' => (int) $add['t'], 'i' => (string) $add['i']];
            }
        }
        return array_slice($pending, -self::KEEP);
    }
}
