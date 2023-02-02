<?php
/**
 * Endpoint para procesar las notificaciones mediante webhooks de los cambios de estado recibidos desde MACH Pay
 *
 * @return void
 */
class MACHPayWebhookModuleFrontController extends ModuleFrontController {
    public function postProcess() {
        /*
         * En caso de que el servidor donde está alojada la tienda funcione con Cloudflare, necesitaremos mirar en otro lado la IP del cliente que se está
         * intentando conectar, ya que $_SERVER['REMOTE_ADDR'] tendrá una IP de Cloudflare
         */
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
            $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }

        $remote_ip = $_SERVER['REMOTE_ADDR'];
        $is_authorized = false;

        // Verificamos que la máquina que está invocando el webhook está autorizada a hacerlo, de acuerdo a la lista de IPs configuradas en el módulo
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
         * en estas circunstancias no resulta útil, ya que los datos de la solicitud no son enviados a través de un formulario HTTP sino como contenido
         * "application/json"
         */
        $webhook_notification = file_get_contents('php://input');

        if ( ! $webhook_notification) {
            PrestaShopLogger::addLog('MACH Pay: no se pudo capturar el cuerpo de la solicitud del webhook', 3);

            http_response_code(400);
            exit;
        }

        $webhook_data = json_decode($webhook_notification, true);
        $business_payment_id = $webhook_data['event_resource_id']; // event_resource_id = business_payment_id = token

        if ( ! isset($webhook_data['event_upstream_id'])) {
            PrestaShopLogger::addLog('MACH Pay: no se pudo obtener el ID del carrito desde el cuerpo de la solicitud del webhook (event_upstream_id)'
                , 3);

            http_response_code(400);
            exit;
        }

        $cart = new Cart((int)$webhook_data['event_upstream_id']); // event_upstream_id = id_cart
        $existing_order = $this->getOrderFromCart($cart);

        /*
         * Puede que recibamos un webhook para un pedido que exista, pero que no haya sido creado por este módulo. Por ejemplo, un cliente puede haber intentado
         * pagar con MACH, para luego arrepentirse y proceder con otro método de pago. En ese caso, existirá un pedido en la tienda asociado al carrito que se
         * informó cuando se creó la intención de pago en MACH. Si el método de pago utilizado no fue MACH, no debemos procesar el evento, y simplemente
         * notificamos que el webhook fue recibido exitosamente
         */
        if ($existing_order && $existing_order->payment != $this->module->displayName) {
            http_response_code(200);
            exit;
        }

        // TODO Quizás sea una buena idea que estos valores sean configurables por el módulo
        $mach_pay_events = [
            'business-payment-completed' => Configuration::get('PS_OS_PAYMENT'),   // Pago completado
            'business-payment-failed'    => Configuration::get('PS_OS_ERROR'),     // Pago fallido
            'business-payment-expired'   => Configuration::get('PS_OS_CANCELED'),  // Pago expirado
            'business-refund-completed'  => Configuration::get('PS_OS_REFUND')     // Devolución completa
        ];

        if ( ! array_key_exists($webhook_data['event_name'], $mach_pay_events)) {
            // Desconocemos el evento que estamos recibiendo, y por ende cómo deberíamos procesarlo
            PrestaShopLogger::addLog('MACH Pay: error al procesar webhook. Se recibió el evento desconocido [' . $webhook_data['event_name'] . ']',
                3,
                null,
                'Cart',
                (int)$webhook_data['event_upstream_id']);

            http_response_code(400);
            exit;
        }

        /*
         * Guardamos la información recibida desde MACH Pay, independiente de las validaciones que hagamos después. Estos datos pueden ser útiles para detectar
         * errores/inconsistencias en los eventos que se capturan. En esa línea, la columna "event_upstream_id" está definida como VARCHAR para almacenar
         * cualquier tipo de "event_upstream_id" que se reciba, aunque este dato debiese ser siempre un entero (ya que hace referencia al ID de un carrito)
         */
        DB::getInstance()->insert('machpay_webhook_event', [
            'event_upstream_id' => pSQL($webhook_data['event_upstream_id']),
            'business_payment_id' => pSQL($business_payment_id),
            'event_name' => pSQL($webhook_data['event_name']),
        ]);

