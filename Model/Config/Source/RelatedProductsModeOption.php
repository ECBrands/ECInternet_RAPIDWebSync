<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options for Related Products
 */
class RelatedProductsModeOption implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => 1,
                'label' => 'Addition'
            ],
            [
                'value' => 2,
                'label' => 'Replacement'
            ]
        ];
    }
}
