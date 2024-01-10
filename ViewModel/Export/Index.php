<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\ViewModel\Export;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\UrlInterface;

class Index implements ArgumentInterface
{
    /**
     * @var \Magento\Framework\UrlInterface
     */
    private $_urlBuilder;

    /**
     * @param \Magento\Framework\UrlInterface $urlInterface
     */
    public function __construct(
        UrlInterface $urlInterface
    ) {
        $this->_urlBuilder = $urlInterface;
    }

    /**
     * Get download URL
     *
     * @return string
     */
    public function getDownloadUrl()
    {
        return $this->_urlBuilder->getUrl('rapidwebsync/export/download');
    }
}
