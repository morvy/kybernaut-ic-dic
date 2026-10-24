// Block checkout: which company fields each billing country gets, the "Buying as
// a company" toggle, the billing country behind "Use same address for billing",
// the ARES autofill and the live intra-EU VAT exemption.
const { test, expect } = require( '@playwright/test' );

const field = ( page, id ) => page.locator( `#order-kybernaut-${ id }` );

async function openCheckout( page ) {
	// Playground hands out its cookie on the first page load.
	await page.goto( '/' );
	const products = await ( await page.request.get( '/?rest_route=/wc/store/v1/products' ) ).json();
	await page.goto( `/?add-to-cart=${ products[ 0 ].id }` );
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

const taxTotal = ( page ) => page.evaluate( () => wp.data.select( wc.wcBlocksData.CART_STORE_KEY ).getCartTotals().total_tax );

test.beforeEach( async ( { page } ) => {
	await openCheckout( page );
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
	await expect( field( page, 'ic' ) ).toBeVisible();
} );

test( 'a Czech IČO fills the company from ARES', async ( { page } ) => {
	await setCountry( page, 'CZ' );
	await asCompany( page );
	await field( page, 'ic' ).fill( '27082440' );
	await field( page, 'ic' ).blur();
	await expect( field( page, 'company' ) ).toHaveValue( /Alza\.cz/ );
	await expect( field( page, 'dic-cz' ) ).toHaveValue( 'CZ27082440' );
} );

test( 'a valid foreign EU VAT ID makes the order VAT exempt, live', async ( { page } ) => {
	await setCountry( page, 'CZ' );
	await asCompany( page );
	await expect.poll( () => taxTotal( page ) ).not.toBe( '0' );

	await field( page, 'dic-cz' ).fill( 'CZ27082440' );
	await field( page, 'dic-cz' ).blur();
	await expect.poll( () => taxTotal( page ), { timeout: 30000 } ).toBe( '0' );

	// Back to the shop's own country: VAT again.
	await setCountry( page, 'SK' );
	await expect.poll( () => taxTotal( page ), { timeout: 30000 } ).not.toBe( '0' );
} );
