<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model\Magento;

class Environment
{
    private const COMMUNITY_EDITION_VALUE = 'Community';

    /**
     * @var \Magento\Framework\App\ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * Environment constructor.
     *
     * @param \Magento\Framework\App\ProductMetadataInterface $productMetadata
     */
    public function __construct(
        \Magento\Framework\App\ProductMetadataInterface $productMetadata
    ) {
        $this->productMetadata = $productMetadata;
    }

    /**
     * @return string
     */
    public function getProductIdColumn()
    {
        return $this->isVersionCommunity() ? 'entity_id' : 'row_id';
    }

    /**
     * Get Product edition
     *
     * @return string
     */
    public function getMagentoEdition()
    {
        return $this->productMetadata->getEdition();
    }

    /**
     * Get Product version
     *
     * @return string
     */
    public function getMagentoVersion()
    {
        return $this->productMetadata->getVersion();
    }

    /**
     * @return bool
     */
    public function isVersionCommunity()
    {
        return $this->getMagentoEdition() === self::COMMUNITY_EDITION_VALUE;
    }
}
