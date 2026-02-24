<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use ECInternet\RAPIDWebSync\Model\Db;
use ECInternet\RAPIDWebSync\Model\Magento\Environment;
use ECInternet\RAPIDWebSync\Util\ArrayString;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * Configurable Helper
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Configurable
{
    public const CONFIGURABLE_ATTRIBUTES = 'configurable_attributes';

    public const SIMPLES_SKUS_FIELD      = 'simples_skus';

    private $productIdColumn;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Attribute
     */
    private $attributeHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\StoreWebsite
     */
    private $storeWebsiteHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Model\Db
     */
    private $db;

    /**
     * @var \ECInternet\RAPIDWebSync\Model\Magento\Environment
     */
    private $magentoEnvironment;

    /**
     * @var \ECInternet\RAPIDWebSync\Util\ArrayString
     */
    private $arrayStringUtils;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * Configurable constructor.
     *
     * @param \ECInternet\RAPIDWebSync\Helper\Attribute          $attributeHelper
     * @param \ECInternet\RAPIDWebSync\Helper\StoreWebsite       $storeWebsiteHelper
     * @param \ECInternet\RAPIDWebSync\Model\Db                  $db
     * @param \ECInternet\RAPIDWebSync\Model\Magento\Environment $magentoEnvironment
     * @param \ECInternet\RAPIDWebSync\Util\ArrayString          $arrayStringUtils
     * @param \Psr\Log\LoggerInterface                           $logger
     */
    public function __construct(
        Attribute $attributeHelper,
        StoreWebsite $storeWebsiteHelper,
        Db $db,
        Environment $magentoEnvironment,
        ArrayString $arrayStringUtils,
        LoggerInterface $logger,
    ) {
        $this->attributeHelper    = $attributeHelper;
        $this->storeWebsiteHelper = $storeWebsiteHelper;
        $this->db                 = $db;
        $this->magentoEnvironment = $magentoEnvironment;
        $this->arrayStringUtils   = $arrayStringUtils;
        $this->logger             = $logger;

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
        if (!$this->isProductConfigurable($product)) {
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
            $attributeInfo = $this->attributeHelper->getCatalogProductAttributeInfoById($configurableAttributeId);

            // Try to get 'product_super_attribute_id' for attribute
            $productSuperAttributeId = $this->getProductSuperAttributeId($productId, $configurableAttributeId);

            // If we don't have one, try to create one
            if ($productSuperAttributeId === null) {
                /** @var int $productSuperAttributeId */
                $productSuperAttributeId = $this->addProductSuperAttributeRecord($productId, $configurableAttributeId, $superAttributeIndex);
            }

            // Insert / Update attribute value for association
            /** @var int[] $productStoreIds */
            $productStoreIds = $this->storeWebsiteHelper->getStoreIdsForProduct($product);
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
        $this->productIdColumn = $this->magentoEnvironment->getProductIdColumn();
    }

    private function isProductConfigurable(array $product)
    {
        return isset($product['type_id']) && $product['type_id'] === 'configurable';
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
                $configurableAttributeIds = $this->attributeHelper->getAttributeIdsFromCodes($attributeCodes);
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
        $this->log('createFixedSuperLink()', [
            'productId' => $productId,
            'skuArray'  => $skuArray
        ]);

        $skus = $this->arrayStringUtils->arrayToCommaSeparatedValueString($skuArray);
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
        $this->log('createSuperLink()', [
            'productId'     => $productId,
            'condition'     => $condition,
            'conditionData' => $conditionData
        ]);

        // Cache our table names
        $productSuperLinkTable = $this->db->getTableName('catalog_product_super_link');
        $productRelationTable  = $this->db->getTableName('catalog_product_relation');
        $productEntityTable    = $this->db->getTableName('catalog_product_entity');

        // TODO: Needs cleanup
        // Delete associations
        $query = "DELETE `cpsl`.*, `cpsr`.* FROM `$productSuperLinkTable` as `cpsl`
                  JOIN `$productRelationTable` as `cpsr` ON `cpsr`.`parent_id` = `cpsl`.`parent_id`
                  WHERE `cpsl`.`parent_id` = ?";
        $binds = [$productId];
        $this->db->delete($query, $binds);

        // Re-create associations
        $query = "INSERT INTO `$productSuperLinkTable` (`parent_id`, `product_id`)
                  SELECT
                    `cpec`.`$this->productIdColumn` as `parent_id`,
                    `cpes`.`entity_id` as `product_id`
                  FROM `$productEntityTable` as `cpec`
                  
                  JOIN `$productEntityTable` as `cpes`
                  ON `cpes`.`type_id` IN ('simple', 'virtual') AND `cpes`.`sku` $condition
                  
                  WHERE `cpec`.`$this->productIdColumn` = ?";
        $binds = array_merge($conditionData, [$productId]);
        $this->db->insert($query, $binds);

        $query = "INSERT INTO `$productRelationTable` (`parent_id`, `child_id`)
                  SELECT
                    `cpec`.`$this->productIdColumn` as `parent_id`,
                    `cpes`.`entity_id` as `child_id`
                  FROM `$productEntityTable` as `cpec`
                  
                  JOIN `$productEntityTable` as `cpes`
                  ON `cpes`.`type_id` IN ('simple','virtual') AND `cpes`.`sku` $condition

                  WHERE `cpec`.`$this->productIdColumn` = ?";
        $binds = array_merge($conditionData, [$productId]);
        $this->db->insert($query, $binds);
    }

    /**
     * @param int $productId
     */
    private function updateProductToBeConfigurable(int $productId)
    {
        $this->log('updateProductToBeConfigurable()', ['productId' => $productId]);

        $table = $this->db->getTableName('catalog_product_entity');
        $query = "UPDATE `$table` SET `type_id` = 'configurable', `has_options` = 1, `required_options` = 1 WHERE `$this->productIdColumn` = ?";
        $binds = [$productId];

        $this->db->update($query, $binds);
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

        $table = $this->db->getTableName('catalog_product_super_attribute');
        $query = "SELECT `product_super_attribute_id`
                  FROM `$table`
                  WHERE `product_id` = ? AND `attribute_id` = ?";
        $binds = [$productId, $attributeId];

        if ($result = $this->db->selectOne($query, $binds, 'product_super_attribute_id')) {
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

        $table = $this->db->getTableName('catalog_product_super_attribute');
        $query = "INSERT INTO `$table` (`product_id`, `attribute_id`, `position`) VALUES (?, ?, ?)";
        $binds = [$productId, $attributeId, $index];

        return $this->db->insert($query, $binds);
    }

    /**
     * @param int   $productSuperAttributeId
     * @param int   $storeId
     * @param mixed $value
     *
     * @return void
     */
    private function upsertProductSuperAttributeLabelRecord(
        int $productSuperAttributeId,
        int $storeId,
        string $value
    ) {
        $this->log('upsertProductSuperAttributeLabelRecord()', [
            'productSuperAttributeId' => $productSuperAttributeId,
            'storeId'                 => $storeId,
            'value'                   => $value
        ]);

        $table = $this->db->getTableName('catalog_product_super_attribute_label');
        $query = "INSERT INTO `$table`
                  (`product_super_attribute_id`, `store_id`, `use_default`, `value`) VALUES (?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE value=VALUES(`value`)";
        $binds = [$productSuperAttributeId, $storeId, 1, $value];

        $this->db->insert($query, $binds);
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
        $this->logger->info('Helper/Configurable - ' . $message, $extra);
    }
}
