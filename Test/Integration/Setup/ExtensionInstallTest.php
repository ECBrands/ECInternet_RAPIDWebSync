<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Test\Integration\Setup;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

class ExtensionInstallTest extends TestCase
{
    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resourceConnection;

    protected function setUp(): void
    {
        $objectManager            = Bootstrap::getObjectManager();
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
    }

    // -------------------------------------------------------------------------
    // Custom tables (db_schema.xml)
    // -------------------------------------------------------------------------

    public function testLogTableWasCreated(): void
    {
        $connection = $this->getConnection();
        $table      = $this->resourceConnection->getTableName('ecinternet_rapidwebsync_log');

        $this->assertTrue($connection->isTableExists($table), 'ecinternet_rapidwebsync_log table should exist.');
        $this->assertTrue($connection->tableColumnExists($table, 'entity_id'),      'log.entity_id column should exist.');
        $this->assertTrue($connection->tableColumnExists($table, 'created_at'),     'log.created_at column should exist.');
        $this->assertTrue($connection->tableColumnExists($table, 'updated_at'),     'log.updated_at column should exist.');
        $this->assertTrue($connection->tableColumnExists($table, 'sync_operation'), 'log.sync_operation column should exist.');
        $this->assertTrue($connection->tableColumnExists($table, 'job_id'),         'log.job_id column should exist.');
        $this->assertTrue($connection->tableColumnExists($table, 'transform_id'),   'log.transform_id column should exist.');
        $this->assertTrue($connection->tableColumnExists($table, 'duration_ms'),    'log.duration_ms column should exist.');
        $this->assertTrue($connection->tableColumnExists($table, 'count_in'),       'log.count_in column should exist.');
        $this->assertTrue($connection->tableColumnExists($table, 'count_out'),      'log.count_out column should exist.');
        $this->assertTrue($connection->tableColumnExists($table, 'warning_count'),  'log.warning_count column should exist.');
        $this->assertTrue($connection->tableColumnExists($table, 'error_count'),    'log.error_count column should exist.');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getConnection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }
}
