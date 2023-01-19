# (WIP) MACH Pay para PrestaShop 1.7

Módulo de pago para PrestaShop que permite realizar pagos con la aplicación MACH Pay.

## Requerimientos

* Extensión `cURL` de PHP
* Tener configurado a Chile (código ISO `CL`) en la tienda donde se desea usar el módulo

## Configuración

* Guardar todo el contenido de este repositorio en una carpeta de nombre `machpay`, y luego comprimir dicha carpeta en un archivo `zip`
* Instalar el módulo mediante la función "Upload module" del back office de PrestaShop, e ingresar a su configuración
* Definir si se desea utilizar el módulo en el ambiente de sandbox o producción, ingresando la `API key` según corresponda
  * Puedes conseguir tu `API key` en el [sitio oficial de MACH Pay](https://pay.somosmach.com/)
* Seleccionar si se deberán confirmar los pagos una vez que se reciba la notificación de un pago completado. Esta configuración depende de cómo esté definido el negocio en MACH Pay:
  * Si la captura de pagos se realiza de forma manual, esta opción debe estar activa
  * En caso de que la captura de pagos sea automática, esta opción se debe apagar, ya que de lo contrario el módulo intentará confirmar el pago mediante API, recibiendo un error al ya estar el pago confirmado
  * Es importante tener presente que cuando la captura es manual, **un pago completado que no es confirmado generará una reversa transcurridos 5 minutos**
* (Opcional) Configurar las URLs base de la API de MACH Pay. Estas no deberían cambiar de las que sugiere por defecto el módulo al instalarse, pero en caso de ser necesario, se deben ingresar **sin** trailing slash (`/`)
* Configurar como webhook la URL que despliega la configuración del módulo en el [back office de MACH Pay](https://pay.somosmach.com/settings/webhooks). Esta URL sólo se muestra como referencia en el formulario para un fácil *copy/paste*; su valor no puede ser cambiado
* (Opcional) Especificar las IPs autorizadas a invocar el webhook. Estas IPs corresponden a los servidores de MACH PAy desde donde se despachan los eventos. Al igual que con las URLs base de la API, la lista que se presenta al instalar el módulo no debería cambiar, pero si lo deseas, puedes cambiar estos valores ingresando IPs separadas por comas

**Work in progress!**