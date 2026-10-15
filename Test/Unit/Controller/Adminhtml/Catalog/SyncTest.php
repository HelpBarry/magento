<?php

namespace Bluebarry\Bluebarry\Test\Unit\Controller\Adminhtml\Catalog;

use Bluebarry\Bluebarry\Controller\Adminhtml\Catalog\Sync;
use Bluebarry\Bluebarry\Model\Catalog\Categories;
use Bluebarry\Bluebarry\Model\Catalog\Sync as CatalogSync;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Area;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Sync now on the settings page: what bin/magento bluebarry:catalog:sync does, in a short request.
 */
class SyncTest extends TestCase
{
    private bool $connected = true;
    private int $queued = 0;
    private ?string $error = null;
    private bool $queueAllWorks = true;
    /** @var array{sent: int, queued: int, busy?: bool} */
    private array $run = ['sent' => 0, 'queued' => 0];
    private array $categoriesResult = ['sent' => 0, 'failed' => 0];
    private ?\Exception $queueAllThrows = null;
    /** @var string[] */
    private array $calls = [];
    private ?array $answer = null;

    public function testNothingRunsWithoutAConnectedWebsite(): void
    {
        $this->connected = false;

        $this->syncNow();

        $this->assertSame([
            'ok' => false,
            'message' => 'No website is connected to bluebarry yet: enter a Tenant ID and API key and save first.',
        ], $this->answer);
        $this->assertSame(['emulate 1', 'stop emulation'], $this->calls);
    }

    public function testWithNothingWaitingTheWholeCatalogIsQueuedAndSent(): void
    {
        $this->run = ['sent' => 120, 'queued' => 0];

        $this->syncNow();

        $this->assertSame(['emulate 1', 'queueAll', 'run 20', 'categories now', 'stop emulation'], $this->calls);
        $this->assertSame(['ok' => true, 'message' => 'Sent 120 products to bluebarry.'], $this->answer);
    }

    public function testWhatWaitsIsSentWithoutStartingTheCatalogOver_TheRestGoesWithTheCron(): void
    {
        // A second click halfway through a big catalog: it carries on where the first stopped.
        $this->queued = 900;
        $this->run = ['sent' => 400, 'queued' => 500];

        $this->syncNow();

        $this->assertSame(['emulate 1', 'run 20', 'categories now', 'stop emulation'], $this->calls);
        $this->assertSame([
            'ok' => true,
            'message' => 'Sent 400 products to bluebarry; 500 more follow with the next cron runs (every minute).',
        ], $this->answer);
    }

    public function testWhileTheCronHoldsTheSyncNothingIsQueued(): void
    {
        $this->queueAllWorks = false;

        $this->syncNow();

        $this->assertSame(['emulate 1', 'queueAll', 'stop emulation'], $this->calls);
        $this->assertSame([
            'ok' => false,
            'message' => 'The cron is sending the catalog right now. Catalog shows how many products are still waiting.',
        ], $this->answer);
    }

    public function testWhileTheCronSendsNothingIsSent(): void
    {
        $this->queued = 30;
        $this->run = ['sent' => 0, 'queued' => 30, 'busy' => true];

        $this->syncNow();

        $this->assertSame(['emulate 1', 'run 20', 'stop emulation'], $this->calls);
        $this->assertSame([
            'ok' => false,
            'message' => 'The cron is sending the catalog right now. Catalog shows how many products are still waiting.',
        ], $this->answer);
    }

    public function testARefusedBatchSaysWhyAndWhenItIsTriedAgain(): void
    {
        $this->queued = 250;
        $this->run = ['sent' => 0, 'queued' => 250];
        $this->error = 'bluebarry refused the API key.';

        $this->syncNow();

        $this->assertSame([
            'ok' => false,
            'message' => 'Sent 0 products to bluebarry. 250 products waiting: bluebarry refused the API key. Trying again every 5 minutes.',
        ], $this->answer);
    }

    public function testACompanyThatMissedTheBatchIsNamed_AlsoWhenTheOthersEmptiedTheQueue(): void
    {
        $this->run = ['sent' => 80, 'queued' => 0];
        $this->error = 'bluebarry answered HTTP 503.';

        $this->syncNow();

        $this->assertSame([
            'ok' => false,
            'message' => 'Sent 80 products to bluebarry. Not sent: bluebarry answered HTTP 503. Trying again every 5 minutes.',
        ], $this->answer);
    }

    public function testCategoriesThatCouldNotBeSentAreReported(): void
    {
        $this->run = ['sent' => 12, 'queued' => 0];
        $this->categoriesResult = ['sent' => 0, 'failed' => 1];

        $this->syncNow();

        $this->assertSame([
            'ok' => false,
            'message' => 'Sent 12 products to bluebarry. The categories could not be sent; the cron tries again.',
        ], $this->answer);
    }

    public function testAFailureEndsTheStoreEmulation_AndIsShown(): void
    {
        $this->queueAllThrows = new \RuntimeException('Deadlock found when trying to get lock');

        $this->syncNow();

        $this->assertSame(['emulate 1', 'queueAll', 'stop emulation'], $this->calls);
        $this->assertSame([
            'ok' => false,
            'message' => 'Could not send the catalog: Deadlock found when trying to get lock',
        ], $this->answer);
    }

    private function syncNow(): void
    {
        $sync = $this->createStub(CatalogSync::class);
        $sync->method('targets')->willReturnCallback(fn () => $this->connected
            ? [['store' => $this->createStub(Store::class), 'tenantId' => 'tenant', 'apiKey' => 'key']]
            : []);
        $sync->method('status')->willReturnCallback(fn () => [
            'queued' => $this->queued, 'sent_at' => null, 'full_at' => null, 'error' => $this->error,
        ]);
        $sync->method('queueAll')->willReturnCallback(function () {
            $this->calls[] = 'queueAll';
            if ($this->queueAllThrows) {
                throw $this->queueAllThrows;
            }
            return $this->queueAllWorks;
        });
        $sync->method('run')->willReturnCallback(function (int $seconds) {
            $this->calls[] = 'run ' . $seconds;
            return $this->run;
        });
        $categories = $this->createStub(Categories::class);
        $categories->method('run')->willReturnCallback(function (bool $now) {
            $this->calls[] = $now ? 'categories now' : 'categories';
            return $this->categoriesResult;
        });

        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->answer = $data;
            return $json;
        });
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        // The cron's store: the default store view, read as the storefront.
        $defaultView = $this->createStub(Store::class);
        $defaultView->method('getId')->willReturn('1');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($defaultView);
        $emulation = $this->createStub(Emulation::class);
        $emulation->method('startEnvironmentEmulation')->willReturnCallback(function ($storeId, $area, $force) {
            $this->assertSame(Area::AREA_FRONTEND, $area);
            $this->assertTrue($force);
            $this->calls[] = 'emulate ' . $storeId;
        });
        $emulation->method('stopEnvironmentEmulation')->willReturnCallback(function () use ($emulation) {
            $this->calls[] = 'stop emulation';
            return $emulation;
        });

        $controller = new Sync($this->createStub(Context::class), $sync, $categories, $jsonFactory, $emulation, $storeManager);
        $this->assertSame($json, $controller->execute());
    }
}
