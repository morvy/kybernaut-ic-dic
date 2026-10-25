<?php
/**
 * The Slovak IČ DPH: the Finančná správa list first, VIES for what it lacks.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Numbers\Checksum;
use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;

/**
 * Errors invalid_vat_dph, vat_unverifiable when neither can answer. With no vat_id
 * service, the format and check digit.
 */
final class IcDphVerify extends AbstractCheck {

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
		return 'ic_dph_verify';
	}

	/**
	 * A Slovak IČ DPH.
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool {
		return $context->entered( 'dic_dph' ) && 'SK' === $context->country();
	}

	/**
	 * Ask the vat_id services (the list, then VIES, by default), or check the digits.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void {
		$dic_dph = $context->number( 'dic_dph' );

		if ( ! $context->has( 'vat_id' ) ) {
			if ( ! Checksum::dic_dph_sk( $dic_dph ) ) {
				$this->error( $result, 'invalid_vat_dph' );
			}
			return;
		}

		$state = $context->vat( $dic_dph );

		if ( 'unverifiable' === $state ) {
			$this->unverifiable( $context, $result, 'vat_unverifiable' );
		} elseif ( 'valid' !== $state ) {
			// A bad format and an invalid number are one error here.
			$this->error( $result, 'invalid_vat_dph' );
		}
	}
}
