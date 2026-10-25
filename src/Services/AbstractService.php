<?php
/**
 * A service that supports none of the features until it says otherwise.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Services;

/**
 * Override the features the service supports; id() and name() are each service's own.
 */
abstract class AbstractService implements Service {

	/**
	 * Not supported.
	 *
	 * @param string $name Company name.
	 */
	public function search_companies( string $name ) {
		return null;
	}

	/**
	 * Not supported.
	 *
	 * @param string $ico IČO.
	 */
	public function lookup_company( string $ico ): ?array {
		return null;
	}

	/**
	 * Not supported.
	 *
	 * @param string $dic DIČ.
	 */
	public function validate_tax_id( string $dic ): ?array {
		return null;
	}

	/**
	 * Not supported.
	 *
	 * @param string $vat VAT number.
	 */
	public function validate_vat_id( string $vat ): ?array {
		return null;
	}
}
