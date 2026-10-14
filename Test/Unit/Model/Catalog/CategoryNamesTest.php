<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Catalog;

use Bluebarry\Bluebarry\Model\Catalog\Categories;
use Bluebarry\Bluebarry\Model\Catalog\CategoryNames;
use Bluebarry\Bluebarry\Model\Catalog\ProductBuilder;
use Bluebarry\Bluebarry\Model\Catalog\Sync;
use Bluebarry\Bluebarry\Model\Api\Client;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Two categories with the same name (Men > Tops, Women > Tops) go to bluebarry by their path, in the
 * category list and in each product's categories alike, so each collection finds only its own products.
 */
class CategoryNamesTest extends TestCase
{
    /** Root 2 ("Default Category"), with Men and Women below it, each with its Tops. */
    private const TREE = [
        2 => ['name' => 'Default Category', 'path' => '1/2', 'is_active' => '1'],
        3 => ['name' => 'Men', 'path' => '1/2/3', 'is_active' => '1'],
        4 => ['name' => 'Women', 'path' => '1/2/4', 'is_active' => '1'],
        5 => ['name' => 'Tops', 'path' => '1/2/3/5', 'is_active' => '1'],
        6 => ['name' => ' TOPS ', 'path' => '1/2/4/6', 'is_active' => '1'],
        7 => ['name' => 'Shirts &amp; blouses', 'path' => '1/2/3/5/7', 'is_active' => '1'],
        // An inactive parent still names its children's path; an inactive namesake is not sent and
        // makes no other category a namesake.
        8 => ['name' => 'Archive', 'path' => '1/2/8', 'is_active' => '0'],
        9 => ['name' => 'Sale', 'path' => '1/2/8/9', 'is_active' => '1'],
        10 => ['name' => 'Sale', 'path' => '1/2/4/10', 'is_active' => '1'],
        11 => ['name' => 'Jeans', 'path' => '1/2/3/11', 'is_active' => '1'],
        12 => ['name' => 'Jeans', 'path' => '1/2/4/12', 'is_active' => '0'],
        // An archive of inactive categories nobody's path goes through: never read.
        13 => ['name' => 'Old stock', 'path' => '1/2/8/13', 'is_active' => '0'],
        14 => ['name' => 'Older stock', 'path' => '1/2/8/13/14', 'is_active' => '0'],
    ];

    /** @var int[] the ids each read of a collection hydrated */
    private array $loaded = [];

    public function testANameAnotherActiveCategoryShares_IsItsPathBelowTheRoot_AndAUniqueNameStays(): void
    {
        $this->assertSame([
            3 => 'Men',
            4 => 'Women',
            5 => 'Men / Tops',
            6 => 'Women / TOPS',
            7 => 'Shirts & blouses',
            9 => 'Archive / Sale',
            10 => 'Women / Sale',
            11 => 'Jeans',
        ], CategoryNames::forStore($this->factory(), $this->store()));
        // The active categories, then only the inactive parent a namesake's path goes through.
        $this->assertSame([[3, 4, 5, 6, 7, 9, 10, 11], [8]], $this->loaded);
    }

    public function testWithoutNamesakesOnlyTheActiveCategoriesAreRead(): void
    {
        $factory = $this->factory([5, 6, 9, 10]);

        $this->assertSame([3 => 'Men', 4 => 'Women', 7 => 'Shirts & blouses', 11 => 'Jeans'], CategoryNames::forStore($factory, $this->store()));
        $this->assertSame([[3, 4, 7, 11]], $this->loaded);
    }

    public function testAProductNamesItsCategoriesAsTheCategoryListDoes_ReadOncePerStore(): void
    {
        $factory = $this->factory();
        $builder = (new \ReflectionClass(ProductBuilder::class))->newInstanceWithoutConstructor();
        $categories = new \ReflectionProperty(ProductBuilder::class, 'categories');
        $categories->setAccessible(true);
        $categories->setValue($builder, $factory);
        $names = new \ReflectionMethod(ProductBuilder::class, 'categoryNames');
        $names->setAccessible(true);

        $this->assertSame(['Men / Tops', 'Shirts & blouses'], $names->invoke($builder, [5, 7, 8], $this->store()));
        $this->assertSame(['Women / TOPS', 'Jeans'], $names->invoke($builder, [6, 11], $this->store()));
        $this->assertCount(2, $this->loaded, 'the categories are read once per store, not per product');
    }

    public function testTheCategoryListNamesThemTheSameWay(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->method('fetchPairs')->willReturn([]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $categories = new Categories($this->createStub(Sync::class), $this->createStub(Client::class), $this->factory(), $resource,
            $this->createStub(FlagManager::class), $this->createStub(LockManagerInterface::class), new NullLogger(),
            $this->createStub(TableMaintainer::class));

        $payload = $categories->payload($this->store());

        $this->assertSame(
            ['3' => 'Men', '4' => 'Women', '5' => 'Men / Tops', '6' => 'Women / TOPS', '7' => 'Shirts & blouses', '9' => 'Archive / Sale', '10' => 'Women / Sale', '11' => 'Jeans'],
            array_column($payload, 'name', 'id')
        );
    }

    /**
     * A category collection over TREE (without the given ids) that honours the filters the module sets:
     * the path below root 2, is_active, and a list of ids. Each read is recorded in $loaded.
     *
     * @param int[] $without
     */
    private function factory(array $without = []): CollectionFactory
    {
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($without) {
            $activeOnly = false;
            $ids = null;
            $collection = $this->createStub(Collection::class);
            foreach (['setStoreId', 'addAttributeToSelect', 'addUrlRewriteToResult', 'setOrder', 'setPageSize'] as $method) {
                $collection->method($method)->willReturnSelf();
            }
            $collection->method('addAttributeToFilter')->willReturnCallback(function ($attribute) use (&$activeOnly, $collection) {
                $activeOnly = $activeOnly || $attribute === 'is_active';
                return $collection;
            });
            $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $condition) use (&$ids, $collection) {
                if ($field === 'entity_id') {
                    $ids = $condition['in'];
                }
                return $collection;
            });
            $collection->method('getSize')->willReturn(count(self::TREE));
            $collection->method('getIterator')->willReturnCallback(function () use (&$activeOnly, &$ids, $without) {
                $rows = [];
                $read = [];
                foreach (self::TREE as $id => $row) {
                    if ($id === 2 || in_array($id, $without, true) || ($activeOnly && $row['is_active'] !== '1')
                        || ($ids !== null && !in_array($id, $ids, true))) {
                        continue; // the root is no category of the store's own
                    }
                    $read[] = $id;
                    $rows[] = new DataObject(['id' => $id, 'url' => 'https://shop.example/c' . $id . '.html'] + $row);
                }
                $this->loaded[] = $read;
                return new \ArrayIterator($rows);
            });
            return $collection;
        });
        return $factory;
    }

    private function store(): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getRootCategoryId')->willReturn(2);
        $store->method('getBaseUrl')->willReturn('https://shop.example/');
        return $store;
    }
}
