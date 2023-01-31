<?php
/**
 * MACH Pay - Método de pago para PrestaShop 1.7
 *
 * @author Enzo Biggio (2023)
 * @version 1.0
 * @email ebiggio@gmail.com
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
include_once (__DIR__ . '/classes/MACHPayAPI.php');

CONST MACHPAY_VERSION = '1.0.0';

if ( ! defined('_PS_VERSION_')) {
    exit;
}

class MACHPay extends PaymentModule {
    protected $_html = '';
    protected $_post_errors = array();

    public function __construct() {
        $this->name = 'machpay';
        $this->tab = 'payments_gateways';
        $this->version = MACHPAY_VERSION;
        $this->author = 'Enzo Biggio';
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->ps_versions_compliancy = array(
            'min' => '1.7.0',
            'max' => _PS_VERSION_
        );
        $this->bootstrap = true;
        $this->displayName = 'MACH';
        $this->description = 'Método de pago MACH Pay.';
        $this->confirmUninstall = '¿Estás seguro de querer desinstalar este módulo?';

        $this->limited_currencies = array('CPL');

        parent::__construct();
    }

    public function install(): bool {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('Se necesita tener la extensión cURL instalada en el servidor para instalar este módulo');

            return false;
        }

        include(dirname(__FILE__) . '/sql/install.php');

        Configuration::updateValue('MACHPAY_IN_PRODUCTION', false);
        Configuration::updateValue('MACHPAY_MANUAL_CONFIRMATION', true);
        Configuration::updateValue('MACHPAY_SANDBOX_URL', 'https://biz-sandbox.soymach.com');
        Configuration::updateValue('MACHPAY_SANDBOX_API_KEY', '');
        Configuration::updateValue('MACHPAY_PRODUCTION_URL', 'https://biz.soymach.com');
        Configuration::updateValue('MACHPAY_PRODUCTION_API_KEY', '');
        Configuration::updateValue('MACHPAY_WEBHOOK_IPS', '10.198.7.238, 10.198.8.203, 10.198.11.42, 10.198.15.241');

        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('displayPaymentReturn');
    }

    public function uninstall(): bool {
        Configuration::deleteByName('MACHPAY_IN_PRODUCTION');
        Configuration::deleteByName('MACHPAY_MANUAL_CONFIRMATION');
        Configuration::deleteByName('MACHPAY_SANDBOX_URL');
        Configuration::deleteByName('MACHPAY_SANDBOX_API_KEY');
        Configuration::deleteByName('MACHPAY_PRODUCTION_URL');
        Configuration::deleteByName('MACHPAY_PRODUCTION_API_KEY');
        Configuration::deleteByName('MACHPAY_WEBHOOK_IPS');

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
            if ($key == 'MACHPAY_PRODUCTION_URL' || $key == 'MACHPAY_SANDBOX_URL') {
                Configuration::updateValue($key, rtrim(Tools::getValue($key), '/'));
            } else {
                Configuration::updateValue($key, Tools::getValue($key));
            }
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

        if ( ! Tools::getValue('MACHPAY_WEBHOOK_IPS')) {
            $this->_post_errors[] = $this->l('Se necesita al menos una IP válida autorizada para consumir el webhook para que este módulo funcione correctamente');
        } else {
            foreach (explode(',', Tools::getValue('MACHPAY_WEBHOOK_IPS')) as $ip) {
                if ( ! filter_var(trim($ip), FILTER_VALIDATE_IP)) {
                    $this->_post_errors[] = $this->l('La IP ingresada no parece ser válida');

                    break;
                }
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
                        'desc'    => $this->l('Indica si el módulo debe trabajar con pago reales o con operaciones de prueba, de acuerdo al ambiente
                            seleccionado'),
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
                    ),
                    array(
                        'type'    => 'switch',
                        'label'   => $this->l('¿Se debe confirmar el pago mediante la API?'),
                        'name'    => 'MACHPAY_MANUAL_CONFIRMATION',
                        'is_bool' => true,
                        'desc'    => $this->l('Indica si, una vez que se recibe mediante webhook la notificación de un pago completado,
                            este luego debe ser confirmado mediante API posterior a las validaciones de la tienda. Esta opción dependerá de cómo esté configurada
                            la captura de los pagos para el negocio en MACH Pay. Si las capturas son manuales (funcionamiento por defecto en producción), esta
                            opción debe estar activa. De lo contrario, al ser las capturas automáticas, esta opción debe estar apagada, ya que el módulo
                            intentará confirmar un pago completado mediante la API, recibiendo un error al estar el pago ya confirmado y no generando el pedido
                            correspondiente en la tienda. Es importante tener presente que cuando la captura es manual, un pago completado que no es confirmado
                            generará una reversa transcurridos 5 minutos.'),
                        'values'  => array(
                            array(
                                'id'    => 'active_off',
                                'value' => true,
                                'label' => $this->l('Sí')
                            ),
                            array(
                                'id'    => 'active_on',
                                'value' => false,
                                'label' => $this->l('No')
                            )
                        )
                    )
                )
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
                        'desc'     => $this->l('Configura esta URL como webhook en MACH Pay para recibir el procesamiento de los pagos. Ten especial
                            atención con las URLs amigables y los lenguajes configurado; en caso que desinstales un lenguaje después de haber utilizado este
                            valor, la URL podría cambiar'),
                        'disabled' => true,
                        'required' => false
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->l('IPs autorizadas a invocar el endpoint'),
                        'name'     => 'MACHPAY_WEBHOOK_IPS',
                        'desc'     => $this->l('Lista de IPs, separadas por comas, autorizadas a llamar el endpoint antes señalado. Si por alguna razón
                            el webhook no funciona, confirma que estos valores correspondan a los servidores de MACH'),
                        'required' => true
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Guardar'),
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
            'MACHPAY_IN_PRODUCTION'       => Configuration::get('MACHPAY_IN_PRODUCTION'),
            'MACHPAY_MANUAL_CONFIRMATION' => Configuration::get('MACHPAY_MANUAL_CONFIRMATION'),
            'MACHPAY_SANDBOX_URL'         => Configuration::get('MACHPAY_SANDBOX_URL'),
            'MACHPAY_SANDBOX_API_KEY'     => Configuration::get('MACHPAY_SANDBOX_API_KEY'),
            'MACHPAY_PRODUCTION_URL'      => Configuration::get('MACHPAY_PRODUCTION_URL'),
            'MACHPAY_PRODUCTION_API_KEY'  => Configuration::get('MACHPAY_PRODUCTION_API_KEY'),
            'MACHPAY_WEBHOOK'             => $this->context->link->getModuleLink($this->name, 'webhook'),
            'MACHPAY_WEBHOOK_IPS'         => Configuration::get('MACHPAY_WEBHOOK_IPS'),
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
     * Genera la opción de pago MACH Pay que se despliega en el checkout
     *
     * Este método debe devolver un arreglo aunque se trate de sola una opción de pago, ya que internamente PrestaShop espera este tipo de dato
     *
     * @return \PrestaShop\PrestaShop\Core\Payment\PaymentOption[]
     */
    public function getMACHPayPaymentOptions(): array {
        $machpay_payment_option = new PaymentOption();
        $machpay_payment_option->setCallToActionText('Pago con MACH')
            ->setAction($this->context->link->getModuleLink($this->name, 'createPayment'))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/machpay.png'));

        return array($machpay_payment_option);
    }

    /**
     * Despliega un mensaje de agradecimiento en la página de confirmación (pago exitoso) del pedido
     *
     * @param array $params
     * @return string
     */
    public function hookPaymentReturn($params) {
        if ( ! $this->active) {
            return '';
        }

        $this->smarty->assign(['machpay_logo' => Media::getMediaPath(_PS_MODULE_DIR_ . 'machpay/views/img/machpay.png')]);

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
}