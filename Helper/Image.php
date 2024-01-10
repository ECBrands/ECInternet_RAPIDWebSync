<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\StateException;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use ECInternet\RAPIDWebSync\Logger\Logger;
use Exception;

/**
 * Image Helper
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class Image
{
    private const STORE_VIEW_ADMIN = 'admin';

    private $_baseImageAttributeCodes = ['image', 'small_image', 'thumbnail'];

    private $_productIdColumn;

    /**
     * @var \Magento\Framework\Filesystem\DirectoryList
     */
    private $_directoryList;

    /**
     * @var \Magento\Framework\Filesystem\Driver\File
     */
    private $_fileDriver;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Data
     */
    private $_helper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Attribute
     */
    private $_attributeHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Db
     */
    private $_dbHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\StoreWebsite
     */
    private $_storeWebsiteHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Logger\Logger
     */
    private $_logger;

    /**
     * @var string
     */
    private $_lastProcessedImage = '';

    /**
     * Image constructor.
     *
     * @param \Magento\Framework\Filesystem\DirectoryList  $directoryList
     * @param \Magento\Framework\Filesystem\Driver\File    $fileDriver
     * @param \ECInternet\RAPIDWebSync\Helper\Data         $helper
     * @param \ECInternet\RAPIDWebSync\Helper\Attribute    $attributeHelper
     * @param \ECInternet\RAPIDWebSync\Helper\Db           $dbHelper
     * @param \ECInternet\RAPIDWebSync\Helper\StoreWebsite $storeWebsiteHelper
     * @param \ECInternet\RAPIDWebSync\Logger\Logger       $logger
     */
    public function __construct(
        DirectoryList $directoryList,
        File $fileDriver,
        Data $helper,
        Attribute $attributeHelper,
        Db $dbHelper,
        StoreWebsite $storeWebsiteHelper,
        Logger $logger
    ) {
        $this->_directoryList      = $directoryList;
        $this->_fileDriver         = $fileDriver;
        $this->_helper             = $helper;
        $this->_attributeHelper    = $attributeHelper;
        $this->_dbHelper           = $dbHelper;
        $this->_storeWebsiteHelper = $storeWebsiteHelper;
        $this->_logger             = $logger;

        $this->initializeProductIdColumn();
    }

    /**
     * @param array  $product
     * @param string $sku
     * @param int    $productId
     *
     * @throws Exception
     */
    public function processProduct(array $product, string $sku, int $productId)
    {
        $this->info('| -- Start Product Image Processor --');
        $this->info("| Sku: [$sku]");
        $this->info("| ProductId: [$productId]");

        // Cache product's store_ids
        $storeIds = $this->_storeWebsiteHelper->getStoreIdsForProduct($product);

        // Handle base image attributes labels
        foreach ($this->_baseImageAttributeCodes as $baseImageAttributeCode) {
            $labelColumn = $baseImageAttributeCode . '_label';
            // Test for case when image is not specified, but label is
            if (isset($product[$labelColumn]) && !isset($product[$baseImageAttributeCode])) {
                $this->info("processProduct() - UNEXPECTED: Column '$labelColumn'' was set, but column '$baseImageAttributeCode' was not.");

                // Force label update
                $imageAttributeInfo = $this->getAttributeInfo($baseImageAttributeCode);
                if ($imageAttributeInfo) {
                    $this->updateImageLabel($imageAttributeInfo, $productId, $storeIds, $product[$labelColumn]);
                }
            }
        }

        // Handle base image attributes
        foreach ($this->_baseImageAttributeCodes as $baseImageAttributeCode) {
            $this->info("processProduct() - Check base image attribute '$baseImageAttributeCode'...");

            if (isset($product[$baseImageAttributeCode])) {
                $baseImageAttributeCodeValue = (string)$product[$baseImageAttributeCode];
                $this->info('processProduct()', ['baseImageAttributeCodeValue' => $baseImageAttributeCodeValue]);

                if ($attributeInfo = $this->getAttributeInfo($baseImageAttributeCode)) {
                    if ($baseImageAttributeCodeValue === '__DELETE__') {
                        $storeIds = $this->_storeWebsiteHelper->getStoreIdsForProduct($product);
                        foreach ($storeIds as $storeId) {
                            $this->_attributeHelper->deleteProductAttributeValue($productId, $storeId, $attributeInfo);
                        }
                    } else {
                        $setImageAttribute = $this->handleVarcharAttribute(
                            $productId,
                            $product,
                            $baseImageAttributeCode,
                            $attributeInfo,
                            $baseImageAttributeCodeValue
                        );

                        if (!$setImageAttribute) {
                            $this->warn("processProduct() - Unable to set base image attribute '$baseImageAttributeCode'.");
                        }
                    }
                }
            }
        }

        // Handle 'media_gallery'
        $this->info("processProduct() - Check image attribute 'media_gallery'...");
        if (isset($product['media_gallery'])) {
            $mediaGallery = (string)$product['media_gallery'];
            $this->info('processProduct()', ['mediaGallery' => $mediaGallery]);

            if ($mediaGalleryAttributeInfo = $this->getAttributeInfo('media_gallery')) {
                $setMediaGalleryAttribute = $this->handleVarcharAttribute(
                    $productId,
                    $product,
                    'media_gallery',
                    $mediaGalleryAttributeInfo,
                    $mediaGallery
                );

                if (!$setMediaGalleryAttribute) {
                    $this->warn("processProduct() - Unable to set image attribute 'media_gallery'.");
                }
            }
        }

        $this->info('| -- End Product Image Processor --' . PHP_EOL);
    }

    /**
     * @return void
     */
    private function initializeProductIdColumn()
    {
        $this->_productIdColumn = $this->_helper->getProductIdColumn();
    }

    /**
     * Adds image to product image gallery only if not already exists
     *
     * @param int         $productId
     *                    product id to test image existence in gallery
     * @param int         $storeId
     * @param string      $imageName
     *                    image file name (relative to /products/media in magento dir)
     * @param array       $targetStoreIds
     * @param string|null $imageLabel
     * @param bool        $isExcluded
     * @param int|null    $refId
     *
     * @throws \Exception
     */
    private function addImageToGallery(
        $productId,
        $storeId,
        $imageName,
        $targetStoreIds,
        $imageLabel = '',
        $isExcluded = false,
        $refId = null
    ) {
        $this->info('addImageToGallery()', [
            'productId'      => $productId,
            'storeId'        => $storeId,
            'image'          => $imageName,
            'targetStoreIds' => $targetStoreIds,
            'label'          => $imageLabel,
            'isExcluded'     => $isExcluded,
            'refId'          => $refId
        ]);

        $mediaGalleryAttributeInfo = $this->getAttributeInfo('media_gallery');
        $mediaGalleryAttributeId = $mediaGalleryAttributeInfo['attribute_id'];
        $this->info('addImageToGallery()', ['mediaGalleryAttributeId' => $mediaGalleryAttributeId]);

        $mediaGalleryValueId = $this->getMediaGalleryValueId($mediaGalleryAttributeId, $imageName);
        $this->info('addImageToGallery()', ['mediaGalleryValueId' => $mediaGalleryValueId]);

        if ($mediaGalleryValueId == null) {
            $this->info('addImageToGallery() - mediaGalleryValudId is null.  Add new record...');
            $mediaGalleryValueId = $this->addMediaGalleryRecord($mediaGalleryAttributeId, $imageName);
            $this->info('addImageToGallery() - MediaGallery record created.', ['mediaGalleryValueId' => $mediaGalleryValueId]);
        }

        $maxPosition = $this->getMaxPosition($productId, $storeId);
        $this->info('addImageToGallery()', [
            'productId'   => $productId,
            'storeId'     => $storeId,
            'maxPosition' => $maxPosition
        ]);

        $this->_dbHelper->execute('SET foreign_key_checks = 0');

        foreach ($targetStoreIds as $targetStoreId) {
            $mediaGalleryValueValueId = $this->getMediaGalleryValueValueId($mediaGalleryValueId, $targetStoreId);
            if ($mediaGalleryValueValueId) {
                $this->updateMediaGalleryValueRecord($mediaGalleryValueId, $targetStoreId, $imageLabel);
            } else {
                $this->addMediaGalleryValueRecord($mediaGalleryValueId, $targetStoreId, $productId, $imageLabel, $maxPosition);
            }
        }

        // Insert to `catalog_product_entity_media_gallery_value_to_entity`
        $this->addMediaGalleryValueToEntityRecord($mediaGalleryValueId, $productId);

        $this->_dbHelper->execute('SET foreign_key_checks = 1');
    }

    /**
     * @param int $productId
     * @param int $storeId
     *
     * @return int
     */
    private function getMaxPosition($productId, $storeId)
    {
        $this->info('getMaxPosition()', ['productId' => $productId, 'storeId' => $storeId]);

        $mediaGallery      = $this->_dbHelper->getTableName('catalog_product_entity_media_gallery');
        $mediaGalleryValue = $this->_dbHelper->getTableName('catalog_product_entity_media_gallery_value');

        // Get maximum current position in the product gallery
        $sql = "SELECT MAX(`position`) as `maxpos`
                 FROM `$mediaGalleryValue`
                 JOIN `$mediaGallery` ON `$mediaGallery`.`value_id` = `$mediaGalleryValue`.`value_id` AND `$mediaGalleryValue`.`$this->_productIdColumn` = ?
                 WHERE `$mediaGalleryValue`.`store_id` = ?
                 GROUP BY `$mediaGalleryValue`.`$this->_productIdColumn`";
        $maxPosition = $this->_dbHelper->selectOne($sql, [$productId, $storeId], 'maxpos');

        return ($maxPosition == null) ? 0 : $maxPosition + 1;
    }

    /**
     * Handle attributes which are type 'image'
     *
     * @param int    $productId
     * @param array  $productData
     * @param int    $storeId
     * @param string $attributeCode
     * @param string $value
     *
     * @return void
     * @throws \Exception
     */
    private function handleImageTypeAttribute(int $productId, array &$productData, int $storeId, string $attributeCode, string $value)
    {
        $this->info('handleImageTypeAttribute()', [
            'productId'     => $productId,
            'storeId'       => $storeId,
            'attributeCode' => $attributeCode,
            'value'         => $value
        ]);

        try {
            $imageFile = $this->copyImageFile($value);
            $this->info('handleImageTypeAttribute()', ['imageFile' => $imageFile]);
        } catch (Exception $e) {
            $this->info("handleImageTypeAttribute() - Unable to copyImageFile($value) - {$e->getMessage()}");
            throw $e;
        }

        // If copy was successful, add to gallery
        if ($imageFile !== false) {
            $label = null;
            if (isset($productData[$attributeCode . '_label'])) {
                $label = $productData[$attributeCode . '_label'];
            }

            // Default `store` value to "admin" if not set
            if (!isset($productData['store'])) {
                $productData['store'] = self::STORE_VIEW_ADMIN;
            }

            if ($this->_dbHelper->isSingleStore()) {
                $targetStoreIds = $this->_storeWebsiteHelper->getStoreIds();
            } else {
                $targetStoreIds = $this->_storeWebsiteHelper->getStoreIdsForStoreScope($productData['store']);
            }

            if (count($targetStoreIds)) {
                $this->info('handleImageTypeAttribute()', ['targetStoreIds' => $targetStoreIds]);

                $attributeDescription = $this->getAttributeInfo($attributeCode);
                $this->addImageToGallery($productId, $storeId, $imageFile, $targetStoreIds, $label, 0, $attributeDescription['attribute_id']);
                $this->_attributeHelper->upsertProductAttributeValue($attributeDescription['attribute_id'], $storeId, $productId, $imageFile, $attributeDescription['backend_type']);
            } else {
                $this->warn('handleImageTypeAttribute() - No target StoreIds.');
            }
        }
    }

    /**
     * Handle attributes which are type 'varchar'
     *
     * @param int    $productId
     * @param array  $productData
     * @param string $attributeCode
     * @param array  $attributeDescription
     * @param string $value
     * @param int    $storeId
     *
     * @return bool
     * @throws Exception
     */
    private function handleVarcharAttribute(int $productId, array &$productData, string $attributeCode, array $attributeDescription, string $value, int $storeId = 0)
    {
        $this->info('handleVarcharAttribute()', [
            'productId'     => $productId,
            'storeId'       => $storeId,
            'attributeCode' => $attributeCode,
            'value'         => $value
        ]);

        // Cleanup incoming value
        $value = trim($value);

        // If empty, drop out early
        if ($value === '') {
            return false;
        }

        // Image varchar attributes are broken up between 'gallery' and 'media_image'
        switch ((string)$attributeDescription['frontend_input']) {
            case 'gallery':
                $this->handleGalleryTypeAttribute($productId, $productData, $storeId, $attributeCode, $value);
                break;

            case 'media_image':
                $this->handleImageTypeAttribute($productId, $productData, $storeId, $attributeCode, $value);
                break;

            default:
                throw new StateException(
                    __("Unexpected 'frontend_input': [{$attributeDescription['frontend_input']}].")
                );
        }

        return true;
    }

    /**
     * Handle attributes which are type 'gallery'
     *
     * 'Gallery'-type attributes will be a delimited string of values.
     *
     * @param int    $productId
     * @param array  $productData
     * @param int    $storeId
     * @param string $attributeCode
     * @param string $value
     *
     * @return void
     * @throws \Exception
     */
    private function handleGalleryTypeAttribute(int $productId, array $productData, int $storeId, string $attributeCode, string $value)
    {
        $this->info('handleGalleryTypeAttribute()', [
            'productId'     => $productId,
            'storeId'       => $storeId,
            'attributeCode' => $attributeCode,
            'value'         => $value
        ]);

        /** @var string[] $imageValues */
        $imageValues = explode($this->getImageDelimeter(), $value);
        foreach ($imageValues as $imageFile) {
            if (!empty($imageFile)) {
                // Trim image file in case of spaced split
                $imageFile = trim($imageFile);

                // Handle exclude flag explicitly
                $exclude = $this->getExclude($imageFile, false);

                $imageFileList = explode('::', $imageFile);
                $label         = null;
                if (count($imageFileList) > 1) {
                    $label     = $imageFileList[1];
                    $imageFile = $imageFileList[0];
                }

                // Copy image from source directory to Product Media directory
                $imageFile = $this->copyImageFile($imageFile);
                if ($imageFile !== false) {
                    $targetStoreIds = ($this->_dbHelper->isSingleStore())
                        ? $this->_storeWebsiteHelper->getStoreIds()
                        : $this->_storeWebsiteHelper->getStoreIdsForStoreScope((string)$productData['store']);

                    $this->addImageToGallery($productId, $storeId, $imageFile, $targetStoreIds, $label, $exclude);
                }
            }
        }
    }

    /**
     * @param string $name
     *
     * @return string
     */
    private function getTargetName($name)
    {
        return strtolower(preg_replace('/%[0-9|A-F][0-9|A-F]/', '_', rawurlencode(basename($name))));
    }

    /**
     * Returns image file name relative to media directory (with leading /)
     *
     * @param string $imageFile
     *
     * @return string|false
     * @throws Exception
     */
    private function copyImageFile($imageFile)
    {
        $this->info('copyImageFile()', ['file' => $imageFile]);

        if ($imageFile == '__NULL__' || $imageFile == null) {
            return false;
        }

        $sourceImageFile = $this->findImageFile($imageFile);
        if ($sourceImageFile === null) {
            $this->warn("copyImageFile() - Image file [$imageFile] cannot be found in images path.");

            return false;
        }

        $this->info("copyImageFile() - Image file [$imageFile] found in images path.");

        if (!$this->_fileDriver->isExists($sourceImageFile)) {
            $this->warn("copyImageFile() - Image file [$imageFile] does not exist at [$sourceImageFile].");

            return false;
        }

        $this->info("copyImageFile() - Image file [$imageFile] exists at [$sourceImageFile].");

        $imageFile = $sourceImageFile;
        $this->info('copyImageFile()', ['sourceImage' => $sourceImageFile]);

        $bImgFile = $this->getTargetName($imageFile);
        $this->info('copyImageFile()', ['targetName' => $bImgFile]);

        // Source file exists
        $character1 = $bImgFile[0] === '.' ? '_' : $bImgFile[0];
        $character2 = $bImgFile[1] === '.' ? '_' : $bImgFile[1];

        // Magento image value (relative to media catalog product)
        $imagePath = "/$character1/$character2/$bImgFile";
        $this->info('copyImageFile()', ['imagePath' => $imagePath]);

        // Target directory
        $media = $this->getMediaPath();
        $targetDirectory = "$media/catalog/product/$character1/$character2";
        $this->info('copyImageFile()', ['targetDirectory' => $targetDirectory]);

        // Test for existence
        $targetPath = "$targetDirectory/$bImgFile";
        $this->info('copyImageFile()', ['targetPath' => $targetPath]);

        // Check the last image we processed so we can grab that quickly
        if ($imagePath == $this->_lastProcessedImage) {
            $this->info('copyImageFile() - The current image file was also the last one processed - Using that.', [$imagePath]);

            return $imagePath;
        }

        // Create target directory if it does not exist
        if (!$this->_fileDriver->isExists($targetPath)) {
            $this->info("copyImageFile() - Target path [$targetPath] does not exist.");

            // Try to recursively create target directory
            if (!$this->_fileDriver->isExists($targetDirectory)) {
                $this->info("copyImageFile() - Target directory [$targetDirectory] does not exist.");

                $this->_fileDriver->createDirectory($targetDirectory);
                $this->info("copyImageFile() - Target directory [$targetDirectory] created.");
            }
        }

        // Copy image
        $this->info("copyImageFile() - Attempting to copy imageFile [$imageFile] to targetPath [$targetPath]...");
        $this->_fileDriver->copy($imageFile, $targetPath);
        $this->info("copyImageFile() - Image file [$imageFile] copied to target path [$targetPath].");

        // TODO: Fix to use correct filename.
        // Let's CHMOD this thing to 0664:

        //TODO: Test against changePermissionsRecursively()
        try {
            $this->info("copyImageFile() - Attempting to chmod fullPath [$targetPath]...");
            $this->_fileDriver->changePermissions($targetPath, octdec('755'));
            $this->info("copyImageFile() - Full path [$targetPath] chmod'ed to 755.");
        } catch (Exception $e) {
            $this->warn("copyImageFile() - Failed to CHMOD file [$targetPath].", [$e->getMessage()]);

            return false;
        }

        $this->_lastProcessedImage = $imagePath;

        return $imagePath;
    }

    /**
     * Search for file in SOURCE folder
     *
     * @param string $filename
     *
     * @return string|null
     */
    public function findImageFile($filename)
    {
        $this->info('findImageFile()', ['file' => $filename]);

        // Do not try to find remote image
        if ($this->isRemotePath($filename)) {
            $this->info('findImageFile() - Incoming value is a remote path - Unhandled.');

            return $filename;
        }

        // If existing, return it directly
        $realPath = $this->_fileDriver->getRealPath($filename);
        $this->info('findImageFile()', ['realPath' => $realPath]);
        if ($realPath) {
            $this->info('findImageFile() - Image file already exists on server.');

            return $filename;
        }

        // Aggregate list of directories to scan for image files
        $scanDirectories = explode(':', $this->getImageSearchPath());
        $scanDirectoriesCount = count($scanDirectories);
        $this->info("findImageFile() - Found [$scanDirectoriesCount] directories to scan:", $scanDirectories);

        // Iterate over image source directories.
        // Try to resolve file name based on input value and current source directory
        for ($i = 0; $i < $scanDirectoriesCount; $i++) {
            $scanDirectory = $scanDirectories[$i];
            $this->info("findImageFile() - Scanning directory: [$scanDirectory] for image: [$filename]...");

            // ScanDirectory is relative
            $magentoDirectory = $this->getMagentoDirectory();
            if ($scanDirectory[0] != '/') {
                $scanDirectory = $magentoDirectory . '/' . $scanDirectory;
            }

            $imageFile = $this->getAbsolutePath($filename, $scanDirectory);
            $this->info('findImageFile()', ['absolutePath' => $imageFile]);
            if ($imageFile) {
                $this->info("findImageFile() - Image found at [$imageFile]...");

                return $imageFile;
            }
        }

        return null;
    }

    /**
     * Update record in 'catalog_product_entity_media_gallery_value'
     *
     * @param array  $attributeInfo
     * @param int    $productId
     * @param array  $storeIds
     * @param string $label
     */
    private function updateImageLabel($attributeInfo, $productId, $storeIds, $label)
    {
        $this->info('updateImageLabel()', [
            'attributeId' => $attributeInfo['attribute_id'],
            'productId'   => $productId,
            'storeIds'    => $storeIds,
            'label'       => $label
        ]);

        $mediaGallery         = $this->_dbHelper->getTableName('catalog_product_entity_media_gallery');
        $mediaGalleryValue    = $this->_dbHelper->getTableName('catalog_product_entity_media_gallery_value');
        $productEntityVarchar = $this->_dbHelper->getTableName('catalog_product_entity_varchar');

        $storeIdString = implode(',', $storeIds);

        $query = "UPDATE `$mediaGalleryValue` as `gv`

                  JOIN `$mediaGallery` as `g`
                  ON `g`.`value_id` = `gv`.`value_id` AND `gv`.`entity_id` = ?

                  JOIN `$productEntityVarchar` as `v`
                  ON `v`.`entity_id` = `gv`.`entity_id` AND `v`.`value` = `g`.`value` AND `v`.`attribute_id` = ?

                  SET `label` = ?

                  WHERE `gv`.`store_id` IN ($storeIdString)";
        $binds = [$productId, $attributeInfo['attribute_id'], $label];

        $this->_dbHelper->update($query, $binds);
    }

    /**
     * @param string $val
     * @param bool   $default
     *
     * @return bool
     */
    private function getExclude(&$val, $default = true)
    {
        $exclude = $default;

        // If the first character is a +/-, test it and then strip it
        if ($val[0] == '+' || $val[0] == '-') {
            $exclude = $val[0] == '-';
            $val = substr($val, 1);
        }

        return $exclude;
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    private function isRemotePath($path)
    {
        $parsedUrl = parse_url($path);

        return isset($parsedUrl['host']);
    }

    /**
     * Returns absolute path for a file with a base path.
     *
     * If $resolve is set to true, return associated realpath
     *
     * @param string $fileName
     * @param string $basePath
     *
     * @return bool|string
     */
    private function getAbsolutePath($fileName, $basePath = '')
    {
        $this->info('getAbsolutePath()', [
            'fileName' => $fileName,
            'basePath' => $basePath
        ]);

        // Ensure basePath is set
        if ($basePath == '') {
            $basePath = $this->_fileDriver->getParentDirectory($this->_fileDriver->getParentDirectory(__FILE__));
        }

        // Build image path
        $imagePath = $basePath . '/' . $fileName;
        $this->info('getAbsolutePath()', ['imagePath' => $imagePath]);

        // Clean image path
        /** @var string $cleanedImagePath */
        $cleanedImagePath = str_replace('//', '/', $imagePath);
        $this->info('getAbsolutePath()', ['cleanedImagePath' => $cleanedImagePath]);

        if (!$this->isRemotePath($cleanedImagePath)) {
            $this->info("getAbsolutePath() -  Attempting to call 'realpath()' on [$cleanedImagePath]...");
            $absolutePath = $this->_fileDriver->getRealPath($cleanedImagePath);
        } else {
            $this->info("getAbsolutePath() - Attempting to breakup path [$cleanedImagePath]...");
            $absolutePath = $this->getRealPath($cleanedImagePath);
        }

        $this->info("getAbsolutePath() - Returning path [$absolutePath]");

        return $absolutePath;
    }

    private function getRealPath(string $imagePath)
    {
        $this->info('getRealPath()', ['imagePath' => $imagePath]);

        $pathParts = explode('/', $imagePath);
        $outParts  = [];

        $partsCount = count($pathParts);
        for ($i = 0; $i < $partsCount; $i++) {
            // Cache
            $pathPart = $pathParts[$i];

            if ($pathPart == '..') {
                array_pop($outParts);
            } elseif ($pathPart != '.') {
                $outParts[] = $pathPart;
            }
        }

        return implode('/', $outParts);
    }

    /**
     * Use AttributeHelper to get additional attribute info
     *
     * @param string $attributeCode
     *
     * @return array|null
     * @throws Exception
     */
    private function getAttributeInfo(string $attributeCode)
    {
        return $this->_attributeHelper->getCatalogProductAttributeInfoByCode($attributeCode);
    }

    ////////////////////////////////////////////////////
    ///
    /// CATALOG_PRODUCT_MEDIA_GALLERY
    ///
    ////////////////////////////////////////////////////

    /**
     * Add record to 'catalog_product_entity_media_gallery'
     *
     * @param int    $attributeId
     * @param string $value
     *
     * @return int
     */
    private function addMediaGalleryRecord($attributeId, $value)
    {
        $this->info('addMediaGalleryRecord()', [
            'attributeId' => $attributeId,
            'value'       => $value
        ]);

        $table = $this->_dbHelper->getTableName('catalog_product_entity_media_gallery');
        $query = "INSERT INTO `$table` (`attribute_id`, `value`, `media_type`) VALUES (?,?,?)";
        $binds = [$attributeId, $value, 'image'];

        return $this->_dbHelper->insert($query, $binds);
    }

    /**
     * Retrieve record from 'catalog_product_entity_media_gallery'
     *
     * @param int    $attributeId
     * @param mixed  $value
     *
     * @return int|null
     */
    private function getMediaGalleryValueId($attributeId, $value)
    {
        $this->info('getMediaGalleryValueId()', [
            'attributeId' => $attributeId,
            'value'       => $value
        ]);

        $table = $this->_dbHelper->getTableName('catalog_product_entity_media_gallery');
        $query = "SELECT `value_id` FROM `$table` WHERE `attribute_id`=? AND `value`=? AND `media_type`=?";
        $binds = [$attributeId, $value, 'image'];

        return $this->_dbHelper->selectOne($query, $binds, 'value_id');
    }

    ////////////////////////////////////////////////////
    ///
    /// CATALOG_PRODUCT_ENTITY_MEDIA_GALLERY_VALUE
    ///
    ////////////////////////////////////////////////////

    /**
     * Retrieve record from 'catalog_product_entity_media_gallery_value'
     *
     * @param int $valueId
     * @param int $storeId
     *
     * @return int|null
     */
    private function getMediaGalleryValueValueId($valueId, $storeId)
    {
        $this->info('getMediaGalleryValueValueId()', [
            'valueId' => $valueId,
            'storeId' => $storeId
        ]);

        $table = $this->_dbHelper->getTableName('catalog_product_entity_media_gallery_value');
        $query = "SELECT `value_id` FROM `$table` WHERE `value_id`=? AND `store_id`=?";
        $binds = [$valueId, $storeId];

        return $this->_dbHelper->selectOne($query, $binds, 'value_id');
    }

    /**
     * Add record to 'catalog_product_entity_media_gallery_value'
     *
     * @param int    $valueId
     * @param int    $storeId
     * @param int    $productId
     * @param string $label
     * @param int    $position
     *
     * @return void
     */
    private function addMediaGalleryValueRecord($valueId, $storeId, $productId, $label, $position)
    {
        $this->info('addMediaGalleryValueRecord()', [
            'valueId'   => $valueId,
            'storeId'   => $storeId,
            'productId' => $productId,
            'label'     => $label,
            'position'  => $position
        ]);

        $table = $this->_dbHelper->getTableName('catalog_product_entity_media_gallery_value');
        $query = "INSERT INTO `$table` (`value_id`, `store_id`, `$this->_productIdColumn`, `label`, `position`) VALUES (?,?,?,?,?)";
        $binds = [$valueId, $storeId, $productId, $label, $position];

        $this->_dbHelper->insert($query, $binds);
    }

    /**
     * Update record in 'catalog_product_entity_media_gallery_value'
     *
     * @param int    $valueId
     * @param int    $storeId
     * @param string $label
     *
     * @return void
     */
    private function updateMediaGalleryValueRecord($valueId, $storeId, $label)
    {
        $this->info('updateMediaGalleryValueRecord()', [
            'valueId'  => $valueId,
            'storeId'  => $storeId,
            'label'    => $label
        ]);

        $table = $this->_dbHelper->getTableName('catalog_product_entity_media_gallery_value');
        $query = "UPDATE `$table` SET `label`=? WHERE `value_id`=? AND `store_id`=?";
        $binds = [$label, $valueId, $storeId];

        $this->_dbHelper->update($query, $binds);
    }

    ////////////////////////////////////////////////////
    ///
    /// CATALOG_PRODUCT_ENTITY_MEDIA_GALLERY_VALUE_TO_ENTITY
    ///
    ////////////////////////////////////////////////////

    /**
     * Add record to 'catalog_product_entity_media_gallery_value_to_entity'
     *
     * @param int $valueId
     * @param int $productId
     *
     * @return void
     */
    private function addMediaGalleryValueToEntityRecord($valueId, $productId)
    {
        $this->info('addMediaGalleryValueToEntityRecord()', [
            'valueId'   => $valueId,
            'productId' => $productId
        ]);

        $table = $this->_dbHelper->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $query = "INSERT IGNORE INTO `$table` (`value_id`, `$this->_productIdColumn`) VALUES (?,?)";
        $binds = [$valueId, $productId];

        $this->_dbHelper->insert($query, $binds);
    }

    ////////////////////////////////////////////////////
    ///
    /// SETTINGS
    ///
    ////////////////////////////////////////////////////

    /**
     * @return string
     */
    protected function getImageDelimeter()
    {
        return $this->_helper->getMediaGalleryDelimeter();
    }

    /**
     * Gets a filesystem path of the root directory
     *
     * @return string
     */
    protected function getMagentoDirectory()
    {
        return $this->_directoryList->getRoot();
    }

    /**
     * Get pub/media directory
     *
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function getMediaPath()
    {
        try {
            return $this->_directoryList->getPath('media');
        } catch (FileSystemException $e) {
            $this->info('getMediaPath()', ['EXCEPTION' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get image search path on server
     *
     * @return string
     */
    protected function getImageSearchPath()
    {
        return $this->_helper->getImageSearchPath();
    }

    ////////////////////////////////////////////////////
    ///
    /// LOGGING
    ///
    ////////////////////////////////////////////////////

    /**
     * Adds a log record at the WARNING level
     *
     * @param string $message
     * @param array  $extra
     */
    private function warn($message, $extra = [])
    {
        $this->_logger->warning("ImageHelper - $message", $extra);
    }

    /**
     * Adds a log record at the INFO level
     *
     * @param string $message
     * @param array  $extra
     *
     * @return void
     */
    private function info($message, $extra = [])
    {
        $this->_logger->info("ImageHelper - $message", $extra);
    }
}
