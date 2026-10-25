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
use KybernautIcDic\Settings\Options;
use KybernautIcDicDeps\Ibericode\Vat\Validator as Formats;

/**
 * VAT ID validation: the format first, then VIES; definitive answers cached.
 */
final class Vies extends AbstractService {

	private const URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

	/**
	 * The answers of this request, by cache key.
	 *
	 * @var array<string,array{state:string,evidence?:array<string,string>}>
	 */
	private static array $answers = array();

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
	 * @return array{state:string,ico:string,evidence?:array<string,string>}
	 */
	public function validate_vat_id( string $vat ): array {
		return array( 'ico' => '' ) + $this->check( $vat );
	}

	/**
	 * The state of a VAT number, and what VIES returned as the proof of the check.
	 *
	 * @param string $vat   VAT number with its prefix.
	 * @param bool   $fresh Ask VIES even when the answer is cached (the admin's "Validate VAT ID").
	 * @return array{state:string,evidence?:array<string,string>}
	 */
	public function check( string $vat, bool $fresh = false ): array {
		if ( ! ( new Formats() )->validateVatNumberFormat( $vat ) ) {
			return array( 'state' => 'bad_format' );
		}

		$requester = self::requester();

		// A valid or invalid answer is stable: cached, as the exemption is decided on
		// every cart and checkout page load. An outage is asked again next time, but
		// not twice in one request (the checkout, then the order's evidence). The whole
		// answer is cached: the consultation number and its date stay the original's.
		$cache_key = 'woolab_icdic_vies_' . md5( $vat . '|' . $requester );
		$cached    = $fresh ? false : ( self::$answers[ $cache_key ] ?? get_transient( $cache_key ) );
		if ( is_array( $cached ) && isset( $cached['state'] ) ) {
			return $cached;
		}

		$answer = self::ask( $vat, $this->log_message, $requester );

		self::$answers[ $cache_key ] = $answer;

		if ( 'unverifiable' !== $answer['state'] ) {
			/**
			 * How long a VIES answer is cached.
			 *
			 * @since 1.11.1
			 * @param int    $ttl   Seconds; 0 turns the cache off.
			 * @param string $vat   VAT number.
			 * @param string $state 'valid' or 'invalid'.
			 */
			$ttl = apply_filters( 'woolab_icdic_vies_cache_ttl', DAY_IN_SECONDS, $vat, $answer['state'] );
			if ( $ttl > 0 ) {
				set_transient( $cache_key, $answer, $ttl );
			}
		}

		return $answer;
	}

	/**
	 * The shop's own VAT ID (Settings → Validation → "Your VAT ID"), sent as the
	 * requester so that VIES returns a consultation number. '' when not set.
	 */
	public static function requester(): string {
		return strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', Options::get( 'shop_vat_id' ) ) );
	}

	/**
	 * Ask VIES, uncached.
	 *
	 * @param string $vat         VAT number with its prefix.
	 * @param string $log_message sprintf format with %s for the VAT number, logged when VIES cannot answer.
	 * @param string $requester   The shop's VAT ID with its prefix, '' for none.
	 * @return array{state:string,evidence?:array<string,string>} State 'valid', 'invalid' or 'unverifiable'.
	 */
	public static function ask( string $vat, string $log_message = 'Could not validate VAT number: %s', string $requester = '' ): array {
		$response = wp_remote_get(
			self::url( $vat, $requester ),
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
		$answer   = self::answer( $code, $body );

		if ( 'unverifiable' === $answer['state'] ) {
			// A mistyped shop VAT ID must not stop every check: ask again without it.
			if ( '' !== $requester && str_contains( $body, '"INVALID_REQUESTER_INFO"' ) ) {
				Logger::log( sprintf( 'VIES rejected the shop VAT ID %s as the requester, checking %s without it.', $requester, $vat ) );
				return self::ask( $vat, $log_message );
			}
			Logger::log( sprintf( $log_message, $vat ) );
			Logger::log( is_wp_error( $response ) ? $response->get_error_message() : $code . ' ' . $body );
		}

		return $answer;
	}

	/**
	 * The REST URL of a VAT number. With a requester VIES answers with a
	 * consultation number (requestIdentifier).
	 *
	 * @param string $vat       VAT number with its prefix.
	 * @param string $requester The shop's VAT ID with its prefix, '' for none.
	 */
	public static function url( string $vat, string $requester = '' ): string {
		$url = sprintf( self::URL, rawurlencode( strtoupper( substr( $vat, 0, 2 ) ) ), rawurlencode( substr( $vat, 2 ) ) );

		if ( '' === $requester ) {
			return $url;
		}

		return $url . '?' . http_build_query(
			array(
				'requesterMemberStateCode' => strtoupper( substr( $requester, 0, 2 ) ),
				'requesterNumber'          => substr( $requester, 2 ),
			)
		);
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

		// MS_UNAVAILABLE, TIMEOUT, SERVICE_UNAVAILABLE, *_MAX_CONCURRENT_REQ,
		// INVALID_REQUESTER_INFO or an HTTP error.
		return 'unverifiable';
	}

	/**
	 * A VIES REST answer: the state, and for a definitive one the evidence — the
	 * date of the check, the consultation number (only with a requester, '' without),
	 * and the name and address VIES returned ('' where it returned "---").
	 *
	 * @param int    $code HTTP status, 0 when the request failed.
	 * @param string $body JSON body.
	 * @return array{state:string,evidence?:array<string,string>}
	 */
	public static function answer( int $code, string $body ): array {
		$state = self::state( $code, $body );

		if ( 'unverifiable' === $state ) {
			return array( 'state' => $state );
		}

		$data  = (array) json_decode( $body, true );
		$field = static fn ( $key ) => '---' === ( $data[ $key ] ?? '' ) ? '' : trim( (string) ( $data[ $key ] ?? '' ) );

		return array(
			'state'    => $state,
			'evidence' => array(
				'requested_at'        => $field( 'requestDate' ),
				'consultation_number' => $field( 'requestIdentifier' ),
				'name'                => $field( 'name' ),
				'address'             => $field( 'address' ),
				'source'              => 'vies',
			),
		);
	}
}
