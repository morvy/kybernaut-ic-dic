// Fluid Checkout for WooCommerce - Lite (src/Compat/FluidCheckout.php): its steps,
// its "+ Add …" sections and the billing fields it re-renders after every refresh.
// On with the cookie woolab_e2e_fc=1 (mu-plugin.php), over the classic checkout.
const { test, expect } = require( '@playwright/test' );
const { fillCart, COMPANY, SHOPPER } = require( './helpers' );

const input = ( page, id ) => page.locator( `#billing_${ id }` );
const row = ( page, id ) => page.locator( `#billing_${ id }_field` );
const settled = ( page ) => expect( page.locator( '.blockUI' ) ).toHaveCount( 0 );

// Contact and shipping filled in, on the billing step.
async function toBilling( page, context, baseURL, country, postcode ) {
	await context.addCookies( [
		{ name: 'woolab_e2e_checkout', value: 'classic', url: baseURL },
		{ name: 'woolab_e2e_fc', value: '1', url: baseURL },
	] );
	await fillCart( page );
	await page.goto( '/classic-checkout/' );
	await page.locator( '#billing_email' ).fill( SHOPPER.email );
	await page.getByRole( 'button', { name: /Proceed to shipping/i } ).click();
	await page.evaluate( ( c ) => jQuery( '#shipping_country' ).val( c ).trigger( 'change' ), country );
	// The country re-renders the address fields.
	await expect( page.locator( '#shipping_postcode' ) ).toBeVisible();
	await settled( page );
	for ( const key of [ 'first_name', 'last_name', 'address_1', 'city' ] ) {
		await page.locator( `#shipping_${ key }` ).fill( SHOPPER[ key ] );
	}
	await page.locator( '#shipping_postcode' ).fill( postcode );
	await settled( page );
	await page.getByRole( 'button', { name: /Proceed to billing/i } ).click();
	await expect( input( page, 'iscomp' ) ).toBeVisible();
	await settled( page );
}

async function tick( page, on ) {
	await input( page, 'iscomp' ).setChecked( on, { force: true } );
	await settled( page );
}

test( 'a company looked up in ARES orders without VAT, billed at the shipping address', async ( { page, context, baseURL } ) => {
	await toBilling( page, context, baseURL, 'CZ', SHOPPER.postcode );
	await tick( page, true );
	// Not behind "+ Add …", and shown again after Fluid Checkout's re-render.
	await expect( row( page, 'ic' ) ).toBeVisible();
	await expect( row( page, 'company' ) ).toBeVisible();

	await input( page, 'ic' ).fill( COMPANY.ico );
	await expect( input( page, 'company' ) ).toHaveValue( COMPANY.name );
	await expect( input( page, 'dic' ) ).toHaveValue( COMPANY.dic );
	// "Same as shipping address" stays: only the identifiers are filled in.
	await expect( page.locator( '#billing_same_as_shipping' ) ).toBeChecked();
	// Required from WooCommerce 11.3.
	if ( await input( page, 'phone' ).isVisible() ) {
		await input( page, 'phone' ).fill( SHOPPER.phone );
	}
	await settled( page );

	await page.getByRole( 'button', { name: /Proceed to payment/i } ).click();
	await page.locator( '#place_order' ).click();
	await expect( page ).toHaveURL( /order-received/, { timeout: 30000 } );
	// €10 product, free shipping: no Slovak VAT (€12.30 with it).
	await expect( page.locator( 'body' ) ).toContainText( /10[.,]00/ );
	await expect( page.locator( 'body' ) ).not.toContainText( /12[.,]30/ );
} );

test( 'unticking hides the company fields, ticking again brings them back with their values', async ( { page, context, baseURL } ) => {
	await toBilling( page, context, baseURL, 'SK', '811 01' );
	await tick( page, true );
	await expect( row( page, 'dic_dph' ) ).toBeVisible();
	await input( page, 'company' ).fill( 'Firma s.r.o.' );
	await input( page, 'dic' ).fill( '2020317057' );
	await settled( page );

	await tick( page, false );
	await expect( row( page, 'company' ) ).toBeHidden();
	await expect( row( page, 'dic' ) ).toBeHidden();

	await tick( page, true );
	await expect( input( page, 'company' ) ).toHaveValue( 'Firma s.r.o.' );
	await expect( input( page, 'dic' ) ).toHaveValue( '2020317057' );
	await expect( row( page, 'dic_dph' ) ).toBeVisible();
} );
