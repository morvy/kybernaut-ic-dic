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

// Settings → General → Company fields, on a Slovak shop. The VAT ID is optional
// in every mode: at home it follows the mode, abroad it says who enters it.
const OPTIONAL = ' (optional)';
const VAT_PAYER = ' (optional, only if you are a VAT payer)';
const MARKS = {
	optional: { company: OPTIONAL, ic: OPTIONAL, dic: OPTIONAL, dic_dph: OPTIONAL, ic_de: OPTIONAL },
	plain: { company: '', ic: '', dic: '', dic_dph: '', ic_de: '' },
	asterisk: { company: ' *', ic: ' *', dic: '', dic_dph: '', ic_de: '' },
	required: { company: ' *', ic: ' *', dic: '', dic_dph: '', ic_de: '' },
};
// The block checkout marks nothing it requires.
const blockMark = ( mark ) => ( OPTIONAL === mark ? OPTIONAL : '' );
// WooCommerce puts a non-breaking space before the mark.
const ends = ( text, mark ) => new RegExp( ( text + mark.replace( /[()*]/g, '\\$&' ) ).replace( / /g, '\\s' ) + '$' );

async function classicCheckout( page, context, baseURL ) {
	await context.addCookies( [ { name: 'woolab_e2e_checkout', value: 'classic', url: baseURL } ] );
	await page.goto( '/classic-checkout/' );
	await page.locator( '#billing_iscomp' ).check();
}

async function setCountry( page, country ) {
	await page.evaluate( ( c ) => jQuery( '#billing_country' ).val( c ).trigger( 'change' ), country );
}

for ( const [ mode, marks ] of Object.entries( MARKS ) ) {
	test( `"${ mode }" company fields reach both checkouts`, async ( { page, context, baseURL } ) => {
		await page.goto( url() );
		await setting( page, 'company_fields' ).selectOption( mode );
		await setting( page, 'company_fields' ).dispatchEvent( 'change' );
		await save( page );
		try {
			await expect( setting( page, 'company_fields' ) ).toHaveValue( mode );
			await fillCart( page );

			await page.goto( '/checkout/' );
			await page.locator( '#shipping-country' ).selectOption( 'SK' );
			await page.getByLabel( 'Use same address for billing' ).check();
			await expect.poll( () => page.evaluate( () => wp.data.select( wc.wcBlocksData.CART_STORE_KEY ).getCustomerData().billingAddress.country ) ).toBe( 'SK' );
			await page.locator( '#order-kybernaut-iscomp' ).check();
			const block = ( id ) => page.locator( `label[for="order-kybernaut-${ id }"]` );
			await expect( block( 'company' ) ).toHaveText( ends( 'Company', blockMark( marks.company ) ) );
			await expect( block( 'ic' ) ).toHaveText( ends( 'Business ID', blockMark( marks.ic ) ) );
			await expect( block( 'dic-sk' ) ).toHaveText( ends( 'Tax ID', blockMark( marks.dic ) ) );
			await expect( block( 'dic-dph' ) ).toHaveText( ends( 'VAT ID', blockMark( marks.dic_dph ) ) );
			if ( 'required' === mode ) {
				await page.locator( '.wc-block-components-checkout-place-order-button' ).click();
				await expect( page.locator( '.has-error #order-kybernaut-ic' ) ).toBeVisible();
			}

			await classicCheckout( page, context, baseURL );
			const classic = ( id ) => page.locator( `label[for="billing_${ id }"]` );
			await setCountry( page, 'SK' );
			await expect( classic( 'company' ) ).toHaveText( ends( 'Company( name)?', marks.company ) );
			await expect( classic( 'ic' ) ).toHaveText( ends( 'Business ID', marks.ic ) );
			await expect( classic( 'dic' ) ).toHaveText( ends( 'Tax ID', marks.dic ) );
			await expect( classic( 'dic_dph' ) ).toHaveText( ends( 'VAT ID', marks.dic_dph ) );
			await setCountry( page, 'CZ' );
			await expect( classic( 'dic' ) ).toHaveText( ends( 'VAT ID', VAT_PAYER ) );
			await expect( classic( 'ic' ) ).toHaveText( ends( 'Business ID', marks.ic ) );
			await setCountry( page, 'DE' );
			await expect( classic( 'dic' ) ).toHaveText( ends( 'VAT ID', VAT_PAYER ) );
			await expect( classic( 'ic' ) ).toHaveText( ends( 'Business ID', marks.ic_de ) );
			if ( 'required' === mode ) {
				await setCountry( page, 'CZ' );
				await page.locator( '#billing_company' ).fill( 'Test s.r.o.' );
				await page.locator( '#place_order' ).click();
				await expect( page.locator( '.woocommerce-error, .woocommerce-NoticeGroup-checkout' ).first() ).toContainText( 'Enter a valid Business ID', { timeout: 30000 } );
			}
		} finally {
			await context.clearCookies( { name: 'woolab_e2e_checkout' } );
			await page.goto( url() );
			await setting( page, 'company_fields' ).selectOption( 'optional' );
			await setting( page, 'company_fields' ).dispatchEvent( 'change' );
			await save( page );
		}
	} );
}

