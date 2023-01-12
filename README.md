# (WIP) MACH Pay para PrestaShop 1.7

Módulo de pago para PrestaShop que permite realizar pagos con la aplicación MACH Pay.

## Requerimientos

* Extensión `cURL` de PHP
* Tener configurado a Chile (código ISO `CL`) en la tienda donde se desea usar el módulo

## Configuración

* Comprimir todo el contenido de este repositorio en un archivo `zip`
* Instalar el módulo mediante la función *Upload module* del back office de PrestaShop, e ingresar a su configuración
* Definir si se desea utilizar el módulo en el ambiente de sandbox o producción, ingresando la `API key` según corresponda
  * Puedes conseguir tu `API key` en el [sitio oficial de MACH Pay](https://pay.somosmach.com/)
* (Opcional) Configurar las URLs base de la API de MACH. Estas no deberían cambiar de las que sugiere por defecto el módulo al instalarse, pero en caso de ser necesario, se deben ingresar **sin** trailing slash (`/`)
* Configurar como webhook en el back office de MACH Pay la URL que se despliega en la configuración del módulo. Esta URL sólo se muestra como referencia en el formulario, ya que su valor no se puede cambiar 

**Work in progress!**