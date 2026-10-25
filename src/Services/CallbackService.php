<?php
/**
 * A service made of callables, for a snippet.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Services;

/**
 * Each feature a callable, or not supported.
 *
 * For example, a VAT check of your own:
 *
 *     new CallbackService( 'my_vat', array( 'vat_id' => fn ( $vat ) => array( 'state' => 'valid', 'ico' => '' ) ) );
 */
final class CallbackService implements Service {

	/**
	 * Feature => callable.
	 *
	 * @param string                 $id        Unique id.
	 * @param array<string,callable> $callables 'company_search', 'company_lookup', 'tax_id', 'vat_id'.
	 */
	public function __construct( private readonly string $id, private readonly array $callables ) {
	}

	/**
	 * Unique id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Call a feature's callable, if there is one.
	 *
	 * @param string $feature Feature.
	 * @param string $value   Its argument.
	 * @return mixed
	 */
	private function call( string $feature, string $value ) {
		return isset( $this->callables[ $feature ] ) ? call_user_func( $this->callables[ $feature ], $value ) : null;
	}

	/**
	 * Companies by name.
	 *
	 * @param string $name Company name.
	 */
	public function search_companies( string $name ) {
		return $this->call( 'company_search', $name );
	}

	/**
	 * The company with this IČO.
	 *
	 * @param string $ico IČO.
	 */
	public function lookup_company( string $ico ): ?array {
		$answer = $this->call( 'company_lookup', $ico );

		return is_array( $answer ) ? $answer : null;
	}

	/**
	 * Whether a DIČ is registered.
	 *
	 * @param string $dic DIČ.
	 */
	public function validate_tax_id( string $dic ): ?array {
		$answer = $this->call( 'tax_id', $dic );

		return is_array( $answer ) ? $answer : null;
	}

	/**
	 * Whether a VAT number is valid.
	 *
	 * @param string $vat VAT number.
	 */
	public function validate_vat_id( string $vat ): ?array {
		$answer = $this->call( 'vat_id', $vat );

		return is_array( $answer ) ? $answer : null;
	}
}
