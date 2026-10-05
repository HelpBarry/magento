<?php

namespace Bluebarry\Bluebarry\Test\Unit\Observer;

use Bluebarry\Bluebarry\Model\Discount\Cart;
use Bluebarry\Bluebarry\Observer\ApplyWaitingCode;
use Bluebarry\Bluebarry\Test\Unit\Double\CheckoutSessionDouble;
use Bluebarry\Bluebarry\Test\Unit\Double\QuoteDouble;
use Bluebarry\Bluebarry\Test\Unit\Double\QuoteItemDouble;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class ApplyWaitingCodeTest extends TestCase
{
    private QuoteDouble $quote;
    private CheckoutSessionDouble $session;
    /** @var string[] */
    private array $remembered = [];

    protected function setUp(): void
    {
        $this->quote = new QuoteDouble();
        $this->session = new CheckoutSessionDouble($this->quote);
    }

    public function testAWaitingCodeGoesOnWithTheShoppersFirstProduct(): void
    {
        $this->session->data[Cart::SESSION_WAITING] = ['code' => 'WELCOME-7KQ2', 'tries' => 0];
        // The first product is added to a cart that is not saved yet.
        $this->quote->quoteId = null;
        $this->quote->lines = [new QuoteItemDouble(10)];

        $this->collectTotals();

        $this->assertSame('WELCOME-7KQ2', $this->quote->coupon);
        $this->assertArrayNotHasKey(Cart::SESSION_WAITING, $this->session->data);
        $this->assertSame(['WELCOME-7KQ2'], $this->remembered);
    }

    public function testAShopperWithoutAWaitingCodeCostsOneSessionRead(): void
    {
        $this->quote->lines = [new QuoteItemDouble(10)];

        $this->collectTotals();

        $this->assertSame('', $this->quote->coupon);
        $this->assertSame([], $this->remembered);
    }

    public function testItWaitsOnWhileTheCartIsEmptyOrCannotTakeIt_AndIsGivenUpOnInTheEnd(): void
    {
        $this->session->data[Cart::SESSION_WAITING] = ['code' => 'REWARD-AAAA', 'tries' => 0];

        $this->collectTotals(); // an empty cart: not even tried
        $this->assertSame(['code' => 'REWARD-AAAA', 'tries' => 0], $this->session->data[Cart::SESSION_WAITING]);

        // Below the code's minimum: Magento drops it, and it waits on.
        $this->quote->lines = [new QuoteItemDouble(10)];
        $this->quote->accepts = fn () => false;
        $this->collectTotals();
        $this->assertSame('', $this->quote->coupon);
        $this->assertSame(['code' => 'REWARD-AAAA', 'tries' => 1], $this->session->data[Cart::SESSION_WAITING]);

        $this->session->data[Cart::SESSION_WAITING]['tries'] = 30;
        $this->collectTotals();
        $this->assertArrayNotHasKey(Cart::SESSION_WAITING, $this->session->data);
    }

    public function testACartThatHasACodeKeepsIt(): void
    {
        $this->session->data[Cart::SESSION_WAITING] = ['code' => 'REWARD-AAAA', 'tries' => 0];
        $this->quote->lines = [new QuoteItemDouble(10)];
        $this->quote->coupon = 'MERCHANT5';

        $this->collectTotals();

        $this->assertSame('MERCHANT5', $this->quote->coupon);
        $this->assertSame(['code' => 'REWARD-AAAA', 'tries' => 0], $this->session->data[Cart::SESSION_WAITING]);
    }

    public function testAnotherCartThanTheShoppersIsLeftAlone(): void
    {
        $this->session->data[Cart::SESSION_WAITING] = ['code' => 'REWARD-AAAA', 'tries' => 0];
        $other = new QuoteDouble();
        $other->quoteId = 99;
        $other->lines = [new QuoteItemDouble(10)];

        $this->collectTotals($other);

        $this->assertSame('', $other->coupon);
    }

    private function collectTotals(?QuoteDouble $quote = null): void
    {
        $quote = $quote ?? $this->quote;
        $cart = $this->createStub(Cart::class);
        $cart->method('remember')->willReturnCallback(function ($code) {
            $this->remembered[] = $code;
        });
        static $observers = [];
        $key = spl_object_id($this);
        $observer = $observers[$key] ??= new ApplyWaitingCode($this->session, $cart);
        $observer->execute($this->event('sales_quote_collect_totals_before', $quote));
        $quote->collectTotals();
        $observer->execute($this->event('sales_quote_collect_totals_after', $quote));
    }

    private function event(string $name, QuoteDouble $quote): Observer
    {
        $event = new Event(['quote' => $quote]);
        $event->setName($name);
        return (new Observer())->setEvent($event);
    }
}
