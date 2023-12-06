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

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdjeminPay extends PaymentModule
{
    /**
     * PrestaPay constructor.
     *
     * Set the information about this module
     */
    public function __construct()
    {
        $this->name = 'adjeminpay';
        $this->tab = 'payments_gateways';
        $this->version = '3.0.1';
        $this->author = 'Adjemin';
        $this->controllers = array('paymentview', 'validation', 'notification');
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->need_instance = 1;
        $this->bootstrap = true;
        $this->displayName = $this->l('AdjeminPay');
        $this->description = $this->l('Allow online payments (mobile money and credit/debit card).');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall this module?');
        $this->ps_versions_compliancy = array('min' => '1.7.0', 'max' => _PS_VERSION_);

        parent::__construct();
    }

    /**
     * Install this module and register the following Hooks:
     *
     * @return bool
     */
    public function install()
    {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }

        Configuration::updateValue('ADJEMINPAY_LIVE_MODE', false);

        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn');
    }

    /**
     * Uninstall this module and remove it from all hooks
     *
     * @return bool
     */
    public function uninstall()
    {
        if (!Configuration::deleteByName('ADJEMINPAY_CLIENT_ID')
            || !Configuration::deleteByName('ADJEMINPAY_CLIENT_SECRET')
            || !Configuration::deleteByName('ADJEMINPAY_LIVE_MODE')
            || !parent::uninstall()) {
            return false;
        }

        return true;
    }

    /**
     * Returns a string containing the HTML necessary to
     * generate a configuration screen on the admin
     *
     * @return string
     */
    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit' . $this->name)) {
            $clientId = (string)Tools::getValue('ADJEMINPAY_CLIENT_ID');
            $clientSecret = (string)Tools::getValue('ADJEMINPAY_CLIENT_SECRET');
            $liveMode = boolval(Tools::getValue('ADJEMINPAY_LIVE_MODE'));

            if (!$clientId || empty($clientId) || !$clientSecret || empty($clientSecret)) {
                $output .= $this->displayError($this->l('Invalid Configuration value'));
            } else {
                Configuration::updateValue('ADJEMINPAY_CLIENT_ID', $clientId);
                Configuration::updateValue('ADJEMINPAY_CLIENT_SECRET', $clientSecret);
                Configuration::updateValue('ADJEMINPAY_LIVE_MODE', $liveMode);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        // Get default language
        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');

        $fieldsForm = array();

        // Init Fields form array
        $fieldsForm[0]['form'] = [
            'legend' => [
                'title' => $this->l('Settings'),
                'icon' => 'icon-cogs',
            ],
            'input' => [
                array(
                    'type' => 'switch',
                    'label' => $this->l('Live mode'),
                    'name' => 'ADJEMINPAY_LIVE_MODE',
                    'is_bool' => true,
                    'desc' => $this->l('Use this module in live mode'),
                    'values' => array(
                        array(
                            'id' => 'active_on',
                            'value' => true,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'id' => 'active_off',
                            'value' => false,
                            'label' => $this->l('Disabled')
                        )
                    ),
                ),
                [
                    'type' => 'text',
                    'label' => $this->l('Client ID'),
                    'name' => 'ADJEMINPAY_CLIENT_ID',
                    'size' => 20,
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Client Secret'),
                    'name' => 'ADJEMINPAY_CLIENT_SECRET',
                    'size' => 20,
                    'required' => true
                ]
            ],

            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            ]
        ];

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Language
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = [
            'save' => [
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name .
                    '&token=' . Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            ]
        ];

        // Load current value
        $helper->fields_value['ADJEMINPAY_CLIENT_ID'] = Tools::getValue(
            'ADJEMINPAY_CLIENT_ID',
            Configuration::get('ADJEMINPAY_CLIENT_ID')
        );
        $helper->fields_value['ADJEMINPAY_CLIENT_SECRET'] = Tools::getValue(
            'ADJEMINPAY_CLIENT_SECRET',
            Configuration::get('ADJEMINPAY_CLIENT_SECRET')
        );
        $helper->fields_value['ADJEMINPAY_LIVE_MODE'] = Tools::getValue(
            'ADJEMINPAY_LIVE_MODE',
            Configuration::get('ADJEMINPAY_LIVE_MODE')
        );

        return $helper->generateForm($fieldsForm);
    }

    /**
     * Display this module as a payment option during the checkout
     *
     * @param array $params
     * @return array|void
     */
    public function hookPaymentOptions($params)
    {
        /*
         * Verify if this module is active
         */
        if (!$this->active) {
            return;
        }

        /**
         * Form action URL. The form data will be sent to the
         * validation controller when the user finishes
         * the order process.
         */
        $formAction = $this->context->link->getModuleLink($this->name, 'paymentview', array(), true);

        /**
         * Assign the url form action to the template var $action
         */
        $this->smarty->assign(['action' => $formAction]);

        /**
         *  Load form template to be displayed in the checkout step
         */
        $paymentForm = $this->fetch('module:adjeminpay/views/templates/hook/payment_options.tpl');

        /**
         * Create a PaymentOption object containing the necessary data
         * to display this module in the checkout
         */
        $newOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $newOption->setModuleName($this->displayName)
            ->setCallToActionText($this->l('Payer avec AdjeminPay'))
            ->setAction($formAction)
            ->setForm($paymentForm);

        $payment_options = array(
            $newOption
        );

        return $payment_options;
    }

    /**
     * Display a message in the paymentReturn hook
     *
     * @param array $params
     * @return string
     */
    public function hookPaymentReturn($params)
    {
        /**
         * Verify if this module is enabled
         */
        if (!$this->active) {
            return;
        }

        return $this->fetch('module:adjeminpay/views/templates/hook/payment_return.tpl');
    }
}
