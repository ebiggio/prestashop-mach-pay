{extends "$layout"}
{block name="content"}

<section>
    <h1>MACH Pay - Error al intentar procesar el pago</h1>
    <p>Ocurrió un error al intentar procesar la transacción. Por favor, inténtalo de nuevo.</p>
    <p class="cart_navigation clearfix">
        <a href="{$link->getPageLink('order', true, NULL, "step=3")|escape:'html'}"><span>{l s='Volver a intentar' mod='machpay'}</span></a>
    </p>
</section>
{/block}