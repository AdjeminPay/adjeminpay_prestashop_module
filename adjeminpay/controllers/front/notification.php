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

class AdjeminpayNotificationModuleFrontController extends ModuleFrontController
{
    /**
     * API's URL
     */
    const API_BASE_URL = "https://api.adjeminpay.com";

    /**
     * Process the data sent via the payment form
     */
    public function postProcess()
    {
        if (Tools::getValue("merchant_trans_id") != null && Tools::getValue("status") != null) {
            $merchantTransactionId = Tools::getValue("merchant_trans_id");

            $clientId = Configuration::get('ADJEMINPAY_CLIENT_ID');
            $clientSecret = Configuration::get('ADJEMINPAY_CLIENT_SECRET');

            $transactionStatus = $this->getPaymentStatus($clientId, $clientSecret, $merchantTransactionId);

            if ($transactionStatus == "SUCCESSFUL") {
                $result = explode("_", $merchantTransactionId);

                $cartId = $result[1];

                $this->validateTransaction($cartId);
            } else {
                die(json_encode([
                    "message" => 'Notification recevied successfully but not status != SUCCESSFUL '
                ]));
            }
        } else {
            die(json_encode([
                "message" => 'Notification recevied without parameters'
            ]));
        }
    }

    public function validateTransaction($cartId)
    {

        /**
         * Get current cart object from session
         */
        $cart = new Cart((int)$cartId);

        if (!$cart || !Validate::isLoadedObject($cart)) {
            return;
        }

        $currencyId = $cart->id_currency;
        $authorized = false;

        /**
         * Verify if this module is enabled and if the cart has
         * a valid customer, delivery address and invoice address
         */
        if (!$this->module->active || $cart->id_customer == 0 || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0) {
            return;
        }

        /**
         * Verify if this payment module is authorized
         */
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'adjeminpay') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            return;
        }

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);

        /**
         * Check if this is a valid customer account
         */
        if (!Validate::isLoadedObject($customer)) {
            return;
        }

        if (!$cart->orderExists()) {

            /**
             * Place the order
             */
            $this->module->validateOrder(
                $cartId,
                Configuration::get('PS_OS_PAYMENT'),
                (float)$cart->getOrderTotal(true, Cart::BOTH),
                $this->module->displayName,
                null,
                null,
                $currencyId,
                false,
                $customer->secure_key
            );
        }
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
}
