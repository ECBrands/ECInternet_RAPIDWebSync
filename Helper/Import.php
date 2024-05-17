<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use Magento\Framework\Exception\IntegrationException;
use ECInternet\RAPIDWebSync\Logger\Logger;
use DateTime;
use DateInterval;
use Exception;

/**
 * Import Helper
 */
class Import
{
    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Data
     */
    private $_helper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Attribute
     */
    private $_attributeHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Category
     */
    private $_categoryHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Configurable
     */
    private $_configurableHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Db
     */
    private $_dbHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Image
     */
    private $_imageHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Link
     */
    private $_linkHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Rewrite
     */
    private $_rewriteHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Stock
     */
    private $_stockHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\StoreWebsite
     */
    private $_storeWebsiteHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\TierPrice
     */
    private $_tierPriceHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Logger\Logger
     */
    private $_logger;

    /**
     * Array for holding sku-entity_id records
     *
     * @var array
     */
    private $_skuEntityIdArray = [];

    /**
     * Array for holding sku-row_id records
     *
     * @var array
     */
    private $_skuRowIdArray = [];

    /**
     * @var bool
     */
    private $_refreshEntityIdArray = true;

    /**
     * @var bool
     */
    private $_refreshRowIdArray = true;

    /**
     * @var string
     */
    private $_sku;

    /**
     * @var int
     */
    private $_productId;

    /**
     * Import constructor.
     *
     * @param \ECInternet\RAPIDWebSync\Helper\Data         $helper
     * @param \ECInternet\RAPIDWebSync\Helper\Attribute    $attributeHelper
     * @param \ECInternet\RAPIDWebSync\Helper\Category     $categoryHelper
     * @param \ECInternet\RAPIDWebSync\Helper\Configurable $configurableHelper
     * @param \ECInternet\RAPIDWebSync\Helper\Db           $dbHelper
     * @param \ECInternet\RAPIDWebSync\Helper\Image        $imageHelper
     * @param \ECInternet\RAPIDWebSync\Helper\Link         $linkHelper
     * @param \ECInternet\RAPIDWebSync\Helper\Rewrite      $rewriteHelper
     * @param \ECInternet\RAPIDWebSync\Helper\Stock        $stockHelper
     * @param \ECInternet\RAPIDWebSync\Helper\StoreWebsite $storeWebsiteHelper
     * @param \ECInternet\RAPIDWebSync\Helper\TierPrice    $tierPriceHelper
     * @param \ECInternet\RAPIDWebSync\Logger\Logger       $logger
     */
    public function __construct(
        Data $helper,
        Attribute $attributeHelper,
        Category $categoryHelper,
        Configurable $configurableHelper,
        Db $dbHelper,
        Image $imageHelper,
        Link $linkHelper,
        Rewrite $rewriteHelper,
        Stock $stockHelper,
        StoreWebsite $storeWebsiteHelper,
        TierPrice $tierPriceHelper,
        Logger $logger
    ) {
        $this->_helper             = $helper;
        $this->_attributeHelper    = $attributeHelper;
        $this->_categoryHelper     = $categoryHelper;
        $this->_configurableHelper = $configurableHelper;
        $this->_dbHelper           = $dbHelper;
        $this->_imageHelper        = $imageHelper;
        $this->_linkHelper         = $linkHelper;
        $this->_rewriteHelper      = $rewriteHelper;
        $this->_stockHelper        = $stockHelper;
        $this->_storeWebsiteHelper = $storeWebsiteHelper;
        $this->_tierPriceHelper    = $tierPriceHelper;
        $this->_logger             = $logger;

        // Build SKU array so we can test for existing / new products
        $this->initSkuArray();
    }

    ////////////////////////////////////////////////////////////////////////////////
    ///
    /// PUBLIC METHODS
    ///
    ////////////////////////////////////////////////////////////////////////////////

