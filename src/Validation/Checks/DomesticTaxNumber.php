<?php
/**
 * A Polish or Hungarian tax number without its country code.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;

/**
 * Notice tax_number_only: the business enters it as a taxpayer, not as a VAT payer.
 * Nothing to ask VIES, the order is placed with VAT.
 */
final class DomesticTaxNumber extends AbstractCheck {

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
		return 'domestic_tax_number';
	}

	/**
	 * A valid NIP / adószám entered without PL / HU.
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool {
		return TaxId::DOMESTIC === TaxId::route( $context );
	}

	/**
	 * Tell the customer the order is placed with VAT.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void {
		$result->add_notice( $this->field(), 'tax_number_only' );
	}
}
