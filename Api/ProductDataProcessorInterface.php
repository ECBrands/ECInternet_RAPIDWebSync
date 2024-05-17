<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Api;

interface ProductDataProcessorInterface
{
    public function beforeImport();

    public function startImport();

    public function processColumnList();

    public function processItemBeforeId(array $productData, string $sku);

    public function process(array $productData, string $sku, int $productId);

    public function preprocessItemAfterId(array $productData, string $sku, int $productId);

    public function processItemAfterId(array $productData, string $sku, int $productId);

    public function endImport();

    public function afterImport();
}
