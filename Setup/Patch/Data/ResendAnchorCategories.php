<?php

namespace Bluebarry\Bluebarry\Setup\Patch\Data;

use Bluebarry\Bluebarry\Model\Catalog\Sync;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Since 2.2 a product also names the anchor categories above it, as their pages list it. This has
 * the next cron run queue every product, like the nightly full sync. Runs once.
 */
class ResendAnchorCategories implements DataPatchInterface
{
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
     * @inheritdoc
     */
    public function apply()
    {
        $this->sync->queueAllOnNextRun();
        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        return [];
    }
}
