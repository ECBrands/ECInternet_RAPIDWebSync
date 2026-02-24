<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model;

use Magento\Framework\Exception\LocalizedException;
use ECInternet\RAPIDWebSync\Api\Data\ProductDataProcessorInterface;
use ECInternet\RAPIDWebSync\Api\ProductDataProcessorPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class ProductDataProcessorPool implements ProductDataProcessorPoolInterface
{
    /**
     * @var \ECInternet\RAPIDWebSync\Api\Data\ProductDataProcessorInterface[]
     */
    protected $productDataProcessors;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * PaymentGatewayPool constructor.
     *
     * @param \Psr\Log\LoggerInterface $logger
     * @param array                    $productDataProcessors
     *
     */
    public function __construct(
        LoggerInterface $logger,
        array $productDataProcessors = []
    ) {
        $this->logger = $logger;

        foreach ($productDataProcessors as $productDataProcessorName => $productDataProcessor) {
            if (!$productDataProcessor instanceof ProductDataProcessorInterface) {
                throw new LocalizedException(
                    __(
                        'Product data processor %1 must be of type ECInternet\RAPIDWebSync\Api\Data\ProductDataProcessorInterface',
                        $productDataProcessorName
                    )
                );
            }
        }

        $this->productDataProcessors = $productDataProcessors;
    }

    public function getProductDataProcessors()
    {
        $this->log('getProductDataProcessors()');

        return $this->productDataProcessors;
    }

    public function getProductDataProcessor(string $name)
    {
        $this->log('getProductDataProcessor()', ['name' => $name]);

        if (array_key_exists($name, $this->productDataProcessors)) {
            return $this->productDataProcessors[$name];
        }

        throw new LocalizedException(__('Product data processor %1 not found', $name));
    }

    private function log(string $message, array $extra = [])
    {
        $this->logger->info('Model/ProductDataProcessorPool - ' . $message, $extra);
    }
}
