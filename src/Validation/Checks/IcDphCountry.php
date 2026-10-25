<?php
/**
 * The Slovak IČ DPH's prefix.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Numbers\Checksum;
use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;

/**
 * Error vat_country_mismatch_billing: an IČ DPH not starting with SK. Off with the
 * woolab_icdic_check_billing_country_and_dic filter.
 */
final class IcDphCountry extends AbstractCheck {

	/**
	 * The field its findings belong to.
	 *
	 * @var string
	 */
	protected string $field = 'dic_dph';

	/**
	 * Unique id.
	 */
	public function id(): string {
		return 'ic_dph_country';
	}

	/**
	 * A Slovak IČ DPH, the country match on.
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool {
		return $context->entered( 'dic_dph' ) && 'SK' === $context->country() && $context->flag( 'check_country_match' );
	}

	/**
	 * Compare the prefix.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void {
		if ( Checksum::vat_country_code( $context->number( 'dic_dph' ) ) !== $context->country() ) {
			$this->error( $result, 'vat_country_mismatch_billing' );
		}
	}
}
