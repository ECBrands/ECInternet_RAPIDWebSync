<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\StateException;
use ECInternet\RAPIDWebSync\Logger\Logger;
use Exception;

/**
 * Rewrite Helper
 */
class Rewrite
{
    const KEY                   = 'url_key';

    const REWRITE_TYPE_CATEGORY = 'category';

    const REWRITE_TYPE_PRODUCT  = 'product';

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Data
     */
    private $_helper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Db
     */
    private $_dbHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Logger\Logger
     */
    private $_logger;

    /**
     * @var int
     */
    private $_categoryUrlPathAttributeId;

    /**
     * @var int
     */
    private $_entityId;

    /**
     * Rewrite constructor.
     *
     * @param \ECInternet\RAPIDWebSync\Helper\Data   $helper
     * @param \ECInternet\RAPIDWebSync\Helper\Db     $dbHelper
     * @param \ECInternet\RAPIDWebSync\Logger\Logger $logger
     *
     * @throws \Exception
     */
    public function __construct(
        Data $helper,
        Db $dbHelper,
        Logger $logger
    ) {
        $this->_helper   = $helper;
        $this->_dbHelper = $dbHelper;
        $this->_logger   = $logger;

        // Cache the AttributeId for 'url_path'
        $this->initCategoryUrlPathAttributeId();
    }

    /**
     * Run Rewrite processor
     *
     * @param array  $product
     * @param string $sku
     * @param int    $entityId
     *
     * @throws \Exception
     */
    public function processProduct(array $product, string $sku, int $entityId)
    {
        $this->log('| -- Start Rewrite Processor --');
        $this->log("| Sku: [$sku]");
        $this->log("| EntityId: [$entityId]");

        // Cache entity_id
        $this->_entityId = $entityId;

        // We can only add a rewrite if we have a 'url_key' value
        // We only have a 'url_key' value on a product update, or a product insert where this field has been mapped.
        // If we don't have a 'url_key' value, we can assume this is an update where we don't need a new rewrite.
        if (isset($product[self::KEY])) {
            $urlKey = (string)$product[self::KEY];
            $this->log("| UrlKey: [$urlKey]");

            // Handle base target path
            $this->upsertProductBaseRewrite($urlKey);

            $this->log('| Clearing existing category-product rewrites...');

            // Clear existing category product rewrites
            // If setting is enabled, they need to be cleared out to make room for new ones
            // If setting is disabled, they shouldn't exist anyway
            $this->clearCategoryProductRewrites($entityId);

            if ($this->shouldGenerateCategoryProductRewrites()) {
                $this->log('| Generating category-product rewrites...');

                // Handle category-specific target path
                $categoryIds = $this->getCategoryIds($entityId);
                $this->log('| CategoryIds:', $categoryIds);

                foreach ($categoryIds as $categoryId) {
                    if (!in_array($categoryId, [1, 2])) {
                        $this->upsertProductCategoryRewrite($categoryId, $urlKey);
                    }
                }
            } else {
                $this->log('| NOT generating category-product rewrites');
            }
        }

        $this->log('| -- End Rewrite Processor --' . PHP_EOL);
    }

    /**
     * Insert/Update 'url_rewrite' record for product link
     *
     * @param string $urlKey
     * @param int    $storeId
     *
     * @throws Exception
     */
    public function upsertProductBaseRewrite(string $urlKey, int $storeId = 1)
    {
        $this->log('upsertProductBaseRewrite()', ['urlKey' => $urlKey, 'storeId' => $storeId]);

        // Confirm we don't have bad data
        $urlRewrites = $this->getProductBaseUrlRewrites($this->_entityId);
        if (count($urlRewrites) > 1) {
            $this->log('upsertProductBaseRewrite() - Found more than one base product rewrite for product', [$this->_entityId]);

            // We can try and be more elegant later.  For now, let's truncate and re-populate
            $this->deleteProductBaseUrlRewriteByProductId($this->_entityId);
        }

        $sluggedUrlKey = $this->_helper->slug($urlKey);
        $targetPath    = $this->buildProductTargetPath($this->_entityId);

        $this->upsertUrlRewriteRecord(self::REWRITE_TYPE_PRODUCT, $this->_entityId, $sluggedUrlKey, $targetPath, $storeId);
    }

