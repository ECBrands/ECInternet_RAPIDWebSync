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
use ECInternet\RAPIDWebSync\Api\BatchproductsInterface;
use ECInternet\RAPIDWebSync\Api\LogRepositoryInterface;
use ECInternet\RAPIDWebSync\Exception\IllegalNewAttributeOptionException;
use ECInternet\RAPIDWebSync\Helper\Attribute as AttributeHelper;
use ECInternet\RAPIDWebSync\Helper\Indexer as IndexerHelper;
use ECInternet\RAPIDWebSync\Model\Config\Source\IllegalNewAttributeActionOption;
use ECInternet\RAPIDWebSync\Model\Data\Log;
use ECInternet\RAPIDWebSync\Model\Data\LogFactory;
use ECInternet\RAPIDWebSync\Model\Import\ProductImporter;
use ECInternet\RAPIDWebSync\Model\Magento\Environment;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Batchproducts model
 */
class Batchproducts implements BatchproductsInterface
{
    /**
     * @var \Magento\Catalog\Model\Product\Image
     */
    private $productImage;

    /**
     * @var \Magento\Framework\Filesystem\Driver\File
     */
    private $fileDriver;

    /**
     * @var \ECInternet\RAPIDWebSync\Api\LogRepositoryInterface
     */
    private $logRepository;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Attribute
     */
    private $attributeHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Indexer
     */
    private $indexerHelper;

    /**
     * @var \ECInternet\RAPIDWebSync\Model\Data\LogFactory
     */
    private $logFactory;

    /**
     * @var \ECInternet\RAPIDWebSync\Model\Config
     */
    private $config;

    /**
     * @var \ECInternet\RAPIDWebSync\Model\Import\ProductImporter
     */
    private $productImporter;

    /**
     * @var \ECInternet\RAPIDWebSync\Model\Magento\Environment
     */
    private $magentoEnvironment;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var string
     */
    private $input;

