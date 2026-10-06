<?php

namespace Bluebarry\Bluebarry\Plugin\Checkout;

use Bluebarry\Bluebarry\Model\Config;
use Magento\Checkout\CustomerData\Cart;
use Magento\Checkout\Model\Session;
use Magento\Store\Model\StoreManagerInterface;

/**
 * What is in the cart, as the catalog sync names it (the variant for a configurable product), added
 * to the shopper's own cart data: recommendation blocks leave it out and recommend from it. It
 * travels with the cart section Magento already loads after the cart changed, so no page and no
 * request is added for it, and pages from the full page cache never hold it.
 */
class CartReferences
{
    public const KEY = 'bluebarry_references';

    /** More lines than this is not a shopper's cart; the SDK only needs what to leave out. */
    private const MAX = 100;

    /**
     * @var Session
     */
    private $checkoutSession;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param Session $checkoutSession
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(Session $checkoutSession, Config $config, StoreManagerInterface $storeManager)
    {
        $this->checkoutSession = $checkoutSession;
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    /**
     * @param Cart $subject
     * @param array $result
     * @return array
     */
    public function afterGetSectionData(Cart $subject, $result)
    {
        try {
            if (!is_array($result) || $this->config->getTenantId($this->storeManager->getStore()->getId()) === null) {
                return $result;
            }
            $references = [];
            // The quote the section just read: its lines are loaded.
            foreach ($this->checkoutSession->getQuote()->getAllVisibleItems() as $item) {
                $variant = $item->getOptionByCode('simple_product');
                $reference = (int) ($variant ? $variant->getValue() : $item->getProductId());
                if ($reference > 0) {
                    $references[(string) $reference] = true;
                }
                if (count($references) >= self::MAX) {
                    break;
                }
            }
            $result[self::KEY] = array_map('strval', array_keys($references));
        } catch (\Exception $e) {
            // Never in the way of the mini-cart.
            return $result;
        }
        return $result;
    }
}
