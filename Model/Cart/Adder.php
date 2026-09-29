<?php

namespace Bluebarry\Bluebarry\Model\Cart;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Checkout\Model\Cart;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Puts bluebarry's products in the shopper's cart: what the quiz, the search widget and the
 * recommendation blocks add. A product is named by the id the catalog sync sends, so a configurable
 * product's child is added through its parent with the child's options, the way the product page's
 * own form adds it, and a bundle with its default selections.
 *
 * A kit is added line by line; what could not be added is reported, the rest stays in the cart.
 */
class Adder
{
    public const MAX_LINES = 20;
    private const MAX_QUANTITY = 999;

    /**
     * @var Cart
     */
    private $cart;

    /**
     * @var ProductRepositoryInterface
     */
    private $products;

    /**
     * @var Configurable
     */
    private $configurable;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var EventManager
     */
    private $events;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var ResponseInterface
     */
    private $response;

    /**
     * @param Cart $cart
     * @param ProductRepositoryInterface $products
     * @param Configurable $configurable
     * @param StoreManagerInterface $storeManager
     * @param EventManager $events
     * @param RequestInterface $request
     * @param ResponseInterface $response
     */
    public function __construct(
        Cart $cart,
        ProductRepositoryInterface $products,
        Configurable $configurable,
        StoreManagerInterface $storeManager,
        EventManager $events,
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $this->cart = $cart;
        $this->products = $products;
        $this->configurable = $configurable;
        $this->storeManager = $storeManager;
        $this->events = $events;
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * @param array<int, array{reference: string, quantity: int}> $items
     * @return array{success: bool, skipped: string[], failure?: string}
     */
    public function add(array $items): array
    {
        $skipped = [];
        $added = [];
        $soldOut = false;
        foreach (array_values($items) as $index => $item) {
            $reference = (string) ($item['reference'] ?? '');
            $quantity = (int) ($item['quantity'] ?? 1);
            // Beyond the limits nothing is guessed: the line is reported as not added.
            if ($index >= self::MAX_LINES || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
                $skipped[] = $reference;
                continue;
            }
            try {
                [$product, $request, $referenced] = $this->resolve($reference, $quantity);
            } catch (NoSuchEntityException $e) {
                $skipped[] = $reference;
                continue;
            }
            try {
                $this->cart->addProduct($product, $request);
                $added[] = [$product, $request];
            } catch (LocalizedException $e) {
                // Sold out when Magento says the product cannot be sold; anything else (a required
                // option the shopper has to choose, too many for what is left) is an error.
                $skipped[] = $reference;
                $soldOut = $soldOut || !$referenced->isSalable();
            }
        }
        if (!$added) {
            return ['success' => false, 'skipped' => $skipped, 'failure' => $soldOut ? 'soldOut' : 'error'];
        }
        $this->cart->save();
        foreach ($added as [$product, $buyRequest]) {
            // What the product page's own button dispatches, for extensions that follow cart adds: with
            // this line's own request (product, quantity, options), as that button's form would send it.
            $request = clone $this->request;
            if ($request instanceof \Magento\Framework\App\Request\Http) {
                $request->setParams(['product' => (string) $product->getId()] + $buyRequest);
            }
            $this->events->dispatch('checkout_cart_add_product_complete', [
                'product' => $product, 'request' => $request, 'response' => $this->response,
            ]);
        }
        return ['success' => true, 'skipped' => $skipped];
    }

    /**
     * The product the cart takes, the request that picks the right variant, and the product the
     * reference names.
     *
     * @param string $reference
     * @param int $quantity
     * @return array{0: Product, 1: array, 2: Product}
     * @throws NoSuchEntityException the product is not on sale in this store
     */
    private function resolve(string $reference, int $quantity): array
    {
        if (!ctype_digit($reference)) {
            throw new NoSuchEntityException();
        }
        $store = $this->storeManager->getStore();
        $websiteId = (int) $store->getWebsiteId();
        /** @var Product $product */
        $product = $this->products->getById((int) $reference, false, (int) $store->getId());
        if (!$this->onSale($product, $websiteId)) {
            throw new NoSuchEntityException();
        }
        $request = ['qty' => $quantity];

        // A configurable product's child goes in through the parent it is shown under, like the catalog
        // sync groups it (the lowest parent id on sale here with a page of its own), with the child's
        // options. Also when the child has a page of its own.
        $parentIds = array_map('intval', $this->configurable->getParentIdsByChild($product->getId()));
        sort($parentIds);
        foreach ($parentIds as $parentId) {
            /** @var Product $parent */
            $parent = $this->products->getById($parentId, false, (int) $store->getId());
            if (!$this->onSale($parent, $websiteId) || (int) $parent->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE) {
                continue;
            }
            $options = [];
            foreach ($this->configurable->getConfigurableAttributes($parent) as $attribute) {
                $code = (string) $attribute->getProductAttribute()->getAttributeCode();
                $options[(int) $attribute->getAttributeId()] = $product->getData($code);
            }
            return [$parent, $request + ['super_attribute' => $options], $product];
        }
        if ((int) $product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE) {
            throw new NoSuchEntityException();
        }

        if ($product->getTypeId() === 'bundle') {
            $request += $this->bundleDefaults($product);
        }
        return [$product, $request, $product];
    }

    /**
     * A bundle's default selections, as its product page preselects them.
     *
     * @param Product $bundle
     * @return array
     */
    private function bundleDefaults(Product $bundle): array
    {
        /** @var \Magento\Bundle\Model\Product\Type $type */
        $type = $bundle->getTypeInstance();
        $options = [];
        $quantities = [];
        $selections = $type->getSelectionsCollection($type->getOptionsIds($bundle), $bundle);
        foreach ($selections as $selection) {
            if (!$selection->getIsDefault()) {
                continue;
            }
            $optionId = (int) $selection->getOptionId();
            $options[$optionId][] = (int) $selection->getSelectionId();
            // Per selection: an option with several defaults keeps each one's own quantity.
            $quantities[$optionId][(int) $selection->getSelectionId()] = (float) $selection->getSelectionQty() ?: 1;
        }
        foreach ($type->getOptionsCollection($bundle) as $option) {
            $optionId = (int) $option->getId();
            if (isset($options[$optionId]) && in_array($option->getType(), ['select', 'radio'], true)) {
                // A single choice, and its quantity as the option's.
                $options[$optionId] = $options[$optionId][0];
                $quantities[$optionId] = $quantities[$optionId][$options[$optionId]];
            }
        }
        return ['bundle_option' => $options, 'bundle_option_qty' => $quantities];
    }

    /**
     * @param Product $product
     * @param int $websiteId
     * @return bool
     */
    private function onSale(Product $product, int $websiteId): bool
    {
        return (int) $product->getStatus() === Status::STATUS_ENABLED
            && in_array($websiteId, array_map('intval', (array) $product->getWebsiteIds()), true);
    }
}
