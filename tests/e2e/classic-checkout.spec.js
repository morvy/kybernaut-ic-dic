// Classic (shortcode) checkout: the same rules as the block checkout, on
// /classic-checkout/ (tests/e2e/mu-plugin.php makes it WooCommerce's checkout page).
const { test, expect } = require( '@playwright/test' );
const { fillCart, expectIdentifiersOnce, COMPANY, SHOPPER } = require( './helpers' );

const row = ( page, id ) => page.locator( `#billing_${ id }_field` );
const input = ( page, id ) => page.locator( `#billing_${ id }` );
const label = ( page, id ) => page.locator( `label[for="billing_${ id }"]` );
const vatRow = ( page ) => page.locator( '.woocommerce-checkout-review-order-table' ).locator( '.tax-rate, .tax-total' );

async function setCountry( page, country ) {
	// selectWoo hides the real <select>; WooCommerce listens to its change event.
	await page.evaluate( ( c ) => jQuery( '#billing_country' ).val( c ).trigger( 'change' ), country );
	await expect( input( page, 'country' ) ).toHaveValue( country );
}

async function asCompany( page ) {
	await input( page, 'iscomp' ).check();
	await expect( row( page, 'company' ) ).toBeVisible();
}

// The review table reloads over AJAX after each change; wait for it to settle.
const settled = ( page ) => expect( page.locator( '.woocommerce-checkout-review-order-table.processing, .blockUI' ) ).toHaveCount( 0 );

test.beforeEach( async ( { page, context, baseURL } ) => {
	await fillCart( page );
	await context.addCookies( [ { name: 'woolab_e2e_checkout', value: 'classic', url: baseURL } ] );
	await page.goto( '/classic-checkout/' );
	await expect( input( page, 'iscomp' ) ).toBeVisible();
} );

test( 'company fields stay hidden until "Buying as a company" is ticked', async ( { page } ) => {
	await expect( row( page, 'company' ) ).toBeHidden();
	await expect( row( page, 'ic' ) ).toBeHidden();
	await asCompany( page );
	await expect( row( page, 'ic' ) ).toBeVisible();
	await input( page, 'iscomp' ).uncheck();
	await expect( row( page, 'ic' ) ).toBeHidden();
} );

test( 'the tax fields and their labels follow the billing country', async ( { page } ) => {
	await asCompany( page );

	await setCountry( page, 'SK' );
	await expect( label( page, 'dic' ) ).toContainText( 'Tax ID' );
	await expect( row( page, 'dic_dph' ) ).toBeVisible();
	await expect( label( page, 'dic_dph' ) ).toContainText( 'VAT ID' );

	await setCountry( page, 'CZ' );
	await expect( label( page, 'dic' ) ).toContainText( 'VAT ID' );
	await expect( row( page, 'dic_dph' ) ).toBeHidden();

	await setCountry( page, 'DE' );
	await expect( label( page, 'dic' ) ).toContainText( 'VAT ID' );
	await expect( row( page, 'dic_dph' ) ).toBeHidden();
	await expect( row( page, 'ic' ) ).toBeVisible();
} );

test( 'a guest\'s company fields survive a reload', async ( { page } ) => {
	await setCountry( page, 'SK' );
	for ( const key of [ 'first_name', 'last_name', 'address_1', 'city', 'postcode', 'phone', 'email' ] ) {
		await input( page, key ).fill( SHOPPER[ key ] );
	}
	await asCompany( page );
	// Typed, not filled: WooCommerce refreshes the order review on key presses.
	await input( page, 'company' ).pressSequentially( 'Firma s.r.o.' );
	await input( page, 'dic' ).pressSequentially( '2020317057' );
	await input( page, 'dic' ).blur();
	await page.waitForResponse( /update_order_review/ );
	await settled( page );

	await page.reload();
	await expect( input( page, 'iscomp' ) ).toBeChecked();
	await expect( input( page, 'company' ) ).toHaveValue( 'Firma s.r.o.' );
	await expect( input( page, 'dic' ) ).toHaveValue( '2020317057' );
} );

test( 'a Czech IČO fills the company and the address from ARES', async ( { page } ) => {
	await setCountry( page, 'CZ' );
	await asCompany( page );
	await input( page, 'ic' ).fill( COMPANY.ico );
	await input( page, 'ic' ).blur();
	await expect( input( page, 'company' ) ).toHaveValue( COMPANY.name );
	await expect( input( page, 'dic' ) ).toHaveValue( COMPANY.dic );
	await expect( input( page, 'city' ) ).toHaveValue( 'Praha 7' );
} );

