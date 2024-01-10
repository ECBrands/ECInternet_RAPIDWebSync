<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use ECInternet\RAPIDWebSync\Logger\Logger;
use Exception;

/**
 * Configurable Helper
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class Configurable
{
    public const CONFIGURABLE_ATTRIBUTES = 'configurable_attributes';

    public const SIMPLES_SKUS_FIELD      = 'simples_skus';

    private $_productIdColumn;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Data
     */
    private $_helper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Attribute
     */
    private $_attributeHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Db
     */
    private $_dbHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\StoreWebsite
     */
    private $_storeWebsiteHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Logger\Logger
     */
    private $_logger;

    /**
     * Configurable constructor.
     *
     * @param \ECInternet\RAPIDWebSync\Helper\Data         $helper
     * @param \ECInternet\RAPIDWebSync\Helper\Attribute    $attributeHelper
     * @param \ECInternet\RAPIDWebSync\Helper\Db           $dbHelper
     * @param \ECInternet\RAPIDWebSync\Helper\StoreWebsite $storeWebsiteHelper
     * @param \ECInternet\RAPIDWebSync\Logger\Logger       $logger
     */
    public function __construct(
        Data $helper,
        Attribute $attributeHelper,
        Db $dbHelper,
        StoreWebsite $storeWebsiteHelper,
        Logger $logger
    ) {
        $this->_helper             = $helper;
        $this->_attributeHelper    = $attributeHelper;
        $this->_dbHelper           = $dbHelper;
        $this->_storeWebsiteHelper = $storeWebsiteHelper;
        $this->_logger             = $logger;

        $this->initializeProductIdColumn();
    }

    /**
     * @param array   $product
     * @param string  $sku
     * @param int     $productId
     * @param boolean $isNew
     *
     * @throws \Exception
     */
    public function processProduct(array $product, string $sku, int $productId, bool $isNew)
    {
        $this->log('| -- Start Configurable Product Processor --');
        $this->log("| Sku: [$sku]");
        $this->log("| ProductId: [$productId]");

        // Make sure we have a configurable product, or leave
        if (!$this->_helper->isProductConfigurable($product)) {
            $this->log('| NOTE: Product is not configurable.');
            $this->log('| -- End Configurable Product Processor --' . PHP_EOL);

            return;
        }

        if ($isNew) {
            if (!isset($product[self::CONFIGURABLE_ATTRIBUTES])) {
                $this->log("| NOTE: Attribute '" . self::CONFIGURABLE_ATTRIBUTES . "' not set.");
                $this->log('| -- End Configurable Product Processor --' . PHP_EOL);

                return;
            }

            if (!isset($product[self::SIMPLES_SKUS_FIELD])) {
                $this->log("| NOTE: Attribute '" . self::SIMPLES_SKUS_FIELD . "' not set.");
                $this->log('| -- End Configurable Product Processor --' . PHP_EOL);

                return;
            }
        }

        // Prep attribute and product_id arrays
        $configurableAttributeIds = $this->getConfigurableAttributeIds($product);

        // Mark product as 'configurable' and requiring options
        $this->updateProductToBeConfigurable($productId);

        $superAttributeIndex = 0;
        foreach ($configurableAttributeIds as $configurableAttributeId) {
            // Get attribute info
            $attributeInfo = $this->_attributeHelper->getCatalogProductAttributeInfoById($configurableAttributeId);

            // Try to get 'product_super_attribute_id' for attribute
            $productSuperAttributeId = $this->getProductSuperAttributeId($productId, $configurableAttributeId);

            // If we don't have one, try to create one
            if ($productSuperAttributeId === null) {
                /** @var int $productSuperAttributeId */
                $productSuperAttributeId = $this->addProductSuperAttributeRecord($productId, $configurableAttributeId, $superAttributeIndex);
            }

            // Insert / Update attribute value for association
            /** @var int[] $productStoreIds */
            $productStoreIds = $this->_storeWebsiteHelper->getStoreIdsForProduct($product);
            foreach ($productStoreIds as $productStoreId) {
                $this->upsertProductSuperAttributeLabelRecord($productSuperAttributeId, $productStoreId, (string)$attributeInfo['frontend_label']);
            }

            $superAttributeIndex++;
        }

        // Add super links
        // ASSUME SIMPLES_SKUS
        if (isset($product[self::SIMPLES_SKUS_FIELD])) {
            $this->createFixedSuperLink($productId, explode(',', (string)$product[self::SIMPLES_SKUS_FIELD]));
        } else {
            $this->log("| NOTE: Attribute '" . self::SIMPLES_SKUS_FIELD . "' not populated.");
        }

        $this->log('| -- End Configurable Product Processor --' . PHP_EOL);
    }

    /**
     * @return void
     */
    private function initializeProductIdColumn()
    {
        $this->_productIdColumn = $this->_helper->getProductIdColumn();
    }

    /**
     * Get attribute ids for configurable attributes on product
     *
     * @param array $product
     *
     * @return int[]
     * @throws Exception
     */
    private function getConfigurableAttributeIds(array $product)
    {
        $configurableAttributeIds = [];

        if (isset($product[self::CONFIGURABLE_ATTRIBUTES])) {
            if ($attributeCodes = explode(',', (string)$product[self::CONFIGURABLE_ATTRIBUTES])) {
                $configurableAttributeIds = $this->_attributeHelper->getAttributeIdsFromCodes($attributeCodes);
            }
        }

        return $configurableAttributeIds;
    }

    /**
     * @param int   $productId
     * @param array $skuArray
     *
     * @return void
     */
    private function createFixedSuperLink(int $productId, array $skuArray)
    {
        $this->log('createFixedSuperLink()', [$productId, $skuArray]);

        $skus = $this->_helper->arrayToCommaSeparatedValueString($skuArray);
        $this->createSuperLink($productId, "IN ($skus)", $skuArray);
    }

    /**
     * Create 'catalog_product_super_link' record
     *
     * @param int      $productId
     * @param string   $condition
     * @param string[] $conditionData
     */
    private function createSuperLink(int $productId, string $condition, array $conditionData = [])
    {
        $this->log('createSuperLink()', [$productId, $condition, $conditionData]);

        // Cache our table names
        $productSuperLinkTable = $this->_dbHelper->getTableName('catalog_product_super_link');
        $productRelationTable  = $this->_dbHelper->getTableName('catalog_product_relation');
        $productEntityTable    = $this->_dbHelper->getTableName('catalog_product_entity');

        // TODO: Needs cleanup
        // Delete associations
        $query = "DELETE `cpsl`.*, `cpsr`.* FROM `$productSuperLinkTable` as `cpsl`
                  JOIN `$productRelationTable` as `cpsr` ON `cpsr`.`parent_id` = `cpsl`.`parent_id`
                  WHERE `cpsl`.`parent_id` = ?";
        $binds = [$productId];
        $this->_dbHelper->delete($query, $binds);

        // Re-create associations
        $query = "INSERT INTO `$productSuperLinkTable` (`parent_id`, `product_id`)
                  SELECT
                    `cpec`.`$this->_productIdColumn` as `parent_id`,
                    `cpes`.`entity_id` as `product_id`
                  FROM `$productEntityTable` as `cpec`
                  
                  JOIN `$productEntityTable` as `cpes`
                  ON `cpes`.`type_id` IN ('simple', 'virtual') AND `cpes`.`sku` $condition
                  
                  WHERE `cpec`.`$this->_productIdColumn` = ?";
        $binds = array_merge($conditionData, [$productId]);
        $this->_dbHelper->insert($query, $binds);

        $query = "INSERT INTO `$productRelationTable` (`parent_id`, `child_id`)
                  SELECT
                    `cpec`.`$this->_productIdColumn` as `parent_id`,
                    `cpes`.`entity_id` as `child_id`
                  FROM `$productEntityTable` as `cpec`
                  
                  JOIN `$productEntityTable` as `cpes`
                  ON `cpes`.`type_id` IN ('simple','virtual') AND `cpes`.`sku` $condition

                  WHERE `cpec`.`$this->_productIdColumn` = ?";
        $binds = array_merge($conditionData, [$productId]);
        $this->_dbHelper->insert($query, $binds);
    }

    /**
     * @param int $productId
     */
    private function updateProductToBeConfigurable(int $productId)
    {
        $this->log('updateProductToBeConfigurable()', [$productId]);

        $table = $this->_dbHelper->getTableName('catalog_product_entity');
        $query = "UPDATE `$table` SET `type_id` = 'configurable', `has_options` = 1, `required_options` = 1 WHERE `$this->_productIdColumn` = ?";
        $binds = [$productId];

        $this->_dbHelper->update($query, $binds);
    }

    /**
     * @param int $productId
     * @param int $attributeId
     *
     * @return int|null
     */
    private function getProductSuperAttributeId(int $productId, int $attributeId)
    {
        $this->log('getProductSuperAttributeId()', [
            'productId'   => $productId,
            'attributeId' => $attributeId
        ]);

        $table = $this->_dbHelper->getTableName('catalog_product_super_attribute');
        $query = "SELECT `product_super_attribute_id`
                  FROM `$table`
                  WHERE `product_id` = ? AND `attribute_id` = ?";
        $binds = [$productId, $attributeId];

        if ($result = $this->_dbHelper->selectOne($query, $binds, 'product_super_attribute_id')) {
            if (is_numeric($result)) {
                return (int)$result;
            }
        }

        return null;
    }

    /**
     * @param int $productId
     * @param int $attributeId
     * @param int $index
     *
     * @return int
     */
    private function addProductSuperAttributeRecord(int $productId, int $attributeId, int $index)
    {
        $this->log('addProductSuperAttributeRecord()', [
            'productId'   => $productId,
            'attributeId' => $attributeId,
            'index'       => $index
        ]);

        $table = $this->_dbHelper->getTableName('catalog_product_super_attribute');
        $query = "INSERT INTO `$table` (`product_id`, `attribute_id`, `position`) VALUES (?, ?, ?)";
        $binds = [$productId, $attributeId, $index];

        return $this->_dbHelper->insert($query, $binds);
    }

    /**
     * @param int   $productSuperAttributeId
     * @param int   $storeId
     * @param mixed $value
     *
     * @return void
     */
    private function upsertProductSuperAttributeLabelRecord(int $productSuperAttributeId, int $storeId, string $value)
    {
        $this->log('upsertProductSuperAttributeLabelRecord()', [
            'productSuperAttributeId' => $productSuperAttributeId,
            'storeId'                 => $storeId,
            'value'                   => $value
        ]);

        $table = $this->_dbHelper->getTableName('catalog_product_super_attribute_label');
        $query = "INSERT INTO `$table`
                  (`product_super_attribute_id`, `store_id`, `use_default`, `value`) VALUES (?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE value=VALUES(`value`)";
        $binds = [$productSuperAttributeId, $storeId, 1, $value];

        $this->_dbHelper->insert($query, $binds);
    }

    /**
     * Write to extension log
     *
     * @param string $message
     * @param array  $extra
     *
     * @return void
     */
    private function log(string $message, array $extra = [])
    {
        $this->_logger->info('Helper/Configurable - ' . $message, $extra);
    }
}
