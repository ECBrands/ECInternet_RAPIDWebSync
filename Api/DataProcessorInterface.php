<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Api;

interface DataProcessorInterface
{
    /**
     * Process product data
     *
     * @param array  $productData
     * @param string $sku
     * @param int    $entityId
     *
     * @return void
     */
    public function processProductData(array $productData, string $sku, int $entityId);
}
