<?php

/** *
 * Payment BB Module
 *
 * @author  Blue Badger <jonathan@badger.blue>
 */
class Kash_Gateway_Model_Method_Bb extends Mage_Payment_Model_Method_Abstract
{
    protected $_code  = Kash_Gateway_Model_Config::METHOD_GATEWAY_KASH;
    protected $_formBlockType = 'kash_gateway/form_bb';
    protected $_infoBlockType = 'kash_gateway/adminhtml_info';

    protected $_canUseInternal              = false;

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
     * @return Kash_Gateway_Model_Method_Bb
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
        return Mage::getUrl('kash_gateway/bb/start', array('_secure'=>true));
    }

    /**
     * Whether can get recurring profile details
     */
    public function canGetRecurringProfileDetails()
    {
        return true;
    }

}
