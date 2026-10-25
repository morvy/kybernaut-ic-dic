<?php
/**
 * The order meta the classic checkout saves.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Order;

/**
 * The check-failed flag of a classic checkout order (the block checkout writes it in
 * Checkout\Blocks::update_order()).
 */
final class Meta {

	/**
	 * Hook the save.
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_update_order_meta', array( self::class, 'save_order_metadata' ) );
	}

	/**
	 * Saves order metadata on WooCommerce checkout.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function save_order_metadata( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$vat_check_fail_ignored = WC()->session->get( 'woolab_icdic_vat_check_fail_ignored' );

		$order->update_meta_data(
			'woolab_icdic_vat_check_fail_ignored',
			$vat_check_fail_ignored ? 'yes' : 'no'
		);

		$order->save_meta_data();
	}
}