    /**
     * Insert/Update 'url_rewrite' record for category product link
     *
     * @param int    $categoryId
     * @param string $urlKey
     * @param int    $storeId
     *
     * @throws Exception
     */
    public function upsertProductCategoryRewrite(int $categoryId, string $urlKey, int $storeId = 1)
    {
        $this->log('upsertProductCategoryRewrite()', [
            'categoryId' => $categoryId,
            'urlKey'     => $urlKey,
            'storeId'    => $storeId
        ]);

        if ($categoryId === 1 || $categoryId === 2) {
            $this->log('upsertProductCategoryRewrite() - Root category found.  Skipping...');

            return;
        }

        $categoryUrlPath = $this->getCategoryUrlPath($categoryId);
        if ($categoryUrlPath === null) {
            $this->log("upsertProductCategoryRewrite() - Unable to lookup 'url_path' for categoryId $categoryId");

            return;
        }

        $sluggedUrlKey = $this->_helper->slug($urlKey);
        if ($sluggedUrlKey === '') {
            $this->log("upsertProductCategoryRewrite() - Unable to create url slug for urlKey $urlKey");

            return;
        }

        $sluggedRequestPath = "$categoryUrlPath/$sluggedUrlKey";
        $targetPath         = $this->buildProductCategoryTargetPath($this->_entityId, $categoryId);
        $metadata           = $this->buildMetadata($categoryId);

        $this->upsertUrlRewriteRecord(self::REWRITE_TYPE_PRODUCT, $this->_entityId, $sluggedRequestPath, $targetPath, $storeId, $metadata);
    }

    /**
     * Insert/Update 'url_rewrite' record for category link
     *
     * @param int $categoryId
     * @param int $storeId
     *
     * @throws Exception
     */
    public function upsertCategoryRewrite(int $categoryId, int $storeId = 1)
    {
        $this->log('upsertCategoryRewrite()', ['categoryId' => $categoryId, 'storeId' => $storeId]);

        if ($categoryId === 1 || $categoryId === 2) {
            $this->log('upsertCategoryRewrite() - Root category found.  Skipping...');

            return;
        }

        // Category url_path + .html becomes request_path
        $categoryUrlPath = $this->getCategoryUrlPath($categoryId);
        $this->log('upsertCategoryRewrite()', ['categoryUrlPath' => $categoryUrlPath]);

        if ($categoryUrlPath !== null) {
            $targetPath = $this->buildCategoryTargetPath($categoryId);
            $this->log('upsertCategoryRewrite()', ['targetPath' => $targetPath]);

            $this->upsertUrlRewriteRecord(self::REWRITE_TYPE_CATEGORY, $categoryId, $categoryUrlPath, $targetPath, $storeId);
        }
    }

    /**
     * Cache 'attribute_id' for 'url_path" Category attribute
     *
     * @returns void
     * @throws Exception
     */
    private function initCategoryUrlPathAttributeId()
    {
        $table = $this->_dbHelper->getTableName('eav_attribute');
        $query = "SELECT `attribute_id` FROM `$table` WHERE `entity_type_id` = 3 AND `attribute_code` = 'url_path'";

        $results = $this->_dbHelper->select($query);
        if (!$results) {
            throw new StateException(__("Unable to lookup 'url_path' attribute id"));
        }

        $this->_categoryUrlPathAttributeId = $results[0]['attribute_id'];
    }

    /**
     * Build Product link
     *
     * @param int $productId
     *
     * @return string
     */
    private function buildProductTargetPath(int $productId)
    {
        return "catalog/product/view/id/$productId";
    }

    /**
     * Build Product Category link
     *
     * @param int $productId
     * @param int $categoryId
     *
     * @return string
     */
    private function buildProductCategoryTargetPath(int $productId, int $categoryId)
    {
        return "catalog/product/view/id/$productId/category/$categoryId";
    }

    /**
     * Build Category link
     *
     * @param int $categoryId
     *
     * @return string
     */
    private function buildCategoryTargetPath(int $categoryId)
    {
        return "catalog/category/view/id/$categoryId";
    }

    /**
     * Build 'metadata' content
     *
     * @param int $categoryId
     *
     * @return string
     */
    private function buildMetadata(int $categoryId)
    {
        return '{"category_id":"' . $categoryId . '"}';
    }

    /**
     * Lookup request_path suffix
     *
     * @return string
     */
    private function getRequestPathSuffix()
    {
        return '.html';
    }

    /**
     * Lookup categoryIds from 'catalog_category_product'
     *
     * @param int $productEntityId
     *
     * @return int[]
     */
    private function getCategoryIds(int $productEntityId)
    {
        $this->log('getCategoryIds()', ['productEntityId' => $productEntityId]);

        $categoryIds = [];

        $table = $this->_dbHelper->getTableName('catalog_category_product');
        $query = "SELECT `category_id` FROM `$table` WHERE `product_id` = ?";
        $binds = [$productEntityId];

        $results = $this->_dbHelper->select($query, $binds);
        foreach ($results as $result) {
            if (isset($result['category_id'])) {
                $categoryId = $result['category_id'];
                if (is_numeric($categoryId)) {
                    $categoryIds[] = (int)$categoryId;
                }
            }
        }

        return $categoryIds;
    }

