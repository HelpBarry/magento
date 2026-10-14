<?php

namespace Bluebarry\Bluebarry\Test\Unit\Setup\Patch\Data;

use Bluebarry\Bluebarry\Model\Catalog\Sync;
use Bluebarry\Bluebarry\Setup\Patch\Data\ResendCategoryNames;
use PHPUnit\Framework\TestCase;

class ResendCategoryNamesTest extends TestCase
{
    public function testTheUpgradeHasTheNextCronRunResendTheProducts_WithoutSendingAnythingItself(): void
    {
        $sync = $this->createMock(Sync::class);
        $sync->expects($this->once())->method('queueAllOnNextRun');
        $sync->expects($this->never())->method('run');
        $sync->expects($this->never())->method('queueAll');

        (new ResendCategoryNames($sync))->apply();
    }
}