        /*
         * Consultamos por el detalle de la transacción que desencadenó el webhook en MACH Pay
         *
         * Si se recibe un evento de pago reversado, el objeto "BusinessPayment" no estará disponible por API. En su lugar, debemos consultar por
         * "BusinessRefund", que es el objeto que se crea cuando se realiza una devolución
         */
        if ($webhook_data['event_name'] == 'business-refund-completed') {
            // TODO Confirmar el ciclo de vida de una devolución. ¿Por qué estados puede pasar una devolución? ¿Qué sucede si se realiza una devolución parcial?
            http_response_code(200);

            exit;
            // $machpay_get_response = MACHPayAPI::makeGETRequest('/business-api/refunds/' . $business_payment_id);
        } else {
            $machpay_get_response = MACHPayAPI::makeGETRequest('/payments/' . $business_payment_id);
        }

        if ($machpay_get_response) {
            $machpay_business_payment_data = json_decode($machpay_get_response, true);
        } else {
            PrestaShopLogger::addLog('MACH Pay: error al consultar por la información del pago [' . $business_payment_id . '] en la API de MACH Pay',
                3,
                null,
                'Cart',
                (int)$webhook_data['event_upstream_id']);

            http_response_code(500);
            exit;
        }

        /*
         * Validamos que el evento que estamos recibiendo corresponda al estado actual del pago en MACH Pay. Si se trata de una devolución, se debe consultar
         * por el el atributo "state" del objeto "BusinessRefund". En caso contrario, se debe consultar por el atributo "status" del objeto "BusinessPayment"
         */
        if ($webhook_data['event_name'] == 'business-refund-completed' && $machpay_business_payment_data['state'] != 'COMPLETED') {
            $log_message = 'MACH Pay: se recibió una notificación de devolución completada, pero el estado de esta informada por la API es ['
                . $machpay_business_payment_data['state'] . ']';
        } elseif (strtoupper(substr($webhook_data['event_name'], 17)) != $machpay_business_payment_data['status']) {
            $log_message = 'MACH Pay: inconsistencia en notificación recibida por webhook. El evento [' . $webhook_data['event_name']
                . '] no equivale al estado actual [' . $machpay_business_payment_data['status'] . '] informado por la API';
        }

        if (isset($log_message)) {
            PrestaShopLogger::addLog($log_message, 3, null, 'Cart', (int)$webhook_data['event_upstream_id']);

            http_response_code(500);
            exit;
        }

        if ( ! $machpay_ps_id_cart = $this->getIdCartForMACHPayData($business_payment_id)) {
            PrestaShopLogger::addLog('MACH Pay: error al recibir notificación para el evento [' . $webhook_data['event_name']
                . ']. El ID del carrito no se encontró en la tabla "PS_machpay" para el identificador [' . $business_payment_id . ']',
                3,
                null,
                'Cart',
                (int)$webhook_data['event_upstream_id']);

            http_response_code(404);
            exit;
        } else if ($machpay_ps_id_cart != $webhook_data['event_upstream_id']) {
            PrestaShopLogger::addLog('MACH Pay: evento [' . $webhook_data['event_name'] . '] recibido por webhook para un carrito distinto al asociado a ['
                . $business_payment_id . ']: se esperaba [' . $machpay_ps_id_cart . '], se recibió [' . $webhook_data['event_upstream_id'] . ']',
                3,
                null,
                'Cart',
                (int)$webhook_data['event_upstream_id']);

            http_response_code(404);
            exit;
        }

