<?php

namespace Bluebarry\Bluebarry\Block;

use Bluebarry\Bluebarry\Model\CollectionPages;
use Magento\Csp\Helper\CspNonceProvider;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Element\Context;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Prints a category's collection page as bluebarry rendered it and the module keeps it. The same markup
 * a Shopify theme gets; the SDK takes over the grid in place.
 */
class CollectionPage extends AbstractBlock implements IdentityInterface
{
    /**
     * @var CollectionPages
     */
    private $pages;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var CspNonceProvider
     */
    private $cspNonceProvider;

    /**
     * @param Context $context
     * @param CollectionPages $pages
     * @param Registry $registry
     * @param StoreManagerInterface $storeManager
     * @param CspNonceProvider $cspNonceProvider
     * @param array $data
     */
    public function __construct(
        Context $context,
        CollectionPages $pages,
        Registry $registry,
        StoreManagerInterface $storeManager,
        CspNonceProvider $cspNonceProvider,
        array $data = []
    ) {
        $this->pages = $pages;
        $this->registry = $registry;
        $this->storeManager = $storeManager;
        $this->cspNonceProvider = $cspNonceProvider;
        parent::__construct($context, $data);
    }

    /**
     * @return string[]
     */
    public function getIdentities()
    {
        $category = $this->categoryId();
        // The category's own tag too: Magento's product list, which carries it, is gone from this page.
        return [CollectionPages::cacheTag((int) $this->storeManager->getStore()->getId(), $category), 'cat_c_' . $category];
    }

    /**
     * @return string
     */
    protected function _toHtml()
    {
        $page = $this->pages->page((int) $this->storeManager->getStore()->getId(), $this->categoryId());
        if ($page === null) {
            return '';
        }
        $css = $page['css'] === '' ? '' : '<style>' . $page['css'] . '</style>';
        return $css . self::withNonce($page['html'], $this->cspNonceProvider->generateNonce());
    }

    /**
     * Its scripts run under Magento's Content Security Policy like the module's own; data blocks (a type of their own) are not scripts.
     *
     * @param string $html
     * @param string $nonce
     * @return string
     */
    public static function withNonce(string $html, string $nonce): string
    {
        return (string) preg_replace('/<script(?![^>]*\btype=)/i', '<script nonce="' . $nonce . '"', $html);
    }

    /**
     * @return int
     */
    private function categoryId(): int
    {
        $category = $this->registry->registry('current_category');
        return $category ? (int) $category->getId() : 0;
    }
}
