<?php

namespace Bluebarry\Bluebarry\Cron;

use Bluebarry\Bluebarry\Model\Storefront;

/**
 * Every 10 minutes: reads each connected website's settings from bluebarry (one small request per
 * website), for when bluebarry could not reach this store to say they changed.
 */
class RefreshStorefront
{
    /**
     * @var Storefront
     */
    private $storefront;

    /**
     * @param Storefront $storefront
     */
    public function __construct(Storefront $storefront)
    {
        $this->storefront = $storefront;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $this->storefront->refreshAll();
    }
}
