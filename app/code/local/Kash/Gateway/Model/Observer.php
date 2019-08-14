<?php

class Kash_Gateway_Model_Observer extends Varien_Object
{
    /**
     * Do not show discount in admin panel
     *
     * @param Varien_Object $observer
     * @return Mage_Centinel_Model_Observer
     */
    public function coreCollectionAbstractLoadBefore($observer)
    {
        if (Mage::app()->getRequest()->getRouteName() == 'adminhtml' &&
            Mage::app()->getRequest()->getControllerName() == 'promo_quote'
        ) {
            if ($observer->getCollection() instanceof Mage_SalesRule_Model_Resource_Rule_Collection) {
                $select = $observer->getCollection()->getSelect();
                $select->where('code NOT IN (?)', Kash_Gateway_Model_Config::GATEWAY_KASH_DISCOUNT_CODE);
                $select->orWhere('code IS NULL');
            }
        }
        return $this;
    }

    /**
     * Do not take the coupon with frontend for customers
     *
     * @param Varien_Object $observer
     * @return Mage_Centinel_Model_Observer
     */
    public function salesQuoteCollectTotalsBefore($observer)
    {
        $quote = $observer->getQuote();
        $discount = $quote->getCouponCode();
        if ($discount === Kash_Gateway_Model_Config::GATEWAY_KASH_DISCOUNT_CODE) {
            $params = Mage::app()->getRequest()->getParams();
            $param = array_key_exists('coupon_code', $params) ? $params['coupon_code'] : null;
            if (Mage::app()->getRequest()->getRouteName() == 'checkout' ||
                $param == $discount) {
                $quote->setCouponCode('');
            }
        }
    }

}

