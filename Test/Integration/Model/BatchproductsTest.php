<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */

/** @noinspection PhpPropertyOnlyWrittenInspection */

declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Test\Integration\Model;

use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use ECInternet\RAPIDWebSync\Model\Batchproducts;

class BatchproductsTest extends TestCase
{
    private $batchProducts;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->batchProducts = $objectManager->get(Batchproducts::class);
    }
}
