<?php

namespace Bluebarry\Bluebarry\Console\Command;

use Bluebarry\Bluebarry\Model\Discount\Coupons;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento bluebarry:discounts:clean: runs the cron's job once, for support and tests.
 */
class CleanDiscountCodes extends Command
{
    /**
     * @var Coupons
     */
    private $coupons;

    /**
     * @var State
     */
    private $state;

    /**
     * @param Coupons $coupons proxied in di.xml: every bin/magento call builds the command list
     * @param State $state
     */
    public function __construct(Coupons $coupons, State $state)
    {
        $this->coupons = $coupons;
        $this->state = $state;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('bluebarry:discounts:clean')
            ->setDescription('Remove the bluebarry coupon codes that have expired, and offer rules without codes.');
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
        $cleaned = $this->coupons->cleanUp();
        $output->writeln(sprintf('%d expired codes and %d empty offer rules removed.', $cleaned['codes'], $cleaned['rules']));
        return 0;
    }
}
