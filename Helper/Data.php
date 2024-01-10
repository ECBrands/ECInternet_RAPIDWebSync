<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ProductMetadataInterface;
use ECInternet\RAPIDWebSync\Logger\Logger;

/**
 * Helper
 */
class Data extends AbstractHelper
{
    const CONFIG_PATH_ENABLED                                 = 'rapid_web_sync/general/enable';

    const CONFIG_PATH_ENABLE_SPEED_LOGGING                    = 'rapid_web_sync/general/speed_logging';

    const CONFIG_PATH_DEFAULT_ATTRIBUTE_SET                   = 'rapid_web_sync/defaults/attribute_set_id';

    const CONFIG_PATH_DEFAULT_TYPE                            = 'rapid_web_sync/defaults/type';

    const CONFIG_PATH_DEFAULT_STATUS                          = 'rapid_web_sync/defaults/status';

    const CONFIG_PATH_DEFAULT_VISIBILITY                      = 'rapid_web_sync/defaults/visibility';

    const CONFIG_PATH_DEFAULT_TAX_CLASS                       = 'rapid_web_sync/defaults/tax_class';

    const CONFIG_PATH_DEFAULT_NEWS_TO_DATE                    = 'rapid_web_sync/defaults/news_to_date';

    const CONFIG_PATH_ATTRIBUTES_ALLOW_NEW_VALUES             = 'rapid_web_sync/attributes/allow_new_values';

    const CONFIG_PATH_ATTRIBUTES_ILLEGAL_NEW_ATTRIBUTE_ACTION = 'rapid_web_sync/attributes/illegal_new_attribute_action';

    const CONFIG_PATH_PRICING_MODE                            = 'rapid_web_sync/pricing/mode';

    const CONFIG_PATH_CATEGORIES_MODE                         = 'rapid_web_sync/categories/mode';

    const CONFIG_PATH_CATEGORIES_LASTONLY                     = 'rapid_web_sync/categories/lastonly';

    const CONFIG_PATH_CATEGORIES_CATEGORY_DELIMETER           = 'rapid_web_sync/categories/category_delimeter';

    const CONFIG_PATH_CATEGORIES_CATEGORY_TREE_DELIMETER      = 'rapid_web_sync/categories/category_tree_delimeter';

    const CONFIG_PATH_CATEGORIES_URLENDING                    = 'rapid_web_sync/categories/urlending';

    const CONFIG_PATH_IMAGES_SOURCE_FOLDER                    = 'rapid_web_sync/images/source_directory';

    const CONFIG_PATH_IMAGES_CASE_INSENSITIVE_SEARCH          = 'rapid_web_sync/images/case_insensitive_search';

    const CONFIG_PATH_IMAGES_MEDIA_GALLERY_DELIMETER          = 'rapid_web_sync/images/media_gallery_delimeter';

    const CONFIG_PATH_INVENTORY_AUTO_SET_MANAGE_STOCK         = 'rapid_web_sync/inventory/auto_set_manage_stock';

    const CONFIG_PATH_INVENTORY_AUTO_SET_IS_IN_STOCK          = 'rapid_web_sync/inventory/auto_set_is_in_stock';

    const CONFIG_PATH_RELATED_PRODUCTS_MODE                   = 'rapid_web_sync/related_products/mode';

    const CONFIG_PATH_POST_IMPORT_REINDEX                     = 'rapid_web_sync/post_import/reindex_enable';

    const CONFIG_PATH_POST_IMPORT_REINDEX_LIST                = 'rapid_web_sync/post_import/reindex_list';

    const CONFIG_PATH_POST_IMPORT_CLEAR_IMAGE_CACHE           = 'rapid_web_sync/post_import/clear_image_cache';

    const CONFIG_PATH_GENERATE_CATALOG_PRODUCT_REWRITES       = 'catalog/seo/generate_category_product_rewrites';

    const COMMUNITY_EDITION_VALUE                             = 'Community';

    /**
     * @var \ECInternet\RAPIDWebSync\Logger\Logger
     */
    protected $_logger;

    /**
     * @var \Magento\Framework\App\ProductMetadataInterface
     */
    private $_productMetadata;

    /**
     * Data constructor.
     *
     * @param \Magento\Framework\App\Helper\Context           $context
     * @param \Magento\Framework\App\ProductMetadataInterface $productMetadata
     * @param \ECInternet\RAPIDWebSync\Logger\Logger          $logger
     */
    public function __construct(
        Context $context,
        ProductMetadataInterface $productMetadata,
        Logger $logger
    ) {
        parent::__construct($context);

        $this->_productMetadata = $productMetadata;
        $this->_logger          = $logger;
    }

