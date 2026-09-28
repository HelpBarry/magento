<?php

namespace Bluebarry\Bluebarry\Plugin\MysqlMq;

use Bluebarry\Bluebarry\Observer\ProcessConversion;
use Magento\MysqlMq\Model\QueueManagement;

/**
 * Routes conversion messages to the conversion queue on shops that use the MySQL queue.
 *
 * The module binds its queue with a wildcard topic (see etc/queue_topology.xml, needed for first-install
 * deploys over RabbitMQ). RabbitMQ matches wildcards; the MySQL queue only routes a message to bindings
 * with exactly its topic, so without this the message would be stored in no queue and lost.
 */
class RouteConversionToQueue
{
    public const QUEUE_NAME = 'bluebarry_conversion_process';

    /**
     * @param QueueManagement $subject
     * @param string $topic
     * @param string $message
     * @param string[] $queueNames
     * @return array
     */
    public function beforeAddMessageToQueues(QueueManagement $subject, $topic, $message, $queueNames)
    {
        if ($topic === ProcessConversion::TOPIC_NAME && !in_array(self::QUEUE_NAME, $queueNames, true)) {
            $queueNames[] = self::QUEUE_NAME;
        }
        return [$topic, $message, $queueNames];
    }
}
