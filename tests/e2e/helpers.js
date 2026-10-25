// Shared by the specs: a cart with the test product, and the shopper's details.
const { expect } = require( '@playwright/test' );

async function fillCart( page ) {
	// Playground hands out its cookie on the first page load.
	await page.goto( '/' );
	const products = await ( await page.request.get( '/?rest_route=/wc/store/v1/products&slug=test-product' ) ).json();
	expect( products.length, 'the test product (tests/e2e/setup.php)' ).toBe( 1 );
	await page.goto( `/?add-to-cart=${ products[ 0 ].id }` );
}

// Alza.cz a.s.: a real Czech company in ARES; VIES is answered by mu-plugin.php.
const COMPANY = { ico: '27082440', dic: 'CZ27082440', name: /Alza\.cz/ };

const SHOPPER = {
	email: 'e2e@woolab-test.local',
	first_name: 'Jana',
	last_name: 'Testová',
	address_1: 'Jankovcova 1522/53',
	city: 'Praha 7',
	postcode: '170 00',
	phone: '+420 600 000 000',
};

// The order confirmation shows the company identifiers once: as their own block
// under the billing address (classic themes), or as lines of it (block themes).
async function expectIdentifiersOnce( page ) {
	const text = await page.locator( 'body' ).innerText();
	expect( text.match( new RegExp( 'Business ID: ' + COMPANY.ico, 'g' ) ) ).toHaveLength( 1 );
	expect( text.match( new RegExp( 'VAT ID: ' + COMPANY.dic, 'g' ) ) ).toHaveLength( 1 );
}

module.exports = { fillCart, expectIdentifiersOnce, COMPANY, SHOPPER };
