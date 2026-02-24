<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    private const CONFIG_PATH_GENERATE_CATALOG_PRODUCT_REWRITES       = 'catalog/seo/generate_category_product_rewrites';

    private const CONFIG_PATH_ENABLED                                 = 'rapid_web_sync/general/enable';

    private const CONFIG_PATH_ENABLE_SPEED_LOGGING                    = 'rapid_web_sync/general/speed_logging';

    private const CONFIG_PATH_DEFAULT_ATTRIBUTE_SET                   = 'rapid_web_sync/defaults/attribute_set_id';

    private const CONFIG_PATH_DEFAULT_TYPE                            = 'rapid_web_sync/defaults/type';

    private const CONFIG_PATH_DEFAULT_STATUS                          = 'rapid_web_sync/defaults/status';

    private const CONFIG_PATH_DEFAULT_VISIBILITY                      = 'rapid_web_sync/defaults/visibility';

    private const CONFIG_PATH_DEFAULT_TAX_CLASS                       = 'rapid_web_sync/defaults/tax_class';

    private const CONFIG_PATH_DEFAULT_NEWS_TO_DATE                    = 'rapid_web_sync/defaults/news_to_date';

    private const CONFIG_PATH_ATTRIBUTES_ALLOW_NEW_VALUES             = 'rapid_web_sync/attributes/allow_new_values';

    private const CONFIG_PATH_ATTRIBUTES_ILLEGAL_NEW_ATTRIBUTE_ACTION = 'rapid_web_sync/attributes/illegal_new_attribute_action';

    private const CONFIG_PATH_PRICING_MODE                            = 'rapid_web_sync/pricing/mode';

    private const CONFIG_PATH_CATEGORIES_MODE                         = 'rapid_web_sync/categories/mode';

    private const CONFIG_PATH_CATEGORIES_LASTONLY                     = 'rapid_web_sync/categories/lastonly';

    private const CONFIG_PATH_CATEGORIES_CATEGORY_DELIMETER           = 'rapid_web_sync/categories/category_delimeter';

    private const CONFIG_PATH_CATEGORIES_CATEGORY_TREE_DELIMETER      = 'rapid_web_sync/categories/category_tree_delimeter';

    private const CONFIG_PATH_CATEGORIES_URLENDING                    = 'rapid_web_sync/categories/urlending';

    private const CONFIG_PATH_IMAGES_SOURCE_FOLDER                    = 'rapid_web_sync/images/source_directory';

    private const CONFIG_PATH_IMAGES_CASE_INSENSITIVE_SEARCH          = 'rapid_web_sync/images/case_insensitive_search';

    private const CONFIG_PATH_IMAGES_MEDIA_GALLERY_DELIMETER          = 'rapid_web_sync/images/media_gallery_delimeter';

    private const CONFIG_PATH_INVENTORY_AUTO_SET_MANAGE_STOCK         = 'rapid_web_sync/inventory/auto_set_manage_stock';

    private const CONFIG_PATH_INVENTORY_AUTO_SET_IS_IN_STOCK          = 'rapid_web_sync/inventory/auto_set_is_in_stock';

    private const CONFIG_PATH_INVENTORY_AUTO_REMOVE_RESERVATIONS      = 'rapid_web_sync/inventory/auto_remove_reservations';

    private const CONFIG_PATH_RELATED_PRODUCTS_MODE                   = 'rapid_web_sync/related_products/mode';

    private const CONFIG_PATH_POST_IMPORT_REINDEX                     = 'rapid_web_sync/post_import/reindex_enable';

    private const CONFIG_PATH_POST_IMPORT_REINDEX_LIST                = 'rapid_web_sync/post_import/reindex_list';

    private const CONFIG_PATH_POST_IMPORT_CLEAR_IMAGE_CACHE           = 'rapid_web_sync/post_import/clear_image_cache';

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * Config constructor.
     *
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Should we generated rewrites?
     *
     * @return bool
     * @since 2.3.3
     */
    public function shouldGenerateCatalogProductRewrites()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_GENERATE_CATALOG_PRODUCT_REWRITES);
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
     * Get the default tax class id
     *
     * @return mixed
     */
    public function getDefaultTaxClassId()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PATH_DEFAULT_TAX_CLASS);
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
        //TODO: Where did this go?
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
     * Should we automatically remove reservations for synced products?
     *
     * @return bool
     */
    public function shouldAutomaticallyRemoveReservations()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_INVENTORY_AUTO_REMOVE_RESERVATIONS);
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
}
