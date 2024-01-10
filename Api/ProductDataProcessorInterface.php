<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Api;

interface ProductDataProcessorInterface
{
    public function process(array $productData, string $sku, int $productId);
}
