<?php
/**
 * Endpoint para procesar las notificaciones mediante webhooks de los cambios de estado recibidos desde MACH Pay
 *
 * @return void
 */
class MACHPayWebhookModuleFrontController extends ModuleFrontController {
    public function postProcess() {
        // Verificamos que la máquina que está invocando el webhook está autorizada a hacerlo, de acuerdo a la lista de IPs configuradas en el módulo
        $remote_ip = $_SERVER['REMOTE_ADDR'];
        $is_authorized = false;

        foreach (explode(',', Configuration::get('MACHPAY_WEBHOOK_IPS')) as $authorized_ip) {
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
        $business_payment_id = $webhook_data['event_resource_id']; // event_resource_id = business_payment_id = token

        $mach_pay_events = [
            'business-payment-completed',   // Pago completado
            'business-payment-failed',      // Pago fallido
            'business-payment-expired',     // Pago expirado
            'business-refund-completed'     // Devolución completa
        ];

        if ( ! in_array($webhook_data['event_name'], $mach_pay_events)) {
            // Desconocemos el evento que estamos recibiendo, y por ende cómo deberíamos procesarlo
            PrestaShopLogger::addLog('MACH Pay: error al procesar webhook. Se recibió el evento desconocido [' . $webhook_data['event_name'] . ']',
                3,
                null,
                'Cart',
                (int)$webhook_data['event_upstream_id']);

            return;
        }

        $cart = new Cart((int)$webhook_data['event_upstream_id']);

        if ($webhook_data['event_name'] == 'business-payment-completed') {
            if ($this->validatePaymentComplete($business_payment_id, $cart)) {
                // Recibimos la notificación de un pago exitoso. Se debe crear un pedido a partir del carrito indicado
                try {
                    $currency = new Currency($cart->id_currency);
                    $customer = new Customer($cart->id_customer);

                    $this->module->validateOrder(
                        $cart->id,
                        Configuration::get('PS_OS_PAYMENT'),
                        (float)$cart->getOrderTotal(),
                        $this->module->displayName,
                        null,
                        array('transaction_id' => $business_payment_id),
                        (int)$currency->id,
                        false,
                        $customer->secure_key
                    );
                } catch (Exception $e) {
                    PrestaShopLogger::addLog('MACH Pay: error al generar la orden luego de recibir notificación de pago en MACH Pay: [' . $e->getMessage() . ']',
                        3,
                        null,
                        'Cart',
                        $cart->id);

                    return;
                }

                /*
                 * Revisamos si el pago completado debe ser confirmado en MACH Pay, de acuerdo a la configuración del módulo. Los pagos completados de negocios
                 * que tengan captura manual deben ser confirmados luego de la generación del pedido. En caso de tratarse de capturas automáticas, esta opción
                 * debe estar apagada para evitar errores al intentar confirmar pagos ya definidos mediante la API de MACH Pay
                 */
                if (Configuration::get('MACHPAY_MUST_CONFIRM')) {
                    if (MACHPayAPI::makePOSTRequest('/payments/' . $business_payment_id . '/confirm', [])) {
                        DB::getInstance()->update('machpay', ['machpay_webhook_event' => 'business-payment-completed'], 'business_payment_id = "' . pSQL($business_payment_id) . '"');

                        http_response_code(200);
                    } else {
                        PrestaShopLogger::addLog('MACH Pay: error al confirmar el pago [' . $business_payment_id . '] en la API de MACH Pay',
                            3,
                            null,
                            'Cart',
                            $cart->id);
                    }
                } else {
                    DB::getInstance()->update('machpay', ['machpay_webhook_event' => 'business-payment-completed'], 'business_payment_id = "' . pSQL($business_payment_id) . '"');
                }
            } else {
                $this->reversePayment($business_payment_id, $cart->id);
            }
        } else {
            DB::getInstance()->update('machpay', ['machpay_webhook_event' => pSQL($webhook_data['event_name'])], 'business_payment_id = "' . pSQL($business_payment_id) . '"');
        }
    }

    private function validatePaymentComplete(string $business_payment_id, $cart): bool {
        // Revisamos si el identificador de pago está asociado al carrito que se desea procesar
        $sql = "SELECT id_cart FROM " . _DB_PREFIX_ . "machpay WHERE business_payment_id = '" . pSQL($business_payment_id) . "' ORDER BY id_machpay DESC";
        $result = Db::getInstance()->getRow($sql);

        if ($result) {
            if ($result['id_cart'] != $cart->id) {
                PrestaShopLogger::addLog('MACH Pay: evento de pago completado recibido por webhook para un carrito distinto al asociado a ['
                    . $business_payment_id . ']: se esperaba [' . $result['id_cart'] . '], se recibió [' . $cart->id . ']',
                    3,
                    null,
                    'Cart',
                    (int)$cart->id);

                return false;
            }
        } else {
            return false;
        }

        // Consultamos por el detalle del pago realizado en MACH Pay que desencadenó el webhook
        if ($machpay_get_response = MACHPayAPI::makeGETRequest('/payments/' . $business_payment_id)) {
            $machpay_business_payment_data = json_decode($machpay_get_response, true);
        } else {
            PrestaShopLogger::addLog('MACH Pay: error al consultar por la información del pago [' . $business_payment_id . '] en la API de MACH Pay',
                3,
                null,
                'Cart',
                (int)$cart->id);

            return false;
        }

        // Verificamos si existe ya una orden creada para este carrito. De ser así, no deberíamos procesar otro pago asociado
        if (Order::getIdByCartId((int)$cart->id)) {
            return false;
        }

        try {
            $cart_total = $cart->getOrderTotal();
        } catch (Exception $e) {
            PrestaShopLogger::addLog('MACH Pay: error al intentar obtener el total del carrito desde la tienda para validar el pago completado en MACH Pay',
                3,
                null,
                'Cart',
                (int)$cart->id);

            return false;
        }

        // Validamos que el total del carrito sea igual al monto pagado en MACH Pay
        if ((int)$cart_total != (int)$machpay_business_payment_data['amount']) {
            PrestaShopLogger::addLog('MACH Pay: error al validar los totales para confirmar el pedido: el monto del carrito [' . $cart_total
                . '] no es igual a lo pagado en MACH Pay: [' . $machpay_business_payment_data['amount'] . ']',
                3,
                null,
                'Cart',
                (int)$cart->id);

            return false;
        }

        return true;
    }

    /**
     * Realiza una reversa sobre un pago en estado "completado" (transacción confirmada por el cliente)
     *
     * @param string $business_payment_id
     * @param int    $cart_id
     * @return void
     */
    private function reversePayment(string $business_payment_id, int $cart_id) {
        if (MACHPayAPI::makePOSTRequest('/payments/' . $business_payment_id . '/reverse', [])) {
            PrestaShopLogger::addLog('MACH Pay: error al intentar realizar una reversa del pago [' . $business_payment_id . '] en la API de MACH Pay',
                3,
                null,
                'Cart',
                $cart_id);
        }
    }
}