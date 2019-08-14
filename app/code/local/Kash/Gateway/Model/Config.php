<?php

/**
 * Config model that is aware of all Kash_Gateway methods
 * Works with Payment BB system configuration
 *
 * @author  Blue Badger <jonathan@badger.blue>
 */
class Kash_Gateway_Model_Config
{
    /**
     * Config path for enabling/disabling order review step in express checkout
     */
    const XML_PATH_GATEWAY_KASH_SKIP_ORDER_REVIEW_STEP_FLAG = 'payment/kash_gateway/skip_order_review_step';

    /**
     * Website Payments Pro - BB Checkout
     * @var string
     */
    const METHOD_GATEWAY_KASH = 'kash_gateway';

    /**
     * URL for get request - BB Checkout
     * @var string
     */
    const REQUEST_GATEWAY_KASH = 'gateway/bb/getRequest';

    /**
     *  Discount code
     */
    const GATEWAY_KASH_DISCOUNT_CODE ='discount_gatewaykash';

    /**
     * Current payment method code
     * @var string
     */
    protected $_methodCode = null;

    /**
     * Current store id
     *
     * @var int
     */
    protected $_storeId = null;

    /**
     * Set method and store id, if specified
     *
     * @param array $params
     */
    public function __construct($params = array())
    {
        if ($params) {
            $method = array_shift($params);
            $this->setMethod($method);
            if ($params) {
                $storeId = array_shift($params);
                $this->setStoreId($storeId);
            }
        }
    }

    /**
     * Method code setter +/
     *
     * @param string|Mage_Payment_Model_Method_Abstract $method
     * @return Kash_Gateway_Model_Config
     */
    public function setMethod($method)
    {
        if ($method instanceof Mage_Payment_Model_Method_Abstract) {
            $this->_methodCode = $method->getCode();
        } elseif (is_string($method)) {
            $this->_methodCode = $method;
        }
        return $this;
    }

    /**
     * Payment method instance code getter +/
     *
     * @return string
     */
    public function getMethodCode()
    {
        return $this->_methodCode;
    }

    /**
     * Store ID setter
     *
     * @param int $storeId
     * @return Kash_Gateway_Model_Config
     */
    public function setStoreId($storeId)
    {
        $this->_storeId = (int)$storeId;
        return $this;
    }


    /**
     * Config field magic getter +/
     * The specified key can be either in camelCase or under_score format
     * Tries to map specified value according to set payment method code, into the configuration value
     * Sets the values into public class parameters, to avoid redundant calls of this method
     *
     * @param string $key
     * @return string|null
     */
    public function __get($key)
    {
        $underscored = strtolower(preg_replace('/(.)([A-Z])/', "$1_$2", $key));
        $value = Mage::getStoreConfig($this->_mapMethodFieldset($underscored), $this->_storeId);
        $this->$key = $value;
        $this->$underscored = $value;
        return $value;
    }

    /**
     * Map Payment BB General Settings
     * +/
     * @param string $fieldName
     * @return string|null
     */
    protected function _mapMethodFieldset($fieldName)
    {
        if (!$this->_methodCode) {
            return null;
        }
        return "payment/{$this->_methodCode}/{$fieldName}";
    }

    /**
     * Check whether order review step enabled in configuration
     *
     * @return bool
     */
    public function isOrderReviewStepDisabled()
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_GATEWAY_KASH_SKIP_ORDER_REVIEW_STEP_FLAG);
    }
}

