// The proof of the VAT ID check on the order (_woolab_icdic_vat_evidence): with the
// shop's VAT ID set, VIES (tests/e2e/mu-plugin.php) returns a consultation number,
// and both checkouts keep it with an exempt order; the admin's "Validate VAT ID"
// order action asks again and sets the VAT.
const { test, expect } = require( '@playwright/test' );
const { fillCart, COMPANY, SHOPPER } = require( './helpers' );

const USER = process.env.E2E_ADMIN_USER || 'admin';
const PASSWORD = process.env.E2E_ADMIN_PASSWORD || 'password';
const SHOP_VAT_ID = 'SK2020317068';

// The admin in a context of its own: the shopper stays a guest.
let admin;

async function setShopVatId( value ) {
	await admin.goto( '/wp-admin/admin.php?page=wc-settings&tab=woolab_icdic&section=validation' );
	const input = admin.locator( '[name="woolab_icdic_settings[shop_vat_id]"]' );
	await input.fill( value );
	await input.dispatchEvent( 'change' );
	await admin.locator( 'button[name="save"]' ).click();
	await expect( admin.locator( '#message.updated, .notice-success' ).first() ).toBeVisible();
	await expect( input ).toHaveValue( value );
}

test.beforeAll( async ( { browser, baseURL } ) => {
	const context = await browser.newContext( { baseURL, ignoreHTTPSErrors: true } );
	admin = await context.newPage();
	await admin.goto( '/wp-login.php' );
	await admin.locator( '#user_login' ).fill( USER );
	await admin.locator( '#user_pass' ).fill( PASSWORD );
	await admin.locator( '#wp-submit' ).click();
	await expect( admin.locator( '#wpadminbar' ) ).toBeVisible();
	await setShopVatId( SHOP_VAT_ID );
} );

test.afterAll( async () => {
	await setShopVatId( '' );
	await admin.context().close();
} );

const orderId = ( page ) => Number( page.url().match( /order-received\/(\d+)/ )[ 1 ] );

// The order edit screen, HPOS or posts.
async function openOrder( id ) {
	await admin.goto( `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }` );
	if ( ! ( await admin.locator( '#order_data' ).count() ) ) {
		await admin.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
	}
	await expect( admin.locator( '#order_data' ) ).toBeVisible();
}

async function expectEvidence( id ) {
	await openOrder( id );
	const box = admin.locator( '.woolab-icdic-vat-evidence' );
	await expect( box.locator( '.woolab-icdic-badge--valid' ) ).toHaveText( 'Valid' );
	await expect( box ).toContainText( 'Reverse charge, without VAT' );
	await expect( box ).toContainText( 'VAT ID ' + COMPANY.dic );
	await expect( box ).toContainText( /Consultation number\s+WAPIE2E\w{9}/ );
	await expect( box ).toContainText( 'Name Alza.cz a.s.' );
	await expect( box ).toContainText( 'billing CZ, VAT ID CZ' );

	// The orders list marks it in its narrow column.
	await admin.goto( '/wp-admin/admin.php?page=wc-orders' );
	if ( ! ( await admin.locator( '.wp-list-table' ).count() ) ) {
		await admin.goto( '/wp-admin/edit.php?post_type=shop_order' );
	}
	await expect( admin.locator( `#order-${ id } .woolab-icdic-reverse-charge, #post-${ id } .woolab-icdic-reverse-charge` ) ).toHaveCount( 1 );
}

test( 'an exempt block checkout order keeps the VIES consultation number', async ( { page } ) => {
	await fillCart( page );
	await page.goto( '/checkout/' );
	await page.locator( '#shipping-country' ).selectOption( 'CZ' );
	await expect.poll( () => page.evaluate( () => wp.data.select( wc.wcBlocksData.CART_STORE_KEY ).getCustomerData().billingAddress.country ) ).toBe( 'CZ' );
	await page.locator( '#order-kybernaut-iscomp' ).check();
	await page.locator( '#order-kybernaut-ic' ).fill( COMPANY.ico );
	await page.locator( '#order-kybernaut-ic' ).blur();
	await expect( page.locator( '#order-kybernaut-dic-cz' ) ).toHaveValue( COMPANY.dic );
	await expect.poll( () => page.evaluate( () => wp.data.select( wc.wcBlocksData.CART_STORE_KEY ).getCartTotals().total_tax ), { timeout: 30000 } ).toBe( '0' );
	await expect( page.locator( '#shipping-city' ) ).toHaveValue( 'Praha 7' );
	await page.locator( '#email' ).fill( SHOPPER.email );
	for ( const key of [ 'first_name', 'last_name' ] ) {
		await page.locator( `#shipping-${ key }` ).fill( SHOPPER[ key ] );
	}
	await page.locator( '.wc-block-components-checkout-place-order-button' ).click();
	await expect( page ).toHaveURL( /order-received/, { timeout: 30000 } );

	await expectEvidence( orderId( page ) );
} );

