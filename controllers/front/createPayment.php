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
            $cart_total = $cart->getOrderTotal();
        } catch (Exception $e) {
            return;
        }

        $payment_details = [
            'payment' => [
                'amount'      => (int)$cart_total,
                'title'       => 'Pago en ' . Configuration::get('PS_SHOP_NAME') . ' | Carrito ' . $cart->id,
                'upstream_id' => (string)$cart->id
            ]
        ];

        // Generamos la intención de pago
        if ($machpay_post_response = MACHPay::makePOSTRequest('/payments', $payment_details)) {
            $machpay_json_response = json_decode($machpay_post_response, true);

            $business_payment_id = $machpay_json_response['business_payment_id'];

            // Obtenemos el QR en base64 desde MACH haciendo una solicitud GET
            if ($machpay_get_response = MACHPay::makeGETRequest('/payments/' . $business_payment_id . '/qr')) {
                try {
                    // Guardamos la información de la transacción generada en MACH Pay
                    Db::getInstance()->insert('machpay', [
                        'id_cart'             => (int)$cart->id,
                        'cart_total'          => (int)$cart_total,
                        'business_payment_id' => pSQL($business_payment_id),
                        'machpay_created_at'  => pSQL($machpay_json_response['created_at'])
                    ]);

                    $machpay_json_response = json_decode($machpay_get_response, true);

                    $this->context->smarty->assign(
                        [
                            'machpay_logo' => Media::getMediaPath(_PS_MODULE_DIR_ . 'machpay/views/img/machpay.png'),
                            'qr'           => $machpay_json_response['image_base_64']
                        ]
                    );

                    $this->setTemplate('module:machpay/views/templates/front/present_qr.tpl');

                    return;
                } catch (PrestaShopDatabaseException $e) {
                    PrestaShopLogger::addLog('MACH Pay: error al intentar guardar la información de la transacción de MACH Pay en la base de datos',
                        3,
                        null,
                        'DB',
                        $cart->id);
                } catch (PrestaShopException $e) {
                    PrestaShopLogger::addLog('MACH Pay: error al intentar presentar la vista con el código QR de pago',
                        3,
                        null,
                        'Template',
                        $cart->id);
                }
            } else {
                PrestaShopLogger::addLog('MACH Pay: error al intentar obtener el QR desde la API de MACH Pay',
                    3,
                    null,
                    'Cart',
                    $cart->id);
            }
        } else {
            PrestaShopLogger::addLog('MACH Pay: error al intentar generar una intención de pago en [' . MACHPay::getConfiguration()['machpay_api_url'] . ']',
                3,
                null,
                'Cart',
                $cart->id);
        }

        $this->setTemplate('module:machpay/views/templates/front/payment_error.tpl');
    }
}