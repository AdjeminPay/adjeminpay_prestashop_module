<?php
/**
 * AdjeminPay - A Payment Module for PrestaShop 1.7
 *
 * This file is the declaration of the module.
 *
 * 2023 Adjemin and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to support@adjeminpay.com so we can send you a copy immediately.
 *
 * @author Adjemin <support@adjeminpay.com>
 * @copyright 2023 Adjemin and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

class AdjeminPayPaymentViewModuleFrontController extends ModuleFrontController
{

    /**
     * API's URL
     */
    const API_BASE_URL = "https://api.adjeminpay.com";

    /**
     * Transaction ID
     * @var string
     */
    private $transactionId;


    public function init()
    {
        parent::init();
        if (!$this->module->active ||
            !$this->context->cart->id_address_delivery ||
            !$this->context->cart->id_address_invoice) {
            Tools::redirect($this->context->link->getPageLink('order'));
        }

        $customer = $this->context->customer;
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect($this->context->link->getPageLink('order'));
        }

        /**
         * Get current cart object from session
         */
        $cart = $this->context->cart;

        $this->transactionId = "prestashop_" . (int)$cart->id . "_" . time();

        $clientId = Configuration::get('ADJEMINPAY_CLIENT_ID');
        $clientSecret = Configuration::get('ADJEMINPAY_CLIENT_SECRET');
        $amount = (int)$cart->getOrderTotal(true, Cart::BOTH);

        $designation = "Paiement en ligne";

        $returnUrl = $this->context->link->getModuleLink('adjeminpay', 'validation', [
            'merchant_trans_id' => $this->transactionId,
            'status' => "completed"
        ], true);

        $notificationUrl = $this->context->link->getModuleLink('adjeminpay', 'notification', [], true);

        $paymentData = [
            "amount" => intval($amount),
            "currency_code" => "XOF",
            "merchant_trans_id" => "$this->transactionId",
            "merchant_trans_data" => "$this->transactionId",
            "designation" => "$designation",
            "webhook_url" => $notificationUrl,
            "return_url" => $returnUrl,
            "cancel_url" => $returnUrl
        ];


        $paymentUrl = $this->getPaymentUrl($clientId, $clientSecret, $paymentData);

        if ($paymentUrl != null && is_string($paymentUrl)) {
            Tools::redirect($paymentUrl);
        }

    }

    public function initContent()
    {
        parent::initContent();

        $this->setTemplate('module:adjeminpay/views/templates/front/sample.tpl');
    }

    public function setMedia()
    {
        parent::setMedia();
    }


    /**
     * Process the data sent via the payment form
     */
    public function postProcess()
    {
    }

    public function getAccessToken($clientId, $clientSecret)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => self::API_BASE_URL . '/oauth/token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => "grant_type=client_credentials&client_id=$clientId&client_secret=$clientSecret",
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

        $json = (array)json_decode($response, true);

        if (array_key_exists('access_token', $json) && !empty($json['access_token'])) {
            return $json['access_token'];
        } else {
            if (array_key_exists('message', $json) && !empty($json['message'])) {
                $message = $json['message'];
            } else {
                $message = "Client authentication failed";
            }
            throw new Exception($message);
        }
    }

    private function getPaymentStatus($clientId, $clientSecret, $merchantTransactionId)
    {
        try {
            $token = $this->getAccessToken($clientId, $clientSecret);

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => self::API_BASE_URL ."/v3/merchants/payment/$merchantTransactionId",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/json',
                    "Authorization: Bearer $token"
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);

            $json = (array)json_decode($response, true);

            if (array_key_exists('data', $json) && !empty($json['data'])) {
                return $json['data']['status'];
            } else {
                return "FAILED";
            }
        } catch (Exception $exception) {
            return "FAILED";
        }
    }


    public function getPaymentUrl($clientId, $clientSecret, $params)
    {
        $token = $this->getAccessToken($clientId, $clientSecret);

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => self::API_BASE_URL . '/v3/merchants/create_checkout',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Content-Type: application/json',
                "Authorization: Bearer  $token"
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

        $json = (array)json_decode($response, true);

       // echo "<!-- DEBUG\n" . print_r($json, true) . "\n-->";

        if (array_key_exists('data', $json) && !empty($json['data'])) {
            return $json['data']['service_payment_url'];
        } else {
            if (array_key_exists('message', $json) && !empty($json['message'])) {
                $message = $json['message'];
            } else {
                $message = "Error when getting payment URL";
            }
            throw new Exception($message);
        }
    }


}
