/**
 * npm run build / npm run start: @wordpress/scripts' webpack config with this
 * plugin's entries, built into assets/ under the file names src/Assets.php
 * enqueues (each script with its .asset.php: dependencies and version).
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const RtlCssPlugin = require( '@wordpress/scripts/plugins/rtlcss-webpack-plugin' );

const CSS = [ 'lookup', 'style', 'admin' ];

/**
 * A stylesheet entry also emits an empty script and its .asset.php; only the CSS
 * is enqueued, so drop them.
 */
class DropStylesheetScripts {
	apply( compiler ) {
		compiler.hooks.thisCompilation.tap( 'DropStylesheetScripts', ( compilation ) => {
			compilation.hooks.processAssets.tap( { name: 'DropStylesheetScripts', stage: compiler.webpack.Compilation.PROCESS_ASSETS_STAGE_REPORT }, () => {
				CSS.forEach( ( name ) => {
					[ `css/${ name }.js`, `css/${ name }.asset.php`, `css/${ name }.js.map` ].forEach( ( file ) => compilation.deleteAsset( file ) );
				} );
			} );
		} );
	}
}

module.exports = {
	...defaultConfig,
	entry: {
		'js/public': './resources/js/classic/checkout.js',
		'js/blocks': './resources/js/blocks/checkout.js',
		'js/admin': './resources/js/admin/settings.js',
		'js/admin-edit': './resources/js/admin/order.js',
		...Object.fromEntries( CSS.map( ( name ) => [ `css/${ name }`, `./resources/css/${ name }.css` ] ) ),
	},
	// Without the style.css chunk for blocks: style.css is a plain stylesheet here.
	optimization: { ...defaultConfig.optimization, splitChunks: false },
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets' ),
	},
	plugins: [
		// No -rtl.css copies: nothing enqueues them (1.x had none either).
		...defaultConfig.plugins.filter( ( plugin ) => ! ( plugin instanceof RtlCssPlugin ) ),
		new DropStylesheetScripts(),
	],
};
