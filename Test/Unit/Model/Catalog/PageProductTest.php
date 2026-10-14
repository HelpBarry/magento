<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Catalog;

use Bluebarry\Bluebarry\Model\Catalog\PageProduct;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableLinks;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\Group;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;

/**
 * Which configurable product a page groups a variant under. The catalog sync reads a website's catalog
 * in its default store view (Sync::targets()), so the page decides it there too, whichever store view
 * the shopper is on.
 */
class PageProductTest extends TestCase
{
    private const DEFAULT_VIEW = 1;
    private const OTHER_VIEW = 2;
    private const WEBSITE = 1;

    /** @var array<int, int[]> child => its configurable products */
    private array $links = [21 => [10, 12]];
    /** @var array<int, int[]> store view => the configurable products enabled and with a page there */
    private array $shown = [];
    /** @var int[] the store views the parents were read in */
    private array $readIn = [];
    /** @var int[][] the website filters */
    private array $websiteFilters = [];

    public function testOnAnotherStoreViewTheVariantIsGroupedAsInTheDefaultStoreView(): void
    {
        // Parent 10 has a page only on the store view being looked at, 12 only on the default one.
        $this->shown = [self::DEFAULT_VIEW => [12], self::OTHER_VIEW => [10]];

        $this->assertSame(12, $this->pageProduct(self::OTHER_VIEW)->syncedParentId(21));
        $this->assertSame([self::DEFAULT_VIEW], $this->readIn);
        $this->assertSame([[self::WEBSITE]], $this->websiteFilters);
    }

    public function testAParentHiddenInTheDefaultStoreViewGroupsNothing_ThoughItHasAPageHere(): void
    {
        $this->shown = [self::DEFAULT_VIEW => [], self::OTHER_VIEW => [10, 12]];

        $this->assertNull($this->pageProduct(self::OTHER_VIEW)->syncedParentId(21));
    }

    public function testTheLowestShownParentGroupsIt(): void
    {
        $this->shown = [self::DEFAULT_VIEW => [12, 10], self::OTHER_VIEW => []];

        $this->assertSame(10, $this->pageProduct(self::DEFAULT_VIEW)->syncedParentId(21));
    }

    public function testAProductWithoutParentsStandsForItself_WithoutReadingAnyParent(): void
    {
        $this->assertNull($this->pageProduct(self::OTHER_VIEW)->syncedParentId(30));
        $this->assertSame([], $this->readIn);
    }

    private function pageProduct(int $storeViewId): PageProduct
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($storeViewId);
        $store->method('getWebsiteId')->willReturn(self::WEBSITE);
        $website = $this->createStub(Website::class);
        $website->method('getId')->willReturn(self::WEBSITE);
        $website->method('getDefaultGroupId')->willReturn(5);
        $group = $this->createStub(Group::class);
        $group->method('getDefaultStoreId')->willReturn(self::DEFAULT_VIEW);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getWebsite')->willReturnCallback(fn ($id) => (int) $id === self::WEBSITE ? $website : null);
        $storeManager->method('getGroup')->willReturnCallback(fn ($id) => (int) $id === 5 ? $group : null);

        $collection = $this->createStub(Collection::class);
        $storeId = null;
        $collection->method('setStoreId')->willReturnCallback(function ($id) use (&$storeId, $collection) {
            $storeId = (int) $id;
            $this->readIn[] = $storeId;
            return $collection;
        });
        $ids = [];
        $collection->method('addIdFilter')->willReturnCallback(function ($filter) use (&$ids, $collection) {
            $ids = $filter;
            return $collection;
        });
        $collection->method('addWebsiteFilter')->willReturnCallback(function ($filter) use ($collection) {
            $this->websiteFilters[] = $filter;
            return $collection;
        });
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('getAllIds')->willReturnCallback(function () use (&$storeId, &$ids) {
            return array_map('strval', array_values(array_intersect($this->shown[$storeId] ?? [], $ids)));
        });
        $products = $this->createStub(CollectionFactory::class);
        $products->method('create')->willReturn($collection);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $children = [];
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use (&$children, $select) {
            $children = (array) $value;
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturnCallback(function () use (&$children) {
            $rows = [];
            foreach ($children as $child) {
                foreach ($this->links[(int) $child] ?? [] as $parent) {
                    $rows[] = ['child' => (string) $child, 'parent' => (string) $parent];
                }
            }
            return $rows;
        });
        $links = $this->createStub(ConfigurableLinks::class);
        $links->method('getConnection')->willReturn($connection);
        $links->method('getTable')->willReturnArgument(0);

        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $metadataPool = $this->createStub(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        return new PageProduct($storeManager, $products, $links, $metadataPool);
    }
}