        if ($webhook_data['event_name'] == 'business-payment-completed') {
            $this->processPaymentComplete($existing_order, $mach_pay_events[$webhook_data['event_name']], $cart, $machpay_business_payment_data
                , $business_payment_id);
        } else {
            // Verificamos si existe un pedido asociado al carrito
            if ($existing_order && $existing_order->getCurrentState() != $mach_pay_events[$webhook_data['event_name']]) {
                /*
                 * Al existir ya un pedido, revisamos si su estado equivale a los definidos para el evento que se está recibiendo. En caso de no ser iguales,
                 * actualizamos el estado del pedido al que representa el cambio recibido
                 */
                $existing_order->setCurrentState($mach_pay_events[$webhook_data['event_name']]);
            }

            DB::getInstance()->update('machpay', ['machpay_webhook_event' => pSQL($webhook_data['event_name'])], 'business_payment_id = "'
                . pSQL($business_payment_id) . '"');
        }

        http_response_code(200);
        exit;
    }

    private function getIdCartForMACHPayData(string $business_payment_id): int {
        $sql = "SELECT id_cart FROM " . _DB_PREFIX_ . "machpay WHERE business_payment_id = '" . pSQL($business_payment_id) . "' ORDER BY id_machpay DESC";

        return (int)Db::getInstance()->getRow($sql)['id_cart'];
    }

    /**
     * Procesa el evento de un pago completado
     *
     * @param       $existing_order
     * @param       $ps_complete_status_id
     * @param \Cart $cart
     * @param       $machpay_business_payment_data
     * @param       $business_payment_id
     * @return void
     */
    public function processPaymentComplete($existing_order, $ps_complete_status_id, Cart $cart, $machpay_business_payment_data, $business_payment_id): void {
        /*
         * Puede que recibamos una notificación de pago completado para un pedido que ya se aprobó con anterioridad. Quizás, por problemas de red, recibamos
         * una notificación doble de un pago válido. Verificamos si existe un pedido asociado para el carrito con el estado asociado al evento de pago
         * completado, ya que en caso de ser así, no necesitamos llevar a cabo ni la confirmación ni la reversa
         */
        if ($existing_order && $existing_order->getCurrentState() == $ps_complete_status_id) {
            PrestaShopLogger::addLog('MACH Pay: notificación de pago completado para carrito con pedido asociado ['
                . $existing_order->id . '] y estado [' . $existing_order->getCurrentState() . ']',
                1,
                null,
                'Cart',
                $cart->id);

            http_response_code(200);
            exit;
        }

        if ($this->validatePaymentComplete((int)$machpay_business_payment_data['amount'], $cart)) {
            /*
             * Revisamos si el pago completado debe ser confirmado en MACH Pay, de acuerdo a la configuración del módulo. Los pagos completados de negocios
             * que tengan captura manual deben ser confirmados luego de la generación del pedido. En caso de tratarse de capturas automáticas, esta opción
             * debe estar apagada para evitar errores al intentar confirmar pagos ya definidos mediante la API de MACH Pay
             */
            if (Configuration::get('MACHPAY_MANUAL_CONFIRMATION')) {
                if ( ! MACHPayAPI::makePOSTRequest('/payments/' . $business_payment_id . '/confirm', [])) {
                    PrestaShopLogger::addLog('MACH Pay: error al intentar confirmar el pago [' . $business_payment_id . '] en la API de MACH Pay',
                        3,
                        null,
                        'Cart',
                        $cart->id);

                    DB::getInstance()->update('machpay', ['machpay_webhook_event' => 'prestashop_error'], 'business_payment_id = "'
                        . pSQL($business_payment_id) . '"');

                    $this->reversePayment($machpay_business_payment_data, $cart->id);

                    http_response_code(500);
                    exit;
                }
            }

            try {
                $currency = new Currency($cart->id_currency);
                $customer = new Customer($cart->id_customer);

                $this->module->validateOrder(
                    $cart->id,
                    $ps_complete_status_id,
                    (float)$cart->getOrderTotal(),
                    $this->module->displayName,
                    null,
                    array('transaction_id' => $business_payment_id),
                    (int)$currency->id,
                    false,
                    $customer->secure_key
                );

                /*
                 * Recién aquí actualizamos la tabla en la BD con el estado de pago completado, para que luego dicho cambio le sea informado al cliente
                 * mediante un EventSource activo en la página que despliega el QR
                 */
                DB::getInstance()->update('machpay', ['machpay_webhook_event' => 'business-payment-completed'], 'business_payment_id = "'
                    . pSQL($business_payment_id) . '"');
            } catch (Exception $e) {
                PrestaShopLogger::addLog('MACH Pay: excepción al intentar generar la orden luego de recibir notificación de pago por webhook: ['
                    . $e->getMessage() . ']',
                    3,
                    null,
                    'Cart',
                    $cart->id);

                $this->reversePayment($machpay_business_payment_data, $cart->id);

                DB::getInstance()->update('machpay', ['machpay_webhook_event' => 'prestashop_error'], 'business_payment_id = "'
                    . pSQL($business_payment_id) . '"');

                http_response_code(503);
                exit;
            }
        } else {
            DB::getInstance()->update('machpay', ['machpay_webhook_event' => 'prestashop_error'], 'business_payment_id = "'
                . pSQL($business_payment_id) . '"');

            $this->reversePayment($machpay_business_payment_data, $cart->id);
        }
    }

    private function validatePaymentComplete(int $machpay_amount, $cart): bool {
        // Verificamos si existe ya una orden para este carrito, que tendría un estado distinto al pagado. De ser así, no deberíamos procesar otro pago asociado
        if ($existing_order = $this->getOrderFromCart($cart)) {
            PrestaShopLogger::addLog('MACH Pay: error en validación del pago completado. Ya existe la orden ['
                . $existing_order->reference . '] asociada al carrito',
                3,
                null,
                'Cart',
                (int)$cart->id);

            return false;
        }

        try {
            $cart_total = $cart->getOrderTotal();
        } catch (Exception $e) {
            PrestaShopLogger::addLog('MACH Pay: error al intentar obtener el total del carrito desde la tienda para validar pago completado en MACH Pay',
                3,
                null,
                'Cart',
                (int)$cart->id);

            return false;
        }

        // Validamos que el total del carrito sea igual al monto pagado en MACH Pay
        if ((int)$cart_total != $machpay_amount) {
            PrestaShopLogger::addLog('MACH Pay: error al validar los totales para confirmar el pedido: el monto del carrito [' . $cart_total
                . '] no es igual a lo pagado en MACH Pay: [' . $machpay_amount . ']',
                3,
                null,
                'Cart',
                (int)$cart->id);

            return false;
        }

        return true;
    }

    /**
     * Devuelve la orden asociada a un carrito
     *
     * @param $cart
     * @return false|\Order
     */
    private function getOrderFromCart($cart) {
        if ( ! $cart->orderExists()) {
            return false;
        } else {
            $id_order = Order::getIdByCartId((int)$cart->id);

            try {
                return new Order($id_order);
            } catch (PrestaShopDatabaseException|PrestaShopException $e) {
                PrestaShopLogger::addLog('MACH Pay: excepción al intentar obtener el pedido [' . $id_order . '] asociado al carrito: ['
                    . $e->getMessage() . ']',
                    3,
                    null,
                    'Cart',
                    $cart->id);

                return false;
            }
        }
    }

    /**
     * Realiza una reversa sobre un pago en estado "completado" (transacción confirmada por el cliente)
     *
     * @param array $machpay_business_payment_data
     * @param int    $id_cart
     * @return void
     */
    private function reversePayment(array $machpay_business_payment_data, int $id_cart) {
        if ($machpay_business_payment_data['status'] != 'COMPLETED') {
            return;
        }

        if ( ! MACHPayAPI::makePOSTRequest('/payments/' . $machpay_business_payment_data['business_payment_id'] . '/reverse', [])) {
            PrestaShopLogger::addLog('MACH Pay: error al intentar realizar una reversa del pago ['
                . $machpay_business_payment_data['business_payment_id'] . '] en la API de MACH Pay',
                3,
                null,
                'Cart',
                $id_cart);
        }
    }
}