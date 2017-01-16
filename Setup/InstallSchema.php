<?php

namespace SnowIO\IdempotentAPI\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class InstallSchema implements InstallSchemaInterface
{

    /**
     * Installs DB schema for a module
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();
        $table = $installer->getConnection()->newTable(
            $installer->getTable('webapi_resource_modification_log')
        )->addColumn(
            'identifier',
            Table::TYPE_TEXT,
            255,
            ['primary' => true, 'nullable' => false],
            'Resource Identifier'
        )->addColumn(
            'timestamp',
            Table::TYPE_DECIMAL,
            null,
            ['unsigned' => true, 'nullable' => false],
            'Timestamp'
        );
        $installer->getConnection()->createTable($table);
        $installer->endSetup();
    }
}