    /**
     * @return array
     */
    public function getSalesOrderColumns()
    {
        return $this->_dbHelper->getTableColumns('sales_order');
    }

    /**
     * @param string $sku
     *
     * @return bool
     */
    public function doesProductExist(string $sku)
    {
        return $this->getProductIdForSku($sku) != null;
    }

    /**
     * @param array $product
     *
     * @return array
     * @throws Exception
     */
    public function addProduct(array $product)
    {
        $this->log('addProduct()');

        $startTime = microtime(true);

        // Cache product sku
        $this->_sku = (string)$product['sku'];

        // Start building response
        $response        = [];
        $response['sku'] = $this->_sku;
        $response['new'] = true;

        // Check required fields
        // Set 'Name' using 'name' attribute if populated, else use 'sku' value
        if (!isset($product['name'])) {
            $product['name'] = $product['sku'];
        }

        // Price must be populated -- If not, set error and return
        if (!isset($product['price'])) {
            $response['error'] = "'price' field not mapped.";

            return $response;
        }

        // Prep product for insert
        $this->setNewProductDefaults($product);

        // Creates master product record and adds to local dictionary of Sku/product_id
        $this->createProductRecord($product, $this->_sku);

        // Extract newly added entity_id (or row_id) and set it in response and private variable.
        $entityId         = $this->_helper->isVersionCommunity() ? (int)$this->_skuEntityIdArray[$this->_sku] : (int)$this->_skuRowIdArray[$this->_sku];
        $response['id']   = $entityId;
        $this->_productId = $entityId;

        /** @var int[] $websiteIds */
        $websiteIds = $this->_storeWebsiteHelper->getWebsiteIdsForProduct($product);
        foreach ($websiteIds as $websiteId) {
            $this->addWebsiteRecord($entityId, $websiteId);
        }

        try {
            $this->processProduct($product, true);
        } catch (Exception $e) {
            $message = $e->getMessage();
            $trace   = $e->getTraceAsString();

            $this->log("addProduct() - EXCEPTION: $message");
            $this->log("addProduct() - TRACE: $trace");

            $response['error'] = $message;
            $response['trace'] = $trace;
        }

        $endTime = microtime(true);
        $this->_helper->logSpeedTest($startTime, $endTime, 'addProduct()');

        // Repopulate our array
        //$this->initSkuArray();

        return $response;
    }

    /**
     * @param array $product
     *
     * @return array
     */
    public function updateProduct(array $product)
    {
        $this->log('updateProduct()');

        $startTime = microtime(true);

        // Cache product sku and id
        $this->_sku       = (string)$product['sku'];
        $this->_productId = $this->getProductIdForSku($this->_sku);

        // Start building response
        $response        = [];
        $response['sku'] = $this->_sku;
        $response['id']  = $this->_productId;
        $response['new'] = false;

        try {
            $this->processProduct($product, false);
            $this->updateWebsites($this->_productId, $product);
            $this->touchProduct($this->_productId);
        } catch (Exception $e) {
            $message = $e->getMessage();
            $trace   = $e->getTraceAsString();

            $this->log("updateProduct() - EXCEPTION: $message");
            $this->log("updateProduct() - TRACE: $trace");

            $response['error'] = $message;
            $response['trace'] = $trace;
        }

        $endTime = microtime(true);
        $this->_helper->logSpeedTest($startTime, $endTime, 'updateProduct()');

        return $response;
    }

    /**
     * Translate product_id to sku.
     *
     * @param string $sku
     *
     * @return int|null
     */
    public function getProductIdForSku(string $sku)
    {
        //$this->log('getProductIdForSku()', ['sku' => $sku]);

        if ($this->_helper->isVersionCommunity()) {
            // Handle for COMMUNITY
            if ($entityIdArray = $this->getEntityIdSkuArray()) {
                if (isset($entityIdArray[$sku])) {
                    $entityId = $entityIdArray[$sku];
                    if (is_numeric($entityId)) {
                        return (int)$entityId;
                    }
                }
            }
        } else {
            // Handle for ENTERPRISE
            if ($rowIdArray = $this->getRowIdSkuArray()) {
                if (isset($rowIdArray[$sku])) {
                    $rowId = $rowIdArray[$sku];
                    if (is_numeric($rowId)) {
                        return (int)$rowId;
                    }
                }
            }
        }

        return null;
    }

