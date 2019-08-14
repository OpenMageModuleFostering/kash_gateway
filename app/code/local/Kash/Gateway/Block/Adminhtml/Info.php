<?php
/**
 * Base payment iformation block
 *
 */
class Kash_Gateway_Block_Adminhtml_Info extends Mage_Payment_Block_Info
{
    /**
     * Payment rendered specific information
     *
     * @var Varien_Object
     */
    protected $_paymentSpecificInformation = null;

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('kash/info/default.phtml');
    }

    public function getAdditionalInformation(){
        return $this->getInfo()->getAdditionalInformation();
    }
}
