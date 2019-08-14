<?php

/**
 * Payment BB "form"
 *
 * @author  Blue Badger <jonathan@badger.blue>
 */
class Kash_Gateway_Block_Form_Bb extends Mage_Payment_Block_Form
{
    /**
     * Payment method code
     * @var string
     */
    protected $_methodCode = Kash_Gateway_Model_Config::METHOD_GATEWAY_KASH;

    /**
     * Config model instance
     *
     * @var Kash_Gateway_Model_Config
     */
    protected $_config;

    /**
     * Set template and redirect message
     */
    protected function _construct()
    {
        $this->_config = Mage::getModel('kash_gateway/config')->setMethod($this->getMethodCode());
        $locale = Mage::app()->getLocale();
        $mark = Mage::getConfig()->getBlockClassName('core/template');
        $mark = new $mark;
        $mark->setTemplate('kash/payment/mark.phtml')
            ->setPaymentImageSrc($this->_config->getPaymentImageUrl($locale->getLocaleCode()))
            ->setMessage(
                Mage::helper('kash_gateway')->__($this->_config->title)
            );
        $this->setTemplate('kash/payment/redirect.phtml')
            ->setRedirectMessage(
                Mage::helper('kash_gateway')->__('')
            )
            ->setMethodTitle('')
            ->setMethodLabelAfterHtml($mark->toHtml())
        ;
        $result = parent::_construct();
        return $result;
    }

    /**
     * Set data to block
     *
     * @return Mage_Core_Block_Abstract
     */
    protected function _beforeToHtml()
    {
        $customerId = Mage::getSingleton('customer/session')->getCustomerId();
        return parent::_beforeToHtml();
    }

    public function getMethodCode()
    {
        return $this->_methodCode;
    }
}
