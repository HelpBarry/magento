<?php

namespace Bluebarry\Bluebarry\Model\Catalog;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;

/**
 * The attributes the store's layered navigation filters by, on category pages or on search results,
 * in its order and with their labels in a store view. bluebarry imports them as search filters; the
 * catalog sync sends each one's values as attr_{code}. Read with the heartbeat (cron and settings
 * save), never on a shopper's request: one query.
 */
class FilterAttributes
{
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
        $collection = $this->attributes->create();
        $collection->addFieldToFilter(['is_filterable', 'is_filterable_in_search'], [['gt' => 0], ['eq' => 1]])
            ->addStoreLabel($storeId)
            ->setOrder('position', 'ASC');
        $filters = [];
        foreach ($collection as $attribute) {
            $code = (string) $attribute->getAttributeCode();
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
