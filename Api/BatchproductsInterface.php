<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Api;

interface BatchproductsInterface
{
    /**
     * Add Products
     *
     * @api
     * @return array
     */
    public function add();

    /**
     * Update Products
     *
     * @api
     * @return array
     */
    public function update();

    /**
     * Add or update Products
     *
     * @api
     * @return array
     */
    public function upsert();

    /**
     * Return a string array of 'sales_order' table columns
     *
     * @api
     * @return array
     */
    public function getSalesOrderColumns();

    /**
     * Return an array of ProductAttribute Codes
     *
     * @api
     * @return array
     */
    public function getProductAttributeCodes();

    /**
     * Reindex Magento tables.
     *
     * @api
     * @return void
     */
    public function reindex();

    /**
     * Reindex Magento tables.
     *
     * @api
     * @return void
     */
    public function reindexTables();

    /**
     * Return the Magento version.
     *
     * @api
     * @return string
     */
    public function getMagentoVersion();

    /**
     * Return the Magento edition.
     *
     * @api
     * @return string
     */
    public function getMagentoEdition();
}
