<?php

namespace Bluebarry\Bluebarry\Setup;

use Bluebarry\Bluebarry\Model\Heartbeat;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderVisitor;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * bin/magento module:uninstall --remove-data: tells bluebarry the store is gone, so Studio stops showing
 * it as connected, then removes what the module stored. Telling bluebarry is best effort; a module
 * removed by other means stops reporting in and drops off after a week.
 */
class Uninstall implements UninstallInterface
{
    /**
     * The module's own tables; Magento leaves declarative schema tables behind on uninstall.
     */
    private const TABLES = [OrderVisitor::TABLE];

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

        $connection = $setup->getConnection();
        foreach (self::TABLES as $table) {
            $connection->dropTable($setup->getTable($table));
        }
        $connection->delete($setup->getTable('flag'), ['flag_code LIKE ?' => 'bluebarry\_%']);
        $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => 'bluebarry\_module/%']);
    }
}
