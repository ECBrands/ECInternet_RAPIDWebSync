<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use Magento\Indexer\Model\IndexerFactory;
use Magento\Indexer\Model\Indexer\CollectionFactory as IndexerCollectionFactory;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class Indexer
{
    /**
     * @var \Magento\Indexer\Model\IndexerFactory
     */
    private $indexerFactory;

    /**
     * @var \Magento\Indexer\Model\Indexer\CollectionFactory
     */
    private $indexerCollectionFactory;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * Indexer constructor.
     *
     * @param \Magento\Indexer\Model\IndexerFactory            $indexerFactory
     * @param \Magento\Indexer\Model\Indexer\CollectionFactory $indexerCollectionFactory
     * @param \Psr\Log\LoggerInterface                         $logger
     */
    public function __construct(
        IndexerFactory $indexerFactory,
        IndexerCollectionFactory $indexerCollectionFactory,
        LoggerInterface $logger,
    ) {
        $this->indexerFactory           = $indexerFactory;
        $this->indexerCollectionFactory = $indexerCollectionFactory;
        $this->logger                   = $logger;
    }

    /**
     * Lookup Indexer by name. Returns first match.
     *
     * @param string $indexerName
     *
     * @return \Magento\Indexer\Model\Indexer|null
     */
    public function getIndexerByName(string $indexerName)
    {
        $this->log('getIndexerByName()', ['indexer' => $indexerName]);

        /** @var \Magento\Indexer\Model\Indexer\Collection $indexers */
        $indexers = $this->indexerCollectionFactory->create();

        /** @var \Magento\Indexer\Model\Indexer $indexer */
        foreach ($indexers as $indexer) {
            if ($indexer->getTitle() === $indexerName) {
                return $indexer;
            }
        }

        return null;
    }

    public function loadIndexerByName(string $indexerName)
    {
        $this->log('loadIndexerByName()', ['indexerName' => $indexerName]);

        try {
            return $this->indexerFactory->create()->load($indexerName);
        } catch (Exception $e) {
            $this->log('loadIndexerByName()', ['exception' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Write to extension log
     *
     * @param string $message
     * @param array  $extra
     */
    private function log(string $message, array $extra = [])
    {
        $this->logger->info('Helper/Indexer - ' . $message, $extra);
    }
}
