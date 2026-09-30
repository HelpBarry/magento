<?php

namespace Bluebarry\Bluebarry\Test\Unit\Cron;

use Bluebarry\Bluebarry\Cron\SendDueConversions;
use Bluebarry\Bluebarry\Model\Conversion\Sender;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderVisitor;
use PHPUnit\Framework\TestCase;

class SendDueConversionsTest extends TestCase
{
    private array $claimed = [];
    private array $sent = [];

    public function testSendsEveryDueConversionItClaims(): void
    {
        $this->job([7, 8, 9], claimable: [7, 9])->execute();

        $this->assertSame([7, 8, 9], $this->claimed);
        $this->assertSame([7, 9], $this->sent);
    }

    public function testStartsNoDeliveryOnceItsTimeIsUp_TheRestStayDue(): void
    {
        // bluebarry answers slowly: the first delivery takes the run's whole second.
        $this->job([7, 8, 9], claimable: [7, 8, 9], seconds: 1, slow: true)->execute();

        $this->assertSame([7], $this->sent);
        $this->assertSame([7], $this->claimed); // 8 and 9 were not claimed, so they are due next run
    }

    private function job(array $due, array $claimable, int $seconds = 40, bool $slow = false): SendDueConversions
    {
        $visitors = $this->createStub(OrderVisitor::class);
        $visitors->method('due')->willReturn($due);
        $visitors->method('claimDue')->willReturnCallback(function ($orderId) use ($claimable) {
            $this->claimed[] = $orderId;
            return in_array($orderId, $claimable, true);
        });
        $sender = $this->createStub(Sender::class);
        $sender->method('send')->willReturnCallback(function ($orderId) use ($slow) {
            $this->sent[] = $orderId;
            if ($slow) {
                sleep(1);
            }
        });
        return new SendDueConversions($visitors, $sender, $seconds);
    }
}
