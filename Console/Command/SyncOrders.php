<?php

namespace Bluebarry\Bluebarry\Console\Command;

use Bluebarry\Bluebarry\Model\Orders\Sync;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento bluebarry:orders:sync [--import]: asks bluebarry for its tasks and sends waiting orders
 * and checkouts now, or with --import the last 365 days of every connected website's orders first. The
 * cron does the same; bluebarry starts the import from its Orders page.
 */
class SyncOrders extends Command
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
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param Sync $sync proxied in di.xml: every bin/magento call builds the command list
     * @param State $state
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(Sync $sync, State $state, StoreManagerInterface $storeManager)
    {
        $this->sync = $sync;
        $this->state = $state;
        $this->storeManager = $storeManager;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('bluebarry:orders:sync')
            ->setDescription('Send the waiting orders and checkouts to bluebarry now.')
            ->addOption('import', null, InputOption::VALUE_NONE, 'Import the last 365 days of orders first.');
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
        if ($input->getOption('import')) {
            foreach ($this->storeManager->getWebsites() as $website) {
                $this->sync->startImport((int) $website->getId(), time() - 365 * 86400);
            }
        }
        $this->sync->pollTasks();
        $result = $this->sync->run();
        $output->writeln(sprintf('Sent %d orders, imported %d, sent %d checkouts.', $result['orders'], $result['imported'], $result['checkouts']));
        return 0;
    }
}
