<?php

namespace Bluebarry\Bluebarry\Controller\Cart;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Discount\Cart;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST bluebarry/cart/discount: bluebarry discounts for the shopper's cart, from the SDK's Magento
 * cart bridge. What the cart cannot take yet waits in the browser: the SDK sends it again when the
 * cart changed, so the module keeps nothing between requests.
 *
 *   code=CODE    a coupon code: a quiz's, a popup's, a reward's
 *   grants=[...] the signed offers of quiz results and recommendation blocks the browser holds (a JSON
 *                array, the newest last)
 *
 * Answers {"applied": bool, "code"?: "applied"|"waiting"|"other_code"|"unknown", "drop": [grants]}:
 * applied when the cart got a discount just now; code what became of the code (waiting: the cart
 * cannot take it yet, other_code: the shopper's own code stays, unknown: no such code here); drop the
 * offers the browser can forget, the others wait on. 500 when it could not be done right now, so the
 * SDK keeps what it holds and asks again. Needs the form key, like the cart page's own coupon field.
 * Never part of a page: the SDK asks after the page, and after an add to the cart, are done.
 */
class Discount implements HttpPostActionInterface, CsrfAwareActionInterface
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
     * @var Cart
     */
    private $cart;

    /**
     * @var FormKeyValidator
     */
    private $formKeys;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param RequestInterface $request
     * @param JsonFactory $json
     * @param Cart $cart
     * @param FormKeyValidator $formKeys
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $json,
        Cart $cart,
        FormKeyValidator $formKeys,
        Config $config,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->json = $json;
        $this->cart = $cart;
        $this->formKeys = $formKeys;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $result = $this->json->create();
        // The shopper's own cart: never kept by a cache in between.
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        if ($this->config->getTenantId($this->storeManager->getStore()->getId()) === null) {
            return $result->setData(['applied' => false, 'drop' => []]);
        }
        $code = $this->request->getParam('code');
        $grants = $this->request->getParam('grants');
        $grants = is_string($grants) ? json_decode($grants, true) : null;
        try {
            $answer = ['applied' => false, 'drop' => []];
            if (is_string($code) && $code !== '') {
                $answer['code'] = $this->cart->applyCode($code);
                $answer['applied'] = $answer['code'] === 'applied';
            }
            if (is_array($grants) && $grants) {
                $offers = $this->cart->redeemOffers($grants);
                $answer['drop'] = $offers['drop'];
                $answer['applied'] = $answer['applied'] || $offers['applied'];
                if (isset($offers['offer'])) {
                    // The offer whose code is on the cart: the SDK does not ask about it again while it is.
                    $answer += ['offer' => $offers['offer'], 'coupon' => $offers['coupon'] ?? ''];
                }
            }
            return $result->setData($answer);
        } catch (\Exception $e) {
            $this->logger->warning('bluebarry: could not put a discount on the cart: ' . $e->getMessage());
            // Not an answer the SDK may act on: it keeps what it holds and asks again.
            return $result->setHttpResponseCode(500)->setData(['applied' => false, 'drop' => [], 'reason' => 'error']);
        }
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        // A JSON answer the bridge can read, instead of a redirect.
        $result = $this->json->create()->setHttpResponseCode(403)
            ->setData(['applied' => false, 'drop' => [], 'reason' => 'form_key']);
        return new InvalidRequestException($result);
    }

    /**
     * @inheritdoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->formKeys->validate($request);
    }
}
