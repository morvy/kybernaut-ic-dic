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
 Requires at least:		6.9
 Requires PHP:			8.1
 Requires Plugins: 		woocommerce
 WC requires at least:	11.0
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
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require __DIR__ . '/vendor/autoload.php';
}

define( 'WOOLAB_IC_DIC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WOOLAB_IC_DIC_ABSPATH', dirname( __FILE__ ) . '/' );
define( 'WOOLAB_IC_DIC_URL', plugin_dir_url( __FILE__ ) );
define( 'WOOLAB_IC_DIC_VERSION', '2.0.0' );

/**
 * Boot the plugin.
 *
 * WooCommerce is guaranteed here: the `Requires Plugins` header lets WordPress
 * refuse activation without it, and this runs on plugins_loaded, after it is
 * loaded.
 */
function woolab_icdic_init() {

	// Localize
	load_plugin_textdomain( 'woolab-ic-dic', FALSE, basename( dirname( __FILE__ ) ) . '/languages/' );

	// Load additional sources
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/ares.php');
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/financna-sprava.php');
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/helpers.php');
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/validation.php');
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/filters-actions.php');
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/settings.php');
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/logger.php');
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/blocks.php');
	// Compatibility
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/compatibility/superfaktura.php');
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/compatibility/pdf-invoices-and-packing-slips-for-woocommerce.php');
	// include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/compatibility/fluidcheckout.php'); don't apply globally.

	add_filter( 'woocommerce_billing_fields' , 'woolab_icdic_billing_fields', 10, 2 );
	add_filter( 'woocommerce_checkout_fields', 'woolab_icdic_checkout_fields', 10, 2);
	add_action( 'woocommerce_checkout_process', 'woolab_icdic_checkout_field_process', 10, 0);
	add_filter( 'woocommerce_my_account_my_address_formatted_address', 'woolab_icdic_my_address_formatted_address', 10, 3 );
	add_filter( 'woocommerce_localisation_address_formats', 'woolab_icdic_localisation_address_formats' );
	add_filter( 'woocommerce_formatted_address_replacements', 'woolab_icdic_formatted_address_replacements', 10, 2 );
	add_filter( 'woocommerce_order_formatted_billing_address', 'woolab_icdic_order_formatted_billing_address', 10, 2 );
	add_action( 'woocommerce_after_order_details', 'woolab_icdic_order_details_enter', 1 );
	add_action( 'woocommerce_order_details_after_customer_details', 'woolab_icdic_order_details_leave', 99 );
	// Core skips the customer details for a viewer who is not the order's customer, and then
	// the hook above never fires: close the section when the order view itself is done.
	add_action( 'woocommerce_thankyou', 'woolab_icdic_order_details_leave', 99 );
	add_action( 'woocommerce_view_order', 'woolab_icdic_order_details_leave', 99 );
	add_action( 'woocommerce_order_details_after_customer_address', 'woolab_icdic_order_details_ids', 10, 2 );
	add_filter( 'woocommerce_customer_meta_fields', 'woolab_icdic_customer_meta_fields' );
	add_filter( 'woocommerce_admin_billing_fields', 'woolab_icdic_admin_billing_fields' );
	add_action( 'woocommerce_process_shop_order_meta', 'woolab_icdic_process_shop_order', 10, 2 );
	add_filter( 'default_checkout_billing_iscomp', 'woolab_icdic_toggle_iscomp_field', 10, 2 );
	add_action( 'init', 'woolab_icdic_set_vat_exempt_for_customer', 10, 1 );
	add_action( 'woocommerce_checkout_update_order_review', 'woolab_icdic_validate_vat_exempt_for_company', 10, 1 );
	add_action( 'woocommerce_checkout_update_order_meta', 'woolab_icdic_save_order_metadata' );
	add_action( 'manage_shop_order_posts_custom_column', 'woolab_icdic_show_check_failed_notice_on_orders_table', 20, 2 ); // HPOS not enabled.
	add_action( 'woocommerce_shop_order_list_table_custom_column', 'woolab_icdic_show_check_failed_notice_on_orders_table_hpos', 10, 2 ); // HPOS alternative of "manage_shop_order_posts_custom_column" above.
	add_action( 'woocommerce_admin_order_data_after_billing_address', 'woolab_icdic_show_check_failed_notice_on_order_edit' );
	add_action( 'woocommerce_email_order_details', 'woolab_icdic_show_check_failed_notice_on_admin_email', 5, 3 );

	// Cart & Checkout Blocks. The classic hooks above never fire there; see
	// includes/blocks.php for what each of these stands in for.
	add_action( 'woocommerce_init', 'woolab_icdic_blocks_register_fields' );
	add_filter( 'rest_pre_dispatch', 'woolab_icdic_blocks_remember_request', 10, 3 );
	add_action( 'woocommerce_blocks_validate_location_order_fields', 'woolab_icdic_blocks_validate', 10, 2 );
	add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'woolab_icdic_blocks_update_order', 10, 2 );
	add_filter( 'woocommerce_localisation_address_formats', 'woolab_icdic_blocks_strip_address_tokens', 20 );
	add_filter( 'option_woocommerce_checkout_company_field', 'woolab_icdic_blocks_hide_core_company' );
	add_filter( 'default_option_woocommerce_checkout_company_field', 'woolab_icdic_blocks_hide_core_company' );

	add_filter( 'woocommerce_ajax_get_customer_details', 'woolab_icdic_ajax_get_customer_details', 10, 3 );

	add_filter( "plugin_row_meta", 'woolab_icdic_plugin_row_meta', 10, 2 );

	if ( is_admin() ) {
		add_action( 'admin_enqueue_scripts', 'woolab_icdic_admin_scripts' );
		add_filter( 'plugin_action_links_' . WOOLAB_IC_DIC_PLUGIN_BASENAME, 'woolab_icdic_plugin_action_links' );
	} else {
		add_action( 'wp_enqueue_scripts', 'woolab_icdic_enqueue_scripts' );
	}

	add_action('wp_ajax_nopriv_ajaxAres', 'woolab_icdic_ares_ajax');
	add_action('wp_ajax_ajaxAres', 'woolab_icdic_ares_ajax');

}

