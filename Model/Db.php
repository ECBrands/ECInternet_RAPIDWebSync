<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Psr\Log\LoggerInterface;
use Zend_Db_Statement_Exception;
use Zend_Db_Statement_Interface;

/**
 * @SuppressWarnings(PHPMD.ShortClassName)
 */
class Db
{
    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private $connection;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * Db constructor.
     *
     * @param \Magento\Framework\App\ResourceConnection $resourceConnection
     * @param \Psr\Log\LoggerInterface                  $logger
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        LoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->connection         = $resourceConnection->getConnection();
        $this->logger             = $logger;
    }

    /**
     * Begin new DB transaction
     */
    public function beginTransaction()
    {
        $this->connection->beginTransaction();
    }

    /**
     * Commit DB transaction
     */
    public function commit()
    {
        $this->connection->commit();
    }

    /**
     * Roll-back DB transaction
     */
    public function rollBack()
    {
        $this->connection->rollBack();
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
        return $this->resourceConnection->getTableName($tableName);
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
        return $this->connection->isTableExists($tableName);
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
        return $this->connection->fetchAll($query, $params);
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
        return $this->connection->fetchRow($query, $params);
    }

    /**
     * @param \Magento\Framework\DB\Select $query
     *
     * @return array
     */
    public function fetchCol(Select $query)
    {
        return $this->connection->fetchCol($query);
    }

    /**
     * Inserts table rows
     *
     * @param string $query
     * @param array  $params
     *
     * @return int|null
     */
    public function insert(string $query, array $params = [])
    {
        try {
            /** @var Zend_Db_Statement_Interface $result */
            $result = $this->connection->query($query, $params);
            $this->log('insert()', ['rowCount' => $result->rowCount()]);

            $lastInsertId = (int)$this->connection->lastInsertId();
            $this->log('insert()', ['lastInsertId' => $lastInsertId]);

            return $lastInsertId;
        } catch (Zend_Db_Statement_Exception $e) {
            $this->log('insert()', [
                'query'     => $query,
                'params'    => $params,
                'exception' => $e
            ]);
        }

        return null;
    }

    /**
     * Updates table rows
     *
     * @param string $query
     * @param array  $params
     *
     * @return Zend_Db_Statement_Interface|null
     */
    public function update(string $query, array $params = [])
    {
        try {
            /** @var Zend_Db_Statement_Interface $result */
            $result = $this->connection->query($query, $params);
            $this->log('update()', ['rowCount' => $result->rowCount()]);

            return $result;
        } catch (Zend_Db_Statement_Exception $e) {
            $this->log('update()', [
                'query'     => $query,
                'params'    => $params,
                'exception' => $e
            ]);
        }

        return null;
    }

    /**
     * Deletes table rows
     *
     * @param string $query
     * @param array  $params
     *
     * @return Zend_Db_Statement_Interface
     */
    public function delete(string $query, array $params = [])
    {
        try {
            $result = $this->connection->query($query, $params);
            $this->log('delete()', ['rowCount' => $result->rowCount()]);

            return $result;
        } catch (Zend_Db_Statement_Exception $e) {
            $this->log('delete()', [
                'query'     => $query,
                'params'    => $params,
                'exception' => $e
            ]);
        }
    }

    /**
     * Execute an SQL query
     *
     * @param string $query
     *
     * @return Zend_Db_Statement_Interface
     */
    public function execute(string $query)
    {
        return $this->connection->query($query);
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
        $select = $this->connection->select()
            ->from($tableName, ['linked_product_id'])
            ->where('product_id = ?', $productId)
            ->where('link_type_id = ?', $linkTypeId);

        return $this->fetchCol($select);
    }

    /**
     * Write to extension log
     *
     * @param string $message
     * @param array  $extra
     */
    private function log(string $message, array $extra = [])
    {
        $this->logger->info('Model/Db - ' . $message, $extra);
    }
}