    /**
     * Lookup `catalog_category_entity_varchar`.`value` for 'url_path' attribute
     *
     * @param int $categoryId
     * @param int $storeId
     *
     * @return string|null
     */
    private function getCategoryUrlPath(int $categoryId, int $storeId = 0)
    {
        $this->log('getCategoryUrlPath()', ['categoryId' => $categoryId, 'storeId' => $storeId]);

        $productIdColumn = $this->_helper->getProductIdColumn();

        $table = $this->_dbHelper->getTableName('catalog_category_entity_varchar');
        $query = "SELECT `value` FROM `$table` WHERE `attribute_id` = ? AND `store_id` = ? AND `$productIdColumn` = ?";
        $binds = [$this->_categoryUrlPathAttributeId, $storeId, $categoryId];

        $value = $this->_dbHelper->selectOne($query, $binds, 'value');
        if ($value !== null) {
            return (string)$value;
        }

        return null;
    }

    /**
     * Lookup 'url_rewrite`.`url_rewrite_id` if it exists
     *
     * @param string $requestPath
     * @param int    $storeId
     *
     * @return int|null
     */
    private function getUniqueUrlRewriteId(string $requestPath, int $storeId = 1)
    {
        $this->log('getUniqueUrlRewriteId()', ['requestPath' => $requestPath, 'storeId' => $storeId]);

        $table = $this->_dbHelper->getTableName('url_rewrite');
        $query = "SELECT `url_rewrite_id` FROM `$table` WHERE `request_path` = ? AND `store_id` = ?";
        $binds = [$requestPath, $storeId];

        $urlRewriteId = $this->_dbHelper->selectOne($query, $binds, 'url_rewrite_id');
        if (is_numeric($urlRewriteId)) {
            return (int)$urlRewriteId;
        }

        return null;
    }

    /**
     * Returns 'url_rewrite' data for product
     *
     * @param int $productId
     *
     * @return array
     */
    private function getProductBaseUrlRewrites(int $productId)
    {
        $table = $this->_dbHelper->getTableName('url_rewrite');
        $query = "SELECT `url_rewrite_id`, `request_path` FROM `$table` WHERE `entity_type` = 'product' AND `metadata` IS NULL AND `entity_id` = ?";
        $binds = [$productId];

        return $this->_dbHelper->select($query, $binds);
    }

