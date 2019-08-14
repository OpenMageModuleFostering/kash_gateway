<?php

/**
 * BB API wrappers model
 */
class Kash_Gateway_Model_Api_Bb extends Kash_Gateway_Model_Api_Abstract
{
    /**
     * Global public interface map
     * @var array
     */
    protected $_globalMap = array(
        // each call
        'x_amount' => 'amount',
        'x_account_id' => 'x_account_id',
        'x_currency' => 'currency_code',
        'x_amount_tax' => 'tax_amount',
        'x_amount_shipping' => 'shipping_amount',
        'x_shop_country' => 'x_shop_country',
        'x_shop_name' => 'x_shop_name',
        'x_transaction_type' => 'x_transaction_type',
        'x_description' => 'x_description',
        'x_invoice' => 'x_invoice',
        'x_test' => 'x_test',
        // backwards compatibility
        'x_customer_first_name' => 'firstname',
        'x_customer_last_name' => 'lastname',
        'x_customer_shipping_country' => 'countrycode',
        'x_url_callback' => 'x_url_callback',
        'x_url_cancel' => 'x_url_cancel',
        'x_url_complete' => 'x_url_complete',
        'x_reference' => 'x_reference'
    );

    /**
     * Interface map for response
     * @var array
     */
    public $responseMap = array(
        'Amount' => 'x_amount',
        'Result' => 'x_result',
        'Gateway Reference' => 'x_gateway_reference',
        'Test mode' => 'x_test'
    );

    /**
     * Filter callbacks for preparing internal amounts to NVP request
     *
     * @var array
     */
    protected $_exportToRequestFilters = array(
        'x_amount' => '_filterAmount',
        'x_amount_shipping' => '_filterAmount',
        'x_amount_tax' => '_filterAmount',
        'x_test' => '_filterBool',
    );

    /**
     * Line items export mapping settings
     * @var array
     */
    protected $_lineItemTotalExportMap = array(
        Kash_Gateway_Model_Cart::TOTAL_TAX => 'x_amount_tax',
        Kash_Gateway_Model_Cart::TOTAL_SHIPPING => 'x_amount_shipping',
    );

    /**
     * SetBBCheckout request/response map
     * @var array
     */
    protected $_setBBCheckoutRequest = array(
        'x_reference', 'x_account_id', 'x_amount', 'x_currency', 'x_shop_country', 'x_shop_name', 'x_description', 'x_transaction_type',
        'x_invoice', 'x_test', 'x_amount_shipping', 'x_amount_tax', 'x_url_callback', 'x_url_cancel', 'x_url_complete'
    );

    /**
     * SetBBCheckout response map
     * @var array
     */
    protected $_setBBCheckoutResponse = array(
        'x_account_id', 'x_reference', 'x_currency', 'x_test', 'x_amount', 'x_gateway_reference', 'x_timestamp',
        'x_result', 'x_signature', 'x_amount_shipping', 'x_amount_tax'
    );

    /**
     * Map for billing address import/export
     * @var array
     */
    protected $_billingAddressMap = array(
        'x_customer_email' => 'email',
        'x_customer_first_name' => 'firstname',
        'x_customer_last_name' => 'lastname',
        'x_customer_shipping_country' => 'country_id', // iso-3166 two-character code
        'x_customer_shipping_state' => 'region',
        'x_customer_shipping_city' => 'city',
        'x_customer_shipping_address1' => 'street',
        'x_customer_shipping_address2' => 'street2',
        'x_customer_shipping_zip' => 'postcode',
        'x_customer_phone' => 'telephone',
    );

    /**
     * Map for billing address to do request (not response)
     * Merging with $_billingAddressMap
     *
     * @var array
     */
    protected $_billingAddressMapRequest = array();

    /**
     * Map for shipping address import/export (extends billing address mapper)
     * @var array
     */
    protected $_shippingAddressMap = array(
        'x_customer_shipping_country' => 'country_id',
        'x_customer_shipping_state' => 'region',
        'x_customer_shipping_city' => 'city',
        'x_customer_shipping_address1' => 'street',
        'x_customer_shipping_address2' => 'street2',
        'x_customer_shipping_zip' => 'postcode',
        'x_customer_phone' => 'telephone',
    );

    /**
     * Return request for API
     *
     */
    public function callSetBBCheckout()
    {
        $request = $this->_exportToRequest($this->_setBBCheckoutRequest);
        $this->_exportLineItems($request);

        // import/suppress shipping address, if any
        if ($this->getAddress()) {
            $request = $this->_importAddresses($request);
        }

        //make sure we have the email
        if (empty($request['x_customer_email'])) {
            try {
                $customer = Mage::getSingleton('customer/session')->getCustomer();
                $request['x_customer_email'] = $customer->getEmail();
            } catch (Exception $e) {
                Mage::log($e->getMessage());
            }
        }

        $date = Zend_Date::now();
        $request['x_timestamp'] = $date->getIso();
        $request['x_signature'] = $this->getSignature($request, $this->getHmacKey());

        return $request;
    }

    /**
     * Gateway signing mechanism
     *
     * @param array $request
     * @param $secret_key
     * @return string
     */
    public function getSignature(array $request, $secret_key)
    {
        ksort($request);
        $signature = '';
        foreach ($request as $key => $val) {
            if ($key === 'x_signature') {
                continue;
            }
            $signature .= $key . $val;
        }
        $sig = hash_hmac('sha256', $signature, $secret_key, false);
        return $sig;
    }


    /**
     * @param array $request
     * @return string
     */
    protected function _buildQuery($request)
    {
        return http_build_query($request);
    }

    /**
     * Prepare request data basing on provided addresses
     *   +/
     * @param array $to
     * @return array
     */
    protected function _importAddresses(array $to)
    {
        $billingAddress = ($this->getBillingAddress()) ? $this->getBillingAddress() : $this->getAddress();
        $shippingAddress = $this->getAddress();

        $to = Varien_Object_Mapper::accumulateByMap(
            $billingAddress,
            $to,
            array_merge(array_flip($this->_billingAddressMap), $this->_billingAddressMapRequest)
        );
        if ($regionCode = $this->_lookupRegionCodeFromAddress($billingAddress)) {
            $to['x_customer_shipping_state'] = $regionCode;
        }
        if (!$this->getSuppressShipping()) {
            $to = Varien_Object_Mapper::accumulateByMap($shippingAddress, $to, array_flip($this->_shippingAddressMap));
            if ($regionCode = $this->_lookupRegionCodeFromAddress($shippingAddress)) {
                $to['x_customer_shipping_state'] = $regionCode;
            }
            $this->_importStreetFromAddress($billingAddress, $to, 'x_customer_shipping_address1', 'x_customer_shipping_address2');
            $this->_importStreetFromAddress($shippingAddress, $to, 'x_customer_shipping_address1', 'x_customer_shipping_address2');
        }
        return $to;
    }

    protected function getXAccountId()
    {
        $value = $this->_getDataOrConfig('x_account_id');
        return $value;
    }

    protected function getXShopCountry()
    {
        $value = $this->_config->getMerchantCountry();
        return $value;
    }

    protected function getXShopName()
    {
        $value = $this->_getDataOrConfig('x_shop_name');
        return $value;
    }

    protected function getXTest()
    {
        $value = $this->_getDataOrConfig('x_test');
        return $value;
    }

    public function getHmacKey()
    {
        $value = $this->_getDataOrConfig('server_key');
        return $value;
    }
}
