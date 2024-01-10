<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use ECInternet\RAPIDWebSync\Api\BulkOperationInterface;
use ECInternet\RAPIDWebSync\Logger\Logger;
use Exception;

/**
 * BulkOperation model
 */
class BulkOperation implements BulkOperationInterface
{
    const RESPONSE_FIELD_SKU     = 'sku';

    const RESPONSE_FIELD_ID      = 'id';

    const RESPONSE_FIELD_NEW     = 'new';

    const RESPONSE_FIELD_WARNING = 'warning';

    const RESPONSE_FIELD_ERROR   = 'error';

    const RESPONSE_FIELD_TRACE   = 'trace';

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var \ECInternet\RAPIDWebSync\Logger\Logger
     */
    private $logger;

    private $requiredFields = [
        'name',
        'attribute_set_id',
        'price',
        'type_id'
    ];

    /**
     * BulkOperation constructor.
     *
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
     * @param \ECInternet\RAPIDWebSync\Logger\Logger          $logger
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        Logger $logger
    ) {
        $this->productRepository = $productRepository;
        $this->logger            = $logger;
    }

    public function add(array $products)
    {
        $this->log('add()');

        $importedProducts = [];

        foreach ($products as $product) {
            $response = [];

            if ($sku = $product->getSku()) {
                $this->log('add()', ['sku' => $sku]);

                // Start building response
                $response[self::RESPONSE_FIELD_SKU] = $sku;

                if (!$this->doesProductExist($sku)) {
                    try {
                        $response = $this->addProduct($product);
                    } catch (Exception $e) {
                        $this->log("add() - Unable to ADD product: {$e->getMessage()}");

                        $response[self::RESPONSE_FIELD_ERROR] = $e->getMessage();
                        $response[self::RESPONSE_FIELD_TRACE] = $e->getTraceAsString();
                    }
                } else {
                    $response[self::RESPONSE_FIELD_WARNING] = 'Product exists.  Insert failed.';
                }
            } else {
                $response[self::RESPONSE_FIELD_ERROR] = "'sku' attribute not found in data.  Unable to process.";
            }

            $importedProducts[] = $response;
        }

        return $importedProducts;
    }

    public function update(array $products)
    {
        $this->log('update()');

        $importedProducts = [];

        foreach ($products as $product) {
            $response = [];

            if ($sku = $product->getSku()) {
                $this->log('update()', ['sku' => $sku]);

                // Start building response
                $response[self::RESPONSE_FIELD_SKU] = $sku;

                $this->log("Processing sku '$sku'...");

                if ($this->doesProductExist($sku)) {
                    try {
                        $response = $this->updateProduct($product);
                    } catch (Exception $e) {
                        $this->log("update() - Unable to UPDATE product: {$e->getMessage()}");

                        $response[self::RESPONSE_FIELD_ERROR] = $e->getMessage();
                        $response[self::RESPONSE_FIELD_TRACE] = $e->getTraceAsString();
                    }
                } else {
                    $response[self::RESPONSE_FIELD_WARNING] = "Product '$sku' does not exist in Magento.";
                }
            } else {
                $response[self::RESPONSE_FIELD_ERROR] = "'sku' attribute not found in data.  Unable to process.";
            }

            $importedProducts[] = $response;
        }

        return $importedProducts;
    }

    public function upsert(array $products)
    {
        $this->log('upsert()');

        $importedProducts = [];

        foreach ($products as $product) {
            $response = [];

            if ($sku = $product->getSku()) {
                $this->log('upsert()', ['sku' => $sku]);

                // Start building response
                $response[self::RESPONSE_FIELD_SKU] = $sku;

                if ($this->doesProductExist($sku)) {
                    // Attempt update
                    try {
                        $response = $this->updateProduct($product);
                    } catch (Exception $e) {
                        $this->log('upsert()', ['operation' => 'update', 'exception' => $e->getMessage()]);

                        $response[self::RESPONSE_FIELD_ERROR] = $e->getMessage();
                        $response[self::RESPONSE_FIELD_TRACE] = $e->getTraceAsString();
                    }
                } else {
                    // Attempt add
                    try {
                        $response = $this->addProduct($product);
                    } catch (Exception $e) {
                        $this->log('upsert()', ['operation' => 'add', 'exception' => $e->getMessage()]);

                        $response[self::RESPONSE_FIELD_ERROR] = $e->getMessage();
                        $response[self::RESPONSE_FIELD_TRACE] = $e->getTraceAsString();
                    }
                }
            } else {
                $response[self::RESPONSE_FIELD_ERROR] = "'sku' attribute not found in data.  Unable to process.";
            }

            $importedProducts[] = $response;
        }

        return $importedProducts;
    }

    /**
     * Add Product
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     *
     * @return array
     * @throws \Magento\Framework\Exception\InputException
     */
    private function addProduct(
        ProductInterface $product
    ) {
        $this->log('addProduct()', ['product' => $product->getData()]);

        // Setup warning and error arrays
        $warnings = [];
        $errors   = [];
        $trace    = null;

        // Check for required fields
        foreach ($this->requiredFields as $requiredField) {
            if (!isset($product[$requiredField])) {
                throw new InputException(
                    __("Attribute '$requiredField' is required for new products.")
                );
            }
        }

        try {
            $product = $this->productRepository->save($product);
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
            $trace    = $e->getTraceAsString();
        }

        return [
            self::RESPONSE_FIELD_SKU     => $product->getSku(),
            self::RESPONSE_FIELD_ID      => $product->getId(),
            self::RESPONSE_FIELD_NEW     => true,
            self::RESPONSE_FIELD_WARNING => implode(' ', $warnings),
            self::RESPONSE_FIELD_ERROR   => implode(' ', $errors),
            self::RESPONSE_FIELD_TRACE   => $trace
        ];
    }

    /**
     * Update Product
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     *
     * @return array
     */
    private function updateProduct(
        ProductInterface $product
    ) {
        $this->log('updateProduct()', ['product' => $product->getData()]);

        // Setup warning and error arrays
        $warnings = [];
        $errors   = [];
        $trace    = null;

        try {
            $product = $this->productRepository->save($product);
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
            $trace    = $e->getTraceAsString();
        }

        return [
            self::RESPONSE_FIELD_SKU     => $product->getSku(),
            self::RESPONSE_FIELD_ID      => $product->getId(),
            self::RESPONSE_FIELD_NEW     => false,
            self::RESPONSE_FIELD_WARNING => implode(' ', $warnings),
            self::RESPONSE_FIELD_ERROR   => implode(' ', $errors),
            self::RESPONSE_FIELD_TRACE   => $trace
        ];
    }

    /**
     * Lookup Product by SKU
     *
     * @param string $sku
     *
     * @return bool
     */
    private function doesProductExist(string $sku)
    {
        try {
            $this->productRepository->get($sku);

            return true;
        } catch (NoSuchEntityException $e) {
            $this->log($e->getMessage());
        }

        return false;
    }

    /**
     * Write to extension log
     *
     * @param string $message
     * @param array  $extra
     */
    private function log(string $message, array $extra = [])
    {
        $this->logger->info('Model/BulkOperation - ' . $message, $extra);
    }
}
