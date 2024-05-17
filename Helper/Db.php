<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use ECInternet\RAPIDWebSync\Logger\Logger;

/**
 * Db Helper
 */
class Db extends AbstractHelper
{
    const CONFIG_PATH_ENABLE_QUERY_LOGGING = 'rapid_web_sync/general/query_logging';

    /**
     * @var \ECInternet\RAPIDWebSync\Logger\Logger
     */
    protected $_logger;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $_resourceConnection;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private $_connection;

    /**
     * Db constructor.
     *
     * @param \Magento\Framework\App\Helper\Context     $context
     * @param \Magento\Framework\App\ResourceConnection $resourceConnection
     * @param \ECInternet\RAPIDWebSync\Logger\Logger    $logger
     */
    public function __construct(
        Context $context,
        ResourceConnection $resourceConnection,
        Logger $logger
    ) {
        parent::__construct($context);

        $this->_resourceConnection = $resourceConnection;
        $this->_connection         = $resourceConnection->getConnection();
        $this->_logger             = $logger;
    }

    /**
     * Begin new DB transaction
     */
    public function beginTransaction()
    {
        $this->_connection->beginTransaction();
    }

    /**
     * Commit DB transaction
     */
    public function commit()
    {
        $this->_connection->commit();
    }

    /**
     * Roll-back DB transaction
     */
    public function rollBack()
    {
        $this->_connection->rollBack();
    }

    /**
     * Get resource table name, validated by db adapter.
     *
     * @param string $tableName
     *
     * @return string
     */
    public function getTableName(string $tableName)
    {
        return $this->_resourceConnection->getTableName($tableName);
    }

    /**
     * Checks if table exists
     *
     * @param string $tableName
     *
     * @return bool
     */
    public function doesTableExist(string $tableName)
    {
        return $this->_connection->isTableExists($tableName);
    }

    /**
     * Get string array of table column names
     *
     * @param string $tableName
     *
     * @return string[]
     */
    public function getTableColumns(string $tableName)
    {
        $columns = [];

        $table = $this->getTableName($tableName);
        $query = "DESCRIBE $table";

        $results = $this->select($query);
        foreach ($results as $result) {
            $columns[] = $result['Field'];
        }

        return $columns;
    }

    /**
     * Gets table rows
     *
     * @param string $query
     * @param array  $params
     *
     * @return array
     */
    public function select(string $query, array $params = [])
    {
        $this->logQuery($query, $params);

        return $this->_connection->fetchAll($query, $params);
    }

    /**
     * Gets the value of a particular field from the first query result
     *
     * @param string $query
     * @param array  $params
     * @param string $column
     *
     * @return mixed|null
     */
    public function selectOne(string $query, array $params, string $column)
    {
        $this->logQuery($query, $params);

        // fetchRow() returns the first row
        if ($record = $this->fetchRow($query, $params)) {
            if (isset($record[$column])) {
                return $record[$column];
            }
        }

        return null;
    }

    public function fetchRow(string $query, array $params = [])
    {
        $this->logQuery($query, $params);

        return $this->_connection->fetchRow($query, $params);
    }

    /**
     * @param \Magento\Framework\DB\Select $query
     *
     * @return array
     */
    public function fetchCol(\Magento\Framework\DB\Select $query)
    {
        $this->logQuery($query->assemble());

        return $this->_connection->fetchCol($query);
    }

    /**
     * Inserts table rows
     *
     * @param string $query
     * @param array  $params
     *
     * @return int
     */
    public function insert(string $query, array $params = [])
    {
        $this->logQuery($query, $params);

        // Send INSERT query
        $this->_connection->query($query, $params);

        return (int)$this->_connection->lastInsertId();
    }

    /**
     * Updates table rows
     *
     * @param string $query
     * @param array  $params
     *
     * @return \Zend_Db_Statement_Interface|null
     */
    public function update(string $query, array $params = [])
    {
        $this->logQuery($query, $params);

        try {
            $result = $this->_connection->query($query, $params);
            $this->log('update()', ['rowCount' => $result->rowCount()]);

            return $result;
        } catch (\Zend_Db_Statement_Exception $e) {
            $this->log('update()', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Deletes table rows
     *
     * @param string $query
     * @param array  $params
     *
     * @return \Zend_Db_Statement_Interface
     */
    public function delete(string $query, array $params = [])
    {
        $this->logQuery($query, $params);

        return $this->_connection->query($query, $params);
    }

    /**
     * Execute an SQL query
     *
     * @param string $query
     *
     * @return \Zend_Db_Statement_Interface
     */
    public function execute(string $query)
    {
        $this->logQuery($query);

        return $this->_connection->query($query);
    }

    /**
     * Is the current store set to "single-store" mode?
     *
     * @return bool
     */
    public function isSingleStore()
    {
        $table = $this->getTableName('store');
        $query = "SELECT COUNT(`store_id`) as 'count' FROM `$table` WHERE `store_id` != 0";
        $binds = [];

        return $this->selectOne($query, $binds, 'count') == 1;
    }

    /**
     * Get product sku
     *
     * @param int $productId
     *
     * @return string
     */
    public function getProductSku(int $productId)
    {
        $table = $this->getTableName('catalog_product_entity');
        $query = "SELECT `sku` FROM `$table` WHERE `entity_id` = ?";
        $binds = [$productId];

        return (string)$this->selectOne($query, $binds, 'sku');
    }

    /**
     * Get product id
     *
     * @param string $sku
     *
     * @return int|null
     */
    public function getProductId(string $sku)
    {
        $table = $this->getTableName('catalog_product_entity');
        $query = "SELECT `entity_id` FROM `$table` WHERE `sku` = ?";
        $binds = [$sku];

        if ($result = $this->selectOne($query, $binds, 'entity_id')) {
            if (is_numeric($result)) {
                return (int)$result;
            }
        }

        return null;
    }

    /**
     * Get 'catalog_product_link' records
     *
     * @param int $productId
     * @param int $linkTypeId
     *
     * @return array
     */
    public function getLinkedProductIds(int $productId, int $linkTypeId)
    {
        $this->log('getLinkedProductIds()', [
            'productId'  => $productId,
            'linkTypeId' => $linkTypeId
        ]);

        $tableName = $this->getTableName('catalog_product_link');

        /** @var \Magento\Framework\DB\Select $select */
        $select = $this->_connection->select()
            ->from($tableName, ['linked_product_id'])
            ->where('product_id = ?', $productId)
            ->where('link_type_id = ?', $linkTypeId);

        return $this->fetchCol($select);
    }

    /**
     * Write the SQL query to log
     *
     * @param string $query
     * @param array  $binds
     *
     * @return void
     */
    private function logQuery(string $query, array $binds = [])
    {
        if ($this->isQueryLoggingEnabled()) {
            $this->log('--- SQL QUERY ---');
            $this->log('| QUERY', [$query]);
            $this->log('| BINDS', [$binds]);
            $this->log('--- SQL QUERY ---' . PHP_EOL);
        }
    }

    /**
     * Should we write to query log?
     *
     * @return bool
     */
    private function isQueryLoggingEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_ENABLE_QUERY_LOGGING);
    }

    /**
     * Write to extension log
     *
     * @param string $message
     * @param array  $extra
     */
    private function log(string $message, array $extra = [])
    {
        $this->_logger->info('DbHelper - ' . $message, $extra);
    }
}
