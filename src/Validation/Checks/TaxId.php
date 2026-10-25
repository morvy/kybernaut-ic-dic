<?php
/**
 * Which rule the DIČ field goes through.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Numbers\Checksum;
use KybernautIcDic\Validation\Context;

/**
 * The DIČ field holds a VAT number in the EU, except in Slovakia (an income tax
 * number there) and except a Polish or Hungarian tax number entered without its
 * country code. Exactly one of the routes applies to a filled-in DIČ in the EU.
 */
final class TaxId {

	/**
	 * A Polish NIP / Hungarian adószám without the country code (DomesticTaxNumber).
	 */
	public const DOMESTIC = 'domestic';

	/**
	 * A VAT number checked by the vat_id service, VIES by default (VatIdCountry, VatIdVies).
	 */
	public const VIES = 'vies';

	/**
	 * A Czech DIČ checked by its format, no vat_id service (TaxIdFormat).
	 */
	public const CZ = 'cz';

	/**
	 * A Slovak DIČ (TaxIdFormat, TaxIdRegistry).
	 */
	public const SK = 'sk';

	/**
	 * The route of the DIČ field, or null when it is empty, outside the EU, or a
	 * country whose DIČ only a vat_id service checks and none is assigned.
	 *
	 * @param Context $context The checkout.
	 */
	public static function route( Context $context ): ?string {
		if ( ! $context->entered( 'dic' ) || ! $context->flag( 'country_in_eu' ) ) {
			return null;
		}

		$country = $context->country();

		if ( Checksum::domestic_tax_number( $country, $context->number( 'dic' ) ) ) {
			return self::DOMESTIC;
		}

		// The Slovak DIČ is no VAT number: VIES cannot (and should not) check it.
		if ( $context->has( 'vat_id' ) && 'SK' !== $country ) {
			return self::VIES;
		}

		if ( 'CZ' === $country ) {
			return self::CZ;
		}

		return 'SK' === $country ? self::SK : null;
	}
}
