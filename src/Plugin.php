<?php
/**
 * Boots the plugin on plugins_loaded.
 *
 * WooCommerce is loaded by then (`Requires Plugins`: WordPress refuses activation
 * without it). WordPress offers updates by the WordPress and PHP requirements only,
 * so a shop on an older WooCommerce can get this version: it stays inactive there,
 * with a notice, instead of breaking the checkout.
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
	 * The oldest WooCommerce it runs on; the header's "WC requires at least" (a unit
	 * test keeps them equal).
	 */
	public const MIN_WC = '10.0';

	/**
	 * The parts that hook themselves (each has a static register()).
	 *
	 * @var array<int,class-string>
	 */
	private const PARTS = array(
		Rest\Routes::class,
		Settings\Fields::class,
		Settings\FilterOverrides::class,
		Compat\SuperFaktura::class,
		Compat\PdfInvoices::class,
		Compat\FluidCheckout::class,
		Assets::class,
		Services\FinancnaSprava\Importer::class,
		Checkout\Classic::class,
		Checkout\Blocks::class,
		Checkout\CartExtension::class,
		Checkout\VatExempt::class,
		Order\Meta::class,
		Order\Evidence::class,
		Order\EvidenceBox::class,
		Order\Privacy::class,
		Order\AdminScreen::class,
		Order\Display::class,
		Order\Customer::class,
		EcSalesList\ExportPage::class,
		Admin\PluginLinks::class,
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

		if ( ! self::woocommerce_supported() ) {
			add_action( 'admin_notices', array( self::class, 'unsupported_notice' ) );
			return;
		}

		foreach ( self::PARTS as $part ) {
			$part::register();
		}
	}

	/**
	 * Whether WooCommerce is loaded and at least MIN_WC.
	 */
	public static function woocommerce_supported(): bool {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, self::MIN_WC, '>=' );
	}

	/**
	 * Why the plugin does nothing, to whoever can update plugins.
	 */
	public static function unsupported_notice(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: required WooCommerce version, 2: installed WooCommerce version */
					__( 'Kybernaut IČO DIČ needs WooCommerce %1$s or newer (this site has %2$s), so it is inactive: the checkout has no company fields and no VAT exemption. Update WooCommerce, or install Kybernaut IČO DIČ 1.11.', 'woolab-ic-dic' ),
					self::MIN_WC,
					defined( 'WC_VERSION' ) ? WC_VERSION : __( 'none', 'woolab-ic-dic' )
				)
			)
		);
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
}
