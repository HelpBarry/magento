<?php

namespace Bluebarry\Bluebarry\Model\Catalog;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Store\Model\Store;

/**
 * The name each category goes to bluebarry under, in the category list (Categories) and in each
 * product's categories (ProductBuilder) alike, because a collection finds its products by name.
 *
 * Magento stores often have the same name under different parents (Men > Tops, Women > Tops), which
 * would be one "Tops" to bluebarry, each collection holding the products of both. A category whose name
 * another active category of the store also has (trimmed, ignoring case) is named by its path instead:
 * the names of its parents below the store's root category, then its own, joined with " / "
 * ("Men / Tops"). A unique name stays as it is. bluebarry's CCV Shop sync and WooCommerce plugin do the same.
 */
class CategoryNames
{
    public const SEPARATOR = ' / ';

    /**
     * The store's active categories under its root category, by id, each under the name bluebarry knows
     * it by. One query; read it once per run, not per product.
     *
     * @param CategoryCollectionFactory $factory
     * @param Store $store
     * @return array<int, string>
     */
    public static function forStore(CategoryCollectionFactory $factory, Store $store): array
    {
        $root = (int) $store->getRootCategoryId();
        $collection = $factory->create();
        // Inactive ones too: an active category's parent can be inactive, and still names its path.
        $collection->setStoreId((int) $store->getId())
            ->addAttributeToSelect(['name', 'is_active'])
            ->addFieldToFilter('path', ['like' => '1/' . $root . '/%']);
        $categories = [];
        foreach ($collection as $category) {
            $categories[(int) $category->getId()] = [
                'name' => self::text($category->getName()),
                'path' => (string) $category->getPath(),
                'active' => (int) $category->getData('is_active') === 1,
            ];
        }
        return self::resolve($categories, $root);
    }

    /**
     * @param array<int, array{name: string, path: string, active: bool}> $categories by id
     * @param int $root the store's root category
     * @return array<int, string> the active ones with a name
     */
    public static function resolve(array $categories, int $root): array
    {
        $sent = array_filter($categories, function ($category) {
            return $category['active'] && $category['name'] !== '';
        });
        $count = [];
        foreach ($sent as $category) {
            $key = self::key($category['name']);
            $count[$key] = ($count[$key] ?? 0) + 1;
        }
        $names = [];
        foreach ($sent as $id => $category) {
            if ($count[self::key($category['name'])] < 2) {
                $names[$id] = $category['name'];
                continue;
            }
            $path = [];
            $below = false;
            foreach (explode('/', $category['path']) as $ancestor) {
                $ancestor = (int) $ancestor;
                if ($ancestor === $id) {
                    break;
                }
                if ($below && ($categories[$ancestor]['name'] ?? '') !== '') {
                    $path[] = $categories[$ancestor]['name'];
                }
                $below = $below || $ancestor === $root;
            }
            $path[] = $category['name'];
            $names[$id] = implode(self::SEPARATOR, $path);
        }
        return $names;
    }

    /**
     * The same reading of a name as the catalog sync's (ProductBuilder::text()).
     *
     * @param mixed $value
     * @return string
     */
    public static function text($value): string
    {
        return trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8'));
    }

    /**
     * @param string $name
     * @return string
     */
    private static function key(string $name): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }
}
