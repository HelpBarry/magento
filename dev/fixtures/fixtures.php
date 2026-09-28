<?php
/**
 * Idempotent test fixtures: NL 21% tax, a simple product and a configurable product (two variants).
 * Run inside the php container: php /module/dev/fixtures/fixtures.php
 */

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory as ConfigurableOptionsFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\SetFactory as AttributeSetFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Tax\Api\Data\TaxRateInterfaceFactory;
use Magento\Tax\Api\Data\TaxRuleInterfaceFactory;
use Magento\Tax\Api\TaxRateRepositoryInterface;
use Magento\Tax\Api\TaxRuleRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

require '/var/www/html/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(State::class)->setAreaCode('adminhtml');

const TAXABLE_GOODS_CLASS = 2;
const RETAIL_CUSTOMER_CLASS = 3;

// --- Tax: NL 21% ---------------------------------------------------------------------------------
$rateRepo = $om->get(TaxRateRepositoryInterface::class);
$ruleRepo = $om->get(TaxRuleRepositoryInterface::class);
$criteria = $om->get(SearchCriteriaBuilder::class)->addFilter('code', 'NL-21')->create();
$rates = $rateRepo->getList($criteria)->getItems();
if ($rates) {
    $rate = reset($rates);
} else {
    $rate = $om->get(TaxRateInterfaceFactory::class)->create()
        ->setCode('NL-21')->setTaxCountryId('NL')->setTaxPostcode('*')->setRate(21);
    $rate = $rateRepo->save($rate);
}
$criteria = $om->get(SearchCriteriaBuilder::class)->addFilter('code', 'NL VAT')->create();
if (!$ruleRepo->getList($criteria)->getItems()) {
    $rule = $om->get(TaxRuleInterfaceFactory::class)->create()
        ->setCode('NL VAT')
        ->setTaxRateIds([$rate->getId()])
        ->setProductTaxClassIds([TAXABLE_GOODS_CLASS])
        ->setCustomerTaxClassIds([RETAIL_CUSTOMER_CLASS])
        ->setPriority(0)->setPosition(0);
    $ruleRepo->save($rule);
}
echo "tax: NL 21% ready\n";

// --- Products -----------------------------------------------------------------------------------
$productRepo = $om->get(ProductRepositoryInterface::class);
$productFactory = $om->get(ProductFactory::class);
$defaultSetId = $om->get(EavConfig::class)->getEntityType('catalog_product')
    ->getDefaultAttributeSetId();

$exists = function (string $sku) use ($productRepo): bool {
    try {
        $productRepo->get($sku);
        return true;
    } catch (NoSuchEntityException $e) {
        return false;
    }
};

$makeSimple = function (string $sku, string $name, float $price, int $visibility, array $extra = []) use ($productFactory, $productRepo, $defaultSetId) {
    $product = $productFactory->create();
    $product->setTypeId(Type::TYPE_SIMPLE)
        ->setAttributeSetId($defaultSetId)
        ->setSku($sku)
        ->setName($name)
        ->setUrlKey($sku)
        ->setPrice($price)
        ->setWeight(1)
        ->setVisibility($visibility)
        ->setStatus(Status::STATUS_ENABLED)
        ->setWebsiteIds([1])
        ->setTaxClassId(TAXABLE_GOODS_CLASS)
        ->setStockData(['use_config_manage_stock' => 1, 'qty' => 1000, 'is_in_stock' => 1]);
    foreach ($extra as $key => $value) {
        $product->setData($key, $value);
    }
    return $productRepo->save($product);
};

if (!$exists('bb-simple')) {
    $makeSimple('bb-simple', 'Bluebarry Simple Product', 100.00, Visibility::VISIBILITY_BOTH);
}
echo "product: bb-simple (EUR 100.00 excl. tax)\n";

// Configurable product on the "color" attribute with two variants.
if (!$exists('bb-configurable')) {
    $eavConfig = $om->get(EavConfig::class);
    $color = $eavConfig->getAttribute('catalog_product', 'color');

    $optionManagement = $om->get(AttributeOptionManagementInterface::class);
    $existing = [];
    foreach ($optionManagement->getItems('catalog_product', 'color') as $option) {
        $existing[$option->getLabel()] = $option->getValue();
    }
    foreach (['BB Red', 'BB Blue'] as $label) {
        if (!isset($existing[$label])) {
            $option = $om->get(AttributeOptionInterfaceFactory::class)->create()->setLabel($label);
            $optionManagement->add('catalog_product', 'color', $option);
        }
    }
    $eavConfig->clear();
    $color = $eavConfig->getAttribute('catalog_product', 'color');
    $optionIds = [];
    foreach ($color->getSource()->getAllOptions(false) as $option) {
        if (in_array($option['label'], ['BB Red', 'BB Blue'], true)) {
            $optionIds[$option['label']] = (int) $option['value'];
        }
    }

    // Make sure "color" is part of the Default attribute set.
    $attributeSet = $om->get(AttributeSetFactory::class)->create()->load($defaultSetId);
    $groupId = $attributeSet->getDefaultGroupId();
    $om->get(\Magento\Eav\Api\AttributeManagementInterface::class)
        ->assign('catalog_product', $defaultSetId, $groupId, 'color', 100);

    $childIds = [];
    $values = [];
    foreach ($optionIds as $label => $optionId) {
        $sku = 'bb-configurable-' . strtolower(str_replace('BB ', '', $label));
        $child = $makeSimple($sku, "Bluebarry Configurable $label", 80.00, Visibility::VISIBILITY_NOT_VISIBLE, ['color' => $optionId]);
        $childIds[] = (int) $child->getId();
        $values[] = ['label' => $label, 'attribute_id' => $color->getId(), 'value_index' => $optionId];
    }

    $configurableOptions = $om->get(ConfigurableOptionsFactory::class)->create([[
        'attribute_id' => $color->getId(),
        'code' => 'color',
        'label' => 'Color',
        'position' => 0,
        'values' => $values,
    ]]);

    $parent = $productFactory->create();
    $parent->setTypeId(Configurable::TYPE_CODE)
        ->setAttributeSetId($defaultSetId)
        ->setSku('bb-configurable')
        ->setName('Bluebarry Configurable Product')
        ->setUrlKey('bb-configurable')
        ->setVisibility(Visibility::VISIBILITY_BOTH)
        ->setStatus(Status::STATUS_ENABLED)
        ->setWebsiteIds([1])
        ->setTaxClassId(TAXABLE_GOODS_CLASS)
        ->setStockData(['use_config_manage_stock' => 1, 'is_in_stock' => 1]);
    $extension = $parent->getExtensionAttributes();
    $extension->setConfigurableProductOptions($configurableOptions);
    $extension->setConfigurableProductLinks($childIds);
    $parent->setExtensionAttributes($extension);
    $productRepo->save($parent);
}
// Older Magento lines (2.4.7) leave the parent out of stock when it is saved before its variants'
// stock is indexed; mark it in stock explicitly (idempotent, also repairs earlier installs).
$stockRegistry = $om->get(\Magento\CatalogInventory\Api\StockRegistryInterface::class);
$parentStock = $stockRegistry->getStockItemBySku('bb-configurable');
$parentStock->setIsInStock(true);
$stockRegistry->updateStockItemBySku('bb-configurable', $parentStock);
echo "product: bb-configurable (variants BB Red / BB Blue, EUR 80.00 excl. tax)\n";
