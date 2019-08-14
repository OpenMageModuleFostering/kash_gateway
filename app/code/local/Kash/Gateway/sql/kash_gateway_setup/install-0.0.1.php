<?php

/* @var $installer Mage_Core_Model_Resource_Setup */
$installer = $this;

$installer->startSetup();

$installer->getConnection()
    ->addColumn($installer->getTable('sales/quote_payment'), 'x_gateway_reference', array(
        'type'    => Varien_Db_Ddl_Table::TYPE_TEXT,
        'comment' => 'Gateway Reference',
        'length'  => '255'
    ));

$installer->getConnection()
    ->addColumn($installer->getTable('sales/order_payment'), 'x_gateway_reference', array(
        'type'    => Varien_Db_Ddl_Table::TYPE_TEXT,
        'comment' => 'Gateway Reference',
        'length'  => '255'
    ));

$installer->endSetup();