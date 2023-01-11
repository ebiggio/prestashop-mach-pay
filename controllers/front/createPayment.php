<?php

class MACHPayCreatePaymentModuleFrontController extends ModuleFrontController {
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess() {
        $cart = $this->context->cart;

        if ( ! $cart->hasProducts() || $cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || ! $this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Verificamos si el módulo de pago está disponible
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'machpay') {
                $authorized = true;

                break;
            }
        }

        if ( ! $authorized) {
            die($this->module->l('Este método de pago no está disponible.', 'validation'));
        }

        try {
            $cart_amount = $cart->getOrderTotal();
        } catch (Exception $e) {
            $cart_amount = 0;
        }

        $payment_details = [
            'payment' => [
                'amount'      => (int)$cart_amount,
                'title'       => 'Pago en ' . Configuration::get('PS_SHOP_NAME'),
                'upstream_id' => (string)$cart->id
            ]
        ];

        $machpay_response = MACHPay::makecURLRequest('/payments', $payment_details);

        if ($machpay_response) {
            // TODO
        } else {
            PrestaShopLogger::addLog('MACH Pay: error al intentar generar un intento de pago (BusinessPayment) ([' . MACHPay::getConfiguration()['machpay_api_url'] . ']');

            $this->setTemplate('module:machpay/views/templates/front/payment_error.tpl');
        }
    }
}