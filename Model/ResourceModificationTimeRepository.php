<?php

namespace SnowIO\IdempotentAPI\Model;

use Magento\Framework\Model\ResourceModel\Db\Context;

class ResourceModificationTimeRepository
{
    private \Magento\Framework\DB\Adapter\AdapterInterface $dbConnection;

    public function __construct(\Magento\Framework\ObjectManager\ContextInterface $dbContext, $connectionName = null)
    {
        $this->dbConnection = $dbContext->getResources()->getConnection($connectionName);
    }

    public function getLastModificationTime(string $identifier)
    {
        $identifier = md5($identifier);
        $select = $this->dbConnection->select()
            ->from(['t' => $this->dbConnection->getTableName('webapi_resource_modification_log')], 'timestamp')
            ->where('t.identifier = ?', $identifier);
        $result = $this->dbConnection->fetchOne($select);

        return $result ? (int) $result : null;
    }

    public function updateModificationTime(
        string $identifier,
        string $modificationTime,
        string $expectedModificationTime = null
    ) {
        $identifier = md5($identifier);

        if ($expectedModificationTime !== null) {
            $rowsAffected = $this->dbConnection->update(
                $this->dbConnection->getTableName('webapi_resource_modification_log'),
                [
                    'timestamp' => $modificationTime
                ],
                ['identifier = ?' => $identifier, 'timestamp = ?' => $expectedModificationTime]
            );
        } else {
            //new resource that has never been modified
            $rowsAffected = $this->dbConnection->insert(
                $this->dbConnection->getTableName('webapi_resource_modification_log'),
                ['identifier' => $identifier, 'timestamp' => $modificationTime]
            );
        }

        if ($rowsAffected === 0 && ($modificationTime !== $expectedModificationTime)) {
            throw new \RuntimeException('There has been a conflict updating the web api resource');
        }
    }
}
