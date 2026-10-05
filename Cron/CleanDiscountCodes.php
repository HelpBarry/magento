<?php

namespace Bluebarry\Bluebarry\Cron;

use Bluebarry\Bluebarry\Model\Discount\Coupons;

/**
 * Every five minutes: bluebarry's coupon codes whose last moment has passed are removed, which is
 * what ends them (Magento does not check a coupon's own expiry date), and offer rules without codes
 * go with them. Three small indexed queries when there is nothing to do.
 */
class CleanDiscountCodes
{
    /**
     * @var Coupons
     */
    private $coupons;

    /**
     * @param Coupons $coupons
     */
    public function __construct(Coupons $coupons)
    {
        $this->coupons = $coupons;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $this->coupons->cleanUp();
    }
}
