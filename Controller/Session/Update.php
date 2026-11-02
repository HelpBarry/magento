<?php

namespace Bluebarry\Bluebarry\Controller\Session;

use Magento\Framework\App\Action\Action; // Frontend Action base
use Magento\Framework\App\Action\Context; // Matching frontend Context
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

use Magento\Framework\Controller\Result\JsonFactory;

use Bluebarry\Bluebarry\Model\Session;

class Update extends Action implements CsrfAwareActionInterface
{
    /**
     * @var \Bluebarry\Bluebarry\Model\Session
     */
    protected Session $session;

    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    protected JsonFactory $jsonFactory;

    public function __construct(
        Context $context,
        Session $session,
        JsonFactory $jsonFactory
    ) {
        $this->session = $session;
        $this->jsonFactory = $jsonFactory;
        parent::__construct($context); // Frontend Action expects only Context
    }

    public function execute() {
        $result = $this->jsonFactory->create();

        if (!$this->getRequest()->isPost()) {
            return $result->setData([
                'success' => true,
                'session' => $this->session->getSession(),
            ]);
        }

        $post = $this->getRequest()->getPostValue();

        $this->session->setSession([
            'bluebarry' => [
                'session_id' => $post['bb_session_id'] ?? null,
                'advisor_id' => $post['advisor_id'] ?? null,
                'user_id'    => $post['user_id'] ?? null,
            ],
        ]);

        return $result->setData([
            'success' => true, 
            'session' => $this->session->getSession(),
            'message' => __('Bluebarry session updated.')
        ]);
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        // This request runs in the background: no redirect, and no "Invalid Form Key" on the shopper's next page.
        return new InvalidRequestException(
            $this->jsonFactory->create()->setHttpResponseCode(403)->setData(['success' => false])
        );
    }

    /**
     * @inheritdoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return null; // Magento's own check: a POST needs the form key, a GET does not.
    }
}