<?php
/**
 * What a checkout was submitted with, for the checks.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation;

/**
 * The entered values, the settings in effect and the registers, from the array both
 * checkouts build (woolab_icdic_validate_checkout() documents its keys). Register
 * answers are kept, so every check that needs one asks the register once.
 */
final class Context {

	/**
	 * Register answers by lookup and number.
	 *
	 * @var array<string,mixed>
	 */
	private array $answers = array();

	/**
	 * Build it from the checkout input.
	 *
	 * @param array<string,mixed> $input See woolab_icdic_validate_checkout().
	 */
	public function __construct( private array $input ) {
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
	 * The company in a business register: 'ares' (CZ) or 'sk' (the Finančná správa
	 * lists).
	 *
	 * @param string $register 'ares' or 'sk'.
	 * @param string $ico      IČO.
	 * @return array<string,mixed>|false|null woolab_icdic_ares()'s shape, falsy when the register failed.
	 */
	public function business( string $register, string $ico ) {
		$lookup = 'sk' === $register ? 'lookup_sk_ico' : 'lookup_ares';

		return $this->ask( $lookup, $ico );
	}

	/**
	 * A number in the Finančná správa DIČ or IČ DPH list.
	 *
	 * @param string $which  'dic' or 'dph'.
	 * @param string $number DIČ or IČ DPH.
	 * @return array<string,mixed>|false|null The row, null when not listed, false when the lists are unavailable.
	 */
	public function sk_list( string $which, string $number ) {
		return $this->ask( 'lookup_sk', $which, $number );
	}

	/**
	 * A VAT number's state.
	 *
	 * @param string $vat VAT number with its prefix.
	 * @return string 'valid', 'bad_format', 'invalid' or 'unverifiable'.
	 */
	public function vat( string $vat ): string {
		return (string) $this->ask( 'verify_vat', $vat );
	}

	/**
	 * Call a register callable once per arguments.
	 *
	 * @param string $lookup Input key of the callable.
	 * @param string ...$args Its arguments.
	 * @return mixed
	 */
	private function ask( string $lookup, string ...$args ) {
		$key = $lookup . ':' . implode( ':', $args );

		if ( ! array_key_exists( $key, $this->answers ) ) {
			$callable              = $this->input[ $lookup ] ?? null;
			$this->answers[ $key ] = is_callable( $callable ) ? $callable( ...$args ) : null;
		}

		return $this->answers[ $key ];
	}
}
