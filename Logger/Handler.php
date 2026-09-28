<?php

namespace Bluebarry\Bluebarry\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

/**
 * Writes the module's log to var/log/bluebarry.log at every level. Magento's own debug.log is off in
 * production mode (dev/debug/debug_logging), which made the admin debug setting a no-op on live shops;
 * whether debug lines are written at all is still decided by that admin setting.
 */
class Handler extends Base
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/bluebarry.log';

    /**
     * @var int
     */
    protected $loggerType = Logger::DEBUG;
}
