#!/bin/sh
set -e

# Trust the test CA so server-side calls to https://data.bluebarry.ai reach the mock API.
if [ -f /certs/ca.crt ]; then
    cp /certs/ca.crt /usr/local/share/ca-certificates/bluebarry-test-ca.crt
    update-ca-certificates >/dev/null 2>&1
fi

exec "$@"
