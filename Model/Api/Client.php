<?php

namespace Bluebarry\Bluebarry\Model\Api;

use Bluebarry\Bluebarry\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Calls bluebarry's API from the store's server. Only ever used from background work (queue
 * consumers, cron) or admin actions: no shopper request waits on bluebarry.
 */
class Client
{
    /**
     * @var CurlFactory
     */
    private $curlFactory;

    /**
     * @var Config
     */
    private $config;

    /**
     * @param CurlFactory $curlFactory
     * @param Config $config
     */
    public function __construct(CurlFactory $curlFactory, Config $config)
    {
        $this->curlFactory = $curlFactory;
        $this->config = $config;
    }

    /**
     * GETs from bluebarry with a website's API key.
     *
     * @param string $path e.g. /data/magento/storefront
     * @param string $apiKey
     * @param int $timeout seconds
     * @return Response status 0 when no answer came
     */
    public function get(string $path, string $apiKey, int $timeout = 15): Response
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout($timeout);
        $curl->addHeader('Authorization', $apiKey);
        try {
            $curl->get($this->config->getApiUrl() . $path);
            return new Response((int) $curl->getStatus(), (string) $curl->getBody());
        } catch (\Exception $e) {
            return new Response(0, '', $e->getMessage());
        }
    }

    /**
     * POSTs JSON to bluebarry.
     *
     * @param string $path e.g. /data/conversionevents
     * @param array $body
     * @param string $tenantId for public (storefront-level) calls
     * @param string|null $apiKey authenticates as the store's integration instead, when given
     * @param int $timeout seconds
     * @return Response status 0 when no answer came
     */
    public function post(string $path, array $body, string $tenantId, ?string $apiKey = null, int $timeout = 15): Response
    {
        // A client per request: Magento's Curl keeps headers and options between calls.
        $curl = $this->curlFactory->create();
        $curl->setTimeout($timeout);
        $curl->addHeader('Content-Type', 'application/json');
        // One or the other: bluebarry authenticates a request that names a tenant as that tenant's
        // public storefront and ignores any key on it.
        if ($apiKey !== null && $apiKey !== '') {
            $curl->addHeader('Authorization', $apiKey);
        } else {
            $curl->addHeader('BB-Tenant-Id', $tenantId);
        }

        try {
            $curl->post($this->config->getApiUrl() . $path, (string) json_encode($body));
            return new Response((int) $curl->getStatus(), (string) $curl->getBody());
        } catch (\Exception $e) {
            return new Response(0, '', $e->getMessage());
        }
    }
}