test( 'a valid foreign EU VAT ID makes the order VAT exempt', async ( { page } ) => {
	await setCountry( page, 'CZ' );
	await asCompany( page );
	await settled( page );
	await expect( vatRow( page ) ).toHaveCount( 1 );

	await input( page, 'dic' ).fill( COMPANY.dic );
	await input( page, 'dic' ).blur();
	await page.evaluate( () => jQuery( document.body ).trigger( 'update_checkout' ) );
	await expect( vatRow( page ) ).toHaveCount( 0, { timeout: 30000 } );
	await expect( page.getByText( 'Reverse charge' ).first() ).toBeVisible();

	await setCountry( page, 'SK' );
	await expect( vatRow( page ) ).toHaveCount( 1, { timeout: 30000 } );
} );

test( 'an invalid IČO stops the order', async ( { page } ) => {
	await setCountry( page, 'CZ' );
	await asCompany( page );
	await input( page, 'ic' ).fill( '12345678' );
	for ( const key of [ 'first_name', 'last_name', 'address_1', 'city', 'postcode', 'phone', 'email' ] ) {
		await input( page, key ).fill( SHOPPER[ key ] );
	}
	await input( page, 'company' ).fill( 'Test s.r.o.' );
	await settled( page );
	await page.locator( '#place_order' ).click();
	await expect( page.locator( '.woocommerce-error, .woocommerce-NoticeGroup-checkout' ).first() ).toBeVisible( { timeout: 30000 } );
	await expect( page ).not.toHaveURL( /order-received/ );
	// Under the field, as WooCommerce reports its own.
	await expect( row( page, 'ic' ).locator( '.checkout-inline-error-message' ) ).toContainText( 'Business ID' );
} );

test( 'a store\'s own check is reported under its field', async ( { page, context, baseURL } ) => {
	await context.addCookies( [ { name: 'woolab_e2e_check', value: 'pl', url: baseURL } ] );
	try {
		await page.reload();
		await setCountry( page, 'PL' );
		await asCompany( page );
		for ( const key of [ 'first_name', 'last_name', 'address_1', 'city', 'phone', 'email' ] ) {
			await input( page, key ).fill( SHOPPER[ key ] );
		}
		await input( page, 'postcode' ).fill( '00-001' );
		await input( page, 'company' ).fill( 'Firma sp. z o.o.' );
		// A valid NIP, so only the store's check objects.
		await input( page, 'dic' ).fill( 'PL1132191233' );
		await settled( page );
		await page.locator( '#place_order' ).click();
		await expect( row( page, 'dic' ).locator( '.checkout-inline-error-message' ) ).toContainText( 'E2E: this NIP is not on the whitelist.', { timeout: 30000 } );
		await expect( page ).not.toHaveURL( /order-received/ );
	} finally {
		await context.clearCookies( { name: 'woolab_e2e_check' } );
	}
} );

test( 'the order carries the company and no VAT', async ( { page } ) => {
	await setCountry( page, 'CZ' );
	await asCompany( page );
	await input( page, 'ic' ).fill( COMPANY.ico );
	await input( page, 'ic' ).blur();
	await expect( input( page, 'dic' ) ).toHaveValue( COMPANY.dic );
	for ( const key of [ 'first_name', 'last_name', 'phone', 'email' ] ) {
		await input( page, key ).fill( SHOPPER[ key ] );
	}
	await page.evaluate( () => jQuery( document.body ).trigger( 'update_checkout' ) );
	await expect( vatRow( page ) ).toHaveCount( 0, { timeout: 30000 } );
	await settled( page );
	await page.locator( '#place_order' ).click();

	await expect( page ).toHaveURL( /order-received/, { timeout: 30000 } );
	await expectIdentifiersOnce( page );
	// €10 product, free shipping: €12.30 with Slovak VAT.
	await expect( page.locator( 'body' ) ).toContainText( /10[.,]00/ );
	await expect( page.locator( 'body' ) ).not.toContainText( /12[.,]30/ );
} );

// A Czech business that is no VAT payer: VIES does not list its DIČ (the stub in
// mu-plugin.php lists Alza's only), and that is no reason to turn it away.
test( 'a Czech DIČ VIES does not list orders with VAT, and says why', async ( { page } ) => {
	await setCountry( page, 'CZ' );
	await asCompany( page );
	for ( const key of [ 'first_name', 'last_name', 'address_1', 'city', 'postcode', 'phone', 'email' ] ) {
		await input( page, key ).fill( SHOPPER[ key ] );
	}
	await input( page, 'company' ).fill( 'Neplátce s.r.o.' );
	await input( page, 'dic' ).fill( 'CZ00006947' );
	await input( page, 'dic' ).blur();
	await page.evaluate( () => jQuery( document.body ).trigger( 'update_checkout' ) );
	await expect( page.getByText( 'is not registered for VAT in the EU (VIES)' ).first() ).toBeVisible( { timeout: 30000 } );
	await expect( vatRow( page ) ).toHaveCount( 1 );

	await settled( page );
	await page.locator( '#place_order' ).click();
	await expect( page ).toHaveURL( /order-received/, { timeout: 30000 } );
	await expect( page.locator( 'body' ) ).toContainText( /12[.,]30/ );
} );
