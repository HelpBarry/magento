#!/bin/bash

set -e

CONTAINER="magento-phpfpm-1"
MAGENTO_ROOT="/var/www/html"

# Get the directory of the script and go to the parent directory (workspace root)
cd "$(dirname "$0")/.." || exit

echo "==> Creating target directory..."
docker exec $CONTAINER mkdir -p $MAGENTO_ROOT/app/code/Bluebarry/Bluebarry

echo "==> Copying extension files..."
docker cp . $CONTAINER:$MAGENTO_ROOT/app/code/Bluebarry/Bluebarry/

echo "==> Running setup:upgrade..."
docker exec $CONTAINER php $MAGENTO_ROOT/bin/magento setup:upgrade

echo "==> Compiling DI..."
docker exec $CONTAINER php $MAGENTO_ROOT/bin/magento setup:di:compile

echo "==> Flushing cache..."
docker exec $CONTAINER php $MAGENTO_ROOT/bin/magento cache:flush

echo "==> Done!"
