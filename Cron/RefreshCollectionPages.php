<?php

namespace Bluebarry\Bluebarry\Cron;

use Bluebarry\Bluebarry\Model\CollectionPages;

/**
 * Every minute: fetches the collection pages that are due. One indexed query when none are.
 */
class RefreshCollectionPages
{
    /**
     * @var CollectionPages
     */
    private $pages;

    /**
     * @param CollectionPages $pages
     */
    public function __construct(CollectionPages $pages)
    {
        $this->pages = $pages;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $this->pages->run();
    }
}
