<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model;

use Magento\Catalog\Model\Product\Image as ProductImage;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Indexer\Model\IndexerFactory;
use Magento\Indexer\Model\Indexer\CollectionFactory as IndexerCollectionFactory;
use ECInternet\RAPIDWebSync\Api\BatchproductsInterface;
use ECInternet\RAPIDWebSync\Api\LogRepositoryInterface;
use ECInternet\RAPIDWebSync\Exception\IllegalNewAttributeOptionException;
use ECInternet\RAPIDWebSync\Helper\Data as Helper;
use ECInternet\RAPIDWebSync\Helper\Attribute as AttributeHelper;
use ECInternet\RAPIDWebSync\Helper\Import as ImportHelper;
use ECInternet\RAPIDWebSync\Logger\Logger;
use ECInternet\RAPIDWebSync\Model\Config\Source\IllegalNewAttributeActionOption;
use Exception;

/**
 * Batchproducts model
 */
class Batchproducts implements BatchproductsInterface
{
    /**
     * @var \Magento\Catalog\Model\Product\Image
     */
    private $_productImage;

    /**
     * @var \Magento\Framework\Filesystem\Driver\File
     */
    private $_fileDriver;

    /**
     * @var \Magento\Indexer\Model\IndexerFactory
     */
    private $_indexerFactory;

    /**
     * @var \Magento\Indexer\Model\Indexer\CollectionFactory
     */
    private $_indexerCollectionFactory;

    /**
     * @var \ECInternet\RAPIDWebSync\Api\LogRepositoryInterface
     */
    private $_logRepository;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Data
     */
    private $_helper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Attribute
     */
    private $_attributeHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Import
     */
    private $_importHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Model\LogFactory
     */
    private $_logFactory;

    /**
     * @var \ECInternet\RAPIDWebSync\Logger\Logger
     */
    private $_logger;

    /**
     * @var string
     */
    private $_input;

    /**
     * Batchproducts constructor.
     *
     * @param \Magento\Catalog\Model\Product\Image                $productImage
     * @param \Magento\Framework\Filesystem\Driver\File           $fileDriver
     * @param \Magento\Indexer\Model\IndexerFactory               $indexerFactory
     * @param \Magento\Indexer\Model\Indexer\CollectionFactory    $indexerCollectionFactory
     * @param \ECInternet\RAPIDWebSync\Api\LogRepositoryInterface $logRepository
     * @param \ECInternet\RAPIDWebSync\Helper\Data                $helper
     * @param \ECInternet\RAPIDWebSync\Helper\Attribute           $attributeHelper
     * @param \ECInternet\RAPIDWebSync\Helper\Import              $importHelper
     * @param \ECInternet\RAPIDWebSync\Model\LogFactory           $logFactory
     * @param \ECInternet\RAPIDWebSync\Logger\Logger              $logger
     */
    public function __construct(
        ProductImage $productImage,
        File $fileDriver,
        IndexerFactory $indexerFactory,
        IndexerCollectionFactory $indexerCollectionFactory,
        LogRepositoryInterface $logRepository,
        Helper $helper,
        AttributeHelper $attributeHelper,
        ImportHelper $importHelper,
        LogFactory $logFactory,
        Logger $logger
    ) {
        $this->_productImage             = $productImage;
        $this->_fileDriver               = $fileDriver;
        $this->_indexerFactory           = $indexerFactory;
        $this->_indexerCollectionFactory = $indexerCollectionFactory;
        $this->_logRepository            = $logRepository;
        $this->_helper                   = $helper;
        $this->_attributeHelper          = $attributeHelper;
        $this->_importHelper             = $importHelper;
        $this->_logFactory               = $logFactory;
        $this->_logger                   = $logger;
    }