    /**
     * Is module enabled?
     *
     * @return bool
     */
    public function isModuleEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_ENABLED);
    }

    /**
     * Is speed logging enabled?
     *
     * @return bool
     */
    public function isSpeedLoggingEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_ENABLE_SPEED_LOGGING);
    }

    /**
     * Get the default AttributeSet ID
     *
     * @return int
     */
    public function getDefaultAttributeSetId()
    {
        return (int)$this->scopeConfig->getValue(self::CONFIG_PATH_DEFAULT_ATTRIBUTE_SET);
    }

    /**
     * Get the default product type
     *
     * @return string
     */
    public function getDefaultType()
    {
        return (string)$this->scopeConfig->getValue(self::CONFIG_PATH_DEFAULT_TYPE);
    }

    /**
     * Get the default product status
     *
     * @return mixed
     */
    public function getDefaultStatus()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PATH_DEFAULT_STATUS);
    }

    /**
     * Get the default product visibility
     *
     * @return mixed
     */
    public function getDefaultVisibility()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PATH_DEFAULT_VISIBILITY);
    }

    /**
     * Get the default product news_to_date days
     *
     * @return mixed
     */
    public function getDefaultNewsToDateDays()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PATH_DEFAULT_NEWS_TO_DATE);
    }

    /**
     * Allow new attribute values to be created on-the-fly?
     *
     * @return bool
     */
    public function allowNewAttributeValues()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_ATTRIBUTES_ALLOW_NEW_VALUES);
    }

    /**
     * @return mixed
     */
    public function getIllegalNewAttributeAction()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PATH_ATTRIBUTES_ILLEGAL_NEW_ATTRIBUTE_ACTION);
    }

    /**
     * Get the pricing import mode
     *
     * @return int
     */
    public function getPricingMode()
    {
        return (int)$this->scopeConfig->getValue(self::CONFIG_PATH_PRICING_MODE);
    }

    /**
     * Get the category import mode
     *
     * @return int
     */
    public function getCategoryMode()
    {
        return (int)$this->scopeConfig->getValue(self::CONFIG_PATH_CATEGORIES_MODE);
    }

    /**
     * Should the product only be added to the last category in the list?
     *
     * @return bool
     */
    public function getCategoryAssignToLastCategoryOnly()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_CATEGORIES_LASTONLY);
    }

    /**
     * Get the category delimeter
     *
     * @return string
     */
    public function getCategoryDelimeter()
    {
        return (string)$this->scopeConfig->getValue(self::CONFIG_PATH_CATEGORIES_CATEGORY_DELIMETER);
    }

    /**
     * Get the category tree delimeter
     *
     * @return string
     */
    public function getCategoryTreeDelimeter()
    {
        return (string)$this->scopeConfig->getValue(self::CONFIG_PATH_CATEGORIES_CATEGORY_TREE_DELIMETER);
    }

    /**
     * Get the url suffix for categories
     *
     * @return string
     */
    public function getCategoryUrlEnding()
    {
        return (string)$this->scopeConfig->getValue(self::CONFIG_PATH_CATEGORIES_URLENDING);
    }

    /**
     * Get image search path on server
     *
     * @return string
     */
    public function getImageSearchPath()
    {
        return (string)$this->scopeConfig->getValue(self::CONFIG_PATH_IMAGES_SOURCE_FOLDER);
    }

    /**
     * Get case-insensitive search flag
     *
     * @return bool
     */
    public function isImageSearchCaseInsensitive()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_IMAGES_CASE_INSENSITIVE_SEARCH);
    }

    /**
     * Get media_gallery delimeter
     *
     * @return string
     */
    public function getMediaGalleryDelimeter()
    {
        return (string)$this->scopeConfig->getValue(self::CONFIG_PATH_IMAGES_MEDIA_GALLERY_DELIMETER);
    }

    public function shouldAutomaticallySetManageStock()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_INVENTORY_AUTO_SET_MANAGE_STOCK);
    }

    public function shouldAutomaticallySetIsInStock()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_INVENTORY_AUTO_SET_IS_IN_STOCK);
    }

    /**
     * Get related products import mode
     *
     * @return int
     */
    public function getRelatedProductsImportMode()
    {
        return (int)$this->scopeConfig->getValue(self::CONFIG_PATH_RELATED_PRODUCTS_MODE);
    }

    /**
     * Should we re-index after import?
     *
     * @return bool
     */
    public function isPostImportReindexEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_POST_IMPORT_REINDEX);
    }

    /**
     * Get list of indexes to ... index
     *
     * @return string
     */
    public function getReindexTableList()
    {
        return (string)$this->scopeConfig->getValue(self::CONFIG_PATH_POST_IMPORT_REINDEX_LIST);
    }

    /**
     * Should we run the image cache clear?
     *
     * @return bool
     */
    public function shouldClearImageCache()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_POST_IMPORT_CLEAR_IMAGE_CACHE);
    }

    /**
     * Should we generated rewrites?
     *
     * @return bool
     * @since 2.3.3
     */
    public function shouldGenerateCatalogProductRewrites()
    {
        if (version_compare($this->getMagentoVersion(), '2.3.3', '>=')) {
            $value = $this->scopeConfig->isSetFlag(self::CONFIG_PATH_GENERATE_CATALOG_PRODUCT_REWRITES);

            if (!empty($value)) {
                return $value;
            }
        }

        return true;
    }

    public function isProductConfigurable(array $product)
    {
        return isset($product['type_id']) && $product['type_id'] === 'configurable';
    }

    /**
     * Log a speed test
     *
     * @param float  $start
     * @param float  $end
     * @param string $function
     */
    public function logSpeedTest(float $start, float $end, string $function)
    {
        if ($this->isSpeedLoggingEnabled()) {
            $elapsedTime = $end - $start;

            $this->log('--- SPEED TEST ---');
            $this->log("| Process [$function]");
            $this->log("| Elapsed time: [$elapsedTime seconds]");
            $this->log('--- SPEED TEST ---' . PHP_EOL);
        }
    }

    //////////////////////////////////////////////////
    ///
    /// STRING / ARRAY FUNCTIONS
    ///
    //////////////////////////////////////////////////

    /**
     * Transforms a 1-d array into a comma-separated list of single-quote(')-wrapped values.
     *
     * @param array $values
     *
     * @return string
     */
    public function arrayToCommaSeparatedValues(array $values)
    {
        $array = [];

        foreach ($values as $value) {
            $array[] = "'$value'";
        }

        return implode(',', $array);
    }

    /**
     * Transform a 2-d array into a comma-separated list of update prepared placeholders.
     * "arr2update"
     *
     * @param array $updateArray
     *
     * @return string
     */
    public function arrayToCommaSeparatedUpdateString(array $updateArray)
    {
        $array = [];

        foreach ($updateArray as $updateKey => $updateValue) {
            $array[] = "$updateKey=?";
        }

        return implode(',', $array);
    }

    /**
     * Transforms a 1-d array into a comma-separated list of unnamed placeholders.
     * "arr2values"
     *
     * @param array $array
     *
     * @return string
     */
    public function arrayToCommaSeparatedValueString($array)
    {
        return substr(str_repeat('?,', count($array)), 0, -1);
    }

    /**
     * Transforms a comma-separated list to a 1-d array of trimmed values.
     * "csl2arr"
     *
     * @param string $list
     * @param string $separator
     *
     * @return string[]
     */
    public function commaSeparatedListToTrimmedArray(string $list, string $separator = ',')
    {
        $array = explode($separator, $list);

        $arrayCount = count($array);
        for ($i = 0; $i < $arrayCount; $i++) {
            $array[$i] = trim($array[$i]);
        }

        return $array;
    }

    /**
     * Filters a key value array over a list of keys.
     *
     * Replaces __NULL__ magic value with true null
     *
     * @param array    $keyValueArray
     * @param string[] $keys
     *
     * @return array
     */
    public function filterKeyValueArray(array $keyValueArray, array $keys)
    {
        $out = [];

        // Iterate over keys.
        // If key exists in our array, and it's not '__NULL__', include it.
        foreach ($keys as $key) {
            if (isset($keyValueArray[$key]) && $keyValueArray[$key] !== '__NULL__') {
                $out[$key] = $keyValueArray[$key];
            } else {
                $out[$key] = null;
            }
        }

        return $out;
    }

    /**
     * Build url slug from string
     *
     * @param string $string
     * @param bool   $allowSlash
     *
     * @return string
     * @noinspection PhpUnnecessaryLocalVariableInspection
     */
    public function slug(string $string, bool $allowSlash = false)
    {
        $regex = $allowSlash ? '[^a-z0-9-/]' : '[^a-z0-9-]';

        $string = strtolower(trim($string));
        $string = preg_replace("|$regex|", '-', $string);
        $string = preg_replace('|-+|', '-', $string);
        $string = preg_replace('|-$|', '', $string);

        return $string;
    }

    /**
     * Get Product edition
     *
     * @return string
     */
    public function getMagentoEdition()
    {
        return $this->_productMetadata->getEdition();
    }

    /**
     * Get Product version
     *
     * @return string
     */
    public function getMagentoVersion()
    {
        return $this->_productMetadata->getVersion();
    }

    /**
     * @return bool
     */
    public function isVersionCommunity()
    {
        return $this->getMagentoEdition() === self::COMMUNITY_EDITION_VALUE;
    }

    /**
     * @return string
     */
    public function getProductIdColumn()
    {
        return $this->isVersionCommunity() ? 'entity_id' : 'row_id';
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
        $this->_logger->info('Helper/Data - ' . $message, $extra);
    }
}
