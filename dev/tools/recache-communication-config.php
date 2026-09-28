<?php
/**
 * Plays the "still-live old release" during a zero-downtime deploy: loads the queue communication
 * config, which re-populates the shared cache with the OLD release's topics whenever the new release's
 * setup:upgrade has just flushed it. Cron and queue consumers of the live release do this in prod.
 * One round per process (like each cron / consumer start); test-deploy-race runs it in a loop from the
 * old release root.
 */

use Magento\Framework\App\Bootstrap;
use Magento\Framework\Communication\Config\Data as CommunicationConfigData;

require getcwd() . '/app/bootstrap.php';

try {
    Bootstrap::create(BP, $_SERVER)->getObjectManager()->create(CommunicationConfigData::class)->get();
} catch (\Throwable $e) {
    // The database may be mid-upgrade; the next round tries again.
}
