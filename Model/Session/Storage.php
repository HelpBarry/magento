<?php
namespace Bluebarry\Bluebarry\Model\Session;

use \Magento\Store\Model\StoreManagerInterface;

class Storage extends \Magento\Framework\Session\Storage
{
    /**
     * @param StoreManagerInterface $storeManager
     * @param string $namespace
     * @param array $data
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        $namespace = 'bluebarry',
        array $data = []
    ) {
        parent::__construct($namespace, $data);
    }
}