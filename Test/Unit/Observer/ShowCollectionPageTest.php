<?php

namespace Bluebarry\Bluebarry\Test\Unit\Observer;

use Bluebarry\Bluebarry\Model\CollectionPages;
use Bluebarry\Bluebarry\Model\Config;
use Bluebarry\Bluebarry\Observer\ShowCollectionPage;
use Magento\Catalog\Model\Category;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Registry;
use Magento\Framework\View\DesignInterface;
use Magento\Framework\View\Layout;
use Magento\Framework\View\Layout\ProcessorInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Theme\Model\Theme;
use PHPUnit\Framework\TestCase;

class ShowCollectionPageTest extends TestCase
{
    private const TENANT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private array $handles = [];
    private ?string $layout = null;

    public function testShowsThePage_WithTheHandlesItAsksFor(): void
    {
        $this->run_();
        $this->assertSame([ShowCollectionPage::HANDLE, ShowCollectionPage::HANDLE . '_title'], $this->handles);
        $this->assertSame('1column', $this->layout);
    }

    public function testAnotherActionLeavesThePageAlone(): void
    {
        $this->run_(['action' => 'catalog_product_view']);
        $this->assertSame([], $this->handles);
    }

    public function testACategoryWithoutAPageStaysMagentos(): void
    {
        $this->run_(['page' => null]);
        $this->assertSame([], $this->handles);
        $this->assertNull($this->layout);
    }

    public function testAStoreViewOfAnotherCompanyStaysMagentos(): void
    {
        $this->run_(['tenant' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc']);
        $this->assertSame([], $this->handles);
    }

    public function testAShopperInAnotherCurrencySeesMagentosPage(): void
    {
        $this->run_(['currency' => 'USD']);
        $this->assertSame([], $this->handles);
    }

    public function testAStaticBlockOnlyCategoryStaysMagentos(): void
    {
        $this->run_(['displayMode' => Category::DM_PAGE]);
        $this->assertSame([], $this->handles);
    }

    public function testACategoryWithItsOwnLayoutUpdateStaysMagentos(): void
    {
        $this->run_(['update' => 'custom.xml']);
        $this->assertSame([], $this->handles);
    }

    public function testACategoryInheritingALayoutUpdateStaysMagentos(): void
    {
        $this->run_(['inheritedUpdate' => 'custom.xml']);
        $this->assertSame([], $this->handles);
    }

    public function testAThemeOfAnUnknownFamilyStaysMagentos(): void
    {
        $this->run_(['themes' => ['Acme/custom']]);
        $this->assertSame([], $this->handles);
    }

    public function testAThemeBuiltOnLumaOrHyvaShowsIt(): void
    {
        $this->run_(['themes' => ['Acme/custom', 'Magento/luma']]);
        $this->assertNotSame([], $this->handles);
        $this->handles = [];
        $this->run_(['themes' => ['Hyva/default']]);
        $this->assertNotSame([], $this->handles);
    }

    private function run_(array $given = []): void
    {
        $given += [
            'action' => 'catalog_category_view',
            'page' => ['tenant' => self::TENANT, 'title' => true, 'description' => false],
            'tenant' => self::TENANT,
            'currency' => 'EUR',
            'displayMode' => Category::DM_PRODUCT,
            'update' => null,
            'inheritedUpdate' => null,
            'themes' => ['Magento/luma'],
        ];
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn(7);
        $category->method('getDisplayMode')->willReturn($given['displayMode']);
        $category->method('getData')->willReturnCallback(fn ($key = '') => $key === 'custom_layout_update_file' ? $given['update'] : null);
        $parent = $this->createMock(Category::class);
        $parent->method('getData')->willReturnCallback(fn ($key = '') => $key === 'custom_layout_update_file' ? $given['inheritedUpdate'] : null);
        $category->method('getParentDesignCategory')->willReturn($parent);
        $registry = $this->createMock(Registry::class);
        $registry->method('registry')->with('current_category')->willReturn($category);
        $store = $this->getMockBuilder(Store::class)->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getCurrentCurrencyCode', 'getDefaultCurrencyCode'])->getMock();
        $store->method('getId')->willReturn(1);
        $store->method('getCurrentCurrencyCode')->willReturn($given['currency']);
        $store->method('getDefaultCurrencyCode')->willReturn('EUR');
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $pages = $this->createMock(CollectionPages::class);
        $pages->method('active')->with(1, 7)->willReturn($given['page']);
        $config = $this->createMock(Config::class);
        $config->method('getTenantId')->willReturn($given['tenant']);
        $theme = null;
        foreach (array_reverse($given['themes']) as $code) {
            $next = $this->getMockBuilder(Theme::class)->disableOriginalConstructor()->onlyMethods(['getCode', 'getParentTheme'])->getMock();
            $next->method('getCode')->willReturn($code);
            $next->method('getParentTheme')->willReturn($theme);
            $theme = $next;
        }
        $design = $this->createMock(DesignInterface::class);
        $design->method('getDesignTheme')->willReturn($theme);
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('setPageLayout')->willReturnCallback(function ($layout) use ($pageConfig) {
            $this->layout = $layout;
            return $pageConfig;
        });
        $update = $this->createMock(ProcessorInterface::class);
        $update->method('addHandle')->willReturnCallback(function ($handle) use ($update) {
            $this->handles[] = $handle;
            return $update;
        });
        $layout = $this->createMock(Layout::class);
        $layout->method('getUpdate')->willReturn($update);
        $event = new Event(['full_action_name' => $given['action'], 'layout' => $layout]);
        (new ShowCollectionPage($pages, $config, $registry, $stores, $design, $pageConfig))->execute(new Observer(['event' => $event]));
    }
}
