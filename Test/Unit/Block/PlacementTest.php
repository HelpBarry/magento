<?php

namespace Bluebarry\Bluebarry\Test\Unit\Block;

use Bluebarry\Bluebarry\Block\Placement;
use Bluebarry\Bluebarry\Model\Catalog\PageProduct;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Model\Storefront;
use Magento\Catalog\Model\Product;
use Magento\Csp\Helper\CspNonceProvider;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class PlacementTest extends TestCase
{
    private const TENANT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const BLOCK = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const QUIZ = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    /** @var string[] the page's product as the catalog sync sends it */
    private array $references = ['17', '18'];
    private ?string $opensWith = '17';
    private ?int $group = 16;
    private bool $onProductPage = true;
    private ?string $tenant = self::TENANT;
    private array $placements = [];
    /** @var array<string, string> product => quiz */
    private array $checks = ['16' => self::QUIZ];

    public function testOnAProductPageABlockRecommendsFromTheProduct_LedByTheVariantThePageOpensWith(): void
    {
        $this->assertSame([
            'data-bluebarry-recommendations' => self::BLOCK,
            'data-product-ids' => '17,18',
            'data-anchor-id' => '17',
        ], $this->placement()->getRecommendationAttributes(strtoupper(self::BLOCK)));
    }

    public function testAnywhereElseItRecommendsFromTheCart_AndStaysHiddenUntilTheCartIsKnown(): void
    {
        $this->onProductPage = false;

        $this->assertSame([
            'data-bluebarry-recommendations' => self::BLOCK,
            'data-cart-anchored' => 'true',
            'hidden' => 'hidden',
        ], $this->placement()->getRecommendationAttributes(self::BLOCK));
    }

    public function testAProductBluebarryDoesNotKnowGetsNoBlock_RatherThanOneFromTheCart(): void
    {
        // A grouped product, or a configurable product without synced variants.
        $this->references = [];
        $this->opensWith = null;

        $this->assertNull($this->placement()->getRecommendationAttributes(self::BLOCK));
    }

    public function testNoBlockWithoutAnIdOrWithoutBluebarryOnThisStoreView(): void
    {
        $this->assertNull($this->placement()->getRecommendationAttributes('not-a-block'));
        $this->assertNull($this->placement()->getRecommendationAttributes(null));
        $this->tenant = null;
        $this->assertNull($this->placement()->getRecommendationAttributes(self::BLOCK));
    }

    public function testTheProductCheckButtonOpensTheQuizOfTheProductAsBluebarryGroupsIt(): void
    {
        $this->placements = ['productCheck' => ['buttonText' => 'Is this my size?']];

        $this->assertSame(['href' => '#bluebarry:' . self::QUIZ . '/17', 'text' => 'Is this my size?'], $this->placement()->getProductCheck());
        // The widget's own text wins over Studio's.
        $this->assertSame('Fits me?', $this->placement()->getProductCheck(' Fits me? ')['text']);
    }

    public function testWithoutATextTheButtonSaysTheDefault_AndAProductWithoutAQuizHasNone(): void
    {
        $this->assertSame(Placement::DEFAULT_TEXT, $this->placement()->getProductCheck()['text']);

        // A simple product stands for itself: the quiz is assigned to its own id.
        $this->group = null;
        $this->assertNotNull($this->placement()->getProductCheck());
        $this->checks = [];
        $this->assertNull($this->placement()->getProductCheck());
        $this->onProductPage = false;
        $this->assertNull($this->placement()->getProductCheck());
    }

    private function placement(): Placement
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getWebsiteId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $context = $this->createStub(Context::class);
        $context->method('getStoreManager')->willReturn($stores);

        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn(16);
        $registry = $this->createStub(Registry::class);
        $registry->method('registry')->willReturnCallback(fn ($key) => $key === 'current_product' && $this->onProductPage ? $product : null);

        $pageProduct = $this->createStub(PageProduct::class);
        $pageProduct->method('references')->willReturnCallback(fn () => $this->references);
        $pageProduct->method('defaultReference')->willReturnCallback(fn () => $this->opensWith);
        $pageProduct->method('groupId')->willReturnCallback(fn () => $this->group);

        $storefront = $this->createStub(Storefront::class);
        $storefront->method('placement')->willReturnCallback(fn ($website, $tenant, $name) => $this->placements[$name] ?? null);
        $storefront->method('productCheck')->willReturnCallback(fn ($website, $tenant, $productId) => $this->checks[$productId] ?? null);
        $config = $this->createStub(Config::class);
        $config->method('getTenantId')->willReturnCallback(fn () => $this->tenant);

        return new Placement($context, $storefront, $pageProduct, $config, $registry, $this->createStub(CspNonceProvider::class));
    }
}