add_action( 'plugins_loaded', 'woolab_icdic_init' );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'woolab_icdic_sk_import' );
} );

function woolab_icdic_enqueue_scripts() {
	$suffix = SCRIPT_DEBUG ? '' : '.min';
	if( is_checkout() ){

		// The block checkout has none of the #billing_* markup public.js drives, so
		// it gets its own script against the Blocks data stores. Same handle, so the
		// localized settings below serve both.
		$blocks = woolab_icdic_blocks_checkout();

		if ( $blocks ) {
			wp_enqueue_script( 'woolab-icdic-public-js', WOOLAB_IC_DIC_URL . 'assets/js/blocks'.$suffix.'.js', array( 'wp-data', 'wc-blocks-data-store' ), WOOLAB_IC_DIC_VERSION, true );
		} else {
			wp_enqueue_script( 'woolab-icdic-public-js', WOOLAB_IC_DIC_URL . 'assets/js/public'.$suffix.'.js', array( 'jquery' ), WOOLAB_IC_DIC_VERSION );
		}

		wp_localize_script( 'woolab-icdic-public-js', 'woolab', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'woolab_icdic_ares' ),
			'l18n_not_valid' => __('Business ID is invalid.', 'woolab-ic-dic'),
			'l18n_error' => __('Unexpected error occurred. Try it again.', 'woolab-ic-dic'),
			'l18n_ok' => __('Information loaded succesfully from ARES.', 'woolab-ic-dic'),
			'l18n_validating' => __('Validating data in ARES.', 'woolab-ic-dic'),
			'l18n_ok_sk' => __('Information loaded succesfully from Finančná správa.', 'woolab-ic-dic'),
			'l18n_validating_sk' => __('Validating data in Finančná správa.', 'woolab-ic-dic'),
			'ares_check' => woolab_icdic_ares_check(),
			'ares_fill' => woolab_icdic_ares_fill(),
			'sk_check' => woolab_icdic_sk_check(),
			'sk_fill' => woolab_icdic_sk_fill(),
			'ignore_check_fail' => woolab_icdic_ignore_check_fail(),
			'fields' => array(
				'ic'      => 'kybernaut/ic',
				'dic'     => 'kybernaut/dic',
				'dic_dph' => 'kybernaut/dic-dph',
				// Empty when the company name stays a normal address field, so ARES
				// autofill knows where to put it.
				'company' => woolab_icdic_blocks_owns_company() ? 'kybernaut/company' : '',
			),
		));
		if ( ! $blocks && woolab_icdic_toggle_enabled() ) {
			wp_enqueue_style( 'woolab-icdic-public-css', WOOLAB_IC_DIC_URL . 'assets/css/style.css', null, WOOLAB_IC_DIC_VERSION );
		}
	}
}

function woolab_icdic_ares_check() {
	$option = woolab_icdic_get_option( 'woolab_icdic_ares_check', 'yes' );
	return apply_filters( 'woolab_icdic_ares_check', $option );
}

function woolab_icdic_ares_fill() {
	$option = woolab_icdic_get_option( 'woolab_icdic_ares_fill', 'no' );
	return apply_filters( 'woolab_icdic_ares_fill', $option );
}

function woolab_icdic_sk_check() {
	$option = woolab_icdic_get_option( 'woolab_icdic_sk_check', 'no' );
	return apply_filters( 'woolab_icdic_sk_check', $option );
}

function woolab_icdic_sk_fill() {
	$option = woolab_icdic_get_option( 'woolab_icdic_sk_fill', 'no' );
	return apply_filters( 'woolab_icdic_sk_fill', $option );
}

function woolab_icdic_vies_check() {

	if ( ! class_exists('SoapClient') ) {
		return false;
	}

	$option = woolab_icdic_get_option( 'woolab_icdic_vies_check', 'yes' );
	return apply_filters( 'woolab_icdic_vies_check', $option );

}

