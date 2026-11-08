// Block checkout: which company fields each billing country gets, the "Buying as
// a company" toggle, the billing country behind "Use same address for billing",
// the ARES autofill, the live intra-EU VAT exemption, and the order it places.
const { test, expect } = require( '@playwright/test' );
const { fillCart, expectIdentifiersOnce, expectMessageColor, expectOneError, COMPANY, PL_COMPANY, SHOPPER } = require( './helpers' );

const field = ( page, id ) => page.locator( `#order-kybernaut-${ id }` );
// The field with what shows under it: WooCommerce's error inside its wrapper, the
// lookup's message after it.
const fieldRow = ( page, id ) => page.locator( '.wc-block-components-address-form' ).filter( { has: field( page, id ) } );

async function openCheckout( page ) {
	await fillCart( page );
	await page.goto( '/checkout/' );
	await expect( field( page, 'iscomp' ) ).toBeVisible();
}

async function setCountry( page, country ) {
	await page.locator( '#shipping-country' ).selectOption( country );
	// The billing country the field rules read follows the shipping one.
	await expect.poll( () => page.evaluate( () => wp.data.select( wc.wcBlocksData.CART_STORE_KEY ).getCustomerData().billingAddress.country ) ).toBe( country );
}

async function asCompany( page ) {
	await field( page, 'iscomp' ).check();
	await expect( field( page, 'company' ) ).toBeVisible();
}

// Whatever ARES did not fill in.
async function fillAddress( page ) {
	await page.locator( '#email' ).fill( SHOPPER.email );
	for ( const key of [ 'first_name', 'last_name', 'address_1', 'city', 'postcode' ] ) {
		const input = page.locator( `#shipping-${ key }` );
		if ( ! ( await input.inputValue() ) ) {
			await input.fill( SHOPPER[ key ] );
		}
	}
}

const taxTotal = ( page ) => page.evaluate( () => wp.data.select( wc.wcBlocksData.CART_STORE_KEY ).getCartTotals().total_tax );

test.beforeEach( async ( { page } ) => {
	await openCheckout( page );
} );

test( 'a guest\'s company fields survive a reload', async ( { page } ) => {
	await setCountry( page, 'SK' );
	await asCompany( page );
	await field( page, 'company' ).fill( 'Firma s.r.o.' );
	await field( page, 'ic' ).fill( '27082440' );
	await field( page, 'dic-sk' ).fill( '2020317057' );
	const saved = page.waitForResponse( ( r ) => /wc\/store\/v1\/checkout/.test( r.url() ) && r.request().postData()?.includes( '2020317057' ) );
	await field( page, 'dic-sk' ).blur();
	await saved;

	await page.reload();
	await expect( field( page, 'iscomp' ) ).toBeChecked();
	await expect( field( page, 'company' ) ).toHaveValue( 'Firma s.r.o.' );
	await expect( field( page, 'ic' ) ).toHaveValue( '27082440' );
	await expect( field( page, 'dic-sk' ) ).toHaveValue( '2020317057' );
} );

// WooCommerce's form mounts before the cart has the billing country and empties the
// fields a country rule hides; the saved IČO and DIČ come back (blocks.js), from the
// customer rather than the draft order (Blocks::stored_value()).
test( 'a logged-in customer\'s saved IČO and DIČ are filled in, VAT exempt', async ( { browser, baseURL } ) => {
	const context = await browser.newContext( { baseURL, ignoreHTTPSErrors: true } );
	const page = await context.newPage();
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'e2e-customer' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /my-account|wp-admin/ );
	await openCheckout( page );

	await expect( field( page, 'iscomp' ) ).toBeChecked();
	await expect( field( page, 'ic' ) ).toHaveValue( COMPANY.ico );
	await expect( field( page, 'dic-cz' ) ).toHaveValue( COMPANY.dic );
	await expect.poll( () => taxTotal( page ), { timeout: 30000 } ).toBe( '0' );
	await context.close();
} );

test( 'company fields stay hidden until "Buying as a company" is ticked', async ( { page } ) => {
	await setCountry( page, 'SK' );
	await expect( field( page, 'company' ) ).toBeHidden();
	await expect( field( page, 'ic' ) ).toBeHidden();
	await asCompany( page );
	await expect( field( page, 'ic' ) ).toBeVisible();
	await field( page, 'iscomp' ).uncheck();
	await expect( field( page, 'ic' ) ).toBeHidden();
} );

