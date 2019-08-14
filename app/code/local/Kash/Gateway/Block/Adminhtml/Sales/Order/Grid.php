<?php

class Kash_Gateway_Block_Adminhtml_Sales_Order_Grid extends Mage_Adminhtml_Block_Sales_Order_Grid {

    protected function _prepareCollection()
    {
        $config = Mage::getModel('kash_gateway/config', array(Kash_Gateway_Model_Config::METHOD_GATEWAY_KASH));
        $api = Mage::getModel('kash_gateway/api_bb')->setConfigObject($config);

        $collection = Mage::getResourceModel($this->_getCollectionClass());

        if ($api->shouldShowGatewayRef()) {
            $collection->join('sales/order_payment', '`main_table`.entity_id=`sales/order_payment`.parent_id', array('x_gateway_reference' =>'x_gateway_reference'), null,'left');
        }

        $this->setCollection($collection);
        return Mage_Adminhtml_Block_Widget_Grid::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        parent::_prepareColumns();

        $config = Mage::getModel('kash_gateway/config', array(Kash_Gateway_Model_Config::METHOD_GATEWAY_KASH));
        $api = Mage::getModel('kash_gateway/api_bb')->setConfigObject($config);

        if ($api->shouldShowGatewayRef()) {
            $this->addColumnAfter('x_gateway_reference', array(
                'header'    => Mage::helper('catalog')->__('Gateway Reference'),
                'index'     => 'x_gateway_reference',
                'type' => 'text'
            ),'sku');
        }
    }

}