<?php
/**
 * EU VIES over its REST API.
 *
 * The European Commission publishes VIES as a keyless JSON API next to the old SOAP
 * service, so the check needs no SoapClient. ibericode/vat stays for the number
 * formats and the EU country list.
 *
 * @see https://ec.europa.eu/taxation_customs/vies/#/technical-information
 * @package KybernautIcDic
 * @since 2.0.0
 */

namespace KybernautIcDic\Services\Vies;

use KybernautIcDic\Logger;
use KybernautIcDic\Services\AbstractService;
use KybernautIcDicDeps\Ibericode\Vat\Validator as Formats;

/**
 * VAT ID validation: the format first, then VIES; definitive answers cached.
 */
final class Vies extends AbstractService {

	private const URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

	/**
	 * Logged when VIES cannot answer.
	 *
	 * @param string $log_message sprintf format with %s for the VAT number.
	 */
	public function __construct( private readonly string $log_message = 'Could not validate VAT number: %s' ) {
	}

	/**
	 * Unique id.
	 */
	public function id(): string {
		return 'vies';
	}

	/**
	 * Display name, for the messages.
	 */
	public function name(): string {
		return 'VIES';
	}

	/**
	 * Whether a VAT number is valid. VIES knows no IČO.
	 *
	 * @param string $vat VAT number with its prefix.
	 * @return array{state:string,ico:string}
	 */
	public function validate_vat_id( string $vat ): array {
		return array(
			'state' => $this->verify( $vat ),
			'ico'   => '',
		);
	}

	/**
	 * The state of a VAT number.
	 *
	 * @param string $vat VAT number with its prefix.
	 */
	private function verify( string $vat ): string {
		if ( ! ( new Formats() )->validateVatNumberFormat( $vat ) ) {
			return 'bad_format';
		}

		// A valid or invalid answer is stable: cached, as the exemption is decided on
		// every cart and checkout page load. An outage is asked again next time.
		$cache_key = 'woolab_icdic_vies_' . md5( $vat );
		$cached    = get_transient( $cache_key );
		if ( 'valid' === $cached || 'invalid' === $cached ) {
			return $cached;
		}

		$state = self::ask( $vat, $this->log_message );

		if ( 'unverifiable' !== $state ) {
			/**
			 * How long a VIES answer is cached.
			 *
			 * @since 1.11.1
			 * @param int    $ttl   Seconds; 0 turns the cache off.
			 * @param string $vat   VAT number.
			 * @param string $state 'valid' or 'invalid'.
			 */
			$ttl = apply_filters( 'woolab_icdic_vies_cache_ttl', DAY_IN_SECONDS, $vat, $state );
			if ( $ttl > 0 ) {
				set_transient( $cache_key, $state, $ttl );
			}
		}

		return $state;
	}

	/**
	 * Ask VIES, uncached.
	 *
	 * @param string $vat         VAT number with its prefix.
	 * @param string $log_message sprintf format with %s for the VAT number, logged when VIES cannot answer.
	 * @return string 'valid', 'invalid' or 'unverifiable'.
	 */
	public static function ask( string $vat, string $log_message = 'Could not validate VAT number: %s' ): string {
		$response = wp_remote_get(
			self::url( $vat ),
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
		$state    = self::state( $code, $body );

		if ( 'unverifiable' === $state ) {
			Logger::log( sprintf( $log_message, $vat ) );
			Logger::log( is_wp_error( $response ) ? $response->get_error_message() : $code . ' ' . $body );
		}

		return $state;
	}

	/**
	 * The REST URL of a VAT number.
	 *
	 * @param string $vat VAT number with its prefix.
	 */
	public static function url( string $vat ): string {
		return sprintf( self::URL, rawurlencode( strtoupper( substr( $vat, 0, 2 ) ) ), rawurlencode( substr( $vat, 2 ) ) );
	}

	/**
	 * A VIES REST answer as the verifier's state.
	 *
	 * @param int    $code HTTP status, 0 when the request failed.
	 * @param string $body JSON body.
	 * @return string 'valid', 'invalid' or 'unverifiable'.
	 */
	public static function state( int $code, string $body ): string {
		$data  = json_decode( $body, true );
		$error = is_array( $data ) && isset( $data['userError'] ) ? (string) $data['userError'] : '';

		if ( 200 === $code && in_array( $error, array( 'VALID', 'INVALID', 'INVALID_INPUT' ), true ) ) {
			return ! empty( $data['isValid'] ) ? 'valid' : 'invalid';
		}

		// MS_UNAVAILABLE, TIMEOUT, SERVICE_UNAVAILABLE, *_MAX_CONCURRENT_REQ or an HTTP error.
		return 'unverifiable';
	}
}
