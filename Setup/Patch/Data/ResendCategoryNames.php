<?php

namespace Bluebarry\Bluebarry\Setup\Patch\Data;

use Bluebarry\Bluebarry\Model\Catalog\Sync;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Since 2.1 a category that shares its name with another goes by its path ("Men / Tops"). The
 * category list in bluebarry changes on the next cron run; this has the same run queue every product,
 * like the nightly full sync, so the products name their categories the same way. Runs once.
 */
class ResendCategoryNames implements DataPatchInterface
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
