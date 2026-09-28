<?php

namespace Bluebarry\Bluebarry\Cron;

use Bluebarry\Bluebarry\Model\Conversion\Sender;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderVisitor;

/**
 * Every minute: conversions that are due, meaning retries after a failed delivery, and paid orders no
 * queue consumer picked up (consumers that don't run are common on real hosts). One indexed query when
 * there is nothing to do.
 */
class SendDueConversions
{
    private const BATCH = 50;

    /**
     * @var OrderVisitor
     */
    private $visitors;

    /**
     * @var Sender
     */
    private $sender;

    /**
     * @param OrderVisitor $visitors
     * @param Sender $sender
     */
    public function __construct(OrderVisitor $visitors, Sender $sender)
    {
        $this->visitors = $visitors;
        $this->sender = $sender;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->visitors->due(self::BATCH) as $orderId) {
            if ($this->visitors->claimDue($orderId)) {
                $this->sender->send($orderId);
            }
        }
    }
}
