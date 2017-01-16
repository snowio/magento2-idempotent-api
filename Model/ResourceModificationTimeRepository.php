<?php

namespace SnowIO\IdempotentAPI\Model;

use Magento\Framework\Model\ResourceModel\Db\Context;

class ResourceModificationTimeRepository
{
    private $dbConnection;

    public function __construct(Context $dbContext, $connectionName = null)
    {
        $this->dbConnection = $dbContext->getResources()->getConnection($connectionName);
    }

    public function getLastModificationTime(string $identifier) : string
    {
        $identifier = md5($identifier);
        $select = $this->dbConnection->select()
            ->from(['t' => $this->dbConnection->getTableName('webapi_resource_modification_log')], 'timestamp')
            ->where('t.identifier = ?', $identifier);
        $result = $this->dbConnection->fetchOne($select);

        return $result ? (string)$result : false;

    }

    public function updateModificationTime(string $identifier, string $modificationTime, string $expectedModificationTime = null)
    {
        $identifier = md5($identifier);
        $this->dbConnection->insert($this->dbConnection->getTableName('webapi_resource_modification_log'), [
           'identifier' => $identifier,
            'timestamp' => $modificationTime
        ]);
    }
}
