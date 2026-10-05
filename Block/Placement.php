<?php

namespace Bluebarry\Bluebarry\Block;

use Bluebarry\Bluebarry\Model\Catalog\PageProduct;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Storefront;
use Bluebarry\Bluebarry\Model\Visitor;
use Magento\Catalog\Model\Product;
use Magento\Csp\Helper\CspNonceProvider;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * The bluebarry elements on the store's pages: the product check button, product chat and
 * recommendation blocks. What shows where is switched on in bluebarry (Integrations > Magento > On
 * your store); the module keeps that (Model\Storefront) and prints each element from a layout block,
 * so a shopper's page never waits for bluebarry. The markup is the same the Shopify theme blocks
 * print, so the SDK picks it up unchanged. Widgets (Block\Widget) put the same elements anywhere else.
 *
 * Only what the page's address decides is printed, so a page from the full page cache is right for
 * every shopper: what is in the cart comes from the shopper's own customer data, in the SDK. The
 * pages carry the settings' cache tag through the head block (Block\Advisor), which is on all of them.
 */
class Placement extends Template
{
    public const DEFAULT_TEXT = 'Is this right for me?';

    /**
     * @var Storefront
     */
    protected $storefront;

    /**
     * @var PageProduct
     */
    protected $pageProduct;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @var CspNonceProvider
     */
    protected $cspNonceProvider;

    /**
     * @param Context $context
     * @param Storefront $storefront
     * @param PageProduct $pageProduct
     * @param Config $config
     * @param Registry $registry
     * @param CspNonceProvider $cspNonceProvider
     * @param array $data
     */
    public function __construct(
        Context $context,
        Storefront $storefront,
        PageProduct $pageProduct,
        Config $config,
        Registry $registry,
        CspNonceProvider $cspNonceProvider,
        array $data = []
    ) {
        $this->storefront = $storefront;
        $this->pageProduct = $pageProduct;
        $this->config = $config;
        $this->registry = $registry;
        $this->cspNonceProvider = $cspNonceProvider;
        parent::__construct($context, $data);
    }

    /**
     * An element Studio switched on for this store view's website, or null while it is off.
     *
     * @param string $name
     * @return array|null
     */
    public function getPlacement(string $name): ?array
    {
        $tenantId = $this->tenantId();
        return $tenantId === null ? null : $this->storefront->placement($this->websiteId(), $tenantId, $name);
    }

    /**
     * The product check button of the product this page is for: where it leads and what it says, or
     * null for a product without a product check quiz. The link opens the quiz for the variant the
     * page opens with.
     *
     * @param string $text the button's own text; empty for the one set in Studio, then the default
     * @return array{href: string, text: string}|null
     */
    public function getProductCheck(string $text = ''): ?array
    {
        $product = $this->product();
        $tenantId = $this->tenantId();
        if ($product === null || $tenantId === null) {
            return null;
        }
        $reference = $this->pageProduct->defaultReference($product);
        if ($reference === null) {
            return null;
        }
        // The product as bluebarry groups it: the quiz is assigned to that one.
        $group = $this->pageProduct->groupId($product) ?? (int) $product->getId();
        $quiz = $this->storefront->productCheck($this->websiteId(), $tenantId, (string) $group);
        if ($quiz === null) {
            return null;
        }
        $text = trim($text);
        if ($text === '') {
            $text = (string) ($this->getPlacement('productCheck')['buttonText'] ?? '');
        }
        return ['href' => '#bluebarry:' . $quiz . '/' . $reference, 'text' => $text !== '' ? $text : self::DEFAULT_TEXT];
    }

    /**
     * The attributes of a recommendation block's element. On a product page it recommends from that
     * product (all its variants) and leads with the variant the page opens with; anywhere else from
     * the cart, which the SDK fills in from the shopper's own cart and keeps current: until then, and
     * while the cart is empty, the element stays hidden.
     *
     * @param string|null $blockId
     * @return array<string, string>|null null without a block to show
     */
    public function getRecommendationAttributes(?string $blockId): ?array
    {
        if (!Visitor::isUuid($blockId) || $this->tenantId() === null) {
            return null;
        }
        $attributes = ['data-bluebarry-recommendations' => strtolower((string) $blockId)];
        $product = $this->product();
        $references = $product === null ? [] : $this->pageProduct->references($product);
        if ($references) {
            $attributes['data-product-ids'] = implode(',', $references);
            $anchor = $this->pageProduct->defaultReference($product);
            if ($anchor !== null) {
                $attributes['data-anchor-id'] = $anchor;
            }
        } else {
            $attributes['data-cart-anchored'] = 'true';
            $attributes['hidden'] = 'hidden';
        }
        return $attributes;
    }

    /**
     * Whether bluebarry is set up for this store view: without it, no element shows.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->tenantId() !== null;
    }

    /**
     * A quiz's id as the SDK takes it, or null for anything else.
     *
     * @param mixed $quiz
     * @return string|null
     */
    public function quizId($quiz): ?string
    {
        return $this->tenantId() !== null && is_string($quiz) && Visitor::isUuid(trim($quiz)) ? strtolower(trim($quiz)) : null;
    }

    /**
     * The nonce an inline script needs under Magento's Content Security Policy.
     *
     * @return string
     */
    public function getNonce(): string
    {
        return $this->cspNonceProvider->generateNonce();
    }

    /**
     * The product this page is for, or null on any other page.
     *
     * @return Product|null
     */
    protected function product(): ?Product
    {
        $product = $this->registry->registry('current_product');
        return $product instanceof Product && $product->getId() ? $product : null;
    }

    /**
     * The Tenant ID of this store view, or null while bluebarry is not set up for it.
     *
     * @return string|null
     */
    protected function tenantId(): ?string
    {
        return $this->config->getTenantId($this->_storeManager->getStore()->getId());
    }

    /**
     * @return int
     */
    protected function websiteId(): int
    {
        return (int) $this->_storeManager->getStore()->getWebsiteId();
    }
}
