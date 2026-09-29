<?php

namespace Bluebarry\Bluebarry\Controller\Cart;

use Bluebarry\Bluebarry\Model\Cart\Adder;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;

/**
 * POST bluebarry/cart/add: the storefront's add to cart for bluebarry's quiz, search and
 * recommendation blocks (the SDK's Magento cart bridge). `items` is JSON:
 * [{"reference": "<product id>", "quantity": 1}, ...]. Answers
 * {"success": bool, "skipped": [references], "failure"?: "soldOut"|"error"}.
 *
 * Needs the form key, like the product page's own add to cart, also on AJAX requests (which
 * Magento's request validation would let through without one).
 */
class Add implements HttpPostActionInterface, CsrfAwareActionInterface
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
     * @var Adder
     */
    private $adder;

    /**
     * @var FormKeyValidator
     */
    private $formKeys;

    /**
     * @param RequestInterface $request
     * @param JsonFactory $json
     * @param Adder $adder
     * @param FormKeyValidator $formKeys
     */
    public function __construct(RequestInterface $request, JsonFactory $json, Adder $adder, FormKeyValidator $formKeys)
    {
        $this->formKeys = $formKeys;
        $this->request = $request;
        $this->json = $json;
        $this->adder = $adder;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $items = json_decode((string) $this->request->getParam('items'), true);
        if (!is_array($items) || !$items) {
            return $this->json->create()->setHttpResponseCode(400)
                ->setData(['success' => false, 'skipped' => [], 'failure' => 'error']);
        }
        return $this->json->create()->setData($this->adder->add(array_values(array_filter($items, 'is_array'))));
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        // A JSON answer the bridge can read, instead of a redirect.
        $result = $this->json->create()->setHttpResponseCode(403)
            ->setData(['success' => false, 'skipped' => [], 'failure' => 'error', 'message' => 'Invalid form key.']);
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
