<?php

namespace Bluebarry\Bluebarry\Observer;

use Bluebarry\Bluebarry\Model\CollectionPages;
use Bluebarry\Bluebarry\Model\Config;
use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\DesignInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;

/**
 * A category with a collection page shows it instead of Magento's product list: in the store view whose
 * catalog it shows, in that view's currency, and only on the themes it is known to work on. Everything else
 * stays Magento's own category page. The decision comes from what the address and the page cache's
 * variation already decide (category, store view, currency), so a cached page is right for every shopper.
 */
class ShowCollectionPage implements ObserverInterface
{
    public const HANDLE = 'bluebarry_collection_page';

    /**
     * @var CollectionPages
     */
    private $pages;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var DesignInterface
     */
    private $design;

    /**
     * @var PageConfig
     */
    private $pageConfig;

    /**
     * @param CollectionPages $pages
     * @param Config $config
     * @param Registry $registry
     * @param StoreManagerInterface $storeManager
     * @param DesignInterface $design
     * @param PageConfig $pageConfig
     */
    public function __construct(
        CollectionPages $pages,
        Config $config,
        Registry $registry,
        StoreManagerInterface $storeManager,
        DesignInterface $design,
        PageConfig $pageConfig
    ) {
        $this->pages = $pages;
        $this->config = $config;
        $this->registry = $registry;
        $this->storeManager = $storeManager;
        $this->design = $design;
        $this->pageConfig = $pageConfig;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if ($observer->getEvent()->getData('full_action_name') !== 'catalog_category_view') {
            return;
        }
        try {
            $page = $this->pageToShow();
        } catch (\Exception $e) {
            return; // Magento's own page is always there to show
        }
        if ($page === null) {
            return;
        }
        $update = $observer->getEvent()->getData('layout')->getUpdate();
        $update->addHandle(self::HANDLE);
        if ($page['title']) {
            $update->addHandle(self::HANDLE . '_title');
        }
        if ($page['description']) {
            $update->addHandle(self::HANDLE . '_description');
        }
        // After the controller applied a category's own layout, before the structure is built from it.
        $this->pageConfig->setPageLayout('1column');
    }

    /**
     * @return array{tenant: string, title: bool, description: bool}|null
     */
    private function pageToShow(): ?array
    {
        $category = $this->registry->registry('current_category');
        if (!$category instanceof Category) {
            return null;
        }
        /** @var \Magento\Store\Model\Store $store */
        $store = $this->storeManager->getStore();
        $page = $this->pages->active((int) $store->getId(), (int) $category->getId());
        if ($page === null || strcasecmp($page['tenant'], trim((string) $this->config->getTenantId($store->getId()))) !== 0) {
            return null;
        }
        // What the page shows is in this currency; a shopper who switched sees Magento's page.
        if ($store->getCurrentCurrencyCode() !== $store->getDefaultCurrencyCode()) {
            return null;
        }
        if ($category->getDisplayMode() === Category::DM_PAGE) {
            return null;
        }
        // A category's own layout update may expect the product list.
        $update = $category->getData('custom_layout_update_file');
        if ((is_string($update) && $update !== '' && $update !== '__no_update__') || (string) $category->getData('custom_layout_update') !== '') {
            return null;
        }
        return $this->isSupportedTheme() ? $page : null;
    }

    /**
     * Luma, Blank, Hyvä and what inherits from them: the themes whose category page is known. Another theme may list its products in a block this cannot remove.
     *
     * @return bool
     */
    private function isSupportedTheme(): bool
    {
        for ($theme = $this->design->getDesignTheme(); $theme; $theme = $theme->getParentTheme()) {
            $code = (string) $theme->getCode();
            if ($code === 'Magento/blank' || $code === 'Magento/luma' || strpos($code, 'Hyva/') === 0) {
                return true;
            }
        }
        return false;
    }
}
