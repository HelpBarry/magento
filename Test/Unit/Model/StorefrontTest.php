<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model;

use Bluebarry\Bluebarry\Model\Api\Client;
use Bluebarry\Bluebarry\Model\Api\Response;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Storefront;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
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
    private array $purged = [];
    /** @var callable|null */
    private $onGet;
    private array $answers = [];
    private bool $locked = false;

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
        $this->assertNull($this->storefront(connected: false)->search(1, self::TENANT));
        $this->assertArrayNotHasKey('search', $this->flag[1]);
    }

    public function testOnlyAnExplicitNullSwitchesSearchOff(): void
    {
        $storefront = $this->storefront();
        $this->answers = [
            $this->answer(['profileId' => self::PROFILE, 'resultsPage' => false]),
            new Response(200, (string) json_encode(['tenantId' => self::TENANT, 'version' => 'v2'])), // no search at all
            new Response(200, (string) json_encode(['tenantId' => self::TENANT, 'search' => ['profileId' => 'nope'], 'version' => 'v2'])),
            new Response(200, (string) json_encode(['tenantId' => self::TENANT, 'search' => 'on', 'version' => 'v2'])),
            new Response(200, (string) json_encode(['tenantId' => self::TENANT, 'search' => ['profileId' => self::PROFILE, 'resultsPage' => 'true'], 'version' => 'v2'])),
        ];
        $storefront->refresh($this->website(1));

        $this->assertNull($storefront->refresh($this->website(1)));
        $this->assertNull($storefront->refresh($this->website(1)));
        $this->assertNull($storefront->refresh($this->website(1)));
        $this->assertNull($storefront->refresh($this->website(1)));
        $this->assertSame(self::PROFILE, $storefront->search(1, self::TENANT)['profileId']);
    }

    public function testWhileAnotherWebsiteSavesNothingIsSavedOverIt(): void
    {
        $this->locked = true;
        $this->answers = [$this->answer(['profileId' => self::PROFILE, 'resultsPage' => false])];
        $this->storefront()->refresh($this->website(1));

        $this->assertSame([], $this->flag);
    }

    public function testOldSettingsCachedByAPageDuringAChangeAreCleared(): void
    {
        $storefront = $this->storefront();
        $this->answers = [$this->answer(['profileId' => self::PROFILE, 'resultsPage' => false]), $this->answer(['profileId' => self::PROFILE, 'resultsPage' => false])];
        $storefront->refresh($this->website(1));
        $this->cache[Storefront::FLAG] = '[]'; // a page read the flag before it was saved, and cached it after
        $purges = $this->purges;

        $storefront->refresh($this->website(1)); // unchanged in bluebarry
        $this->assertSame(self::PROFILE, $storefront->search(1, self::TENANT)['profileId']);
        $this->assertSame($purges + 1, $this->purges);
    }

    public function testOnlyTheWebsitesOwnPagesArePurged(): void
    {
        $this->answers = [$this->answer(['profileId' => self::PROFILE, 'resultsPage' => false])];
        $this->storefront()->refresh($this->website(2));

        $this->assertSame([[Storefront::cacheTag(2)]], $this->purged);
    }

    public function testAnOlderReadNeverReplacesNewerSettings(): void
    {
        $storefront = $this->storefront();
        $old = $this->answer(['profileId' => self::PROFILE, 'resultsPage' => false]);
        $newer = $this->answer(['profileId' => self::PROFILE, 'resultsPage' => true]);
        // The cron's read is on its way when bluebarry's command reads the newer settings and saves them.
        $this->answers = [$old, $newer];
        $this->onGet = fn () => $storefront->refresh($this->website(1));

        $storefront->refresh($this->website(1));

        $this->assertTrue($storefront->search(1, self::TENANT)['resultsPage']);
    }

    public function testPagesArePurgedAgainWhenTheSettingsCacheIsGoneRightAfterAChange(): void
    {
        $storefront = $this->storefront();
        $this->answers = [$this->answer(['profileId' => self::PROFILE, 'resultsPage' => false]), $this->answer(['profileId' => self::PROFILE, 'resultsPage' => false])];
        $storefront->refresh($this->website(1));
        $purges = $this->purges;
        // A page read the old settings before the change and went into the page cache after the purge.
        unset($this->cache[Storefront::FLAG]);

        $storefront->refresh($this->website(1));

        $this->assertSame($purges + 1, $this->purges);
    }

    public function testAChangeIsPurgedOnceMoreAMinuteLater_ForPagesStillRenderingDuringIt(): void
    {
        $storefront = $this->storefront();
        $same = fn () => $this->answer(['profileId' => self::PROFILE, 'resultsPage' => false]);
        $this->answers = [$same(), $same(), $same(), $same()];
        $storefront->refresh($this->website(1));
        $storefront->search(1, self::TENANT); // a page refilled the settings cache
        $storefront->refresh($this->website(1)); // right away: a page may still be rendering
        $this->assertSame(1, $this->purges);

        // A page that rendered the old settings reached the page cache after the purge.
        $this->flag[1]['written'] -= 61;
        unset($this->cache[Storefront::FLAG]);
        $storefront->search(1, self::TENANT); // the settings cache is current
        $storefront->refresh($this->website(1));
        $this->assertSame(2, $this->purges);

        $storefront->search(1, self::TENANT);
        $storefront->refresh($this->website(1)); // and only once
        $this->assertSame(2, $this->purges);
        $this->assertSame(self::PROFILE, $storefront->search(1, self::TENANT)['profileId']);
    }

    public function testAWebsiteThatWasNeverConnectedPurgesNothing(): void
    {
        $storefront = $this->storefront(connected: false);

        $storefront->refresh($this->website(1));
        $this->flag[1]['written'] -= 61;
        $this->cache[Storefront::FLAG] = (string) json_encode($this->flag);
        $storefront->refresh($this->website(1));

        $this->assertSame(0, $this->purges);
        $this->assertNull($storefront->search(1, self::TENANT));
    }

    public function testAReadBegunBeforeADisconnectDoesNotBringSearchBack(): void
    {
        $storefront = $this->storefront();
        $this->answers = [$this->answer(['profileId' => self::PROFILE, 'resultsPage' => false])];
        // The cron's read is on its way when the settings save disconnects the website.
        $this->onGet = fn () => $this->storefront(connected: false)->refresh($this->website(1));

        $storefront->refresh($this->website(1));

        $this->assertNull($storefront->search(1, self::TENANT));
    }

    public function testADisconnectObservedBeforeAReconnectDoesNotSwitchSearchOff(): void
    {
        // The disconnect saw the missing key first, but saves after the reconnect's read.
        $late = new \ReflectionMethod(Storefront::class, 'write');
        $disconnectSeenAt = (int) round(microtime(true) * 1000) - 1000;
        $storefront = $this->storefront();
        $this->answers = [$this->answer(['profileId' => self::PROFILE, 'resultsPage' => false])];
        $storefront->refresh($this->website(1));

        $late->invoke($storefront, 1, null, $disconnectSeenAt);

        $this->assertSame(self::PROFILE, $storefront->search(1, self::TENANT)['profileId']);
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
        $client->method('get')->willReturnCallback(function () {
            $answer = array_shift($this->answers);
            if ($this->onGet) {
                $onGet = $this->onGet;
                $this->onGet = null;
                usleep(2000); // the other read begins later
                $onGet();
            }
            return $answer;
        });
        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(fn () => $this->flag);
        $flags->method('saveFlag')->willReturnCallback(function ($code, $data) {
            $this->flag = $data;
            return true;
        });
        $events = $this->createStub(ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function ($name, $data) {
            if ($name === 'clean_cache_by_tags') {
                $this->purged[] = $data['object']->getIdentities();
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
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturnCallback(fn () => !$this->locked);
        return new Storefront($config, $client, $this->createStub(StoreManagerInterface::class), $flags, $events, $cache, $locks);
    }
}
