<?php
/**
 * What a checkout was submitted with, for the checks.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation;

use KybernautIcDic\Services\CallbackService;
use KybernautIcDic\Services\Service;
use KybernautIcDic\Services\Services;

/**
 * The entered values, the settings in effect and the services of the billing
 * country, from the array both checkouts build (woolab_icdic_validate_checkout()
 * documents its keys). Service answers are kept, so every check that needs one asks
 * once.
 */
final class Context {

	/**
	 * Register answers by lookup and number.
	 *
	 * @var array<string,mixed>
	 */
	private array $answers = array();

	/**
	 * The services by feature.
	 *
	 * @var array<string,array<int,Service>>
	 */
	private array $services;

	/**
	 * Build it from the checkout input.
	 *
	 * @param array<string,mixed> $input See woolab_icdic_validate_checkout(); 'services' is
	 *                                   Services::for_country(), or the 1.x callables
	 *                                   (verify_vat, lookup_ares, …) stand in for it.
	 */
	public function __construct( private array $input ) {
		$this->services = is_array( $input['services'] ?? null ) ? $input['services'] : $this->callable_services();
	}

	/**
	 * Billing country.
	 */
	public function country(): string {
		return (string) ( $this->input['country'] ?? '' );
	}

	/**
	 * A value as entered: 'company', 'ic', 'dic', 'dic_dph', 'postcode', 'city',
	 * 'address_1', or any other input key.
	 *
	 * @param string $key Input key.
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->input[ $key ] ?? null;
	}

	/**
	 * Whether a field is filled in (as the checkout sent it).
	 *
	 * @param string $field 'company', 'ic', 'dic' or 'dic_dph'.
	 */
	public function entered( string $field ): bool {
		return ! empty( $this->input[ $field ] );
	}

	/**
	 * A number without whitespace.
	 *
	 * @param string $field 'ic', 'dic' or 'dic_dph'.
	 */
	public function number( string $field ): string {
		return (string) preg_replace( '/\s+/', '', (string) ( $this->input[ $field ] ?? '' ) );
	}

	/**
	 * A setting or a fact the checkout passed in: 'ares_check', 'vies_check',
	 * 'country_in_eu', 'ignore_check_fail', …
	 *
	 * @param string $name Input key.
	 */
	public function flag( string $name ): bool {
		return ! empty( $this->input[ $name ] );
	}

	/**
	 * Whether a company name is filled in.
	 */
	public function has_company(): bool {
		return '' !== trim( (string) ( $this->input['company'] ?? '' ) );
	}

	/**
	 * Whether the customer orders as a company in the "Required" mode: "Buying as a
	 * company" ticked with the toggle on, a filled-in company name without it.
	 */
	public function is_company(): bool {
		if ( ! $this->flag( 'require_company' ) ) {
			return false;
		}

		return $this->flag( 'toggle_enabled' ) ? $this->flag( 'iscomp' ) : $this->has_company();
	}

	/**
	 * Whether a feature has a service: 'company_search', 'company_lookup', 'tax_id',
	 * 'vat_id'.
	 *
	 * @param string $feature A Services::FEATURES value.
	 */
	public function has( string $feature ): bool {
		return ! empty( $this->services[ $feature ] );
	}

	/**
	 * The first service of a feature, e.g. to name it in a message.
	 *
	 * @param string $feature A Services::FEATURES value.
	 */
	public function service( string $feature ): ?Service {
		return $this->services[ $feature ][0] ?? null;
	}

	/**
	 * The company with this IČO (Service::lookup_company()).
	 *
	 * @param string $ico IČO.
	 * @return array<string,mixed>|null
	 */
	public function company( string $ico ): ?array {
		return $this->remember( 'company:' . $ico, fn () => Services::first( $this->services['company_lookup'] ?? array(), 'lookup_company', $ico ) );
	}

	/**
	 * Whether a DIČ is registered, and to which IČO (Service::validate_tax_id()).
	 *
	 * @param string $dic DIČ.
	 * @return array{state:string,ico:string}|null
	 */
	public function tax_id( string $dic ): ?array {
		return $this->remember( 'tax_id:' . $dic, fn () => Services::first( $this->services['tax_id'] ?? array(), 'validate_tax_id', $dic ) );
	}

	/**
	 * Whether a VAT number is valid, and to which IČO (Service::validate_vat_id(),
	 * the services in order).
	 *
	 * @param string $vat VAT number with its prefix.
	 * @return array{state:string,ico:string}|null
	 */
	public function vat_id( string $vat ): ?array {
		return $this->remember( 'vat_id:' . $vat, fn () => Services::vat_id( $this->services['vat_id'] ?? array(), $vat ) );
	}

	/**
	 * A VAT number's state.
	 *
	 * @param string $vat VAT number with its prefix.
	 * @return string 'valid', 'bad_format', 'invalid' or 'unverifiable'.
	 */
	public function vat( string $vat ): string {
		return (string) ( $this->vat_id( $vat )['state'] ?? 'unverifiable' );
	}

	/**
	 * An answer, asked once.
	 *
	 * @param string   $key Answer key.
	 * @param callable $ask Asks the service.
	 * @return mixed
	 */
	private function remember( string $key, callable $ask ) {
		if ( ! array_key_exists( $key, $this->answers ) ) {
			$this->answers[ $key ] = $ask();
		}

		return $this->answers[ $key ];
	}

	/**
	 * The services the 1.x input describes: the settings flags and the callables
	 * lookup_ares, lookup_sk_ico, lookup_sk and verify_vat.
	 *
	 * @return array<string,array<int,Service>>
	 */
	private function callable_services(): array {
		$country  = $this->country();
		$sk       = 'SK' === $country && $this->flag( 'sk_check' );
		$call     = fn ( string $key, ...$args ) => is_callable( $this->input[ $key ] ?? null ) ? $this->input[ $key ]( ...$args ) : null;
		$services = array();

		if ( 'CZ' === $country && $this->flag( 'ares_check' ) ) {
			$services['company_lookup'] = array( new CallbackService( 'ares', array( 'company_lookup' => fn ( $ico ) => $call( 'lookup_ares', $ico ) ) ) );
		} elseif ( $sk ) {
			$services['company_lookup'] = array( new CallbackService( 'financna_sprava', array( 'company_lookup' => fn ( $ico ) => $call( 'lookup_sk_ico', $ico ) ) ) );
		}

		if ( $sk ) {
			$services['tax_id'] = array(
				new CallbackService(
					'financna_sprava',
					array(
						'tax_id' => static function ( $dic ) use ( $call ) {
							$row = $call( 'lookup_sk', 'dic', $dic );
							return array(
								'state' => false === $row ? 'unverifiable' : ( $row ? 'valid' : 'invalid' ),
								'ico'   => $row ? (string) $row['ico'] : '',
							);
						},
					)
				),
			);
		}

		if ( 'SK' === $country ? ( $this->flag( 'vies_check' ) || $sk ) : $this->flag( 'vies_check' ) ) {
			$services['vat_id'] = array(
				new CallbackService(
					'vies',
					array(
						'vat_id' => static function ( $vat ) use ( $call, $sk ) {
							$row = $sk ? $call( 'lookup_sk', 'dph', $vat ) : null;
							return array(
								'state' => (string) $call( 'verify_vat', $vat ),
								'ico'   => $row ? (string) $row['ico'] : '',
							);
						},
					)
				),
			);
		}

		return $services;
	}
}
