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

    public function testABundleTheStockIndexCallsOutOfStockIsOutOfStock_WhateverItsOwnSettings(): void
    {
        $this->assertSame(['outofstock', null], ProductBuilder::stockOf($this->row(['is_in_stock' => '0', 'use_config_manage_stock' => '0', 'manage_stock' => '0']), true, false, false));
    }

    public function testNothingLeftIsOutOfStock(): void
    {
        $this->assertSame(['outofstock', 0], ProductBuilder::stockOf($this->row(['qty' => '0']), true, false, true));
        $this->assertSame(['outofstock', null], ProductBuilder::stockOf($this->row(['is_in_stock' => '0']), true, false, true));
    }

    public function testInAMultiSourceStockTheQuantityLeftAfterReservationsDecides(): void
    {
        // The last unit was reserved, then its order cancelled: 1 left, before Magento's consumer set
        // the stock's salable flag again.
        $this->assertSame(['instock', 1], ProductBuilder::stockOf($this->row(['qty' => '1', 'is_in_stock' => '0', 'quantity_decides' => 1]), true, false, true));
        // The last unit reserved, flag not cleared yet.
        $this->assertSame(['outofstock', 0], ProductBuilder::stockOf($this->row(['qty' => '0', 'is_in_stock' => '1', 'quantity_decides' => 1]), true, false, true));
        // The product's own out-of-stock threshold still applies.
        $this->assertSame(['outofstock', 0], ProductBuilder::stockOf($this->row(['qty' => '3', 'is_in_stock' => '0', 'use_config_min_qty' => '0', 'min_qty' => '3', 'quantity_decides' => 1]), true, false, true));
        // With backorders the flag is the sources' status, not a quantity.
        $this->assertSame(['outofstock', null], ProductBuilder::stockOf($this->row(['qty' => '3', 'is_in_stock' => '0', 'quantity_decides' => 1]), true, true, true));
        // A bundle's status is its parts'.
        $this->assertSame(['outofstock', null], ProductBuilder::stockOf($this->row(['qty' => '3', 'is_in_stock' => '0', 'quantity_decides' => 1]), true, false, false));
    }

    public function testABundlesPartsAreTaxedEachByTheirOwnRate(): void
    {
        // Two required parts of EUR 10, one taxed 21%, one exempt.
        $price = ProductBuilder::bundlePriceOf([$this->part(1, 10, 12.10), $this->part(2, 10, 10)], false);
        $this->assertEqualsWithDelta(22.10, $price, 0.001);
    }

    public function testABundlePartNotForSaleIsNoPartOfItsPrice(): void
    {
        // One required option: EUR 20 taxed 21%, and a cheaper exempt part that is sold out.
        $price = ProductBuilder::bundlePriceOf([$this->part(1, 20, 24.20), $this->part(1, 10, 10, available: false)], false);
        $this->assertEqualsWithDelta(24.20, $price, 0.001);
    }

    public function testABundleWithNoPricedSelectionsHasAZeroMinimum(): void
    {
        $this->assertSame(0.0, ProductBuilder::bundlePriceOf([], false));
        $this->assertSame(0.0, ProductBuilder::bundlePriceOf([$this->part(1, 10, 12.10, available: false)], false));
    }

    public function testABundleWithoutRequiredOptionsShowsItsCheapestPartWithTax(): void
    {
        // Across optional options, Magento compares the amounts including tax.
        $price = ProductBuilder::bundlePriceOf([$this->part(1, 10, 12.10, required: false), $this->part(2, 11, 11, required: false)], false);
        $this->assertEqualsWithDelta(11.00, $price, 0.001);
    }

    public function testAlternativesWithinOneOptionAreChosenBeforeTax(): void
    {
        // Magento takes the cheaper untaxed selection, even when another costs less after tax.
        $price = ProductBuilder::bundlePriceOf([$this->part(1, 10, 12.10), $this->part(1, 11, 11)], false);
        $this->assertEqualsWithDelta(12.10, $price, 0.001);
    }

    public function testQuantityTierPricesDoNotReorderAnOptionsSelections(): void
    {
        // A's indexed sorting total is EUR 40, despite a tier making its two units EUR 12.10.
        // B's sorting total is EUR 30, so Magento selects B.
        $price = ProductBuilder::bundlePriceOf([$this->part(1, 40, 6.05, qty: 2), $this->part(1, 30, 36.30)], false);
        $this->assertEqualsWithDelta(36.30, $price, 0.001);
    }

    public function testUnitTaxRoundingHappensBeforeMultiplyingSelectionQuantity(): void
    {
        $parts = [$this->part(1, 3, 0.0363, qty: 100), $this->part(2, 0.01, 0.01)];
        $this->assertEqualsWithDelta(4.01, ProductBuilder::bundlePriceOf($parts, true), 0.001);
        $this->assertEqualsWithDelta(3.64, round(ProductBuilder::bundlePriceOf($parts, false), 2), 0.001);
    }

    public function testOptionalOptionsAreComparedBeforeUnitRounding(): void
    {
        $price = ProductBuilder::bundlePriceOf([
            $this->part(1, 0.03, 0.0149, required: false, qty: 3),
            $this->part(2, 0.03, 0.04, required: false),
        ], true);
        $this->assertEqualsWithDelta(0.04, $price, 0.001);
    }

    private function part(int $option, float $amount, float $unit, bool $required = true, bool $available = true, float $qty = 1): array
    {
        return ['option' => $option, 'required' => $required, 'amount' => $amount, 'available' => $available, 'unit' => $unit, 'qty' => $qty];
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
