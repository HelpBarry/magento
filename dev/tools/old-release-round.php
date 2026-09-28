<?php
/**
 * Plays the "still-live old release" during a zero-downtime deploy: one process start of the previous
 * release (a cron run or queue consumer), loading the configuration such processes load. Whatever is
 * missing from the shared cache (e.g. right after the new release flushed it) is re-cached from the OLD
 * release's code, without this module. test-deploy-race runs it in a loop from the old release root.
 * Exits non-zero when the round failed (expected now and then while the database is mid-upgrade), so the
 * caller counts only rounds that really loaded the config.
 */

use Magento\Framework\App\Bootstrap;
use Magento\Framework\Communication\Config\Data as CommunicationConfigData;
use Magento\Framework\Event\ConfigInterface as EventConfig;

require getcwd() . '/app/bootstrap.php';

try {
    $objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
    // Queue topics (the 1.0.3 incident) and the global event config, where the module's checkout
    // observer is registered.
    $objectManager->create(CommunicationConfigData::class)->get();
    $objectManager->get(EventConfig::class)->getObservers('sales_model_service_quote_submit_success');
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
