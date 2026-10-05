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
 * POST bluebarry/cart/discount: a bluebarry discount for the shopper's cart, from the SDK's Magento
 * cart bridge. One of:
 *
 *   code=CODE    a coupon code: a quiz's, a popup's, a reward's
 *   grants=[...] signed offers of quiz results and recommendation blocks (a JSON array), which the SDK
 *                hands over once the cart has something
 *   (neither)    the cart changed: offers waiting for their products get another look
 *
 * Answers {"applied": bool, "waiting": bool, "offers": n, "reason"?: "other_code"|"unknown"}: applied
 * when the cart has the discount now, waiting when it goes on once the cart can take it, offers how
 * many offers still wait. Needs the form key, like the cart page's own coupon field. Never part of a
 * page: the SDK asks after the page, and after an add to the cart, are done.
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
            return $result->setData(['applied' => false, 'waiting' => false, 'offers' => 0, 'reason' => 'unknown']);
        }
        $code = $this->request->getParam('code');
        $grants = $this->request->getParam('grants');
        $grants = is_string($grants) ? json_decode($grants, true) : null;
        try {
            if (is_array($grants) && $grants) {
                return $result->setData($this->cart->keepOffers(array_values($grants)));
            }
            if (is_string($code) && $code !== '') {
                return $result->setData($this->cart->applyCode($code) + ['offers' => $this->cart->waitingOffers()]);
            }
            return $result->setData($this->cart->redeemOffers());
        } catch (\Exception $e) {
            $this->logger->warning('bluebarry: could not put a discount on the cart: ' . $e->getMessage());
            return $result->setData(['applied' => false, 'waiting' => false, 'offers' => 0, 'reason' => 'error']);
        }
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        // A JSON answer the bridge can read, instead of a redirect.
        $result = $this->json->create()->setHttpResponseCode(403)
            ->setData(['applied' => false, 'waiting' => false, 'offers' => 0, 'reason' => 'form_key']);
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
