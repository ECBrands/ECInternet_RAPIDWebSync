<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */

/** @noinspection PhpPropertyOnlyWrittenInspection */

declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Cron;

use ECInternet\RAPIDWebSync\Helper\Db;

class ProductRewriteDataReport
{
    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Db
     */
    private $_db;

    /**
     * ProductRewriteDataReport constructor.
     *
     * @param \ECInternet\RAPIDWebSync\Helper\Db $db
     */
    public function __construct(
        Db $db
    ) {
        $this->_db = $db;
    }

    public function execute()
    {
    }
}