    /**
     * Update 'url_rewrite' record
     *
     * @param string      $entityType
     * @param int         $entityId
     * @param string      $requestPath Assumed to not have suffix.
     * @param string      $targetPath
     * @param int         $storeId
     * @param string|null $metadata
     *
     * @return void
     * @throws Exception
     */
    private function upsertUrlRewriteRecord(string $entityType, int $entityId, string $requestPath, string $targetPath, int $storeId = 1, string $metadata = null)
    {
        $this->log('upsertUrlRewriteRecord()', [
            'entityType'  => $entityType,
            'entityId'    => $entityId,
            'requestPath' => $requestPath,
            'targetPath'  => $targetPath,
            'storeId'     => $storeId,
            'metaData'    => $metadata
        ]);

        // Cache full requestPath
        $requestPathWithSuffix = $this->addSuffix($requestPath);

        // Cache existing and incoming url_rewrite_id values
        /** @var int|null $requestPathUrlRewriteId */
        $requestPathUrlRewriteId = $this->getUniqueUrlRewriteId($requestPathWithSuffix, $storeId); // existing lookup

        /** @var int|null $urlRewriteId */
        $urlRewriteId = $this->getUrlRewriteId($entityType, $entityId, $targetPath, $storeId);     // incoming lookup

        $this->log('upsertUrlRewriteRecord()', [
            'requestPath'             => $requestPath,
            'requestPathWithSuffix'   => $requestPathWithSuffix,
            'targetPath'              => $targetPath,
            'requestPathUrlRewriteId' => $requestPathUrlRewriteId,
            'urlRewriteId'            => $urlRewriteId
        ]);

        // Background:
        // - request_path: frontend url user will hit
        //   - category: line-card.html
        //   - product: advanced-poly-thing.html
        //
        // - target_path: internal (ugly) url
        //   - category: catalog/category/view/id/8
        //   - product: catalog/product/view/id/8)
        //
        // Logic setup:
        // 1. We look for an existing record using REQUESTPATH and STOREID, and if we find it we put the value into
        //    $requestPathUrlRewriteId. This value represents an existing url rewrite which takes the frontend url, and
        //    maps it to the internal url.
        // 2. We take our incoming data and perform a lookup. If the record exists, we store in $urlRewriteId. This
        //    value represents an existing url_rewrite record for the incoming data. If it exists, then the url_rewrite
        //    has already been created. If not, we need to attempt to create it.
        //
        // Logic
        // - If $urlRewriteId (2) is not null, this means that our incoming record already exists in Magento. Next, we
        //   need to check $requestPathUrlRewriteId (1). If it is null, which means that there isn't a mapping for the
        //   request_path, and we can update our url_rewrite record (2) to use that request_path. If it is not null,
        //   then we need to check whether or not it matches our incoming value (2). If it matches (most likely case)
        //   then we update (2) to point to (1). If it doesn't match, that means that the existing url_rewrite record
        //   (1) cannot be overwritten, and we throw an error.
        //
        // - If we haven't returned or thrown an exception, this means that our initial $urlRewriteId lookup (1)
        //   failed. This means that the incoming record does not exist in Magento yet, and we have to attempt to create
        //   it. To create it, we have to check if the request_path is taken (1). If it's not taken, we create it. If it
        //   is already taken, we throw an exception.

        // Check for existing `url_rewrite` record for incoming [product / targetPath / store] combo
        if ($urlRewriteId !== null) {
            // Existing url_rewrite record found for incoming [product / targetPath / store]

            // If the requested requestPath is not already in use, update existing product rewrite with new requestPath
            if ($requestPathUrlRewriteId === null) {
                $this->log("upsertUrlRewriteRecord() - RequestPath [$requestPathWithSuffix] is not already mapped");
                $this->updateUrlRewriteRecord($urlRewriteId, $requestPathWithSuffix, $targetPath, $metadata);

                return;
            } else {
                // Requested requestPath is already in use
                $this->log("upsertUrlRewriteRecord() - RequestPath [$requestPathWithSuffix] is already in use by Url Rewrite [$requestPathUrlRewriteId]");
            }

            // It MAY be in use by our incoming value
            if ($requestPathUrlRewriteId == $urlRewriteId) {
                $this->log('upsertUrlRewriteRecord() - RequestPath rewrite and product rewrite are the same, updating rewrite...');
                $this->updateUrlRewriteRecord($urlRewriteId, $requestPathWithSuffix, $targetPath, $metadata);

                return;
            } else {
                $this->log("upsertUrlRewriteRecord() - RequestPath rewrite ($requestPathUrlRewriteId) and product rewrite ($urlRewriteId) are not the same");
            }

            throw new LocalizedException(
                __("Unable to update url rewrite - RequestPath [$requestPathWithSuffix] is already in use by URL Rewrite [$requestPathUrlRewriteId]")
            );
        }

        // Could not find existing url_rewrite record for this [product / targetPath / store]

        // If the requested requestPath isn't used, create it.
        if ($requestPathUrlRewriteId === null) {
            $this->log("upsertUrlRewriteRecord() - RequestPath [$requestPathWithSuffix] is not already in use.");
            $this->insertUrlRewriteRecord($entityType, $entityId, $requestPathWithSuffix, $targetPath, $storeId, $metadata);

            return;
        } else {
            $this->log("upsertUrlRewriteRecord() - RequestPath [$requestPathWithSuffix] is already in use by Url Rewrite [$requestPathUrlRewriteId]");
        }

        throw new LocalizedException(
            __("Unable to create url rewrite - RequestPath [$requestPathWithSuffix] is already in use by Url Rewrite [$requestPathUrlRewriteId]")
        );
    }

    /**
     * Lookup 'url_rewrite_id' from 'url_rewrite'
     *
     * @param string $entityType
     * @param int    $entityId
     * @param string $targetPath
     * @param int    $storeId
     *
     * @return int|null
     */
    private function getUrlRewriteId(string $entityType, int $entityId, string $targetPath, int $storeId = 1)
    {
        $this->log('getUrlRewriteId()', [
            'entityType' => $entityType,
            'entityId'   => $entityId,
            'targetPath' => $targetPath,
            'storeId'    => $storeId
        ]);

        $table = $this->_dbHelper->getTableName('url_rewrite');
        $query = "SELECT `url_rewrite_id` FROM `$table` WHERE `entity_type` = ? AND `entity_id` = ? AND `target_path` = ? AND `store_id` = ?";
        $binds = [$entityType, $entityId, $targetPath, $storeId];

        $urlRewriteId = $this->_dbHelper->selectOne($query, $binds, 'url_rewrite_id');
        if (is_numeric($urlRewriteId)) {
            return (int)$urlRewriteId;
        }

        return null;
    }

