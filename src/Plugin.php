<?php
/**
 * Boots the plugin on plugins_loaded.
 *
 * WooCommerce is guaranteed by then: the `Requires Plugins` header lets WordPress
 * refuse activation without it, and plugins_loaded runs after it is loaded.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * Loads the translations, then lets every part hook itself.
 */
final class Plugin {

	/**
	 * The parts that hook themselves (each has a static register()).
	 *
	 * @var array<int,class-string>
	 */
	private const PARTS = array(
		Assets::class,
	);

	/**
	 * Files not moved to classes yet.
	 *
	 * @var array<int,string>
	 */
	private const LEGACY = array(
		'labels.php',
		'ares.php',
		'vies.php',
		'rest.php',
		'financna-sprava.php',
		'filters-actions.php',
		'settings.php',
		'blocks.php',
		'compatibility/superfaktura.php',
		'compatibility/pdf-invoices-and-packing-slips-for-woocommerce.php',
	);

	/**
	 * Hook the plugin.
	 */
	public static function boot(): void {
		add_action( 'plugins_loaded', array( self::class, 'init' ) );
		add_action( 'before_woocommerce_init', array( self::class, 'declare_compatibility' ) );
		register_deactivation_hook( WOOLAB_IC_DIC_FILE, array( self::class, 'deactivate' ) );
	}

	/**
	 * Translations, then every part.
	 */
	public static function init(): void {
		load_plugin_textdomain( 'woolab-ic-dic', false, dirname( WOOLAB_IC_DIC_PLUGIN_BASENAME ) . '/languages/' );
		// A wordpress.org language pack takes precedence over the bundled file and lags
		// behind new strings; load the bundled one too, it fills in what the pack lacks.
		load_textdomain( 'woolab-ic-dic', WOOLAB_IC_DIC_ABSPATH . 'languages/woolab-ic-dic-' . determine_locale() . '.mo' );

		foreach ( self::LEGACY as $file ) {
			include_once WOOLAB_IC_DIC_ABSPATH . 'includes/' . $file;
		}

		foreach ( self::PARTS as $part ) {
			$part::register();
		}

		self::register_legacy_hooks();
	}

	/**
	 * HPOS and the Cart and Checkout blocks.
	 */
	public static function declare_compatibility(): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', WOOLAB_IC_DIC_FILE, true );
			FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WOOLAB_IC_DIC_FILE, true );
		}
	}

	/**
	 * Stop the daily Finančná správa import.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'woolab_icdic_sk_import' );
	}

	/**
	 * The hooks of the code still in includes/, moved into the classes' register()
	 * as each file becomes classes.
	 */
	private static function register_legacy_hooks(): void {
		add_filter( 'woocommerce_billing_fields', 'woolab_icdic_billing_fields', 10, 2 );
		add_filter( 'woocommerce_default_address_fields', 'woolab_icdic_default_address_fields' );
		add_filter( 'woocommerce_checkout_fields', 'woolab_icdic_checkout_fields' );
		add_action( 'woocommerce_checkout_process', 'woolab_icdic_checkout_field_process', 10, 0 );
		add_filter( 'woocommerce_checkout_posted_data', 'woolab_icdic_checkout_posted_data' );
		add_filter( 'woocommerce_form_field', 'woolab_icdic_form_field_label', 10, 3 );
		add_filter( 'woocommerce_my_account_my_address_formatted_address', 'woolab_icdic_my_address_formatted_address', 10, 3 );
		add_filter( 'woocommerce_localisation_address_formats', 'woolab_icdic_localisation_address_formats' );
		add_filter( 'woocommerce_formatted_address_replacements', 'woolab_icdic_formatted_address_replacements', 10, 2 );
		add_filter( 'woocommerce_order_formatted_billing_address', 'woolab_icdic_order_formatted_billing_address', 10, 2 );
		add_action( 'woocommerce_before_template_part', 'woolab_icdic_order_details_enter' );
		add_action( 'woocommerce_after_template_part', 'woolab_icdic_order_details_leave' );
		add_action( 'woocommerce_order_details_after_customer_address', 'woolab_icdic_order_details_ids', 10, 2 );
		add_filter( 'woocommerce_customer_meta_fields', 'woolab_icdic_customer_meta_fields' );
		add_filter( 'woocommerce_admin_billing_fields', 'woolab_icdic_admin_billing_fields' );
		add_action( 'woocommerce_process_shop_order_meta', 'woolab_icdic_process_shop_order', 10, 2 );
		add_filter( 'default_checkout_billing_iscomp', 'woolab_icdic_toggle_iscomp_field', 10, 2 );
		add_action( 'woocommerce_checkout_update_order_review', 'woolab_icdic_remember_checkout_fields' );
		add_filter( 'woocommerce_checkout_get_value', 'woolab_icdic_remembered_checkout_value', 10, 2 );
		add_action( 'wp', 'woolab_icdic_set_vat_exempt_for_customer' );
		add_action( 'woocommerce_checkout_update_order_review', 'woolab_icdic_validate_vat_exempt_for_company', 10, 1 );
		add_action( 'woocommerce_checkout_update_order_meta', 'woolab_icdic_save_order_metadata' );
		// HPOS off, and its HPOS counterpart.
		add_action( 'manage_shop_order_posts_custom_column', 'woolab_icdic_show_check_failed_notice_on_orders_table', 20, 2 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', 'woolab_icdic_show_check_failed_notice_on_orders_table_hpos', 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', 'woolab_icdic_show_check_failed_notice_on_order_edit' );
		add_action( 'woocommerce_email_order_details', 'woolab_icdic_show_check_failed_notice_on_admin_email', 5, 3 );

		// Cart & Checkout Blocks: the classic hooks above never fire there.
		add_action( 'woocommerce_init', 'woolab_icdic_blocks_register_fields' );
		add_action( 'woocommerce_blocks_checkout_block_registration', 'woolab_icdic_blocks_register_integration' );
		add_filter( 'rest_pre_dispatch', 'woolab_icdic_blocks_remember_request', 10, 3 );
		add_action( 'woocommerce_blocks_validate_location_order_fields', 'woolab_icdic_blocks_validate', 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'woolab_icdic_blocks_update_order', 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', 'woolab_icdic_blocks_customer_vat_exempt', 10, 2 );
		add_action( 'woocommerce_init', 'woolab_icdic_blocks_register_update_callback' );
		add_action( 'woocommerce_before_calculate_totals', 'woolab_icdic_blocks_restore_vat_exempt' );
		add_filter( 'woocommerce_localisation_address_formats', 'woolab_icdic_blocks_strip_address_tokens', 20 );
		add_filter( 'option_woocommerce_checkout_company_field', 'woolab_icdic_blocks_hide_core_company' );
		add_filter( 'default_option_woocommerce_checkout_company_field', 'woolab_icdic_blocks_hide_core_company' );

		add_filter( 'woocommerce_ajax_get_customer_details', 'woolab_icdic_ajax_get_customer_details', 10, 3 );
		add_filter( 'plugin_row_meta', 'woolab_icdic_plugin_row_meta', 10, 2 );

		if ( is_admin() ) {
			add_filter( 'plugin_action_links_' . WOOLAB_IC_DIC_PLUGIN_BASENAME, 'woolab_icdic_plugin_action_links' );
		}
	}
}
