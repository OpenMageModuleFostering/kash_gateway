<?php

require_once(Mage::getModuleDir('kashlib', 'Kash_Gateway').'/kashlib/KashApi.php');

class Kash_Gateway_Model_Offsite extends Mage_Payment_Model_Method_Abstract
{
    protected $_code  = Kash_Gateway_Model_Config::METHOD_GATEWAY_KASH;
    protected $_formBlockType = 'kash_gateway/form_bb';
    protected $_infoBlockType = 'kash_gateway/adminhtml_info';

    protected $_canUseInternal              = false;
    protected $_canRefund                   = true;
    protected $_canRefundInvoicePartial     = true;

    /**
     * Config instance
     *
     * @var Kash_Gateway_Model_Config
     */
    protected $_config = null;

    /**
     * Config model type
     *
     * @var string
     */
    protected $_configType = 'kash_gateway/config';

    public function __construct()
    {
        $this->_config = Mage::getModel($this->_configType, array($this->_code));
    }

    /**
     * Config instance setter
     *
     * @param Kash_Gateway_Model_Config $instace
     * @param int $storeId
     * @return $this
     */
    public function setConfig(Kash_Gateway_Model_Config $instace, $storeId = null)
    {
        $this->_config = $instace;
        if (null !== $storeId) {
            $this->_config->setStoreId($storeId);
        }
        return $this;
    }

    /**
     * Config instance getter
     *
     * @return Kash_Gateway_Model_Config
     */
    public function getConfig()
    {
        return $this->_config;
    }


    /**
     * Store setter
     * Also updates store ID in config object
     *
     * @param Mage_Core_Model_Store|int $store
     * @return Kash_Gateway_Model_Offsite
     */
    public function setStore($store)
    {
        $this->setData('store', $store);
        if (null === $store) {
            $store = Mage::app()->getStore()->getId();
        }
        $this->_config->setStoreId(is_object($store) ? $store->getId() : $store);
        return $this;
    }

    /**
     * Custom getter for payment configuration
     *
     * @param string $field
     * @param int $storeId
     * @return mixed
     */
    public function getConfigData($field, $storeId = null)
    {
        return $this->_config->$field;
    }

    /**
     * Checkout redirect URL getter for onepage checkout (hardcode)
     *
     * @see Mage_Checkout_OnepageController::savePaymentAction()
     * @see Mage_Sales_Model_Quote_Payment::getCheckoutRedirectUrl()
     * @return string
     */
    public function getCheckoutRedirectUrl()
    {
        return Mage::getUrl('kash_gateway/offsite/start', array('_secure'=>true));
    }

    /**
     * Whether can get recurring profile details
     */
    public function canGetRecurringProfileDetails()
    {
        return true;
    }

    /**
     * Refund specified amount for payment
     *
     * @param Varien_Object $payment
     * @param float $amount
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function refund(Varien_Object $payment, $amount)
    {
        $kashTransactionId = $payment->getCreditmemo()->getInvoice()->getTransactionId();
        $logger = Mage::helper('kash_gateway')->logger();
        $logger->log('refunding $' . $amount . ' for ' . $kashTransactionId);

        $gatewayUrl = $this->_config->post_url;
        $serverKey = $this->_config->server_key;
        $kashApi = new KashApi($gatewayUrl, $serverKey);

        $result = $kashApi->refund($kashTransactionId, $amount);
        if ($result->statusCode !== 200) {
            Mage::throwException(Mage::helper('kash_gateway')->__($result->body->message));
        }

        return $this;
    }

}
