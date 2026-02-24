<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use Magento\Framework\Exception\InputException;
use Magento\Store\Model\Data\StoreConfig;
use Magento\Store\Model\Store;
use ECInternet\RAPIDWebSync\Logger\Logger;
use ECInternet\RAPIDWebSync\Model\Db;
use ECInternet\RAPIDWebSync\Util\ArrayString;
use Exception;

/**
 * Store / Website helper
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class StoreWebsite
{
    private const ADMIN_STORECODE = 'admin';

    private const FIELD_STORE     = 'store';

    private const FIELD_WEBSITES  = 'websites';

    public const SCOPE_STORE      = 0;

    public const SCOPE_GLOBAL     = 1;

    public const SCOPE_WEBSITE    = 2;

    /**
     * @var \ECInternet\RAPIDWebSync\Logger\Logger
     */
    private $logger;

    /**
     * @var \ECInternet\RAPIDWebSync\Model\Db
     */
    private $db;

    /**
     * @var \ECInternet\RAPIDWebSync\Util\ArrayString
     */
    private $arrayStringUtils;

    /**
     * @var array
     */
    private $stores = [];

    /**
     * @var array
     */
    private $websites = [];

    /**
     * StoreWebsite constructor.
     *
     * @param \ECInternet\RAPIDWebSync\Logger\Logger    $logger
     * @param \ECInternet\RAPIDWebSync\Model\Db         $db
     * @param \ECInternet\RAPIDWebSync\Util\ArrayString $arrayStringUtils
     */
    public function __construct(
        Logger $logger,
        Db $db,
        ArrayString $arrayStringUtils,
    ) {
        $this->logger           = $logger;
        $this->db               = $db;
        $this->arrayStringUtils = $arrayStringUtils;

        $this->initStoreArray();
        $this->initWebsiteArray();
    }

    /**
     * Get storeId array
     *
     * @return int[]
     */
    public function getStoreIds()
    {
        $storeIds = [];

        if ($storesData = $this->getStoresData()) {
            foreach (array_keys($storesData) as $storeId) {
                $storeIds[] = $storeId;
            }
        }

        return $storeIds;
    }

    /**
     * Return affected store ids for a given item given an attribute scope.
     *
     * May or may not need to start also returning 0.
     *
     * @param array $product
     * @param int   $scope
     *
     * @return int[]
     * @throws Exception
     */
    public function getStoreIdsForProduct(array $product, int $scope = 0)
    {
        $this->log('getStoreIdsForProduct()', ['sku' => $product['sku'], 'scope' => $scope]);

        // If 'store' is not set, assume we're referring to admin (store_id = 0)
        if (!isset($product[self::FIELD_STORE])) {
            $product[self::FIELD_STORE] = static::ADMIN_STORECODE;
        }

        switch ($scope) {
            case self::SCOPE_STORE:
                // Gets store_ids for the values in $product['store'] (possibly "admin" if nothing passed in)
                return $this->getStoreIdsForStoreScope((string)$product[self::FIELD_STORE]);

            case self::SCOPE_GLOBAL:
                // Gets store_id for the store with code "admin" (usually store_id = 0)
                return $this->getStoreIdsForStoreScope(static::ADMIN_STORECODE);

            case self::SCOPE_WEBSITE:
                // Gets store_ids that share website of $product['store'] (possibly "admin" if nothing is passed in)
                return $this->getStoreIdsForWebsiteScope((string)$product[self::FIELD_STORE]);

            default:
                throw new InputException(__("Unexpected 'scope' value: [$scope]."));
        }
    }

    /**
     * Returns an array of store_ids of the storeCodes passed as parameter
     *
     * @param string $storeCodeString
     *
     * @return int[]
     */
    public function getStoreIdsForStoreScope(string $storeCodeString)
    {
        $storeIds = [];

        $storeCodes = $this->arrayStringUtils->commaSeparatedListToTrimmedArray($storeCodeString);
        $values     = $this->arrayStringUtils->arrayToCommaSeparatedValueString($storeCodes);

        $table = $this->db->getTableName('store');
        $query = "SELECT `store_id` FROM `$table` WHERE `code` IN ($values)";
        $binds = $storeCodes;

        if ($results = $this->db->select($query, $binds)) {
            foreach ($results as $result) {
                if (isset($result[Store::STORE_ID])) {
                    $storeId = $result[Store::STORE_ID];
                    if (is_numeric($storeId)) {
                        $storeIds[] = (int)$storeId;
                    }
                }
            }
        }

        return $storeIds;
    }

    /**
     * Return website_ids for a product, based either on websites or store column.
     * We already built a list of website_ids in initWebsiteIds,
     * but this handles the cases when a product specifies multiples website_ids.
     *
     * Note: We return if we see the 'websites' column set, so 'store' may be ignored in that case.
     *
     * @param array $product
     *
     * @return int[]
     * @throws Exception
     */
    public function getWebsiteIdsForProduct(array $product)
    {
        // Handle 'websites' column if set
        if (!empty($product[self::FIELD_WEBSITES])) {
            return $this->getWebsiteIdsFromWebsitesColumn((string)$product[self::FIELD_WEBSITES]);
        }

        return $this->getWebsiteIdsFromStoreColumn($product);
    }

    /**
     * Build websiteId list from value in 'websites' column
     *
     * @param string $websitesString
     *
     * @return int[]
     * @throws Exception
     */
    public function getWebsiteIdsFromWebsitesColumn(string $websitesString)
    {
        $websiteIds = [];

        $websiteCodes = $this->arrayStringUtils->commaSeparatedListToTrimmedArray($websitesString);
        foreach ($websiteCodes as $websiteCode) {
            if ($websiteId = $this->getWebsiteIdForWebsiteCode($websiteCode)) {
                $websiteIds[] = $websiteId;
            } else {
                $this->log("| WARN: Unable to find website_id for website_code [$websiteCode].");
            }
        }

        return $websiteIds;
    }

    /**
     * Returns an array of store_ids that share the same website(s) of the storeCodes passed as parameter
     *
     * @param string $storeCodeString
     *
     * @return int[]
     */
    private function getStoreIdsForWebsiteScope(string $storeCodeString)
    {
        $storeIds = [];

        $storeCodes = $this->arrayStringUtils->commaSeparatedListToTrimmedArray($storeCodeString);
        $values     = $this->arrayStringUtils->arrayToCommaSeparatedValueString($storeCodes);

        $table = $this->db->getTableName('store');
        $query = "SELECT `b`.`store_id` FROM `$table` as a
                  JOIN `$table` as b ON `b`.`website_id` = `a`.`website_id`
                  WHERE `a`.`code` IN ($values)";
        $binds = $storeCodes;

        if ($results = $this->db->select($query, $binds)) {
            foreach ($results as $result) {
                if (isset($result[Store::STORE_ID])) {
                    $storeId = $result[Store::STORE_ID];
                    if (is_numeric($storeId)) {
                        $storeIds[] = (int)$storeId;
                    }
                }
            }
        }

        return $storeIds;
    }

    /**
     * Lookup website Id by code
     *
     * @param string $websiteCode
     *
     * @return int
     * @throws Exception
     */
    private function getWebsiteIdForWebsiteCode(string $websiteCode)
    {
        if (isset($this->websites[$websiteCode])) {
            $websiteId = $this->websites[$websiteCode];
            if (is_numeric($websiteId)) {
                return (int)$websiteId;
            }
        }

        throw new InputException(__("Could not find `website_id` for `website_code` [$websiteCode]."));
    }

    /**
     * Build websiteId list from 'store' column
     *
     * @param array $product
     *
     * @return int[]
     */
    private function getWebsiteIdsFromStoreColumn(array $product)
    {
        $websiteIds = [];

        // Default 'store' value to "admin" if not set
        $product[self::FIELD_STORE] = $product[self::FIELD_STORE] ?? static::ADMIN_STORECODE;

        // Use this value as our key when we iterate through website_ids
        $key = (string)$product[self::FIELD_STORE];

        if (trim($key) !== static::ADMIN_STORECODE) {
            $storeCodes = $this->arrayStringUtils->commaSeparatedListToTrimmedArray($key);
            foreach ($storeCodes as $storeCode) {
                $websiteIds[] = $this->getWebsiteIdsForStoreCode($storeCode);
            }

            return $websiteIds;
        }

        foreach ($this->stores as $storeId => $storeData) {
            if ($storeId != 0) {
                if (isset($storeData[StoreConfig::KEY_WEBSITE_ID])) {
                    $websiteId = $storeData[StoreConfig::KEY_WEBSITE_ID];
                    if (is_numeric($websiteId)) {
                        // Cast as int
                        $websiteId = (int)$websiteId;

                        if (!in_array($websiteId, $websiteIds)) {
                            $websiteIds[] = $websiteId;
                        }
                    }
                }
            }
        }

        return $websiteIds;
    }

    /**
     * Lookup website_ids for store_code
     *
     * @param string $storeCode
     *
     * @return int[]
     */
    private function getWebsiteIdsForStoreCode(string $storeCode)
    {
        $websiteIds = [];

        if ($storesData = $this->getStoresData()) {
            foreach ($storesData as $storeData) {
                if ((string)$storeData[StoreConfig::KEY_CODE] === $storeCode) {
                    if (isset($storeData[StoreConfig::KEY_WEBSITE_ID])) {
                        $websiteId = $storeData[StoreConfig::KEY_WEBSITE_ID];
                        if (is_numeric($websiteId)) {
                            $websiteIds[] = (int)$websiteId;
                        }
                    }
                }
            }
        }

        return $websiteIds;
    }

    /**
     * Cache 'store' table data
     *
     * @return void
     */
    private function initStoreArray()
    {
        $table = $this->db->getTableName('store');
        $query = "SELECT `store_id`, `code`, `website_id` FROM `$table`";

        $results = $this->db->select($query);
        foreach ($results as $result) {
            if (isset($result[StoreConfig::KEY_WEBSITE_ID])) {
                $websiteId = $result[StoreConfig::KEY_WEBSITE_ID];
                if (is_numeric($websiteId)) {
                    $this->stores[$result[Store::STORE_ID]]                              = [];
                    $this->stores[$result[Store::STORE_ID]][StoreConfig::KEY_CODE]       = (string)$result[StoreConfig::KEY_CODE];
                    $this->stores[$result[Store::STORE_ID]][StoreConfig::KEY_WEBSITE_ID] = (int)$websiteId;
                }
            }
        }
    }

    /**
     * Cache 'store_website' table data
     *
     * @return void
     */
    private function initWebsiteArray()
    {
        $table = $this->db->getTableName('store_website');
        $query = "SELECT `website_id`, `code` FROM `$table`";

        $results = $this->db->select($query);
        foreach ($results as $result) {
            if (isset($result[StoreConfig::KEY_CODE], $result[StoreConfig::KEY_WEBSITE_ID])) {
                $code      = $result[StoreConfig::KEY_CODE];
                $websiteId = $result[StoreConfig::KEY_WEBSITE_ID];

                if (is_numeric($websiteId)) {
                    $this->websites[$code] = (int)$websiteId;
                }
            }
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
        $this->logger->info('StoreWebsiteHelper - ' . $message, $extra);
    }
}
