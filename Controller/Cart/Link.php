<?php

namespace Bluebarry\Bluebarry\Controller\Cart;

use Bluebarry\Bluebarry\Model\Cart\Adder;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Discount\Cart as Discounts;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as Messages;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * GET bluebarry/cart/link?items=<product id>:<quantity>,...[&code=CODE]: a link that puts products in
 * the shopper's cart on arrival and opens the cart page, like Shopify's /cart/<variant>:<quantity>. A
 * quiz that is not on the store (a landing page, a standalone quiz link) cannot post to
 * bluebarry/cart/add, which needs the form key, so it sends the shopper here instead.
 *
 * Each line is added as bluebarry/cart/add adds it (Adder): a configurable product's variant with its
 * options, a bundle with its default selections, with the same limits. An unknown or malformed line
 * is left out; the shopper still lands on the cart, with a message when something was not added.
 * Nothing but the shopper's own cart changes. `code` is a coupon code for the cart, put on the way the
 * SDK's bluebarry/cart/discount puts one (a code the shopper entered stays): no request to bluebarry.
 *
 * Never cached: the answer is a redirect with no-store, which neither the full page cache nor Varnish
 * keeps.
 */
class Link implements HttpGetActionInterface
{
    /** Longest `items` read: 20 lines of long ids and quantities fit with room to spare. */
    private const MAX_ITEMS_LENGTH = 1024;

    /** Cookies that make the theme reload the shopper's mini-cart (see reloadMiniCart()). */
    private const PRIVATE_CONTENT_VERSION = 'private_content_version';
    private const PRIVATE_CONTENT_PERIOD = 315360000;
    private const SECTION_DATA_CLEAN = 'section_data_clean';
    private const SECTION_DATA_CLEAN_PERIOD = 300;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var RedirectFactory
     */
    private $redirects;

    /**
     * @var Adder
     */
    private $adder;

    /**
     * @var Discounts
     */
    private $discounts;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Messages
     */
    private $messages;

    /**
     * @var CookieManagerInterface
     */
    private $cookies;

    /**
     * @var PublicCookieMetadataFactory
     */
    private $cookieMetadata;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param RequestInterface $request
     * @param RedirectFactory $redirects
     * @param Adder $adder
     * @param Discounts $discounts
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param Messages $messages
     * @param CookieManagerInterface $cookies
     * @param PublicCookieMetadataFactory $cookieMetadata
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestInterface $request,
        RedirectFactory $redirects,
        Adder $adder,
        Discounts $discounts,
        Config $config,
        StoreManagerInterface $storeManager,
        Messages $messages,
        CookieManagerInterface $cookies,
        PublicCookieMetadataFactory $cookieMetadata,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->request = $request;
        $this->redirects = $redirects;
        $this->adder = $adder;
        $this->discounts = $discounts;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->messages = $messages;
        $this->cookies = $cookies;
        $this->cookieMetadata = $cookieMetadata;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $result = $this->redirects->create()->setPath('checkout/cart');
        // The shopper's own cart: never kept by a cache in between.
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $raw = $this->request->getParam('items');
        $items = is_string($raw) ? self::lines($raw) : [];
        if (!$items) {
            return $result;
        }

        $outcome = $this->adder->add($items);
        if (!$outcome['success']) {
            $this->messages->addErrorMessage(($outcome['failure'] ?? '') === 'soldOut'
                ? __('Sorry, the products you chose are sold out.')
                : __('Sorry, the products you chose could not be added to your cart.'));
            return $result;
        }
        if ($outcome['skipped']) {
            $this->messages->addNoticeMessage(__('Some of the products you chose could not be added to your cart.'));
        }
        $this->applyCode($this->request->getParam('code'));
        $this->reloadMiniCart();
        return $result;
    }

    /**
     * The lines `items` names, as Adder takes them: "<id>:<quantity>" or "<id>" for one, comma
     * separated. Empty ones are passed over; anything else goes as given and Adder leaves out what it
     * cannot add. At most one line past Adder's limit, which it reports as not added.
     *
     * @param string $items
     * @return array<int, array{reference: string, quantity: string|int}>
     */
    public static function lines(string $items): array
    {
        $lines = [];
        foreach (explode(',', substr($items, 0, self::MAX_ITEMS_LENGTH)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (count($lines) > Adder::MAX_LINES) {
                break;
            }
            $parts = explode(':', $line, 2);
            $lines[] = ['reference' => trim($parts[0]), 'quantity' => isset($parts[1]) ? trim($parts[1]) : 1];
        }
        return $lines;
    }

    /**
     * Puts the link's coupon code on the cart, the way bluebarry/cart/discount does: a code the shopper
     * entered themselves stays, an unknown code is ignored.
     *
     * @param mixed $code
     * @return void
     */
    private function applyCode($code): void
    {
        if (!is_string($code) || trim($code) === '' || $this->config->getTenantId($this->storeManager->getStore()->getId()) === null) {
            return;
        }
        try {
            $this->discounts->applyCode($code);
        } catch (\Exception $e) {
            // The products are in the cart; the code is the shopper's to enter on the cart page.
            $this->logger->warning('bluebarry: could not put a cart link\'s code on the cart: ' . $e->getMessage());
        }
    }

    /**
     * Tells the theme the shopper's cart changed. A POST does that in the browser (Luma) or through
     * Magento's private_content_version cookie (Hyvä); a link is a GET, so both are said here: Hyvä
     * reloads the shopper's data when that cookie changes, Luma once when section_data_clean is set (and
     * clears it). Otherwise the mini-cart shows what it held before the shopper left the store.
     *
     * @return void
     */
    private function reloadMiniCart(): void
    {
        $secure = $this->request->isSecure();
        try {
            // As Magento's own PageCache\Version sets it after a POST.
            $this->cookies->setPublicCookie(self::PRIVATE_CONTENT_VERSION, bin2hex(random_bytes(16)),
                $this->cookieMetadata->create()->setDuration(self::PRIVATE_CONTENT_PERIOD)
                    ->setPath('/')->setSecure($secure)->setHttpOnly(false)->setSameSite('Lax'));
            // As Luma's customer-data sets it (domain included, or its clearing would miss this one), and
            // short-lived: a cookie it does not clear only reloads the shopper's data for a few minutes.
            $clean = $this->cookieMetadata->create()->setDuration(self::SECTION_DATA_CLEAN_PERIOD)
                ->setPath('/')->setSecure($secure)->setHttpOnly(false)->setSameSite('Lax');
            $domain = (string) $this->scopeConfig->getValue('web/cookie/cookie_domain', ScopeInterface::SCOPE_STORE);
            if ($domain !== '') {
                $clean->setDomain($domain);
            }
            $this->cookies->setPublicCookie(self::SECTION_DATA_CLEAN, 'cart', $clean);
        } catch (\Exception $e) {
            $this->logger->info('bluebarry: could not mark the mini-cart for reloading: ' . $e->getMessage());
        }
    }
}