// The classic checkout on /classic-checkout/ (tests/e2e/mu-plugin.php).
async function classicCheckout( page, context, baseURL ) {
	await fillCart( page );
	await context.addCookies( [ { name: 'woolab_e2e_checkout', value: 'classic', url: baseURL } ] );
	await page.goto( '/classic-checkout/' );
	await page.evaluate( () => jQuery( '#billing_country' ).val( 'CZ' ).trigger( 'change' ) );
	await page.locator( '#billing_iscomp' ).check();
	await expect( page.locator( '#billing_company_field' ) ).toBeVisible();
}

const settled = ( page ) => expect( page.locator( '.woocommerce-checkout-review-order-table.processing, .blockUI' ) ).toHaveCount( 0 );
const vatRow = ( page ) => page.locator( '.woocommerce-checkout-review-order-table' ).locator( '.tax-rate, .tax-total' );

test( 'an exempt classic checkout order keeps the VIES consultation number', async ( { page, context, baseURL } ) => {
	try {
		await classicCheckout( page, context, baseURL );
		await page.locator( '#billing_ic' ).fill( COMPANY.ico );
		await page.locator( '#billing_ic' ).blur();
		await expect( page.locator( '#billing_dic' ) ).toHaveValue( COMPANY.dic );
		for ( const key of [ 'first_name', 'last_name', 'phone', 'email' ] ) {
			await page.locator( `#billing_${ key }` ).fill( SHOPPER[ key ] );
		}
		await page.evaluate( () => jQuery( document.body ).trigger( 'update_checkout' ) );
		await expect( vatRow( page ) ).toHaveCount( 0, { timeout: 30000 } );
		await settled( page );
		await page.locator( '#place_order' ).click();
		await expect( page ).toHaveURL( /order-received/, { timeout: 30000 } );

		await expectEvidence( orderId( page ) );
	} finally {
		await context.clearCookies( { name: 'woolab_e2e_checkout' } );
	}
} );

test( '"Validate VAT ID" on the order screen asks VIES again and removes the VAT', async ( { page, context, baseURL } ) => {
	let id;
	try {
		// A Czech business VIES does not list: the order is placed with VAT.
		await classicCheckout( page, context, baseURL );
		for ( const key of [ 'first_name', 'last_name', 'address_1', 'city', 'postcode', 'phone', 'email' ] ) {
			await page.locator( `#billing_${ key }` ).fill( SHOPPER[ key ] );
		}
		await page.locator( '#billing_company' ).fill( 'Neplátce s.r.o.' );
		await page.locator( '#billing_dic' ).fill( 'CZ00006947' );
		await page.locator( '#billing_dic' ).blur();
		await page.evaluate( () => jQuery( document.body ).trigger( 'update_checkout' ) );
		await expect( page.getByText( 'is not registered for VAT in the EU (VIES)' ).first() ).toBeVisible( { timeout: 30000 } );
		await settled( page );
		await page.locator( '#place_order' ).click();
		await expect( page ).toHaveURL( /order-received/, { timeout: 30000 } );
		await expect( page.locator( 'body' ) ).toContainText( /12[.,]30/ );
		id = orderId( page );
	} finally {
		await context.clearCookies( { name: 'woolab_e2e_checkout' } );
	}

	await openOrder( id );
	await expect( admin.locator( '.woolab-icdic-vat-evidence' ) ).toContainText( 'With VAT' );

	// The admin corrects the VAT ID, and validates it in the same save.
	await admin.locator( '.order_data_column' ).nth( 1 ).locator( 'a.edit_address' ).click();
	await admin.locator( '#_billing_billing_dic' ).fill( COMPANY.dic );
	await admin.locator( '.woolab-icdic-revalidate' ).click();

	await expect( admin.locator( '#order_data' ) ).toBeVisible();
	await expect( admin.locator( '.order_notes' ) ).toContainText( `VAT ID ${ COMPANY.dic } is valid in VIES: the order is without VAT (reverse charge), totals recalculated.` );
	await expect( admin.locator( '.order_notes' ) ).toContainText( /Consultation number: WAPIE2E\w{9}\./ );
	await expect( admin.locator( '.woolab-icdic-vat-evidence' ) ).toContainText( 'Reverse charge, without VAT' );
	await expect( admin.locator( '.woolab-icdic-vat-evidence-history summary' ) ).toHaveText( '1 earlier check' );
	await admin.locator( '.woolab-icdic-vat-evidence-history summary' ).click();
	await expect( admin.locator( '.woolab-icdic-vat-evidence-history' ) ).toContainText( 'CZ00006947' );
	const totals = admin.locator( '.wc-order-totals' ).first();
	await expect( totals ).toContainText( /10[.,]00/ );
	await expect( totals ).not.toContainText( /12[.,]30/ );
} );
