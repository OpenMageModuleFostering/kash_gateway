<?php

class Kash_Gateway_Model_Observer extends Varien_Object
{
    /**
     * Do not show discount in admin panel
     *
     * @param Varien_Object $observer
     * @return Mage_Centinel_Model_Observer
     */
    public function coreCollectionAbstractLoadBefore($observer)
    {
        if (Mage::app()->getRequest()->getRouteName() == 'adminhtml' &&
            Mage::app()->getRequest()->getControllerName() == 'promo_quote'
        ) {
            if ($observer->getCollection() instanceof Mage_SalesRule_Model_Resource_Rule_Collection) {
                $select = $observer->getCollection()->getSelect();
                $select->where('code NOT IN (?)', Kash_Gateway_Model_Config::GATEWAY_KASH_DISCOUNT_CODE);
                $select->orWhere('code IS NULL');
            }
        }
        return $this;
    }

    /**
     * Do not take the coupon with frontend for customers
     *
     * @param Varien_Object $observer
     * @return Mage_Centinel_Model_Observer
     */
    public function salesQuoteCollectTotalsBefore($observer)
    {
        $quote = $observer->getQuote();
        $discount = $quote->getCouponCode();
        if ($discount === Kash_Gateway_Model_Config::GATEWAY_KASH_DISCOUNT_CODE) {
            $params = Mage::app()->getRequest()->getParams();
            $param = array_key_exists('coupon_code', $params) ? $params['coupon_code'] : null;
            if (Mage::app()->getRequest()->getRouteName() == 'checkout' ||
                $param == $discount) {
                $quote->setCouponCode('');
            }
        }
    }

    /**
     * Listen for when an order is completed, then send that order's payment details and amount
     * for analytics
     *
     * @param Varien_Object $observer
     */
    public function sendReport($observer) {
        $orderId = $observer->getData('order_ids')[0];

        $order = Mage::getModel('sales/order')->load($orderId);
        $payment = $order->getPayment()->getMethodInstance()->getCode();
        $total = $order->getGrandTotal();

        //standardize the 'kash' type, leave others as they are
        if ($payment == 'kash_gateway') {
            $payment = 'kash';
        }

        $config = Mage::getModel('kash_gateway/config', array(Kash_Gateway_Model_Config::METHOD_GATEWAY_KASH));
        $api = Mage::getModel('kash_gateway/api_bb')->setConfigObject($config);

        $url = $config->post_url.'/reporting';

        $logger = Mage::helper('kash_gateway')->logger();
        $logger->log("order ".$order->getIncrementId()." paid with: ".$payment);
        $log = $logger->getLog();

        $data = array(
            'x_account_id' => $config->x_account_id,
            'x_merchant' => $config->x_shop_name,
            'x_payment' => $payment,
            'x_amount' => $total,
            'x_log' => $log
        );
        $data['x_signature'] = $api->getSignature($data, $api->getHmacKey());

        $options = array(
            'http' => array(
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
            ),
        );

        //send the stats and log back to the server.
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        //If the server did not return an error, erase the part of the log we just sent.
        if ($result !== FALSE) {
            $logger->resetLog(strlen($log));
        }
    }

    /**
     * Listen for when an order is created and log it
     *
     * @param Varien_Object $observer
     */
    public function logOrderSave($observer) {
        $order = $observer->getOrder();
        $logger = Mage::helper('kash_gateway')->logger();
        $logger->log('order '.$order->getIncrementId().': was saved, state is: '.$order->getState());
    }

    /**
     * Listen for when an a quote is converted to an order and log it
     *
     * @param Varien_Object $observer
     */
    public function logQuoteToOrder($observer) {
        $order = $observer->getOrder();
        $quote = $observer->getQuote();
        $logger = Mage::helper('kash_gateway')->logger();
        $logger->log('quote '.$quote->getId().': was converted to order '.$order->getIncrementId());
    }

}

