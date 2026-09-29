<?php

namespace Bluebarry\Bluebarry\Cron;

use Bluebarry\Bluebarry\Model\Orders\Sync;

/**
 * Every minute, in the module's own cron group: sends paid and changed orders, the history import's
 * next batches and checkouts that gave an email. A few indexed queries when there is nothing to do.
 */
class SyncOrders
{
    private const SECONDS = 50;

    /**
     * @var Sync
     */
    private $sync;

    /**
     * @param Sync $sync
     */
    public function __construct(Sync $sync)
    {
        $this->sync = $sync;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $this->sync->run(self::SECONDS);
    }
}
