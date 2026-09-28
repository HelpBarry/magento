<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Catalog;

use Bluebarry\Bluebarry\Model\Api\Client;
use Bluebarry\Bluebarry\Model\Api\Response;
use Bluebarry\Bluebarry\Model\Catalog\ProductBuilder;
use Bluebarry\Bluebarry\Model\Catalog\Sync;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\ResourceModel\ProductSyncQueue;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\Group;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SyncTest extends TestCase
{
    /** @var array<int, string> product id => claim ('' unclaimed) */
    private array $queued = [];
    private int $catalogQueued = 0;
    private array $calls = [];
    private array $flag = [];
    private array $awaitingIndex = [];
    private array $unreadable = [];
    private bool $locked = false;
    /** @var callable|null */
    private $onPost;

    public function testSendsQueuedChangesOncePerCompany_TheDefaultWebsitesView(): void
    {
        // Websites 1 (default) and 2 share a company; 3 has its own.
        $sync = $this->sync([200, 200], tenants: [1 => 'a', 2 => 'a', 3 => 'b']);
        $this->flag = ['tenants' => ['a', 'b'], 'full_at' => time()];
        $this->queued = [5 => '', 7 => ''];

        $result = $sync->run();

        $this->assertSame(['key-1', 'key-3'], array_column($this->calls, 'key'));
        $this->assertSame([[5, 7, 'store-1'], [5, 7, 'store-3']], array_column($this->calls, 'built'));
        $this->assertSame(['sent' => 2, 'queued' => 0], $result);
        $this->assertArrayHasKey('sent_at', $this->flag);
    }

    public function testANewlyConnectedCompanyGetsTheWholeCatalog(): void
    {
        $sync = $this->sync([200], tenants: [1 => 'a']);

        $sync->run();
        $sync->run();

        $this->assertSame(1, $this->catalogQueued);
        $this->assertSame(['a'], $this->flag['tenants']);
    }

    public function testAFailedRequestKeepsTheBatchAndWaitsFiveMinutes(): void
    {
        $sync = $this->sync([503, 200]);
        $this->flag = ['tenants' => ['a'], 'full_at' => time()];
        $this->queued = [5 => ''];

        $this->assertSame(0, $sync->run()['sent']);
        $this->assertArrayHasKey(5, $this->queued);
        $this->assertGreaterThan(time() + 200, $this->flag['retry_at']);
        $this->assertSame('bluebarry answered HTTP 503.', $sync->status()['error']);

        $sync->run(); // still waiting: nothing sent
        $this->assertCount(1, $this->calls);
    }

    public function testARefusedKeyIsReported(): void
    {
        $sync = $this->sync([401]);
        $this->flag = ['tenants' => ['a'], 'full_at' => time()];
        $this->queued = [5 => ''];

        $sync->run();

        $this->assertSame('bluebarry refused the API key.', $sync->status()['error']);
    }

    public function testAKeyFromAnotherCompanyIsReported(): void
    {
        $sync = $this->sync([409]);
        $this->flag = ['tenants' => ['a'], 'full_at' => time()];
        $this->queued = [5 => ''];

        $sync->run();

        $this->assertSame('a', $this->calls[0]['body']['tenantId']);
        $this->assertSame('The API key belongs to another bluebarry company than the Tenant ID.', $sync->status()['error']);
        $this->assertArrayHasKey(5, $this->queued);
    }

    public function testAProductThatCannotBeReadStaysQueued_TheOthersGo(): void
    {
        $sync = $this->sync([200]);
        $this->flag = ['tenants' => ['a'], 'full_at' => time()];
        $this->queued = [5 => '', 6 => ''];
        $this->unreadable = [6];

        $this->assertSame(1, $sync->run()['sent']);
        $this->assertSame([6], array_keys($this->queued));
    }

    public function testAChangeWaitsForMagentosPriceIndex(): void
    {
        $sync = $this->sync([200]);
        $this->flag = ['tenants' => ['a'], 'full_at' => time()];
        $this->queued = [5 => '', 6 => ''];
        $this->awaitingIndex = [6];

        $sync->run();

        $this->assertSame([[5, 'store-1']], array_column($this->calls, 'built'));
        $this->assertSame([6], array_keys($this->queued));
    }

    public function testAChangeMadeWhileItsBatchWasSentStaysQueued(): void
    {
        $sync = $this->sync([200]);
        $this->flag = ['tenants' => ['a'], 'full_at' => time()];
        $this->queued = [5 => ''];
        $this->onPost = function () {
            $this->queued[5] = ''; // saved again: the claim is cleared
        };

        $sync->run();

        $this->assertSame([5], array_keys($this->queued));
    }

    public function testOneRunAtATime(): void
    {
        $sync = $this->sync([200]);
        $this->queued = [5 => ''];
        $this->locked = true;

        $sync->run();

        $this->assertSame([], $this->calls);
    }

    private function sync(array $statuses, array $tenants = [1 => 'a']): Sync
    {
        $websites = [];
        foreach (array_keys($tenants) as $id) {
            $website = $this->createStub(Website::class);
            $website->method('getId')->willReturn($id);
            $website->method('getDefaultGroupId')->willReturn($id);
            $websites[$id] = $website;
        }
        $config = $this->createStub(Config::class);
        $config->method('getWebsiteTenantId')->willReturnCallback(fn ($id) => $tenants[(int) $id] ?? null);
        $config->method('getWebsiteApiKey')->willReturnCallback(fn ($id) => isset($tenants[(int) $id]) ? "key-$id" : null);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn(array_reverse($websites, true));
        $default = $this->createStub(Store::class);
        $default->method('getWebsiteId')->willReturn(1);
        $storeManager->method('getDefaultStoreView')->willReturn($default);
        $storeManager->method('getGroup')->willReturnCallback(function ($id) {
            $group = $this->createStub(Group::class);
            $group->method('getDefaultStoreId')->willReturn((int) $id);
            return $group;
        });
        $storeManager->method('getStore')->willReturnCallback(function ($id) {
            $store = $this->createStub(Store::class);
            $store->method('getCode')->willReturn("store-$id");
            return $store;
        });

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturnCallback(function ($path, $body, $tenant, $key) use (&$statuses) {
            $this->calls[count($this->calls) - 1] += ['path' => $path, 'key' => $key, 'body' => $body];
            if ($this->onPost) {
                ($this->onPost)();
            }
            return new Response((int) array_shift($statuses), '{}');
        });

        $builder = $this->createStub(ProductBuilder::class);
        $builder->method('awaitingPriceIndex')->willReturnCallback(fn ($queued) => array_values(array_intersect(array_keys($queued), $this->awaitingIndex)));
        $builder->method('build')->willReturnCallback(function ($ids, $store) {
            $this->calls[] = ['built' => array_merge($ids, [$store->getCode()])];
            return [
                'products' => array_map(fn ($id) => ['reference' => (string) $id], array_values(array_diff($ids, $this->unreadable))),
                'reconcileGroupIds' => [],
                'failed' => array_values(array_intersect($ids, $this->unreadable)),
            ];
        });

        $queue = $this->createStub(ProductSyncQueue::class);
        $queue->method('enqueueAll')->willReturnCallback(function () {
            $this->catalogQueued++;
        });
        $queue->method('next')->willReturnCallback(function ($limit, $after) {
            ksort($this->queued);
            $next = [];
            foreach (array_keys($this->queued) as $id) {
                if ($id > $after && count($next) < $limit) {
                    $next[$id] = gmdate('Y-m-d H:i:s');
                }
            }
            return $next;
        });
        $queue->method('claim')->willReturnCallback(function ($ids, $claim) {
            foreach ($ids as $id) {
                $this->queued[$id] = $claim;
            }
        });
        $queue->method('remove')->willReturnCallback(function ($ids, $claim) {
            foreach ($ids as $id) {
                if (($this->queued[$id] ?? null) === $claim) {
                    unset($this->queued[$id]);
                }
            }
        });
        $queue->method('fail')->willReturn([]);
        $queue->method('count')->willReturnCallback(fn () => count($this->queued));

        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(fn () => $this->flag);
        $flags->method('saveFlag')->willReturnCallback(function ($code, $data) {
            $this->flag = $data;
            return true;
        });
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturnCallback(fn () => !$this->locked);
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime('2026-09-29 14:00:00'));

        return new Sync($config, $client, $storeManager, $queue, $builder, $flags, $locks, $timezone, new NullLogger());
    }
}
