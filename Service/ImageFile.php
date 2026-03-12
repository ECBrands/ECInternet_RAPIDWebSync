<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Service;

use ECInternet\RAPIDWebSync\Model\Config;
use Exception;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Psr\Log\LoggerInterface;

class ImageFile
{
    /**
     * @var \Magento\Framework\Filesystem\DirectoryList
     */
    private $directoryList;

    /**
     * @var \Magento\Framework\Filesystem\Driver\File
     */
    private $fileDriver;

    /**
     * @var \ECInternet\RAPIDWebSync\Model\Config
     */
    private $config;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var string
     */
    private $lastProcessedImage = '';

    /**
     * ImageFile constructor.
     *
     * @param \Magento\Framework\Filesystem\DirectoryList $directoryList
     * @param \Magento\Framework\Filesystem\Driver\File   $fileDriver
     * @param \ECInternet\RAPIDWebSync\Model\Config       $config
     * @param \Psr\Log\LoggerInterface                    $logger
     */
    public function __construct(
        DirectoryList $directoryList,
        File $fileDriver,
        Config $config,
        LoggerInterface $logger,
    ) {
        $this->directoryList = $directoryList;
        $this->fileDriver    = $fileDriver;
        $this->config        = $config;
        $this->logger        = $logger;
    }

    /**
     * Returns image file name relative to media directory (with leading /)
     *
     * @param string $imageFile
     *
     * @return string|false
     * @throws Exception
     */
    public function copyImageFile(string $imageFile)
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
     * Gets a filesystem path of the root directory
     *
     * @return string
     */
    protected function getMagentoDirectory()
    {
        return $this->directoryList->getRoot();
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
     * Get image search path on server
     *
     * @return string
     */
    protected function getImageSearchPath()
    {
        return $this->config->getImageSearchPath();
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
        $this->logger->info('ImageFile - ' . $message, $extra);
    }
}
