<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\StateException;
use Magento\Framework\Exception\State\InitException;
use Magento\Framework\Phrase;
use ECInternet\RAPIDWebSync\Exception\IllegalNewAttributeOptionException;
use ECInternet\RAPIDWebSync\Logger\Logger;
use ECInternet\RAPIDWebSync\Model\Config\Source\IllegalNewAttributeActionOption;
use Exception;

/**
 * Attribute Helper
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Attribute
{
    /**
     * @var string[]
     */
    private $_validAttributeTypes = ['datetime', 'decimal', 'int', 'text', 'varchar'];

    /**
     * @var string[]
     */
    private $_imageAttributes = ['image', 'small_image', 'thumbnail'];

    /**
     * @var \Magento\Eav\Model\Config
     */
    private $_eavConfig;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Data
     */
    private $_helper;

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
     * @var string
     */
    private $_productIdColumn;

    /**
     * @var int
     */
    private $_catalogProductEntityTypeId;

    /**
     * @var array
     */
    private $_attributeSets;

    /**
     * @var array
     */
    private $_attributes;

    /**
     * Attribute constructor.
     *
     * @param \Magento\Eav\Model\Config                    $eavConfig
     * @param \ECInternet\RAPIDWebSync\Helper\Data         $helper
     * @param \ECInternet\RAPIDWebSync\Helper\Db           $dbHelper
     * @param \ECInternet\RAPIDWebSync\Helper\StoreWebsite $storeWebsiteHelper
     * @param \ECInternet\RAPIDWebSync\Logger\Logger       $logger
     */
    public function __construct(
        EavConfig $eavConfig,
        Data $helper,
        Db $dbHelper,
        StoreWebsite $storeWebsiteHelper,
        Logger $logger
    ) {
        $this->_eavConfig          = $eavConfig;
        $this->_helper             = $helper;
        $this->_dbHelper           = $dbHelper;
        $this->_storeWebsiteHelper = $storeWebsiteHelper;
        $this->_logger             = $logger;
    }

    /**
     * Run AttributeHelper on product array
     *
     * @param array  $product
     * @param string $sku
     * @param int    $productId
     *
     * @throws Exception
     */
    public function processProduct(array $product, string $sku, int $productId)
    {
        $this->log('| -- Start Attribute Processor --');
        $this->log("| Sku: [$sku]");
        $this->log("| ProductId: [$productId]");

        $this->upsertProductAttributes($product, $productId);

        $this->log('| -- End Product Attribute Processor --' . PHP_EOL);
    }

    /**
     * Lookup attribute set id by name
     *
     * @param string $attributeSetName
     *
     * @return int|null
     * @throws Exception
     */
    public function getAttributeSetId(string $attributeSetName)
    {
        if ($attributeSets = $this->getAttributeSets()) {
            if (isset($attributeSets[$attributeSetName])) {
                $attributeSetId = $attributeSets[$attributeSetName];
                if (is_numeric($attributeSetId)) {
                    return (int)$attributeSetId;
                }
            }
        }

        return null;
    }

    /**
     * Get array of attribute codes
     *
     * @return string[]
     * @throws Exception
     */
    public function getCatalogProductAttributeCodes()
    {
        if ($attributes = $this->getCatalogProductAttributes()) {
            return array_keys($attributes);
        }

        return [];
    }

    /**
     * Lookup attribute info by code
     *
     * @param string $attributeCode
     *
     * @return array{attribute_id: int, backend_type: string, frontend_input: string, frontend_label: string, source_model: string, is_global: int, apply_to: string}|null
     * @throws Exception
     */
    public function getCatalogProductAttributeInfoByCode(string $attributeCode)
    {
        //$this->log('getCatalogProductAttributeInfoByCode()', [$attributeCode]);

        if ($attributes = $this->getCatalogProductAttributes()) {
            if (isset($attributes[$attributeCode])) {
                return $attributes[$attributeCode];
            }
        }

        return null;
    }

    /**
     * Retrieve attribute_ids for an array of attribute_codes
     *
     * @param string[] $codeArray
     *
     * @return int[]
     * @throws Exception
     */
    public function getAttributeIdsFromCodes(array $codeArray)
    {
        $attributeIds = [];

        foreach ($codeArray as $code) {
            if ($code) {
                if ($id = $this->getAttributeIdFromCode(trim($code))) {
                    $attributeIds[] = $id;
                }
            }
        }

        return $attributeIds;
    }

    /**
     * Lookup attribute info by attribute_id
     *
     * @param int $attributeId
     *
     * @return array|null
     * @throws Exception
     */
    public function getCatalogProductAttributeInfoById(int $attributeId)
    {
        if ($attributes = $this->getCatalogProductAttributes()) {
            foreach ($attributes as $attribute) {
                if (isset($attribute['attribute_id'])) {
                    if ($attribute['attribute_id'] === $attributeId) {
                        return $attribute;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Insert / Update product attribute value
     *
     * @param int    $attributeId
     * @param int    $storeId
     * @param int    $productId
     * @param mixed  $value
     * @param string $attributeType
     *
     * @return void
     */
    public function upsertProductAttributeValue(int $attributeId, int $storeId, int $productId, $value, string $attributeType)
    {
        $this->log('upsertProductAttributeValue()', [
            'attributeId'   => $attributeId,
            'storeId'       => $storeId,
            'productId'     => $productId,
            'value'         => $value,
            'attributeType' => $attributeType
        ]);

        if ($this->doesProductAttributeValueExist($attributeId, $storeId, $productId, $attributeType)) {
            $this->updateProductAttributeValue($productId, $storeId, $attributeId, $attributeType, $value);
        } else {
            $this->insertProductAttributeValue($attributeId, $storeId, $productId, $value, $attributeType);
        }
    }

    /**
     * Determine product id column
     *
     * @return string
     */
    private function getProductIdColumn()
    {
        if ($this->_productIdColumn === null) {
            $this->_productIdColumn = $this->_helper->getProductIdColumn();
        }

        return $this->_productIdColumn;
    }

    /**
     * Get cached entity_type_id value for catalog_product
     *
     * @return int
     * @throws Exception
     */
    private function getProductEntityTypeId()
    {
        if ($this->_catalogProductEntityTypeId === null) {
            $this->_catalogProductEntityTypeId = $this->initializeCatalogProductEntityTypeId();
        }

        return $this->_catalogProductEntityTypeId;
    }

    /**
     * Get cached attribute_sets
     *
     * @return array
     * @throws Exception
     */
    private function getAttributeSets()
    {
        if ($this->_attributeSets === null) {
            $this->initializeAttributeSets();
        }

        return $this->_attributeSets;
    }

    /**
     * Get cached attributes
     *
     * @return array
     * @throws Exception
     */
    private function getCatalogProductAttributes()
    {
        if ($this->_attributes === null) {
            $this->initializeCatalogProductAttributes();
        }

        return $this->_attributes;
    }

    /**
     * Cache catalog_product entity_type_id
     *
     * @throws Exception
     */
    private function initializeCatalogProductEntityTypeId()
    {
        $table = $this->_dbHelper->getTableName('eav_entity_type');
        $query = "SELECT `entity_type_id` FROM `$table` WHERE `entity_type_code` = ?";
        $binds = ['catalog_product'];

        $results = $this->_dbHelper->select($query, $binds);
        if (!$results) {
            throw new InitException(__("Unable to lookup 'entity_type_id' for 'entity_type_code' = 'catalog_product'"));
        }

        if (count($results) != 1) {
            throw new StateException(
                __('Found ' . count($results) . " results when looking for 'catalog_product' 'entity_type_id'.")
            );
        }

        if (!isset($results[0]['entity_type_id'])) {
            throw new StateException(__("Unable to find column 'entity_type_id' in result."));
        }

        return $results[0]['entity_type_id'];
    }

    /**
     * Initialize attribute_set data
     *
     * @return void
     * @throws Exception
     */
    private function initializeAttributeSets()
    {
        $table = $this->_dbHelper->getTableName('eav_attribute_set');
        $query = "SELECT `attribute_set_id`, `attribute_set_name`
                  FROM `$table`
                  WHERE `entity_type_id` = ?";
        $binds = [$this->getProductEntityTypeId()];

        $results = $this->_dbHelper->select($query, $binds);
        foreach ($results as $result) {
            $this->_attributeSets[$result['attribute_set_name']] = (int)$result['attribute_set_id'];
        }
    }

    /**
     * Initialize attribute data
     *
     * @return void
     * @throws Exception
     */
    private function initializeCatalogProductAttributes()
    {
        $eav_attribute         = $this->_dbHelper->getTableName('eav_attribute');
        $catalog_eav_attribute = $this->_dbHelper->getTableName('catalog_eav_attribute');

        $query = "SELECT
                    `$eav_attribute`.`attribute_id`,
                    `$eav_attribute`.`attribute_code`,
                    `$eav_attribute`.`backend_type`,
                    `$eav_attribute`.`frontend_input`,
                    `$eav_attribute`.`frontend_label`,
                    `$eav_attribute`.`source_model`,
                    `$catalog_eav_attribute`.`is_global`,
                    `$catalog_eav_attribute`.`apply_to`
                  FROM
                    `$eav_attribute`

                  INNER JOIN
                    `$catalog_eav_attribute`
                  ON
                    `$eav_attribute`.`attribute_id` = `$catalog_eav_attribute`.`attribute_id`

                  WHERE
                    `$eav_attribute`.`entity_type_id` = ?";
        $binds = [$this->getProductEntityTypeId()];

        $results = $this->_dbHelper->select($query, $binds);
        foreach ($results as $result) {
            $this->_attributes[$result['attribute_code']] = [
                'attribute_id'    => (int)$result['attribute_id'],
                'backend_type'    => (string)$result['backend_type'],
                'frontend_input'  => (string)$result['frontend_input'],
                'frontend_label'  => (string)$result['frontend_label'],
                'source_model'    => (string)$result['source_model'],
                'is_global'       => (int)$result['is_global'],
                'apply_to'        => (string)$result['apply_to']
            ];
        }
    }

    /**
     * Upsert product attributes values
     *
     * @param array $product
     * @param int   $productId
     *
     * @throws Exception
     */
    private function upsertProductAttributes(array $product, int $productId)
    {
        $this->log('upsertProductAttributes()', ['productId' => $productId]);

        /** @var string[] $attributeCodes */
        $attributeCodes = $this->getCatalogProductAttributeCodes();

        // We don't handle the attribute "sku"
        if (($key = array_search('sku', $attributeCodes)) !== false) {
            unset($attributeCodes[$key]);
        }

        foreach ($attributeCodes as $attributeCode) {
            $this->upsertProductAttribute($product, $productId, $attributeCode);
        }
    }

    /**
     * Upsert product attribute value
     *
     * @param array  $product
     * @param int    $productId
     * @param string $attributeCode
     *
     * @throws Exception
     */
    private function upsertProductAttribute(array $product, int $productId, string $attributeCode)
    {
        $this->log('upsertProductAttribute()', ['productId' => $productId, 'attributeCode' => $attributeCode]);

        // We handle image attributes separately
        if ($this->isImageAttribute($attributeCode)) {
            return;
        }

        // Make sure the value is set first
        if (!isset($product[$attributeCode])) {
            return;
        }

        $this->log('upsertProductAttribute()', ['value' => $product[$attributeCode]]);

        // Make sure the attribute is in our system
        if ($attributeInfo = $this->getCatalogProductAttributeInfoByCode($attributeCode)) {
            $attributeId            = (int)$attributeInfo['attribute_id'];
            $attributeBackendType   = (string)$attributeInfo['backend_type'];
            $attributeFrontendInput = (string)$attributeInfo['frontend_input'];
            $attributeSourceModel   = (string)$attributeInfo['source_model'];
            $attributeScope         = (int)$attributeInfo['is_global'];

            // We only handle a subset of attribute types
            if (!$this->isValidAttributeType($attributeBackendType)) {
                $this->log('upsertProductAttribute()', ['invalidAttributeType' => $attributeBackendType]);

                return;
            }

            // Cache passed-in value
            $value = $product[$attributeCode];

            // Handle delete
            if ($value === '__DELETE__') {
                /** @var int[] $storeIds */
                $storeIds = $this->_storeWebsiteHelper->getStoreIdsForProduct($product);
                foreach ($storeIds as $storeId) {
                    $this->deleteProductAttributeValue($productId, $storeId, $attributeInfo);
                }

                return;
            }

            // If attribute type is select or multiselect, then we need to query the system
            if ($attributeFrontendInput === 'select') {
                if (empty($attributeSourceModel) || $attributeSourceModel === Table::class) {
                    /** @var int|null $existingOptionId */
                    $existingOptionId = $this->getAttributeOptionId($attributeCode, $value);
                    if ($existingOptionId) {
                        $value = $existingOptionId;
                    } else {
                        if ($this->_helper->allowNewAttributeValues()) {
                            // Cache new option_id value for writing to catalog_product_entity_*
                            $newOptionId = $this->addAttributeOptionRecord($attributeId);
                            $this->addAttributeOptionValueRecord($newOptionId, $value);

                            // We write the option_id to catalog_product_entity_int
                            $value = $newOptionId;
                        } else {
                            $this->log('upsertProductAttribute() - Not allowing new attribute values.');

                            switch ($this->_helper->getIllegalNewAttributeAction()) {
                                case IllegalNewAttributeActionOption::ACTION_IGNORE_VALUE:
                                    break;

                                case IllegalNewAttributeActionOption::ACTION_SKIP_PRODUCT_VALUE:
                                case IllegalNewAttributeActionOption::ACTION_SKIP_BATCH_VALUE:
                                    throw new IllegalNewAttributeOptionException(__("Attempted to add new attribute value '$value'."));
                            }

                            return;
                        }
                    }
                } else {
                    $this->log("upsertProductAttribute() - non-null 'source_mode'");

                    $optionId = $this->getAttributeSourceOptionId($attributeCode, $value);
                    if ($optionId !== null) {
                        $value = $optionId;
                    } else {
                        throw new LocalizedException(__('Unable to find option key for attribute ' . $attributeCode . ' and value ' . $value));
                    }
                }
            } elseif ($attributeFrontendInput === 'multiselect') {
                $optionIds = [];

                $values = $this->_helper->commaSeparatedListToTrimmedArray((string)$value);
                foreach ($values as $value) {
                    $existingOptionId = $this->getAttributeOptionId($attributeCode, $value);
                    if ($existingOptionId) {
                        // Don't add if it's already in array (in case someone accidentally puts same Option twice)
                        if (!in_array($existingOptionId, $optionIds)) {
                            $optionIds[] = $existingOptionId;
                        } else {
                            $this->log("Value `$value` already in Multi-Select value list");
                        }
                    } else {
                        if ($this->_helper->allowNewAttributeValues()) {
                            // Cache new option_id value for writing to catalog_product_entity_*
                            $newOptionId = $this->addAttributeOptionRecord($attributeId);
                            $this->addAttributeOptionValueRecord($newOptionId, $value);

                            // We write the option_ids to catalog_product_entity_varchar
                            $optionIds[] = $newOptionId;
                        } else {
                            $this->log('upsertProductAttribute() - Not allowing new attribute values.');

                            switch ($this->_helper->getIllegalNewAttributeAction()) {
                                case IllegalNewAttributeActionOption::ACTION_IGNORE_VALUE:
                                    break;

                                case IllegalNewAttributeActionOption::ACTION_SKIP_PRODUCT_VALUE:
                                case IllegalNewAttributeActionOption::ACTION_SKIP_BATCH_VALUE:
                                    throw new IllegalNewAttributeOptionException(__("Attempted to add new attribute value '$value'."));
                            }

                            return;
                        }
                    }
                }

                $value = implode(',', $optionIds);
            }

            /** @var int[] $storeIds */
            $storeIds = isset($product['store'])
                ? $this->_storeWebsiteHelper->getStoreIdsForProduct($product, $attributeScope)
                : [0];

            // TODO: Add handling for deleting from ALL stores when not singleStore.
            if ($this->_dbHelper->isSingleStore()) {
                // Delete from all but 0
                $this->deleteProductAttributeValueExclude($productId, 0, $attributeInfo);
            }

            foreach ($storeIds as $storeId) {
                $this->upsertProductAttributeValue($attributeId, $storeId, $productId, $value, $attributeBackendType);
            }
        }
    }

    /**
     * Tests if an attribute has a particular value available
     *
     * @param int    $attributeId
     * @param int    $storeId
     * @param int    $productId
     * @param string $attributeType
     *
     * @return bool
     */
    private function doesProductAttributeValueExist(int $attributeId, int $storeId, int $productId, string $attributeType)
    {
        $this->log('doesProductAttributeValueExist()', [
            'attribute_id'  => $attributeId,
            'store_id'      => $storeId,
            'product_id'    => $productId,
            'attributeType' => $attributeType
        ]);

        $table = $this->_dbHelper->getTableName('catalog_product_entity_' . $attributeType);
        $query = "SELECT COUNT(*) as 'count'
                  FROM `$table`
                  WHERE `attribute_id`=? AND `store_id`=? AND `{$this->getProductIdColumn()}`=?";
        $binds = [$attributeId, $storeId, $productId];

        return ((int)$this->_dbHelper->selectOne($query, $binds, 'count')) > 0;
    }

    /**
     * Insert new record into 'catalog_product_entity_*'
     *
     * @param int    $attributeId
     * @param int    $storeId
     * @param int    $productId
     * @param mixed  $value
     * @param string $attributeType
     *
     * @return void
     */
    private function insertProductAttributeValue(int $attributeId, int $storeId, int $productId, $value, string $attributeType)
    {
        $this->log('insertProductAttributeValue()', [
            'attribute_id'   => $attributeId,
            'store_id'       => $storeId,
            'product_id'     => $productId,
            'value'          => $value,
            'attribute_type' => $attributeType]);

        $table = $this->_dbHelper->getTableName('catalog_product_entity_' . $attributeType);
        $query = "INSERT INTO `$table` (`attribute_id`,`store_id`,`{$this->getProductIdColumn()}`,`value`) VALUES (?,?,?,?)";
        $binds = [$attributeId, $storeId, $productId, $value];

        $this->_dbHelper->insert($query, $binds);
    }

    /**
     * Update product attribute value
     *
     * @param int    $attributeId
     * @param int    $storeId
     * @param int    $productId
     * @param mixed  $value
     * @param string $attributeType
     *
     * @return void
     */
    private function updateProductAttributeValue(int $productId, int $storeId, int $attributeId, string $attributeType, $value)
    {
        $this->log('updateProductAttributeValue()', [
            'productId'     => $productId,
            'storeId'       => $storeId,
            'attributeId'   => $attributeId,
            'attributeType' => $attributeType,
            'value'         => $value
        ]);

        $table = $this->_dbHelper->getTableName('catalog_product_entity_' . $attributeType);
        $query = "UPDATE `$table` SET `value`=? WHERE `attribute_id`=? AND `store_id`=? AND `{$this->getProductIdColumn()}`=?";
        $binds = [$value, $attributeId, $storeId, $productId];

        $this->_dbHelper->update($query, $binds);
    }

    /**
     * Lookup attribute id by code
     *
     * @param string $attributeCode
     *
     * @return int|null
     * @throws Exception
     */
    private function getAttributeIdFromCode(string $attributeCode)
    {
        if ($attributeInfo = $this->getCatalogProductAttributeInfoByCode($attributeCode)) {
            if (isset($attributeInfo['attribute_id'])) {
                $attributeId = $attributeInfo['attribute_id'];
                if (is_numeric($attributeId)) {
                    return (int)$attributeId;
                }
            }
        }

        return null;
    }

    /**
     * Is attribute_type one that we handle?
     *
     * @param string $attributeType
     *
     * @return bool
     */
    private function isValidAttributeType(string $attributeType)
    {
        return in_array($attributeType, $this->_validAttributeTypes);
    }

    /**
     * Is attribute in our array of vanilla image attributes?
     *
     * @param string $attributeCode
     *
     * @return bool
     */
    private function isImageAttribute(string $attributeCode)
    {
        return in_array($attributeCode, $this->_imageAttributes);
    }

    /**
     * Get maximum 'sort_order' value in `eav_attribute_option` for a particular attribute_id
     *
     * @param int $attributeId
     *
     * @return int|null
     */
    private function getTopSortOrderEavAttributeOption(int $attributeId)
    {
        $this->log('getTopSortOrderEavAttributeOption()', ['attribute_id' => $attributeId]);

        $table = $this->_dbHelper->getTableName('eav_attribute_option');
        $query = "SELECT MAX(`sort_order`) as 'sort_order' FROM `$table` WHERE `attribute_id`=?";
        $binds = [$attributeId];

        return $this->_dbHelper->selectOne($query, $binds, 'sort_order');
    }

    /**
     * Get attribute option id (`eav_attribute_option`.`option_id`)
     *
     * @param string $attributeCode
     * @param mixed  $attributeOptionValue
     *
     * @return int|null
     */
    private function getAttributeOptionId(string $attributeCode, $attributeOptionValue)
    {
        $this->log('getAttributeOptionId()', [
            'attribute_code' => $attributeCode,
            'value'          => $attributeOptionValue
        ]);

        $eavAttribute            = $this->_dbHelper->getTableName('eav_attribute');
        $eavAttributeOption      = $this->_dbHelper->getTableName('eav_attribute_option');
        $eavAttributeOptionValue = $this->_dbHelper->getTableName('eav_attribute_option_value');

        $query = "SELECT
                    `$eavAttribute`.`attribute_code`,
                    `$eavAttributeOption`.`option_id`,
                    `$eavAttributeOptionValue`.`value`
                  FROM
                    `$eavAttribute`
                  INNER JOIN
                    `$eavAttributeOption`
                  ON
                    `$eavAttribute`.`attribute_id` = `$eavAttributeOption`.`attribute_id`
                  INNER JOIN
                    `$eavAttributeOptionValue`
                  ON
                    `$eavAttributeOption`.`option_id` = `$eavAttributeOptionValue`.`option_id`
                  WHERE
                    `$eavAttribute`.`attribute_code`=? AND `$eavAttributeOptionValue`.`value`=?";
        $binds = [$attributeCode, $attributeOptionValue];

        return (int)$this->_dbHelper->selectOne($query, $binds, 'option_id');
    }

    private function getAttributeSourceOptionId(string $attributeCode, string $value)
    {
        $this->log('getAttributeSourceOptionId()', ['attributeCode' => $attributeCode, 'value' => $value]);

        if ($attribute = $this->getAttribute($attributeCode)) {
            $this->log('getAttributeSourceOptionId() - Attribute exists.');
            if ($attribute->usesSource()) {
                $this->log('getAttributeSourceOptionId() - Attribute uses source.');

                return $this->getOptionId($attribute, $value);
            }
        }

        return null;
    }

    /**
     * @param \Magento\Eav\Model\Entity\Attribute\AbstractAttribute $attribute
     * @param string                                                $value
     *
     * @return mixed|null
     */
    private function getOptionId(AbstractAttribute $attribute, string $value)
    {
        if ($source = $this->getAttributeSource($attribute)) {
            foreach ($source->getAllOptions() as $option) {
                if (isset($option['label'])) {
                    $label = $option['label'];
                    if ($label instanceof Phrase) {
                        $label = $label->getText();
                    }

                    // Label match
                    if ($this->mbStrcasecmp($label, $value) === 0) {
                        return $option['value'];
                    }

                    // Value match
                    if ($option['value'] == $value) {
                        return $option['value'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get attribute source
     *
     * @param \Magento\Eav\Model\Entity\Attribute\AbstractAttribute $attribute
     *
     * @return \Magento\Eav\Model\Entity\Attribute\Source\AbstractSource|null
     */
    private function getAttributeSource(AbstractAttribute $attribute)
    {
        if ($attribute->usesSource()) {
            try {
                return $attribute->getSource();
            } catch (LocalizedException $e) {
                $this->log('getAttributeSource()', ['error' => $e->getMessage()]);
            }
        }

        return null;
    }

    /**
     * Multibyte support strcasecmp function version.
     *
     * @param string $str1
     * @param string $str2
     * @return int
     */
    private function mbStrcasecmp(string $str1, string $str2)
    {
        $encoding = mb_internal_encoding();
        return strcmp(
            mb_strtoupper($str1, $encoding),
            mb_strtoupper($str2, $encoding)
        );
    }

    /**
     * @param string $attributeCode
     *
     * @return \Magento\Eav\Model\Entity\Attribute\AbstractAttribute|null
     */
    private function getAttribute(string $attributeCode)
    {
        try {
            return $this->_eavConfig->getAttribute(Product::ENTITY, $attributeCode);
        } catch (LocalizedException $e) {
            $this->log('getAttribute()', ['attribute' => $attributeCode, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Add record to 'eav_attribute_option'
     *
     * @param int      $attributeId
     * @param int|null $sortOrder
     *
     * @return int
     */
    private function addAttributeOptionRecord(int $attributeId, int $sortOrder = null)
    {
        $this->log('addAttributeOptionRecord()', ['attributeId' => $attributeId, 'sortOrder' => $sortOrder]);

        // If default, get from `eav_attribute_option`
        if ($sortOrder === null) {
            $sortOrder = $this->getTopSortOrderEavAttributeOption($attributeId);
        }

        // If it's still null, then we don't have a record yet, and this will get sort_order 0
        if ($sortOrder === null) {
            $sortOrder = 0;
        }

        $table = $this->_dbHelper->getTableName('eav_attribute_option');
        $query = "INSERT INTO `$table` (`attribute_id`, `sort_order`) VALUES (?,?)";
        $binds = [$attributeId, $sortOrder];

        return $this->_dbHelper->insert($query, $binds);
    }

    /**
     * Add record to 'eav_attribute_option_value` table
     *
     * @param int   $optionId
     * @param mixed $value
     * @param int   $storeId
     *
     * @return void
     */
    private function addAttributeOptionValueRecord(int $optionId, $value, int $storeId = 0)
    {
        $this->log('addAttributeOptionValueRecord()', [
            'storeId'  => $storeId,
            'optionId' => $optionId,
            'value'    => $value,
        ]);

        $table = $this->_dbHelper->getTableName('eav_attribute_option_value');
        $query = "INSERT INTO `$table` (`option_id`, `store_id`, `value`) VALUES (?,?,?)";
        $binds = [$optionId, $storeId, $value];

        $this->_dbHelper->insert($query, $binds);
    }

    /**
     * Delete record from 'catalog_product_entity_*'
     *
     * @param array $attributeInfo
     * @param int   $storeId
     * @param int   $productId
     *
     * @return void
     */
    public function deleteProductAttributeValue(int $productId, int $storeId, array $attributeInfo)
    {
        $this->log('deleteProductAttributeValue()', [
            'productId'   => $productId,
            'storeId'     => $storeId,
            'attributeId' => $attributeInfo['attribute_id']
        ]);

        // Cache the 'backend_type' value as this determines which table to update
        $attributeId = $attributeInfo['attribute_id'];
        $backendType = $attributeInfo['backend_type'];

        $table = $this->_dbHelper->getTableName('catalog_product_entity_' . $backendType);
        $query = "DELETE FROM `$table` WHERE `attribute_id` = ? AND `store_id` = ? AND `{$this->getProductIdColumn()}` = ?";
        $binds = [$attributeId, $storeId, $productId];

        $this->_dbHelper->delete($query, $binds);
    }

    /**
     * Delete product attribute value in all stores except one
     *
     * @param array $attributeInfo
     * @param int   $storeId
     * @param int   $productId
     *
     * @return void
     */
    private function deleteProductAttributeValueExclude(int $productId, int $storeId, array $attributeInfo)
    {
        $this->log('deleteProductAttributeValueExclude()', [
            'productId'   => $productId,
            'storeId'     => $storeId,
            'attributeId' => $attributeInfo['attribute_id']
        ]);

        // Cache the 'backend_type' value as this determines which table to update
        $attributeId = $attributeInfo['attribute_id'];
        $backendType = $attributeInfo['backend_type'];

        $table = $this->_dbHelper->getTableName('catalog_product_entity_' . $backendType);
        $query = "DELETE FROM `$table` WHERE `attribute_id` = ? AND `store_id` <> ? AND `{$this->getProductIdColumn()}` = ?";
        $binds = [$attributeId, $storeId, $productId];

        $this->_dbHelper->delete($query, $binds);
    }

    /**
     * Write to extension log
     *
     * @param string $message
     * @param array  $extra
     */
    private function log(string $message, array $extra = [])
    {
        $this->_logger->info('AttributeHelper - ' . $message, $extra);
    }
}
