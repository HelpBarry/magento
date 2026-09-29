<?php

namespace Bluebarry\Bluebarry\Model\Catalog;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\Group;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Tax\Model\Calculation as TaxCalculation;
use Magento\Tax\Model\Config as TaxConfig;

/**
 * Reads a batch of products the way one website's default store view shows them, in the shape
 * bluebarry's product sync takes (the WooCommerce plugin's, property for property where Magento has
 * the same thing).
 *
 * Every simple, virtual, downloadable and bundle product is a product. A configurable product's
 * children are products grouped under it (its name, address and categories); the configurable and
 * grouped products themselves are not, like WooCommerce's variable and grouped products. The
 * reference is the Magento product id, which orders report.
 *
 * A batch costs a fixed number of queries, whatever its size.
 */
class ProductBuilder
{
    /** Types whose stock is a quantity (the others' stock follows their parts). */
    private const COUNTED_TYPES = ['simple', 'virtual', 'downloadable'];

    /** Types that are no product of their own. */
    private const CONTAINER_TYPES = ['configurable', 'grouped'];

    /** Attribute inputs synced as properties. */
    private const ATTRIBUTE_INPUTS = ['select', 'multiselect', 'boolean', 'text', 'textarea', 'price', 'weight'];

    /** A longer text attribute is cut here (it is a fact about the product, not its description). */
    private const MAX_TEXT = 1000;

    /** A change waits at most this long for the price index before it is sent anyway. */
    private const INDEX_WAIT = 3600;

    private const MAX_SECONDARY_IMAGES = 5;

    /**
     * @var ProductCollectionFactory
     */
    private $products;

    /**
     * @var CategoryCollectionFactory
     */
    private $categories;

    /**
     * @var AttributeCollectionFactory
     */
    private $attributes;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @var IndexerRegistry
     */
    private $indexers;

    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * @var TaxCalculation
     */
    private $taxCalculation;

    /**
     * @var TaxConfig
     */
    private $taxConfig;

    /**
     * @var GroupRepositoryInterface
     */
    private $customerGroups;

    /** @var array<int, array<int, float>> store => product tax class => price factor */
    private $taxFactors = [];

    /** @var array<string, \Magento\Catalog\Model\ResourceModel\Eav\Attribute>|null */
    private $syncedAttributes;

    /** @var array<int, array<string, array<string, string>>> store => attribute => option => label */
    private $optionLabels = [];

    /** @var array<int, array<int, string>> store => category => name */
    private $categoryNames = [];

    /** @var array<int, int> dynamically priced bundle => the tax class of its parts, for the current build */
    private $bundleTaxClasses = [];

    /**
     * @param ProductCollectionFactory $products
     * @param CategoryCollectionFactory $categories
     * @param AttributeCollectionFactory $attributes
     * @param ResourceConnection $resource
     * @param ScopeConfigInterface $scopeConfig
     * @param MetadataPool $metadataPool
     * @param IndexerRegistry $indexers
     * @param TimezoneInterface $timezone
     * @param TaxCalculation $taxCalculation
     * @param TaxConfig $taxConfig
     * @param GroupRepositoryInterface $customerGroups
     */
    public function __construct(
        ProductCollectionFactory $products,
        CategoryCollectionFactory $categories,
        AttributeCollectionFactory $attributes,
        ResourceConnection $resource,
        ScopeConfigInterface $scopeConfig,
        MetadataPool $metadataPool,
        IndexerRegistry $indexers,
        TimezoneInterface $timezone,
        TaxCalculation $taxCalculation,
        TaxConfig $taxConfig,
        GroupRepositoryInterface $customerGroups
    ) {
        $this->products = $products;
        $this->categories = $categories;
        $this->attributes = $attributes;
        $this->resource = $resource;
        $this->scopeConfig = $scopeConfig;
        $this->metadataPool = $metadataPool;
        $this->indexers = $indexers;
        $this->timezone = $timezone;
        $this->taxCalculation = $taxCalculation;
        $this->taxConfig = $taxConfig;
        $this->customerGroups = $customerGroups;
    }

