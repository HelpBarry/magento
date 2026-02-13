<?php

namespace Bluebarry\Bluebarry\Cron;

use Magento\Framework\MessageQueue\ConsumerFactory;
use Psr\Log\LoggerInterface;

/**
 * Cron job to process the Bluebarry conversion queue
 */
class ProcessQueue
{
    /**
     * @var ConsumerFactory
     */
    private $consumerFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ConsumerFactory $consumerFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        ConsumerFactory $consumerFactory,
        LoggerInterface $logger
    ) {
        $this->consumerFactory = $consumerFactory;
        $this->logger = $logger;
    }

    /**
     * Execute cron job to process queue messages
     *
     * @return void
     */
    public function execute()
    {
        $this->logger->info('Bluebarry queue processing cron job started at ' . date('Y-m-d H:i:s'));
        
        try {
            $consumer = $this->consumerFactory->get('BluebarryConversionProcess');
            $consumer->process(1000);
            $this->logger->info('Bluebarry queue processing cron job completed successfully at ' . date('Y-m-d H:i:s'));
        } catch (\Exception $e) {
            $this->logger->error('Bluebarry queue processing error: ' . $e->getMessage());
        }
    }
}
