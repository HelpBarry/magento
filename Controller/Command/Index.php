<?php

namespace Bluebarry\Bluebarry\Controller\Command;

use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Orders\Sync as OrderSync;
use Bluebarry\Bluebarry\Model\Storefront;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * POST bluebarry/command: bluebarry asking this website to do something now, such as reload the
 * settings the merchant just changed in Studio. The heartbeat tells bluebarry where this is.
 *
 * Signed with the website's own API key, which only this website and bluebarry know: the
 * X-Bluebarry-Signature header is HMAC-SHA256 of "{X-Bluebarry-Timestamp}.{body}", and a request more
 * than five minutes off is refused. Nothing else is accepted, so the form key is not needed.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** How old a signed request may be, either way, before it is refused. */
    private const MAX_SKEW = 300;

    /**
     * @var HttpRequest
     */
    private $request;

    /**
     * @var JsonFactory
     */
    private $json;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Storefront
     */
    private $storefront;

    /**
     * @var ModuleListInterface
     */
    private $modules;

    /**
     * @var OrderSync
     */
    private $orders;

    /**
     * @param HttpRequest $request
     * @param JsonFactory $json
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param Storefront $storefront
     * @param ModuleListInterface $modules
     * @param OrderSync $orders
     */
    public function __construct(
        HttpRequest $request,
        JsonFactory $json,
        Config $config,
        StoreManagerInterface $storeManager,
        Storefront $storefront,
        ModuleListInterface $modules,
        OrderSync $orders
    ) {
        $this->request = $request;
        $this->json = $json;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->storefront = $storefront;
        $this->modules = $modules;
        $this->orders = $orders;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $result = $this->json->create();
        $website = $this->storeManager->getWebsite($this->storeManager->getStore()->getWebsiteId());
        $key = $this->config->getWebsiteApiKey($website->getId());
        $body = (string) $this->request->getContent();
        if ($key === null || !self::isSigned($key, (string) $this->request->getHeader('X-Bluebarry-Timestamp'),
            (string) $this->request->getHeader('X-Bluebarry-Signature'), $body, time())) {
            return $result->setHttpResponseCode(401)->setData(['ok' => false, 'error' => 'Not signed by bluebarry.']);
        }

        $decoded = json_decode($body, true);
        $command = $decoded['command'] ?? null;
        switch ($command) {
            case 'ping':
                return $result->setData(['ok' => true, 'version' => (string) ($this->modules->getOne('Bluebarry_Bluebarry')['setup_version'] ?? '')]);
            case 'settings.refresh':
                $version = $this->storefront->refresh($website);
                return $result->setHttpResponseCode($version === null ? 502 : 200)->setData(['ok' => $version !== null, 'version' => $version]);
            case 'orders.import':
                // Studio's Orders page: the history import, run by the cron in batches.
                $since = strtotime((string) ($decoded['payload']['since'] ?? ''));
                return $result->setData(['started' => $this->orders->startImport((int) $website->getId(), $since ?: time() - 365 * 86400)]);
            default:
                return $result->setHttpResponseCode(404)->setData(['ok' => false, 'error' => 'Unknown command.']);
        }
    }

    /**
     * @param string $key the website's API key
     * @param string $timestamp unix seconds
     * @param string $signature lowercase hex HMAC-SHA256 of "{timestamp}.{body}"
     * @param string $body
     * @param int $now
     * @return bool
     */
    public static function isSigned(string $key, string $timestamp, string $signature, string $body, int $now): bool
    {
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp) || abs($now - (int) $timestamp) > self::MAX_SKEW) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $key), strtolower($signature));
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true; // the signature is checked instead
    }
}
