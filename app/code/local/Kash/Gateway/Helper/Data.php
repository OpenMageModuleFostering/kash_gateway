<?php

/**
 * This is a special Helper class that gets loaded if Mage::helper() asks only
 * for the namespace. E.g. Mage::helper('kash_gateway')
 *
 * Mage_Core_Model_Config::getHelperClassName() will automatically change the
 * requested 'kash_gateway' to 'kash_gateway/data'.
 */
class Kash_Gateway_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**
     * Helper function to retrieve the logger.
     * Use it via `Mage::helper('kash_gateway')->logger()`
     */
    public function logger()
    {
        $config = $this->config();
        $logger = Mage::getModel('kash_gateway/logger', array($config->x_shop_name));
        return $logger;
    }

    public function config()
    {
        $config = Mage::getModel('kash_gateway/config', array(Kash_Gateway_Model_Config::METHOD_GATEWAY_KASH));
        return $config;
    }
}
