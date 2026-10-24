<?php

/**
 * Company identifiers for SuperFaktúra's client. The VAT number is IČ DPH in
 * Slovakia and the DIČ field everywhere else.
 *
 * @param array    $client_data SuperFaktúra client data.
 * @param WC_Order $order       Order.
 * @return array
 */
function woolab_icdic_superfaktura_client_data( $client_data, $order ) {
	$client_data['dic'] = $order->get_meta( '_billing_dic' );
	$client_data['ico'] = $order->get_meta( '_billing_ic' );
	// $country was never set, so the Slovak IČ DPH was never sent.
	$client_data['ic_dph'] = ( 'SK' === $order->get_billing_country() ) ? $order->get_meta( '_billing_dic_dph' ) : $order->get_meta( '_billing_dic' );

	return $client_data;
}
add_filter( 'sf_client_data', 'woolab_icdic_superfaktura_client_data', 10, 2 );
