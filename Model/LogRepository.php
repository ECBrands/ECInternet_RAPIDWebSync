<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use ECInternet\RAPIDWebSync\Api\Data\LogInterface;
use ECInternet\RAPIDWebSync\Api\LogRepositoryInterface;
use ECInternet\RAPIDWebSync\Model\ResourceModel\Log;
use Exception;

/**
 * Repository for Log model
 */
class LogRepository implements LogRepositoryInterface
{
    /**
     * @var \ECInternet\RAPIDWebSync\Model\ResourceModel\Log
     */
    private $_resourceModel;

    /**
     * LogRepository constructor.
     *
     * @param \ECInternet\RAPIDWebSync\Model\ResourceModel\Log $resourceModel
     */
    public function __construct(
        Log $resourceModel
    ) {
        $this->_resourceModel = $resourceModel;
    }

    /**
     * Save Log
     *
     * @param \ECInternet\RAPIDWebSync\Api\Data\LogInterface $log
     *
     * @return \ECInternet\RAPIDWebSync\Api\Data\LogInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(
        LogInterface $log
    ) {
        try {
            $this->_resourceModel->save($log);
        } catch (Exception $e) {
            throw new CouldNotSaveException(__('Could not save the log: %1', $e->getMessage()));
        }

        return $log;
    }
}
