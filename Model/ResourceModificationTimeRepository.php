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

    public function getLastModification(string $identifier)
    {
        $identifier = md5($identifier);
        $select = $this->dbConnection->select()
            ->from(['t' => $this->dbConnection->getTableName('webapi_resource_modification_log')], ['timestamp', 'version'])
            ->where('t.identifier = ?', $identifier);
        $result = $this->dbConnection->fetchAssoc($select);

        return array_shift($result);
    }

    public function updateModificationTime(
        string $identifier,
        string $modificationTime,
        int $expectedVersion = null,
        string $expectedModificationTime = null
    ) {
        $identifier = md5($identifier);

        if ($expectedModificationTime !== null) {
            $rowsAffected = $this->dbConnection->update(
                $this->dbConnection->getTableName('webapi_resource_modification_log'),
                [
                    'timestamp' => $modificationTime,
                    'version' => $expectedVersion + 1
                ],
                ['identifier = ?' => $identifier, 'timestamp = ?' => $expectedModificationTime, 'version = ?' => $expectedVersion]
            );
        } else {
            $rowsAffected = $this->dbConnection->insert(
                $this->dbConnection->getTableName('webapi_resource_modification_log'),
                ['identifier' => $identifier, 'timestamp' => $modificationTime, 'version' => 1]
            );
        }

        if ($rowsAffected === 0) {
            throw new ConflictException;
        }
    }
}
