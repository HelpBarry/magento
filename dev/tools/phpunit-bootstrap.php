<?php
// Magento's unit test bootstrap, with the module resolved from the working tree (/module) rather than
// the copy Composer installed into vendor/, so tests run against the code being edited.
require '/var/www/html/dev/tests/unit/framework/bootstrap.php';

$loader = require '/var/www/html/vendor/autoload.php';
$loader->addPsr4('Bluebarry\\Bluebarry\\', '/module/', true);
