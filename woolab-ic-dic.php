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
	// A wordpress.org language pack takes precedence over the bundled file and lags
	// behind new strings; load the bundled one too, it fills in what the pack lacks.
	load_textdomain( 'woolab-ic-dic', WOOLAB_IC_DIC_ABSPATH . 'languages/woolab-ic-dic-' . determine_locale() . '.mo' );

	// Load additional sources
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/options.php');
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/labels.php');
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/ares.php');
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/vies.php');
	include_once( WOOLAB_IC_DIC_ABSPATH . 'includes/rest.php');
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
	add_filter( 'woocommerce_default_address_fields', 'woolab_icdic_default_address_fields' );
	add_filter( 'woocommerce_checkout_fields', 'woolab_icdic_checkout_fields' );
	add_action( 'woocommerce_checkout_process', 'woolab_icdic_checkout_field_process', 10, 0 );
	add_filter( 'woocommerce_checkout_posted_data', 'woolab_icdic_checkout_posted_data' );
	add_filter( 'woocommerce_form_field', 'woolab_icdic_form_field_hide_optional', 10, 2 );
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
	add_action( 'wp', 'woolab_icdic_set_vat_exempt_for_customer' );
	add_action( 'woocommerce_checkout_update_order_review', 'woolab_icdic_validate_vat_exempt_for_company', 10, 1 );
	add_action( 'woocommerce_checkout_update_order_meta', 'woolab_icdic_save_order_metadata' );
	add_action( 'manage_shop_order_posts_custom_column', 'woolab_icdic_show_check_failed_notice_on_orders_table', 20, 2 ); // HPOS not enabled.
	add_action( 'woocommerce_shop_order_list_table_custom_column', 'woolab_icdic_show_check_failed_notice_on_orders_table_hpos', 10, 2 ); // HPOS alternative of "manage_shop_order_posts_custom_column" above.
	add_action( 'woocommerce_admin_order_data_after_billing_address', 'woolab_icdic_show_check_failed_notice_on_order_edit' );
	add_action( 'woocommerce_email_order_details', 'woolab_icdic_show_check_failed_notice_on_admin_email', 5, 3 );

	// Cart & Checkout Blocks. The classic hooks above never fire there; see
	// includes/blocks.php for what each of these stands in for.
	add_action( 'woocommerce_init', 'woolab_icdic_blocks_register_fields' );
	add_action( 'woocommerce_blocks_checkout_block_registration', 'woolab_icdic_blocks_register_integration' );
	add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', 'woolab_icdic_enqueue_lookup_style' );
	add_filter( 'rest_pre_dispatch', 'woolab_icdic_blocks_remember_request', 10, 3 );
	add_action( 'woocommerce_blocks_validate_location_order_fields', 'woolab_icdic_blocks_validate', 10, 2 );
	add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'woolab_icdic_blocks_update_order', 10, 2 );
	add_action( 'woocommerce_store_api_checkout_update_customer_from_request', 'woolab_icdic_blocks_customer_vat_exempt', 10, 2 );
	add_action( 'woocommerce_init', 'woolab_icdic_blocks_register_update_callback' );
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


}

add_action( 'plugins_loaded', 'woolab_icdic_init' );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'woolab_icdic_sk_import' );
} );

/**
 * The lookup spinner, status icons and suggestion list (lookup.css), with the
 * colours from Settings → IČO DIČ → Appearance. Shared by both checkouts.
 */
function woolab_icdic_enqueue_lookup_style() {
	if ( woolab_icdic_ares_check() || woolab_icdic_sk_check() ) {
		wp_enqueue_style( 'woolab-icdic-lookup-css', WOOLAB_IC_DIC_URL . 'assets/css/lookup.css', array(), WOOLAB_IC_DIC_VERSION );
		wp_add_inline_style( 'woolab-icdic-lookup-css', woolab_icdic_lookup_colors_css() );
	}
}

/**
 * Whether the company name search runs: wherever a register also autofills
 * (woolab_icdic_rest_search()).
 */
function woolab_icdic_suggest_enabled() {
	return woolab_icdic_ares_check() && woolab_icdic_ares_fill() || woolab_icdic_sk_check() && woolab_icdic_sk_fill();
}

/**
 * Register suggest.js, shared by both checkout scripts, when the search runs.
 *
 * @return string[] The handle as a dependency, or none.
 */
function woolab_icdic_register_suggest_script() {
	if ( ! woolab_icdic_suggest_enabled() ) {
		return array();
	}

	$suffix = SCRIPT_DEBUG ? '' : '.min';
	wp_register_script( 'woolab-icdic-suggest-js', WOOLAB_IC_DIC_URL . 'assets/js/suggest' . $suffix . '.js', array(), WOOLAB_IC_DIC_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );

	return array( 'woolab-icdic-suggest-js' );
}

