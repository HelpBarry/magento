<?php

namespace Bluebarry\Bluebarry\Observer;

use Bluebarry\Bluebarry\Model\Catalog\Categories;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * A category was saved, moved or deleted: noted (one flag write), and the cron sends bluebarry the
 * categories.
 */
class NoteChangedCategories implements ObserverInterface
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
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            $this->categories->changed();
        } catch (\Exception $e) {
            // Never in the way of a category save; the daily look catches up.
            return;
        }
    }
}
