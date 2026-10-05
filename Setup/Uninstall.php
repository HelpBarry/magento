<?php

namespace Bluebarry\Bluebarry\Setup;

use Bluebarry\Bluebarry\Model\Discount\Coupons;
use Bluebarry\Bluebarry\Model\Heartbeat;
use Bluebarry\Bluebarry\Model\ResourceModel\CheckoutNotes;
use Bluebarry\Bluebarry\Model\ResourceModel\DiscountRules;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderSyncQueue;
use Bluebarry\Bluebarry\Model\ResourceModel\OrderVisitor;
use Bluebarry\Bluebarry\Model\ResourceModel\ProductSyncQueue;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * bin/magento module:uninstall --remove-data: tells bluebarry the store is gone, so Studio stops showing
 * it as connected, then removes what the module stored and the cart price rules it made. Telling bluebarry is best effort; a module
 * removed by other means stops reporting in and drops off after a week.
 */
class Uninstall implements UninstallInterface
{
    /**
     * The module's own tables; Magento leaves declarative schema tables behind on uninstall.
     */
    private const TABLES = [
        OrderVisitor::TABLE, ProductSyncQueue::TABLE, OrderSyncQueue::TABLE, CheckoutNotes::TABLE,
        DiscountRules::TABLE, DiscountRules::CODES_TABLE,
    ];

    /**
     * @var Heartbeat
     */
    private $heartbeat;

    /**
     * @var Coupons
     */
    private $coupons;

    /**
     * @param Heartbeat $heartbeat
     * @param Coupons $coupons
     */
    public function __construct(Heartbeat $heartbeat, Coupons $coupons)
    {
        $this->heartbeat = $heartbeat;
        $this->coupons = $coupons;
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

        try {
            // bluebarry's cart price rules and their codes: nothing would end the codes any more.
            $this->coupons->deleteAll();
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
