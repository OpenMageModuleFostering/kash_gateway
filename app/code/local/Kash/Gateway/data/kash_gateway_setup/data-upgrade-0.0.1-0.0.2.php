<?php

/* @var $installer Mage_Core_Model_Resource_Setup */
$installer = $this;

$installer->startSetup();

try{
    $coupon = Mage::getModel('salesrule/coupon')->loadByCode(Kash_Gateway_Model_Config::GATEWAY_KASH_DISCOUNT_CODE);
    if(!$coupon->isEmpty()){
        $ruleCoupon = Mage::getModel('salesrule/rule')->load($coupon->getRuleId(), 'rule_id');
        $ruleCoupon->delete();
        $coupon->delete();
    }
    $ruleCoupon = Mage::getModel('salesrule/rule');
    $ruleCoupon->setName('Discount for Payment BB')
        ->setDescription('this is a discount for payment bb')
        ->setFromDate(date('Y-m-d'))
        ->setCouponType(2)
        ->setCouponCode(Kash_Gateway_Model_Config::GATEWAY_KASH_DISCOUNT_CODE)
        ->setCustomerGroupIds(array(1))
        ->setIsActive(1)
        ->setStopRulesProcessing(0)
        ->setIsAdvanced(1)
        ->setProductIds('')
        ->setSortOrder(0)
        ->setSimpleAction('by_percent')
        //->setDiscountAmount(100)
        ->setDiscountQty(null)
        ->setDiscountStep('0')
        ->setSimpleFreeShipping('0')
        ->setApplyToShipping('0')
        ->setIsRss(0)
        ->setWebsiteIds(array(1));
    $ruleCoupon->save();
}catch (Exception $ex){
    Mage::logException($ex);
}

$installer->endSetup();