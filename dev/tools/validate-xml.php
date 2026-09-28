<?php
/**
 * Validates the module's XML config against the XSDs of the installed Magento version.
 * Magento skips schema validation in production mode, so a broken file can deploy fine and then
 * misbehave at runtime; this catches it up front. Usage: php validate-xml.php /module
 */

use Magento\Framework\Config\Dom\UrnResolver;

require '/var/www/html/vendor/autoload.php';

$moduleDir = rtrim($argv[1] ?? '/module', '/');
$resolver = new UrnResolver();
// Magento XSDs include each other by URN; resolve those the way Magento's own validator does.
libxml_set_external_entity_loader([$resolver, 'registerEntityLoader']);
$failures = 0;

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$moduleDir/etc", FilesystemIterator::SKIP_DOTS));
$files = array_merge(iterator_to_array($files), iterator_to_array(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$moduleDir/view", FilesystemIterator::SKIP_DOTS))
));

libxml_use_internal_errors(true);
foreach ($files as $file) {
    if ($file->getExtension() !== 'xml') {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($moduleDir) + 1);
    $dom = new DOMDocument();
    if (!$dom->load($file->getPathname())) {
        echo "FAIL $relative: not well-formed\n";
        $failures++;
        continue;
    }
    $urn = $dom->documentElement->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'noNamespaceSchemaLocation');
    if (!$urn) {
        echo "WARN $relative: no xsi:noNamespaceSchemaLocation, not validated\n";
        continue;
    }
    try {
        $xsd = $resolver->getRealPath($urn);
    } catch (\Exception $e) {
        echo "FAIL $relative: cannot resolve $urn ({$e->getMessage()})\n";
        $failures++;
        continue;
    }
    libxml_clear_errors();
    if ($dom->schemaValidate($xsd)) {
        echo "ok   $relative\n";
    } else {
        foreach (libxml_get_errors() as $error) {
            printf("FAIL %s:%d %s\n", $relative, $error->line, trim($error->message));
        }
        $failures++;
    }
}

exit($failures ? 1 : 0);
