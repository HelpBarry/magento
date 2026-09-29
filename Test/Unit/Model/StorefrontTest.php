<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model;

use Bluebarry\Bluebarry\Model\Api\Client;
use Bluebarry\Bluebarry\Model\Api\Response;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Storefront;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\FlagManager;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;

class StorefrontTest extends TestCase
{
    private const TENANT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const PROFILE = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private array $flag = [];
    private array $cache = [];
    private int $purges = 0;
    private array $answers = [];

    public function testReadsSearchForTheWebsite_AndPurgesThePagesOnceWhenItChanges(): void
    {
        $storefront = $this->storefront();
        $this->answers = [$this->answer(['profileId' => strtoupper(self::PROFILE), 'resultsPage' => true]), $this->answer(['profileId' => self::PROFILE, 'resultsPage' => true])];

        $this->assertSame('v1', $storefront->refresh($this->website(1)));
        $this->assertSame(['profileId' => self::PROFILE, 'resultsPage' => true], $storefront->search(1, self::TENANT));
        $this->assertSame(1, $this->purges);

        $storefront->refresh($this->website(1)); // unchanged: the page cache stays
        $this->assertSame(1, $this->purges);
    }

    public function testOnlyTheTenantItWasReadForGetsIt(): void
    {
        $storefront = $this->storefront();
        $this->answers = [$this->answer(['profileId' => self::PROFILE, 'resultsPage' => false])];
        $storefront->refresh($this->website(1));

        // A store view that reports to another company.
        $this->assertNull($storefront->search(1, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'));
    }

    public function testABadAnswerKeepsWhatTheStoreHad(): void
    {
        $storefront = $this->storefront();
        $this->answers = [$this->answer(['profileId' => self::PROFILE, 'resultsPage' => false]), new Response(503, ''), $this->answer(null, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd')];
        $storefront->refresh($this->website(1));

        $this->assertNull($storefront->refresh($this->website(1)));
        $this->assertNull($storefront->refresh($this->website(1))); // another company's settings
        $this->assertSame(self::PROFILE, $storefront->search(1, self::TENANT)['profileId']);
    }

    public function testSearchOffAndADisconnectedWebsiteAreForgotten(): void
    {
        $storefront = $this->storefront();
        $this->answers = [$this->answer(['profileId' => self::PROFILE, 'resultsPage' => false]), $this->answer(null)];
        $storefront->refresh($this->website(1));
        $storefront->refresh($this->website(1));
        $this->assertNull($storefront->search(1, self::TENANT));

        $this->storefront(connected: false)->refresh($this->website(1));
        $this->assertSame([], $this->flag);
    }

    private function answer(?array $search, string $tenant = self::TENANT): Response
    {
        return new Response(200, (string) json_encode(['tenantId' => $tenant, 'search' => $search, 'version' => $search ? 'v1' : 'v0']));
    }

    private function website(int $id): Website
    {
        $website = $this->createStub(Website::class);
        $website->method('getId')->willReturn($id);
        return $website;
    }

    private function storefront(bool $connected = true): Storefront
    {
        $config = $this->createStub(Config::class);
        $config->method('getWebsiteTenantId')->willReturn($connected ? self::TENANT : null);
        $config->method('getWebsiteApiKey')->willReturn($connected ? 'key' : null);
        $client = $this->createStub(Client::class);
        $client->method('get')->willReturnCallback(fn () => array_shift($this->answers));
        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(fn () => $this->flag);
        $flags->method('saveFlag')->willReturnCallback(function ($code, $data) {
            $this->flag = $data;
            return true;
        });
        $events = $this->createStub(ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function ($name, $data) {
            if ($name === 'clean_cache_by_tags' && $data['object']->getIdentities() === [Storefront::CACHE_TAG]) {
                $this->purges++;
            }
        });
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn ($id) => $this->cache[$id] ?? false);
        $cache->method('save')->willReturnCallback(function ($data, $id) {
            $this->cache[$id] = $data;
            return true;
        });
        $cache->method('remove')->willReturnCallback(function ($id) {
            unset($this->cache[$id]);
            return true;
        });
        return new Storefront($config, $client, $this->createStub(StoreManagerInterface::class), $flags, $events, $cache);
    }
}
