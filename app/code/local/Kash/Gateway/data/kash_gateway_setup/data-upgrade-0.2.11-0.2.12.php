<?php

/* @var $installer Mage_Core_Model_Resource_Setup */
$installer = $this;

$installer->startSetup();
try{
    $coupon = Mage::getModel('salesrule/coupon')->loadByCode('discount_gatewaykash');
    if(!$coupon->isEmpty()){
        $ruleCoupon = Mage::getModel('salesrule/rule')->load($coupon->getRuleId(), 'rule_id');
        $ruleCoupon->delete();
        $coupon->delete();
    }
}catch (Exception $ex){
    Mage::logException($ex);
}
$installer->endSetup();