<?php
$sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'machpay` (
            `id_machpay` int(11) NOT NULL AUTO_INCREMENT,
            `id_cart` INT(11) NOT NULL,
            `cart_total` INT(11) NOT NULL,
            `business_payment_id` varchar(60) DEFAULT NOT NULL,
            `machpay_created_at` datetime DEFAULT NOT NULL,
            `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_machpay`)
        ) ENGINE=' . _MYSQL_ENGINE_ . '
        COMMENT=\'Guarda la información de las transacciones realizadas en MACH Pay\';';

if ( ! Db::getInstance()->execute($sql)) {

    return false;
}