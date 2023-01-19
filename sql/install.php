<?php
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'machpay` (
            `id_machpay` INT(11) NOT NULL AUTO_INCREMENT,
            `id_cart` INT(11) NOT NULL,
            `cart_total` INT(11) NOT NULL,
            `business_payment_id` VARCHAR(60) DEFAULT NOT NULL,
            `machpay_webhook_event` VARCHAR(50) DEFAULT NULL NULL,
            `machpay_created_at` datetime DEFAULT NOT NULL,
            `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_machpay`)
        ) ENGINE=' . _MYSQL_ENGINE_ . '
        COMMENT=\'Guarda la información de las intenciones de pago generadas en MACH Pay\';';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'machpay_webhook_event` (
            `id_machpay_webhook_event` INT(11) NOT NULL AUTO_INCREMENT,
            `event_upstream_id` VARCHAR(30) NOT NULL,
            `business_payment_id` VARCHAR(60) DEFAULT NOT NULL,
            `event_name` VARCHAR(50) DEFAULT NULL NULL,
            `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_machpay_webhook_event`)
        ) ENGINE=' . _MYSQL_ENGINE_ . '
        COMMENT=\'Almacena los eventos recibidos mediante webhook desde MACH Pay\';';

foreach ($sql as $query) {
    if ( ! Db::getInstance()->execute($query)) {

        return false;
    }
}