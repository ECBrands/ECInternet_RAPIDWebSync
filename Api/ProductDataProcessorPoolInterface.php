<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Api;

interface ProductDataProcessorPoolInterface
{
    /**
     * @return \ECInternet\RAPIDWebSync\Api\Data\ProductDataProcessorInterface[]
     */
    public function getProductDataProcessors();

    /**
     * @param string $name
     *
     * @return \ECInternet\RAPIDWebSync\Api\Data\ProductDataprocessorInterface
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getProductDataProcessor(string $name);
}
