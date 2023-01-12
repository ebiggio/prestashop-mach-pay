{extends file='page.tpl'}

{block name='page_content'}
<div class="cart-grid row">
    <div class="cart-grid-body col-xs-12 col-lg-8">
        <div>
            <div class="card-block">
                <h1 class="h1"><img src="{$machpay_logo}" alt=""> {l s='Pago con MACH Pay'}</h1>
            </div>
            <div class="text-lg-center text-md-center text-sm-center">
                <p class="text-sm-center">{l s='Utiliza la aplicación de MACH Pay para escanear el siguiente código QR y autorizar la transacción:' mod='machpay'}</p>
                {nocache}
                <img class="img-responsive img-fluid" src="{$qr}">
                {/nocache}
            </div>
        </div>
    </div>
    <div class="cart-grid-right col-xs-12 col-lg-4">
        {block name='cart_summary'}
        <div class="card cart-summary">

            {block name='hook_shopping_cart'}
            {hook h='displayShoppingCart'}
            {/block}

            {block name='cart_totals'}
            {include file='checkout/_partials/cart-detailed-totals.tpl' cart=$cart}
            {/block}
        </div>
        {/block}
    </div>
</div>
{/block}