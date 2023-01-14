<?php

class MACHPayWebhookModuleFrontController extends ModuleFrontController {
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess() {
        // Verificamos que la máquina que está invocando el webhook está autorizada a hacerlo, de acuerdo a la lista de IPs configuradas en el módulo
        $remote_ip = $_SERVER['REMOTE_ADDR'];
        $is_authorized = false;

        foreach (explode(',', Tools::getValue('MACHPAY_WEBHOOK_IPS')) as $authorized_ip) {
            if ($remote_ip == trim($authorized_ip)) {
                $is_authorized = true;

                break;
            }
        }

        if ( ! $is_authorized) {
            return;
        }

        /*
         * Debemos utilizar "file_get_contents" para capturar el cuerpo de la solicitud. Si bien el webhook se invoca mediante POST, la variable global $_POST
         * en estas circunstancias no resulta útil, ya que los datos de la solicitud no son enviados a través de un formulario HTTP sino como contenido "application/json"
         */
        $webhook_notification = file_get_contents('php://input');

        if ( ! $webhook_notification) {
            return;
        }

        $webhook_data = json_decode($webhook_notification, true);

        // Sólo nos interesa los eventos "business-payment-completed", que corresponden a una autorización y confirmación de pago de parte del cliente
        if ($webhook_data['event_name'] != 'business-payment-completed') {
            return;
        }

        $business_payment_id = $webhook_data['event_resource_id'];

        // Consultamos por el detalle del pago realizado que desencadenó el webhook
        // TODO Validar que los totales calcen
        $business_payment = MACHPay::makeGETRequest('payments/' . $business_payment_id);

        // TODO Obtener este valor desde MACH Pay
        $cart_total = 1;

        $cart = new Cart((int)$webhook_data['event_upstream_id']);

        try {
            $currency = new Currency($cart->id_currency);
            $customer = new Customer($cart->id_customer);

            $this->module->validateOrder(
                $cart->id,
                Configuration::get('PS_OS_PAYMENT'),
                (float)$cart_total,
                $this->module->displayName,
                null,
                array('transaction_id' => $business_payment_id),
                (int)$currency->id,
                false,
                $customer->secure_key
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog('MACH Pay: error al intentar validar la orden: [' . $e->getMessage() . ']');
        }
    }
}