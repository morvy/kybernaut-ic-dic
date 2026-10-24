<?php
/**
 * EU VIES over its REST API.
 *
 * The European Commission publishes VIES as a keyless JSON API next to the old
 * SOAP service, so the check no longer needs PHP's SoapClient extension.
 * ibericode/vat stays for the number formats and the EU country list.
 * https://ec.europa.eu/taxation_customs/vies/#/technical-information
 *
 * @since 2.0.0
 */

use KybernautIcDic\Logger;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

const WOOLAB_ICDIC_VIES_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

/**
 * Check a VAT number in VIES.
 *
 * @param string $vat         VAT number with its country prefix (Greece: EL), no spaces.
 * @param string $log_message sprintf format with %s for the VAT number, logged when VIES cannot answer.
 * @return string 'valid', 'invalid', or 'unverifiable' when VIES or the member state's
 *                register is down or busy.
 */
function woolab_icdic_vies_rest( $vat, $log_message = 'Could not validate VAT number: %s' ) {

	$response = wp_remote_get(
		woolab_icdic_vies_url( $vat ),
		array(
			'timeout' => 10,
			// WordPress sends "Connection: close", and the Commission's server then ends
			// the TLS session without a close_notify: cURL reports "unexpected eof"
			// (error 56) and WordPress drops the 200 it had already received.
			'headers' => array(
				'Connection' => 'keep-alive',
				'Accept'     => 'application/json',
			),
		)
	);
	$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	$body     = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
	$state    = woolab_icdic_vies_state( $code, $body );

	if ( 'unverifiable' === $state ) {
		$logger = Logger::getInstance();
		$logger->log( sprintf( $log_message, $vat ) );
		$logger->log( is_wp_error( $response ) ? $response->get_error_message() : $code . ' ' . $body );
	}

	return $state;
}

/**
 * @param string $vat VAT number with its country prefix.
 * @return string
 */
function woolab_icdic_vies_url( $vat ) {
	return sprintf( WOOLAB_ICDIC_VIES_URL, rawurlencode( strtoupper( substr( $vat, 0, 2 ) ) ), rawurlencode( substr( $vat, 2 ) ) );
}

/**
 * A VIES REST answer as the verifier's state.
 *
 * @param int    $code HTTP status, 0 when the request failed.
 * @param string $body JSON body.
 * @return string 'valid', 'invalid' or 'unverifiable'.
 */
function woolab_icdic_vies_state( $code, $body ) {
	$data  = json_decode( (string) $body, true );
	$error = is_array( $data ) && isset( $data['userError'] ) ? (string) $data['userError'] : '';

	if ( 200 === $code && in_array( $error, array( 'VALID', 'INVALID', 'INVALID_INPUT' ), true ) ) {
		return ! empty( $data['isValid'] ) ? 'valid' : 'invalid';
	}

	// MS_UNAVAILABLE, TIMEOUT, SERVICE_UNAVAILABLE, *_MAX_CONCURRENT_REQ, an HTTP error…
	return 'unverifiable';
}