test( 'each billing country gets its own tax fields', async ( { page } ) => {
	await asCompany( page );

	await setCountry( page, 'SK' );
	await expect( field( page, 'ic' ) ).toBeVisible();
	await expect( field( page, 'dic-sk' ) ).toBeVisible();
	await expect( field( page, 'dic-dph' ) ).toBeVisible();
	await expect( field( page, 'dic-cz' ) ).toBeHidden();
	await expect( field( page, 'dic' ) ).toBeHidden();

	await setCountry( page, 'CZ' );
	await expect( field( page, 'dic-cz' ) ).toBeVisible();
	await expect( field( page, 'dic-sk' ) ).toBeHidden();
	await expect( field( page, 'dic-dph' ) ).toBeHidden();

	await setCountry( page, 'DE' );
	await expect( field( page, 'dic' ) ).toBeVisible();
	await expect( field( page, 'dic-cz' ) ).toBeHidden();
	// Not every company has a Business ID there: a field without the checks.
	await expect( field( page, 'ic-eu' ) ).toBeVisible();
	await expect( field( page, 'ic' ) ).toBeHidden();
} );

test( 'a Czech IČO fills the company from ARES', async ( { page } ) => {
	await setCountry( page, 'CZ' );
	await asCompany( page );
	await field( page, 'ic' ).fill( COMPANY.ico );
	await field( page, 'ic' ).blur();
	await expect( field( page, 'company' ) ).toHaveValue( COMPANY.name );
	await expect( field( page, 'dic-cz' ) ).toHaveValue( COMPANY.dic );
} );

test( 'requests of its own: one IČO lookup and one VAT update, nothing before', async ( { page } ) => {
	// Every request is a full WordPress + WooCommerce boot, often on shared
	// hosting: the plugin adds only the ones with something to decide.
	const own = [];
	page.on( 'request', ( request ) => {
		const url = decodeURIComponent( request.url() );
		if ( url.includes( 'woolab-ic-dic/v1/' ) || ( request.postData() || '' ).includes( '"namespace":"woolab-ic-dic"' ) ) {
			own.push( url.includes( 'woolab-ic-dic/v1/lookup' ) ? 'lookup' : url.includes( 'woolab-ic-dic/v1/search' ) ? 'search' : 'vat' );
		}
	} );
	await page.reload();
	await expect( field( page, 'iscomp' ) ).toBeVisible();
	await setCountry( page, 'CZ' );
	await asCompany( page );
	await page.waitForTimeout( 2000 );
	expect( own, 'nothing without a VAT ID' ).toEqual( [] );

	await field( page, 'ic' ).fill( COMPANY.ico );
	await field( page, 'ic' ).blur();
	await expect( field( page, 'dic-cz' ) ).toHaveValue( COMPANY.dic );
	await expect.poll( () => taxTotal( page ), { timeout: 30000 } ).toBe( '0' );
	await page.waitForTimeout( 2000 );
	expect( own ).toEqual( [ 'lookup', 'vat' ] );
} );

test( 'the ARES address survives slow Store API responses', async ( { page } ) => {
	// Every Store API batch takes 4 s, so the country change is still on its way
	// when ARES fills the address and the VAT update follows.
	await page.route( /wc\/store\/v1\/batch/, async ( route ) => {
		await new Promise( ( resolve ) => setTimeout( resolve, 4000 ) );
		await route.continue();
	} );
	await page.locator( '#shipping-country' ).selectOption( 'CZ' );
	await asCompany( page );
	await field( page, 'ic' ).fill( COMPANY.ico );
	await field( page, 'ic' ).blur();
	await expect( page.locator( '#shipping-city' ) ).toHaveValue( 'Praha 7' );
	// Long enough for every response sent so far to have come back.
	await page.waitForTimeout( 10000 );
	await expect( page.locator( '#shipping-city' ) ).toHaveValue( 'Praha 7' );
	await expect( page.locator( '#shipping-address_1' ) ).toHaveValue( /Jankovcova/ );
} );

test( 'a valid foreign EU VAT ID makes the order VAT exempt, live', async ( { page } ) => {
	await setCountry( page, 'CZ' );
	await asCompany( page );
	await expect.poll( () => taxTotal( page ) ).not.toBe( '0' );

	await field( page, 'dic-cz' ).fill( COMPANY.dic );
	await field( page, 'dic-cz' ).blur();
	await expect.poll( () => taxTotal( page ), { timeout: 30000 } ).toBe( '0' );
	await expect( page.getByText( 'Reverse charge' ).first() ).toBeVisible();

	// Back to the shop's own country: VAT again.
	await setCountry( page, 'SK' );
	await expect.poll( () => taxTotal( page ), { timeout: 30000 } ).not.toBe( '0' );
	await expect( page.locator( '.wc-block-components-notice-banner' ).getByText( 'Reverse charge' ) ).toHaveCount( 0 );
} );

