<?php
/*
 Plugin Name:			Kybernaut IC DIC
 Plugin URI:			https://kybernaut.cz/pluginy/kybernaut-ic-dic
 Description:			Adds Czech Company & VAT numbers (IČO & DIČ) to WooCommerce billing fields and verifies if data are correct.
 Version:				2.0.0
 Author:				Karolína Vyskočilová
 Author URI:			https://kybernaut.cz
 Text Domain:			woolab-ic-dic
 License:				GPLv3
 License URI:			http://www.gnu.org/licenses/gpl-3.0.html
 Domain Path:			/languages
 Donate link:			https://paypal.me/KarolinaVyskocilova/
 Requires at least:		6.7
 Requires PHP:			8.1
 Requires Plugins: 		woocommerce
 WC requires at least:	10.0
 WC tested up to:		11.0
 Copyright:				© 2016-2026 Karolína Vyskočilová.
 License:				GNU General Public License v3.0
 License URI:			http://www.gnu.org/licenses/gpl-3.0.html
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Loads Composer dependencies.
 */
if ( file_exists( __DIR__ . '/deps/scoper-autoload.php' ) ) {
	require __DIR__ . '/deps/scoper-autoload.php';
}
if ( file_exists( __DIR__ . '/deps/autoload.php' ) ) {
	require __DIR__ . '/deps/autoload.php';
}
// The plugin's own classes (src/, PSR-4) and src/functions.php.
require __DIR__ . '/vendor/autoload.php';

define( 'WOOLAB_IC_DIC_FILE', __FILE__ );
define( 'WOOLAB_IC_DIC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WOOLAB_IC_DIC_ABSPATH', dirname( __FILE__ ) . '/' );
define( 'WOOLAB_IC_DIC_URL', plugin_dir_url( __FILE__ ) );
define( 'WOOLAB_IC_DIC_VERSION', '2.0.0' );

\KybernautIcDic\Plugin::boot();
