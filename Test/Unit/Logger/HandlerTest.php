<?php

namespace Bluebarry\Bluebarry\Test\Unit\Logger;

use Bluebarry\Bluebarry\Logger\Handler;
use Magento\Framework\Filesystem\Driver\File;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class HandlerTest extends TestCase
{
    /** An unwritable log file must not turn a log call into an exception (checkout, queue consumer). */
    public function testUnwritableLogFileDoesNotThrow(): void
    {
        $handler = new Handler(new File(), '/proc/bluebarry-unwritable/');
        $logger = new Logger('bluebarry', [$handler]);

        $logger->error('conversion failed');

        $this->addToAssertionCount(1);
    }

    public function testWritesToTheModuleLog(): void
    {
        $root = sys_get_temp_dir() . '/bluebarry-handler-' . uniqid() . '/';
        $logger = new Logger('bluebarry', [new Handler(new File(), $root)]);

        $logger->debug('--- Bluebarry request ---');

        $this->assertStringContainsString('--- Bluebarry request ---', (string) file_get_contents($root . 'var/log/bluebarry.log'));
    }
}
