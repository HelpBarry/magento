<?php

namespace Bluebarry\Bluebarry\Model\Catalog;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableLinks;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\StoreManagerInterface;

/**
 * A product page's product as bluebarry knows it from the catalog sync: the product it is grouped
 * under, its variants, and the variant the page opens with. Only what the page's address decides, so
 * what is printed from it is right for every shopper of a page from the full page cache.
 */
class PageProduct
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ProductCollectionFactory
     */
    private $products;

    /**
     * @var ConfigurableLinks
     */
    private $configurableLinks;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /** @var array<int, int|null> child => the configurable product the catalog sync groups it under */
    private $syncedParents = [];

    /**
     * @param StoreManagerInterface $storeManager
     * @param ProductCollectionFactory $products
     * @param ConfigurableLinks $configurableLinks
     * @param MetadataPool $metadataPool
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        ProductCollectionFactory $products,
        ConfigurableLinks $configurableLinks,
        MetadataPool $metadataPool
    ) {
        $this->storeManager = $storeManager;
        $this->products = $products;
        $this->configurableLinks = $configurableLinks;
        $this->metadataPool = $metadataPool;
    }

    /**
     * The variant a product page opens with, for its product view: a configurable product's first
     * variant for sale (by id, as the catalog sync orders them) of the ones the sync groups under it
     * (a variant of several configurable products goes under one), else its first; any other product
     * itself. None for a configurable product without such variants or a grouped product: the catalog
     * sync sends neither, only their products.
     *
     * @param Product $product
     * @return string|null
     */
    public function defaultReference($product): ?string
    {
        if ($product->getTypeId() === 'grouped') {
            return null;
        }
        if ($product->getTypeId() !== 'configurable') {
            return (string) $product->getId();
        }
        $own = $this->ownVariants($product);
        foreach ($own as $child) {
            if ($child instanceof Product && $child->isSalable()) {
                return (string) $child->getId();
            }
        }
        // Without variants there is nothing the catalog sync sends for it.
        return $own ? (string) $own[0]->getId() : null;
    }

    /**
     * Every product the catalog sync sends for a product page: a configurable product's variants (the
     * ones grouped under it, by id), any other product itself, none for a grouped product.
     *
     * @param Product $product
     * @return string[]
     */
    public function references($product): array
    {
        if ($product->getTypeId() === 'grouped') {
            return [];
        }
        if ($product->getTypeId() !== 'configurable') {
            return [(string) $product->getId()];
        }
        return array_map(function ($child) {
            return (string) $child->getId();
        }, $this->ownVariants($product));
    }

    /**
     * The product a page's product is in bluebarry: the configurable product the catalog sync groups
     * it under (itself, for a configurable product with variants), or null when it stands for itself.
     *
     * @param Product $product
     * @return int|null
     */
    public function groupId($product): ?int
    {
        return $product->getTypeId() === 'configurable'
            ? ($this->defaultReference($product) !== null ? (int) $product->getId() : null)
            : $this->syncedParentId((int) $product->getId());
    }

    /**
     * The configurable product the catalog sync groups a product under: its lowest parent that is
     * enabled, has a page and is in this website (ProductBuilder::build()), or null.
     *
     * @param int $childId
     * @return int|null
     */
    public function syncedParentId(int $childId): ?int
    {
        return $this->syncedParents([$childId])[$childId] ?? null;
    }

    /**
     * A configurable product's variants that the catalog sync groups under it, by id.
     *
     * @param Product $product
     * @return ProductInterface[]
     */
    private function ownVariants($product): array
    {
        // The page's own options already loaded these (the configurable type caches them on the product).
        $type = $product->getTypeInstance();
        $children = $type instanceof Configurable ? $type->getUsedProducts($product) : [];
        usort($children, function ($a, $b) {
            return (int) $a->getId() <=> (int) $b->getId();
        });
        $groups = $this->syncedParents(array_map(function ($child) {
            return (int) $child->getId();
        }, $children));
        return array_values(array_filter($children, function ($child) use ($product, $groups) {
            return ($groups[(int) $child->getId()] ?? null) === (int) $product->getId();
        }));
    }

    /**
     * syncedParentId() for many products at once: two queries however many there are.
     *
     * @param int[] $childIds
     * @return array<int, int|null>
     */
    private function syncedParents(array $childIds): array
    {
        $missing = array_values(array_diff($childIds, array_keys($this->syncedParents)));
        if ($missing) {
            foreach ($missing as $id) {
                $this->syncedParents[$id] = null;
            }
            $connection = $this->configurableLinks->getConnection();
            $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
            $entities = $this->configurableLinks->getTable('catalog_product_entity');
            $select = $connection->select()
                ->from(['link' => $this->configurableLinks->getTable('catalog_product_super_link')], ['child' => 'product_id'])
                ->join(['parent' => $entities], "parent.$linkField = link.parent_id", ['parent' => 'entity_id'])
                ->where('link.product_id IN (?)', $missing);
            if ($linkField !== 'entity_id' && $connection->tableColumnExists($entities, 'created_in')) {
                // Content staging: the version live now, as the catalog sync reads it.
                $now = time();
                $select->where('parent.created_in <= ?', $now)->where('parent.updated_in > ?', $now);
            }
            $links = $connection->fetchAll($select);
            $parentIds = array_values(array_unique(array_map('intval', array_column($links, 'parent'))));
            if ($parentIds) {
                $store = $this->storeManager->getStore();
                $shown = array_flip(array_map('intval', $this->products->create()
                    ->setStoreId((int) $store->getId())
                    ->addIdFilter($parentIds)
                    ->addWebsiteFilter([(int) $store->getWebsiteId()])
                    ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
                    ->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE])
                    ->getAllIds()));
                foreach ($links as $link) {
                    $child = (int) $link['child'];
                    $parent = (int) $link['parent'];
                    if (isset($shown[$parent]) && ($this->syncedParents[$child] === null || $parent < $this->syncedParents[$child])) {
                        $this->syncedParents[$child] = $parent;
                    }
                }
            }
        }
        return array_intersect_key($this->syncedParents, array_flip($childIds));
    }
}
