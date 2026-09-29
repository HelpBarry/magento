<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Catalog;

use Bluebarry\Bluebarry\Model\Catalog\ProductBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The stock and price rules. Reading the catalog itself is covered end to end (dev/e2e/tests/catalog.spec.ts).
 */
class ProductBuilderTest extends TestCase
{
    private function row(array $values = []): array
    {
        return $values + [
            'qty' => '12.0000', 'is_in_stock' => '1',
            'use_config_manage_stock' => '1', 'manage_stock' => '0',
            'use_config_backorders' => '1', 'backorders' => '0',
        ];
    }

    public function testAManagedProductReportsItsQuantity(): void
    {
        $this->assertSame(['instock', 12], ProductBuilder::stockOf($this->row(), true, false, true));
    }

    public function testTheOutOfStockThresholdIsNotForSale(): void
    {
        // Store-wide threshold 5, then a product's own 11 (the product has 12).
        $this->assertSame(['instock', 7], ProductBuilder::stockOf($this->row(), true, false, true, 5.0));
        $this->assertSame(['outofstock', 0], ProductBuilder::stockOf($this->row(['use_config_min_qty' => '0', 'min_qty' => '12']), true, false, true, 5.0));
    }

    public function testAQuantitySoldInFractionsKeepsItsFraction(): void
    {
        $this->assertSame(['instock', 0.5], ProductBuilder::stockOf($this->row(['qty' => '0.5000']), true, false, true));
    }

    public function testNothingLeftIsOutOfStock(): void
    {
        $this->assertSame(['outofstock', 0], ProductBuilder::stockOf($this->row(['qty' => '0']), true, false, true));
        $this->assertSame(['outofstock', null], ProductBuilder::stockOf($this->row(['is_in_stock' => '0']), true, false, true));
    }

    public function testAProductThatCanAlwaysBeBoughtHasNoQuantity(): void
    {
        // Stock not managed (store-wide, then per product).
        $this->assertSame(['instock', null], ProductBuilder::stockOf($this->row(['qty' => '0']), false, false, true));
        $this->assertSame(['instock', null], ProductBuilder::stockOf($this->row(['use_config_manage_stock' => '0']), true, false, true));
        // Backorders: sold beyond zero.
        $this->assertSame(['onbackorder', null], ProductBuilder::stockOf($this->row(['qty' => '-2']), true, true, true));
        $this->assertSame(['instock', null], ProductBuilder::stockOf($this->row(['use_config_backorders' => '0', 'backorders' => '1']), true, false, true));
    }

    public function testABundlesStockIsItsStatusOnly(): void
    {
        $this->assertSame(['instock', null], ProductBuilder::stockOf($this->row(['qty' => '0']), true, false, false));
    }

    public function testASaleShowsTheRegularPrice_NoSaleClearsIt(): void
    {
        $this->assertSame([25.0, 30.0], ProductBuilder::pricesOf(25, 30));
        $this->assertSame([30.0, null], ProductBuilder::pricesOf(30, 30));
        $this->assertSame([30.0, null], ProductBuilder::pricesOf(30, null));
        $this->assertSame([19.99, 24.2], ProductBuilder::pricesOf(19.9900001, 24.2));
    }
}