    /**
     * The queued products Magento has not indexed yet ("Update by Schedule"): their new price, or their
     * stock (a bundle's availability comes from its parts through the stock index). They wait a cron run
     * or two instead of going out with the old values. Never longer than an hour.
     *
     * @param array<int, string> $queued product id => queued_at (UTC)
     * @return int[]
     */
    public function awaitingIndexes(array $queued): array
    {
        $recent = array_keys(array_filter($queued, function ($at) {
            return strtotime($at . ' UTC') > time() - self::INDEX_WAIT;
        }));
        if (!$recent) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $products = $this->resource->getTableName('catalog_product_entity');
        $waiting = [];
        foreach (['catalog_product_price', 'cataloginventory_stock', 'inventory'] as $indexerId) {
            try {
                $indexer = $this->indexers->get($indexerId);
            } catch (\InvalidArgumentException $e) {
                continue; // multi-source inventory is not installed
            }
            if (!$indexer->isScheduled()) {
                continue;
            }
            $view = $indexer->getView();
            $changelog = $view->getChangelog();
            $column = 'changelog.' . $changelog->getColumnName();
            $select = $connection->select()->distinct()
                ->from(['changelog' => $this->resource->getTableName($changelog->getName())], [])
                ->where('changelog.version_id > ?', (int) $view->getState()->getVersionId());
            if ($indexerId === 'inventory') {
                // Its changelog names source items; they name the product by SKU.
                $select->join(['source_item' => $this->resource->getTableName('inventory_source_item')], "source_item.source_item_id = $column", [])
                    ->join(['product' => $products], 'product.sku = source_item.sku', ['entity_id']);
            } else {
                // Only products that still exist: a deleted one has nothing to wait for.
                $select->join(['product' => $products], "product.entity_id = $column", ['entity_id']);
            }
            $select->where('product.entity_id IN (?)', array_values(array_diff($recent, $waiting)));
            $waiting = array_merge($waiting, array_map('intval', $connection->fetchCol($select)));
            if (count($waiting) === count($recent)) {
                break;
            }
        }
        return array_values(array_unique($waiting));
    }

    /**
     * @param int[] $ids queued product ids
     * @param Store $store the website's default store view
     * @return array{products: array[], reconcileGroupIds: string[], failed: int[]}
     */
    public function build(array $ids, Store $store): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $websiteId = (int) $store->getWebsiteId();
        $storeId = (int) $store->getId();

        // A configurable product brings all of its children, so bluebarry can switch off one taken off it;
        // a part brings the bundles it is in, whose price and stock follow it.
        $children = $this->configurableLinks('parent', $ids);
        $all = array_values(array_unique(array_merge($ids, array_keys($children), $this->bundlesWith($ids))));
        $parentsOf = [];
        foreach ($this->configurableLinks('child', $all) as $child => $parents) {
            sort($parents);
            $parentsOf[$child] = $parents;
        }
        $parentIds = array_values(array_unique(array_merge(...array_values($parentsOf ?: [[]]))));

        $items = $this->load(array_values(array_unique(array_merge($all, $parentIds))), $storeId);
        $inWebsite = array_flip($this->inWebsite(array_keys($items), $websiteId));
        $prices = $this->prices($all, $websiteId, $storeId);
        $stock = $this->stock($all, $items, $store);
        $categoryIds = $this->productCategories(array_keys($items));
        $currency = $this->currency($store);
        $this->bundleTaxClasses = $this->dynamicBundleTaxClasses($items);

        $products = [];
        $failed = [];
        $reconcile = [];
        foreach ($all as $id) {
            $product = $items[$id] ?? null;
            if ($product === null) {
                $products[] = ['reference' => (string) $id, 'inactive' => true];
                // A deleted configurable product takes its children with it.
                if (in_array($id, $ids, true)) {
                    $reconcile[] = (string) $id;
                }
                continue;
            }
            if (in_array($product->getTypeId(), self::CONTAINER_TYPES, true)) {
                if ($product->getTypeId() === 'configurable' && in_array($id, $ids, true)) {
                    $reconcile[] = (string) $id;
                }
                continue;
            }
            try {
                // Shown through a parent on sale here; else on its own, if it has a page of its own.
                $parent = null;
                foreach ($parentsOf[$id] ?? [] as $parentId) {
                    if (isset($items[$parentId]) && $this->isShown($items[$parentId], $inWebsite)) {
                        $parent = $items[$parentId];
                        break;
                    }
                }
                $active = (int) $product->getStatus() === Status::STATUS_ENABLED
                    && isset($inWebsite[$id])
                    && ($parent !== null || $this->isShown($product, $inWebsite));
                if (!$active) {
                    $products[] = ['reference' => (string) $id, 'inactive' => true];
                    continue;
                }
                $products[] = $this->product($product, $parent, $store, $prices[$id] ?? null, $stock[$id] ?? null, $categoryIds, $currency);
            } catch (\Exception $e) {
                $failed[] = $id;
            }
        }

