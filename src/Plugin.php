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
		Rest\Routes::class,
		Settings\Fields::class,
		Settings\FilterOverrides::class,
		Compat\SuperFaktura::class,
		Compat\PdfInvoices::class,
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

		foreach ( self::PARTS as $part ) {
			$part::register();
		}
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
