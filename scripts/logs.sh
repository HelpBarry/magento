#!/bin/bash

CONTAINER="magento-phpfpm-1"
MAGENTO_ROOT="/var/www/html"
LOG_DIR="$MAGENTO_ROOT/var/log"

# Default to tail mode, use -f for follow
FOLLOW=""
if [[ "$1" == "-f" ]]; then
    FOLLOW="-f"
    shift
fi

# Show Bluebarry-specific logs and general Magento logs
echo "==> Checking for Bluebarry logs..."

# Check if bluebarry.log exists
if docker exec $CONTAINER test -f $LOG_DIR/bluebarry.log 2>/dev/null; then
    echo "==> Bluebarry log:"
    docker exec $CONTAINER tail $FOLLOW -n 100 $LOG_DIR/bluebarry.log
fi

echo ""
echo "==> Debug log (last 100 lines):"
docker exec $CONTAINER tail $FOLLOW -n 100 $LOG_DIR/debug.log 2>/dev/null || echo "No debug.log found"

# Also show system.log and exception.log for debugging
echo ""
echo "==> System log (last 50 lines):"
docker exec $CONTAINER tail $FOLLOW -n 50 $LOG_DIR/system.log 2>/dev/null || echo "No system.log found"

echo ""
echo "==> Exception log (last 50 lines):"
docker exec $CONTAINER tail $FOLLOW -n 50 $LOG_DIR/exception.log 2>/dev/null || echo "No exception.log found"
