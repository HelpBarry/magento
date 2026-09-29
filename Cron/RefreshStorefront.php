<?php

namespace Bluebarry\Bluebarry\Cron;

use Bluebarry\Bluebarry\Model\Orders\Sync as OrderSync;
use Bluebarry\Bluebarry\Model\Storefront;

/**
 * Every 10 minutes: reads each connected website's settings and tasks from bluebarry (two small
 * requests per website), for when bluebarry could not reach this store to say they changed, or to
 * start its order history import.
 */
class RefreshStorefront
{
    /**
     * @var Storefront
     */
    private $storefront;

    /**
     * @var OrderSync
     */
    private $orders;

    /**
     * @param Storefront $storefront
     * @param OrderSync $orders
     */
    public function __construct(Storefront $storefront, OrderSync $orders)
    {
        $this->storefront = $storefront;
        $this->orders = $orders;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $this->storefront->refreshAll();
        $this->orders->pollTasks();
    }
}
