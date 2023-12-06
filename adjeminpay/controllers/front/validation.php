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
 * to support.adjeminpay@adjemin.com so we can send you a copy immediately.
 *
 * @author Adjemin <support@adjemin.com>
 * @copyright 2023 Adjemin and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

class AdjeminpayValidationModuleFrontController extends ModuleFrontController
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
        if (Tools::getValue("merchant_trans_id") != null && Tools::getValue("status") == "completed") {
            $merchantTransactionId = Tools::getValue("merchant_trans_id");

            $clientId = Configuration::get('ADJEMINPAY_CLIENT_ID');
            $clientSecret = Configuration::get('ADJEMINPAY_CLIENT_SECRET');

            $transactionStatus = $this->getPaymentStatus($clientId, $clientSecret, $merchantTransactionId);
            if ($transactionStatus == "SUCCESSFUL") {
                $this->validateTransaction();
            } else {
                Tools::redirect($this->context->link->getPageLink('order'));
            }
        } else {
            Tools::redirect($this->context->link->getPageLink('order'));
        }
    }

    public function validateTransaction()
    {
        /**
         * Get current cart object from session
         */
        $cart = $this->context->cart;
        $authorized = false;
        
        /**
         * Verify if this module is enabled and if the cart has
         * a valid customer, delivery address and invoice address
         */
        if (!$this->module->active || $cart->id_customer == 0 || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0) {
            Tools::redirect('index.php?controller=order&step=1');
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
            die($this->l('This payment method is not available.'));
        }

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);

        /**
         * Check if this is a vlaid customer account
         */
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }


        if (!$cart->orderExists()) {

            /**
             * Place the order
             */
            $this->module->validateOrder(
                (int)$this->context->cart->id,
                Configuration::get('PS_OS_PAYMENT'),
                (float)$this->context->cart->getOrderTotal(true, Cart::BOTH),
                $this->module->displayName,
                null,
                null,
                (int)$this->context->currency->id,
                false,
                $customer->secure_key
            );
        }

        /**
         * If the order has been validated we try to retrieve it
         */
        $order_id = Order::getOrderByCartId((int)$cart->id);

        if ($order_id) {

            /**
             * Redirect the customer to the order confirmation page
             */
            Tools::redirect('index.php?controller=order-confirmation&id_cart='
                . (int)$cart->id . '&id_module='
                . (int)$this->module->id . '&id_order='
                . $this->module->currentOrder . '&key=' . $customer->secure_key);
        } else {
            Tools::redirect('index.php?controller=order&step=1');
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
