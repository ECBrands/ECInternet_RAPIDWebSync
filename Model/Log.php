<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Model\AbstractModel;
use ECInternet\RAPIDWebSync\Api\Data\LogInterface;

/**
 * Log Model
 */
class Log extends AbstractModel implements IdentityInterface, LogInterface
{
    const CACHE_TAG = 'ecinternet_rapidwebsync_log';

    protected $_cacheTag    = 'ecinternet_rapidwebsync_log';

    protected $_eventPrefix = 'ecinternet_rapidwebsync_log';

    protected $_eventObject = 'log';

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\Log::class);
    }

    /**
     * @inheritDoc
     */
    public function getIdentities()
    {
        return [self::CACHE_TAG . '_' . $this->getId()];
    }

    /**
     * @inheritDoc
     */
    public function getId()
    {
        return $this->getData(self::COLUMN_ID);
    }

    /**
     * @inheritDoc
     */
    public function getSyncOperation()
    {
        return $this->getData(self::COLUMN_SYNC_OPERATION);
    }

    /**
     * @inheritDoc
     */
    public function setSyncOperation(int $syncOperation)
    {
        $this->setData(self::COLUMN_SYNC_OPERATION, $syncOperation);
    }

    /**
     * @inheritDoc
     */
    public function getTransformId()
    {
        return $this->getData(self::COLUMN_TRANSFORM_ID);
    }

    /**
     * @inheritDoc
     */
    public function setTransformId(string $transformId)
    {
        $this->setData(self::COLUMN_TRANSFORM_ID, $transformId);
    }

    /**
     * @inheritDoc
     */
    public function getJobId()
    {
        return $this->getData(self::COLUMN_JOB_ID);
    }

    /**
     * @inheritDoc
     */
    public function setJobId(string $jobId)
    {
        $this->setData(self::COLUMN_JOB_ID, $jobId);
    }

    /**
     * @inheritDoc
     */
    public function getDuration()
    {
        return $this->getData(self::COLUMN_DURATION);
    }

    /**
     * @inheritDoc
     */
    public function setDuration(int $duration)
    {
        $this->setData(self::COLUMN_DURATION, $duration);
    }

    /**
     * @inheritDoc
     */
    public function getCountIn()
    {
        return $this->getData(self::COLUMN_COUNT_IN);
    }

    /**
     * @inheritDoc
     */
    public function setCountIn(int $countIn)
    {
        $this->setData(self::COLUMN_COUNT_IN, $countIn);
    }

    /**
     * @inheritDoc
     */
    public function getCountOut()
    {
        return $this->getData(self::COLUMN_COUNT_OUT);
    }

    /**
     * @inheritDoc
     */
    public function setCountOut(int $countOut)
    {
        $this->setData(self::COLUMN_COUNT_OUT, $countOut);
    }

    /**
     * @inheritDoc
     */
    public function getWarningCount()
    {
        return $this->getData(self::COLUMN_WARNING_COUNT);
    }

    /**
     * @inheritDoc
     */
    public function setWarningCount(int $warningCount)
    {
        $this->setData(self::COLUMN_WARNING_COUNT, $warningCount);
    }

    /**
     * @inheritDoc
     */
    public function getErrorCount()
    {
        return $this->getData(self::COLUMN_ERROR_COUNT);
    }

    /**
     * @inheritDoc
     */
    public function setErrorCount(int $errorCount)
    {
        $this->setData(self::COLUMN_ERROR_COUNT, $errorCount);
    }
}
