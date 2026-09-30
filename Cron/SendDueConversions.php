<?php

namespace Bluebarry\Bluebarry\Cron;

use Bluebarry\Bluebarry\Model\Conversion\Sender;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderVisitor;

/**
 * Every minute, in the module's orders cron group: conversions that are due, meaning retries after a
 * failed delivery, and paid orders no queue consumer picked up (consumers that don't run are common on
 * real hosts). At most 40 seconds a run, so a slow bluebarry holds up no other job for long: the rest
 * stay due for the next run. One indexed query when there is nothing to do.
 */
class SendDueConversions
{
    private const BATCH = 50;
    private const SECONDS = 40;

    /**
     * @var OrderVisitor
     */
    private $visitors;

    /**
     * @var Sender
     */
    private $sender;

    /**
     * @var int
     */
    private $seconds;

    /**
     * @param OrderVisitor $visitors
     * @param Sender $sender
     * @param int $seconds the time a run may take: no new delivery starts after it
     */
    public function __construct(OrderVisitor $visitors, Sender $sender, int $seconds = self::SECONDS)
    {
        $this->visitors = $visitors;
        $this->sender = $sender;
        $this->seconds = $seconds;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $start = time();
        foreach ($this->visitors->due(self::BATCH) as $orderId) {
            if (time() - $start >= $this->seconds) {
                // Not claimed: still due, for the next run.
                break;
            }
            if ($this->visitors->claimDue($orderId)) {
                $this->sender->send($orderId);
            }
        }
    }
}
