<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Api;

interface BulkOperationInterface
{
    /**
     * Add Products
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface[] $products
     *
     * @api
     * @return mixed[]
     */
    public function add(array $products);

    /**
     * Update Products
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface[] $products
     *
     * @api
     * @return mixed[]
     */
    public function update(array $products);

    /**
     * Add or update Products
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface[] $products
     *
     * @api
     * @return mixed[]
     */
    public function upsert(array $products);
}
