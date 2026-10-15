<?php

namespace Bluebarry\Bluebarry\Controller\Adminhtml\Catalog;

use Bluebarry\Bluebarry\Model\Catalog\Categories;
use Bluebarry\Bluebarry\Model\Catalog\Sync as CatalogSync;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;

/**
 * "Sync now" next to Catalog on the settings page (bluebarry/catalog/sync, POST with the form key):
 * what bin/magento bluebarry:catalog:sync does, for merchants without the command line or whose cron
 * does not run yet. The whole catalog when nothing waits; otherwise what waits, so clicking again works
 * through a big catalog instead of starting it over. Stops after SECONDS; the cron sends the rest.
 *
 * Runs in the default store view, as the cron does: the admin's own store would put ?___store= on the
 * addresses of a store with more than one store view.
 */
class Sync extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Bluebarry_Bluebarry::config';

    /** Keeps the request short; what is left goes with the next cron runs. */
    public const SECONDS = 20;

    /**
     * @var CatalogSync
     */
    private $sync;

    /**
     * @var Categories
     */
    private $categories;

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var Emulation
     */
    private $emulation;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param Context $context
     * @param CatalogSync $sync
     * @param Categories $categories
     * @param JsonFactory $jsonFactory
     * @param Emulation $emulation
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        CatalogSync $sync,
        Categories $categories,
        JsonFactory $jsonFactory,
        Emulation $emulation,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->sync = $sync;
        $this->categories = $categories;
        $this->jsonFactory = $jsonFactory;
        $this->emulation = $emulation;
        $this->storeManager = $storeManager;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $this->emulation->startEnvironmentEmulation(
            (int) $this->storeManager->getDefaultStoreView()->getId(),
            Area::AREA_FRONTEND,
            true
        );
        try {
            [$ok, $messages] = $this->syncNow();
        } catch (\Exception $e) {
            [$ok, $messages] = [false, [__('Could not send the catalog: %1', $e->getMessage())]];
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
        // Rendered in the admin's language, not the store view's the emulation switched to.
        return $this->jsonFactory->create()->setData([
            'ok' => $ok,
            'message' => implode(' ', array_map('strval', $messages)),
        ]);
    }

    /**
     * @return array{0: bool, 1: \Magento\Framework\Phrase[]}
     */
    private function syncNow(): array
    {
        if (!$this->sync->targets()) {
            return [false, [__('No website is connected to bluebarry yet: enter a Tenant ID and API key and save first.')]];
        }
        $busy = __('The cron is sending the catalog right now. Catalog shows how many products are still waiting.');
        if ($this->sync->status()['queued'] === 0 && !$this->sync->queueAll()) {
            return [false, [$busy]];
        }
        $result = $this->sync->run(self::SECONDS);
        if (!empty($result['busy'])) {
            return [false, [$busy]];
        }
        $categories = $this->categories->run(true);
        // A company that failed stays failed while the others emptied the queue.
        $error = $this->sync->status()['error'];

        $messages = [];
        if ($error === null && $result['queued'] > 0) {
            $messages[] = __('Sent %1 products to bluebarry; %2 more follow with the next cron runs (every minute).', $result['sent'], $result['queued']);
        } else {
            $messages[] = __('Sent %1 products to bluebarry.', $result['sent']);
        }
        if ($error !== null) {
            $messages[] = $result['queued'] > 0
                ? __('%1 products waiting: %2 Trying again every 5 minutes.', $result['queued'], $error)
                : __('Not sent: %1 Trying again every 5 minutes.', $error);
        }
        if ($categories['failed'] > 0) {
            $messages[] = __('The categories could not be sent; the cron tries again.');
        }
        return [$error === null && $categories['failed'] === 0, $messages];
    }
}
