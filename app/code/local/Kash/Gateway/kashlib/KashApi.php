<?php

/**
 * This is a reusable component to talk to kash_api.
 */
class KashApi
{
    private static $apiUrlProd = 'https://api.withkash.com/v1';

    protected $_gatewayUrl;
    protected $_serverKey;

    public function __construct($gatewayUrl, $serverKey)
    {
        $this->_gatewayUrl = $gatewayUrl;
        $this->_serverKey = $serverKey;
    }

    public function refund($kashTransactionId, $refundAmount)
    {
        $requestHeaders = array(
            'Authorization: Basic ' . base64_encode($this->_serverKey . ':')
        );

        $url = self::$apiUrlProd;
        if (strpos($this->_gatewayUrl, 'februalia') !== false) {
            $url = str_replace('withkash', 'februalia', $url);
        }
        else if ($this->gatewayUrl === 'http://kash-gateway') {
            $url = 'http://kash-api:8080';
        }
        $url .= '/refunds';

        $requestPayload = array(
            'amount' => $refundAmount * 100,
            'transaction_id' => $kashTransactionId
        );

        // create a new cURL resource
        $ch = curl_init($url);

        // set URL and other appropriate options
        curl_setopt($ch, CURLOPT_POST ,1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);

        // Execute the request.
        $rawResponse = curl_exec($ch);
        $result = null;
        $errorMessage = null;
        if ($rawResponse === FALSE) {
            $errorMessage = curl_error($ch);
        }
        else {
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($rawResponse, 0, $headerSize);
            $rawBody = substr($rawResponse, $headerSize);
            $body = json_decode($rawBody);

            $result = new stdClass();
            $result->statusCode = $statusCode;
            $result->headers = $headers;
            $result->body = $body;
            $result->rawBody = $rawBody;
        }

        // close cURL resource, and free up system resources
        curl_close($ch);

        if (!$result) {
            throw new Exception($errorMessage);
        }
        return $result;
    }
}
