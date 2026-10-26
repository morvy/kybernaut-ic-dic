// WooCommerce → EC Sales List: an exempt order (VIES answered by mu-plugin.php) is in
// its customer's row of the order's month, in the preview, the CSV and the SK XML
// (once the shop's details are filled in, Settings → IČO DIČ → EC Sales List).
const fs = require( 'fs' );
const { test, expect } = require( '@playwright/test' );
const { fillCart, COMPANY, SHOPPER } = require( './helpers' );

const USER = process.env.E2E_ADMIN_USER || 'admin';
const PASSWORD = process.env.E2E_ADMIN_PASSWORD || 'password';
const settings = ( section ) => `/wp-admin/admin.php?page=wc-settings&tab=woolab_icdic&section=${ section }`;
const setting = ( page, key ) => page.locator( `[name="woolab_icdic_settings[${ key }]"]` );
const VAT = COMPANY.dic.slice( 2 );

// The second test downloads the first one's month.
test.describe.configure( { mode: 'serial' } );

// The admin in a context of its own: the shopper stays a guest.
let admin;
// The EC Sales List of the placed order's month (the order's date on the site).
let page_url;
// The shop's details before the spec, restored after.
let saved;

async function saveSettings( section, values ) {
	await admin.goto( settings( section ) );
	for ( const [ key, value ] of Object.entries( values ) ) {
		await setting( admin, key ).fill( value );
		await setting( admin, key ).dispatchEvent( 'change' );
	}
	await admin.locator( 'button[name="save"]' ).click();
	await expect( admin.locator( '#message.updated, .notice-success' ).first() ).toBeVisible();
}

async function download( name ) {
	await admin.goto( page_url );
	const [ file ] = await Promise.all( [ admin.waitForEvent( 'download' ), admin.getByRole( 'link', { name } ).click() ] );
	return fs.readFileSync( await file.path(), 'utf8' );
}

// The customer's goods row of the CSV: value and orders, zero when there is none
// (flagged or not: the site may have exempt orders from before the VAT ID evidence).
async function csvRow() {
	const csv = await download( 'Download CSV' );
	expect( csv.startsWith( '﻿Country,"VAT number",' ) ).toBe( true );
	const row = csv.match( new RegExp( `^CZ,${ VAT },goods,0,0,(-?\\d+\\.\\d{2}),EUR,(\\d+),(?:yes)?$`, 'm' ) );
	return row ? { value: Number( row[ 1 ] ), orders: Number( row[ 2 ] ) } : { value: 0, orders: 0 };
}

// The order edit screen, HPOS or posts.
async function openOrder( id ) {
	await admin.goto( `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }` );
	if ( ! ( await admin.locator( '#order_data' ).count() ) ) {
		await admin.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
	}
	await expect( admin.locator( '#order_data' ) ).toBeVisible();
}

test.beforeAll( async ( { browser, baseURL } ) => {
	const context = await browser.newContext( { baseURL, ignoreHTTPSErrors: true } );
	admin = await context.newPage();
	await admin.goto( '/wp-login.php' );
	await admin.locator( '#user_login' ).fill( USER );
	await admin.locator( '#user_pass' ).fill( PASSWORD );
	await admin.locator( '#wp-submit' ).click();
	await expect( admin.locator( '#wpadminbar' ) ).toBeVisible();

	await admin.goto( settings( 'ec_sales_list' ) );
	saved = { ec_company: await setting( admin, 'ec_company' ).inputValue(), ec_person: await setting( admin, 'ec_person' ).inputValue() };
} );

test.afterAll( async () => {
	await saveSettings( 'validation', { shop_vat_id: '' } );
	await saveSettings( 'ec_sales_list', saved );
	await admin.context().close();
} );

test( 'an exempt order is in its row in the CSV, until it is cancelled', async ( { page } ) => {
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
	const id = Number( page.url().match( /order-received\/(\d+)/ )[ 1 ] );

	// The month of the order's date on the site.
	await openOrder( id );
	const [ year, month ] = ( await admin.locator( 'input[name="order_date"]' ).inputValue() ).split( '-' ).map( Number );
	page_url = `/wp-admin/admin.php?page=woolab-icdic-ec-sales-list&period=m${ month }&year=${ year }`;

	const placed = await csvRow();
	await admin.goto( page_url );
	await expect( admin.locator( '.woolab-icdic-ec-sales-list tbody tr', { hasText: VAT } ).first() ).toContainText( 'Goods' );

	// Cancelled, the order leaves the statement: it was in it with its 10.00 net.
	await openOrder( id );
	await admin.locator( '#order_status' ).selectOption( 'wc-cancelled' );
	await admin.locator( 'button.save_order' ).click();
	await expect( admin.locator( '#order_data' ) ).toBeVisible();
	const cancelled = await csvRow();
	expect( placed.orders - cancelled.orders ).toBe( 1 );
	expect( placed.value - cancelled.value ).toBeCloseTo( 10, 2 );
} );

test( 'the SK XML needs the shop\'s details, then lists the row', async () => {
	await saveSettings( 'validation', { shop_vat_id: '' } );
	await admin.goto( page_url );
	await expect( admin.getByRole( 'link', { name: 'Download SK XML (eDane)' } ) ).toHaveCount( 0 );
	await expect( admin.locator( '.woolab-icdic-ec-sales-list' ) ).toContainText( 'SK XML (in EUR only): fill in Your VAT ID in Validation (SK and 10 digits)' );

	await saveSettings( 'ec_sales_list', { ec_company: 'Woolab Test s.r.o.', ec_person: 'Jana Testová' } );
	await saveSettings( 'validation', { shop_vat_id: 'SK2020317068' } );

	const xml = await download( 'Download SK XML (eDane)' );
	const month = page_url.match( /period=m(\d+)/ )[ 1 ];
	expect( xml ).toContain( '<dic>2020317068</dic>' );
	expect( xml ).toContain( '<riadok>Woolab Test s.r.o.</riadok>' );
	expect( xml ).toContain( `<mesiac>${ month.padStart( 2, '0' ) }</mesiac>` );
	expect( xml ).toMatch( new RegExp( `<kodStatu>CZ</kodStatu>\\s*<idCislo>${ VAT }</idCislo>\\s*<hodnota>\\d+</hodnota>\\s*<kod>0</kod>` ) );
} );