/**
 * Settings the checkout scripts read: public.js as window.woolab, blocks.js as
 * wcSettings 'woolab-ic-dic_data' (includes/class-woolab-icdic-blocks-integration.php).
 *
 * @param bool $blocks For the block checkout.
 * @return array
 */
function woolab_icdic_script_data( $blocks ) {
	$data = array(
		// The lookups (includes/rest.php).
		'rest'               => woolab_icdic_rest_url(),
		'l18n_not_valid'     => __( 'Business ID is invalid.', 'woolab-ic-dic' ),
		'l18n_error'         => __( 'Unexpected error occurred. Try it again.', 'woolab-ic-dic' ),
		'l18n_ok'            => __( 'Information loaded succesfully from ARES.', 'woolab-ic-dic' ),
		'l18n_validating'    => __( 'Validating data in ARES.', 'woolab-ic-dic' ),
		'l18n_ok_sk'         => __( 'Information loaded succesfully from Finančná správa.', 'woolab-ic-dic' ),
		'l18n_validating_sk' => __( 'Validating data in Finančná správa.', 'woolab-ic-dic' ),
		'l18n_clear'         => __( 'Clear company details', 'woolab-ic-dic' ),
		'ares_check'         => woolab_icdic_ares_check(),
		'ares_fill'          => woolab_icdic_ares_fill(),
		'sk_check'           => woolab_icdic_sk_check(),
		'sk_fill'            => woolab_icdic_sk_fill(),
		'ignore_check_fail'  => woolab_icdic_ignore_check_fail(),
	);

	if ( $blocks ) {
		// Ask the server to recalculate the exemption as the VAT fields change
		// (woolab_icdic_blocks_update_vat_exempt()).
		$data['vat_exempt'] = woolab_icdic_vat_exempt_enabled();
		$data['fields'] = array(
			'ic'      => 'kybernaut/ic',
			// Country => field: the DIČ field is one per label (includes/blocks.php).
			'dic'     => woolab_icdic_blocks_dic_fields(),
			'dic_dph' => 'kybernaut/dic-dph',
			// Empty when the company name stays a normal address field, so ARES
			// autofill knows where to put it.
			'company' => woolab_icdic_blocks_owns_company() ? 'kybernaut/company' : '',
		);
	} else {
		// Field labels by billing country (includes/labels.php); the block fields
		// carry theirs.
		$data['labels']       = woolab_icdic_labels_by_country();
		$data['placeholders'] = woolab_icdic_labels_by_country( false );
		// Countries the Business ID is shown for, null for all.
		$data['ic_countries'] = woolab_icdic_ic_countries();
	}

	return $data;
}

/**
 * The classic checkout's script and styles. Only on the checkout form itself: the
 * block checkout loads blocks.js through its integration, and the order-received
 * and pay pages need neither.
 */
