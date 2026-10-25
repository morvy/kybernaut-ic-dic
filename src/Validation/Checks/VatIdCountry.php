<?php
/**
 * The VAT number's country against the billing and shipping country.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Numbers\Checksum;
use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;

/**
 * Errors vat_country_mismatch_billing and vat_country_mismatch_shipping. Off with
 * the woolab_icdic_check_billing_country_and_dic filter.
 */
final class VatIdCountry extends AbstractCheck {

	/**
	 * The field its findings belong to.
	 *
	 * @var string
	 */
	protected string $field = 'dic';

	/**
	 * Unique id.
	 */
	public function id(): string {
		return 'vat_id_country';
	}

	/**
	 * A VAT number checked in VIES, with the country match on.
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool {
		return TaxId::VIES === TaxId::route( $context ) && $context->flag( 'check_country_match' );
	}

	/**
	 * Compare the prefix with both countries.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void {
		$prefix = Checksum::vat_country_code( $context->number( 'dic' ) );

		if ( $prefix !== $context->country() ) {
			$this->error( $result, 'vat_country_mismatch_billing' );
		}

		$shipping = $context->get( 'shipping_country' );
		if ( $context->flag( 'ship_to_different' ) && null !== $shipping && $prefix !== $shipping ) {
			$this->error( $result, 'vat_country_mismatch_shipping' );
		}
	}
}