    ////////////////////////////////////////////////////////////////////////////////
    ///
    /// Init functions
    ///
    ////////////////////////////////////////////////////////////////////////////////

    /**
     * Cache 'catalog_product_entity' table data
     *
     * @return void
     */
    protected function initSkuArray()
    {
        $table = $this->_dbHelper->getTableName('catalog_product_entity');
        $query = "SELECT DISTINCT `entity_id`, `sku` FROM `$table`";

        // If we're COMMUNITY we also want to pick up row_id
        if (!$this->_helper->isVersionCommunity()) {
            $query = "SELECT `row_id`, `entity_id`, `sku` FROM `$table`";
        }

        $results = $this->_dbHelper->select($query);
        foreach ($results as $result) {
            // Always add rows to entity_id / sku array
            $this->_skuEntityIdArray[$result['sku']] = (int)$result['entity_id'];

            // Only add rows to row_id / sku array if we're in COMMUNITY
            if (!$this->_helper->isVersionCommunity()) {
                $this->_skuRowIdArray[$result['sku']] = (int)$result['row_id'];
            }
        }

        $this->_refreshEntityIdArray = false;
        $this->_refreshRowIdArray    = false;
    }

    ////////////////////////////////////////////////////////////////////////////////
    ///
    /// Main processing functions, each of these are comparable to Magmi "plugins".
    ///
    ////////////////////////////////////////////////////////////////////////////////

    /**
     * @param array $productData
     * @param bool  $isNew
     *
     * @throws Exception
     */
    private function processProduct(array $productData, bool $isNew)
    {
        $this->log('processProduct()', ['isNew' => $isNew ? 'TRUE' : 'FALSE']);

        // HANDLE 'URL_KEY'
        $this->handleUrlKey($productData, $isNew);

        // HANDLE 'TAX_CLASS_ID'
        $this->handleTaxClassId($productData, $isNew);

        // HANDLE ATTRIBUTES
        $this->processAttributes($productData);

        // HANDLE STOCK ITEM COLUMNS
        $this->processStockItem($productData);

        // HANDLE TIER PRICES
        $this->processTierPrices($productData);

        // HANDLE CONFIGURABLE COLUMNS
        $this->processConfigurableProduct($productData, $isNew);

        // HANDLE IMAGE COLUMNS
        $this->processImageFields($productData);

        // HANDLE CATEGORY COLUMN
        $this->processCategories($productData);

        // HANDLE RELATED_PRODUCTS COLUMN
        $this->processLinks($productData);

        // HANDLE URL_REWRITE'S (this should be last)
        $this->processRewrites($productData);
    }

    /**
     * @param array $product
     *
     * @return void
     * @throws Exception
     */
    private function processAttributes(array $product)
    {
        $this->log('processAttributes()');

        $this->_attributeHelper->processProduct($product, $this->_sku, $this->_productId);
    }

    /**
     * Process StockItem fields of product
     *
     * @param array $product
     *
     * @return void
     * @throws Exception
     */
    private function processStockItem(array $product)
    {
        $this->log('processStockItem()');

        $this->_stockHelper->processProduct($product, $this->_sku, $this->_productId);
    }

    /**
     * Run TierPrices processor
     *
     * @param array $product
     *
     * @return void
     * @throws Exception
     */
    private function processTierPrices(array $product)
    {
        $this->log('processTierPrices()');

        $this->_tierPriceHelper->processProduct($product, $this->_sku, $this->_productId);
    }

