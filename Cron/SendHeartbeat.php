<?php

namespace Bluebarry\Bluebarry\Cron;

use Bluebarry\Bluebarry\Model\Heartbeat;

/**
 * Every ten minutes: pings the websites whose last heartbeat is a day old, and every website after a
 * module upgrade. Reads one flag when nothing is due.
 */
class SendHeartbeat
{
    /**
     * @var Heartbeat
     */
    private $heartbeat;

    /**
     * @param Heartbeat $heartbeat
     */
    public function __construct(Heartbeat $heartbeat)
    {
        $this->heartbeat = $heartbeat;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $this->heartbeat->sendDue();
    }
}
