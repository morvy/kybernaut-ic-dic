// The public lookup routes: their argument checks and the rate limit they share
// with the Store API (25 requests per 10 s per client by default).
const { test, expect } = require( '@playwright/test' );
const { COMPANY } = require( './helpers' );

const route = ( path ) => '/?rest_route=/woolab-ic-dic/v1/' + path;

test( 'lookup returns the company', async ( { request } ) => {
	const response = await request.get( route( `lookup&ico=${ COMPANY.ico }&country=CZ` ) );
	expect( response.status() ).toBe( 200 );
	expect( ( await response.json() ).spolecnost ).toMatch( COMPANY.name );
} );

test( 'bad arguments are rejected before any register is asked', async ( { request } ) => {
	expect( ( await request.get( route( 'lookup&ico=1&country=DE' ) ) ).status() ).toBe( 400 );
	expect( ( await request.get( route( 'search&name=ab&country=CZ' ) ) ).status() ).toBe( 400 );
} );

test( 'the lookups are rate limited', async ( { request } ) => {
	// At once: a slow host (Playground: about a second a request) would otherwise
	// spread them over several 10 s windows. Not a valid IČO, so ARES is not asked.
	const responses = await Promise.all( Array.from( { length: 40 }, () => request.get( route( 'lookup&ico=1&country=CZ' ) ) ) );
	const limited = responses.find( ( response ) => 429 === response.status() );
	expect( limited, 'a 429 among 40 requests' ).toBeTruthy();
	const retry = Number( limited.headers()[ 'retry-after' ] );
	expect( retry ).toBeGreaterThan( 0 );
	expect( limited.headers()[ 'ratelimit-limit' ] ).toBeTruthy();

	// Leave the limit as it was found for whatever runs next.
	await new Promise( ( resolve ) => setTimeout( resolve, ( retry + 1 ) * 1000 ) );
} );