    /**
     * Run Configurables processor
     *
     * @param array $product
     * @param bool  $isNew
     *
     * @return void
     * @throws Exception
     */
    private function processConfigurableProduct(array $product, bool $isNew)
    {
        $this->log('processConfigurableProduct()');

        $this->_configurableHelper->processProduct($product, $this->_sku, $this->_productId, $isNew);
    }

    /**
     * Run Images processor
     *
     * @param array $product
     *
     * @return void
     * @throws Exception
     */
    private function processImageFields(array $product)
    {
        $this->log('processImageFields()');

        try {
            $this->_imageHelper->processProduct($product, $this->_sku, $this->_productId);
        } catch (Exception $e) {
            $this->log("Error found in ImageProcessor: [{$e->getMessage()}].");
            throw new IntegrationException(__("Error found in ImageProcessor: [{$e->getMessage()}]."));
        }
    }

    /**
     * Run Categories processor
     *
     * @param array $product
     *
     * @return void
     * @throws Exception
     */
    private function processCategories(array $product)
    {
        $this->log('processCategories()');

        $this->_categoryHelper->processProduct($product, $this->_sku, $this->_productId);
    }

    /**
     * Run Link processor
     *
     * @param array $product
     *
     * @return void
     * @throws \Exception
     */
    private function processLinks(array $product)
    {
        $this->log('processLinks()');

        $this->_linkHelper->processProduct($product, $this->_sku, $this->_productId);
    }

    /**
     * @param array $product
     *
     * @return void
     * @throws Exception
     */
    private function processRewrites(array $product)
    {
        $this->log('processRewrites()');

        $this->_rewriteHelper->processProduct($product, $this->_sku, $this->_productId);
    }

    ////////////////////////////////////////////////////////////////////////////////
    ///
    /// PRIVATE METHODS
    ///
    ////////////////////////////////////////////////////////////////////////////////

    /**
     * Get column names for Product table
     *
     * @return string[]
     */
    private function getProductColumns()
    {
        return $this->_dbHelper->getTableColumns('catalog_product_entity');
    }

    /**
     * Get/Refresh Sku array
     *
     * @return array
     */
    private function getEntityIdSkuArray()
    {
        if ($this->_skuEntityIdArray == null ||
            count($this->_skuEntityIdArray) == 0 ||
            $this->_refreshEntityIdArray === true
        ) {
            $this->initSkuArray();
        }

        return $this->_skuEntityIdArray;
    }

    /**
     * Get/Refresh Row array
     *
     * @return array
     */
    private function getRowIdSkuArray()
    {
        if ($this->_skuRowIdArray == null ||
            count($this->_skuRowIdArray) == 0 ||
            $this->_refreshRowIdArray === true
        ) {
            $this->initSkuArray();
        }

        return $this->_skuRowIdArray;
    }

    /**
     * Set defaults from settings
     *
     * @param array $product
     *
     * @throws \Exception
     */
    private function setNewProductDefaults(array &$product)
    {
        $this->log('setNewProductDefaults()');

        $product['attribute_set_id'] = $product['attribute_set_id'] ?? $this->_helper->getDefaultAttributeSetId();
        $product['type_id']          = $product['type_id']          ?? $this->_helper->getDefaultType();
        $product['status']           = $product['status']           ?? $this->_helper->getDefaultStatus();
        $product['visibility']       = $product['visibility']       ?? $this->_helper->getDefaultVisibility();
        // TODO: Add tax_class default value
        $product['weight']           = $product['weight']           ?? 1;

        $newsToDateSetting = $this->_helper->getDefaultNewsToDateDays();
        if (is_numeric($newsToDateSetting) && $newsToDateSetting > 0) {
            if (empty($product['news_from_date']) && empty($product['news_to_date'])) {
                $dateTime = new DateTime();
                $today    = $dateTime->format('Y-m-d H:i:s');
                $future   = $dateTime->add(new DateInterval('P' . $newsToDateSetting . 'D'))->format('Y-m-d H:i:s');

                $product['news_from_date'] = $today;
                $product['news_to_date']   = $future;
            }
        }
    }

