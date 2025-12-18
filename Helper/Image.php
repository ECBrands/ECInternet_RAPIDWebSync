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
use ECInternet\RAPIDWebSync\Model\Config;
use ECInternet\RAPIDWebSync\Model\Db;
use Exception;

/**
 * Image Helper
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Image
{
    private const STORE_VIEW_ADMIN = 'admin';

    private $baseImageAttributeCodes = ['image', 'small_image', 'thumbnail'];

    private $productIdColumn;

    /**
     * @var \Magento\Framework\Filesystem\DirectoryList
     */
    private $directoryList;

    /**
     * @var \Magento\Framework\Filesystem\Driver\File
     */
    private $fileDriver;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Attribute
     */
    private $attributeHelper;

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
    private $lastProcessedImage = '';

    /**
     * Image constructor.
     *
     * @param \Magento\Framework\Filesystem\DirectoryList  $directoryList
     * @param \Magento\Framework\Filesystem\Driver\File    $fileDriver
     * @param \ECInternet\RAPIDWebSync\Helper\Data         $helper
     * @param \ECInternet\RAPIDWebSync\Helper\Attribute    $attributeHelper
     * @param \ECInternet\RAPIDWebSync\Helper\StoreWebsite $storeWebsiteHelper
     * @param \ECInternet\RAPIDWebSync\Logger\Logger       $logger
     * @param \ECInternet\RAPIDWebSync\Model\Config        $config
     * @param \ECInternet\RAPIDWebSync\Model\Db            $db
     */
    public function __construct(
        DirectoryList $directoryList,
        File $fileDriver,
        Data $helper,
        Attribute $attributeHelper,
        StoreWebsite $storeWebsiteHelper,
        Logger $logger,
        Config $config,
        Db $db
    ) {
        $this->directoryList      = $directoryList;
        $this->fileDriver         = $fileDriver;
        $this->helper             = $helper;
        $this->attributeHelper    = $attributeHelper;
        $this->storeWebsiteHelper = $storeWebsiteHelper;
        $this->logger             = $logger;
        $this->config             = $config;
        $this->db                 = $db;

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
        $this->log('| -- Start Product Image Processor --');
        $this->log("| Sku: [$sku]");
        $this->log("| ProductId: [$productId]");

        // Cache product's store_ids
        $storeIds = $this->storeWebsiteHelper->getStoreIdsForProduct($product);

        // Handle base image attributes labels
        foreach ($this->baseImageAttributeCodes as $baseImageAttributeCode) {
            $labelColumn = $baseImageAttributeCode . '_label';
            // Test for case when image is not specified, but label is
            if (isset($product[$labelColumn]) && !isset($product[$baseImageAttributeCode])) {
                $this->log("processProduct() - UNEXPECTED: Column '$labelColumn'' was set, but column '$baseImageAttributeCode' was not.");

                // Force label update
                if ($imageAttributeInfo = $this->getAttributeInfo($baseImageAttributeCode)) {
                    $this->updateImageLabel($imageAttributeInfo, $productId, $storeIds, (string)$product[$labelColumn]);
                }
            }
        }

        // Handle base image attributes
        foreach ($this->baseImageAttributeCodes as $baseImageAttributeCode) {
            $this->log("processProduct() - Check base image attribute '$baseImageAttributeCode'...");

            if (isset($product[$baseImageAttributeCode])) {
                $baseImageAttributeCodeValue = (string)$product[$baseImageAttributeCode];
                $this->log('processProduct()', ['baseImageAttributeCodeValue' => $baseImageAttributeCodeValue]);

                if ($attributeInfo = $this->getAttributeInfo($baseImageAttributeCode)) {
                    if ($baseImageAttributeCodeValue === '__DELETE__') {
                        $storeIds = $this->storeWebsiteHelper->getStoreIdsForProduct($product);
                        foreach ($storeIds as $storeId) {
                            $this->attributeHelper->deleteProductAttributeValue($productId, $storeId, $attributeInfo);
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
                            $this->log("processProduct() - Unable to set base image attribute '$baseImageAttributeCode'.");
                        }
                    }
                }
            }
        }

        // Handle 'media_gallery'
        $this->log("processProduct() - Check image attribute 'media_gallery'...");
        if (isset($product['media_gallery'])) {
            $mediaGallery = (string)$product['media_gallery'];
            $this->log('processProduct()', ['mediaGallery' => $mediaGallery]);

            if ($mediaGalleryAttributeInfo = $this->getAttributeInfo('media_gallery')) {
                $setMediaGalleryAttribute = $this->handleVarcharAttribute(
                    $productId,
                    $product,
                    'media_gallery',
                    $mediaGalleryAttributeInfo,
                    $mediaGallery
                );

                if (!$setMediaGalleryAttribute) {
                    $this->log("processProduct() - Unable to set image attribute 'media_gallery'.");
                }
            }
        }

        $this->log('| -- End Product Image Processor --' . PHP_EOL);
    }

    /**
     * @return void
     */
    private function initializeProductIdColumn()
    {
        $this->productIdColumn = $this->helper->getProductIdColumn();
    }

    /**
     * Adds image to product image gallery only if not already exists
     *
     * @param int      $productId       ProductId to test image existence in gallery
     * @param int      $storeId
     * @param string   $imageName       Image file name (relative to /products/media in magento dir)
     * @param int[]    $targetStoreIds
     * @param string   $imageLabel
     * @param bool     $isExcluded
     * @param int|null $refId
     *
     * @return void
     *
     * @throws \Exception
     */
    private function addImageToGallery(
        int $productId,
        int $storeId,
        string $imageName,
        array $targetStoreIds,
        string $imageLabel = '',
        bool $isExcluded = false,
        ?int $refId = null
    ) {
        $this->log('addImageToGallery()', [
            'productId'      => $productId,
            'storeId'        => $storeId,
            'image'          => $imageName,
            'targetStoreIds' => $targetStoreIds,
            'label'          => $imageLabel,
            'isExcluded'     => $isExcluded,
            'refId'          => $refId
        ]);

        $mediaGalleryAttributeInfo = $this->getAttributeInfo('media_gallery');
        if ($mediaGalleryAttributeInfo === null) {
            $this->log('addImageToGallery() - Unable to get image attribute info');
            return;
        }
        
        $mediaGalleryAttributeId = $mediaGalleryAttributeInfo['attribute_id'];
        $this->log('addImageToGallery()', ['mediaGalleryAttributeId' => $mediaGalleryAttributeId]);

        if (!is_numeric($mediaGalleryAttributeId)) {
            $this->log('addImageToGallery() - Media gallery attribute id must be numeric');
            return;
        }

        // Cast to int
        $mediaGalleryAttributeId = (int)$mediaGalleryAttributeId;

        // Test for existance of media gallery record
        $mediaGalleryValueId = $this->getMediaGalleryValueId($mediaGalleryAttributeId, $imageName);
        $this->log('addImageToGallery()', ['mediaGalleryValueId' => $mediaGalleryValueId]);

        // Add media gallery record if it doesn't already exist
        if ($mediaGalleryValueId === null) {
            $this->log('addImageToGallery() - mediaGalleryValudId is null.  Adding new record...');
            /** @var int $mediaGalleryValueId */
            $mediaGalleryValueId = $this->addMediaGalleryRecord($mediaGalleryAttributeId, $imageName);
            $this->log('addImageToGallery() - MediaGallery record created.', ['mediaGalleryValueId' => $mediaGalleryValueId]);
        }

        $maxPosition = $this->getMaxPosition($productId, $storeId);
        $this->log('addImageToGallery()', [
            'productId'   => $productId,
            'storeId'     => $storeId,
            'maxPosition' => $maxPosition
        ]);

        $this->db->execute('SET foreign_key_checks = 0');

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

        $this->db->execute('SET foreign_key_checks = 1');
    }

    /**
     * @param int $productId
     * @param int $storeId
     *
     * @return int
     */
    private function getMaxPosition(int $productId, int $storeId)
    {
        $this->log('getMaxPosition()', ['productId' => $productId, 'storeId' => $storeId]);

        $mediaGallery      = $this->db->getTableName('catalog_product_entity_media_gallery');
        $mediaGalleryValue = $this->db->getTableName('catalog_product_entity_media_gallery_value');

        // Get maximum current position in the product gallery
        $sql = "SELECT MAX(`position`) as `maxpos`
                 FROM `$mediaGalleryValue`
                 JOIN `$mediaGallery` ON `$mediaGallery`.`value_id` = `$mediaGalleryValue`.`value_id` AND `$mediaGalleryValue`.`$this->productIdColumn` = ?
                 WHERE `$mediaGalleryValue`.`store_id` = ?
                 GROUP BY `$mediaGalleryValue`.`$this->productIdColumn`";
        $maxPosition = $this->db->selectOne($sql, [$productId, $storeId], 'maxpos');

        return ($maxPosition === null) ? 0 : (int)$maxPosition + 1;
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
    private function handleImageTypeAttribute(
        int $productId,
        array &$productData,
        int $storeId,
        string $attributeCode,
        string $value
    ) {
        $this->log('handleImageTypeAttribute()', [
            'productId'     => $productId,
            'storeId'       => $storeId,
            'attributeCode' => $attributeCode,
            'value'         => $value
        ]);

        try {
            $imageFile = $this->copyImageFile($value);
            $this->log('handleImageTypeAttribute()', ['imageFile' => $imageFile]);
        } catch (Exception $e) {
            $this->log("handleImageTypeAttribute() - Unable to copyImageFile($value) - {$e->getMessage()}");
            throw $e;
        }

        // If copy was successful, add to gallery
        if ($imageFile !== false) {
            $label = $productData[$attributeCode . '_label'] ?? null;

            // Default `store` value to "admin" if not set
            if (!isset($productData['store'])) {
                $productData['store'] = self::STORE_VIEW_ADMIN;
            }

            $targetStoreIds = ($this->db->isSingleStore())
                ? $this->storeWebsiteHelper->getStoreIds()
                : $this->storeWebsiteHelper->getStoreIdsForStoreScope((string)$productData['store']);

            if (count($targetStoreIds)) {
                $this->log('handleImageTypeAttribute()', ['targetStoreIds' => $targetStoreIds]);

                $attributeDescription = $this->getAttributeInfo($attributeCode);
                $this->addImageToGallery($productId, $storeId, $imageFile, $targetStoreIds, $label, false, $attributeDescription['attribute_id']);
                $this->attributeHelper->upsertProductAttributeValue($attributeDescription['attribute_id'], $storeId, $productId, $imageFile, $attributeDescription['backend_type']);
            } else {
                $this->log('handleImageTypeAttribute() - No target StoreIds.');
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
    private function handleVarcharAttribute(
        int $productId,
        array &$productData,
        string $attributeCode,
        array $attributeDescription,
        string $value,
        int $storeId = 0
    ) {
        $this->log('handleVarcharAttribute()', [
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
    private function handleGalleryTypeAttribute(
        int $productId,
        array $productData,
        int $storeId,
        string $attributeCode,
        string $value
    ) {
        $this->log('handleGalleryTypeAttribute()', [
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
                    $targetStoreIds = ($this->db->isSingleStore())
                        ? $this->storeWebsiteHelper->getStoreIds()
                        : $this->storeWebsiteHelper->getStoreIdsForStoreScope((string)$productData['store']);

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
    private function getTargetName(string $name)
    {
        return strtolower(
            preg_replace('/%[0-9|A-F][0-9|A-F]/', '_', rawurlencode(basename($name)))
        );
    }

    /**
     * Returns image file name relative to media directory (with leading /)
     *
     * @param string $imageFile
     *
     * @return string|false
     * @throws Exception
     */
    private function copyImageFile(string $imageFile)
    {
        $this->log('copyImageFile()', ['file' => $imageFile]);

        if ($imageFile === '__NULL__' || $imageFile === '') {
            return false;
        }

        $sourceImageFile = $this->findImageFile($imageFile);
        if ($sourceImageFile === null) {
            $this->log("copyImageFile() - Image file [$imageFile] cannot be found in images path.");
            return false;
        }

        $this->log("copyImageFile() - Image file [$imageFile] found in images path.");

        if (!$this->fileDriver->isExists($sourceImageFile)) {
            $this->log("copyImageFile() - Image file [$imageFile] does not exist at [$sourceImageFile].");
            return false;
        }

        $this->log("copyImageFile() - Image file [$imageFile] exists at [$sourceImageFile].");

        $imageFile = $sourceImageFile;
        $this->log('copyImageFile()', ['sourceImage' => $sourceImageFile]);

        $bImgFile = $this->getTargetName($imageFile);
        $this->log('copyImageFile()', ['targetName' => $bImgFile]);

        // Source file exists
        $character1 = $bImgFile[0] === '.' ? '_' : $bImgFile[0];
        $character2 = $bImgFile[1] === '.' ? '_' : $bImgFile[1];

        // Magento image value (relative to media catalog product)
        $imagePath = "/$character1/$character2/$bImgFile";
        $this->log('copyImageFile()', ['imagePath' => $imagePath]);

        // Target directory
        $media = $this->getMediaPath();
        $targetDirectory = "$media/catalog/product/$character1/$character2";
        $this->log('copyImageFile()', ['targetDirectory' => $targetDirectory]);

        // Test for existence
        $targetPath = "$targetDirectory/$bImgFile";
        $this->log('copyImageFile()', ['targetPath' => $targetPath]);

        // Check the last image we processed so we can grab that quickly
        if ($imagePath === $this->lastProcessedImage) {
            $this->log('copyImageFile() - The current image file was also the last one processed - Using that.', [$imagePath]);
            return $imagePath;
        }

        // Create target directory if it does not exist
        if (!$this->fileDriver->isExists($targetPath)) {
            $this->log("copyImageFile() - Target path [$targetPath] does not exist.");

            // Try to recursively create target directory
            if (!$this->fileDriver->isExists($targetDirectory)) {
                $this->log("copyImageFile() - Target directory [$targetDirectory] does not exist.");

                $this->fileDriver->createDirectory($targetDirectory);
                $this->log("copyImageFile() - Target directory [$targetDirectory] created.");
            }
        }

        // Copy image
        $this->log("copyImageFile() - Attempting to copy imageFile [$imageFile] to targetPath [$targetPath]...");
        $this->fileDriver->copy($imageFile, $targetPath);
        $this->log("copyImageFile() - Image file [$imageFile] copied to target path [$targetPath].");

        // TODO: Fix to use correct filename.
        // Let's CHMOD this thing to 0664:

        //TODO: Test against changePermissionsRecursively()
        try {
            $this->log("copyImageFile() - Attempting to chmod fullPath [$targetPath]...");
            $this->fileDriver->changePermissions($targetPath, octdec('755'));
            $this->log("copyImageFile() - Full path [$targetPath] chmod'ed to 755.");
        } catch (Exception $e) {
            $this->log("copyImageFile() - Failed to CHMOD file [$targetPath].", [$e->getMessage()]);
            return false;
        }

        // Update cache
        $this->lastProcessedImage = $imagePath;

        return $imagePath;
    }

    /**
     * Search for file in SOURCE folder
     *
     * @param string $filename
     *
     * @return string|null
     */
    public function findImageFile(string $filename)
    {
        $this->log('findImageFile()', ['filename' => $filename]);

        // Do not try to find remote image
        if ($this->isRemotePath($filename)) {
            $this->log('findImageFile() - Incoming value is a remote path - Unhandled.');
            return $filename;
        }

        // If existing, return it directly
        $realPath = $this->fileDriver->getRealPath($filename);
        $this->log('findImageFile()', ['realPath' => $realPath]);
        if ($realPath) {
            $this->log('findImageFile() - Image file already exists on server.');
            return $filename;
        }

        // Aggregate list of directories to scan for image files
        $scanDirectories = explode(':', $this->getImageSearchPath());
        $scanDirectoriesCount = count($scanDirectories);
        $this->log("findImageFile() - Found [$scanDirectoriesCount] directories to scan:", $scanDirectories);

        // Iterate over image source directories
        foreach ($scanDirectories as $scanDirectory) {
            $this->log("findImageFile() - Scanning directory: [$scanDirectory] for image: [$filename]...");

            // ScanDirectory is relative
            $magentoDirectory = $this->getMagentoDirectory();
            if ($scanDirectory[0] !== '/') {
                $scanDirectory = $magentoDirectory . '/' . $scanDirectory;
            }

            // Try to resolve file name based on input value and current source directory
            $imageFile = $this->getAbsolutePath($filename, $scanDirectory);
            $this->log('findImageFile()', ['absolutePath' => $imageFile]);

            if ($imageFile) {
                $this->log("findImageFile() - Image found at [$imageFile]...");
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
     * @param int[]  $storeIds
     * @param string $label
     */
    private function updateImageLabel(
        array $attributeInfo,
        int $productId,
        array $storeIds,
        string $label
    ) {
        $this->log('updateImageLabel()', [
            'attributeId' => $attributeInfo['attribute_id'],
            'productId'   => $productId,
            'storeIds'    => $storeIds,
            'label'       => $label
        ]);

        $mediaGallery         = $this->db->getTableName('catalog_product_entity_media_gallery');
        $mediaGalleryValue    = $this->db->getTableName('catalog_product_entity_media_gallery_value');
        $productEntityVarchar = $this->db->getTableName('catalog_product_entity_varchar');

        $storeIdString = implode(',', $storeIds);

        $query = "UPDATE `$mediaGalleryValue` as `gv`

                  JOIN `$mediaGallery` as `g`
                  ON `g`.`value_id` = `gv`.`value_id` AND `gv`.`entity_id` = ?

                  JOIN `$productEntityVarchar` as `v`
                  ON `v`.`entity_id` = `gv`.`entity_id` AND `v`.`value` = `g`.`value` AND `v`.`attribute_id` = ?

                  SET `label` = ?

                  WHERE `gv`.`store_id` IN ($storeIdString)";
        $binds = [$productId, $attributeInfo['attribute_id'], $label];

        $this->db->update($query, $binds);
    }

    /**
     * @param string $val
     * @param bool   $default
     *
     * @return bool
     */
    private function getExclude(string &$val, bool $default = true)
    {
        $exclude = $default;

        // If the first character is a +/-, test it and then strip it
        if ($val[0] === '+' || $val[0] === '-') {
            $exclude = $val[0] === '-';
            $val = substr($val, 1);
        }

        return $exclude;
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    private function isRemotePath(string $path)
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
     * @return false|string
     */
    private function getAbsolutePath(string $fileName, string $basePath = '')
    {
        $this->log('getAbsolutePath()', [
            'fileName' => $fileName,
            'basePath' => $basePath
        ]);

        // Ensure basePath is set
        if ($basePath === '') {
            $basePath = $this->fileDriver->getParentDirectory($this->fileDriver->getParentDirectory(__FILE__));
        }

        // Build image path
        $imagePath = $basePath . '/' . $fileName;
        $this->log('getAbsolutePath()', ['imagePath' => $imagePath]);

        // Clean image path
        /** @var string $cleanedImagePath */
        $cleanedImagePath = str_replace('//', '/', $imagePath);
        $this->log('getAbsolutePath()', ['cleanedImagePath' => $cleanedImagePath]);

        if (!$this->isRemotePath($cleanedImagePath)) {
            $this->log("getAbsolutePath() -  Attempting to call 'realpath()' on [$cleanedImagePath]...");
            $absolutePath = $this->fileDriver->getRealPath($cleanedImagePath);
        } else {
            $this->log("getAbsolutePath() - Attempting to breakup path [$cleanedImagePath]...");
            $absolutePath = $this->getRealPath($cleanedImagePath);
        }

        $this->log("getAbsolutePath() - Returning path [$absolutePath]");

        return $absolutePath;
    }

    private function getRealPath(string $imagePath)
    {
        $this->log('getRealPath()', ['imagePath' => $imagePath]);

        $pathParts = explode('/', $imagePath);
        $outParts  = [];

        foreach ($pathParts as $pathPart) {
            if ($pathPart === '..') {
                array_pop($outParts);
            } elseif ($pathPart !== '.') {
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
        return $this->attributeHelper->getCatalogProductAttributeInfoByCode($attributeCode);
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
    private function addMediaGalleryRecord(int $attributeId, string $value)
    {
        $this->log('addMediaGalleryRecord()', [
            'attributeId' => $attributeId,
            'value'       => $value
        ]);

        $table = $this->db->getTableName('catalog_product_entity_media_gallery');
        $query = "INSERT INTO `$table` (`attribute_id`, `value`, `media_type`) VALUES (?,?,?)";
        $binds = [$attributeId, $value, 'image'];

        return $this->db->insert($query, $binds);
    }

    /**
     * Retrieve record from 'catalog_product_entity_media_gallery'
     *
     * @param int    $attributeId
     * @param string $value
     *
     * @return int|null
     */
    private function getMediaGalleryValueId(int $attributeId, string $value)
    {
        $this->log('getMediaGalleryValueId()', [
            'attributeId' => $attributeId,
            'value'       => $value
        ]);

        $table = $this->db->getTableName('catalog_product_entity_media_gallery');
        $query = "SELECT `value_id` FROM `$table` WHERE `attribute_id`=? AND `value`=? AND `media_type`=?";
        $binds = [$attributeId, $value, 'image'];

        return $this->db->selectOne($query, $binds, 'value_id');
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
    private function getMediaGalleryValueValueId(int $valueId, int $storeId)
    {
        $this->log('getMediaGalleryValueValueId()', [
            'valueId' => $valueId,
            'storeId' => $storeId
        ]);

        $table = $this->db->getTableName('catalog_product_entity_media_gallery_value');
        $query = "SELECT `value_id` FROM `$table` WHERE `value_id`=? AND `store_id`=?";
        $binds = [$valueId, $storeId];

        return $this->db->selectOne($query, $binds, 'value_id');
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
    private function addMediaGalleryValueRecord(
        int $valueId,
        int $storeId,
        int $productId,
        string $label,
        int $position
    ) {
        $this->log('addMediaGalleryValueRecord()', [
            'valueId'   => $valueId,
            'storeId'   => $storeId,
            'productId' => $productId,
            'label'     => $label,
            'position'  => $position
        ]);

        $table = $this->db->getTableName('catalog_product_entity_media_gallery_value');
        $query = "INSERT INTO `$table` (`value_id`, `store_id`, `$this->productIdColumn`, `label`, `position`) VALUES (?,?,?,?,?)";
        $binds = [$valueId, $storeId, $productId, $label, $position];

        $this->db->insert($query, $binds);
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
    private function updateMediaGalleryValueRecord(
        int $valueId,
        int $storeId,
        string $label
    ) {
        $this->log('updateMediaGalleryValueRecord()', [
            'valueId'  => $valueId,
            'storeId'  => $storeId,
            'label'    => $label
        ]);

        $table = $this->db->getTableName('catalog_product_entity_media_gallery_value');
        $query = "UPDATE `$table` SET `label`=? WHERE `value_id`=? AND `store_id`=?";
        $binds = [$label, $valueId, $storeId];

        $this->db->update($query, $binds);
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
    private function addMediaGalleryValueToEntityRecord(int $valueId, int $productId)
    {
        $this->log('addMediaGalleryValueToEntityRecord()', [
            'valueId'   => $valueId,
            'productId' => $productId
        ]);

        $table = $this->db->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $query = "INSERT IGNORE INTO `$table` (`value_id`, `$this->productIdColumn`) VALUES (?,?)";
        $binds = [$valueId, $productId];

        $this->db->insert($query, $binds);
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
        return $this->config->getMediaGalleryDelimeter();
    }

    /**
     * Gets a filesystem path of the root directory
     *
     * @return string
     */
    protected function getMagentoDirectory()
    {
        return $this->directoryList->getRoot();
    }

    /**
     * Get pub/media directory
     *
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function getMediaPath()
    {
        try {
            return $this->directoryList->getPath('media');
        } catch (FileSystemException $e) {
            $this->log('getMediaPath()', ['EXCEPTION' => $e->getMessage()]);
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
        return $this->config->getImageSearchPath();
    }

    /**
     * Adds a log record at the INFO level
     *
     * @param string $message
     * @param array  $extra
     *
     * @return void
     */
    private function log(string $message, array $extra = [])
    {
        $this->logger->info("ImageHelper - $message", $extra);
    }
}
