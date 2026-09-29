<?php

namespace Bluebarry\Bluebarry\Cron;

use Bluebarry\Bluebarry\Model\Catalog\Sync;

/**
 * Every minute, in the module's own cron group: sends the products that changed. One indexed query
 * when nothing did.
 */
class SyncCatalog
{
    /** Stops sending before the next run is due; the rest goes next minute. */
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