test( 'after a reload the VAT ID and the VAT agree', async ( { page } ) => {
	await setCountry( page, 'CZ' );
	await asCompany( page );
	await field( page, 'dic-cz' ).fill( COMPANY.dic );
	await field( page, 'dic-cz' ).blur();
	await expect.poll( () => taxTotal( page ), { timeout: 30000 } ).toBe( '0' );

	// The Checkout block saves the address after a pause, and on WooCommerce 10.0
	// only once it is complete; reloading earlier loses it.
	await fillAddress( page );
	await expect.poll( async () => ( await ( await page.request.get( '/?rest_route=/wc/store/v1/cart' ) ).json() ).shipping_address.country ).toBe( 'CZ' );

	// A guest's 'order' fields do not reliably survive a reload (WooCommerce keeps
	// them in its checkout draft at best), so neither may the exemption: whatever
	// the page shows, no VAT ID means VAT, and typing it again exempts the order.
	await page.reload();
	await expect( field( page, 'iscomp' ) ).toBeVisible();
	if ( ! ( await field( page, 'iscomp' ).isChecked() ) ) {
		await asCompany( page );
	}
	if ( await field( page, 'dic-cz' ).inputValue() ) {
		// Restored (WooCommerce 10.0's checkout draft): so is the exemption.
		await expect.poll( () => taxTotal( page ), { timeout: 30000 } ).toBe( '0' );
		return;
	}
	await expect.poll( () => taxTotal( page ), { timeout: 30000 } ).not.toBe( '0' );
	await field( page, 'dic-cz' ).fill( COMPANY.dic );
	await field( page, 'dic-cz' ).blur();
	await expect.poll( () => taxTotal( page ), { timeout: 30000 } ).toBe( '0' );
} );

test( 'an invalid IČO is reported at the field', async ( { page } ) => {
	await setCountry( page, 'CZ' );
	await asCompany( page );
	await field( page, 'ic' ).fill( '12345678' );
	await field( page, 'ic' ).blur();
	const error = page.locator( '.wc-block-components-validation-error' ).first();
	await expect( error ).toBeVisible();
	await expectMessageColor( error, 'error' );

	// Placing the order adds no second one.
	await fillAddress( page );
	await page.locator( '.wc-block-components-checkout-place-order-button' ).click();
	await expect( page ).not.toHaveURL( /order-received/ );
	await expectOneError( fieldRow( page, 'ic' ) );
} );

test( 'the lookup says it found the company, in the success colour', async ( { page } ) => {
	await setCountry( page, 'CZ' );
	await asCompany( page );
	await field( page, 'ic' ).fill( COMPANY.ico );
	const message = fieldRow( page, 'ic' ).locator( '.woolab-ic-dic-message--success' );
	await expect( message ).toContainText( 'ARES' );
	await expectMessageColor( message, 'success' );
} );

test( 'the order carries the company and no VAT', async ( { page } ) => {
	await setCountry( page, 'CZ' );
	await asCompany( page );
	await field( page, 'ic' ).fill( COMPANY.ico );
	await field( page, 'ic' ).blur();
	await expect( field( page, 'dic-cz' ) ).toHaveValue( COMPANY.dic );
	await expect.poll( () => taxTotal( page ), { timeout: 30000 } ).toBe( '0' );

	// ARES filled the street, city and postcode.
	await expect( page.locator( '#shipping-city' ) ).toHaveValue( 'Praha 7' );
	await fillAddress( page );
	await page.locator( '.wc-block-components-checkout-place-order-button' ).click();

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
	await field( page, 'company' ).fill( 'Neplátce s.r.o.' );
	await field( page, 'dic-cz' ).fill( 'CZ00006947' );
	await field( page, 'dic-cz' ).blur();
	await expect( page.getByText( 'is not registered for VAT in the EU (VIES)' ).first() ).toBeVisible( { timeout: 30000 } );
	expect( await taxTotal( page ) ).not.toBe( '0' );

	// WooCommerce drops a click while it saves the address it just got.
	const saved = page.waitForResponse( ( r ) => r.url().includes( '__experimental_calc_totals' ), { timeout: 10000 } ).catch( () => null );
	await fillAddress( page );
	await page.locator( '#shipping-postcode' ).blur();
	await saved;
	await page.locator( '.wc-block-components-checkout-place-order-button' ).click();
	await expect( page ).toHaveURL( /order-received/, { timeout: 30000 } );
	await expect( page.locator( 'body' ) ).toContainText( /12[.,]30/ );
} );

test( 'a Polish NIP VIES does not list orders with VAT too', async ( { page } ) => {
	await setCountry( page, 'PL' );
	await asCompany( page );
	await field( page, 'dic' ).fill( 'PL5260250995' );
	await field( page, 'dic' ).blur();
	await expect( page.getByText( 'is not registered for VAT in the EU (VIES)' ).first() ).toBeVisible( { timeout: 30000 } );
} );

