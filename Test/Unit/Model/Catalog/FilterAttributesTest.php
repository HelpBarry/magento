<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Catalog;

use Bluebarry\Bluebarry\Model\Catalog\FilterAttributes;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

class FilterAttributesTest extends TestCase
{
    /** @var array<int, array> the filters each collection was given, in creation order */
    private array $filtersApplied = [];

    public function testOnlyFiltersTheCatalogSends_AndPrice(): void
    {
        $filterAttributes = $this->filterAttributes(
            // What the catalog sync sends as attr_{code}.
            ['color', 'material', 'description_extra'],
            // What the layered navigation filters by, in its order.
            [
                ['attribute_code' => 'price', 'store_label' => 'Prijs', 'position' => 0],
                ['attribute_code' => 'manufacturer', 'store_label' => 'Merk', 'position' => 1], // a system attribute
                ['attribute_code' => 'color', 'store_label' => 'Kleur', 'position' => 2],
                ['attribute_code' => 'material', 'store_label' => '', 'frontend_label' => 'Material', 'position' => 3],
            ]
        );

        $this->assertSame([
            ['code' => 'price', 'label' => 'Prijs', 'position' => 0],
            ['code' => 'color', 'label' => 'Kleur', 'position' => 2],
            ['code' => 'material', 'label' => 'Material', 'position' => 3],
        ], $filterAttributes->forStore(1));
        // The synced set is the catalog sync's own rule: its own attributes, never Magento's system ones.
        $this->assertContains(['is_user_defined', ['eq' => 1]], $this->filtersApplied[0]);
    }

    public function testAStoreWithoutFiltersReportsNone(): void
    {
        $this->assertSame([], $this->filterAttributes(['color'], [])->forStore(1));
    }

    /**
     * @param string[] $synced
     * @param array[] $filterable
     */
    private function filterAttributes(array $synced, array $filterable): FilterAttributes
    {
        $collections = [
            $this->collection(array_map(fn ($code) => ['attribute_code' => $code], $synced)),
            $this->collection($filterable),
        ];
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use (&$collections) {
            return array_shift($collections);
        });
        return new FilterAttributes($factory);
    }

    private function collection(array $rows): Collection
    {
        $index = count($this->filtersApplied);
        $this->filtersApplied[$index] = [];
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $condition) use ($collection, $index) {
            $this->filtersApplied[$index][] = [$field, $condition];
            return $collection;
        });
        $collection->method('addStoreLabel')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getIterator')->willReturnCallback(fn () => new \ArrayIterator(array_map(fn ($row) => new DataObject($row), $rows)));
        return $collection;
    }
}
