<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Processor;

use ECInternet\RAPIDWebSync\Api\DataProcessorInterface;
use ECInternet\RAPIDWebSync\Model\Config;
use ECInternet\RAPIDWebSync\Model\Db;
use ECInternet\RAPIDWebSync\Util\ArrayString;
use Magento\Framework\Module\Manager as ModuleManager;
use Psr\Log\LoggerInterface;

/**
 * Inventory processor
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Inventory implements DataProcessorInterface
{
    private const DEFAULT_STOCK_ID     = 1;

    private const DEFAULT_STOCK_STATUS = 1;

    private const DEFAULT_STOCK_QTY    = 0;

    private const DEFAULT_SOURCE_CODE  = 'default';

    private const STOCK_ITEM_COLUMNS   = ['item_id', 'product_id', 'stock_id', 'qty', 'min_qty', 'use_config_min_qty',
                                          'is_qty_decimal', 'backorders', 'use_config_backorders', 'min_sale_qty',
                                          'use_config_min_sale_qty', 'max_sale_qty', 'use_config_max_sale_qty',
                                          'is_in_stock', 'low_stock_date', 'notify_stock_qty',
                                          'use_config_notify_stock_qty', 'manage_stock', 'use_config_manage_stock',
                                          'stock_status_changed_auto', 'use_config_qty_increments', 'qty_increments',
                                          'use_config_enable_qty_inc', 'enable_qty_increments', 'is_decimal_divided',
                                          'website_id'];

    /**
     * @var \Magento\Framework\Module\Manager
     */
    private $moduleManager;

    /**
     * @var \ECInternet\RAPIDWebSync\Model\Config
     */
    private $config;

    /**
     * @var \ECInternet\RAPIDWebSync\Model\Db
     */
    private $db;

    /**
     * @var \ECInternet\RAPIDWebSync\Util\ArrayString
     */
    private $arrayStringUtils;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var string
     */
    private $sku;

    /**
     * @var int
     */
    private $entityId;

    /**
     * Inventory constructor.
     *
     * @param \Magento\Framework\Module\Manager         $moduleManager
     * @param \ECInternet\RAPIDWebSync\Model\Config     $config
     * @param \ECInternet\RAPIDWebSync\Model\Db         $db
     * @param \ECInternet\RAPIDWebSync\Util\ArrayString $arrayStringUtils
     * @param \Psr\Log\LoggerInterface                  $logger
     */
    public function __construct(
        ModuleManager $moduleManager,
        Config $config,
        Db $db,
        ArrayString $arrayStringUtils,
        LoggerInterface $logger,
    ) {
        $this->moduleManager    = $moduleManager;
        $this->logger           = $logger;
        $this->config           = $config;
        $this->db               = $db;
        $this->arrayStringUtils = $arrayStringUtils;
    }

    public function processProductData(array $productData, string $sku, int $entityId)
    {
        $this->log('| -- Start Stock Processor --');
        $this->log("| Sku: [$sku]");
        $this->log("| ProductId: [$entityId]");

        // Cache sku and product_id
        $this->sku      = $sku;
        $this->entityId = $entityId;

        // Intersect product columns with columns in stock_item table,
        // and drop out if we don't have any columns to process
        $itemStockItemColumns = array_intersect(array_keys($productData), self::STOCK_ITEM_COLUMNS);
        $this->log('| Columns: [' . implode(', ', $itemStockItemColumns) . ']');
        if (count($itemStockItemColumns) === 0) {
            $this->log('| -- End Stock Processor --' . PHP_EOL);
            return;
        }

        // This may modify new fields, so afterwards we have to do another check on valid columns
        $productData = $this->setStockColumns($productData);

        // Add new record to `cataloginventory_stock_item` if not exists
        $this->upsertStockItemRecord();

        // Update `cataloginventory_stock_item` using values in $product
        $this->updateStockItemRecord($productData);

        // Clear existing `cataloginventory_stock_status` records.
        $this->clearStockStatusRecords();

        // Upsert `cataloginventory_stock_status` using values in $product
        $this->upsertStockStatusRecords($productData);

        // HANDLE MULTI SOURCE INVENTORY
        if ($this->isMultiSourceInventoryInstalled()) {
            $this->upsertInventorySourceItem($productData);

            // Remove inventory_reservation records
            if ($this->shouldAutomaticallyRemoveReservations()) {
                $this->clearReservations($sku);
            }
        }

        $this->log('| -- End Stock Processor --' . PHP_EOL);
    }

    /**
     * Conditionally set 'manage_stock' and 'use_config_manage_stock'
     * Conditionally set 'is_in_stock'
     *
     * @param array $product
     *
     * @return array
     */
    private function setStockColumns(array $product)
    {
        if (!isset($product['qty'])) {
            return $product;
        }

        // Conditionally auto-set manage_stock
        if ($this->config->shouldAutomaticallySetManageStock()) {
            // If 'manage_stock' is not mapped, set it to true, since we're obviously managing stock.
            if (!isset($product['manage_stock'])) {
                $product['manage_stock']            = 1;
                $product['use_config_manage_stock'] = 0;
            }
        }

        // Conditionally auto-set is_in_stock
        if ($this->config->shouldAutomaticallySetIsInStock()) {
            // If 'is_in_stock' is not mapped,
            if (!isset($product['is_in_stock'])) {
                // Default to 0 if not set
                $minQty = $product['min_qty'] ?? 0;

                // Set 'is_in_stock' based on incoming 'qty' compared to the `min_qty`
                $product['is_in_stock'] = ($product['qty'] > $minQty) ? 1 : 0;
            }
        }

        return $product;
    }

    /**
     * Is Magento_Inventory installed?
     *
     * @return bool
     */
    private function isMultiSourceInventoryInstalled()
    {
        return $this->moduleManager->isEnabled('Magento_Inventory');
    }

    private function shouldAutomaticallyRemoveReservations()
    {
        return $this->config->shouldAutomaticallyRemoveReservations();
    }

    /**
     * Upsert record in 'cataloginventory_stock_item'.
     *
     * COMMUNITY  - CONSTRAINT product_id --> catalog_product_entity.entity_id
     * ENTERPRISE - CONSTRAINT product_id --> sequence_product.sequence_value
     *
     * @param int $stockId
     *
     * @return void
     */
    private function upsertStockItemRecord(int $stockId = self::DEFAULT_STOCK_ID)
    {
        $this->log('upsertStockItemRecord()', ['stock_id' => $stockId]);

        $table = $this->db->getTableName('cataloginventory_stock_item');
        $query = "INSERT IGNORE INTO `$table` (`product_id`, `stock_id`) VALUES (?, ?)";
        $binds = [$this->entityId, $stockId];

        $this->db->insert($query, $binds);
    }

    /**
     * Update record in 'cataloginventory_stock_item' table.
     *
     * COMMUNITY  - CONSTRAINT product_id --> catalog_product_entity.entity_id
     * ENTERPRISE - CONSTRAINT product_id --> sequence_product.sequence_value
     *
     * @param array $product
     *
     * @return void
     */
    private function updateStockItemRecord(array $product)
    {
        $this->log('updateStockItemRecord()', ['product' => $product]);

        // Create update string from 'stock_item' columns in $product
        /** @var string[] $productStockItemColumns */
        $productStockItemColumns         = array_intersect(array_keys($product), self::STOCK_ITEM_COLUMNS);
        $productStockItemValues          = $this->arrayStringUtils->filterKeyValueArray($product, $productStockItemColumns);
        $productStockItemKeyValuesString = $this->arrayStringUtils->arrayToCommaSeparatedUpdateString($productStockItemValues);

        $table = $this->db->getTableName('cataloginventory_stock_item');
        $query = "UPDATE `$table` SET $productStockItemKeyValuesString WHERE `product_id` = ? AND `stock_id` = ?";
        $binds = array_merge(array_values($productStockItemValues), [$this->entityId, self::DEFAULT_STOCK_ID]);

        $this->db->update($query, $binds);
    }

    /**
     * Upsert records in 'cataloginventory_stock_status' table.
     *
     * @param array $product
     */
    private function upsertStockStatusRecords(array $product)
    {
        $this->log('upsertStockStatusRecords()');

        // Set defaults if values aren't set on $product
        $stockId     = isset($product['stock_id'])     ? (int)$product['stock_id']     : self::DEFAULT_STOCK_ID;
        $stockStatus = isset($product['stock_status']) ? (int)$product['stock_status'] : self::DEFAULT_STOCK_STATUS;
        $qty         = isset($product['qty'])          ? (int)$product['qty']          : self::DEFAULT_STOCK_QTY;

        $stockWebsiteId = $this->getStockWebsiteId($stockId);
        if ($stockWebsiteId !== null) {
            $this->upsertStockStatusRecord($stockWebsiteId, $stockId, $qty, $stockStatus);
        }
    }

    /**
     * @return void
     */
    private function clearStockStatusRecords()
    {
        $this->log('clearStockStatusRecords()');

        $table = $this->db->getTableName('cataloginventory_stock_status');
        $query = "DELETE FROM `$table` WHERE `product_id` = ?";
        $binds = [$this->entityId];

        $this->db->delete($query, $binds);
    }

    /**
     * Upsert record in 'cataloginventory_stock_status' table.
     *
     * @param int $websiteId
     * @param int $stockId
     * @param int $qty
     * @param int $stockStatus
     *
     * @return void
     */
    private function upsertStockStatusRecord(int $websiteId, int $stockId, int $qty, int $stockStatus)
    {
        $this->log('upsertStockStatusRecord()', [
            'websiteId'   => $websiteId,
            'stockId'     => $stockId,
            'qty'         => $qty,
            'stockStatus' => $stockStatus
        ]);

        $table = $this->db->getTableName('cataloginventory_stock_status');
        $query = "INSERT INTO `$table` (`product_id`, `website_id`, `stock_id`, `qty`, `stock_status`)
                  VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE stock_status=VALUES(`stock_status`), qty=VALUES(`qty`)";
        $binds = [$this->entityId, $websiteId, $stockId, $qty, $stockStatus];

        $this->db->insert($query, $binds);
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ///
    /// MULTI SOURCE INVENTORY
    ///
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

    /**
     * @param int $stockId
     *
     * @return int|null
     */
    private function getStockWebsiteId(int $stockId)
    {
        $this->log('getStockWebsiteId()', ['stockId' => $stockId]);

        $table = $this->db->getTableName('cataloginventory_stock');
        $query = "SELECT `website_id` FROM `$table` WHERE `stock_id` = ?";
        $binds = [$stockId];

        $websiteId = $this->db->selectOne($query, $binds, 'website_id');
        if (is_numeric($websiteId)) {
            return (int)$websiteId;
        }

        return null;
    }

    /**
     * @param array $product
     *
     * @return void
     */
    private function upsertInventorySourceItem(array $product)
    {
        $this->log('upsertInventorySourceItem()');

        if (!isset($product['qty'])) {
            return;
        }

        // Cache it
        $qty = $product['qty'];

        // Qty must be numeric
        if (!is_numeric($qty)) {
            $this->log('upsertInventorySourceItem() - Qty is not numeric');
            return;
        }

        // Check if table exists
        if (!$this->db->doesTableExist($this->db->getTableName('inventory_source_item'))) {
            $this->log("upsertInventorySourceItem() - Table 'inventory_source_item' missing");
            return;
        }

        // Use 'source_code' if passed in, else use default ('default')
        $sourceCode  = $product['source_code'] ?? self::DEFAULT_SOURCE_CODE;

        // Check if 'is_in_stock' is set and numeric
        $stockStatus = isset($product['is_in_stock']) && is_numeric($product['is_in_stock'])
            ? (int)$product['is_in_stock']
            : 0;

        $this->upsertInventorySourceItemRecord($sourceCode, $this->sku, (int)$qty, $stockStatus);
    }

    private function clearReservations(string $sku)
    {
        $this->log('clearReservations()', ['sku' => $sku]);

        $table = $this->db->getTableName('inventory_reservation');
        $query = "DELETE FROM `$table` WHERE `sku` = ?";
        $binds = [$sku];

        $response = $this->db->delete($query, $binds);
        $this->log('clearReservations()', ['rowCount' => $response->rowCount()]);
    }

    private function upsertInventorySourceItemRecord(string $sourceCode, string $sku, int $qty, int $status = 0)
    {
        $this->log('upsertInventorySourceItemRecord()', [
            'sourceCode' => $sourceCode,
            'sku'        => $sku,
            'qty'        => $qty,
            'status'     => $status
        ]);

        $table = $this->db->getTableName('inventory_source_item');
        $query = "INSERT INTO `$table` (`source_code`, `sku`, `quantity`, `status`)
                  VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantity=VALUES(`quantity`), status=VALUES(`status`)";
        $binds = [$sourceCode, $sku, $qty, $status];

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
        $this->logger->info('Inventory - ' . $message, $extra);
    }
}