test( 'a VAT ID the server rejects is reported under its field', async ( { page } ) => {
	await setCountry( page, 'DE' );
	// The address first, as the form has it: the VAT update answers with the cart.
	await asCompany( page );
	await fillAddress( page );
	await page.locator( '#shipping-postcode' ).fill( '10115' );
	await field( page, 'company' ).fill( 'Firma GmbH' );
	await field( page, 'dic' ).fill( 'DE123456789' );
	await field( page, 'dic' ).blur();
	await page.waitForResponse( ( r ) => r.url().includes( 'cart/extensions' ), { timeout: 15000 } ).catch( () => null );
	await page.locator( '.wc-block-components-checkout-place-order-button' ).click();

	const row = page.locator( '.wc-block-components-text-input' ).filter( { has: field( page, 'dic' ) } );
	await expect( row.locator( '.wc-block-components-validation-error' ) ).toContainText( 'Enter a valid VAT ID', { timeout: 30000 } );
	await expectOneError( fieldRow( page, 'dic' ) );
	await expect( page ).not.toHaveURL( /order-received/ );
} );

test( 'a store\'s own check is reported under its field', async ( { page, context, baseURL } ) => {
	await context.addCookies( [ { name: 'woolab_e2e_check', value: 'pl', url: baseURL } ] );
	try {
		await setCountry( page, 'PL' );
		await asCompany( page );
		await fillAddress( page );
		await page.locator( '#shipping-postcode' ).fill( '00-001' );
		await field( page, 'company' ).fill( 'Firma sp. z o.o.' );
		// A valid NIP, so only the store's check objects.
		await field( page, 'dic' ).fill( 'PL1132191233' );
		await field( page, 'dic' ).blur();
		await page.waitForResponse( ( r ) => r.url().includes( 'cart/extensions' ), { timeout: 15000 } ).catch( () => null );
		await page.locator( '.wc-block-components-checkout-place-order-button' ).click();

		const row = page.locator( '.wc-block-components-text-input' ).filter( { has: field( page, 'dic' ) } );
		await expect( row.locator( '.wc-block-components-validation-error' ) ).toContainText( 'E2E: this NIP is not on the whitelist.', { timeout: 30000 } );
		await expect( page ).not.toHaveURL( /order-received/ );
	} finally {
		await context.clearCookies( { name: 'woolab_e2e_check' } );
	}
} );

test( 'a Polish NIP without "PL" is a valid tax number, the order with VAT', async ( { page } ) => {
	await setCountry( page, 'PL' );
	await asCompany( page );
	await field( page, 'dic' ).fill( '5260250995' );
	await field( page, 'dic' ).blur();
	await expect( page.getByText( 'This tax number is valid' ).first() ).toBeVisible( { timeout: 30000 } );
	expect( await taxTotal( page ) ).not.toBe( '0' );
} );

// A store's own register (woolab_icdic_services, tests/e2e/mu-plugin.php) works like
// ARES: in Poland the IČO field is kybernaut/ic-eu.
async function withPolishRegister( page, context, baseURL ) {
	await context.addCookies( [ { name: 'woolab_e2e_service', value: 'pl', url: baseURL } ] );
	await page.goto( '/checkout/' );
	await setCountry( page, 'PL' );
	await asCompany( page );
}

async function expectPolishCompany( page ) {
	await expect( field( page, 'company' ) ).toHaveValue( PL_COMPANY.name );
	await expect( page.locator( '#shipping-address_1' ) ).toHaveValue( PL_COMPANY.address_1 );
	await expect( page.locator( '#shipping-postcode' ) ).toHaveValue( PL_COMPANY.postcode );
	await expect( page.locator( '#shipping-city' ) ).toHaveValue( PL_COMPANY.city );
}

test( 'a national register fills the company from its IČO', async ( { page, context, baseURL } ) => {
	await withPolishRegister( page, context, baseURL );
	await field( page, 'ic-eu' ).fill( PL_COMPANY.ico );
	await field( page, 'ic-eu' ).blur();
	await expectPolishCompany( page );
} );

test( 'a national register suggests companies by name', async ( { page, context, baseURL } ) => {
	await withPolishRegister( page, context, baseURL );
	await field( page, 'company' ).pressSequentially( 'Polska' );
	const suggestion = page.locator( '#woolab-ic-dic-suggest li' ).filter( { hasText: PL_COMPANY.name } );
	await expect( suggestion ).toBeVisible();
	await suggestion.click();
	await expect( field( page, 'ic-eu' ) ).toHaveValue( PL_COMPANY.ico );
	await expectPolishCompany( page );
} );