    /**
     * Add product
     *
     * @return array
     * @throws \Exception
     * @throws \Throwable
     */
    public function add()
    {
        $this->log('---------------------------------------------------------');
        $this->log('| Starting RAPIDWebSync Import');
        $this->log('|');
        $this->log('| Update Operation: Insert');

        $startTime = microtime(true);

        /** @var \ECInternet\RAPIDWebSync\Model\Log $log */
        $log = $this->_logFactory->create();
        $log->setSyncOperation(Log::SYNC_OPERATION_INSERT);

        $importedProducts = [];

        $products = $this->getProductsFromInput();
        $settings = $this->getSettingsFromInput();

        $productInCount = count($products);
        $log->setCountIn($productInCount);
        $this->log("add() - Found [$productInCount] products in input.");

        if ($settings && $settings['transformId']) {
            $log->setTransformId($settings['transformId']);
        }

        $productOutCount = 0;
        $warningCount    = 0;
        $errorCount      = 0;

        $requiredAttributes = ['sku', 'price'];

        /** @var array $product */
        foreach ($products as $product) {
            $response = [];
            $errors   = [];

            foreach ($requiredAttributes as $requiredAttribute) {
                if (!isset($product[$requiredAttribute])) {
                    $errors[] = "Attribute '$requiredAttribute' is required and must be mapped.";
                    $errorCount++;
                }
            }

            if (!empty($errors)) {
                $response['error'] = join('  ', $errors);
            } else {
                // Cache sku
                $sku = (string)$product['sku'];

                // Start building response
                $response['sku'] = $sku;

                $this->log("add() - Processing sku '$sku'...");

                if ($this->_importHelper->doesProductExist($sku)) {
                    $response['warning'] = "Cannot add product.  Product with sku '$sku' exists already.";
                    $warningCount++;
                } else {
                    try {
                        $response = $this->_importHelper->addProduct($product);
                        $productOutCount++;
                    } catch (Exception $e) {
                        // If this was from attempting to add new attribute option, and we're skipping product, simply log it and move only next product
                        if ($e instanceof IllegalNewAttributeOptionException && $this->_helper->getIllegalNewAttributeAction() == IllegalNewAttributeActionOption::ACTION_SKIP_PRODUCT_VALUE) {
                            $this->log('add() - Attempted to add new attribute option.');

                            continue;
                        }

                        $response['error'] = $e->getMessage();
                        $errorCount++;
                    }
                }
            }

            $importedProducts[] = $response;
        }

        $endTime = microtime(true);
        $this->_helper->logSpeedTest($startTime, $endTime, 'add()');

        $log->setDuration((int)($endTime - $startTime));
        $log->setCountOut($productOutCount);
        $log->setWarningCount($warningCount);
        $log->setErrorCount($errorCount);
        $this->_logRepository->save($log);

        $this->reindex();
        $this->clearImageCache();

        $this->log('---------------------------------------------------------' . PHP_EOL . PHP_EOL . PHP_EOL);

        return $importedProducts;
    }

    /**
     * Update products
     *
     * @return array
     * @throws \Exception
     * @throws \Throwable
     */
    public function update()
    {
        $this->log('---------------------------------------------------------');
        $this->log('| Starting RAPIDWebSync Import');
        $this->log('|');
        $this->log('| Update Operation: Update');

        $startTime = microtime(true);

        /** @var \ECInternet\RAPIDWebSync\Model\Log $log */
        $log = $this->_logFactory->create();
        $log->setSyncOperation(Log::SYNC_OPERATION_UPDATE);

        $importedProducts = [];

        $products = $this->getProductsFromInput();
        $settings = $this->getSettingsFromInput();

        $productInCount = count($products);
        $log->setCountIn($productInCount);
        $this->log("update() - Found [$productInCount] products in input.");

        if ($settings && $settings['transformId']) {
            $log->setTransformId($settings['transformId']);
        }

        $productCountOut = 0;
        $warningCount    = 0;
        $errorCount      = 0;

        /** @var array $product */
        foreach ($products as $product) {
            $response = [];

            if (isset($product['sku'])) {
                // Cache sku
                $sku = (string)$product['sku'];

                // Start building response
                $response['sku'] = $sku;

                $this->log("update() - Processing sku '$sku'...");

                if ($this->_importHelper->doesProductExist($sku)) {
                    try {
                        $response = $this->_importHelper->updateProduct($product);
                        $productCountOut++;
                    } catch (Exception $e) {
                        // If this was from attempting to add new attribute option, and we're skipping product, simply log it and move only next product
                        if ($e instanceof IllegalNewAttributeOptionException && $this->_helper->getIllegalNewAttributeAction() == IllegalNewAttributeActionOption::ACTION_SKIP_PRODUCT_VALUE) {
                            $this->log('update() - Attempted to add new attribute option.');

                            continue;
                        }

                        $response['error'] = $e->getMessage();
                        $errorCount++;
                    }
                } else {
                    $this->log('update() - Sku does not already exist in system.  Cancelling update attempt.' . PHP_EOL);

                    $response['warning'] = "Cannot update product.  Product with sku '$sku' does not exist.";
                    $warningCount++;
                }
            } else {
                $message = "'sku' attribute not found in data.  Unable to process.";

                $response['error'] = $message;
                $errorCount++;
            }

            $importedProducts[] = $response;
        }

        $endTime = microtime(true);
        $this->_helper->logSpeedTest($startTime, $endTime, 'update()');

        $log->setDuration((int)($endTime - $startTime));
        $log->setCountOut($productCountOut);
        $log->setWarningCount($warningCount);
        $log->setErrorCount($errorCount);
        $this->_logRepository->save($log);

        $this->reindex();
        $this->clearImageCache();

        $this->log('---------------------------------------------------------' . PHP_EOL . PHP_EOL . PHP_EOL);

        return $importedProducts;
    }

