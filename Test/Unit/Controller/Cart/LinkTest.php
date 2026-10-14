<?php

namespace Bluebarry\Bluebarry\Test\Unit\Controller\Cart;

use Bluebarry\Bluebarry\Controller\Cart\Link;
use Bluebarry\Bluebarry\Model\Cart\Adder;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Discount\Cart as Discounts;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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

    public function testTheRedirectIsNeverCachedNorIndexed_SoCrawlersFollowingQuizLinksGoNoFurther(): void
    {
        $headers = [];
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $redirect->method('setHeader')->willReturnCallback(function ($name, $value) use (&$headers, $redirect) {
            $headers[$name] = $value;
            return $redirect;
        });
        $redirects = $this->createStub(RedirectFactory::class);
        $redirects->method('create')->willReturn($redirect);
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($key) => $key === 'items' ? '12:1' : null);
        $adder = $this->createStub(Adder::class);
        $adder->method('add')->willReturn(['success' => false, 'failure' => 'soldOut']);

        $link = new Link(
            $request, $redirects, $adder, $this->createStub(Discounts::class), $this->createStub(Config::class),
            $this->createStub(StoreManagerInterface::class), $this->createStub(ManagerInterface::class),
            $this->createStub(CookieManagerInterface::class), $this->createStub(PublicCookieMetadataFactory::class),
            $this->createStub(ScopeConfigInterface::class), new NullLogger()
        );

        $this->assertSame($redirect, $link->execute());
        $this->assertSame('noindex, nofollow', $headers['X-Robots-Tag']);
        $this->assertStringContainsString('no-store', $headers['Cache-Control']);
    }
}