    /**
     * Create new Product record
     *
     * @param array  $productData
     * @param string $sku
     *
     * @throws Exception
     */
    private function createProductRecord(array &$productData, string $sku)
    {
        $this->log('createProductRecord()', ['sku' => $sku]);

        /** @var string[] $productColumns */
        $productColumns = $this->getProductColumns();

        //$productData['entity_type_id']   = $this->getCatalogProductEntityTypeId();
        $productData['type_id']          = $product['type_id'] ?? 'simple';
        $productData['attribute_set_id'] = $this->getAttributeSetId($productData);

        // Variables for holding Ids.  We won't know if we need to store these until after our Transaction.
        // Start them as null, and we can check later after Transaction is committed.
        $sequenceProductId      = null;
        $catalogProductEntityId = null;

        try {
            $this->_dbHelper->beginTransaction();

            // Insert into sequence_product if we're in EE
            if (!$this->_helper->isVersionCommunity()) {
                // Create a `sequence_product` record which will be like CE's entity_id
                $sequenceProductId = $this->createSequenceProductRecord();

                // Set this value on the product array so it is used in INSERT.
                $productData['entity_id'] = $sequenceProductId;
            }

            // Filter out non-`catalog_product_entity` columns
            /** @var string[] $filteredColumns */
            $filteredColumns = array_intersect(array_keys($productData), $productColumns);
            $values          = $this->_helper->filterKeyValueArray($productData, $filteredColumns);

            // Extract column and value strings
            $columnString = implode(',', $filteredColumns);
            $valuesString = $this->_helper->arrayToCommaSeparatedValueString($filteredColumns);

            // Let's write this baby
            $table = $this->_dbHelper->getTableName('catalog_product_entity');
            $query = "INSERT INTO `$table` ($columnString) VALUES ($valuesString)";
            $binds = array_values($values);

            // For CE this will be new `entity_id`
            // For EE this will be new `row_id`
            $catalogProductEntityId = $this->_dbHelper->insert($query, $binds);

            $this->_dbHelper->commit();
        } catch (Exception $e) {
            $this->_dbHelper->rollBack();
            throw $e;
        }

        if (!$this->_helper->isVersionCommunity()) {
            // ENTERPRISE
            // $skuRowIdArray[$sku] gets newly created `row_id` value.
            // $skuEntityIdArray[$sku] gets `sequence_product` Id, which was originally used as 'entity_id' when creating product record.
            $this->_skuRowIdArray[$sku]    = $catalogProductEntityId;
            $this->_skuEntityIdArray[$sku] = $sequenceProductId;
        } else {
            // COMMUNITY
            // $skuEntityIdArray[$sku] gets newly created 'entity_id' value.
            $this->_skuEntityIdArray[$sku] = $catalogProductEntityId;
        }
    }

    /**
     * Create new record in 'sequence_product'
     *
     * @return int
     */
    private function createSequenceProductRecord()
    {
        $this->log('createSequenceProductRecord()');

        $table = $this->_dbHelper->getTableName('sequence_product');
        $query = "INSERT INTO `$table` VALUES (null)";

        return $this->_dbHelper->insert($query);
    }

    /**
     * Get AttributeSet id for the product data array
     *
     * @param array $product
     *
     * @return int
     * @throws Exception
     */
    private function getAttributeSetId(array $product)
    {
        if (isset($product['attribute_set_id'])) {
            $attributeSetId = $product['attribute_set_id'];
            if (is_numeric($attributeSetId)) {
                return (int)$attributeSetId;
            } else {
                // Check for existing AttributeSet with this name
                if ($existingAttributeSetId = $this->_attributeHelper->getAttributeSetId((string)$attributeSetId)) {
                    return $existingAttributeSetId;
                }
            }
        }

        // Else return the default
        return $this->_helper->getDefaultAttributeSetId();
    }

