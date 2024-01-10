<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model\Log\Source;

use Magento\Framework\Data\OptionSourceInterface;
use ECInternet\RAPIDWebSync\Model\Log;

/**
 * Options for SyncOperation
 */
class SyncOperation implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => Log::SYNC_OPERATION_INSERT,
                'label' => __('Insert')
            ],
            [
                'value' => Log::SYNC_OPERATION_UPDATE,
                'label' => __('Update')
            ],
            [
                'value' => Log::SYNC_OPERATION_UPSERT,
                'label' => __('Insert/Update')
            ]
        ];
    }
}