function woolab_icdic_ignore_check_fail() {
	$option = woolab_icdic_get_option( 'woolab_icdic_ignore_check_fail', 'no' );
	return apply_filters( 'woolab_icdic_ignore_check_fail', $option );
}

// NOTE: the four accessors below deliberately do NOT go through
// woolab_icdic_get_option(). Each preserves its original public filter contract,
// which differs from the helpers above: the vat_exempt/dic_dicdph filters receive
// an already-computed bool (and vat_exempt ANDs in wc_tax_enabled / dic_dicdph is
// an inverted "disable" option), while the toggle/country filters receive the raw
// 'yes'/'no' string and are normalized with `!== 'no'` afterwards. Routing any of
// these through woolab_icdic_get_option() would change what the filter sees or the
// truthiness — do not "consolidate" them into it.

/**
 * Whether automatic VAT exemption for valid intra-EU B2B is enabled.
 * Filter receives (and returns) a bool, matching the original call sites.
 */
function woolab_icdic_vat_exempt_enabled() {
	return apply_filters( 'woolab_icdic_vat_exempt_enabled', ( get_option( 'woolab_icdic_vat_exempt_switch', 'no' ) !== 'no' && wc_tax_enabled() ) );
}

/**
 * Whether the SK DIČ ↔ IČ DPH match check is enabled. Stored as an inverted
 * "disable" option. Filter receives (and returns) a bool.
 */
function woolab_icdic_dic_dicdph_match_enabled() {
	return apply_filters( 'woolab_icdic_enable_dic_dicdph_match_check', get_option( 'woolab_icdic_disable_dic_dicdph_match', 'no' ) !== 'yes' );
}

/**
 * Whether the "buying as a company" fields toggle is enabled.
 * Filter receives (and returns) the raw 'yes'/'no' string; normalization to bool
 * happens here.
 */
function woolab_icdic_toggle_enabled() {
	return apply_filters( 'woolab_icdic_toggle', get_option( 'woolab_icdic_toggle_switch', 'no' ) ) !== 'no';
}

/**
 * Whether the country field is moved above the toggle.
 * Filter receives (and returns) the raw 'yes'/'no' string; normalization to bool
 * happens here.
 */
function woolab_icdic_country_ontop_enabled() {
	return apply_filters( 'woolab_icdic_country_ontop', get_option( 'woolab_icdic_country_switch', 'no' ) ) !== 'no';
}

function woolab_icdic_get_option( $name, $default = 'yes' ) {

	$option = get_option( $name, $default );
	if ( $option == 'yes' ) {
		return true;
	} else {
		return false;
	}

}

function woolab_icdic_admin_scripts( $hook ) {
	$suffix = SCRIPT_DEBUG ? '' : '.min';

	// Order edit screen: legacy CPT uses post(-new).php, HPOS uses the
	// wc-orders admin page (hook 'woocommerce_page_wc-orders'). Without the
	// HPOS hook the country-based show/hide JS never loads, so the SK-only
	// "VAT reg. no." field stayed visible on CZ orders under HPOS.
	if ( 'post.php' === $hook || 'post-new.php' === $hook || 'woocommerce_page_wc-orders' === $hook ) {
		wp_enqueue_style( 'woolab-ic-dic-admin', WOOLAB_IC_DIC_URL . 'assets/css/admin.css', array(), WOOLAB_IC_DIC_VERSION );
		wp_enqueue_script( 'woolab-ic-dic-admin-edit', WOOLAB_IC_DIC_URL . 'assets/js/admin-edit'.$suffix.'.js', array('jquery'), WOOLAB_IC_DIC_VERSION );
	}
	if ( 'woocommerce_page_wc-settings' === $hook ) {
		wp_enqueue_script( 'woolab-ic-dic-admin', WOOLAB_IC_DIC_URL . 'assets/js/admin'.$suffix.'.js', array('jquery'), WOOLAB_IC_DIC_VERSION );
		wp_localize_script( 'woolab-ic-dic-admin', 'woolab', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'soap' => class_exists('SoapClient'),
		));
	}
}

function woolab_icdic_ares_ajax(){
	check_ajax_referer( 'woolab_icdic_ares', 'nonce' );

	$ico = isset( $_REQUEST['ico'] ) ? wc_clean( wp_unslash( $_REQUEST['ico'] ) ) : '';
	$country = isset( $_REQUEST['country'] ) ? wc_clean( wp_unslash( $_REQUEST['country'] ) ) : 'CZ';

	if ( 'SK' === $country ) {
		wp_send_json( woolab_icdic_sk_check() ? woolab_icdic_sk_lookup( $ico ) : array( 'error' => __( 'Unexpected error occurred. Try it again.', 'woolab-ic-dic' ) ) );
	}

	wp_send_json( woolab_icdic_ares( $ico ) );
};

/**
 * Declare WooCommerce HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		// Supported since the Store API integration in includes/blocks.php.
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );