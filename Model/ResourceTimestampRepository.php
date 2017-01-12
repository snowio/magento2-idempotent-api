<?php

namespace SnowIO\IdempotentAPI\Model;

class ResourceTimestampRepository
{
    private $dbConnection;
    public function __construct(Context $dbContext, $connectionName = null)
    {
        $this->dbConnection = $dbContext->getResources()->getConnection($connectionName);
    }


    public function get(string $identifier) : array
    {
    }

    public function save(string $identifier, float $timestamp)
    {
    }
}
