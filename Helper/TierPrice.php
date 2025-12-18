<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use Magento\Customer\Api\Data\GroupInterface;
use ECInternet\RAPIDWebSync\Logger\Logger;
use ECInternet\RAPIDWebSync\Model\Config;
use ECInternet\RAPIDWebSync\Model\Db;
use Exception;

/**
 * TierPrice Helper
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class TierPrice
{
    private const ALL_GROUPS_KEY           = 'ALL_GROUPS';

    private const PRICE_SCOPE_GLOBAL       = 0;

    private const TIER_PRICES_KEY          = 'tier_prices';

    private const PRICING_MODE_ADDITION    = 1;

    private const PRICING_MODE_REPLACEMENT = 2;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Data
     */
    private $helper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\StoreWebsite
     */
    private $storeWebsiteHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Logger\Logger
     */
    private $logger;

    /**
     * @var \ECInternet\RAPIDWebSync\Model\Config
     */
    private $config;

    /**
     * @var \ECInternet\RAPIDWebSync\Model\Db
     */
    private $db;

    /**
     * @var string
     */
    private $_productIdColumn;

    /**
     * TierPrice constructor.
     *
     * @param \ECInternet\RAPIDWebSync\Helper\Data         $helper
     * @param \ECInternet\RAPIDWebSync\Helper\StoreWebsite $storeWebsiteHelper
     * @param \ECInternet\RAPIDWebSync\Logger\Logger       $logger
     * @param \ECInternet\RAPIDWebSync\Model\Config        $config
     * @param \ECInternet\RAPIDWebSync\Model\Db            $db
     */
    public function __construct(
        Data $helper,
        StoreWebsite $storeWebsiteHelper,
        Logger $logger,
        Config $config,
        Db $db,
    ) {
        $this->helper             = $helper;
        $this->storeWebsiteHelper = $storeWebsiteHelper;
        $this->logger            = $logger;
        $this->config              = $config;
        $this->db                  = $db;
    }

    /**
     * @param array  $product
     * @param string $sku
     * @param int    $productId
     *
     * @throws \Exception
     */
    public function processProduct(array $product, string $sku, int $productId)
    {
        $this->log('| -- Start Tier Price Processor --');
        $this->log("| Sku: [$sku]");
        $this->log("| ProductId: [$productId]");

        if (!isset($product[self::TIER_PRICES_KEY])) {
            $this->log('| Tier price key not found');
            $this->log('| -- End Tier Price Processor --' . PHP_EOL);
            return;
        }

        if (!is_array($product[self::TIER_PRICES_KEY])) {
            $this->log('| Tier price key is not an array');
            $this->log('| -- End Tier Price Processor --' . PHP_EOL);
            return;
        }

        // Cache TierPrices
        $productTierPrices = $product[self::TIER_PRICES_KEY];

        /** @var int[] $websiteIds */
        $websiteIds = $this->getWebsiteIds($product);

        ////////////////////////////////////////
        // DELETE PREVIOUS DATA
        ////////////////////////////////////////

        if ($this->shouldClearPricingRecords()) {
            // Find CustomerGroupIds specified in data (if any)
            $customerGroupIds = $this->aggregateCustomerGroupIds($productTierPrices);

            // If we find any, remove those records from DB
            if (!empty($customerGroupIds)) {
                $this->deleteProductTierPriceRecordsForWebsiteAndGroup($productId, $websiteIds, $customerGroupIds);
            } else {
                $this->deleteProductTierPriceRecordsForWebsite($productId, $websiteIds);
            }

            // Delete records for 'all_groups' = 1
            $this->deleteProductTierPriceRecordsForAllGroups($productId);
        }

        ////////////////////////////////////////
        /// INSERT NEW DATA
        ////////////////////////////////////////

        // Iterate over the Product's TierPrices.  Add record for each website.
        foreach ($productTierPrices as $productTierPrice) {
            // Default CustomerGroupId to null which will create a record for 'all_groups'

            /** @var int|null $customerGroupId */
            $customerGroupId   = null;
            $customerGroupCode = null;

            // Attempt to parse CustomerGroup from data
            if (isset($productTierPrice['customer_group_id'])) {
                if ($customerGroupCode = (string)$productTierPrice['customer_group_id']) {
                    // First check for ALL_GROUPS
                    if ($customerGroupCode === self::ALL_GROUPS_KEY) {
                        $customerGroupId = GroupInterface::CUST_GROUP_ALL;
                    } else {
                        $customerGroupId = $this->getCustomerGroupId($customerGroupCode);

                        // Create one if we can't find existing one
                        if ($customerGroupId === null) {
                            $customerGroupId = $this->createCustomerGroup($customerGroupCode);
                        }
                    }
                }
            }

            // Set the rest of TierPrice values
            if ($customerGroupId === null || $customerGroupId === GroupInterface::CUST_GROUP_ALL) {
                $allGroups = 1;
            } else {
                $allGroups = 0;
            }

            if (!isset($productTierPrice['qty'])) {
                $this->log('processProduct()', ['"qty" field missing.']);
                continue;
            }

            if (!isset($productTierPrice['price'])) {
                $this->log('processProduct()', ['"price" field missing.']);
                continue;
            }

            $qty   = (int)$productTierPrice['qty'];
            $price = (float)$productTierPrice['price'];

            // If we never found a value, set to 0 for 'all_groups'
            if ($customerGroupId === null) {
                $customerGroupId = 0;
            }

            // We are guaranteed to have at least 1 (either list from IMan, or default of [0])
            foreach ($websiteIds as $websiteId) {
                // If we don't have CustomerGroup, create on-the-fly
                if ($customerGroupId === null) {
                    if ($customerGroupCode !== null) {
                        $customerGroupId = $this->createCustomerGroup($customerGroupCode);
                    }
                }

                // One of these will be added for each record of data we want to add.
                $this->addIgnoreTierPriceRecord($productId, $allGroups, $customerGroupId, $qty, $price, $websiteId);
            }
        }

        $this->log('| -- End Tier Price Processor --' . PHP_EOL);
    }

    /**
     * @return string
     */
    private function getProductIdColumn()
    {
        if ($this->_productIdColumn === null) {
            $this->_productIdColumn = $this->helper->getProductIdColumn();
        }

        return $this->_productIdColumn;
    }

    /**
     * @param string $customerGroupCode
     *
     * @return int|null
     */
    private function getCustomerGroupId(string $customerGroupCode)
    {
        $this->log('getCustomerGroupId()', ['customerGroupCode' => $customerGroupCode]);

        $customerGroups = $this->getCustomerGroups();

        if (isset($customerGroups[$customerGroupCode])) {
            $customerGroupId = $customerGroups[$customerGroupCode];
            if (is_numeric($customerGroupId)) {
                return (int)$customerGroupId;
            }
        }

        return null;
    }

    /**
     * @param string $groupName
     *
     * @return int
     */
    private function createCustomerGroup(string $groupName)
    {
        $this->log('createCustomerGroup()', ['groupName' => $groupName]);

        $table = $this->db->getTableName('customer_group');
        $query = "INSERT INTO `$table` (`customer_group_code`, `tax_class_id`) VALUES (?, ?)";
        $binds = [$groupName, $this->getTaxClassId()];

        return $this->db->insert($query, $binds);
    }

    /**
     * @return array
     */
    private function getCustomerGroups()
    {
        $customerGroups = [];

        $table = $this->db->getTableName('customer_group');
        $query = "SELECT `customer_group_id`, `customer_group_code` FROM `$table`";

        $results = $this->db->select($query);
        foreach ($results as $result) {
            $customerGroups[$result['customer_group_code']] = $result['customer_group_id'];
        }

        return $customerGroups;
    }

    /**
     * Gets TaxClass Id for Customer object
     *
     * @return int
     */
    protected function getTaxClassId()
    {
        $table = $this->db->getTableName('tax_class');
        $query = "SELECT `class_id` FROM `$table` WHERE `class_type` = ?";
        $binds = ['CUSTOMER'];

        return $this->db->selectOne($query, $binds, 'class_id');
    }

    /**
     * @param array $product
     *
     * @return int[]
     */
    protected function getWebsiteIds(array $product)
    {
        // Single store, only set for storeId 0
        if (!$this->db->isSingleStore()) {
            return [0];
        }

        // Confirm valid price scope for comparison
        $priceScope = $this->getPriceScope();
        if (!is_numeric($priceScope)) {
            $this->log('getWebsiteIds() - Non-numeric price scope.');
            return [0];
        }

        // Global price, only set for storeId 0
        if ((int)$priceScope === self::PRICE_SCOPE_GLOBAL) {
            return [0];
        }

        try {
            return $this->storeWebsiteHelper->getWebsiteIdsForProduct($product);
        } catch (Exception $e) {
            $this->log('getWebsiteIds()', ['exception' => $e]);
        }

        return [0];
    }

    /**
     * Should we wipe out previous pricing records?
     *
     * @return bool
     */
    private function shouldClearPricingRecords()
    {
        return $this->config->getPricingMode() === self::PRICING_MODE_REPLACEMENT;
    }

    /**
     * @return mixed
     */
    private function getPriceScope()
    {
        $table = $this->db->getTableName('core_config_data');

        // Check price attribute scope in config (0 = global, 1 = website)
        $query = "SELECT `value` FROM `$table` WHERE `path` = ?";
        $binds = ['catalog/price/scope'];

        return $this->db->selectOne($query, $binds, 'value');
    }

    /**
     * @param array $tierPrices
     *
     * @return string[]
     */
    protected function aggregateCustomerGroupIds(array $tierPrices)
    {
        $customerGroupIds = [];

        foreach ($tierPrices as $tierPrice) {
            if (isset($tierPrice['customer_group_id'])) {
                if ((string)$tierPrice['customer_group_id'] !== '') {
                    $customerGroupIds[] = $tierPrice['customer_group_id'];
                }
            }
        }

        return $customerGroupIds;
    }

    /**
     * @param int   $productId
     * @param int   $allGroups
     * @param int   $customerGroupId
     * @param int   $qty
     * @param float $value
     * @param int   $websiteId
     *
     * @return int
     */
    protected function addIgnoreTierPriceRecord(
        int $productId,
        int $allGroups,
        int $customerGroupId,
        int $qty,
        float $value,
        int $websiteId
    ) {
        $this->log('addIgnoreTierPriceRecord', [
            'productId'       => $productId,
            'allGroups'       => $allGroups,
            'customerGroupId' => $customerGroupId,
            'qty'             => $qty,
            'value'           => $value,
            'websiteId'       => $websiteId
        ]);

        $table = $this->db->getTableName('catalog_product_entity_tier_price');
        $query = "INSERT INTO `$table` (`{$this->getProductIdColumn()}`, `all_groups`, `customer_group_id`, `qty`, `value`, `website_id`)
                  VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        $binds = [$productId, $allGroups, $customerGroupId, $qty, $value, $websiteId];

        return $this->db->insert($query, $binds);
    }

    /**
     * @param int   $productId
     * @param int[] $websiteIds
     */
    protected function deleteProductTierPriceRecordsForWebsite(int $productId, array $websiteIds)
    {
        $this->log('deleteProductTierPriceRecordsForWebsite()', [
            'productId'  => $productId,
            'websiteIds' => $websiteIds
        ]);

        $table = $this->db->getTableName('catalog_product_entity_tier_price');
        $query = "DELETE FROM `$table` WHERE `{$this->getProductIdColumn()}` = ? AND `website_id` IN (?)";
        $binds = [$productId, $this->helper->arrayToCommaSeparatedValues($websiteIds)];

        try {
            $this->db->select($query, $binds);
        } catch (Exception $exception) {
            $this->log("Cannot call 'deleteProductTierPriceRecordsForWebsite' - {$exception->getMessage()}.");
        }
    }

    /**
     * Delete records from 'catalog_product_entity_tier_price'
     *
     * @param int      $productId
     * @param int[]    $websiteIds
     * @param string[] $customerGroupCodes
     *
     * @return void
     */
    protected function deleteProductTierPriceRecordsForWebsiteAndGroup(
        int $productId,
        array $websiteIds,
        array $customerGroupCodes
    ) {
        $this->log('deleteProductTierPriceRecordsForWebsiteAndGroup()', [
            'productId'          => $productId,
            'websiteIds'         => $websiteIds,
            'customerGroupCodes' => $customerGroupCodes
        ]);

        $customerGroupIds = [];
        foreach ($customerGroupCodes as $customerGroupCode) {
            $customerGroupId = $this->getCustomerGroupId($customerGroupCode);
            if ($customerGroupId !== null) {
                $customerGroupIds[] = $customerGroupId;
            }
        }

        $table = $this->db->getTableName('catalog_product_entity_tier_price');
        $query = "DELETE FROM `$table` WHERE `{$this->getProductIdColumn()}` = ? AND `website_id` IN (?) AND `customer_group_id` IN (?)";
        $binds = [
            $productId,
            $this->helper->arrayToCommaSeparatedValues($websiteIds),
            $this->helper->arrayToCommaSeparatedValues($customerGroupIds)
        ];

        $this->db->delete($query, $binds);
    }

    /**
     * @param int $productId
     *
     * @return void
     */
    protected function deleteProductTierPriceRecordsForAllGroups(int $productId)
    {
        $this->log('deleteProductTierPriceRecordsForAllGroups()', ['productId' => $productId]);

        $table = $this->db->getTableName('catalog_product_entity_tier_price');
        $query = "DELETE FROM `$table` WHERE `{$this->getProductIdColumn()}` = ? AND `all_groups` = 1";
        $binds = [$productId];

        try {
            $this->db->delete($query, $binds);
        } catch (Exception $e) {
            $this->log('deleteProductTierPriceRecordsForAllGroups()', ['exception' => $e->getMessage()]);
        }
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
        $this->logger->info('Helper/TierPrice - ' . $message, $extra);
    }
}
