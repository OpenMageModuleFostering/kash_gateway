<?php

/**
 * Wrapper that performs Payment BB and Checkout communication
 * Use current Payment BB method instance
 *
 * @author  Blue Badger <jonathan@badger.blue>
 */
class Kash_Gateway_Model_Checkout
{
    /**
     * @var Mage_Sales_Model_Quote
     */
    public $_quote = null;

    /**
     * Config instance
     * @var Kash_Gateway_Model_Config
     */
    protected $_config = null;

    /**
     * API instance
     * @var Kash_Gateway_Model_Api_Bb
     */
    protected $_api = null;

    /**
     * Api Model Type
     *
     * @var string
     */
    protected $_apiType = 'kash_gateway/api_bb';

    /**
     * Payment method type
     */
    protected $_methodType = Kash_Gateway_Model_Config::METHOD_GATEWAY_KASH;

    /**
     * Params response
     */
    protected $_params = null;

    /**
     * State helper variables
     * @var string
     */
    protected $_redirectUrl = '';
    protected $_pendingPaymentMessage = '';
    protected $_checkoutRedirectUrl = '';

    /**
     * @var Mage_Customer_Model_Session
     */
    protected $_customerSession;

    /**
     * Redirect urls supposed to be set to support giropay
     *
     * @var array
     */
    protected $_giropayUrls = array();

    /**
     * Customer ID
     *
     * @var int
     */
    protected $_customerId = null;

    /**
     * Order
     *
     * @var Mage_Sales_Model_Quote
     */
    protected $_order = null;

    /**
     * Set quote and config instances
     * @param array $params
     * @throws Exception
     */
    public function __construct($params = array())
    {
        if (isset($params['quote']) && $params['quote'] instanceof Mage_Sales_Model_Quote) {
            $this->_quote = $params['quote'];
        } else {
            throw new Exception('Quote instance is required.');
        }
        if (isset($params['config']) && $params['config'] instanceof Kash_Gateway_Model_Config) {
            $this->_config = $params['config'];
        } else {
            throw new Exception('Config instance is required.');
        }
        $this->_customerSession = isset($params['session']) && $params['session'] instanceof Mage_Customer_Model_Session
            ? $params['session'] : Mage::getSingleton('customer/session');
    }

    /**
     * Setter that enables giropay redirects flow
     *  +/
     * @param string $callbackUrl - payment callback
     * @param string $cancelUrl - payment cancellation result
     * @param string $completeUrl - complete payment result
     * @return $this
     */
    public function prepareGiropayUrls($callbackUrl, $cancelUrl, $completeUrl)
    {
        $this->_giropayUrls = array($callbackUrl, $cancelUrl, $completeUrl);
        return $this;
    }

    /**
     * Setter for customer Id
     *
     * @param int $id
     * @return $this
     */
    public function setCustomerId($id)
    {
        $this->_customerId = $id;
        return $this;
    }

    /**
     * Setter for customer
     *
     * @param Mage_Customer_Model_Customer $customer
     * @return $this
     */
    public function setCustomer($customer)
    {
        $this->_quote->assignCustomer($customer);
        $this->_customerId = $customer->getId();
        return $this;
    }

    /**
     * Setter for customer with billing and shipping address changing ability
     *
     * @param  Mage_Customer_Model_Customer $customer
     * @param  Mage_Sales_Model_Quote_Address $billingAddress
     * @param  Mage_Sales_Model_Quote_Address $shippingAddress
     * @return $this
     */
    public function setCustomerWithAddressChange($customer, $billingAddress = null, $shippingAddress = null)
    {
        $this->_quote->assignCustomerWithAddressChange($customer, $billingAddress, $shippingAddress);
        $this->_customerId = $customer->getId();
        return $this;
    }

