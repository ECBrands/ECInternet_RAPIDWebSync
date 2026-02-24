<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use Magento\Framework\Exception\InputException;
use ECInternet\RAPIDWebSync\Model\Config;
use ECInternet\RAPIDWebSync\Model\Db;
use ECInternet\RAPIDWebSync\Model\Magento\Environment;
use ECInternet\RAPIDWebSync\Util\ArrayString;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Category Helper
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Category
{
    private const KEY                       = 'categories';

    private const DEFAULT_ROOT_PATH_KEY     = '%RP:base%';

    private const CATEGORY_MODE_ADDITION    = 1;

    private const CATEGORY_MODE_REPLACEMENT = 2;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Rewrite
     */
    private $rewriteHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\StoreWebsite
     */
    private $storeWebsiteHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Model\Config
     */
    private $config;

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
     * @var string
     */
    private $productIdColumn;

    /**
     * @var array
     */
    private $categoryInfos = [];

    /**
     * @var array
     */
    private $categoryRootStores = [];

    /**
     * @var array
     */
    private $categoryRootWebsites = [];

    /**
     * Tricky escaped separator that matches slugging separator
     *
     * @var string
     */
    private $escapedTreeSeparator = '---';

    /**
     * Category constructor.
     *
     * @param \ECInternet\RAPIDWebSync\Helper\Rewrite            $rewriteHelper
     * @param \ECInternet\RAPIDWebSync\Helper\StoreWebsite       $storeWebsiteHelper
     * @param \ECInternet\RAPIDWebSync\Model\Config              $config
     * @param \ECInternet\RAPIDWebSync\Model\Db                  $db
     * @param \ECInternet\RAPIDWebSync\Model\Magento\Environment $magentoEnvironment
     * @param \ECInternet\RAPIDWebSync\Util\ArrayString          $arrayStringUtils
     * @param \Psr\Log\LoggerInterface                           $logger
     */
    public function __construct(
        Rewrite $rewriteHelper,
        StoreWebsite $storeWebsiteHelper,
        Config $config,
        Db $db,
        Environment $magentoEnvironment,
        ArrayString $arrayStringUtils,
        LoggerInterface $logger,
    ) {
        $this->rewriteHelper      = $rewriteHelper;
        $this->storeWebsiteHelper = $storeWebsiteHelper;
        $this->logger             = $logger;
        $this->config             = $config;
        $this->db                 = $db;
        $this->magentoEnvironment = $magentoEnvironment;
        $this->arrayStringUtils   = $arrayStringUtils;

        $this->initializeCategories();
        $this->initializeCategoryInfo();
    }

    /**
     * Run category processor
     *
     * @param array  $product
     * @param string $sku
     * @param int    $productId
     *
     * @throws \Exception
     */
    public function processProduct(array &$product, string $sku, int $productId)
    {
        $this->log('| -- Start Product Category Processor --');
        $this->log("| Sku: [$sku]");
        $this->log("| ProductId: [$productId]");

        if (!isset($product[self::KEY])) {
            $this->log("| NOTE: Product does not have '" . self::KEY . "' column set.");
            $this->log('| -- End Product Category Processor --' . PHP_EOL);

            return;
        }

        $productCategoriesString = (string)$product[self::KEY];
        $this->log("| Value: [$productCategoriesString]");

        //TODO: Handle escaping (\\ + separator)

        $storeRootPaths = $this->getStoreRootPaths($product);

        if (count($storeRootPaths['__error__'])) {
            throw new InputException(__('Cannot find site root with names [' . implode(',', $storeRootPaths['__error__']) . ']'));
        }

        // Unset error container if empty
        unset($storeRootPaths['__error__']);

        // Store category ids
        $categoryIds  = [];

        // Categories may have changed, use escaping
        if ($categoryStringDelimeter = $this->getCategoryStringDelimeter()) {
            if ($explodedProductCategoriesStrings = explode($categoryStringDelimeter, $product[self::KEY])) {
                foreach ($explodedProductCategoriesStrings as $explodedProductCategoriesString) {
                    /** @var int[] $categoryDefinition */
                    $categoryDefinition = $this->getCategoryIdsFromDefinition($explodedProductCategoriesString, $storeRootPaths);

                    // Grab last category
                    if ($this->assignProductsToLastCategoryOnly()) {
                        $categoryDefinition = [$categoryDefinition[count($categoryDefinition) - 1]];
                    }

                    $categoryIds = array_unique(array_merge($categoryIds, $categoryDefinition));
                }

                // Assign to category roots
                if (!$this->assignProductsToLastCategoryOnly()) {
                    foreach ($storeRootPaths as $base => $ra) {
                        // For each part of category list to include upwards, match up to local root
                        foreach ($explodedProductCategoriesStrings as $categoryItem) {
                            if (str_starts_with($categoryItem, $base)) {
                                $rootPath = $ra['rootarr'];
                                array_shift($rootPath);
                                $categoryIds = array_merge($categoryIds, $rootPath);
                            }
                        }
                    }
                }
            }
        }

        $categoryIds = array_unique($categoryIds);
        $product['category_ids'] = implode(',', $categoryIds);

        $this->assignCategories($productId, $product);

        $this->log('| -- End Product Category Processor --' . PHP_EOL);
    }

    /**
     * Lazyload category setting data
     *
     * @return array
     */
    private function getCategoryData()
    {
        if ($this->categoryInfos === null) {
            $this->initializeCategoryInfo();
        }

        return $this->categoryInfos;
    }

    /**
     * Lookup column name for product id
     *
     * @return string
     */
    private function getProductIdColumn()
    {
        if ($this->productIdColumn === null) {
            $this->productIdColumn = $this->magentoEnvironment->getProductIdColumn();
        }

        return $this->productIdColumn;
    }

    /**
     * Initialize category settings
     *
     * @return void
     */
    private function initializeCategoryInfo()
    {
        $this->categoryInfos = [
            'varchar' => [
                'name'            => [],
                'url_key'         => [],
                'url_path'        => []
            ],
            'int' => [
                'is_active'       => [],
                'is_anchor'       => [],
                'include_in_menu' => []
            ]
        ];

        foreach ($this->categoryInfos as $categoryType => $attributeList) {
            foreach (array_keys($attributeList) as $categoryAttribute) {
                // Make sure it's a string
                $categoryAttributeCode = (string)$categoryAttribute;

                $this->categoryInfos[$categoryType][$categoryAttributeCode] = $this->getCategoryAttributeInfos($categoryAttributeCode);
            }
        }
    }

    /**
     * Initialize category data
     *
     * @return void
     */
    private function initializeCategories()
    {
        $categoryEntity        = $this->db->getTableName('catalog_category_entity');
        $categoryEntityVarchar = $this->db->getTableName('catalog_category_entity_varchar');
        $storeGroup            = $this->db->getTableName('store_group');
        $store                 = $this->db->getTableName('store');
        $eavAttribute          = $this->db->getTableName('eav_attribute');

        $query = "SELECT
                    `$store`.`store_id`,
                    `$storeGroup`.`website_id`,
                    `$categoryEntity`.`path`,
                    `$categoryEntityVarchar`.`value` as 'name'
                  FROM
                    `$store`
                  INNER JOIN
                    `$storeGroup`
                  ON
                    `$storeGroup`.`group_id` = `$store`.`group_id`
                  INNER JOIN
                    `$categoryEntity`
                  ON
                    `$categoryEntity`.`{$this->getProductIdColumn()}` = `$storeGroup`.`root_category_id`
                  INNER JOIN
                    `$eavAttribute`
                  ON
                    `$eavAttribute`.`attribute_code` = 'name'
                  INNER JOIN
                    `$categoryEntityVarchar`
                  ON
                    `$categoryEntityVarchar`.`attribute_id` = `$eavAttribute`.`attribute_id`
                    AND `$categoryEntityVarchar`.`{$this->getProductIdColumn()}` = `$categoryEntity`.`{$this->getProductIdColumn()}`";

        $result = $this->db->select($query);

        foreach ($result as $row) {
            $rootCategoryInfo = [
                'path'    => $row['path'],
                'name'    => $row['name'],
                'rootarr' => explode('/', $row['path'] ?? '')
            ];

            $storeId = $row['store_id'];
            $websiteId = $row['website_id'];

            $this->categoryRootStores[$storeId]       = $rootCategoryInfo;
            $this->categoryRootWebsites[$websiteId][] = $storeId;
        }
    }

    /**
     * Get attribute details for category entity
     *
     * @param string $attributeCode
     *
     * @return object
     */
    private function getCategoryAttributeInfos(string $attributeCode)
    {
        $table = $this->db->getTableName('eav_attribute');
        $query = "SELECT * FROM `$table` WHERE `entity_type_id` = 3 AND `attribute_code` = ?";
        $binds = [$attributeCode];

        $result = $this->db->select($query, $binds);

        return $result[0];
    }

    /**
     * Gets existing category based on:
     * - name's attribute_id
     * - name value
     * - parent id
     *
     * @param array $parentPath         A list of parent categories from root to immediate parent
     * @param array $categoryAttributes Category attributes from getCatAttributeInfos()
     *
     * @return mixed|null               ID of existing category
     */
    private function getExistingCategory(array $parentPath, array $categoryAttributes)
    {
        $categoryEntity        = $this->db->getTableName('catalog_category_entity');
        $categoryEntityVarchar = $this->db->getTableName('catalog_category_entity_varchar');

        $parentId = array_pop($parentPath);
        $categoryData = $this->getCategoryData();

        $query = "SELECT
                    `$categoryEntity`.`{$this->getProductIdColumn()}`
                  FROM
                    `$categoryEntity`
                  JOIN
                    $categoryEntityVarchar
                  ON
                    `$categoryEntityVarchar`.`{$this->getProductIdColumn()}` = `$categoryEntity`.`{$this->getProductIdColumn()}` AND
                    `$categoryEntityVarchar`.`attribute_id` = ? AND
                    `$categoryEntityVarchar`.`value` = ?
                  WHERE
                    `$categoryEntity`.`parent_id` = ?";

        $binds = [
            $categoryData['varchar']['name']['attribute_id'],
            $categoryAttributes['name'],
            $parentId
        ];

        return $this->db->selectOne($query, $binds, 'entity_id');
    }

    /**
     * Get Category Id
     *
     * First check existing, then attempt to find it based on parents and siblings
     *
     * @param string[] $parentPaths        A list of parent categories from root to immediate parent
     * @param array    $categoryAttributes Category attributes from getCatAttributeInfos()
     *
     * @return int                         ID of existing category
     */
    private function getCategoryId(array $parentPaths, array $categoryAttributes)
    {
        $this->log('getCategoryId()');

        // Clean category name
        $categoryAttributes['name'] = str_replace(
            $this->escapedTreeSeparator,
            $this->getCategoryTreeSeparator(),
            $categoryAttributes['name']
        );

        // Check for existing category, and return its ID if found
        // If we return then WE DON'T UPDATE CATEGORY ATTRIBUTES
        $categoryId = $this->getExistingCategory($parentPaths, $categoryAttributes);
        if (is_numeric($categoryId)) {
            $this->log('getCategoryId() - Found existing categoryId', ['categoryId' => $categoryId]);
            return (int)$categoryId;
        }

        $this->log('getCategoryId() - Existing ID not found.');

        // Otherwise, get new category values from parent & siblings
        $categoryEntity = $this->db->getTableName('catalog_category_entity');
        $path = implode('/', $parentPaths);

        if ($parentId = array_pop($parentPaths)) {
            if (is_numeric($parentId)) {

                // Cast to int
                $parentId = (int)$parentId;

                // Get child info using parent data
                $query = "SELECT
                    `c1`.`attribute_set_id`,
                    `c1`.`level` + 1 as `level`,
                    COALESCE(MAX(`c2`.`position`), 0) + 1 as `position`
                  FROM
                    `$categoryEntity` as c1
                  LEFT JOIN
                    `$categoryEntity` as c2 ON `c2`.`parent_id` = `c1`.`{$this->getProductIdColumn()}`
                  WHERE
                    `c1`.`{$this->getProductIdColumn()}` = ?
                  GROUP BY
                    `c2`.`parent_id`";
                $binds = [$parentId];

                $info = $this->db->select($query, $binds);
                $info = $info[0];

                // Insert new record into "catalog_category_entity"
                $categoryId = $this->addCategoryRecord(
                    (int)$info['attribute_set_id'],
                    $parentId,
                    (int)$info['position'],
                    (int)$info['level'],
                    '',
                    0
                );

                if ($categoryId !== null) {
                    // Set category path with inserted category id
                    $this->updateCategoryRecordPath($categoryId, $path);

                    // Set category attributes
                    $this->log('getCategoryId() - Setting Category Attributes...');

                    // Iterate over attribute types
                    foreach ($this->getCategoryData() as $attributeEntityType => $attributeInfo) {
                        // Iterate over attributes
                        foreach ($attributeInfo as $attributeCode => $attributeData) {
                            if (isset($attributeData['attribute_id'])) {
                                $attributeId = $attributeData['attribute_id'];
                                if (is_numeric($attributeId)) {
                                    $this->upsertCategoryAttributeValue((int)$attributeId, 0, $categoryId, $categoryAttributes[$attributeCode], $attributeEntityType);
                                }
                            }
                        }
                    }
                }
            }
        }

        return $categoryId;
    }

    /**
     * Extract attributes like is_anchor and include_in_menu
     *
     * @param string &$categoryDefinitionString A category string. Will be cleaned from options.
     *
     * @return array                            A list of category info
     */
    private function extractCategoryAttributes(string &$categoryDefinitionString)
    {
        $this->log('extractCategoryAttributes()', ['categoryDefinitionString' => $categoryDefinitionString]);

        $categoryNames         = [];
        $storeCategoryNames    = [];
        $categoryAttributeList = [];

        // Explode string using TreeSeparator
        $categoryDefinitions = explode($this->getCategoryTreeSeparator(), $categoryDefinitionString ?? '');
        foreach ($categoryDefinitions as $categoryDefinition) {
            $parts             = explode('::', $categoryDefinition ?? '');
            $categoryName      = trim($parts[0] ?? '');
            $storeCategoryName = $categoryName;
            $lastPart          = array_pop($parts);

            // Check for storename::[defaultname] syntax
            if ($categoryName !== $lastPart && str_starts_with($lastPart, '[')) {
                $categoryName = trim($lastPart, '[]');
            } else {
                // If not translation add $last back to array
                $parts[] = $lastPart;
            }

            $partsCount           = count($parts);
            $categoryNames[]      = $categoryName;
            $storeCategoryNames[] = $storeCategoryName;

            $attributes = [
                'name'            => $categoryName,
                'is_active'       => $partsCount > 1 ? $parts[1] : 1,
                'is_anchor'       => $partsCount > 2 ? $parts[2] : 1,
                'include_in_menu' => $partsCount > 3 ? $parts[3] : 1,
                'url_key'         => $this->arrayStringUtils->slugify($categoryName),
                'url_path'        => $this->arrayStringUtils->slugify(implode('/', $categoryNames), true),
            ];

            if ($categoryName !== $storeCategoryName) {
                $attributes['translated_name']     = $storeCategoryName;
                $attributes['translated_url_key']  = $this->arrayStringUtils->slugify($storeCategoryName);
                $attributes['translated_url_path'] = $this->arrayStringUtils->slugify(implode('/', $storeCategoryNames), true);
            }

            $categoryAttributeList[] = $attributes;
        }

        $categoryDefinitionString = implode($this->getCategoryTreeSeparator(), $categoryNames);

        return $categoryAttributeList;
    }

    /**
     * @param string $categoryString
     * @param array  $storeRootPaths
     *
     * @return int[]
     */
    private function getCategoryIdsFromDefinition(string $categoryString, array $storeRootPaths)
    {
        $storeRootPath = self::DEFAULT_ROOT_PATH_KEY;

        foreach (array_keys($storeRootPaths) as $storeRootPathKey) {
            if ($categoryString == $storeRootPathKey) {
                $this->log("getCategoryIdsFromDefinition() - Found storeRootPath match for [$categoryString]!");

                return [$storeRootPaths[$storeRootPathKey]['rootarr'][1]];
            }

            // Check which root we have
            if (str_starts_with($categoryString, $storeRootPathKey)) {
                $storeRootPath = $storeRootPathKey;
                break;
            }
        }

        $this->log("getCategoryIdsFromDefinition() - Using StoreRootPath [$storeRootPath]");

        // Remove explicit root ([Default Root Path]| vs [Default Root Path] |)
        $explicitRoot = $storeRootPath . $this->getCategoryTreeSeparator();
        $categoryString = str_replace($explicitRoot, '', $categoryString);

        // Explode into individual categories in tree
        $explodedCategoryStringParts = explode($this->getCategoryTreeSeparator(), $categoryString);

        // Cleaning parts: trim, remove empty
        $pCategoryParts = [];
        foreach ($explodedCategoryStringParts as $explodedCategoryStringPart) {
            if ($trimmedExplodedCategoryPartsString = trim($explodedCategoryStringPart)) {
                $pCategoryParts[] = $trimmedExplodedCategoryPartsString;
            }
        }

        /** @var string[] $categoryParts */
        $categoryParts     = [];
        $categoryPositions = [];

        // Build a position table to restore after category ids will be created.
        // Basically removing position from category string, but keeping other options.
        foreach ($pCategoryParts as $pCategoryPart) {
            $a = explode('::', $pCategoryPart ?? '');

            // Separate numeric options into array (is_active, is_anchored, include_in_menu, position)
            $options = [];

            if (count($a) > 1) {
                $options = array_filter($a, 'is_numeric');

                // The first three options are always used together.
                // Therefore position must be at the end as '4' or the first and only one
                if (count($options) === 4 || count($options) === 1) {
                    $categoryPositions[] = array_pop($options);
                } else {
                    $categoryPositions[] = '0';
                }
            } else {
                $categoryPositions[] = '0';
            }

            $translationOption = array_values(array_filter($a, function ($option) {
                return str_starts_with($option, '[');
            }));

            $translationOptionPart = count($translationOption)
                ? '::' . $translationOption[0]
                : '';

            $optionsPart  = count($options)
                ? '::' . implode('::', $options)
                : '';

            $categoryParts[] = $a[0] . $optionsPart . $translationOptionPart;
        }

        // Build a position-free Category string
        $implodedCategoryParts = implode($this->getCategoryTreeSeparator(), $categoryParts);

        // Category Ids!
        $categoryIds = [];

        // Path as array.
        // Base path is always "/" - separated
        $baseArray = explode('/', (string)$storeRootPaths[$storeRootPath]['path']);

        // Add store tree root to category path
        $currentPath = array_merge($baseArray, $categoryIds);

        // Get Categories attributes
        $categoryAttributes = $this->extractCategoryAttributes($implodedCategoryParts);

        $categoryPartsCount = count($categoryParts);

        // Iterate on missing levels
        for ($i = 0; $i < $categoryPartsCount; $i++) {
            if ($categoryParts[$i] === '') {
                continue;
            }

            // Retrieve CategoryId (by creating it if needed from category attributes)
            $categoryId = $this->getCategoryId($currentPath, $categoryAttributes[$i]);

            // Add newly created level to item category ids
            $categoryIds[] = $categoryId;

            // Add newly created level to current paths
            $currentPath[] = $categoryId;

            // Cache newly created levels...
            // TODO: Cache newly created levels
        }

        $categoryPartsCount = count($categoryParts);
        $this->log("getCategoryIdsFromDefinition() - categoryPartsCount: $categoryPartsCount");

        $this->log('getCategoryIdsFromDefinition() - Current CategoryIds:', $categoryIds);
        $this->log('getCategoryIdsFromDefinition() - Current CategoryPositions:', $categoryPositions);

        // Add position handling
        for ($i = 0; $i < $categoryPartsCount; $i++) {
            $categoryIds[$i] .= '::' . $categoryPositions[$i];
        }

        return $categoryIds;
    }

    /**
     * @param array $product
     *
     * @return array
     * @throws \Exception
     */
    private function getStoreRootPaths(array &$product)
    {
        $this->log('getStoreRootPaths()', ['product' => $product]);

        // Create array and container for errors
        $rootPaths              = [];
        $rootPaths['__error__'] = [];

        /** @var int[] $storeIds */
        $storeIds = $this->storeWebsiteHelper->getStoreIdsForProduct($product, StoreWebsite::SCOPE_WEBSITE);
        $this->log('getStoreRootPaths()', ['storeIds' => $storeIds]);

        // Remove 'admin' from StoreIds (no category root in it)
        if ($storeIds[0] === 0) {
            array_shift($storeIds);
        }

        // If only 'admin' store is set, use website store roots
        if (count($storeIds) === 0) {
            $websiteStoreIds = [];

            $websiteIds = $this->storeWebsiteHelper->getWebsiteIdsForProduct($product);
            foreach ($websiteIds as $websiteId) {
                $websiteStoreIds[] = $this->categoryRootWebsites[$websiteId];
            }

            // Add website-level StoreIds to array of store-level StoreIds
            $storeIds = array_merge($storeIds, $websiteStoreIds);
        }

        // Check for explicit root assignment (wrapping root in brackets)
        if (preg_match_all('|(?<!::)\[(.*?)\]|', $product['categories'], $matches)) {
            // $matches[1] holds the string without brackets
            $firstMatchCount = count($matches[1]);

            // For each found explicit root:
            for ($i = 0; $i < $firstMatchCount; $i++) {
                // Test store matching
                foreach ($storeIds as $storeId) {
                    $storeRootPath = $this->categoryRootStores[$storeId];
                    $storeRootName = $matches[1][$i];
                    $foundMatch    = trim($storeRootName) === (string)$storeRootPath['name'];

                    // Found a match!
                    if ($foundMatch) {
                        // Set a specific store key
                        $key = "%RP:$storeId%";

                        // Store root path definitions
                        $rootPaths[$key] = [
                            'path'    => $storeRootPath['path'],
                            'rootarr' => $storeRootPath['rootarr']
                        ];

                        // Replace root name with store root key
                        $product[self::KEY] = str_replace($matches[0][$i], $key, $product[self::KEY]);
                        break;
                    }
                }
            }
        }

        if (preg_match_all('|(?<!::)\[(.*?)\]|', $product[self::KEY], $matches)) {
            $firstMatchCount = count($matches[1]);
            for ($i = 0; $i < $firstMatchCount; $i++) {
                $rootPaths['__error__'] = $matches[1];
            }
        }

        $storeIds = array_keys($this->categoryRootStores);
        $storeRootPath = $this->categoryRootStores[$storeIds[0]];
        $rootPaths[self::DEFAULT_ROOT_PATH_KEY] = [
            'path'    => $storeRootPath['path'],
            'rootarr' => $storeRootPath['rootarr']
        ];

        return $rootPaths;
    }

    /**
     * Assign product to categories
     *
     * @param int   $productId
     * @param array $product
     *
     * @return void
     * @throws \Exception
     */
    private function assignCategories(int $productId, array $product)
    {
        $this->log('assignCategories()');

        // Handle any resetting of categories
        if ($this->shouldResetCategories()) {
            $this->resetCategories($productId);
        }

        // Build category data from products `category_ids` column
        if ($categoryData = $this->buildCategoryData($product)) {
            /** @var int[] $categoryIds */
            $categoryIds = $this->getCategoryIds($categoryData);

            // Now get the diff
            $diffs     = array_diff(array_keys($categoryData), $categoryIds);
            $diffCount = count($diffs);

            // Remove invalid category entries
            if ($diffCount > 0) {
                foreach ($diffs as $diff) {
                    unset($categoryData[$diff]);
                }
            }

            // Now we have verified ids.  Create the `category_catalog_product` records.
            foreach ($categoryData as $categoryId => $categoryPosition) {
                if (is_numeric($categoryId)) {
                    if (is_numeric($categoryPosition)) {
                        // Create new category assignment for products, if multi-store with repeated ids, ignore duplicates
                        $this->addCategoryProductRecord((int)$categoryId, $productId, (int)$categoryPosition);
                    } elseif ($categoryPosition === null) {
                        // If $categoryPosition is not numeric, call the function without that parameter
                        $this->addCategoryProductRecord((int)$categoryId, $productId);
                    }

                    // Add category rewrites
                    $this->rewriteHelper->upsertCategoryRewrite((int)$categoryId);
                }
            }
        }
    }

    /**
     * Reset category / product associations in 'catalog_category_product'
     *
     * @param int $productId
     *
     * @return void
     */
    private function resetCategories(int $productId)
    {
        $this->log('resetCategories()', ['productId' => $productId]);

        $categoryTable        = $this->db->getTableName('catalog_category_entity');
        $categoryProductTable = $this->db->getTableName('catalog_category_product');

        // Handle assignment reset
        $query = "DELETE `$categoryProductTable`.*
                  FROM `$categoryProductTable`
                  JOIN `$categoryTable` ON `$categoryTable`.`entity_id` = `$categoryProductTable`.`category_id`
                  WHERE `product_id` = ?";
        $binds = [$productId];

        $this->db->delete($query, $binds);
    }

    /**
     * Build category data for product
     *
     * @param array $product
     *
     * @return array|null
     */
    private function buildCategoryData(array $product)
    {
        $this->log('buildCategoryData()');

        $productCategoryIds = (string)$product['category_ids'];
        $this->log('buildCategoryData()', ['category_ids' => $productCategoryIds]);

        if ($productCategoryIds === '') {
            return null;
        }

        $categoryData = [];

        /** @var string[] $categoryIds */
        $categoryIds = $this->arrayStringUtils->commaSeparatedListToTrimmedArray($productCategoryIds);
        $this->log('buildCategoryData()', ['categoryIdsCount' => count($categoryIds)]);

        // Find positive category assignments
        foreach ($categoryIds as $categoryDefinition) {
            if ($categoryDefinition) {
                if ($a = explode('::', $categoryDefinition)) {
                    if (isset($a[0])) {
                        $categoryId = $a[0];
                        if (is_numeric($categoryId)) {
                            // Cast to int
                            $categoryId = (int)$categoryId;

                            /** @var int $categoryId */
                            if (!in_array($categoryId, [1, 2])) {
                                if (count($a) > 1) {
                                    if (is_numeric($a[1])) {
                                        $categoryPosition = (int)$a[1];
                                    } else {
                                        $categoryPosition = 0;
                                    }
                                } else {
                                    $categoryPosition = 0;
                                }

                                $this->log('buildCategoryData()', [
                                    'categoryId'       => $categoryId,
                                    'categoryPosition' => $categoryPosition
                                ]);

                                $categoryData[$categoryId] = $categoryPosition;
                            }
                        }
                    }
                }
            }
        }

        return $categoryData;
    }

    /**
     * Lookup categoryIds
     *
     * @param array $categoryData
     *
     * @return int[];
     */
    private function getCategoryIds(array $categoryData)
    {
        $categoryIds = [];

        $keys   = array_keys($categoryData);
        $values = $this->arrayStringUtils->arrayToCommaSeparatedValueString($keys);

        $table = $this->db->getTableName('catalog_category_entity');
        $query = "SELECT `{$this->getProductIdColumn()}` FROM `$table` WHERE `{$this->getProductIdColumn()}` IN ($values)";
        $binds = $keys;

        $results = $this->db->select($query, $binds);
        foreach ($results as $result) {
            if (isset($result[$this->getProductIdColumn()])) {
                $categoryId = $result[$this->getProductIdColumn()];
                if (is_numeric($categoryId)) {
                    $categoryIds[] = (int)$categoryId;
                }
            }
        }

        return $categoryIds;
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ///
    /// DB FUNCTIONS
    ///
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

    /**
     * Add record to `catalog_category_entity` table
     *
     * @param int    $attributeSetId
     * @param int    $parentId
     * @param int    $position
     * @param int    $level
     * @param string $path
     * @param int    $childrenCount
     *
     * @return int|null
     */
    private function addCategoryRecord(
        int $attributeSetId,
        int $parentId,
        int $position,
        int $level,
        string $path = '',
        int $childrenCount = 0
    ) {
        $this->log('addCategoryRecord()', [
            'attributeSetId' => $attributeSetId,
            'parentId'       => $parentId,
            'position'       => $position,
            'level'          => $level,
            'path'           => $path,
            'childrenCount'  => $childrenCount
        ]);

        $table = $this->db->getTableName('catalog_category_entity');

        try {
            $this->db->beginTransaction();

            // insert new category
            if ($this->getProductIdColumn() !== 'entity_id') {
                $sequence = $this->addSequenceCategoryRecord();

                $query = "INSERT INTO `$table` (`entity_id`, `attribute_set_id`, `parent_id`, `position`, `level`, `path`, `children_count`) VALUES (?,?,?,?,?,?,?)";
                $binds = [$sequence, $attributeSetId, $parentId, $position, $level, $path, $childrenCount];
            } else {
                $query = "INSERT INTO `$table` (`attribute_set_id`, `parent_id`, `position`, `level`, `path`, `children_count`) VALUES (?,?,?,?,?,?)";
                $binds = [$attributeSetId, $parentId, $position, $level, $path, $childrenCount];
            }

            $result = $this->db->insert($query, $binds);

            $this->db->commit();

            return $result;
        } catch (Exception $e) {
            $this->log("addCategoryRecord() - Rolling back due to EXCEPTION: {$e->getMessage()}");
            $this->db->rollBack();
        }

        return null;
    }

    /**
     * Add record to `sequence_catalog_category` table
     *
     * @return int
     */
    private function addSequenceCategoryRecord()
    {
        $this->log('addSequenceCategoryRecord()');

        $table = $this->db->getTableName('sequence_catalog_category');
        $query = "INSERT INTO `$table` VALUES (null)";

        return $this->db->insert($query);
    }

    /**
     * Set category path with inserted category id
     *
     * @param int    $categoryId
     * @param string $path
     *
     * @return void
     */
    private function updateCategoryRecordPath(int $categoryId, string $path)
    {
        $this->log('updateCategoryRecordPath()', ['categoryId' => $categoryId, 'path' => $path]);

        $table = $this->db->getTableName('catalog_category_entity');
        $query = "UPDATE `$table` SET `path` = ?, `created_at`= NOW(), `updated_at` = NOW() WHERE `{$this->getProductIdColumn()}`=?";
        $binds = ["$path/$categoryId",$categoryId];

        $this->db->update($query, $binds);
    }

    /**
     * Create new category assignment for products, if multi-store with repeated ids, ignore duplicates
     *
     * @param int $categoryId
     * @param int $productId
     * @param int $position
     *
     * @return void
     */
    private function addCategoryProductRecord(int $categoryId, int $productId, int $position = 0)
    {
        $this->log('addCategoryProductRecord()', [
            'categoryId' => $categoryId,
            'productId'  => $productId,
            'position'   => $position
        ]);

        $table = $this->db->getTableName('catalog_category_product');

        $query = "INSERT INTO `$table` (`category_id`, `product_id`, `position`)
                  VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE position=VALUES(`position`)";
        $binds = [$categoryId, $productId, $position];

        $this->db->insert($query, $binds);
    }

    /**
     * Upsert category attribute value
     *
     * @param int    $attributeId
     * @param int    $storeId
     * @param int    $categoryId
     * @param mixed  $value
     * @param string $attributeType
     *
     * @return void
     */
    private function upsertCategoryAttributeValue(
        int $attributeId,
        int $storeId,
        int $categoryId,
        mixed $value,
        string $attributeType
    ) {
        $this->log('upsertCategoryAttributeValue()', [$attributeId, $storeId, $categoryId, $value, $attributeType]);

        $table = $this->db->getTableName('catalog_category_entity_' . $attributeType);
        $query = "INSERT INTO `$table` (`attribute_id`,`store_id`,`{$this->getProductIdColumn()}`,`value`) VALUES (?,?,?,?)
                  ON DUPLICATE KEY UPDATE value=VALUES(`value`)";
        $binds = [$attributeId, $storeId, $categoryId, $value];

        $this->db->insert($query, $binds);
    }

    //////////////////////////////////////////////////
    ///
    /// SETTINGS / CONFIG
    ///
    //////////////////////////////////////////////////

    /**
     * @return bool
     */
    private function assignProductsToLastCategoryOnly()
    {
        return $this->config->getCategoryAssignToLastCategoryOnly();
    }

    /**
     * @return bool
     */
    private function shouldResetCategories()
    {
        return $this->config->getCategoryMode() == self::CATEGORY_MODE_REPLACEMENT;
    }

    /**
     * @return string
     */
    private function getCategoryTreeSeparator()
    {
        return $this->config->getCategoryTreeDelimeter();
    }

    /**
     * @return string
     */
    private function getCategoryStringDelimeter()
    {
        return $this->config->getCategoryDelimeter();
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
        $this->logger->info('Helper/Category - ' . $message, $extra);
    }
}
