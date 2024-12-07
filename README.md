# Magento2 Module RAPID Web Sync
``ecinternet/rapidwebsync - 1.6.2.0``

- [Requirements](#requirements-header)
- [Overview](#overview-header)
- [Installation](#installation-header)
- [Configuration](#configuration-header)
- [Specifications](#specifications-header)
- [Attributes](#attributes-header)
- [Testing](#testing-header)
- [Notes](#notes-header)
- [Version History](#version-history-header)

## Requirements

## Overview
RAPIDWebSync adds IMan sync information to the Magento 2 backend for viewing sync progress and state, and adds needed API methods for syncing data to Magento 2.

## Installation
- Unzip the zip file in `app/code/ECInternet`
- Enable the module by running `php bin/magento module:enable ECInternet_RAPIDWebSync`
- Apply database updates by running `php bin/magento setup:upgrade`
- Recompile code by running `php bin/magento setup:di:compile`
- Flush the cache by running `php bin/magento cache:flush`

### Breaking Change
- Due to a 2.4.3 security update, built-in rate limiting was added to Magento APIs to prevent denial-of-service attacks.  This may cause some features of the Magento2 connector to not function properly.

  For more information, and instructions on how to patch, please see the following page:

  https://experienceleague.adobe.com/en/docs/commerce-knowledge-base/kb/troubleshooting/known-issues-patches-attached/web-api-resources-limit

- Due to a 2.4.4 security update, Integration Tokens are disabled by default.  To re-enable, run the following command:

  ```bin/magento config:set oauth/consumer/enable_integration_as_bearer 1```

## Configuration

## Specifications
- Admin sync log

## Attributes

## Testing
### Integration Tests
- Navigate to `public_html/dev/tests/integration`
- Run `../../../vendor/bin/phpunit --testsuite "ECInternet Integration Tests"`

### Notes
- Test cleanup setting is `TESTS_CLEANUP` located in `public_html/dev/tests/integration/phpunit.xml`
- To force re-generation of cache and other files, remove the directory `dev/tests/integration/tmp/sandbox-*`

## Notes
- `store` - Comma-separated list of Magento "Store View Codes"
- `related_products` - Comma-separated list of product SKUs
- `websites` - Comma-separated list of Magento "Web Site Codes"

### Inventory Notes
- `setStockColumns()`
  - Update `manage_stock`, `use_config_manage_stock`, `min_qty`, `is_in_stock` on `$product`
- `upsertStockItemRecord()`
  - `INSERT IGNORE INTO cataloginventory_stock_item (product_id, stock_id) VALUES (?,?)` 
- `updateStockItemRecord()`
  - Update `cataloginventory_stock_item` record, Use all table fields set on `$product`
- `clearStockStatusRecords()`
  - `DELETE FROM cataloginventory_stock_status`
  
    `WHERE product_id = ?`
- `upsertStockStatusRecord()`
  - `INSERT INTO cataloginventory_stock_status (product_id, website_id, stock_id, qty, stock_status)`
  
    `VALUES (?, ?, ?, ?, ?)`
  
    `ON DUPLICATE KEY UPDATE stock_status=VALUES(stock_status), qty=VALUES(qty)`
- `upsertInventorySourceItem()` to `upsertInventorySourceItemRecord()`
  - `INSERT INTO inventory_source_item (source_code, sku, quantity, status) VALUES (?, ?, ?, ?)`
  
    `ON DUPLICATE KEY UPDATE quantity=VALUES(quantity), status=VALUES(status)`

### Known Issues
- `url_rewrite` records are not cleared correctly when product is removed from category.

## Version History
- 1.9.1.0 - Re-add BulkOperation and API endpoints.
- 1.8.1.0 - Added support for `first_parent_product` field.
- 1.8.1.0 - Added `product_id` to product data export.
- 1.8.0.0 - Added support for `related_products` field.
- 1.7.0.0 - Added `reindexTables` endpoint.
- 1.6.3.0 - Added support for images with periods in first two characters of file name.
- 1.6.2.1 - Fixed issue where incorrect parameter passed to function.
- 1.6.2.0 - Fixed strict_mode check.
- 1.4.0.4 - Explicitly removed `sku` attribute from attribute processing.  This was mainly done to reduce logging.
- 1.4.0.3 - Modified logic to only handle product websites for inserts or updates with `websites` column set.
- 1.4.0.2 - Modified INSERT of `catalog_product_website` to ignore existing record (INSERT IGNORE...).
- 1.4.0.1 - Added config setting for category mode ("Addition" / "Replacement").  Fixed issue with website assignment through `websites` column.
- 1.4.0.0 - Added support for `websites` column.
- 1.3.6.0 - Fixed issue with `status` updates not respecting scope.
- 1.3.5.0 - Added config setting for default "Tax Class ID" value.
- 1.3.2.3 - Fixed lookup for existing products (Enterprise only). 
- 1.3.2.2 - Added check for existing `request_path`/`store_id` combination in `url_rewrite` table. 
- 1.3.2.1 - Fixed issue with updating of `visibility` and `status` fields.  Removed use of `is_integer()`.
- 1.3.2.0 - Added handling for `country_of_manufacture` field.  We check first for country_id and then country_name.
- 1.3.1.0 - Fixed issue with writing of `url_rewrite` record.
- 1.3.0.2 - Fixed issue in AttributeHelper where we weren't getting product_id correctly.
- 1.3.0.1 - Fixed issue with CE versions attempting to write to `sequence_product` table.
- 1.3.0.0 - Added handling for EE.  Fixed issue with clearing and then writing `cataloginventory_stock_status` records.
- 1.2.0.1 - Fixed issue with not writing to `cataloginventory_stock_item` table correctly.
- 1.2.0.0 - Fixed issue with `visibility` and `status` attributes not being set correctly.
- 1.1.5.2 - Added internal Magento lookup for retrieving root directory path.
