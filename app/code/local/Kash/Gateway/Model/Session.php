<?php
/**
 * Payment transaction session namespace
 */
class Kash_Gateway_Model_Session extends Mage_Core_Model_Session_Abstract
{
    public function __construct()
    {
        $this->init('payment');
    }
}
