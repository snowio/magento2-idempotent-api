<?php

namespace SnowIO\IdempotentAPI\Model;

use Magento\Framework\Model\ResourceModel\Db\Context;

class WebApiMessageGroupRepository
{
    private $dbConnection;

    public function __construct(Context $dbContext, $connectionName = null)
    {
        $this->dbConnection = $dbContext->getResources()->getConnection($connectionName);
    }

    public function getMessageGroup(string $id)
    {
        $id = md5($id);
        $select = $this->dbConnection->select()
            ->from(['t' => $this->dbConnection->getTableName('webapi_message_group')], ['timestamp', 'version'])
            ->where('t.id = ?', $id);
        $result = $this->dbConnection->fetchAssoc($select);

        return array_shift($result);
    }

    public function updateModificationTime(
        string $id,
        string $modificationTime,
        int $expectedVersion = null,
        string $expectedModificationTime = null
    ) {
        $id = md5($id);

        if ($expectedModificationTime !== null) {
            $rowsAffected = $this->dbConnection->update(
                $this->dbConnection->getTableName('webapi_message_group'),
                [
                    'timestamp' => $modificationTime,
                    'version' => $expectedVersion + 1
                ],
                ['id = ?' => $id, 'timestamp = ?' => $expectedModificationTime, 'version = ?' => $expectedVersion]
            );
        } else {
            $rowsAffected = $this->dbConnection->insert(
                $this->dbConnection->getTableName('webapi_message_group'),
                ['id' => $id, 'timestamp' => $modificationTime, 'version' => 1]
            );
        }

        if ($rowsAffected === 0) {
            throw new ConflictException;
        }
    }
}