    /**
     * Reserve order ID for specified quote and start checkout on Payment
     *
     * @return mixed
     */
    public function start()
    {
        $this->getApi()->log('quote '.$this->_quote->getId().': start()');
        $this->_quote->collectTotals();

        if (!$this->_quote->getGrandTotal() && !$this->_quote->hasNominalItems()) {
            $this->getApi()->log('quote '.$this->_quote->getId().': Error, quote has items, but no amount.');
            Mage::throwException(Mage::helper('kash_gateway')->__('Payment does not support processing orders with zero amount. To complete your purchase, proceed to the standard checkout process.'));
        }

        $this->_quote->reserveOrderId()->save();
        $this->getApi()->log('quote '.$this->_quote->getId().' reserved orderId/x_reference '.$this->_quote->getReservedOrderId());

        // prepare API
        $this->getApi()->setAmount($this->_quote->getGrandTotal())
            ->setCurrencyCode($this->_quote->getBaseCurrencyCode())
            ->setXInvoice($this->_quote->getReservedOrderId());
        list($callbackUrl, $cancelUrl, $completeUrl) = $this->_giropayUrls;
        $this->getApi()->addData(array(
            'x_url_callback' => $callbackUrl,
            'x_url_cancel' => $cancelUrl,
            'x_url_complete' => $completeUrl,
            'x_transaction_type' => 'sale',
            'x_description' => 'Order #' . $this->_quote->getReservedOrderId(),
            'x_reference' => $this->_quote->getReservedOrderId(),
        ));

        // Always set billing address. This is always needed so that we can process payments.
        $billingAddress = $this->_quote->getBillingAddress();
        if ($billingAddress->validate() === true) {
            $this->getApi()->setBillingAddress($billingAddress);
        }
        else {
            $this->getApi()->log('x_reference '.$this->_quote->getReservedOrderId().': Could not validate billing address');
        }

        // Set shipping address unless the product is a virtual product
        if ($this->_quote->getIsVirtual()) {
            $this->getApi()->setSuppressShipping(true);
        } else {
            // Set shipping address
            $shippingAddress = $this->_quote->getShippingAddress();
            if ($shippingAddress->validate() === true) {
                $this->getApi()->setAddress($shippingAddress);
            }
        }

        // add line items
        $paymentCart = Mage::getModel('kash_gateway/cart', array($this->_quote));
        $this->getApi()->setPaymentCart($paymentCart);

        // call API and redirect with token
        $token = $this->getApi()->callSetBBCheckout();
        $this->_redirectUrl = $this->_config->post_url;

        return $token;
    }

    /**
     * Check whether system can skip order review page before placing order
     * +/
     * @return bool
     */
    public function canSkipOrderReviewStep()
    {
        return $this->_config->isOrderReviewStepDisabled();
    }

    /**
     * Set shipping method to quote, if needed
     * @param string $methodCode
     */
    public function updateShippingMethod($methodCode)
    {
        if (!$this->_quote->getIsVirtual() && $shippingAddress = $this->_quote->getShippingAddress()) {
            if ($methodCode != $shippingAddress->getShippingMethod()) {
                $this->_ignoreAddressValidation();
                $shippingAddress->setShippingMethod($methodCode)->setCollectShippingRates(true);
                $this->_quote->collectTotals()->save();
            }
        }
    }

