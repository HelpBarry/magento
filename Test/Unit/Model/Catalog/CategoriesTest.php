<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Catalog;

use Bluebarry\Bluebarry\Model\Api\Client;
use Bluebarry\Bluebarry\Model\Api\Response;
use Bluebarry\Bluebarry\Model\Catalog\Categories;
use Bluebarry\Bluebarry\Model\Catalog\Sync;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Url;
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
        // The company is named in the request, like the catalog sync's: a key from another company is refused by it.
        $this->assertSame([['tenant' => 'tenant-a', 'key' => 'key-tenant-a', 'body' => ['tenantId' => 'tenant-a', 'categories' => $this->stores['tenant-a']]]], $this->posted);

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

    public function testProductCountsComeFromTheStoreViewsCategoryIndex_ForTheCategoriesSent(): void
    {
        // The index counts an anchor category's subcategories' products, and only enabled products of
        // the store view's website: catalog_category_product has neither.
        $index = $this->createMock(TableMaintainer::class);
        $index->expects($this->once())->method('getMainTable')->with(3)->willReturn('catalog_category_product_index_store3');
        $parts = [];
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnCallback(function ($table, $columns) use (&$parts, $select) {
            $parts['from'] = $table;
            return $select;
        });
        $select->method('where')->willReturnCallback(function ($condition, $value) use (&$parts, $select) {
            $parts[$condition] = $value;
            return $select;
        });
        $select->method('group')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchPairs')->willReturn(['12' => '4', '13' => '0']);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $categories = new Categories(
            $this->createStub(Sync::class), $this->createStub(Client::class), $this->createStub(CollectionFactory::class),
            $resource, $this->createStub(FlagManager::class), $this->createStub(LockManagerInterface::class), new NullLogger(), $index
        );

        $counts = (new \ReflectionMethod($categories, 'productCounts'))->invoke($categories, 3, ['12', '13']);

        $this->assertSame([12 => 4, 13 => 0], $counts);
        $this->assertSame([
            'from' => 'catalog_category_product_index_store3',
            'store_id = ?' => 3,
            'category_id IN (?)' => [12, 13],
            // As the category page lists them: not the products only search finds.
            'visibility IN (?)' => [2, 4],
        ], $parts);
    }

    public function testACategoryWithoutAUrlRewriteGetsItsStorefrontPage_AlsoFromTheAdmin(): void
    {
        // Category::getUrl() would build it with the area's URL builder: an admin address in the admin.
        $url = $this->createMock(Url::class);
        $url->expects($this->once())->method('setScope')->with(3)->willReturnSelf();
        $url->expects($this->once())->method('getUrl')
            ->with('catalog/category/view', ['s' => 'mugs', 'id' => 12, '_nosid' => true, '_secure' => false])
            ->willReturn('https://shop.example/catalog/category/view/s/mugs/id/12/');
        $categories = new Categories(
            $this->createStub(Sync::class), $this->createStub(Client::class), $this->createStub(CollectionFactory::class),
            $this->createStub(ResourceConnection::class), $this->createStub(FlagManager::class), $this->createStub(LockManagerInterface::class),
            new NullLogger(), $this->createStub(TableMaintainer::class), $url
        );
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn('12');
        $category->method('getUrlKey')->willReturn('mugs');
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn('3');

        $page = (new \ReflectionMethod($categories, 'pageWithoutRewrite'))->invoke($categories, $category, $store);

        $this->assertSame('https://shop.example/catalog/category/view/s/mugs/id/12/', $page);
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
            ->setConstructorArgs([$sync, $client, $this->createStub(CollectionFactory::class), $this->createStub(ResourceConnection::class), $flags, $locks, new NullLogger(), $this->createStub(TableMaintainer::class)])
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
