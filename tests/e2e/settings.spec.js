// WooCommerce → Settings → IČO DIČ: every section saves, and what is saved reaches
// the checkout.
const { test, expect } = require( '@playwright/test' );
const { fillCart } = require( './helpers' );

const USER = process.env.E2E_ADMIN_USER || 'admin';
const PASSWORD = process.env.E2E_ADMIN_PASSWORD || 'password';
const url = ( section = '' ) => '/wp-admin/admin.php?page=wc-settings&tab=woolab_icdic' + ( section ? '&section=' + section : '' );
const setting = ( page, key ) => page.locator( `[name="woolab_icdic_settings[${ key }]"]` );

async function save( page ) {
	await page.locator( 'button[name="save"]' ).click();
	await expect( page.locator( '#message.updated, .notice-success' ).first() ).toBeVisible();
}

test.beforeEach( async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( USER );
	await page.locator( '#user_pass' ).fill( PASSWORD );
	await page.locator( '#wp-submit' ).click();
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
} );

for ( const section of [ '', 'validation', 'appearance' ] ) {
	test( `the ${ section || 'general' } section saves`, async ( { page } ) => {
		await page.goto( url( section ) );
		// WooCommerce enables Save only once the form changed; nothing is changed.
		await page.locator( '#mainform :is(input, select):not([type=hidden])' ).first().dispatchEvent( 'change' );
		await save( page );
	} );
}

test( 'the Finančná správa section shows the import status, read-only', async ( { page } ) => {
	await page.goto( url( 'financna_sprava' ) );
	await expect( page.locator( '.woolab-icdic-sk-status, .form-table' ).first() ).toBeVisible();
	await expect( page.locator( 'button[name="save"]' ) ).toHaveCount( 0 );
} );

test( '"Hide (optional)" reaches the checkout', async ( { page } ) => {
	await page.goto( url() );
	await setting( page, 'hide_optional' ).check();
	await save( page );
	try {
		await expect( setting( page, 'hide_optional' ) ).toBeChecked();
		await fillCart( page );
		await page.goto( '/checkout/' );
		await expect( page.locator( '#order-kybernaut-iscomp' ) ).toBeVisible();
		await page.locator( '#order-kybernaut-iscomp' ).check();
		await expect( page.locator( 'label[for="order-kybernaut-ic"]' ) ).not.toContainText( 'optional' );
	} finally {
		await page.goto( url() );
		await setting( page, 'hide_optional' ).uncheck();
		await save( page );
	}
} );

test( 'a status icon colour reaches the checkout', async ( { page } ) => {
	await page.goto( url( 'appearance' ) );
	await setting( page, 'color_ok' ).fill( '#123456' );
	await save( page );
	try {
		await expect( setting( page, 'color_ok' ) ).toHaveValue( '#123456' );
		await fillCart( page );
		await page.goto( '/checkout/' );
		await expect.poll( () => page.evaluate( () => getComputedStyle( document.documentElement ).getPropertyValue( '--woolab-ic-dic-color-ok' ).trim() ) ).toBe( '#123456' );
	} finally {
		await page.goto( url( 'appearance' ) );
		await setting( page, 'color_ok' ).fill( '' );
		await save( page );
	}
} );
