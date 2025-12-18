<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Indexer\Model\Indexer\CollectionFactory as IndexerCollectionFactory;

/**
 * Options for Indexer
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class IndexerOption implements OptionSourceInterface
{
    /**
     * @var \Magento\Indexer\Model\Indexer\CollectionFactory
     */
    private $indexerCollectionFactory;

    /**
     * IndexerOption constructor.
     *
     * @param \Magento\Indexer\Model\Indexer\CollectionFactory $indexerCollectionFactory
     */
    public function __construct(
        IndexerCollectionFactory $indexerCollectionFactory
    ) {
        $this->indexerCollectionFactory = $indexerCollectionFactory;
    }

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray()
    {
        $options = [];

        /** @var \Magento\Indexer\Model\Indexer\Collection $indexers */
        $indexers = $this->indexerCollectionFactory->create();
        foreach ($indexers as $indexer) {
            /** @var \Magento\Indexer\Model\Indexer $indexer */
            $options[] = [
                'value' => $indexer->getId(),
                'label' => $indexer->getTitle()
            ];
        }

        return $options;
    }
}
