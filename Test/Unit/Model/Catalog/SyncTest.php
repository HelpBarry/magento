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
    /** @var array<int, int[]> queued configurable product => the children build() adds */
    private array $expanded = [];
    private bool $locked = false;
    private string $now = '2026-09-29 14:00:00 UTC';
    /** @var callable|null */
    private $onPost;

    public function testSendsQueuedChangesOncePerCompany_TheDefaultWebsitesView(): void
    {
        // Websites 1 (default) and 2 share a company; 3 has its own.
        $sync = $this->sync([200, 200], tenants: [1 => 'a', 2 => 'a', 3 => 'b']);
        $this->flag = ['tenants' => ['a' => 1, 'b' => 3], 'full_at' => time()];
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
        $this->assertSame(['a' => 1], $this->flag['tenants']);
    }

    public function testAFailedRequestKeepsTheBatchAndWaitsFiveMinutes(): void
    {
        $sync = $this->sync([503, 200]);
        $this->flag = ['tenants' => ['a' => 1], 'full_at' => time()];
        $this->queued = [5 => ''];

        $this->assertSame(0, $sync->run()['sent']);
        $this->assertArrayHasKey(5, $this->queued);
        $this->assertGreaterThan(time() + 200, $this->flag['targets']['a']['retry_at']);
        $this->assertSame('bluebarry answered HTTP 503.', $sync->status()['error']);

        $sync->run(); // still waiting: nothing sent
        $this->assertCount(1, $this->calls);
    }

    public function testARefusedKeyIsReported(): void
    {
        $sync = $this->sync([401]);
        $this->flag = ['tenants' => ['a' => 1], 'full_at' => time()];
        $this->queued = [5 => ''];

        $sync->run();

        $this->assertSame('bluebarry refused the API key.', $sync->status()['error']);
    }

    public function testAKeyFromAnotherCompanyIsReported(): void
    {
        $sync = $this->sync([409]);
        $this->flag = ['tenants' => ['a' => 1], 'full_at' => time()];
        $this->queued = [5 => ''];

        $sync->run();

        $this->assertSame('a', $this->calls[0]['body']['tenantId']);
        $this->assertSame('The API key belongs to another bluebarry company than the Tenant ID.', $sync->status()['error']);
        $this->assertArrayHasKey(5, $this->queued);
    }

    public function testAProductThatCannotBeReadStaysQueued_TheOthersGo(): void
    {
        $sync = $this->sync([200]);
        $this->flag = ['tenants' => ['a' => 1], 'full_at' => time()];
        $this->queued = [5 => '', 6 => ''];
        $this->unreadable = [6];

        $this->assertSame(1, $sync->run()['sent']);
        $this->assertSame([6], array_keys($this->queued));
    }

    public function testAChangeWaitsForMagentosPriceIndex(): void
    {
        $sync = $this->sync([200]);
        $this->flag = ['tenants' => ['a' => 1], 'full_at' => time()];
        $this->queued = [5 => '', 6 => ''];
        $this->awaitingIndex = [6];

        $sync->run();

        $this->assertSame([[5, 'store-1']], array_column($this->calls, 'built'));
        $this->assertSame([6], array_keys($this->queued));
    }

    public function testAChangeMadeWhileItsBatchWasSentStaysQueued(): void
    {
        $sync = $this->sync([200]);
        $this->flag = ['tenants' => ['a' => 1], 'full_at' => time()];
        $this->queued = [5 => ''];
        $this->onPost = function () {
            $this->queued[5] = ''; // saved again: the claim is cleared
        };

        $sync->run();

        $this->assertSame([5], array_keys($this->queued));
    }

    public function testOneCompanysFailureDoesNotHoldUpAnother_ItGetsTheWholeCatalogOnceItWorks(): void
    {
        $sync = $this->sync([503, 200, 200, 200], tenants: [1 => 'a', 3 => 'b']);
        $this->flag = ['tenants' => ['a' => 1, 'b' => 3], 'full_at' => time()];
        $this->queued = [5 => ''];

        $this->assertSame(1, $sync->run()['sent']); // b received it; a waits
        $this->assertSame([], array_keys($this->queued));
        $this->assertTrue($this->flag['targets']['a']['stale']);
        $this->assertSame('bluebarry answered HTTP 503.', $sync->status()['error']);

        // Five minutes later a works again: it missed changes, so the whole catalog goes out again.
        $this->flag['targets']['a']['retry_at'] = time() - 1;
        $this->queued = [6 => ''];
        $sync->run();
        $this->assertSame(1, $this->catalogQueued);
        $this->assertArrayNotHasKey('targets', array_filter($this->flag));
        $this->assertNull($sync->status()['error']);
    }

    public function testACompanyThatMissedChangesGetsTheCatalogWhenItIsRetried_EvenWithNothingNewQueued(): void
    {
        $sync = $this->sync([200], tenants: [1 => 'a']);
        $this->flag = ['tenants' => ['a' => 1], 'full_at' => time(), 'targets' => ['a' => ['retry_at' => time() - 1, 'error' => 'x', 'stale' => true]]];

        $sync->run();

        $this->assertSame(1, $this->catalogQueued);
        $this->assertFalse($this->flag['targets']['a']['stale'] ?? false);
    }

    public function testACompanyWaitingOutAFailureMissesWhatAnotherReceives_SoItGetsTheCatalogLater(): void
    {
        $sync = $this->sync([200], tenants: [1 => 'a', 3 => 'b']);
        $this->flag = ['tenants' => ['a' => 1, 'b' => 3], 'full_at' => time(), 'targets' => ['a' => ['retry_at' => time() + 60, 'error' => 'x']]];
        $this->queued = [5 => ''];

        $sync->run();

        $this->assertSame(['key-3'], array_column($this->calls, 'key'));
        $this->assertTrue($this->flag['targets']['a']['stale']);
    }

    public function testEveryNightBetweenOneAndFive_WhenTheLastFullSyncWasTheDayBefore(): void
    {
        $this->now = '2026-09-29 02:00:00 UTC';
        $this->flag = ['tenants' => ['a' => 1], 'full_at' => strtotime('2026-09-28 14:00:00 UTC')]; // connected yesterday afternoon
        $this->sync([], tenants: [1 => 'a'])->run();
        $this->assertSame(1, $this->catalogQueued);

        $this->now = '2026-09-29 03:00:00 UTC'; // later that night: done already
        $this->sync([], tenants: [1 => 'a'])->run();
        $this->assertSame(1, $this->catalogQueued);
    }

    public function testADisconnectedCompanysFailureIsForgotten(): void
    {
        $this->flag = ['tenants' => ['a' => 1, 'b' => 3], 'full_at' => time(), 'targets' => ['b' => ['retry_at' => time() + 60, 'error' => 'x']]];

        $sync = $this->sync([], tenants: [1 => 'a']);
        $sync->run();

        $this->assertArrayNotHasKey('targets', $this->flag);
        $this->assertNull($sync->status()['error']);
    }

    public function testAReplacedCompanysFailureIsForgotten(): void
    {
        $this->flag = ['tenants' => ['b' => 3], 'full_at' => time(), 'targets' => ['b' => ['retry_at' => time() + 60, 'error' => 'x']]];

        $this->sync([], tenants: [1 => 'a'])->run(); // b left, a came

        $this->assertSame(1, $this->catalogQueued);
        $this->assertArrayNotHasKey('targets', $this->flag);
    }

    public function testReconnectingACompanyAfterADisconnectResendsTheCatalog(): void
    {
        $this->flag = ['tenants' => ['a' => 1], 'full_at' => time()];
        $this->sync([], tenants: [])->run(); // disconnected
        $this->assertSame([], $this->flag['tenants']);

        $this->sync([200], tenants: [1 => 'a'])->run();
        $this->assertSame(1, $this->catalogQueued);
    }

    public function testACompanyReconnectingWhileAnotherStayedConnectedGetsTheCatalog(): void
    {
        $this->flag = ['tenants' => ['a' => 1, 'b' => 3], 'full_at' => time()];
        $this->sync([], tenants: [1 => 'a'])->run(); // b disconnected; a keeps receiving changes
        $this->assertSame(0, $this->catalogQueued);

        $this->sync([], tenants: [1 => 'a', 3 => 'b'])->run();
        $this->assertSame(1, $this->catalogQueued);
    }

    public function testACompanyReadFromAnotherWebsiteGetsTheCatalogAgain(): void
    {
        // Websites 2 and 3 share company b; 2 is disconnected, so 3 now speaks for it.
        $this->flag = ['tenants' => ['b' => 2], 'full_at' => time()];
        $this->sync([], tenants: [3 => 'b'])->run();

        $this->assertSame(1, $this->catalogQueued);
        $this->assertSame(['b' => 3], $this->flag['tenants']);
    }

    public function testAVariantThatCannotBeReadIsQueuedOnItsOwn(): void
    {
        $sync = $this->sync([200]);
        $this->flag = ['tenants' => ['a' => 1], 'full_at' => time()];
        $this->queued = [4 => '']; // the configurable product; its child 68 fails to build
        $this->unreadable = [68];
        $this->expanded = [4 => [68, 69]];

        $sync->run();

        $this->assertSame([68], array_keys($this->queued));
    }

    public function testRequestsStayUnderTheApiLimitAndKeepAGroupWithItsSwitchOff(): void
    {
        $group = fn (string $id, int $n) => array_map(fn ($i) => ['reference' => "$id-$i", 'groupId' => $id], range(1, $n));
        $requests = Sync::requests(array_merge($group('10', 300), $group('20', 300), $group('30', 600)), ['10', '20', '30', '40']);

        $this->assertSame([300, 500, 100, 300], array_map(fn ($r) => count($r['products']), $requests));
        $this->assertSame(['10'], $requests[0]['reconcileGroupIds']);
        // 30 is too big for one request, so it is never switched off; 40 (deleted) rides along.
        $this->assertSame([[], []], [$requests[1]['reconcileGroupIds'], $requests[2]['reconcileGroupIds']]);
        $this->assertSame(['20', '40'], $requests[3]['reconcileGroupIds']);
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
            $store->method('getId')->willReturn((int) $id);
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
        $builder->method('awaitingIndexes')->willReturnCallback(fn ($queued) => array_values(array_intersect(array_keys($queued), $this->awaitingIndex)));
        $builder->method('build')->willReturnCallback(function ($ids, $store) {
            $this->calls[] = ['built' => array_merge($ids, [$store->getCode()])];
            $all = $ids;
            foreach ($ids as $id) {
                $all = array_merge($all, $this->expanded[$id] ?? []);
            }
            return [
                'products' => array_map(fn ($id) => ['reference' => (string) $id], array_values(array_diff($all, $this->unreadable))),
                'reconcileGroupIds' => [],
                'failed' => array_values(array_intersect($all, $this->unreadable)),
            ];
        });

        $queue = $this->createStub(ProductSyncQueue::class);
        $queue->method('enqueueAll')->willReturnCallback(function () {
            $this->catalogQueued++;
        });
        $queue->method('claimNext')->willReturnCallback(function ($limit, $after, $claim) {
            ksort($this->queued);
            $next = [];
            foreach (array_keys($this->queued) as $id) {
                if ($id > $after && count($next) < $limit) {
                    $this->queued[$id] = $claim;
                    $next[$id] = gmdate('Y-m-d H:i:s');
                }
            }
            return $next;
        });
        $queue->method('release')->willReturnCallback(function ($ids, $claim) {
            foreach ($ids as $id) {
                if (($this->queued[$id] ?? null) === $claim) {
                    $this->queued[$id] = '';
                }
            }
        });
        $queue->method('ensureQueued')->willReturnCallback(function ($ids) {
            foreach ($ids as $id) {
                $this->queued[$id] = $this->queued[$id] ?? '';
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
        // The store's time: now, or the time given.
        $timezone->method('date')->willReturnCallback(fn ($date = null) => $date instanceof \DateTimeInterface
            ? \DateTime::createFromInterface($date) : new \DateTime($this->now));

        return new Sync($config, $client, $storeManager, $queue, $builder, $flags, $locks, $timezone, new NullLogger());
    }
}
