<?php
class MACHPayPaymentErrorModuleFrontController extends ModuleFrontController {
    public function initContent() {
        $this->display_column_left = false;
        $this->display_column_right = false;

        parent::initContent();

        $this->setTemplate('module:machpay/views/templates/front/payment_error.tpl');
    }
}