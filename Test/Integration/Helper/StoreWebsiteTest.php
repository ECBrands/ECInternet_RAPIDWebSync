<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Test\Integration\Helper;

use Magento\TestFramework\Helper\Bootstrap;
use ECInternet\RAPIDWebSync\Helper\StoreWebsite;

/**
 * @magentoDbIsolation disabled
 */
class StoreWebsiteTest extends \PHPUnit\Framework\TestCase
{
    private $storeWebsite;

    protected function setUp(): void
    {
        // Load classes from here
        $objectManager = Bootstrap::getObjectManager();

        // Test class
        $this->storeWebsite = $objectManager->get(StoreWebsite::class);
    }

    public function testGetStoreIds()
    {
        $result = $this->storeWebsite->getStoreIds();
        $this->assertIsArray($result);
        $this->assertContainsOnly('int', $result);
        $this->assertTrue(count($result) >= 1);
    }
}
