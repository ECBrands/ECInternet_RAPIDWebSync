<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options for Category Mode
 */
class IllegalNewAttributeActionOption implements OptionSourceInterface
{
    const ACTION_IGNORE_VALUE       = 1;

    const ACTION_IGNORE_LABEL       = 'Ignore (Warning)';

    const ACTION_SKIP_PRODUCT_VALUE = 2;

    const ACTION_SKIP_PRODUCT_LABEL = 'Skip product (Error)';

    const ACTION_SKIP_BATCH_VALUE   = 3;

    const ACTION_SKIP_BATCH_LABEL   = 'Skip entire batch (Error)';

    /**
     * @inheritDoc
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => self::ACTION_IGNORE_VALUE,
                'label' => self::ACTION_IGNORE_LABEL
            ],
            [
                'value' => self::ACTION_SKIP_PRODUCT_VALUE,
                'label' => self::ACTION_SKIP_PRODUCT_LABEL
            ],
            [
                'value' => self::ACTION_SKIP_BATCH_VALUE,
                'label' => self::ACTION_SKIP_BATCH_LABEL
            ]
        ];
    }
}
