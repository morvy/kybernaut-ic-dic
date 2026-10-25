<?php
/**
 * Compatibility with Fluid Checkout for WooCommerce - Lite.
 * https://wordpress.org/plugins/fluid-checkout/
 *
 * Not hooked by the plugin (only partial support): call
 * `\KybernautIcDic\Compat\FluidCheckout::register();` from the theme or a plugin,
 * where 1.x included includes/compatibility/fluidcheckout.php.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Compat;

/**
 * Keep the company fields out of Fluid Checkout's hidden optional fields.
 */
final class FluidCheckout {

	/**
	 * Hook Fluid Checkout's skip list.
	 */
	public static function register(): void {
		add_filter( 'fc_hide_optional_fields_skip_list', array( self::class, 'optional_fields_skip_list' ), 10 );
	}

	/**
	 * Skip hiding optional fields with the "+ add" buttons.
	 *
	 * @see https://wordpress.org/support/topic/compatibility-with-kybernaut-ico-dic-plugin/#post-17330291
	 *
	 * @param string[] $skip_list Field ids Fluid Checkout does not hide.
	 * @return string[]
	 */
	public static function optional_fields_skip_list( $skip_list ) {
		$skip_list[] = 'billing_ic';
		$skip_list[] = 'billing_dic';
		return $skip_list;
	}
}
