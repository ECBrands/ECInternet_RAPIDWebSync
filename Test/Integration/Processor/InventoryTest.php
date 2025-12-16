<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Test\Integration\Processor;

use Magento\TestFramework\Helper\Bootstrap;
use ECInternet\RAPIDWebSync\Processor\Inventory;

/**
 * @magentoDbIsolation disabled
 */
class InventoryTest extends \PHPUnit\Framework\TestCase
{
    private $inventory;

    protected function setUp(): void
    {
        // Load classes from here
        $objectManager = Bootstrap::getObjectManager();

        // Test class
        $this->inventory = $objectManager->get(Inventory::class);
    }

    public function testProcessProductData()
    {
        $productData = [
            'sku' => 'AAA'
        ];

        $this->inventory->processProductData($productData, 'AAA', 1);
    }
}
