<?php
/**
 * Compatibility with WooCommerce SuperFaktura.
 * https://wordpress.org/plugins/woocommerce-superfaktura/
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Compat;

use WC_Order;

/**
 * The company identifiers on SuperFaktúra's client.
 */
final class SuperFaktura {

	/**
	 * Hook SuperFaktúra's client data.
	 */
	public static function register(): void {
		add_filter( 'sf_client_data', array( self::class, 'client_data' ), 10, 2 );
	}

	/**
	 * Company identifiers for SuperFaktúra's client. The VAT number is IČ DPH in
	 * Slovakia and the DIČ field everywhere else.
	 *
	 * @param array<string,mixed> $client_data SuperFaktúra client data.
	 * @param WC_Order            $order       Order.
	 * @return array<string,mixed>
	 */
	public static function client_data( $client_data, $order ) {
		$client_data['dic'] = $order->get_meta( '_billing_dic' );
		$client_data['ico'] = $order->get_meta( '_billing_ic' );
		// $country was never set, so the Slovak IČ DPH was never sent.
		$client_data['ic_dph'] = ( 'SK' === $order->get_billing_country() ) ? $order->get_meta( '_billing_dic_dph' ) : $order->get_meta( '_billing_dic' );

		return $client_data;
	}
}
