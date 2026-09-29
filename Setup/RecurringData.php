<?php

namespace Bluebarry\Bluebarry\Setup;

use Bluebarry\Bluebarry\Model\Heartbeat;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

/**
 * After every setup:upgrade: the next cron run tells bluebarry the module's new version. No request
 * to bluebarry during the deploy itself.
 */
class RecurringData implements InstallDataInterface
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
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $this->heartbeat->forceNext();
    }
}
