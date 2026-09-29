<?php

namespace Bluebarry\Bluebarry\Console\Command;

use Bluebarry\Bluebarry\Cron\SendDueConversions as SendDueConversionsJob;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento bluebarry:conversions:send-due: runs the cron's job once, for support and tests.
 */
class SendDueConversions extends Command
{
    /**
     * @var SendDueConversionsJob
     */
    private $job;

    /**
     * @var State
     */
    private $state;

    /**
     * @param SendDueConversionsJob $job proxied in di.xml: every bin/magento call builds the command list
     * @param State $state
     */
    public function __construct(SendDueConversionsJob $job, State $state)
    {
        $this->job = $job;
        $this->state = $state;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('bluebarry:conversions:send-due')
            ->setDescription('Send the bluebarry conversions that are due now (retries, and paid orders no queue consumer picked up).');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->state->setAreaCode(Area::AREA_CRONTAB);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area already set.
        }
        $this->job->execute();
        return 0;
    }
}