        // A child that could not be read is missing from the batch, so its parent's children must not
        // be switched off by what is missing: that waits until the child is read.
        foreach ($failed as $id) {
            $reconcile = array_diff($reconcile, array_map('strval', $parentsOf[$id] ?? []));
        }

        return ['products' => $products, 'reconcileGroupIds' => array_values($reconcile), 'failed' => $failed];
    }

    /**
     * Stock as the WooCommerce plugin reports it: a status, and a quantity only while the quantity
     * decides (stock managed, no backorders). A product that can always be bought never reads as sold out.
     *
     * @param array|null $row cataloginventory_stock_item
     * @param bool $manageByDefault
     * @param bool $backordersByDefault
     * @param bool $counted whether the product type has a quantity of its own
     * @param float $minQtyByDefault the out-of-stock threshold: what is left at or below it is not for sale
     * @return array{0: string, 1: int|float|null} status, quantity
     */
    public static function stockOf(?array $row, bool $manageByDefault, bool $backordersByDefault, bool $counted, float $minQtyByDefault = 0.0): array
    {
        if ($row === null) {
            return ['instock', null];
        }
        $managed = (int) $row['use_config_manage_stock'] ? $manageByDefault : (bool) (int) $row['manage_stock'];
        if (!$managed) {
            return ['instock', null];
        }
        if (!(int) $row['is_in_stock']) {
            return ['outofstock', null];
        }
        $backorders = (int) $row['use_config_backorders'] ? $backordersByDefault : (bool) (int) $row['backorders'];
        $minQty = (int) ($row['use_config_min_qty'] ?? 1) ? $minQtyByDefault : (float) ($row['min_qty'] ?? 0);
        // A negative threshold (with backorders) lets Magento sell below zero; it is kept as is. A
        // product sold in decimal quantities keeps its fraction.
        $quantity = round((float) $row['qty'] - $minQty, 4);
        $quantity = $quantity == floor($quantity) ? (int) $quantity : $quantity;
        if ($backorders) {
            return [$counted && $quantity <= 0 ? 'onbackorder' : 'instock', null];
        }
        if (!$counted) {
            return ['instock', null];
        }
        return $quantity > 0 ? ['instock', $quantity] : ['outofstock', 0];
    }

    /**
     * The price shoppers pay, and the regular price only while it is higher (a special price or a
     * catalog price rule). Sent empty otherwise, which clears an ended sale in bluebarry.
     *
     * @param float $final
     * @param float|null $regular
     * @return array{0: float, 1: float|null}
     */
    public static function pricesOf(float $final, ?float $regular): array
    {
        $final = round($final, 2);
        $regular = $regular === null ? null : round($regular, 2);
        return [$final, $regular !== null && $regular > $final ? $regular : null];
    }

    /**
     * @param Product $product
     * @param Product|null $parent the configurable product it is a child of
     * @param Store $store
     * @param array|null $price the price index row
     * @param array|null $stock
     * @param array<int, int[]> $categoryIds
     * @param array{code: string, rate: float} $currency
     * @return array
     */
    private function product(Product $product, ?Product $parent, Store $store, ?array $price, ?array $stock, array $categoryIds, array $currency): array
    {
        $shown = $parent ?? $product;
        $id = (int) $product->getId();
        $type = (string) $product->getTypeId();

        // As shown: in the store view's currency, with or without tax as its catalog displays prices.
        // A dynamically priced bundle is taxed through its parts, not by a class of its own.
        $taxClass = $this->bundleTaxClasses[$id] ?? (int) $product->getData('tax_class_id');
        $factor = $currency['rate'] * $this->taxFactor($store, $taxClass);
        if ($price !== null) {
            // A bundle's own final price is its fixed part; "as low as" is what shoppers see.
            $final = $type === 'bundle' ? (float) $price['min_price'] : (float) $price['final_price'];
            [$final, $regular] = self::pricesOf($final * $factor, $type === 'bundle' ? null : (float) $price['price'] * $factor);
        } elseif ($type === 'bundle') {
            // A bundle's price comes from its parts, through the index: none rather than a wrong one.
            [$final, $regular] = [null, null];
        } else {
            // Not in the price index (not indexed yet, or out of stock while those are hidden): its own
            // price and special price. Catalog price rules only apply through the index.
            $regular = (float) $product->getData('price');
            $special = $product->getData('special_price');
            $onSale = $special !== null && $special !== ''
                && $this->timezone->isScopeDateInInterval($store, $product->getData('special_from_date'), $product->getData('special_to_date'));
            [$final, $regular] = self::pricesOf(
                ($onSale ? min((float) $special, $regular) : $regular) * $factor,
                $regular * $factor
            );
        }
        [$stockStatus, $quantity] = self::stockOf(
            $stock,
            // In the store's scope, as Magento's stock configuration reads them.
            $this->scopeConfig->isSetFlag('cataloginventory/item_options/manage_stock', ScopeInterface::SCOPE_STORE, $store),
            (bool) (int) $this->scopeConfig->getValue('cataloginventory/item_options/backorders', ScopeInterface::SCOPE_STORE, $store),
            in_array($type, self::COUNTED_TYPES, true),
            (float) $this->scopeConfig->getValue('cataloginventory/item_options/min_qty', ScopeInterface::SCOPE_STORE, $store)
        );

        $properties = [
            ['propertyName' => 'price', 'value' => $final, 'type' => 'numeric'],
            ['propertyName' => 'compare_at_price', 'value' => $regular, 'type' => 'numeric'],
            ['propertyName' => 'currency', 'value' => $currency['code'], 'type' => 'text'],
            ['propertyName' => 'stock_status', 'value' => $stockStatus, 'type' => 'text'],
            ['propertyName' => 'stock_quantity', 'value' => $quantity, 'type' => 'numeric'],
            ['propertyName' => 'type', 'value' => $type, 'type' => 'text'],
            ['propertyName' => 'sku', 'value' => (string) $product->getSku(), 'type' => 'text'],
            ['propertyName' => 'product_id', 'value' => (string) $id, 'type' => 'text'],
        ];
        $categories = $this->categoryNames($categoryIds[(int) $shown->getId()] ?? [], $store);
        if ($categories) {
            $properties[] = ['propertyName' => 'categories', 'value' => $categories, 'type' => 'collection'];
        }
        foreach ($this->syncedAttributes() as $code => $attribute) {
            $property = $this->attributeProperty($attribute, $product, $parent, (int) $store->getId());
            if ($property !== null) {
                $properties[] = $property;
            }
        }

        $name = $this->text($shown->getName());
        [$image, $secondary] = $this->images($product, $parent, $store);
        return [
            'reference' => (string) $id,
            'name' => $name,
            'groupName' => $parent ? $name : null,
            'groupId' => $parent ? (string) $parent->getId() : null,
            'url' => (string) $shown->getProductUrl(),
            'imageUrl' => $image,
            'secondaryImages' => $secondary,
            'inactive' => false,
            'properties' => $properties,
        ];
    }

    /**
     * @param \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute
     * @param Product $product
     * @param Product|null $parent a child without a value of its own shows its parent's
     * @param int $storeId
     * @return array|null
     */
    private function attributeProperty($attribute, Product $product, ?Product $parent, int $storeId): ?array
    {
        $code = (string) $attribute->getAttributeCode();
        $value = $product->getData($code);
        if (($value === null || $value === '') && $parent !== null) {
            $value = $parent->getData($code);
        }
        if ($value === null || $value === '') {
            return null;
        }
        $name = 'attr_' . $code;
        switch ($attribute->getFrontendInput()) {
            case 'boolean':
                return ['propertyName' => $name, 'value' => (bool) (int) $value, 'type' => 'boolean'];
            case 'price':
            case 'weight':
                return is_numeric($value) ? ['propertyName' => $name, 'value' => (float) $value, 'type' => 'numeric'] : null;
            case 'select':
                $label = $this->optionLabels($attribute, $storeId)[(string) $value] ?? null;
                return $label === null ? null : ['propertyName' => $name, 'value' => $label, 'type' => 'text'];
            case 'multiselect':
                $labels = $this->optionLabels($attribute, $storeId);
                $values = [];
                foreach (explode(',', (string) $value) as $option) {
                    if (isset($labels[$option])) {
                        $values[] = $labels[$option];
                    }
                }
                return $values ? ['propertyName' => $name, 'value' => $values, 'type' => 'collection'] : null;
            default:
                $text = mb_substr($this->text($value), 0, self::MAX_TEXT);
                return $text === '' ? null : ['propertyName' => $name, 'value' => $text, 'type' => 'text'];
        }
    }

    /**
     * The product's own image or else its parent's, and up to five more from the same gallery.
     *
     * @param Product $product
     * @param Product|null $parent
     * @param Store $store
     * @return array{0: string|null, 1: string[]}
     */
    private function images(Product $product, ?Product $parent, Store $store): array
    {
        $source = $this->hasImage($product) || $parent === null ? $product : $parent;
        $base = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/') . '/catalog/product';
        $main = $this->hasImage($source) ? (string) $source->getData('image') : null;
        $secondary = [];
        foreach (($source->getData('media_gallery')['images'] ?? []) as $image) {
            $file = (string) ($image['file'] ?? '');
            if ($file === '' || $file === $main || !empty($image['disabled']) || ($image['media_type'] ?? 'image') !== 'image') {
                continue;
            }
            $secondary[] = $base . $file;
            if (count($secondary) >= self::MAX_SECONDARY_IMAGES) {
                break;
            }
        }
        return [$main === null ? null : $base . $main, $secondary];
    }

    /**
     * Enabled, on sale on the website and with a page of its own.
     *
     * @param Product $product
     * @param array<int, int> $inWebsite
     * @return bool
     */
    private function isShown(Product $product, array $inWebsite): bool
    {
        return (int) $product->getStatus() === Status::STATUS_ENABLED
            && isset($inWebsite[(int) $product->getId()])
            && (int) $product->getVisibility() !== Visibility::VISIBILITY_NOT_VISIBLE;
    }

    /**
     * @param Product $product
     * @return bool
     */
    private function hasImage(Product $product): bool
    {
        $image = (string) $product->getData('image');
        return $image !== '' && $image !== 'no_selection';
    }

    /**
     * @param int[] $ids
     * @param int $storeId
     * @return array<int, Product>
     */
    private function load(array $ids, int $storeId): array
    {
        if (!$ids) {
            return [];
        }
        $collection = $this->products->create();
        $collection->setStoreId($storeId)
            ->addIdFilter($ids)
            ->addAttributeToSelect(array_merge(['name', 'status', 'visibility', 'image', 'url_key', 'price', 'special_price', 'special_from_date', 'special_to_date', 'tax_class_id'], array_keys($this->syncedAttributes())))
            ->addUrlRewrite();
        $collection->addMediaGalleryData();
        $items = [];
        foreach ($collection as $product) {
            $product->setStoreId($storeId);
            $items[(int) $product->getId()] = $product;
        }
        return $items;
    }

    /**
     * The price index for guests: the regular price, the final price (special prices and catalog price
     * rules applied) and a bundle's lowest price. Missing for products not indexed yet.
     *
     * @param int[] $ids
     * @param int $websiteId
     * @param int $storeId
     * @return array<int, array>
     */
    private function prices(array $ids, int $websiteId, int $storeId): array
    {
        $collection = $this->products->create();
        $collection->setStoreId($storeId)->addIdFilter($ids)->addPriceData(0, $websiteId);
        $prices = [];
        foreach ($collection->getData() as $row) {
            $prices[(int) $row['entity_id']] = $row;
        }
        return $prices;
    }

    /**
     * Stock as shoppers can buy it. With Magento's multi-source inventory, what open orders reserved is
     * taken off (an order reserves stock; the quantity itself only drops when it ships), and a website
     * selling from another stock than the default one reads that stock.
     *
     * @param int[] $ids
     * @param array<int, Product> $items
     * @param Store $store
     * @return array<int, array>
     */
    private function stock(array $ids, array $items, Store $store): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll($connection->select()
            ->from($this->resource->getTableName('cataloginventory_stock_item'), [
                'product_id', 'qty', 'is_in_stock', 'use_config_manage_stock', 'manage_stock', 'use_config_backorders', 'backorders',
                'use_config_min_qty', 'min_qty',
            ])
            ->where('product_id IN (?)', $ids)
            ->where('stock_id = ?', 1));
        $stock = [];
        foreach ($rows as $row) {
            $stock[(int) $row['product_id']] = $row;
        }
        // A bundle is for sale while its parts are: the stock index knows, its own stock item does not.
        $composite = array_values(array_filter($ids, function ($id) use ($items, $stock) {
            return isset($items[$id], $stock[$id]) && !in_array($items[$id]->getTypeId(), self::COUNTED_TYPES, true);
        }));
        if ($composite) {
            // Magento indexes it for all websites (0); a row of the website's own, if any, comes last and wins.
            foreach ($connection->fetchAll($connection->select()
                ->from($this->resource->getTableName('cataloginventory_stock_status'), ['product_id', 'stock_status'])
                ->where('product_id IN (?)', $composite)
                ->where('stock_id = ?', 1)
                ->where('website_id IN (?)', [0, (int) $store->getWebsiteId()])
                ->order('website_id')) as $row) {
                $stock[(int) $row['product_id']]['is_in_stock'] = $row['stock_status'];
            }
        }

        $bySku = [];
        foreach ($ids as $id) {
            if (isset($items[$id], $stock[$id])) {
                $bySku[(string) $items[$id]->getSku()] = $id;
            }
        }
        if (!$bySku) {
            return $stock;
        }
        $stockId = $this->salesStockId($store);
        $index = $this->resource->getTableName('inventory_stock_' . $stockId);
        if ($stockId !== 1 && $connection->isTableExists($index)) {
            // Not in the website's stock at all: not for sale there.
            foreach ($bySku as $id) {
                $stock[$id]['qty'] = 0;
                $stock[$id]['is_in_stock'] = 0;
            }
            foreach ($connection->fetchAll($connection->select()->from($index, ['sku', 'quantity', 'is_salable'])
                ->where('sku IN (?)', array_keys($bySku))) as $row) {
                if (isset($bySku[$row['sku']])) {
                    $stock[$bySku[$row['sku']]]['qty'] = $row['quantity'];
                    $stock[$bySku[$row['sku']]]['is_in_stock'] = $row['is_salable'];
                }
            }
        }
        $reservations = $this->resource->getTableName('inventory_reservation');
        if ($connection->isTableExists($reservations)) {
            foreach ($connection->fetchPairs($connection->select()->from($reservations, ['sku', 'reserved' => 'SUM(quantity)'])
                ->where('stock_id = ?', $stockId)
                ->where('sku IN (?)', array_keys($bySku))
                ->group('sku')) as $sku => $reserved) {
                if (isset($bySku[$sku])) {
                    $stock[$bySku[$sku]]['qty'] = (float) $stock[$bySku[$sku]]['qty'] + (float) $reserved;
                }
            }
        }
        return $stock;
    }

    /**
     * The inventory stock the website sells from: 1 (the default) without multi-source inventory.
     *
     * @param Store $store
     * @return int
     */
    private function salesStockId(Store $store): int
    {
        $connection = $this->resource->getConnection();
        $channels = $this->resource->getTableName('inventory_stock_sales_channel');
        if (!$connection->isTableExists($channels)) {
            return 1;
        }
        $stockId = $connection->fetchOne($connection->select()->from($channels, 'stock_id')
            ->where('type = ?', 'website')
            ->where('code = ?', (string) $store->getWebsite()->getCode()));
        return $stockId ? (int) $stockId : 1;
    }

    /**
     * @param int[] $ids
     * @param int $websiteId
     * @return int[]
     */
    private function inWebsite(array $ids, int $websiteId): array
    {
        if (!$ids) {
            return [];
        }
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchCol($connection->select()
            ->from($this->resource->getTableName('catalog_product_website'), 'product_id')
            ->where('product_id IN (?)', $ids)
            ->where('website_id = ?', $websiteId)));
    }

    /**
     * The bundles that contain any of the products (by entity id; Adobe Commerce links them by row id).
     *
     * @param int[] $ids
     * @return int[]
     */
    private function bundlesWith(array $ids): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('catalog_product_bundle_selection');
        if (!$ids || !$connection->isTableExists($table)) {
            return [];
        }
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        return array_map('intval', $connection->fetchCol($connection->select()->distinct()
            ->from(['selection' => $table], [])
            ->join(['bundle' => $this->resource->getTableName('catalog_product_entity')], "bundle.$linkField = selection.parent_product_id", ['entity_id'])
            ->where('selection.product_id IN (?)', $ids)));
    }

    /**
     * The tax class a dynamically priced bundle's "as low as" price is taxed by: its parts' (the default
     * selection first), as Magento taxes each part. A fixed-price bundle has a tax class of its own.
     *
     * @param array<int, Product> $items
     * @return array<int, int> bundle id => tax class
     */
    private function dynamicBundleTaxClasses(array $items): array
    {
        $bundles = array_filter($items, function (Product $product) {
            return $product->getTypeId() === 'bundle';
        });
        if (!$bundles) {
            return [];
        }
        $resource = $this->products->create()->getResource();
        if (!$resource instanceof \Magento\Eav\Model\Entity\AbstractEntity) {
            return [];
        }
        $priceType = $resource->getAttribute('price_type');
        $taxClass = $resource->getAttribute('tax_class_id');
        if (!$priceType || !$taxClass) {
            return [];
        }
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $idByLink = [];
        foreach ($bundles as $id => $bundle) {
            $idByLink[(int) $bundle->getData($linkField)] = $id;
        }
        $connection = $this->resource->getConnection();
        $int = $this->resource->getTableName('catalog_product_entity_int');
        $dynamic = $connection->fetchCol($connection->select()->from($int, [$linkField])
            ->where('attribute_id = ?', (int) $priceType->getId())
            ->where('store_id = ?', 0)
            ->where('value = ?', 0)
            ->where("$linkField IN (?)", array_keys($idByLink)));
        if (!$dynamic) {
            return [];
        }
        $classes = [];
        foreach ($connection->fetchAll($connection->select()
            ->from(['selection' => $this->resource->getTableName('catalog_product_bundle_selection')], ['parent_product_id'])
            ->join(['part' => $this->resource->getTableName('catalog_product_entity')], 'part.entity_id = selection.product_id', [])
            ->join(['tax' => $int], "tax.$linkField = part.$linkField AND tax.store_id = 0 AND tax.attribute_id = " . (int) $taxClass->getId(), ['value'])
            ->where('selection.parent_product_id IN (?)', array_map('intval', $dynamic))
            ->order(['selection.is_default DESC', 'selection.position', 'selection.selection_id'])) as $row) {
            $bundleId = $idByLink[(int) $row['parent_product_id']] ?? null;
            if ($bundleId !== null && !isset($classes[$bundleId])) {
                $classes[$bundleId] = (int) $row['value'];
            }
        }
        return $classes;
    }

    /**
     * What turns a catalog price into the price guests see: the tax rate added when prices are entered
     * without tax but shown with it, taken off in the reverse case, 1 otherwise. Guests' tax class and
     * the store's default tax destination, as Magento prices a page for a visitor it does not know.
     *
     * @param Store $store
     * @param int $productTaxClass
     * @return float
     */
    private function taxFactor(Store $store, int $productTaxClass): float
    {
        $storeId = (int) $store->getId();
        if (!isset($this->taxFactors[$storeId][$productTaxClass])) {
            $entered = $this->taxConfig->priceIncludesTax($store);
            $shown = (int) $this->taxConfig->getPriceDisplayType($store) !== TaxConfig::DISPLAY_TYPE_EXCLUDING_TAX;
            $factor = 1.0;
            if ($entered !== $shown && $productTaxClass > 0) {
                $guests = (int) $this->customerGroups->getById(Group::NOT_LOGGED_IN_ID)->getTaxClassId();
                $request = $this->taxCalculation->getRateRequest(null, null, $guests, $store);
                $rate = (float) $this->taxCalculation->getRate($request->setProductClassId($productTaxClass)) / 100;
                $factor = $shown ? 1 + $rate : 1 / (1 + $rate);
            }
            $this->taxFactors[$storeId][$productTaxClass] = $factor;
        }
        return $this->taxFactors[$storeId][$productTaxClass];
    }

    /**
     * Configurable product links, by entity id (Adobe Commerce links parents by row id).
     *
     * @param string $by 'parent' for the children of $ids, 'child' for the parents of $ids
     * @param int[] $ids
     * @return array<int, int[]> child => parents
     */
    private function configurableLinks(string $by, array $ids): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('catalog_product_super_link');
        if (!$ids || !$connection->isTableExists($table)) {
            return [];
        }
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $entities = $this->resource->getTableName('catalog_product_entity');
        $select = $connection->select()->distinct()
            ->from(['link' => $table], ['child' => 'product_id'])
            ->join(['parent' => $entities], "parent.$linkField = link.parent_id", ['parent' => 'entity_id'])
            ->where($by === 'parent' ? 'parent.entity_id IN (?)' : 'link.product_id IN (?)', $ids);
        if ($linkField !== 'entity_id' && $connection->tableColumnExists($entities, 'created_in')) {
            // Content staging keeps a row per scheduled version, each with its own links: the one live now.
            $now = time();
            $select->where('parent.created_in <= ?', $now)->where('parent.updated_in > ?', $now);
        }
        $links = [];
        foreach ($connection->fetchAll($select) as $row) {
            $links[(int) $row['child']][] = (int) $row['parent'];
        }
        return $links;
    }

    /**
     * @param int[] $ids
     * @return array<int, int[]>
     */
    private function productCategories(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $categories = [];
        foreach ($connection->fetchAll($connection->select()
            ->from($this->resource->getTableName('catalog_category_product'), ['product_id', 'category_id'])
            ->where('product_id IN (?)', $ids)) as $row) {
            $categories[(int) $row['product_id']][] = (int) $row['category_id'];
        }
        return $categories;
    }

    /**
     * The names of the product's active categories in the store's own category tree.
     *
     * @param int[] $ids
     * @param Store $store
     * @return string[]
     */
    private function categoryNames(array $ids, Store $store): array
    {
        $storeId = (int) $store->getId();
        if (!isset($this->categoryNames[$storeId])) {
            $collection = $this->categories->create();
            $collection->setStoreId($storeId)
                ->addAttributeToSelect('name')
                ->addAttributeToFilter('is_active', ['eq' => 1])
                ->addFieldToFilter('path', ['like' => '1/' . (int) $store->getRootCategoryId() . '/%']);
            $this->categoryNames[$storeId] = [];
            foreach ($collection as $category) {
                $this->categoryNames[$storeId][(int) $category->getId()] = $this->text($category->getName());
            }
        }
        $names = [];
        foreach ($ids as $id) {
            if (isset($this->categoryNames[$storeId][$id]) && $this->categoryNames[$storeId][$id] !== '') {
                $names[] = $this->categoryNames[$storeId][$id];
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * The attributes a merchant made part of the storefront: shown on the product page, or filterable
     * in the layered navigation. Their own, not Magento's system attributes.
     *
     * @return array<string, \Magento\Catalog\Model\ResourceModel\Eav\Attribute>
     */
    private function syncedAttributes(): array
    {
        if ($this->syncedAttributes === null) {
            $collection = $this->attributes->create();
            $collection->addFieldToFilter('is_user_defined', ['eq' => 1])
                ->addFieldToFilter('frontend_input', ['in' => self::ATTRIBUTE_INPUTS])
                ->addFieldToFilter(['is_visible_on_front', 'is_filterable'], [['eq' => 1], ['gt' => 0]]);
            $this->syncedAttributes = [];
            foreach ($collection as $attribute) {
                $this->syncedAttributes[(string) $attribute->getAttributeCode()] = $attribute;
            }
        }
        return $this->syncedAttributes;
    }

    /**
     * @param \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute
     * @param int $storeId
     * @return array<string, string> option id => label in the store view
     */
    private function optionLabels($attribute, int $storeId): array
    {
        $code = (string) $attribute->getAttributeCode();
        if (!isset($this->optionLabels[$storeId][$code])) {
            $labels = [];
            if ($attribute->usesSource()) {
                $attribute->setStoreId($storeId);
                foreach ($attribute->getSource()->getAllOptions() as $option) {
                    $label = $this->text($option['label'] ?? '');
                    if ($label !== '' && isset($option['value']) && !is_array($option['value'])) {
                        $labels[(string) $option['value']] = $label;
                    }
                }
            }
            $this->optionLabels[$storeId][$code] = $labels;
        }
        return $this->optionLabels[$storeId][$code];
    }

    /**
     * Prices in the store view's currency, as shoppers see them.
     *
     * @param Store $store
     * @return array{code: string, rate: float}
     */
    private function currency(Store $store): array
    {
        $base = (string) $store->getBaseCurrencyCode();
        $shown = (string) $store->getDefaultCurrencyCode();
        if ($shown !== '' && $shown !== $base) {
            $rate = $store->getBaseCurrency()->getRate($shown);
            if ($rate) {
                return ['code' => $shown, 'rate' => (float) $rate];
            }
        }
        return ['code' => $base, 'rate' => 1.0];
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function text($value): string
    {
        return trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8'));
    }
}
