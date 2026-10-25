/**
 * The REST lookups both checkouts call (src/Rest/Routes.php), and which countries
 * have a service for them (src/Assets.php services()).
 */

/**
 * The company features of a billing country: lookup, search, autofill, and the
 * status texts. An empty object where there is no service.
 *
 * @param {Object} data    The script data.
 * @param {string} country Billing country.
 * @return {Object} Its entry of data.services.
 */
export function service( data, country ) {
	return ( data.services && data.services[ country ] ) || {};
}

/**
 * Whether any country has a feature.
 *
 * @param {Object} data    The script data.
 * @param {string} feature 'lookup', 'search' or 'autofill'.
 * @return {boolean} Whether one has.
 */
export function anywhere( data, feature ) {
	return Object.keys( data.services || {} ).some( ( country ) => !! data.services[ country ][ feature ] );
}

/**
 * GET a route with query arguments; the base is pretty or ?rest_route=.
 *
 * @param {string} rest  REST base URL, ending in "woolab-ic-dic/v1/".
 * @param {string} route 'lookup' or 'search'.
 * @param {Object} args  Query arguments.
 * @return {Promise<Object>} The JSON answer. A refusal with a reason (a 429, a
 *                           register switched off) carries an `error` and is an
 *                           answer too; anything else rejects.
 */
function get( rest, route, args ) {
	const query = Object.keys( args ).map( ( key ) => key + '=' + encodeURIComponent( args[ key ] ) ).join( '&' );

	return fetch( rest + route + ( rest.indexOf( '?' ) === -1 ? '?' : '&' ) + query ).then( ( response ) =>
		response.json().then( ( data ) => {
			if ( ! response.ok && ! ( data && data.error ) ) {
				throw new Error( response.statusText );
			}
			return data;
		} )
	);
}

/**
 * The company with this IČO: 'error' false and 'spolecnost', 'dic', 'adresa',
 * 'psc', 'mesto' (Services\Service::lookup_company()).
 *
 * @param {string} rest    REST base URL.
 * @param {string} ico     IČO.
 * @param {string} country Billing country.
 * @return {Promise<Object>} The answer.
 */
export function lookup( rest, ico, country ) {
	return get( rest, 'lookup', { ico, country } );
}

/**
 * Companies by name: 'results' => [ { ico, name, address } ].
 *
 * @param {string} rest    REST base URL.
 * @param {string} name    Three letters or more.
 * @param {string} country Billing country.
 * @return {Promise<Object>} The answer.
 */
export function search( rest, name, country ) {
	return get( rest, 'search', { name, country } );
}
