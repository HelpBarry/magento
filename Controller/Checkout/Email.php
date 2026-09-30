<?php

namespace Bluebarry\Bluebarry\Controller\Checkout;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\ResourceModel\CheckoutNotes;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Store\Model\StoreManagerInterface;

/**
 * POST bluebarry/checkout/email: the checkout page tells the module the email the shopper gave (or,
 * signed in, their account's), for bluebarry's abandoned checkout flow. One row on the module's own
 * table; the cron sends it once the shopper stopped typing (Model\Orders\Sync). With withdraw=1, a
 * guest removed the email: it is not sent. Only for the shopper's own cart, with the form key.
 */
class Email implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var JsonFactory
     */
    private $json;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var CheckoutNotes
     */
    private $notes;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var FormKeyValidator
     */
    private $formKeys;

    /**
     * @var CookieHelper
     */
    private $cookieHelper;

    /**
     * @param RequestInterface $request
     * @param JsonFactory $json
     * @param CheckoutSession $checkoutSession
     * @param CheckoutNotes $notes
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param FormKeyValidator $formKeys
     * @param CookieHelper $cookieHelper
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $json,
        CheckoutSession $checkoutSession,
        CheckoutNotes $notes,
        Config $config,
        StoreManagerInterface $storeManager,
        FormKeyValidator $formKeys,
        CookieHelper $cookieHelper
    ) {
        $this->request = $request;
        $this->json = $json;
        $this->checkoutSession = $checkoutSession;
        $this->notes = $notes;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->formKeys = $formKeys;
        $this->cookieHelper = $cookieHelper;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $result = $this->json->create();
        $store = $this->storeManager->getStore();
        // Nothing before the shopper allowed cookies (Magento's cookie restriction mode), like all tracking.
        if ($this->config->getTenantId($store->getId()) === null || !$this->config->hasWebsiteApiKey($store->getWebsiteId())
            || $this->cookieHelper->isUserNotAllowSaveCookie()) {
            return $result->setData(['noted' => false]);
        }
        $quote = $this->checkoutSession->getQuote();
        if (!$quote->getId()) {
            return $result->setData(['noted' => false]);
        }
        if ($this->request->getParam('withdraw')) {
            // A guest removed their email, or is correcting it: the one noted is not sent. A signed-in
            // shopper's account email is theirs whatever the field says.
            $guest = !$quote->getCustomerId();
            if ($guest) {
                $this->notes->withdraw((int) $quote->getId());
            }
            return $result->setData(['noted' => false, 'withdrawn' => $guest]);
        }
        if (!$quote->getItemsCount()) {
            return $result->setData(['noted' => false]);
        }
        $email = trim((string) $this->request->getParam('email'));
        if ($email === '') {
            $email = (string) $quote->getCustomerEmail();
        }
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $result->setData(['noted' => false]);
        }
        $firstName = $quote->getCustomerFirstname() ?: ($quote->getBillingAddress() ? $quote->getBillingAddress()->getFirstname() : null);
        $this->notes->note((int) $quote->getId(), (int) $store->getId(), $email, $firstName ? (string) $firstName : null, CheckoutNotes::lines($quote));
        return $result->setData(['noted' => true]);
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return new InvalidRequestException($this->json->create()->setHttpResponseCode(403)->setData(['noted' => false]));
    }

    /**
     * @inheritdoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->formKeys->validate($request);
    }
}
