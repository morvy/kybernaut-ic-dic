<?php
/**
 * The VAT number in VIES.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Numbers\Checksum;
use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;

/**
 * Errors vat_format and invalid_vat, vat_unverifiable when VIES cannot answer; notice
 * vat_not_registered for a valid Czech, Polish or Hungarian tax number VIES does not
 * list (every business has one, only VAT payers are in VIES).
 */
final class VatIdVies extends AbstractCheck {

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
		return 'vat_id_vies';
	}

	/**
	 * A VAT number in the EU outside Slovakia, VIES on.
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool {
		return TaxId::VIES === TaxId::route( $context );
	}

	/**
	 * Ask VIES.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void {
		$dic   = $context->number( 'dic' );
		$state = $context->vat( $dic );

		if ( 'bad_format' === $state ) {
			// The format check fails, and so does the VIES one without asking.
			$this->error( $result, 'vat_format' );
			$this->error( $result, 'invalid_vat' );
		} elseif ( 'invalid' === $state && substr( $dic, 0, 2 ) === $context->country() && Checksum::tax_number( $dic ) ) {
			// The order goes through as a company's, with VAT.
			$result->add_notice( $this->field(), 'vat_not_registered' );
		} elseif ( 'invalid' === $state ) {
			$this->error( $result, 'invalid_vat' );
		} elseif ( 'unverifiable' === $state ) {
			$this->unverifiable( $context, $result, 'vat_unverifiable' );
		}
	}
}
