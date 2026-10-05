<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Catalog;

use Bluebarry\Bluebarry\Model\Api\Client;
use Bluebarry\Bluebarry\Model\Api\Response;
use Bluebarry\Bluebarry\Model\Catalog\Categories;
use Bluebarry\Bluebarry\Model\Catalog\Sync;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CategoriesTest extends TestCase
{
    private array $flag = [];
    /** @var array<string, array[]|null> tenant => the categories its store has now */
    private array $stores = ['tenant-a' => [['id' => '12', 'name' => 'Mugs', 'count' => 4]]];
    /** @var array<int, array{tenant: string, key: string, body: array}> */
    private array $posted = [];
    /** @var Response[] */
    private array $answers = [];
    private bool $locked = false;

    public function testACompanyThatHasNoneYetIsSentItsCategories_AndNotAgainWhileNothingChanged(): void
    {
        $this->assertSame(['sent' => 1, 'failed' => 0], $this->categories()->run());
        $this->assertSame([['tenant' => 'tenant-a', 'key' => 'key-tenant-a', 'body' => ['categories' => $this->stores['tenant-a']]]], $this->posted);

        // The cron's next runs: one flag read, nothing built, nothing sent.
        $this->stores['tenant-a'] = null; // would fail if it were read
        $this->assertSame(['sent' => 0, 'failed' => 0], $this->categories()->run());
        $this->assertCount(1, $this->posted);
    }

    public function testACategorySavedInTheAdminIsNotedOnce_AndTheNextRunSendsTheSet(): void
    {
        $this->categories()->run();
        $categories = $this->categories();
        $before = $this->flag;

        $categories->changed();
        $categories->changed(); // an import saves many
        $this->assertTrue($this->flag['changed']);
        $this->assertSame($before['sent'], $this->flag['sent']);

        $this->stores['tenant-a'][] = ['id' => '13', 'name' => 'Tea', 'count' => 1];
        $this->assertSame(['sent' => 1, 'failed' => 0], $categories->run());
        $this->assertCount(2, $this->posted[1]['body']['categories']);
        $this->assertFalse($this->flag['changed']);
    }

    public function testASaveThatChangedNothingBluebarryKeepsSendsNothing(): void
    {
        $this->categories()->run();
        $categories = $this->categories();
        $categories->changed();

        $this->assertSame(['sent' => 0, 'failed' => 0], $categories->run());
        $this->assertCount(1, $this->posted);
    }

    public function testOnceADayTheSetIsLookedAtAgain_ForItsProductCounts(): void
    {
        $this->categories()->run();
        $this->stores['tenant-a'][0]['count'] = 5;

        $this->assertSame(0, $this->categories()->run()['sent']); // not due yet
        $this->flag['looked'] -= 86401;
        $this->assertSame(1, $this->categories()->run()['sent']);
        $this->assertSame(5, $this->posted[1]['body']['categories'][0]['count']);
    }

    public function testWhatCouldNotBeSentStaysToBeSent_AndEveryCompanyGetsItsOwn(): void
    {
        $this->stores['tenant-b'] = [['id' => '20', 'name' => 'Bikes', 'count' => 2]];
        $this->answers = [new Response(503, ''), new Response(200, '{}')];

        $this->assertSame(['sent' => 1, 'failed' => 1], $this->categories()->run());
        $this->assertTrue($this->flag['changed']);
        $this->assertSame(['tenant-b'], array_keys($this->flag['sent']));

        $this->assertSame(['sent' => 1, 'failed' => 0], $this->categories()->run());
        $this->assertSame(['tenant-a', 'tenant-b', 'tenant-a'], array_column($this->posted, 'tenant'));
        $this->assertFalse($this->flag['changed']);
    }

    public function testAStoreWithMoreCategoriesThanBluebarryTakesSendsNone_RatherThanPartOfThem(): void
    {
        $this->stores['tenant-a'] = null;
        $categories = $this->categories(tooMany: true);

        $this->assertSame(['sent' => 1, 'failed' => 0], $categories->run());
        $this->assertSame([], $this->posted);
        // Not looked at again every five minutes.
        $this->assertSame(['sent' => 0, 'failed' => 0], $categories->run());
    }

    public function testTwoRunsAtOnceSendOnce_AndNothingRunsWithoutAConnectedWebsite(): void
    {
        $this->locked = true;
        $this->assertSame(['sent' => 0, 'failed' => 0], $this->categories()->run());
        $this->locked = false;
        $this->stores = [];
        $this->assertSame(['sent' => 0, 'failed' => 0], $this->categories()->run(true));
        $this->assertSame([], $this->posted);
    }

    private function categories(bool $tooMany = false): Categories
    {
        $sync = $this->createStub(Sync::class);
        $sync->method('targets')->willReturnCallback(fn () => array_map(fn ($tenant) => [
            'store' => $this->store($tenant), 'tenantId' => $tenant, 'apiKey' => 'key-' . $tenant,
        ], array_keys($this->stores)));
        $client = $this->createStub(Client::class);
        $client->method('post')->willReturnCallback(function ($path, $body, $tenant, $key) {
            $this->assertSame('/data/magento/categories', $path);
            $answer = array_shift($this->answers) ?? new Response(200, '{}');
            $this->posted[] = ['tenant' => $tenant, 'key' => $key, 'body' => $body];
            return $answer;
        });
        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(fn () => $this->flag);
        $flags->method('saveFlag')->willReturnCallback(function ($code, $data) {
            $this->flag = $data;
            return true;
        });
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturnCallback(fn () => !$this->locked);

        $categories = $this->getMockBuilder(Categories::class)
            ->setConstructorArgs([$sync, $client, $this->createStub(CollectionFactory::class), $this->createStub(ResourceConnection::class), $flags, $locks, new NullLogger()])
            ->onlyMethods(['payload'])
            ->getMock();
        $categories->method('payload')->willReturnCallback(function (Store $store) use ($tooMany) {
            if ($tooMany) {
                return null;
            }
            $set = $this->stores[$store->getCode()] ?? null;
            $this->assertNotNull($set, 'the categories were read though nothing was due');
            return $set;
        });
        return $categories;
    }

    private function store(string $tenant): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getCode')->willReturn($tenant);
        return $store;
    }
}
