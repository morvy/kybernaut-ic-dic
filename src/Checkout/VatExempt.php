<?php
/**
 * The intra-EU VAT exemption.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Checkout;

/**
 * Which number the exemption is decided by, and the decision.
 */
final class VatExempt {

	/**
	 * The field that carries the VIES-checkable VAT number: the IČ DPH in Slovakia,
	 * the DIČ everywhere else.
	 *
	 * @param string $country Billing country.
	 * @param string $dic     DIČ.
	 * @param string $dic_dph IČ DPH.
	 */
	public static function vat_number( string $country, string $dic, string $dic_dph ): string {
		return 'SK' === $country ? $dic_dph : $dic;
	}

	/**
	 * Whether a VIES state grants the exemption: a valid number does; VIES not
	 * answering does with "Ignore when a check fails"; anything else does not.
	 *
	 * @param string $state             'valid', 'bad_format', 'invalid' or 'unverifiable'.
	 * @param bool   $ignore_check_fail Whether a VIES outage still grants it.
	 */
	public static function from_state( string $state, bool $ignore_check_fail ): bool {
		if ( 'valid' === $state ) {
			return true;
		}

		return 'unverifiable' === $state && $ignore_check_fail;
	}
}
