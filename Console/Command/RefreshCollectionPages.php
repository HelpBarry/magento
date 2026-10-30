<?php

namespace Bluebarry\Bluebarry\Console\Command;

use Bluebarry\Bluebarry\Model\CollectionPages;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento bluebarry:collection-pages:refresh: reads the list and fetches every page now, for support and tests.
 */
class RefreshCollectionPages extends Command
{
    /**
     * @var CollectionPages
     */
    private $pages;

    /**
     * @var State
     */
    private $state;

    /**
     * @param CollectionPages $pages proxied in di.xml: every bin/magento call builds the command list
     * @param State $state
     */
    public function __construct(CollectionPages $pages, State $state)
    {
        $this->pages = $pages;
        $this->state = $state;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('bluebarry:collection-pages:refresh')
            ->setDescription('Fetch the collection pages shown on category pages from bluebarry now');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_CRONTAB);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area already set.
        }
        $this->pages->markDue();
        $result = $this->pages->run();
        $output->writeln(sprintf('Pages fetched: %d. Changed: %d.', $result['fetched'], $result['changed']));
        return 0;
    }
}
