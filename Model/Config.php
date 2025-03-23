<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model;

class Config
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
}