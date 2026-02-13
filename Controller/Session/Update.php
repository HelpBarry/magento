<?php

namespace Bluebarry\Bluebarry\Controller\Session;

use Magento\Framework\App\Action\Action; // Frontend Action base
use Magento\Framework\App\Action\Context; // Matching frontend Context

use Magento\Framework\Controller\Result\JsonFactory;

use Bluebarry\Bluebarry\Model\Session;

class Update extends Action
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
    
        $post = $this->getRequest()->getPostValue();

        $this->session->setSession([
            'bluebarry' => [
                'session_id' => $post['session_id'] ?? null,
                'advisor_id' => $post['advisor_id'] ?? null,
                'user_id'    => $post['user_id'] ?? null,
            ],
        ]);

        $result = $this->jsonFactory->create();
        
        return $result->setData([
            'success' => true, 
            'session' => $this->session->getSession(),
            'message' => __('Bluebarry session updated.')
        ]);
    }
}