{extends file='page.tpl'}
{block name="content"}

<h3>{l s='MACH Pay - Error al intentar iniciar el pago' mod='machpay'}</h3>
<p>{l s='Ocurrió un error al intentar iniciar el pago con MACH Pay. Por favor, inténtalo nuevamente.' mod='machpay'}</p>
<p class="cart_navigation clearfix">
    <a href="{$link->getPageLink('order', true, NULL, "step=3")|escape:'html'}"><span>{l s='Volver a intentar' mod='machpay'}</span></a>
</p>
{/block}