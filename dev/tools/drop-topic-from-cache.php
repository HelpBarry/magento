<?php
/**
 * Simulates the stale-cache state a still-running old release leaves behind after a first-install
 * deploy: the cached communication config no longer contains the module's topic.
 */

use Magento\Framework\App\Bootstrap;
use Magento\Framework\Communication\ConfigInterface;
use Magento\Framework\Config\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;

require getcwd() . '/app/bootstrap.php';

$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(ConfigInterface::class)->getTopics(); // make sure the cache entry exists
$cache = $om->get(CacheInterface::class);
$serializer = $om->get(SerializerInterface::class);
$data = $serializer->unserialize($cache->load('communication_config_cache'));
unset($data['topics'][$argv[1] ?? 'bluebarry.conversion.process']);
$cache->save($serializer->serialize($data), 'communication_config_cache');
echo "dropped topic from cached communication config\n";