    /**
     * @param int $productId
     * @param int $websiteId
     *
     * @return void
     */
    private function addWebsiteRecord(int $productId, int $websiteId)
    {
        $this->log('addWebsiteRecord()', ['productId' => $productId, 'websiteId' => $websiteId]);

        $table = $this->_dbHelper->getTableName('catalog_product_website');
        $query = "INSERT IGNORE INTO `$table` (`product_id`, `website_id`) VALUES (?,?)";
        $binds = [$productId, $websiteId];

        $this->_dbHelper->insert($query, $binds);
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ///
    /// SPECIAL COLUMNS
    ///
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

    /**
     * Special handling for 'url_key' field
     *
     * If 'url_key' is not set AND it's a new product, we need a value.
     * Try to pull it from name.  If that fails, fall back to sku.
     *
     * @param array $productData
     * @param bool  $isNew
     *
     * @return void
     */
    private function handleUrlKey(array &$productData, bool $isNew)
    {
        if (!isset($productData['url_key'])) {
            if ($isNew) {
                if (isset($productData['name'])) {
                    $productData['url_key'] = $this->_helper->slug($productData['name']);
                } else {
                    $productData['url_key'] = $this->_helper->slug($productData['sku']);
                }
            }
        } else {
            // If 'url_key' is set, slug it and be done with it.
            $productData['url_key'] = $this->_helper->slug($productData['url_key']);
        }
    }

    /**
     * Special handling for 'tax_class_id' field
     * Currently defaulting to "Taxable Goods"
     * Should only run in product insert.
     *
     * @param array $productData
     * @param bool  $isNew
     *
     * @return void
     */
    private function handleTaxClassId(array &$productData, bool $isNew)
    {
        if ($isNew && !isset($productData['tax_class_id'])) {
            $productData['tax_class_id'] = 'Taxable Goods';
        }
    }

    /**
     * @param int   $productId
     * @param array $product
     *
     * @throws Exception
     */
    private function updateWebsites(int $productId, array $product)
    {
        $this->log('updateWebsites()', ['productId' => $productId]);

        if (isset($product['websites'])) {
            $websites = (string)$product['websites'];

            /** @var int[] $websiteIds */
            $websiteIds = $this->_storeWebsiteHelper->getWebsiteIdsFromWebsitesColumn($websites);

            // Clear existing -- SHOULD THIS BE REPLACE OR ADDITION?
            $this->clearProductWebsites($productId);

            // Populate
            foreach ($websiteIds as $websiteId) {
                $this->addWebsiteRecord($productId, $websiteId);
            }
        }
    }

    /**
     * Remove product from 'catalog_product_website'
     *
     * @param int $productId
     *
     * @return void
     */
    private function clearProductWebsites(int $productId)
    {
        $this->log('clearProductWebsites()', ['productId' => $productId]);

        $table = $this->_dbHelper->getTableName('catalog_product_website');
        $query = "DELETE FROM `$table` WHERE `product_id` = ?";
        $binds = [$productId];

        $this->_dbHelper->delete($query, $binds);
    }

    /**
     * Touch 'catalog_product_entity'.'updated_at'
     *
     * @param int $productId
     *
     * @return void
     */
    private function touchProduct(int $productId)
    {
        $this->log('touchProduct()', ['productId' => $productId]);

        $timestamp = date('Y-m-d H:i:s');

        $table = $this->_dbHelper->getTableName('catalog_product_entity');
        $query = "UPDATE `$table` SET `updated_at`=? WHERE `entity_id`=?";
        $binds = [$timestamp, $productId];

        $this->_dbHelper->update($query, $binds);
    }

    /**
     * Write to extension log
     *
     * @param string $message
     * @param array  $extra
     */
    private function log(string $message, array $extra = [])
    {
        $this->_logger->info("ImportHelper - $message", $extra);
    }
}
