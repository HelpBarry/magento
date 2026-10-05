<?php

namespace Bluebarry\Bluebarry\Controller\Loyalty;

use Bluebarry\Bluebarry\Model\Api\Client;
use Bluebarry\Bluebarry\Model\Config;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * GET bluebarry/loyalty/session: bluebarry's rewards panel asks whether the shopper is signed in to
 * the store. The module vouches for them to bluebarry, server to server with the website's API key,
 * so they see their rewards without an emailed code.
 *
 *   ?check=1   only says which customer is signed in ({"customerId": "12"} or null): the panel ends
 *              its session when the store's customer signed out or changed
 *   (else)     opens the session: {"token": ..., "expiresAt": ..., "customerId": "12"}
 *
 * Opening a session can enrol the customer and credit a referral, so only the panel on the store's own
 * pages may ask: its X-Bluebarry-Loyalty header can't come from a link or form on another site, and a
 * script there can't send one here without a CORS approval it never gets. Only the panel calls this,
 * and only when it is opened: no page waits for it.
 */
class Session implements HttpGetActionInterface
{
    /**
     * @var HttpRequest
     */
    private $request;

    /**
     * @var JsonFactory
     */
    private $json;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Client
     */
    private $client;

    /**
     * @param HttpRequest $request
     * @param JsonFactory $json
     * @param CustomerSession $customerSession
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param Client $client
     */
    public function __construct(
        HttpRequest $request,
        JsonFactory $json,
        CustomerSession $customerSession,
        Config $config,
        StoreManagerInterface $storeManager,
        Client $client
    ) {
        $this->request = $request;
        $this->json = $json;
        $this->customerSession = $customerSession;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->client = $client;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $result = $this->json->create();
        // About one shopper: never kept by a cache in between.
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $none = ['customerId' => null];

        $websiteId = $this->storeManager->getStore()->getWebsiteId();
        $tenantId = $this->config->getWebsiteTenantId($websiteId);
        $apiKey = $this->config->getWebsiteApiKey($websiteId);
        $email = $this->customerSession->isLoggedIn() ? trim((string) $this->customerSession->getCustomer()->getEmail()) : '';
        if ($tenantId === null || $apiKey === null || $email === '') {
            return $result->setData($none);
        }
        $customerId = (string) $this->customerSession->getCustomerId();
        if ($this->request->getParam('check') !== null) {
            return $result->setData(['customerId' => $customerId]);
        }
        if ((string) $this->request->getHeader('X-Bluebarry-Loyalty') === '') {
            return $result->setHttpResponseCode(403)->setData($none);
        }
        $referral = $this->request->getParam('ref');
        // Nothing of the session is needed from here on: it is let go before bluebarry is asked, so the
        // shopper's next request never waits behind this one.
        $this->customerSession->writeClose();
        $response = $this->client->post('/data/magento/loyalty/customer-session', [
            'email' => $email,
            'referralCode' => is_string($referral) && $referral !== '' ? substr($referral, 0, 64) : null,
        ], $tenantId, $apiKey, 10);
        $body = $response->isSuccess() ? json_decode($response->getBody(), true) : null;
        if (!is_array($body) || empty($body['token']) || !is_string($body['token'])) {
            return $result->setData($none);
        }
        return $result->setData(['token' => $body['token'], 'expiresAt' => $body['expiresAt'] ?? null, 'customerId' => $customerId]);
    }
}
