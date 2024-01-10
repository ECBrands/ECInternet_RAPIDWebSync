<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use Magento\Framework\Exception\InputException;
use ECInternet\RAPIDWebSync\Logger\Logger;
use Exception;

/**
 * Store / Website helper
 */
class StoreWebsite
{
    const ADMIN_STORECODE = 'admin';

    const FIELD_STORE     = 'store';

    const FIELD_WEBSITES  = 'websites';

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Data
     */
    private $_helper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Db
     */
    private $_db;

    /**
     * @var \ECInternet\RAPIDWebSync\Logger\Logger
     */
    private $_logger;

    /**
     * @var array
     */
    private $_stores = [];

    /**
     * @var array
     */
    private $_websites = [];

    /**
     * @param \ECInternet\RAPIDWebSync\Helper\Data   $helper
     * @param \ECInternet\RAPIDWebSync\Helper\Db     $db
     * @param \ECInternet\RAPIDWebSync\Logger\Logger $logger
     */
    public function __construct(
        Data $helper,
        Db $db,
        Logger $logger
    ) {
        $this->_helper = $helper;
        $this->_db     = $db;
        $this->_logger = $logger;

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
        foreach ($this->_stores as $storeId => $storeData) {
            $storeIds[] = $storeId;
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
        $this->log('getStoreIdsForProduct()', ['product' => $product, 'scope' => $scope]);

        // If 'store' is not set, assume we're referring to admin (store_id = 0)
        if (!isset($product[self::FIELD_STORE])) {
            $product[self::FIELD_STORE] = static::ADMIN_STORECODE;
        }

        switch ($scope) {
            // Store-View Scope
            case 0:
                // Gets store_ids for the values in $product['store'] (possibly "admin" if nothing passed in)
                return $this->getStoreIdsForStoreScope((string)$product[self::FIELD_STORE]);

            // Global Scope
            case 1:
                // Gets store_id for the store with code "admin" (usually store_id = 0)
                return $this->getStoreIdsForStoreScope(static::ADMIN_STORECODE);

            // Website Scope
            case 2:
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

        $storeCodes = $this->_helper->commaSeparatedListToTrimmedArray($storeCodeString);
        $values     = $this->_helper->arrayToCommaSeparatedValueString($storeCodes);

        $table = $this->_db->getTableName('store');
        $query = "SELECT `store_id` FROM `$table` WHERE `code` IN ($values)";
        $binds = $storeCodes;

        if ($results = $this->_db->select($query, $binds)) {
            foreach ($results as $result) {
                if (isset($result['store_id'])) {
                    $storeId = $result['store_id'];
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

        $websiteCodes = $this->_helper->commaSeparatedListToTrimmedArray($websitesString);
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

        $storeCodes = $this->_helper->commaSeparatedListToTrimmedArray($storeCodeString);
        $values     = $this->_helper->arrayToCommaSeparatedValueString($storeCodes);

        $table = $this->_db->getTableName('store');
        $query = "SELECT `b`.`store_id` FROM `$table` as a
                  JOIN `$table` as b ON `b`.`website_id` = `a`.`website_id`
                  WHERE `a`.`code` IN ($values)";
        $binds = $storeCodes;

        if ($results = $this->_db->select($query, $binds)) {
            foreach ($results as $result) {
                if (isset($result['store_id'])) {
                    $storeId = $result['store_id'];
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
        if (isset($this->_websites[$websiteCode])) {
            $websiteId = $this->_websites[$websiteCode];
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

        if (trim($key) != static::ADMIN_STORECODE) {
            $storeCodes = $this->_helper->commaSeparatedListToTrimmedArray($key);
            foreach ($storeCodes as $storeCode) {
                $websiteIds[] = $this->getWebsiteIdsForStoreCode($storeCode);
            }
        } else {
            foreach ($this->_stores as $storeId => $storeData) {
                if ($storeId != 0) {
                    if (isset($storeData['website_id'])) {
                        $websiteId = $storeData['website_id'];
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

        foreach ($this->_stores as $storeData) {
            if ((string)$storeData['code'] === $storeCode) {
                if (isset($storeData['website_id'])) {
                    $websiteId = $storeData['website_id'];
                    if (is_numeric($websiteId)) {
                        $websiteIds[] = (int)$websiteId;
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
        $table = $this->_db->getTableName('store');
        $query = "SELECT `store_id`, `code`, `website_id` FROM `$table`";

        $results = $this->_db->select($query);
        foreach ($results as $result) {
            if (isset($result['website_id'])) {
                $websiteId = $result['website_id'];
                if (is_numeric($websiteId)) {
                    $this->_stores[$result['store_id']]               = [];
                    $this->_stores[$result['store_id']]['code']       = (string)$result['code'];
                    $this->_stores[$result['store_id']]['website_id'] = (int)$websiteId;
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
        $table = $this->_db->getTableName('store_website');
        $query = "SELECT `website_id`, `code` FROM `$table`";

        $results = $this->_db->select($query);
        foreach ($results as $result) {
            if (isset($result['code']) && isset($result['website_id'])) {
                $code      = $result['code'];
                $websiteId = $result['website_id'];

                if (is_numeric($websiteId)) {
                    $this->_websites[$code] = (int)$websiteId;
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
        $this->_logger->info('StoreWebsiteHelper - ' . $message, $extra);
    }
}
