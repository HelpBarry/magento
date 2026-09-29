<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model;

use Bluebarry\Bluebarry\Model\Api\Client;
use Bluebarry\Bluebarry\Model\Api\Response;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Heartbeat;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Store\Model\Group;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;

class HeartbeatTest extends TestCase
{
    private const TENANT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private array $calls = [];
    private array $flag = [];
    /** @var callable|null */
    private $onSave;

    public function testPingsEachConnectedWebsiteWithItsKey(): void
    {
        $heartbeat = $this->heartbeat([200], connected: [1 => true, 2 => false]);

        $heartbeat->sendDue();

        $this->assertCount(1, $this->calls);
        [$path, $body, $tenant, $key] = $this->calls[0];
        $this->assertSame('/data/magento/ping', $path);
        $this->assertSame('key-1', $key);
        $this->assertSame(self::TENANT, $tenant);
        $this->assertSame([
            'tenantId' => self::TENANT,
            'siteUrl' => 'https://shop1.example',
            'siteName' => 'Website 1',
            'moduleVersion' => '1.1.0',
            'magentoVersion' => '2.4.8-p5',
            'magentoEdition' => 'Community',
            'commandUrl' => 'https://shop1.example/bluebarry/command/',
        ], $body);
        $this->assertTrue($heartbeat->outcomes()[1]['ok']);
    }

    public function testPingsDailyUnlessForced(): void
    {
        $heartbeat = $this->heartbeat([200, 200, 200]);

        $heartbeat->sendDue();
        $heartbeat->sendDue(); // within the day: nothing
        $this->assertCount(1, $this->calls);

        $heartbeat->forceNext(); // after a module upgrade
        $heartbeat->sendDue();
        $this->assertCount(2, $this->calls);
        $this->assertArrayNotHasKey('force', $this->flag);
    }

    public function testAKeyFromAnotherCompanyIsNotAConnection(): void
    {
        $heartbeat = $this->heartbeat([409]); // bluebarry refuses it before registering anything

        $outcome = $heartbeat->send($this->website(1));

        $this->assertFalse($outcome['ok']);
        $this->assertStringContainsString('another bluebarry company', $outcome['error']);
    }

    public function testARefusedKeyIsReported(): void
    {
        $outcome = $this->heartbeat([401])->send($this->website(1));

        $this->assertFalse($outcome['ok']);
        $this->assertSame('bluebarry refused the API key.', $outcome['error']);
    }

    public function testAWebsiteWithoutKeyIsNotPingedAndForgotten(): void
    {
        $heartbeat = $this->heartbeat([200], connected: [1 => false]);
        $this->flag = ['websites' => [1 => ['at' => 1, 'ok' => true]]];

        $this->assertNull($heartbeat->send($this->website(1)));
        $this->assertSame([], $this->calls);
        $this->assertSame([], $heartbeat->outcomes());
    }

    public function testAnUnconnectedWebsiteCostsNoWrite(): void
    {
        $heartbeat = $this->heartbeat([], connected: [1 => false]);
        $saves = 0;
        $this->onSave = function () use (&$saves) {
            $saves++;
        };

        $heartbeat->sendDue();
        $heartbeat->sendDue();

        $this->assertSame(0, $saves);
    }

    public function testOneBrokenWebsiteDoesNotStopTheOthers(): void
    {
        $heartbeat = $this->heartbeat([200], connected: [1 => true, 2 => true], brokenWebsite: 1);

        $heartbeat->sendDue();

        $this->assertSame(['key-2'], array_column($this->calls, 3));
        $this->assertFalse($heartbeat->outcomes()[1]['ok']);
        $this->assertTrue($heartbeat->outcomes()[2]['ok']);
    }

    public function testUninstallStillDeactivatesTheOthersWhenOneWebsiteIsBroken(): void
    {
        $this->heartbeat([200], connected: [1 => true, 2 => true], brokenWebsite: 1)->deactivateAll();

        $this->assertSame(['key-2'], array_column($this->calls, 3));
    }

    public function testUninstallDeactivatesEveryConnectedWebsite(): void
    {
        $this->heartbeat([200, 200], connected: [1 => true, 2 => true])->deactivateAll();

        $this->assertSame(['/data/magento/deactivate', '/data/magento/deactivate'], array_column($this->calls, 0));
        $this->assertSame(['siteUrl' => 'https://shop2.example'], $this->calls[1][1]);
    }

    private function website(int $id): Website
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn("https://shop$id.example/");
        $group = $this->createStub(Group::class);
        $group->method('getDefaultStore')->willReturn($store);
        $website = $this->createStub(Website::class);
        $website->method('getId')->willReturn($id);
        $website->method('getName')->willReturn("Website $id");
        $website->method('getDefaultGroupId')->willReturn($id);
        $website->method('getDefaultStore')->willReturn($store);
        return $website;
    }

    private function heartbeat(array $statuses, array $connected = [1 => true], ?int $brokenWebsite = null): Heartbeat
    {
        $websites = [];
        foreach (array_keys($connected) as $id) {
            $websites[$id] = $this->website($id);
        }
        $config = $this->createStub(Config::class);
        $config->method('getWebsiteTenantId')->willReturnCallback(fn ($id) => ($connected[(int) $id] ?? false) ? self::TENANT : null);
        $config->method('getWebsiteApiKey')->willReturnCallback(function ($id) use ($connected, $brokenWebsite) {
            if ((int) $id === $brokenWebsite) {
                throw new \Exception('Unable to decrypt the key.');
            }
            return ($connected[(int) $id] ?? false) ? "key-$id" : null;
        });

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturnCallback(function ($path, $body, $tenant, $key) use (&$statuses) {
            $this->calls[] = [$path, $body, $tenant, $key];
            return new Response((int) array_shift($statuses), (string) json_encode(['success' => true, 'tenantId' => self::TENANT]));
        });

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn($websites);
        $storeManager->method('getGroup')->willReturnCallback(fn ($id) => $this->groupFor((int) $id));
        $storeManager->method('getStore')->willReturnCallback(fn ($id) => $websites[(int) $id]->getDefaultStore());

        $metadata = $this->createStub(ProductMetadataInterface::class);
        $metadata->method('getVersion')->willReturn('2.4.8-p5');
        $metadata->method('getEdition')->willReturn('Community');
        $modules = $this->createStub(ModuleListInterface::class);
        $modules->method('getOne')->willReturn(['setup_version' => '1.1.0']);

        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(fn () => $this->flag);
        $flags->method('saveFlag')->willReturnCallback(function ($code, $data) {
            if ($this->onSave) {
                ($this->onSave)();
            }
            $this->flag = $data;
            return true;
        });

        return new Heartbeat($config, $client, $storeManager, $metadata, $modules, $flags);
    }

    private function groupFor(int $websiteId): Group
    {
        $group = $this->createStub(Group::class);
        $group->method('getDefaultStoreId')->willReturn($websiteId); // one store view per website here
        return $group;
    }
}