    /**
     * Insert record into 'url_rewrite'
     *
     * @param string      $entityType
     * @param int         $entityId
     * @param string      $requestPath
     * @param string      $targetPath
     * @param int         $storeId
     * @param string|null $metadata
     *
     * @return void
     */
    private function insertUrlRewriteRecord(string $entityType, int $entityId, string $requestPath, string $targetPath, int $storeId = 1, string $metadata = null)
    {
        $this->log('insertUrlRewriteRecord()', [
            'entityType'  => $entityType,
            'entityId'    => $entityId,
            'requestPath' => $requestPath,
            'targetPath'  => $targetPath,
            'storeId'     => $storeId,
            'metadata'    => $metadata
        ]);

        $table = $this->_dbHelper->getTableName('url_rewrite');
        $query = "INSERT INTO `$table` (`entity_type`, `entity_id`, `request_path`, `target_path`, `store_id`, `is_autogenerated`, `metadata`) VALUES (?,?,?,?,?,?,?)";
        $binds = [$entityType, $entityId, $requestPath, $targetPath, $storeId, 1, $metadata];

        try {
            $this->_dbHelper->insert($query, $binds);
        } catch (Exception $e) {
            $this->log('insertUrlRewriteRecord()', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Update 'url_rewrite' record
     *
     * @param int         $urlRewriteId
     * @param string      $requestPath
     * @param string      $targetPath
     * @param string|null $metadata
     *
     * @return void
     */
    private function updateUrlRewriteRecord(int $urlRewriteId, string $requestPath, string $targetPath, string $metadata = null)
    {
        $this->log('updateUrlRewriteRecord()', [
            'urlRewriteId' => $urlRewriteId,
            'requestPath'  => $requestPath,
            'targetPath'   => $targetPath,
            'metadata'     => $metadata
        ]);

        $table = $this->_dbHelper->getTableName('url_rewrite');
        $query = "UPDATE `$table` SET `request_path`=?, `target_path`=?, `metadata`=? WHERE `url_rewrite_id`=?";
        $binds = [$requestPath, $targetPath, $metadata, $urlRewriteId];

        try {
            $this->_dbHelper->update($query, $binds);
        } catch (Exception $e) {
            $this->log('updateUrlRewriteRecord()', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Delete record from 'url_rewrite' by productId
     *
     * @param int $productId
     *
     * @return void
     */
    private function deleteProductBaseUrlRewriteByProductId(int $productId)
    {
        $this->log('deleteProductBaseUrlRewriteByProductId()', ['productId' => $productId]);

        $table = $this->_dbHelper->getTableName('url_rewrite');
        $query = "DELETE FROM `$table` WHERE `entity_type` = 'product' AND `metadata` IS NULL AND `entity_id`=?";
        $binds = [$productId];

        $this->_dbHelper->delete($query, $binds);
    }

    /**
     * @return bool
     */
    private function shouldGenerateCategoryProductRewrites()
    {
        return $this->_helper->shouldGenerateCatalogProductRewrites();
    }

    private function clearCategoryProductRewrites(int $productId)
    {
        $this->log('clearCategoryProductRewrites()', ['productId' => $productId]);

        $table = $this->_dbHelper->getTableName('url_rewrite');
        $query = "DELETE FROM `$table` WHERE `entity_type` = 'product' AND `entity_id`=? AND `metadata` IS NOT NULL";
        $binds = [$productId];

        $this->_dbHelper->delete($query, $binds);
    }

    /**
     * Adds ".html" suffix.  If suffix already exists, we don't add (effectively allowing one instance of ".html")
     *
     * @param string $url
     *
     * @return string
     */
    private function addSuffix(string $url)
    {
        $suffix = $this->getRequestPathSuffix();

        if ($this->endsWith($url, $suffix)) {
            return $url;
        }

        return $url . $suffix;
    }

    /**
     * Does string end with other string?
     *
     * @param string $haystack
     * @param string $needle
     *
     * @return bool
     */
    private function endsWith(string $haystack, string $needle)
    {
        $length = strlen($needle);
        if (!$length) {
            return true;
        }

        return substr($haystack, -$length) === $needle;
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
        $this->_logger->info('RewriteHelper - ' . $message, $extra);
    }
}