    /**
     * Place the order and recurring payment profiles when customer returned from payment
     * Until this moment all quote data must be valid
     */
    public function place()
    {
        $isNewCustomer = false;
        switch ($this->getCheckoutMethod()) {
            case Mage_Checkout_Model_Type_Onepage::METHOD_GUEST:
                $this->_prepareGuestQuote();
                break;
            case Mage_Checkout_Model_Type_Onepage::METHOD_REGISTER:
                $this->_prepareNewCustomerQuote();
                $isNewCustomer = true;
                break;
            default:
                $this->_prepareCustomerQuote();
                break;
        }

        $this->setAdditionalInformation();
        $this->_ignoreAddressValidation();
        $this->_applyDiscount();
        $this->_quote->collectTotals();
        $service = Mage::getModel('sales/service_quote', $this->_quote);
        $service->submitAll();
        $this->_quote->save();

        if ($isNewCustomer) {
            try {
                $this->_involveNewCustomer();
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }

        /** @var $order Mage_Sales_Model_Order */
        $order = $service->getOrder();
        if (!$order) {
            return;
        }

        $this->_order = $order;
    }

    /**
     * Set additional information
     */
    public function setAdditionalInformation()
    {
        $payment = $this->_quote->getPayment();
        $payment->setXGatewayReference($this->getParams('x_gateway_reference'));

        $params = $this->getParams();
        $result = array();
        foreach ($this->getApi()->responseMap as $key => $val) {
            $result[$key] = $params[$val];
        }
        $payment->setAdditionalInformation($result);
    }

    /** +/
     * Make sure addresses will be saved without validation errors
     */
    public function _ignoreAddressValidation()
    {
        $this->_quote->getBillingAddress()->setShouldIgnoreValidation(true);
        if (!$this->_quote->getIsVirtual()) {
            $this->_quote->getShippingAddress()->setShouldIgnoreValidation(true);
            if (!$this->_quote->getBillingAddress()->getEmail()) {
                $this->_quote->getBillingAddress()->setSameAsBilling(1);
            }
        }
    }

    /**
     * Determine whether redirect somewhere specifically is required
     *
     * @return string
     */
    public function getRedirectUrl()
    {
        return $this->_redirectUrl;
    }

    /**
     * Return order
     *  +/
     * @return Mage_Sales_Model_Order
     */
    public function getOrder()
    {
        return $this->_order;
    }

    /**
     * Get checkout method
     *  +/
     * @return string
     */
    public function getCheckoutMethod()
    {
        if ($this->getCustomerSession()->isLoggedIn()) {
            return Mage_Checkout_Model_Type_Onepage::METHOD_CUSTOMER;
        }
        if (!$this->_quote->getCheckoutMethod()) {
            if (Mage::helper('checkout')->isAllowedGuestCheckout($this->_quote)) {
                $this->_quote->setCheckoutMethod(Mage_Checkout_Model_Type_Onepage::METHOD_GUEST);
            } else {
                $this->_quote->setCheckoutMethod(Mage_Checkout_Model_Type_Onepage::METHOD_REGISTER);
            }
        }
        return $this->_quote->getCheckoutMethod();
    }

    /** +/
     * @return Kash_Gateway_Model_Api_Bb
     */
    public function getApi()
    {
        if (null === $this->_api) {
            $this->_api = Mage::getModel($this->_apiType)->setConfigObject($this->_config);
        }
        return $this->_api;
    }

    /**
     * Prepare quote for guest checkout order submit
     *
     * @return $this
     */
    protected function _prepareGuestQuote()
    {
        $this->getApi()->log('x_reference '.$this->_quote->getReservedOrderId().': Preparing a quote for a GUEST user.');
        $quote = $this->_quote;
        $quote->setCustomerId(null)
            ->setCustomerEmail($quote->getBillingAddress()->getEmail())
            ->setCustomerIsGuest(true)
            ->setCustomerGroupId(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID);
        return $this;
    }

    /**
    *   Logins the user so orders can be created with the right account
    */
    public function loginUser() {
        $customerId = $this->_lookupCustomerId();
        $this->getCustomerSession()->loginById($customerId);
    }

    /**
     * Checks if customer with email coming from Express checkout exists
     *  +/
     * @return int
     */
    protected function _lookupCustomerId()
    {
        return Mage::getModel('customer/customer')
            ->setWebsiteId(Mage::app()->getWebsite()->getId())
            ->loadByEmail($this->_quote->getCustomerEmail())
            ->getId();
    }

    /**
     * Prepare quote for customer registration and customer order submit
     * and restore magento customer data from quote
     *
     * @return $this
     */
    protected function _prepareNewCustomerQuote()
    {
        $this->getApi()->log('x_reference '.$this->_quote->getReservedOrderId().': preparing a new customer quote');
        $quote = $this->_quote;
        $billing = $quote->getBillingAddress();
        $shipping = $quote->isVirtual() ? null : $quote->getShippingAddress();

        $customerId = $this->_lookupCustomerId();
        if ($customerId) {
            $this->getCustomerSession()->loginById($customerId);
            return $this->_prepareCustomerQuote();
        }

        $customer = $quote->getCustomer();
        /** @var $customer Mage_Customer_Model_Customer */
        $customerBilling = $billing->exportCustomerAddress();
        $customer->addAddress($customerBilling);
        $billing->setCustomerAddress($customerBilling);
        $customerBilling->setIsDefaultBilling(true);
        if ($shipping && !$shipping->getSameAsBilling()) {
            $customerShipping = $shipping->exportCustomerAddress();
            $customer->addAddress($customerShipping);
            $shipping->setCustomerAddress($customerShipping);
            $customerShipping->setIsDefaultShipping(true);
        } elseif ($shipping) {
            $customerBilling->setIsDefaultShipping(true);
        }

        if ($quote->getCustomerDob() && !$billing->getCustomerDob()) {
            $billing->setCustomerDob($quote->getCustomerDob());
        }

        if ($quote->getCustomerTaxvat() && !$billing->getCustomerTaxvat()) {
            $billing->setCustomerTaxvat($quote->getCustomerTaxvat());
        }

        if ($quote->getCustomerGender() && !$billing->getCustomerGender()) {
            $billing->setCustomerGender($quote->getCustomerGender());
        }

        Mage::helper('core')->copyFieldset('checkout_onepage_billing', 'to_customer', $billing, $customer);
        $customer->setEmail($quote->getCustomerEmail());
        $customer->setPrefix($quote->getCustomerPrefix());
        $customer->setFirstname($quote->getCustomerFirstname());
        $customer->setMiddlename($quote->getCustomerMiddlename());
        $customer->setLastname($quote->getCustomerLastname());
        $customer->setSuffix($quote->getCustomerSuffix());
        $customer->setPassword($customer->decryptPassword($quote->getPasswordHash()));
        $customer->setPasswordHash($customer->hashPassword($customer->getPassword()));
        $customer->save();
        $quote->setCustomer($customer);

        return $this;
    }

    /**
     * Prepare quote for customer order submit
     *    +/
     * @return Kash_Gateway_Model_Checkout
     */
    protected function _prepareCustomerQuote()
    {
        $this->getApi()->log('x_reference '.$this->_quote->getReservedOrderId().': preparing a quote for an existing customer');
        $quote = $this->_quote;
        $billing = $quote->getBillingAddress();
        $shipping = $quote->isVirtual() ? null : $quote->getShippingAddress();

        $customer = $this->getCustomerSession()->getCustomer();

        if (!$billing->getCustomerId() || $billing->getSaveInAddressBook()) {
            $customerBilling = $billing->exportCustomerAddress();
            $customer->addAddress($customerBilling);
            $billing->setCustomerAddress($customerBilling);
        }
        if ($shipping && ((!$shipping->getCustomerId() && !$shipping->getSameAsBilling())
                || (!$shipping->getSameAsBilling() && $shipping->getSaveInAddressBook()))
        ) {
            $customerShipping = $shipping->exportCustomerAddress();
            $customer->addAddress($customerShipping);
            $shipping->setCustomerAddress($customerShipping);
        }

        if (isset($customerBilling) && !$customer->getDefaultBilling()) {
            $customerBilling->setIsDefaultBilling(true);
        }
        if ($shipping && isset($customerBilling) && !$customer->getDefaultShipping() && $shipping->getSameAsBilling()) {
            $customerBilling->setIsDefaultShipping(true);
        } elseif ($shipping && isset($customerShipping) && !$customer->getDefaultShipping()) {
            $customerShipping->setIsDefaultShipping(true);
        }
        $quote->setCustomer($customer);

        return $this;
    }

    /**
     * Involve new customer to system
     *
     * @return $this
     */
    protected function _involveNewCustomer()
    {
        $this->getApi()->log('x_reference '.$this->_quote->getReservedOrderId().': involve new customer, send them confirmation email.');
        $customer = $this->_quote->getCustomer();
        if ($customer->isConfirmationRequired()) {
            $customer->sendNewAccountEmail('confirmation');
            $url = Mage::helper('customer')->getEmailConfirmationUrl($customer->getEmail());
            $this->getCustomerSession()->addSuccess(
                Mage::helper('customer')->__('Account confirmation is required. Please, check your e-mail for confirmation link. To resend confirmation email please <a href="%s">click here</a>.', $url)
            );
        } else {
            $customer->sendNewAccountEmail();
            $this->getCustomerSession()->loginById($customer->getId());
        }
        return $this;
    }

    /**
     * Get customer session object
     *    +/
     * @return Mage_Customer_Model_Session
     */
    public function getCustomerSession()
    {
        return $this->_customerSession;
    }

    /**
     * Params response
     *
     * @param null $key
     * @return mixed
     */
    public function getParams($key = null)
    {
        if ($key) {
            return array_key_exists($key, $this->_params) ? $this->_params[$key] : null;
        }
        return $this->_params;
    }

    /**
     * Params response
     *
     * @param mixed $params
     */
    public function setParams($params)
    {
        $this->_params = $params;
    }

    /**
     * Check gateway signing mechanism
     *
     * @return string
     */
    public function checkSignature()
    {
        $api = $this->getApi();
        $signature = $this->getParams('x_signature');
        $sig = $api->getSignature($this->getParams(), $api->getHmacKey());
        if ($sig === $signature) {
            return true;
        }
        return false;
    }

    /**
     * Check result completed|failed|pending ​
     *
     * @return bool
     */
    public function checkResult()
    {
        $xResult = $this->getParams('x_result');
        switch ($xResult) {
            case 'completed':
                return true;
            default:
                return false;
        }
    }


    /**
     * Apply Discount
     */
    public function _applyDiscount()
    {
        $xAmount = $this->getParams('x_amount');
        $shippingAmount = $this->_quote->getShippingAddress()->getBaseShippingAmount();

        $xAmount = $xAmount - $shippingAmount;
        $grandTotal = $this->_quote->getGrandTotal() - $shippingAmount;

        $percent = round((100 - $xAmount * 100 / $grandTotal), 2);

        $this->_quote->setCouponCode($this->_getCouponCode($percent));
    }

    /**
     * Get coupon
     * @param $percent
     * @return string code
     */
    protected function _getCouponCode($percent)
    {
        try {
            $coupon = Mage::getModel('salesrule/coupon')->loadByCode(Kash_Gateway_Model_Config::GATEWAY_KASH_DISCOUNT_CODE);
            if (!$coupon->isEmpty()) {
                $ruleCoupon = Mage::getModel('salesrule/rule')->load($coupon->getRuleId(), 'rule_id');
                if (!$ruleCoupon->isEmpty()) {
                    $ruleCoupon->setDiscountAmount($percent);
                    $ruleCoupon->save();
                    return $ruleCoupon->getCouponCode();
                }
            }
            //when no discount
            $coupon = Mage::getModel('salesrule/coupon')->loadByCode(Kash_Gateway_Model_Config::GATEWAY_KASH_DISCOUNT_CODE);
            if (!$coupon->isEmpty()) {
                $ruleCoupon = Mage::getModel('salesrule/rule')->load($coupon->getRuleId(), 'rule_id');
                $ruleCoupon->delete();
                $coupon->delete();
            }
            $ruleCoupon = Mage::getModel('salesrule/rule');
            $ruleCoupon->setName('Discount for Kash Gateway')
                ->setDescription('this is a discount for the kash gateway')
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
                ->setDiscountAmount($percent)
                ->setDiscountQty(null)
                ->setDiscountStep('0')
                ->setSimpleFreeShipping('0')
                ->setApplyToShipping('0')
                ->setIsRss(0)
                ->setWebsiteIds(array(1));
            $ruleCoupon->save();
            return $ruleCoupon->getCouponCode();
        } catch (Exception $ex) {
            $this->getApi()->log('Error: coupon exception');
            $this->getApi()->log($ex);
            Mage::logException($ex);
        }
    }
}

