<?php
/**
 * La desinstalación del módulo de MACH Pay no elimina las tablas asociadas en la base de datos. Esto, en caso de un usuario quiera reinicializar el módulo
 * pero sin perder la información de las transacciones emitidas con anterioridad
 */
$sql = array();

foreach ($sql as $query) {
    if ( ! Db::getInstance()->execute($query)) {
        return false;
    }
}