test( '"Require DIČ" makes the DIČ of the shop\'s country required for companies', async ( { page, context, baseURL } ) => {
	const set = async ( section, key, apply ) => {
		await page.goto( url( section ) );
		await apply( setting( page, key ) );
		await setting( page, key ).dispatchEvent( 'change' );
		await save( page );
	};
	await set( '', 'company_fields', ( el ) => el.selectOption( 'required' ) );
	await set( '', 'dic_required', ( el ) => el.check() );
	try {
		await fillCart( page );
		await page.goto( '/checkout/' );
		await page.locator( '#shipping-country' ).selectOption( 'SK' );
		await page.getByLabel( 'Use same address for billing' ).check();
		await expect.poll( () => page.evaluate( () => wp.data.select( wc.wcBlocksData.CART_STORE_KEY ).getCustomerData().billingAddress.country ) ).toBe( 'SK' );
		await page.locator( '#order-kybernaut-iscomp' ).check();
		await page.locator( '.wc-block-components-checkout-place-order-button' ).click();
		await expect( page.locator( '.has-error #order-kybernaut-dic-sk' ) ).toBeVisible();

		await classicCheckout( page, context, baseURL );
		await setCountry( page, 'SK' );
		await expect( page.locator( 'label[for="billing_dic"]' ) ).toHaveText( ends( 'Tax ID', ' *' ) );
	} finally {
		await context.clearCookies( { name: 'woolab_e2e_checkout' } );
		await set( '', 'dic_required', ( el ) => el.uncheck() );
		await set( '', 'company_fields', ( el ) => el.selectOption( 'optional' ) );
	}
} );

test( 'a 1.x filter decides over its checkbox, and saving keeps the stored value', async ( { page, context, baseURL } ) => {
	const checkbox = () => setting( page, 'dic_required' );
	await page.goto( url() );
	await checkbox().check();
	await save( page );
	try {
		await context.addCookies( [ { name: 'woolab_e2e_filter', value: 'sk_dic', url: baseURL } ] );
		await page.goto( url() );
		await expect( checkbox() ).not.toBeChecked();
		await expect( checkbox() ).toBeDisabled();
		await expect( page.locator( '#mainform' ) ).toContainText( 'Set by the woolab_icdic_sk_required_ic_and_dic filter' );
		await page.locator( '#mainform :is(input, select):not([type=hidden]):not([disabled])' ).first().dispatchEvent( 'change' );
		await save( page );

		await context.clearCookies( { name: 'woolab_e2e_filter' } );
		await page.goto( url() );
		await expect( checkbox() ).toBeChecked();
		await expect( checkbox() ).toBeEnabled();
	} finally {
		await context.clearCookies( { name: 'woolab_e2e_filter' } );
		await page.goto( url() );
		await checkbox().uncheck();
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

test( 'the suggestion list corner radius reaches the checkout', async ( { page } ) => {
	await page.goto( url( 'appearance' ) );
	await setting( page, 'list_radius' ).fill( '12' );
	await save( page );
	try {
		await expect( setting( page, 'list_radius' ) ).toHaveValue( '12' );
		await fillCart( page );
		await page.goto( '/checkout/' );
		await expect.poll( () => page.evaluate( () => getComputedStyle( document.documentElement ).getPropertyValue( '--woolab-ic-dic-list-radius' ).trim() ) ).toBe( '12px' );
	} finally {
		await page.goto( url( 'appearance' ) );
		await setting( page, 'list_radius' ).fill( '' );
		await save( page );
	}
} );
