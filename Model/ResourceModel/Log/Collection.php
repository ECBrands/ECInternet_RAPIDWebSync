<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model\ResourceModel\Log;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Log Collection
 */
class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected $_eventPrefix = 'ecinternet_rapidwebsync_log_collection';

    protected $_eventObject = 'log_collection';

    /**
     * Define resource model
     *
     * @return void
     */
    protected function _construct()
    {
        /** @noinspection PhpFullyQualifiedNameUsageInspection */
        $this->_init(
            \ECInternet\RAPIDWebSync\Model\Log::class,
            \ECInternet\RAPIDWebSync\Model\ResourceModel\Log::class
        );
    }
}