    /**
     * Upsert products
     *
     * @return array
     * @throws \Exception
     * @throws \Throwable
     */
    public function upsert()
    {
        $this->log('---------------------------------------------------------');
        $this->log('| Starting RAPIDWebSync Import');
        $this->log('|');
        $this->log('| Update Operation: Insert/Update');

        $startTime = microtime(true);

        /** @var \ECInternet\RAPIDWebSync\Model\Log $log */
        $log = $this->_logFactory->create();
        $log->setSyncOperation(Log::SYNC_OPERATION_UPSERT);

        $importedProducts = [];

        $products = $this->getProductsFromInput();
        $settings = $this->getSettingsFromInput();

        $productInCount = count($products);
        $log->setCountIn($productInCount);
        $this->log("upsert() - Found [$productInCount] products in input.");

        if ($settings && $settings['transformId']) {
            $log->setTransformId($settings['transformId']);
        }

        $productOutCount = 0;
        $warningCount    = 0;
        $errorCount      = 0;

        /** @var array $product */
        foreach ($products as $product) {
            $response = [];

            if (isset($product['sku'])) {
                // Cache sku
                $sku = (string)$product['sku'];

                // Start building response
                $response['sku'] = $sku;

                $this->log("Processing sku '$sku'...");

                if ($this->_importHelper->doesProductExist($sku)) {
                    try {
                        $response = $this->_importHelper->updateProduct($product);
                        $productOutCount++;
                    } catch (Exception $e) {
                        $response['error'] = $e->getMessage();
                        $errorCount++;
                    }
                } else {
                    try {
                        $response = $this->_importHelper->addProduct($product);
                        if (isset($response['error'])) {
                            $errorCount++;
                        } else {
                            $productOutCount++;
                        }
                    } catch (Exception $e) {
                        $response['error'] = $e->getMessage();
                        $errorCount++;
                    }
                }
            } else {
                $response['error'] = "'sku' attribute not found in data.  Unable to process.";
                $errorCount++;
            }

            $importedProducts[] = $response;
        }

        // STOP TIMER
        $endTime = microtime(true);
        $this->_helper->logSpeedTest($startTime, $endTime, 'upsert()');

        $log->setDuration((int)($endTime - $startTime));
        $log->setCountOut($productOutCount);
        $log->setWarningCount($warningCount);
        $log->setErrorCount($errorCount);
        $this->_logRepository->save($log);

        $this->reindex();
        $this->clearImageCache();

        $this->log('---------------------------------------------------------' . PHP_EOL . PHP_EOL . PHP_EOL);

        return $importedProducts;
    }

    /**
     * Get columns of 'sales_order' table
     *
     * @return array
     */
    public function getSalesOrderColumns()
    {
        return $this->_importHelper->getSalesOrderColumns();
    }

    /**
     * Get product attribute codes
     *
     * @return array
     * @throws \Exception
     */
    public function getProductAttributeCodes()
    {
        return $this->_attributeHelper->getCatalogProductAttributeCodes();
    }

    /**
     * Reindex tables defined by settings
     *
     * @return void
     * @throws \Exception
     * @throws \Throwable
     */
    public function reindex()
    {
        $this->log('reindex()');

        // If flag is disabled, log it and leave.
        if (!$this->_helper->isPostImportReindexEnabled()) {
            $this->log('NOTE: Post-import reindex is disabled.');

            return;
        }

        $tablesToIndex      = $this->getTablesToReindex();
        $tablesToIndexCount = count($tablesToIndex);
        $this->log("reindex() - Found [$tablesToIndexCount] tables to re-index.");

        if ($tablesToIndexCount == 0) {
            $this->log('NOTE: No tables set to reindex.');

            return;
        }

        $startTime = microtime(true);
        foreach ($tablesToIndex as $tableToIndex) {
            $this->log('reindex()', ['tableToIndex' => $tableToIndex]);

            if (!empty($tableToIndex)) {
                $startTimeIndexer = microtime(true);

                /** @var \Magento\Indexer\Model\Indexer $indexer */
                if ($indexer = $this->loadIndexerByName($tableToIndex)) {
                    $this->log("reindex() - Re-indexing table [$tableToIndex]...");
                    $indexer->reindexAll();
                    $this->log('reindex() - Done.');
                } else {
                    $this->log("reindex() - Could not find table [$tableToIndex]");
                }

                $endTimeIndexer = microtime(true);

                $this->_helper->logSpeedTest($startTimeIndexer, $endTimeIndexer, "Reindexed table [$tableToIndex].");
            }
        }
        $endTime = microtime(true);
        $this->_helper->logSpeedTest($startTime, $endTime, 'reindex()');
    }

