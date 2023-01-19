<?php
class MACHPayPaymentErrorModuleFrontController extends ModuleFrontController {
    public function initContent() {
        $this->display_column_left = false;
        $this->display_column_right = false;

        parent::initContent();

        $this->context->smarty->assign(
            [
                'machpay_logo'     => Media::getMediaPath(_PS_MODULE_DIR_ . 'machpay/views/img/machpay.png'),
            ]
        );

        $this->setTemplate('module:machpay/views/templates/front/payment_error.tpl');
    }
}