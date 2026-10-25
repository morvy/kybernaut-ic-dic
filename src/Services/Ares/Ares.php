<?php
/**
 * ARES, the Czech register of economic subjects.
 *
 * @see https://ares.gov.cz/stranky/vyvojar-info
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Services\Ares;

use KybernautIcDic\Logger;
use KybernautIcDic\Services\AbstractService;

/**
 * Company search and lookup (CZ) over the ARES REST API, cached.
 */
final class Ares extends AbstractService {

	private const URL = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/';

	/**
	 * Unique id.
	 */
	public function id(): string {
		return 'ares';
	}

	/**
	 * Display name, for the messages.
	 */
	public function name(): string {
		return 'ARES';
	}

	/**
	 * The company with this IČO.
	 *
	 * @param string $ico IČO.
	 * @return array<string,mixed>
	 */
	public function lookup_company( string $ico ): array {
		if ( '' === $ico ) {
			return array( 'error' => __( 'Business ID not set.', 'woolab-ic-dic' ) );
		}

		// Check the format before asking ARES.
		if ( ! is_numeric( $ico ) || 8 !== strlen( $ico ) ) {
			return array( 'error' => __( 'Business ID must be a number and 8 digits long.', 'woolab-ic-dic' ) );
		}

		// ARES data changes rarely, and the lookup endpoint is public: the cache spares
		// the register and keeps the store from being rate-limited by it.
		$cache_key = 'woolab_icdic_ares_' . $ico;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get( self::URL . $ico );

		if ( is_wp_error( $response ) ) {
			Logger::log( sprintf( 'An error occured while connecting to ARES: %s', $ico ) );
			Logger::log( $response );
			$return = array(
				'error'          => __( 'An error occured while connecting to ARES, try it again later.', 'woolab-ic-dic' ),
				'internal_error' => true,
			);
		} else {
			$return = self::parse( $ico, $response );
		}

		// Only definitive answers (a subject, a confirmed 404): a failure (internal_error)
		// is asked again next time.
		if ( empty( $return['internal_error'] ) ) {
			/**
			 * How long an ARES answer is cached.
			 *
			 * @since 1.11.1
			 * @param int                 $ttl    Seconds; 0 turns the cache off.
			 * @param string              $ico    IČO.
			 * @param array<string,mixed> $return The answer.
			 */
			$ttl = apply_filters( 'woolab_icdic_ares_cache_ttl', DAY_IN_SECONDS, $ico, $return );
			if ( $ttl > 0 ) {
				set_transient( $cache_key, $return, $ttl );
			}
		}

		return $return;
	}

	/**
	 * The subject from an ARES response.
	 *
	 * @param string              $ico      IČO.
	 * @param array<string,mixed> $response wp_remote_get() response.
	 * @return array<string,mixed>
	 */
	private static function parse( string $ico, $response ): array {
		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( 200 === $status_code && $data ) {
			$seat = $data['sidlo'] ?? array();
			// The DIČ only while the VAT registration is active.
			$vat_state = $data['seznamRegistraci']['stavZdrojeDph'] ?? null;
			$orient    = $seat['cisloOrientacni'] ?? '';
			$house     = $seat['cisloDomovni'] ?? '';
			$letter    = $seat['cisloOrientacniPismeno'] ?? '';
			$number    = '' !== $orient ? $house . '/' . $orient . $letter : $house;
			$street    = $seat['nazevUlice'] ?? $seat['nazevObce'] ?? '';

			Logger::info( 'Ares response:' );
			Logger::info( $body );

			return array(
				'error'      => false,
				'spolecnost' => $data['obchodniJmeno'] ?? '',
				'ico'        => $data['ico'] ?? '',
				'dic'        => 'AKTIVNI' === $vat_state ? ( $data['dic'] ?? '' ) : '',
				'adresa'     => sprintf( '%s %s', $street, $number ),
				'psc'        => (string) ( $seat['psc'] ?? '' ),
				'mesto'      => $seat['nazevMestskehoObvodu'] ?? $seat['nazevObce'] ?? '',
			);
		}

		if ( 404 === $status_code ) {
			Logger::log( sprintf( 'Entity doesn\'t exist in ARES: %s', $ico ) );
			Logger::log( $response );
			return array( 'error' => __( 'Entity doesn\'t exist in ARES.', 'woolab-ic-dic' ) );
		}

		Logger::log( sprintf( 'Ares is not responding, used IČO: %s', $ico ) );
		Logger::log( $response );
		return array(
			'error'          => __( 'ARES is not responding.', 'woolab-ic-dic' ),
			'internal_error' => true,
		);
	}

	/**
	 * Active subjects by name.
	 *
	 * @param string $name Company name.
	 * @return array<int,array{ico:string,name:string,address:string}>|false
	 */
	public function search_companies( string $name ) {
		// Public and fired while typing: cached to spare ARES.
		$cache_key = 'woolab_icdic_ares_search_' . md5( mb_strtolower( $name ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_post(
			self::URL . 'vyhledat',
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'obchodniJmeno' => $name,
						'pocet'         => 10,
					)
				),
			)
		);
		$code     = wp_remote_retrieve_response_code( $response );
		$data     = json_decode( wp_remote_retrieve_body( $response ), true );

		// A short or common name matches more than ARES returns; the shopper types on.
		if ( 400 === $code && 'VYSTUP_PRILIS_MNOHO_VYSLEDKU' === ( $data['subKod'] ?? '' ) ) {
			return array();
		}

		if ( 200 !== $code || ! isset( $data['ekonomickeSubjekty'] ) ) {
			Logger::log( sprintf( 'ARES search is not responding, used name: %s', $name ) );
			Logger::log( $response );
			return false;
		}

		$results = array();
		foreach ( $data['ekonomickeSubjekty'] as $subject ) {
			if ( empty( $subject['ico'] ) || ! empty( $subject['datumZaniku'] ) ) {
				continue;
			}
			$results[] = array(
				'ico'     => $subject['ico'],
				'name'    => $subject['obchodniJmeno'] ?? '',
				'address' => $subject['sidlo']['textovaAdresa'] ?? '',
			);
		}

		set_transient( $cache_key, $results, DAY_IN_SECONDS );

		return $results;
	}
}