    /**
     * Reindex tables by name
     *
     * @return void
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Throwable
     */
    public function reindexTables()
    {
        $this->log('reindexTables()');

        if ($input = $this->getInput()) {
            if (isset($input['items'])) {
                $items = $input['items'];
                if (is_array($items)) {
                    foreach ($items as $indexerName) {
                        $this->log("Found reindex name [$indexerName]");

                        /** @var \Magento\Indexer\Model\Indexer $indexer */
                        if ($indexer = $this->getIndexerByName($indexerName)) {
                            $this->log("Reindexing '{$indexer->getTitle()}' ({$indexer->getId()})...");
                            $indexer->reindexAll();
                            $this->log('Reindex complete.');
                        }
                    }
                }
            }
        }
    }

    /**
     * Get Magento edition
     *
     * @return string
     */
    public function getMagentoEdition()
    {
        return $this->_helper->getMagentoEdition();
    }

    /**
     * Get Magento version
     *
     * @return string
     */
    public function getMagentoVersion()
    {
        return $this->_helper->getMagentoVersion();
    }

    /**
     * Clear product image cache
     */
    private function clearImageCache()
    {
        $this->log('clearImageCache()');

        // If flag is disabled, log it and leave.
        if (!$this->_helper->shouldClearImageCache()) {
            $this->log('NOTE: Post-import image cache clear is disabled.');

            return;
        }

        try {
            $this->_productImage->clearCache();
        } catch (FileSystemException $e) {
            $this->log('clearImageCache()', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Get reindex tables from settings
     *
     * @return string[]
     */
    private function getTablesToReindex()
    {
        $this->log('getTablesToReindex()');

        $tables = [];

        if ($reindexTableList = $this->_helper->getReindexTableList()) {
            $tables = explode(',', $reindexTableList);
        }

        return $tables;
    }

    private function loadIndexerByName(string $indexerName)
    {
        $this->log('loadIndexerByName()', ['indexerName' => $indexerName]);

        try {
            return $this->_indexerFactory->create()->load($indexerName);
        } catch (Exception $e) {
            $this->log('loadIndexerByName()', ['exception' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Lookup Indexer by name. Returns first match.
     *
     * @param string $indexerName
     *
     * @return \Magento\Framework\DataObject|\Magento\Indexer\Model\Indexer|null
     */
    private function getIndexerByName(string $indexerName)
    {
        $this->log('getIndexerByName()', ['indexer' => $indexerName]);

        /** @var \Magento\Indexer\Model\Indexer\Collection $indexers */
        $indexers = $this->_indexerCollectionFactory->create();

        /** @var \Magento\Indexer\Model\Indexer $indexer */
        foreach ($indexers as $indexer) {
            if ($indexer->getTitle() === $indexerName) {
                $this->log("getIndexerByName() - Found match: [{$indexer->getId()}]");

                return $indexer;
            }
        }

        return null;
    }

    ////////////////////////////////////////////////////////////////////////////////
    ///
    /// INPUT PROCESSING
    ///
    ////////////////////////////////////////////////////////////////////////////////

    /**
     * Parse products from 'products' in input
     *
     * @return array
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function getProductsFromInput()
    {
        $startTime = microtime(true);

        $products = [];
        if ($input = $this->getInput()) {
            if (isset($input['products'])) {
                foreach ($input['products'] as $product) {
                    $products[] = $product;
                }
            }
        }

        $endTime = microtime(true);

        $this->_helper->logSpeedTest($startTime, $endTime, 'getProductsFromInput()');

        return $products;
    }

    /**
     * Parse settings from 'settings' in input
     *
     * @return array|mixed|string
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function getSettingsFromInput()
    {
        $startTime = microtime(true);

        $settings = [];

        if ($input = $this->getInput()) {
            if (isset($input['settings'])) {
                $settings = $input['settings'];
            }
        }

        $endTime = microtime(true);

        $this->_helper->logSpeedTest($startTime, $endTime, 'getSettingsFromInput()');

        return $settings;
    }

    /**
     * Read input
     *
     * @return mixed|string
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function getInput()
    {
        if (!$this->_input) {
            $startTime = time();
            $contents  = $this->_fileDriver->fileGetContents('php://input');
            $this->log("| JSON INPUT: [$contents]");
            $this->log('|');
            $this->_input = json_decode($contents, true);
            $endTime      = time();

            $this->_helper->logSpeedTest($startTime, $endTime, 'getInput()');
        }

        return $this->_input;
    }

    /**
     * Write to extension log
     *
     * @param string $message
     * @param array  $extra
     */
    private function log(string $message, array $extra = [])
    {
        $this->_logger->info('Model/Batchproducts - ' . $message, $extra);
    }
}