function woolab_icdic_enqueue_scripts() {
	if ( ! is_checkout() || is_wc_endpoint_url() || woolab_icdic_blocks_checkout() ) {
		return;
	}

	$suffix = SCRIPT_DEBUG ? '' : '.min';

	woolab_icdic_enqueue_lookup_style();

	wp_enqueue_script( 'woolab-icdic-public-js', WOOLAB_IC_DIC_URL . 'assets/js/public' . $suffix . '.js', array_merge( array( 'jquery' ), woolab_icdic_register_suggest_script() ), WOOLAB_IC_DIC_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_localize_script( 'woolab-icdic-public-js', 'woolab', woolab_icdic_script_data( false ) );

	// In the head: it hides the toggled fields before the page paints.
	if ( woolab_icdic_toggle_enabled() ) {
		wp_enqueue_style( 'woolab-icdic-public-css', WOOLAB_IC_DIC_URL . 'assets/css/style.css', array(), WOOLAB_IC_DIC_VERSION );
	}
}

/**
 * The colours set in Settings → IČO DIČ → Appearance, as the custom properties
 * lookup.css reads. Empty when none is set.
 */
function woolab_icdic_lookup_colors_css() {
	$properties = array();

	foreach ( array( 'color_ok' => 'ok', 'color_clear' => 'clear', 'color_list_bg' => 'list-bg', 'color_list_text' => 'list-text', 'color_list_border' => 'list-border', 'color_list_highlight' => 'list-highlight' ) as $key => $property ) {
		$color = sanitize_hex_color( woolab_icdic_setting( $key ) );
		if ( $color ) {
			$properties[] = '--woolab-ic-dic-color-' . $property . ':' . $color;
		}
	}

	return $properties ? ':root{' . implode( ';', $properties ) . '}' : '';
}

function woolab_icdic_ares_check() {
	$option = 'yes' === woolab_icdic_setting( 'ares_check' );
	return apply_filters( 'woolab_icdic_ares_check', $option );
}

function woolab_icdic_ares_fill() {
	$option = 'yes' === woolab_icdic_setting( 'ares_fill' );
	return apply_filters( 'woolab_icdic_ares_fill', $option );
}

function woolab_icdic_sk_check() {
	$option = 'yes' === woolab_icdic_setting( 'sk_check' );
	return apply_filters( 'woolab_icdic_sk_check', $option );
}

function woolab_icdic_sk_fill() {
	$option = 'yes' === woolab_icdic_setting( 'sk_fill' );
	return apply_filters( 'woolab_icdic_sk_fill', $option );
}

function woolab_icdic_vies_check() {
	$option = 'yes' === woolab_icdic_setting( 'vies_check' );
	return apply_filters( 'woolab_icdic_vies_check', $option );

}

function woolab_icdic_ignore_check_fail() {
	$option = 'yes' === woolab_icdic_setting( 'ignore_check_fail' );
	return apply_filters( 'woolab_icdic_ignore_check_fail', $option );
}

// NOTE: the four accessors below keep their original public filter contracts,
// which differ from the helpers above: the vat_exempt/dic_dicdph filters receive
// an already-computed bool (and vat_exempt ANDs in wc_tax_enabled / dic_dicdph is
// an inverted "disable" option), while the toggle/country filters receive the raw
// 'yes'/'no' string and are normalized with `!== 'no'` afterwards — do not
// "consolidate" them.

/**
 * Whether automatic VAT exemption for valid intra-EU B2B is enabled.
 * Filter receives (and returns) a bool, matching the original call sites.
 */
function woolab_icdic_vat_exempt_enabled() {
	return apply_filters( 'woolab_icdic_vat_exempt_enabled', ( woolab_icdic_setting( 'vat_exempt' ) !== 'no' && wc_tax_enabled() ) );
}

/**
 * Whether the SK DIČ ↔ IČ DPH match check is enabled. Stored as an inverted
 * "disable" option. Filter receives (and returns) a bool.
 */
function woolab_icdic_dic_dicdph_match_enabled() {
	return apply_filters( 'woolab_icdic_enable_dic_dicdph_match_check', woolab_icdic_setting( 'disable_dic_dph_match' ) !== 'yes' );
}

/**
 * Whether the "buying as a company" fields toggle is enabled.
 * Filter receives (and returns) the raw 'yes'/'no' string; normalization to bool
 * happens here.
 */
function woolab_icdic_toggle_enabled() {
	return apply_filters( 'woolab_icdic_toggle', woolab_icdic_setting( 'toggle' ) ) !== 'no';
}

/**
 * Whether the country field is moved above the toggle.
 * Filter receives (and returns) the raw 'yes'/'no' string; normalization to bool
 * happens here.
 */
function woolab_icdic_country_ontop_enabled() {
	return apply_filters( 'woolab_icdic_country_ontop', woolab_icdic_setting( 'country_ontop' ) ) !== 'no';
}

/**
 * Whether a 1.x setting option is 'yes'. Kept for code calling it from outside;
 * the settings now live in one array (includes/options.php).
 *
 * @deprecated 2.0.0 Use woolab_icdic_setting().
 */
function woolab_icdic_get_option( $name, $default = 'yes' ) {

	foreach ( woolab_icdic_settings_schema() as $key => $definition ) {
		if ( $definition[0] === $name ) {
			return 'yes' === woolab_icdic_setting( $key );
		}
	}

	return 'yes' === get_option( $name, $default );

}

function woolab_icdic_admin_scripts( $hook ) {
	$suffix = SCRIPT_DEBUG ? '' : '.min';

	// Order screens only: 'shop_order' for the legacy post type, the wc-orders page
	// under HPOS (wc_get_page_screen_id()). Without the HPOS one the country-based
	// show/hide JS never loads, so the SK-only IČ DPH field stayed visible on CZ
	// orders under HPOS.
	$screen = get_current_screen();
	if ( $screen && in_array( $screen->id, array( 'shop_order', wc_get_page_screen_id( 'shop-order' ) ), true ) ) {
		wp_enqueue_style( 'woolab-ic-dic-admin', WOOLAB_IC_DIC_URL . 'assets/css/admin.css', array(), WOOLAB_IC_DIC_VERSION );
		wp_enqueue_script( 'woolab-ic-dic-admin-edit', WOOLAB_IC_DIC_URL . 'assets/js/admin-edit'.$suffix.'.js', array('jquery'), WOOLAB_IC_DIC_VERSION );
	}
	if ( 'woocommerce_page_wc-settings' === $hook && isset( $_GET['tab'] ) && 'woolab_icdic' === $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the screen.
		wp_enqueue_script( 'woolab-ic-dic-admin', WOOLAB_IC_DIC_URL . 'assets/js/admin'.$suffix.'.js', array('jquery'), WOOLAB_IC_DIC_VERSION );
	}
}

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