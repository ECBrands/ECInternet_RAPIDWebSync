<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Api\Data;

interface LogInterface
{
    public const COLUMN_ID             = 'entity_id';

    public const COLUMN_CREATED_AT     = 'created_at';

    public const COLUMN_UPDATED_AT     = 'updated_at';

    public const COLUMN_SYNC_OPERATION = 'sync_operation';

    public const COLUMN_TRANSFORM_ID   = 'transform_id';

    public const COLUMN_JOB_ID         = 'job_id';

    public const COLUMN_DURATION       = 'duration_ms';

    public const COLUMN_COUNT_IN       = 'count_in';

    public const COLUMN_COUNT_OUT      = 'count_out';

    public const COLUMN_WARNING_COUNT  = 'warning_count';

    public const COLUMN_ERROR_COUNT    = 'error_count';

    public const SYNC_OPERATION_INSERT = 1;

    public const SYNC_OPERATION_UPDATE = 2;

    public const SYNC_OPERATION_UPSERT = 3;

    /**
     * Get ID
     *
     * @return mixed
     */
    public function getId();

    /**
     * Get sync operation
     *
     * @return string
     */
    public function getSyncOperation();

    /**
     * Set sync operation
     *
     * @param int $syncOperation
     *
     * @return void
     */
    public function setSyncOperation(int $syncOperation);

    /**
     * Get transform ID
     *
     * @return string
     */
    public function getTransformId();

    /**
     * Set transform ID
     *
     * @param string $transformId
     *
     * @return void
     */
    public function setTransformId(string $transformId);

    /**
     * Get job ID
     *
     * @return string
     */
    public function getJobId();

    /**
     * Set job ID
     *
     * @param string $jobId
     *
     * @return void
     */
    public function setJobId(string $jobId);

    /**
     * Get duration
     *
     * @return int
     */
    public function getDuration();

    /**
     * Set duration
     *
     * @param int $duration
     *
     * @return void
     */
    public function setDuration(int $duration);

    /**
     * Get count in
     *
     * @return int
     */
    public function getCountIn();

    /**
     * Set count in
     *
     * @param int $countIn
     *
     * @return void
     */
    public function setCountIn(int $countIn);

    /**
     * Get count out
     *
     * @return int
     */
    public function getCountOut();

    /**
     * Set count out
     *
     * @param int $countOut
     *
     * @return void
     */
    public function setCountOut(int $countOut);

    /**
     * Get warning count
     *
     * @return int
     */
    public function getWarningCount();

    /**
     * Set warning count
     *
     * @param int $warningCount
     *
     * @return void
     */
    public function setWarningCount(int $warningCount);

    /**
     * Get error count
     *
     * @return int
     */
    public function getErrorCount();

    /**
     * Set error count
     *
     * @param int $errorCount
     *
     * @return void
     */
    public function setErrorCount(int $errorCount);
}
