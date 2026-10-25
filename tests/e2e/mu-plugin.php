<?php
/**
 * End-to-end tests only (copied to mu-plugins by tests/e2e/setup.php): with the
 * cookie woolab_e2e_checkout=classic, the shortcode page is WooCommerce's checkout,
 * so one site tests both checkouts.
 */

add_filter(
	'woocommerce_get_checkout_page_id',
	function ( $id ) {
		$classic = isset( $_COOKIE['woolab_e2e_checkout'] ) && 'classic' === $_COOKIE['woolab_e2e_checkout'];
		return $classic ? (int) get_option( 'woolab_icdic_e2e_classic_checkout', $id ) : $id;
	}
);

/*
 * VIES answered here: the member states' registers are slow and often turn
 * requests away (MS_MAX_CONCURRENT_REQ), which would fail the tests on nights the
 * shop's code is fine. The answers are the live API's (tests/unit/ViesTest.php).
 * ARES stays live.
 */
add_filter(
	'pre_http_request',
	function ( $response, $args, $url ) {
		if ( ! str_starts_with( $url, 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/' ) ) {
			return $response;
		}
		$valid = str_ends_with( $url, '/ms/CZ/vat/27082440' );
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'isValid' => $valid, 'userError' => $valid ? 'VALID' : 'INVALID' ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// With the cookie woolab_e2e_filter=sk_dic, a 1.x snippet turns the Slovak DIČ
// requirement off, the way a theme would (tests/e2e/settings.spec.js).
if ( isset( $_COOKIE['woolab_e2e_filter'] ) && 'sk_dic' === $_COOKIE['woolab_e2e_filter'] ) {
	add_filter( 'woolab_icdic_sk_required_ic_and_dic', '__return_false' );
}
