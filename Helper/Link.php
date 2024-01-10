<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use Magento\Catalog\Model\Product\Link as ProductLink;
use ECInternet\RAPIDWebSync\Logger\Logger;

/**
 * Link helper
 */
class Link
{
    const KEY_RELATED = 'related_products';

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
     * Link constructor.
     *
     * @param \ECInternet\RAPIDWebSync\Helper\Data   $helper
     * @param \ECInternet\RAPIDWebSync\Helper\Db     $dbHelper
     * @param \ECInternet\RAPIDWebSync\Logger\Logger $logger
     */
    public function __construct(
        Data $helper,
        Db $dbHelper,
        Logger $logger
    ) {
        $this->_helper   = $helper;
        $this->_dbHelper = $dbHelper;
        $this->_logger   = $logger;
    }

    /**
     * Run the Link processor
     *
     * @param array  $product
     * @param string $sku
     * @param int    $productId
     *
     * @throws \Exception
     */
    public function processProduct(array $product, string $sku, int $productId)
    {
        $this->info('| -- Start Product Link Processor --');
        $this->info("| Sku: [$sku]");
        $this->info("| ProductId: [$productId]");

        if (isset($product[self::KEY_RELATED])) {
            /** @var string $relatedProductsValue */
            $relatedProductsValue = (string)$product[self::KEY_RELATED];
            $this->info("| Value: [$relatedProductsValue]");

            /** @var int $entityId */
            if ($entityId = $this->getProductId($sku)) {
                if ($relatedProductsValue === '__DELETE__') {
                    $this->info("processProduct() - DELETE flag found. Removing 'related_products' for [$sku]");
                    $this->deleteLinks($entityId);
                } else {
                    // Get import mode from settings. 1 -> Addition, 2 -> Replacement
                    $importMode = $this->_helper->getRelatedProductsImportMode();
                    $this->info('processProduct()', [
                        'importMode' => $importMode === 1 ? 'Addition' : 'Replacement'
                    ]);

                    $relatedProductIds = $this->getProductIds($relatedProductsValue);
                    $this->info('processProduct()', ['relatedProductIds' => $relatedProductIds]);

                    // if replacement, remove links for this product NOT in the incoming list
                    if ($importMode === 2) {
                        $this->deleteExcludeList($entityId, $relatedProductIds, ProductLink::LINK_TYPE_RELATED);
                    }

                    // Add links, existing ones will not throw error thanks to INSERT IGNORE
                    $this->addRelatedLinks($entityId, $relatedProductIds);
                }
            }
        } else {
            $this->info("processProduct() - Field 'related_products' not found");
        }

        $this->info('| -- End Product Link Processor --' . PHP_EOL);
    }

    /**
     * Get array of related product skus
     *
     * @param int $productId
     *
     * @return string[]
     */
    public function getRelatedProductSkus(int $productId)
    {
        $this->info('getRelatedProductSkus()', ['productId' => $productId]);

        $relatedProductSkus = [];

        if ($relatedProductIds = $this->getRelatedProductIds($productId)) {
            $this->info('getRelatedProductSkus()', ['relatedProductIds' => $relatedProductIds]);

            foreach ($relatedProductIds as $relatedProductId) {
                if (is_numeric($relatedProductId)) {
                    if ($sku = $this->getProductSku((int)$relatedProductId)) {
                        $relatedProductSkus[] = $sku;
                    }
                }
            }
        }

        return $relatedProductSkus;
    }

    /**
     * @param int   $productId
     * @param int[] $linkedProductIds
     *
     * @return void
     */
    public function addRelatedLinks(int $productId, array $linkedProductIds)
    {
        $this->addLinks($productId, $linkedProductIds, ProductLink::LINK_TYPE_RELATED);
    }

    /**
     * Convert skus to entity_ids
     *
     * @param string $productSkusString
     *
     * @return int[]
     */
    private function getProductIds(string $productSkusString)
    {
        $this->info('getProductIds()', ['productSkusString' => $productSkusString]);

        $productIds = [];

        if ($productSkus = explode(',', $productSkusString)) {
            $this->info('getProductIds()', ['productSkus' => $productSkus]);

            foreach ($productSkus as $productSku) {
                if (!empty($productSku)) {
                    // Trim in case extra spaces were added to comma-separated list
                    $productSku = trim($productSku);
                    $this->info('getProductIds()', ['sku' => $productSku]);

                    if ($productId = $this->getProductId($productSku)) {
                        $this->info('getProductIds()', ['productId' => $productId]);

                        $productIds[] = $productId;
                    }
                }
            }
        }

        return $productIds;
    }

