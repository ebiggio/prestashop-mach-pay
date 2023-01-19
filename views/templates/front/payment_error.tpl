{extends file='page.tpl'}

{block name="content"}
    <h1 class="h1"><img src="{$machpay_logo}" alt=""> {l s='MACH Pay - Error' mod='machpay'}</h1>
    <p>{l s='Ocurrió un error al intentar procesar el pago con MACH Pay. Por favor, inténtalo nuevamente.' mod='machpay'}</p>
    <p class="cart_navigation clearfix">
        <a href="{$link->getPageLink('order', true, NULL, "step=3")|escape:'html'}"><span>{l s='Volver a intentar' mod='machpay'}</span></a>
    </p>
{/block}