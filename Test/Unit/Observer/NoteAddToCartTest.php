<?php

namespace Bluebarry\Bluebarry\Test\Unit\Observer;

use Bluebarry\Bluebarry\Observer\NoteAddToCart;
use PHPUnit\Framework\TestCase;

class NoteAddToCartTest extends TestCase
{
    public function testKeepsOnlyWellFormedAddsAndTheLastTwenty(): void
    {
        $adds = [['r' => '18', 'q' => 2, 't' => 1, 'i' => 'a1'], ['r' => 'x', 'q' => 1, 't' => 1, 'i' => 'a2'], ['r' => '19', 'q' => 1, 't' => 1, 'i' => '<script>']];
        for ($n = 0; $n < 25; $n++) {
            $adds[] = ['r' => (string) (100 + $n), 'q' => 1, 't' => 1, 'i' => "b$n"];
        }

        $pending = NoteAddToCart::pending((string) json_encode($adds));

        $this->assertCount(20, $pending);
        $this->assertSame('105', $pending[0]['r']);
        $this->assertSame([], NoteAddToCart::pending('not json'));
    }
}
