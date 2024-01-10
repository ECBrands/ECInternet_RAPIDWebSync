<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Api;

use ECInternet\RAPIDWebSync\Api\Data\LogInterface;

interface LogRepositoryInterface
{
    /**
     * Save Log
     *
     * @param \ECInternet\RAPIDWebSync\Api\Data\LogInterface $log
     *
     * @return \ECInternet\RAPIDWebSync\Api\Data\LogInterface
     */
    public function save(LogInterface $log);
}
