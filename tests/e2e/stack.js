// Writes tests/e2e/.blueprint.json for one of the two stacks the end-to-end tests
// run on, and prints it:
//
//   min    — the plugin's own minimums, read from its header: WooCommerce
//            "WC requires at least" (its newest patch release), WordPress
//            "Requires at least", PHP "Requires PHP";
//   latest — the newest WordPress and WooCommerce (WordPress.org APIs), on the
//            newest PHP Playground runs.
//
// Usage: node tests/e2e/stack.js min|latest
const fs = require( 'fs' );
const path = require( 'path' );

function header( name ) {
	const source = fs.readFileSync( path.join( __dirname, '../../woolab-ic-dic.php' ), 'utf8' );
	const match = source.match( new RegExp( '^\\s*' + name + ':\\s*(\\S+)', 'm' ) );
	if ( ! match ) {
		throw new Error( `No "${ name }" in the plugin header` );
	}
	return match[ 1 ];
}

async function api( url ) {
	const response = await fetch( url );
	if ( ! response.ok ) {
		throw new Error( `${ url }: HTTP ${ response.status }` );
	}
	return response.json();
}

const woocommerce = () => api( 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=woocommerce&request[fields][versions]=1&request[fields][sections]=0' );

// "10.0" → the newest stable 10.0.x the plugin directory has.
function newestPatch( info, minor ) {
	const patches = Object.keys( info.versions )
		.filter( ( v ) => v.startsWith( minor + '.' ) && /^\d+\.\d+\.\d+$/.test( v ) )
		.sort( ( a, b ) => a.localeCompare( b, undefined, { numeric: true } ) );
	if ( ! patches.length ) {
		throw new Error( `No WooCommerce ${ minor }.x release` );
	}
	return patches.pop();
}

async function stack( name ) {
	const info = await woocommerce();

	if ( 'min' === name ) {
		const wc = newestPatch( info, header( 'WC requires at least' ) );
		return { wp: header( 'Requires at least' ), php: header( 'Requires PHP' ), wc, zip: info.versions[ wc ] };
	}
	if ( 'latest' === name ) {
		const core = await api( 'https://api.wordpress.org/core/version-check/1.7/' );
		const { LatestSupportedPHPVersion } = await import( '@php-wasm/universal' );
		return { wp: core.offers[ 0 ].version, php: LatestSupportedPHPVersion, wc: info.version, zip: info.download_link };
	}
	throw new Error( 'Usage: node tests/e2e/stack.js min|latest' );
}

function blueprint( { wp, php, wc, zip } ) {
	const expect = `define( 'WOOLAB_E2E_WP', '${ wp }' ); define( 'WOOLAB_E2E_WC', '${ wc }' );`;
	return {
		$schema: 'https://playground.wordpress.net/blueprint-schema.json',
		// Wins over the CLI's --wp / --php, which a blueprint without it ignores.
		preferredVersions: { wp, php },
		features: { networking: true },
		steps: [
			// WP-Cron would otherwise update core, WooCommerce or translations in the
			// background mid-run ("Briefly unavailable for scheduled maintenance").
			{ step: 'defineWpConfigConsts', consts: { AUTOMATIC_UPDATER_DISABLED: true, WP_AUTO_UPDATE_CORE: false } },
			{ step: 'installPlugin', pluginData: { resource: 'url', url: zip } },
			{ step: 'activatePlugin', pluginPath: 'woolab-ic-dic/woolab-ic-dic.php' },
			{ step: 'runPHP', code: `<?php require '/wordpress/wp-load.php'; ${ expect } require WP_PLUGIN_DIR . '/woolab-ic-dic/tests/e2e/setup.php';` },
		],
	};
}

stack( process.argv[ 2 ] ).then( ( versions ) => {
	fs.writeFileSync( path.join( __dirname, '.blueprint.json' ), JSON.stringify( blueprint( versions ), null, '\t' ) + '\n' );
	console.log( `WordPress ${ versions.wp }, WooCommerce ${ versions.wc }, PHP ${ versions.php }` );
} ).catch( ( error ) => {
	console.error( error.message );
	process.exit( 1 );
} );
