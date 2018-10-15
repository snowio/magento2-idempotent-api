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
            $installer->getTable('webapi_message_group')
        )->addColumn(
            'id',
            Table::TYPE_TEXT,
            255,
            ['primary' => true, 'nullable' => false],
            'Message Group ID'
        )->addColumn(
            'timestamp',
            Table::TYPE_TEXT,
            255,
            ['unsigned' => true, 'nullable' => false],
            'Message Timestamp'
        )->addColumn(
            'version',
            Table::TYPE_INTEGER,
            255,
            ['unsigned' => true, 'nullable' => false, 'default' => 1],
            'Version'
        );
        $installer->getConnection()->createTable($table);
        $installer->endSetup();
    }
}
