<?php

/**
 * Bb Checkout Controller
 *
 * @author  Blue Badger <jonathan@badger.blue>
 */
class Kash_Gateway_BbController extends Mage_Core_Controller_Front_Action
{
    /**
     * @var Kash_Gateway_Model_Checkout
     */
    protected $_checkout = null;

    /**
     * Checkout mode type
     *
     * @var string
     */
    protected $_checkoutType = 'kash_gateway/checkout';

    /**
     * @var Kash_Gateway_Model_Config
     */
    protected $_config = null;

    /**
     * Config mode type
     *
     * @var string
     */
    protected $_configType = 'kash_gateway/config';

    /**
     * @var Mage_Sales_Model_Quote
     */
    protected $_quote = false;

    /**
     * Config method type
     *
     * @var string
     */
    protected $_configMethod = Kash_Gateway_Model_Config::METHOD_GATEWAY_KASH;

    /**
     * Instantiate config
     */
    protected function _construct()
    {
        parent::_construct();
        $this->_config = Mage::getModel($this->_configType, array($this->_configMethod));
    }

    /**
     * Get form for requesting form
     */
    public function startAction(){
        $paymentParams = $this->getPaymentRequest();
        if(!$paymentParams){
            return;
        }
        $this->_initToken($paymentParams);
        $this->loadLayout();
        $contentBlock = $this->getLayout()->getBlock('content');
        $formBlock = $contentBlock->getChild('block.form.request');
        $formBlock->setInput($paymentParams);
        $formBlock->setPostUrl($this->_config->post_url);
        $this->renderLayout();
    }

    /**
     * Get form for requesting initial token and dispatching customer
     */
    public function getRequestAction()
    {
        try {
            $paymentParams = $this->getPaymentRequest();
            if(!$paymentParams){
                return;
            }
            $this->_initToken($paymentParams);

            $this->loadLayout();
            $block = $this->getLayout()->createBlock(
                'Mage_Core_Block_Template',
                'block_form_request',
                array('template' => 'kash/form.phtml')
            );
            $block->setInput($paymentParams);
            $block->setPostUrl($this->_config->post_url);
            $this->getResponse()->setBody($block->toHtml());
            return;
        } catch (Mage_Core_Exception $e) {
            $this->_getCheckoutSession()->addError($e->getMessage());
        } catch (Exception $e) {
            $this->_getCheckoutSession()->addError($this->__('Unable to start BB Checkout.'));
            Mage::logException($e);
        }

        $this->_redirect('checkout/cart');
    }

    /**
     * Cancel Payment
     */
    public function cancelAction()
    {
        $this->_initToken(false);
        $this->_redirect('checkout/cart');
    }

    /**
     * Callback Payment
     */
    public function callbackAction()
    {
        $param = Mage::app()->getRequest()->getParam('x_reference');
        $quote = Mage::getModel('sales/quote')->load($param, 'reserved_order_id');
        $this->_quote = $quote;

        $this->_config->setStoreId($this->_getQuote()->getStoreId());
        $this->_checkout = Mage::getSingleton($this->_checkoutType, array(
            'config' => $this->_config,
            'quote' => $quote,
        ));

        $params = Mage::app()->getRequest()->getParams();
        $this->_checkout->setParams($params);

        $this->_checkout->loginUser();

        if ($this->_checkout->checkSignature() && $this->_checkout->checkResult() && $quote->getIsActive()) {
            $connection = Mage::getSingleton('core/resource')->getConnection('core_write');
            try {
                $connection->beginTransaction();

                $this->_checkout->place();
                $order = $this->_checkout->getOrder();

                if ($order->getIncrementId() == $param) {
                    $this->invoiceOrder($order);
                    $connection->commit();
                }
                else {
                    $connection->rollback();
                }
            } catch (Exception $e) {
                $connection->rollback();
            }
        }
    }

