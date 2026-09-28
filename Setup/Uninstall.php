<?php

namespace Bluebarry\Bluebarry\Setup;

use Bluebarry\Bluebarry\Model\Heartbeat;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * bin/magento module:uninstall: tells bluebarry the store is gone, so Studio stops showing it as
 * connected. Best effort; a module removed by other means stops reporting in and drops off after a week.
 */
class Uninstall implements UninstallInterface
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
     * @inheritdoc
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        try {
            $this->heartbeat->deactivateAll();
        } catch (\Exception $e) {
            // Never block the uninstall.
        }
    }
}
