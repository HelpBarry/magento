<?php

namespace Bluebarry\Bluebarry\Test\Unit\Plugin\MysqlMq;

use Bluebarry\Bluebarry\Plugin\MysqlMq\RouteConversionToQueue;
use Magento\MysqlMq\Model\QueueManagement;
use PHPUnit\Framework\TestCase;

class RouteConversionToQueueTest extends TestCase
{
    private RouteConversionToQueue $plugin;
    private QueueManagement $queueManagement;

    protected function setUp(): void
    {
        $this->plugin = new RouteConversionToQueue();
        $this->queueManagement = $this->createStub(QueueManagement::class);
    }

    /** The wildcard binding routes nothing on the MySQL queue; the plugin supplies the queue. */
    public function testRoutesConversionTopicToItsQueue(): void
    {
        [, , $queues] = $this->plugin->beforeAddMessageToQueues($this->queueManagement, 'bluebarry.conversion.process', '{}', []);

        $this->assertSame(['bluebarry_conversion_process'], $queues);
    }

    public function testDoesNotDuplicateTheQueue(): void
    {
        [, , $queues] = $this->plugin->beforeAddMessageToQueues(
            $this->queueManagement,
            'bluebarry.conversion.process',
            '{}',
            ['bluebarry_conversion_process']
        );

        $this->assertSame(['bluebarry_conversion_process'], $queues);
    }

    public function testLeavesOtherTopicsAlone(): void
    {
        $this->assertSame(
            ['product_action_attribute.update', '{}', ['product_action_attribute.update']],
            $this->plugin->beforeAddMessageToQueues(
                $this->queueManagement,
                'product_action_attribute.update',
                '{}',
                ['product_action_attribute.update']
            )
        );
    }
}