    /**
     * Complete from Payment and dispatch customer to order review page
     */
    public function completeAction()
    {
        try {
            if (!$this->_initCheckout(true)) {
                $this->getResponse()->setRedirect(Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB));
                return;
            }
            if (!$this->_checkout->checkSignature()) {
                Mage::log('All requests and responses must be signed/verified using HMAC-SHA256');
                Mage::getSingleton('checkout/session')->addError('Not valid signature');
                $this->_redirect('checkout/cart');
                return;
            }
            if (!$this->_checkout->checkResult()) {
                Mage::log('Result not completed');
                Mage::getSingleton('checkout/session')->addError('Kash Gateway not completed');
                $this->_redirect('checkout/cart');
                return;
            }


            $this->_initToken();

            // prepare session to success or cancellation page
            $session = $this->_getCheckoutSession();
            $session->clearHelperData();

            // store the last successful quote and order so the correct order can be displayed on
            // on the next screen. Get the Quote and Order from the db using xref, since it's not
            // in the session once callback completes.
            $xref = $this->_checkout->getParams('x_reference');
            $quote = Mage::getModel('sales/quote')->load($xref, 'reserved_order_id');
            $session->setLastQuoteId($quote->getId())
                    ->setLastSuccessQuoteId($quote->getId());

            //get the order that was created and store the IDs for use in info pages
            $order = Mage::getModel('sales/order')->loadByIncrementId($xref);
            $session->setLastOrderId($order->getId())
                    ->setLastRealOrderId($order->getIncrementId());

            // no need in token anymore
            $this->_initToken(false);

            if (!$this->_checkout->canSkipOrderReviewStep()) {
                $this->_redirect('checkout/onepage/success');
            } else {
                $this->_redirect('*/*/review');
            }
            return;
        } catch (Mage_Core_Exception $e) {
            Mage::getSingleton('checkout/session')->addError($e->getMessage());
        } catch (Exception $e) {
            Mage::getSingleton('checkout/session')->addError($this->__('Unable to process Kash Gateway Checkout approval.'));
            Mage::logException($e);
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * Review order after returning from Payment
     */
    public function reviewAction()
    {
        try {
            $session = $this->_getCheckoutSession();
            // "last successful quote"
            $quoteId = $session->getLastQuoteId();
            $quote = Mage::getModel('sales/quote')->load($quoteId);

            $this->loadLayout();
            $this->_initLayoutMessages('kash_gateway/session');
            $reviewBlock = $this->getLayout()->getBlock('gateway.kash.review');
            $reviewBlock->setQuote($quote);
            $detailsBlock = $reviewBlock->getChild('details')->setCustomQuote($quote);
            if ($reviewBlock->getChild('shipping_method')) {
                $reviewBlock->getChild('shipping_method')->setCustomQuote($quote);
            }
            if ($detailsBlock->getChild('totals')) {
                $detailsBlock->getChild('totals')->setCustomQuote($quote);
            }
            $this->renderLayout();
            return;
        } catch (Mage_Core_Exception $e) {
            Mage::getSingleton('checkout/session')->addError($e->getMessage());
        } catch (Exception $e) {
            Mage::getSingleton('checkout/session')->addError(
                $this->__('Unable to initialize Kash Gateway Checkout review.')
            );
            Mage::logException($e);
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * Auto invoice for order
     *
     * @param $order Mage_Sales_Model_Order
     */
    protected function invoiceOrder($order)
    {
        if ($order->getState() == Mage_Sales_Model_Order::STATE_NEW) {
            try {
                if (!$order->canInvoice()) {
                    $order->addStatusHistoryComment('Kash Gateway: Order cannot be auto invoiced.', false);
                    $order->save();
                }
                $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

                $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
                $invoice->register();

                $invoice->getOrder()->setCustomerNoteNotify(false);
                $invoice->getOrder()->setIsInProcess(true);
                $order->addStatusHistoryComment('Automatically INVOICED by Kash Gateway.', false);
                $order->sendNewOrderEmail();

                $transactionSave = Mage::getModel('core/resource_transaction')
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());

                $transactionSave->save();
            } catch (Exception $e) {
                $order->addStatusHistoryComment('Kash Gateway: Exception occurred during automatically Invoice action. Exception message: ' . $e->getMessage(), false);
                $order->save();
            }

        }
    }

    /**
     * Search for proper checkout token in request or session or (un)set specified one
     * Combined getter/setter
     *
     * @param string $setToken
     * @return $this|mixed|null|string
     */
    protected function _initToken($setToken = null)
    {
        if (null !== $setToken) {
            if (false === $setToken) {
                // security measure for avoid unsetting token twice
                if (!$this->_getSession()->getBBCheckoutToken()) {
                    Mage::throwException($this->__('Payment Kash Gateway Checkout Token does not exist.'));
                }
                $this->_getSession()->unsBBCheckoutToken();
            } else {
                $this->_getSession()->setBBCheckoutToken($setToken['x_reference']);
            }
            return $this;
        }
        if ($setToken = $this->getRequest()->getParam('x_reference')) {
            if ($setToken !== $this->_getSession()->getBBCheckoutToken()) {
                Mage::throwException($this->__('Wrong Payment Kash Gateway Checkout Token specified.'));
            }
        } else {
            $setToken = $this->_getSession()->getBBCheckoutToken();
        }
        return $setToken;
    }

    /**
     * Payment session instance getter
     *
     * @return Kash_Gateway_Model_Session
     */
    protected function _getSession()
    {
        return Mage::getSingleton('kash_gateway/session');
    }

    /**
     * Return checkout session object
     *
     * @return Mage_Checkout_Model_Session
     */
    protected function _getCheckoutSession()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Return checkout quote object
     *
     * @return Mage_Sales_Model_Quote
     */
    private function _getQuote()
    {
        if (!$this->_quote) {
            $this->_quote = $this->_getCheckoutSession()->getQuote();
        }
        return $this->_quote;
    }

    /**
     * Redirect to login page
     *
     */
    public function redirectLogin()
    {
        $this->setFlag('', 'no-dispatch', true);
        $this->getResponse()->setRedirect(
            Mage::helper('core/url')->addRequestParam(
                Mage::helper('customer')->getLoginUrl(),
                array('context' => 'checkout')
            )
        );
    }


    /**
     * Instantiate quote and checkout
     *
     * @param bool $callback
     * @return Kash_Gateway_Model_Checkout
     * @throws Mage_Core_Exception
     */
    protected function _initCheckout($callback = false)
    {
        $quote = $this->_getQuote();
        if (!$callback && (!$quote->hasItems() || $quote->getHasError())) {
            //$this->getResponse()->setHeader('HTTP/1.1', '403 Forbidden');
            Mage::log(Mage::helper('kash_gateway')->__('Unable to initialize Kash Gateway Checkout.'));
            return null;
        }
        $this->_config->setStoreId($this->_getQuote()->getStoreId());
        $this->_checkout = Mage::getSingleton($this->_checkoutType, array(
            'config' => $this->_config,
            'quote' => $quote,
        ));

        $params = Mage::app()->getRequest()->getParams();
        $this->_checkout->setParams($params);
        return $this->_checkout;
    }

    /**
     * Make params for Payment BB
     */
    protected function getPaymentRequest(){
        $this->_initCheckout();

        if ($this->_getQuote()->getIsMultiShipping()) {
            $this->_getQuote()->setIsMultiShipping(false);
            $this->_getQuote()->removeAllAddresses();
        }

        $customer = Mage::getSingleton('customer/session')->getCustomer();
        $quoteCheckoutMethod = $this->_getQuote()->getCheckoutMethod();
        if ($customer && $customer->getId()) {
            $this->_checkout->setCustomerWithAddressChange(
                $customer, $this->_getQuote()->getBillingAddress(), $this->_getQuote()->getShippingAddress()
            );
        } elseif ((!$quoteCheckoutMethod
                || $quoteCheckoutMethod != Mage_Checkout_Model_Type_Onepage::METHOD_REGISTER)
            && !Mage::helper('checkout')->isAllowedGuestCheckout(
                $this->_getQuote(),
                $this->_getQuote()->getStoreId()
            )
        ) {
            Mage::getSingleton('core/session')->addNotice(
                Mage::helper('kash_gateway')->__('To proceed to Checkout, please log in using your email address.')
            );
            $this->redirectLogin();
            Mage::getSingleton('customer/session')
                ->setBeforeAuthUrl(Mage::getUrl('*/*/*', array('_current' => true, '_secure'=>true)));
            return null;
        }

        // giropay
        $this->_checkout->prepareGiropayUrls(
            Mage::getUrl('gateway/bb/callback', array('_secure'=>true)),
            Mage::getUrl('gateway/bb/cancel', array('_secure'=>true)),
            Mage::getUrl('gateway/bb/complete', array('_secure'=>true))
        );

        $params = $this->_checkout->start();
        return $params;
    }
}
