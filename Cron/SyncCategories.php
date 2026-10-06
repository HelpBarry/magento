<?php

namespace Bluebarry\Bluebarry\Cron;

use Bluebarry\Bluebarry\Model\Catalog\Categories;

/**
 * Every five minutes: sends bluebarry the categories when one changed, a company has none yet, or the
 * daily look is due, and only when the set differs from what was last sent. One flag read otherwise.
 */
class SyncCategories
{
    /**
     * @var Categories
     */
    private $categories;

    /**
     * @param Categories $categories
     */
    public function __construct(Categories $categories)
    {
        $this->categories = $categories;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $this->categories->run();
    }
}
