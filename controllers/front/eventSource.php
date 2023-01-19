<?php
class MACHPayEventSourceModuleFrontController extends ModuleFrontController {
    public function postProcess() {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');

        $redirect_url = '';

        if ($business_payment_id = Tools::getValue('bpi')) {
            $sql =
                "SELECT id_cart, machpay_webhook_event
                FROM " . _DB_PREFIX_ . "machpay
                WHERE business_payment_id = '" . pSQL($business_payment_id) . "' AND machpay_webhook_event IS NOT NULL
                ORDER BY id_machpay DESC";
            $result = Db::getInstance()->getRow($sql);

            if ($result) {
                if ($result['machpay_webhook_event'] == 'business-payment-completed') {
                    $cart = new Cart((int)$result['id_cart']);
                    $order = new Order(Order::getIdByCartId((int)$result['id_cart']));

                    if ($order->getCurrentState() == Configuration::get('PS_OS_PAYMENT')) {
                        $redirect_url = '/index.php?controller=order-confirmation&id_cart='.$cart->id.'&id_module='.$this->module->id.'&id_order='.$order->id.'&key='.$cart->secure_key;
                    } else {
                        $redirect_url = $this->context->link->getModuleLink($this->module->name, 'paymentError');
                    }
                } else {
                    $redirect_url = $this->context->link->getModuleLink($this->module->name, 'paymentError');
                }
            }
        }

        if ($redirect_url) {
            echo "event: redirect\n";
            echo 'data: {"url": "' . $redirect_url . '"}';
            echo "\n\n";

        }

        ob_flush();
        flush();

        exit;
    }
}