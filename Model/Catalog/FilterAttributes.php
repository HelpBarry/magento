<?php

namespace Bluebarry\Bluebarry\Model\Catalog;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;

/**
 * The attributes the store's layered navigation filters by, on category pages or on search results,
 * in its order and with their labels in a store view. bluebarry imports them as search filters. Only
 * the ones the catalog sync sends as attr_{code} (ProductBuilder::onlySynced()), and price, which
 * bluebarry turns into its price range: a filter without values in bluebarry would be no filter.
 * Read with the heartbeat (cron and settings save), never on a shopper's request: two queries.
 */
class FilterAttributes
{
    /** Magento's price filter: not an attr_ field, bluebarry's price range. */
    private const PRICE = 'price';

    /**
     * @var AttributeCollectionFactory
     */
    private $attributes;

    /**
     * @param AttributeCollectionFactory $attributes
     */
    public function __construct(AttributeCollectionFactory $attributes)
    {
        $this->attributes = $attributes;
    }

    /**
     * @param int $storeId the store view whose labels shoppers see
     * @return array<int, array{code: string, label: string, position: int}>
     */
    public function forStore(int $storeId): array
    {
        $synced = [self::PRICE => true];
        foreach (ProductBuilder::onlySynced($this->attributes->create()) as $attribute) {
            $synced[(string) $attribute->getAttributeCode()] = true;
        }

        $collection = $this->attributes->create();
        $collection->addFieldToFilter(['is_filterable', 'is_filterable_in_search'], [['gt' => 0], ['eq' => 1]])
            ->addStoreLabel($storeId)
            ->setOrder('position', 'ASC');
        $filters = [];
        foreach ($collection as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            if (!isset($synced[$code])) {
                continue;
            }
            // The store view's label, else the attribute's own (addStoreLabel falls back to it).
            $label = trim((string) ($attribute->getData('store_label') ?: $attribute->getData('frontend_label')));
            $filters[] = [
                'code' => $code,
                'label' => $label !== '' ? $label : $code,
                'position' => (int) $attribute->getData('position'),
            ];
        }
        return $filters;
    }
}
