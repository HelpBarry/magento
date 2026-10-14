<?php

namespace Bluebarry\Bluebarry\Test\Unit\Controller\Cart;

use Bluebarry\Bluebarry\Controller\Cart\Link;
use Bluebarry\Bluebarry\Model\Cart\Adder;
use PHPUnit\Framework\TestCase;

/**
 * The lines of a cart link's `items`, as bluebarry/cart/add's Adder takes them.
 */
class LinkTest extends TestCase
{
    public function testEachLineIsAnIdWithItsQuantityOrOne(): void
    {
        $this->assertSame([
            ['reference' => '12', 'quantity' => '2'],
            ['reference' => '34', 'quantity' => 1],
            ['reference' => '56', 'quantity' => '1.5'],
        ], Link::lines('12:2,34, 56:1.5'));
    }

    public function testEmptyLinesArePassedOverAndTheRestGoesAsGiven(): void
    {
        // Adder leaves out what it cannot add: an id that is no number, a quantity that is none.
        $this->assertSame([
            ['reference' => 'abc', 'quantity' => 1],
            ['reference' => '12', 'quantity' => 'x'],
            ['reference' => '', 'quantity' => '3'],
            ['reference' => '7', 'quantity' => '1:2'],
        ], Link::lines(',abc,,12:x,:3,7:1:2,'));
        $this->assertSame([], Link::lines(''));
        $this->assertSame([], Link::lines(' , '));
    }

    public function testAtMostOneLinePastTheAddersLimitIsRead(): void
    {
        $lines = Link::lines(implode(',', range(1, 500)));
        // The one past the limit is reported by the adder as not added; the rest is not even read.
        $this->assertCount(Adder::MAX_LINES + 1, $lines);
        $this->assertSame((string) (Adder::MAX_LINES + 1), end($lines)['reference']);
    }

    public function testAnOverlongValueIsCutBeforeItIsSplit(): void
    {
        $lines = Link::lines(str_repeat('9', 5000) . ',12');
        $this->assertCount(1, $lines);
        $this->assertSame(1024, strlen($lines[0]['reference']));
    }
}
