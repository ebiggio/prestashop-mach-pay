<?php
/**
 * MACH Pay - Método de pago para PrestaShop 1.7
 *
 * @author Enzo Biggio (2022)
 * @version 1.0
 * @email ebiggio@gmail.com
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if ( ! defined('_PS_VERSION_')) {
    exit;
}

class MACHPay extends PaymentModule {
    protected $_html = '';
    protected $_post_errors = array();
    static $machpay_version = '1.0.0';

    public function __construct() {
        $this->name = 'machpay';
        $this->tab = 'payments_gateways';
        $this->version = MACHPay::$machpay_version;
        $this->author = 'Enzo Biggio';
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->ps_versions_compliancy = array(
            'min' => '1.7.0',
            'max' => _PS_VERSION_
        );
        $this->bootstrap = true;
        $this->displayName = 'MACH Pay';
        $this->description = 'Método de pago MACH Pay.';
        $this->confirmUninstall = '¿Estás seguro de querer desinstalar este módulo?';

        $this->limited_countries = array('CL');

        $this->limited_currencies = array('CPL');

        parent::__construct();
    }

    public function install(): bool {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('Se necesita tener la extensión cURL instalada en el servidor para instalar este módulo');

            return false;
        }

        $iso_code = Country::getIsoById(Configuration::get('PS_COUNTRY_DEFAULT'));

        if ( ! in_array($iso_code, $this->limited_countries)) {
            $this->_errors[] = $this->l('Este módulo no está disponible en tu país');

            return false;
        }

        include(dirname(__FILE__) . '/sql/install.php');

        Configuration::updateValue('MACHPAY_IN_PRODUCTION', false);
        Configuration::updateValue('MACHPAY_SANDBOX_URL', 'https://biz-sandbox.soymach.com');
        Configuration::updateValue('MACHPAY_SANDBOX_API_KEY', '');
        Configuration::updateValue('MACHPAY_PRODUCTION_URL', 'https://biz.soymach.com');
        Configuration::updateValue('MACHPAY_PRODUCTION_API_KEY', '');

        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('displayPaymentReturn');
    }

    public function uninstall(): bool {
        Configuration::deleteByName('MACHPAY_IN_PRODUCTION');
        Configuration::deleteByName('MACHPAY_SANDBOX_URL');
        Configuration::deleteByName('MACHPAY_SANDBOX_API_KEY');
        Configuration::deleteByName('MACHPAY_PRODUCTION_URL');
        Configuration::deleteByName('MACHPAY_PRODUCTION_API_KEY');

        return parent::uninstall();
    }

    public function getContent() {
        if (Tools::isSubmit('submitMACHPayModule')) {
            $this->postValidation();

            if ( ! count($this->_post_errors)) {
                $this->postProcess();
            } else {
                foreach ($this->_post_errors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        }

        return $this->_html . $this->renderForm();
    }

    protected function postProcess() {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }

        $this->_html .= $this->displayConfirmation($this->l('Configuración guardada exitosamente'));
    }

    protected function postValidation() {
        Configuration::updateValue('MACHPAY_IN_PRODUCTION', Tools::getValue('MACHPAY_IN_PRODUCTION'));

        if (Tools::getValue('MACHPAY_IN_PRODUCTION')) {
            if ( ! Tools::getValue('MACHPAY_PRODUCTION_URL')) {
                $this->_post_errors[] = $this->l('La URL del ambiente de producción es requerida');
            }

            if ( ! Tools::getValue('MACHPAY_PRODUCTION_API_KEY')) {
                $this->_post_errors[] = $this->l('La llave API del ambiente de producción es requerida');
            }
        } else {
            if ( ! Tools::getValue('MACHPAY_SANDBOX_URL')) {
                $this->_post_errors[] = $this->l('La URL del ambiente sandbox es requerida');
            }

            if ( ! Tools::getValue('MACHPAY_SANDBOX_API_KEY')) {
                $this->_post_errors[] = $this->l('La llave API del ambiente sandbox es requerida');
            }
        }
    }

    protected function renderForm() {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMACHPayModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues()
        );

        return $helper->generateForm($this->getConfigForm());
    }

    protected function getConfigForm(): array {
        $mode_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Modo de funcionamiento'),
                    'icon'  => 'icon-cogs',
                ),
                'input'  => array(
                    array(
                        'type'    => 'switch',
                        'label'   => $this->l('¿En producción?'),
                        'name'    => 'MACHPAY_IN_PRODUCTION',
                        'is_bool' => true,
                        'desc'    => $this->l('Indica si el módulo debe trabajar con pago reales o con operaciones de prueba, de acuerdo al ambiente seleccionado.'),
                        'values'  => array(
                            array(
                                'id'    => 'active_off',
                                'value' => true,
                                'label' => $this->l('Producción')
                            ),
                            array(
                                'id'    => 'active_on',
                                'value' => false,
                                'label' => $this->l('Sandbox')
                            )
                        )
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Guardar'),
                ),
            )
        );

        $sandbox_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Configuración del ambiente de pruebas')
                ),
                'input'  => array(
                    array(
                        'type'     => 'text',
                        'prefix'   => '<i class="icon icon-link"></i>',
                        'label'    => $this->l('URL del ambiente de pruebas'),
                        'name'     => 'MACHPAY_SANDBOX_URL',
                        'desc'     => $this->l('Ingresa la URL sin un "/" al final de esta'),
                        'required' => false
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->l('Llave API del ambiente de pruebas'),
                        'name'     => 'MACHPAY_SANDBOX_API_KEY',
                        'required' => false
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Guardar'),
                )
            )
        );

        $production_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Configuración del ambiente de producción')
                ),
                'input'  => array(
                    array(
                        'type'     => 'text',
                        'prefix'   => '<i class="icon icon-link"></i>',
                        'label'    => $this->l('URL del ambiente de producción'),
                        'name'     => 'MACHPAY_PRODUCTION_URL',
                        'desc'     => $this->l('Ingresa la URL sin un "/" al final de esta'),
                        'required' => false
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->l('Llave API del ambiente de producción'),
                        'name'     => 'MACHPAY_PRODUCTION_API_KEY',
                        'required' => false
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Guardar'),
                )
            )
        );

        $webhook_url = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Webhook')
                ),
                'input'  => array(
                    array(
                        'type'     => 'text',
                        'prefix'   => '<i class="icon icon-link"></i>',
                        'label'    => $this->l('URL para configurar como endpoint de webhook'),
                        'name'     => 'MACHPAY_WEBHOOK',
                        'desc'     => $this->l('Configura esta URL como webhook en MACH Pay para recibir el procesamiento de los pagos'),
                        'disabled' => true,
                        'required' => false
                    )
                )
            )
        );

        return array(
            $mode_form,
            $sandbox_form,
            $production_form,
            $webhook_url
        );
    }

    protected function getConfigFormValues(): array {
        return array(
            'MACHPAY_IN_PRODUCTION'      => Configuration::get('MACHPAY_IN_PRODUCTION'),
            'MACHPAY_SANDBOX_URL'        => Configuration::get('MACHPAY_SANDBOX_URL'),
            'MACHPAY_SANDBOX_API_KEY'    => Configuration::get('MACHPAY_SANDBOX_API_KEY'),
            'MACHPAY_PRODUCTION_URL'     => Configuration::get('MACHPAY_PRODUCTION_URL'),
            'MACHPAY_PRODUCTION_API_KEY' => Configuration::get('MACHPAY_PRODUCTION_API_KEY'),
            'MACHPAY_WEBHOOK'            => $this->context->link->getModuleLink($this->name, 'webhook')
        );
    }

    public function hookPaymentOptions($params) {
        if ( ! $this->active) {
            return [];
        }

        if ( ! $this->checkCurrency($params['cart'])) {
            return [];
        }

        return $this->getMACHPayPaymentOptions();
    }

    public function checkCurrency($cart): bool {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Genera la configuración para mostrar la opción de pago MACH Pay en el checkout
     *
     * Este método debe devolver un arreglo aunque se trate de sola una opción de pago, ya que internamente PrestaShop espera este tipo de dato
     *
     * @return \PrestaShop\PrestaShop\Core\Payment\PaymentOption[]
     */
    public function getMACHPayPaymentOptions(): array {
        $machpay_payment_option = new PaymentOption();
        $machpay_payment_option->setCallToActionText('Pago con MACH Pay')
            ->setAction($this->context->link->getModuleLink($this->name, 'createPayment'))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/machpay.png'));

        return array($machpay_payment_option);
    }

    /**
     * Despliega un mensaje en la página de confirmación (pago exitoso) del pedido
     *
     * @param array $params
     * @return string
     */
    public function hookPaymentReturn($params) {
        if ( ! $this->active) {
            return '';
        }

        return $this->fetch('module:machpay/views/templates/hook/payment_return.tpl');
    }

    /**
     * Obtiene la configuración del módulo de acuerdo al ambiente en el que se está trabajando
     *
     * @return array
     */
    public static function getConfiguration(): array {
        if (Configuration::get('MACHPAY_IN_PRODUCTION')) {
            return array(
                'machpay_api_url' => Configuration::get('MACHPAY_PRODUCTION_URL'),
                'machpay_api_key' => Configuration::get('MACHPAY_PRODUCTION_API_KEY')
            );
        } else {
            return array(
                'machpay_api_url' => Configuration::get('MACHPAY_SANDBOX_URL'),
                'machpay_api_key' => Configuration::get('MACHPAY_SANDBOX_API_KEY')
            );
        }
    }

    /*
     * Disclaimer:
     * Estas solicitudes se podrían hacer con Guzzle, pero según leí la versión que viene por defecto con PrestaShop 1.7 es una bastante desactualizada. Ergo, cURL
     */
    public static function makePOSTRequest(string $endpoint, array $request_data) {
        $headers[] = 'Content-type: application/json';
        $headers[] = 'Authorization: Bearer ' . MACHPay::getConfiguration()['machpay_api_key'];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_URL, MACHPay::getConfiguration()['machpay_api_url'] . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PrestaShop MACHPay/' . MACHPay::$machpay_version);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));

        return MACHPay::processcURLResponse($ch);
    }

    public static function makeGETRequest(string $endpoint) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . MACHPay::getConfiguration()['machpay_api_key']]);
        curl_setopt($ch, CURLOPT_URL, MACHPay::getConfiguration()['machpay_api_url'] . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PrestaShop MACHPay/' . MACHPay::$machpay_version);

        return MACHPay::processcURLResponse($ch);
    }

    private static function processcURLResponse($curl_handle) {
        $response = curl_exec($curl_handle);

        $curl_error_code = curl_errno($curl_handle);
        $curl_error_message = curl_error($curl_handle);
        $http_status_code = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
        $http_error = in_array(floor($http_status_code / 100), array(
            4,
            5
        ));

        $error = ! ($curl_error_code === 0) || $http_error;

        if ($error) {
            return false;
        } else {
            return $response;
        }
    }
}