    /**
     * Batchproducts constructor.
     *
     * @param \Magento\Catalog\Model\Product\Image                  $productImage
     * @param \Magento\Framework\Filesystem\Driver\File             $fileDriver
     * @param \ECInternet\RAPIDWebSync\Api\LogRepositoryInterface   $logRepository
     * @param \ECInternet\RAPIDWebSync\Helper\Attribute             $attributeHelper
     * @param \ECInternet\RAPIDWebSync\Helper\Indexer               $indexerHelper
     * @param \ECInternet\RAPIDWebSync\Model\Data\LogFactory        $logFactory
     * @param \ECInternet\RAPIDWebSync\Model\Config                 $config
     * @param \ECInternet\RAPIDWebSync\Model\Import\ProductImporter $productImporter
     * @param \ECInternet\RAPIDWebSync\Model\Magento\Environment    $magentoEnvironment
     * @param \Psr\Log\LoggerInterface                              $logger
     */
    public function __construct(
        ProductImage $productImage,
        File $fileDriver,
        LogRepositoryInterface $logRepository,
        AttributeHelper $attributeHelper,
        IndexerHelper $indexerHelper,
        LogFactory $logFactory,
        Config $config,
        ProductImporter $productImporter,
        Environment $magentoEnvironment,
        LoggerInterface $logger
    ) {
        $this->productImage       = $productImage;
        $this->fileDriver         = $fileDriver;
        $this->logRepository      = $logRepository;
        $this->attributeHelper    = $attributeHelper;
        $this->indexerHelper      = $indexerHelper;
        $this->logFactory         = $logFactory;
        $this->config             = $config;
        $this->productImporter    = $productImporter;
        $this->magentoEnvironment = $magentoEnvironment;
        $this->logger             = $logger;
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

        /** @var \ECInternet\RAPIDWebSync\Model\Data\Log $log */
        $log = $this->logFactory->create();
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
                $response['error'] = implode('  ', $errors);
            } else {
                // Cache sku
                $sku = (string)$product['sku'];

                // Start building response
                $response['sku'] = $sku;

                $this->log("add() - Processing sku '$sku'...");

                if ($this->productImporter->doesProductExist($sku)) {
                    $response['warning'] = "Cannot add product.  Product with sku '$sku' exists already.";
                    $warningCount++;
                } else {
                    try {
                        $response = $this->productImporter->addProduct($product);
                        $productOutCount++;
                    } catch (Exception $e) {
                        // If this was from attempting to add new attribute option, and we're skipping product, simply log it and move only next product
                        if ($e instanceof IllegalNewAttributeOptionException && $this->config->getIllegalNewAttributeAction() == IllegalNewAttributeActionOption::ACTION_SKIP_PRODUCT_VALUE) {
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

        $log->setDuration((int)($endTime - $startTime));
        $log->setCountOut($productOutCount);
        $log->setWarningCount($warningCount);
        $log->setErrorCount($errorCount);
        $this->logRepository->save($log);

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

        /** @var \ECInternet\RAPIDWebSync\Model\Data\Log $log */
        $log = $this->logFactory->create();
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

                if ($this->productImporter->doesProductExist($sku)) {
                    try {
                        $response = $this->productImporter->updateProduct($product);
                        $productCountOut++;
                    } catch (Exception $e) {
                        // If this was from attempting to add new attribute option, and we're skipping product, simply log it and move only next product
                        if ($e instanceof IllegalNewAttributeOptionException && $this->config->getIllegalNewAttributeAction() == IllegalNewAttributeActionOption::ACTION_SKIP_PRODUCT_VALUE) {
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

        $log->setDuration((int)($endTime - $startTime));
        $log->setCountOut($productCountOut);
        $log->setWarningCount($warningCount);
        $log->setErrorCount($errorCount);
        $this->logRepository->save($log);

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

        /** @var \ECInternet\RAPIDWebSync\Model\Data\Log $log */
        $log = $this->logFactory->create();
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

                if ($this->productImporter->doesProductExist($sku)) {
                    try {
                        $response = $this->productImporter->updateProduct($product);
                        $productOutCount++;
                    } catch (Exception $e) {
                        $response['error'] = $e->getMessage();
                        $errorCount++;
                    }
                } else {
                    try {
                        $response = $this->productImporter->addProduct($product);
                        if (isset($response['error'])) {
                            $errorCount++;
                        } else {
                            $productOutCount++;
                        }
                    } catch (Exception $e) {
                        // If this was from attempting to add new attribute option, and we're skipping product, simply log it and move only next product
                        if ($e instanceof IllegalNewAttributeOptionException && $this->config->getIllegalNewAttributeAction() == IllegalNewAttributeActionOption::ACTION_SKIP_PRODUCT_VALUE) {
                            $this->log('update() - Attempted to add new attribute option.');

                            continue;
                        }

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

        $log->setDuration((int)($endTime - $startTime));
        $log->setCountOut($productOutCount);
        $log->setWarningCount($warningCount);
        $log->setErrorCount($errorCount);
        $this->logRepository->save($log);

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
        return $this->productImporter->getSalesOrderColumns();
    }

    /**
     * Get product attribute codes
     *
     * @return array
     * @throws \Exception
     */
    public function getProductAttributeCodes()
    {
        return $this->attributeHelper->getCatalogProductAttributeCodes();
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
        if (!$this->config->isPostImportReindexEnabled()) {
            $this->log('NOTE: Post-import reindex is disabled.');

            return;
        }

        $tablesToIndex      = $this->getTablesToReindex();
        $tablesToIndexCount = count($tablesToIndex);
        $this->log("reindex() - Found [$tablesToIndexCount] tables to re-index.");

        if ($tablesToIndexCount === 0) {
            $this->log('NOTE: No tables set to reindex.');

            return;
        }

        foreach ($tablesToIndex as $tableToIndex) {
            $this->log('reindex()', ['tableToIndex' => $tableToIndex]);

            if (!empty($tableToIndex)) {
                /** @var \Magento\Indexer\Model\Indexer $indexer */
                if ($indexer = $this->indexerHelper->loadIndexerByName($tableToIndex)) {
                    $this->log("reindex() - Re-indexing table [$tableToIndex]...");
                    $indexer->reindexAll();
                    $this->log('reindex() - Done.');
                } else {
                    $this->log("reindex() - Could not find table [$tableToIndex]");
                }
            }
        }
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
                        if ($indexer = $this->indexerHelper->getIndexerByName($indexerName)) {
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
        return $this->magentoEnvironment->getMagentoEdition();
    }

    /**
     * Get Magento version
     *
     * @return string
     */
    public function getMagentoVersion()
    {
        return $this->magentoEnvironment->getMagentoVersion();
    }

    /**
     * Clear product image cache
     */
    private function clearImageCache()
    {
        $this->log('clearImageCache()');

        // If flag is disabled, log it and leave.
        if (!$this->config->shouldClearImageCache()) {
            $this->log('NOTE: Post-import image cache clear is disabled.');

            return;
        }

        try {
            $this->productImage->clearCache();
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

        if ($reindexTableList = $this->config->getReindexTableList()) {
            $tables = explode(',', $reindexTableList);
        }

        return $tables;
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
        $products = [];

        if ($input = $this->getInput()) {
            if (isset($input['products'])) {
                foreach ($input['products'] as $product) {
                    $products[] = $product;
                }
            }
        }

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
        $settings = [];

        if ($input = $this->getInput()) {
            if (isset($input['settings'])) {
                $settings = $input['settings'];
            }
        }

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
        if (!$this->input) {
            $contents  = $this->fileDriver->fileGetContents('php://input');
            $this->log("| JSON INPUT: [$contents]");
            $this->log('|');
            $this->input = json_decode($contents, true);
        }

        return $this->input;
    }

    /**
     * Write to extension log
     *
     * @param string $message
     * @param array  $extra
     */
    private function log(string $message, array $extra = [])
    {
        $this->logger->info('Model/Batchproducts - ' . $message, $extra);
    }
}