    /**
     * Get product entity_id for sku
     *
     * @param string $sku
     *
     * @return int|null
     */
    private function getProductId(string $sku)
    {
        $this->info('getProductId()', ['sku' => $sku]);

        return $this->_dbHelper->getProductId($sku);
    }

    /**
     * Get product sku for entity_id
     *
     * @param int $productId
     *
     * @return string
     */
    private function getProductSku(int $productId)
    {
        $this->info('getProductSku()', ['productId' => $productId]);

        return $this->_dbHelper->getProductSku($productId);
    }

    /**
     * @param int $productId
     *
     * @return array
     */
    private function getRelatedProductIds(int $productId)
    {
        $this->info('getRelatedProductIds()', ['productId' => $productId]);

        return $this->getLinkedProductIds($productId, ProductLink::LINK_TYPE_RELATED);
    }

    /**
     * Get 'catalog_product_link' records
     *
     * @param int $productId
     * @param int $linkTypeId
     *
     * @return array
     */
    private function getLinkedProductIds(int $productId, int $linkTypeId)
    {
        $this->info('getLinkedProductIds()', [
            'productId'  => $productId,
            'linkTypeId' => $linkTypeId
        ]);

        return $this->_dbHelper->getLinkedProductIds($productId, $linkTypeId);
    }

    /**
     * Add records to 'catalog_product_link'
     *
     * @param int   $productId
     * @param int[] $linkedProductIds
     * @param int   $linkTypeId
     *
     * @return void
     */
    private function addLinks(int $productId, array $linkedProductIds, int $linkTypeId)
    {
        $this->info('addLinks()', [
            'productId'        => $productId,
            'linkedProductIds' => $linkedProductIds,
            'linkTypeId'       => $linkTypeId
        ]);

        foreach ($linkedProductIds as $linkedProductId) {
            $this->addLink($productId, $linkedProductId, $linkTypeId);
        }
    }

    /**
     * Add record to 'catalog_product_link'
     *
     * @param int $productId
     * @param int $linkedProductId
     * @param int $linkTypeId
     *
     * @return void
     */
    private function addLink(int $productId, int $linkedProductId, int $linkTypeId)
    {
        $this->info('addLink()', [
            'productId'       => $productId,
            'linkedProductId' => $linkedProductId,
            'linkTypeId'      => $linkTypeId
        ]);

        $table = $this->_dbHelper->getTableName('catalog_product_link');
        $query = "INSERT IGNORE INTO `$table` (`product_id`, `linked_product_id`, `link_type_id`) VALUES (?,?,?)";
        $binds = [$productId, $linkedProductId, $linkTypeId];

        $this->_dbHelper->insert($query, $binds);
    }

    /**
     * Delete existing links not in our incoming list
     *
     * @param int   $productId
     * @param int[] $productIds
     * @param int   $linkTypeId
     *
     * @return void
     */
    private function deleteExcludeList(int $productId, array $productIds, int $linkTypeId)
    {
        $this->info('deleteExcludeList()', [
            'productId'  => $productId,
            'productIds' => $productIds,
            'linkTypeId' => $linkTypeId
        ]);

        $productIdsString = implode(',', $productIds);

        $table = $this->_dbHelper->getTableName('catalog_product_link');
        $query = "DELETE FROM `$table` WHERE `product_id` = ? AND `link_type_id` = ? AND `linked_product_id` NOT IN (?)";
        $binds = [$productId, $linkTypeId, $productIdsString];

        $this->_dbHelper->delete($query, $binds);
    }

    /**
     * Delete records from 'catalog_product_link'
     *
     * @param int $productId
     *
     * @return void
     */
    private function deleteLinks(int $productId)
    {
        $this->info('deleteLinks()', ['productId' => $productId]);

        $table = $this->_dbHelper->getTableName('catalog_product_link');
        $query = "DELETE FROM `$table` WHERE `product_id` = ?";
        $binds = [$productId];

        $this->_dbHelper->delete($query, $binds);
    }

    ////////////////////////////////////////////////////
    ///
    /// SETTINGS
    ///
    ////////////////////////////////////////////////////

    /**
     * Get import mode
     *
     * @return int
     */
    protected function getImportMode()
    {
        return $this->_helper->getRelatedProductsImportMode();
    }

    ////////////////////////////////////////////////////
    ///
    /// LOGGING
    ///
    ////////////////////////////////////////////////////

    /**
     * Write to extension log
     *
     * @param string $message
     * @param array  $extra
     *
     * @return void
     */
    private function info(string $message, array $extra = [])
    {
        $this->_logger->info('LinkHelper - ' . $message, $extra);
    }
}
