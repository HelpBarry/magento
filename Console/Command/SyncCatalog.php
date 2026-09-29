<?php

namespace Bluebarry\Bluebarry\Console\Command;

use Bluebarry\Bluebarry\Model\Catalog\Sync;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento bluebarry:catalog:sync [--all]: sends the queued product changes now, or the whole
 * catalog with --all. The cron does the same every minute.
 */
class SyncCatalog extends Command
{
    /**
     * @var Sync
     */
    private $sync;

    /**
     * @var State
     */
    private $state;

    /**
     * @param Sync $sync proxied in di.xml: every bin/magento call builds the command list
     * @param State $state
     */
    public function __construct(Sync $sync, State $state)
    {
        $this->sync = $sync;
        $this->state = $state;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('bluebarry:catalog:sync')
            ->setDescription('Send the changed products to bluebarry now.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Send the whole catalog.');
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
        if (!$this->sync->targets()) {
            $output->writeln('<error>No website is connected to bluebarry: enter a Tenant ID and API key first.</error>');
            return 1;
        }
        if ($input->getOption('all')) {
            $this->sync->queueAll();
        }
        $result = $this->sync->run();
        if (!empty($result['busy'])) {
            $output->writeln(sprintf('<error>The cron is sending the catalog right now; it sends the %d waiting products.</error>', $result['queued']));
            return 1;
        }
        $status = $this->sync->status();
        $output->writeln(sprintf('Sent %d products; %d waiting.', $result['sent'], $result['queued']));
        // A company that failed stays failed while the others emptied the queue.
        if ($status['error'] !== null) {
            $output->writeln('<error>' . $status['error'] . '</error>');
            return 1;
        }
        return 0;
    }
}
