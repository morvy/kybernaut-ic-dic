<?php
/**
 * Compatibility with Fluid Checkout for WooCommerce - Lite.
 * https://wordpress.org/plugins/fluid-checkout/
 *
 * Registered by Plugin, active only when Fluid Checkout is. Calling register() from
 * a theme, as before 2.0, still works: WordPress adds the same callback once.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Compat;

use KybernautIcDic\Settings\Flags;

/**
 * Keep the company fields out of Fluid Checkout's "+ Add …" sections. It moves an
 * optional field's classes onto the section, so the "Buying as a company" toggle
 * (`woolab-ic-dic-toggle`, resources/js/classic/checkout.js) would hide the section
 * and never show the field. Fluid Checkout also re-renders the billing fields after
 * every checkout refresh; the checkout script shows them again then.
 */
final class FluidCheckout {

	/**
	 * The fields the toggle shows and hides.
	 */
	private const FIELDS = array( 'billing_company', 'billing_ic', 'billing_dic', 'billing_dic_dph' );

	/**
	 * Hook Fluid Checkout's skip list, when Fluid Checkout is active.
	 */
	public static function register(): void {
		if ( ! class_exists( 'FluidCheckout' ) ) {
			return;
		}

		add_filter( 'fc_hide_optional_fields_skip_list', array( self::class, 'optional_fields_skip_list' ), 10 );
		add_filter( 'fc_billing_same_as_shipping_field_keys', array( self::class, 'company_not_copied' ), 10 );
	}

	/**
	 * With "Buying as a company" the company name belongs with the identifiers, not
	 * with the billing address copied from shipping: Fluid Checkout collapses that
	 * address under "Same as shipping address". It counts a field as copied when the
	 * shipping fields have one too, which they do from WooCommerce 11.3.
	 *
	 * @param string[] $keys Billing fields copied from the shipping address.
	 * @return string[]
	 */
	public static function company_not_copied( $keys ) {
		return Flags::toggle() ? array_values( array_diff( (array) $keys, array( 'billing_company' ) ) ) : $keys;
	}

	/**
	 * Fields Fluid Checkout does not hide behind "+ Add …".
	 *
	 * @see https://wordpress.org/support/topic/compatibility-with-kybernaut-ico-dic-plugin/#post-17330291
	 *
	 * @param string[] $skip_list Field IDs Fluid Checkout does not hide.
	 * @return string[]
	 */
	public static function optional_fields_skip_list( $skip_list ) {
		return array_merge( (array) $skip_list, self::FIELDS );
	}
}
