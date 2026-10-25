<?php
/**
 * The Slovak IČ DPH belongs to the same company as the DIČ and the IČO.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;
use KybernautIcDic\Validation\SkLists;

/**
 * Error dic_dph_mismatch: the IČ DPH is listed under another IČO, or is not "SK" +
 * DIČ, unless the lists tie both to the same IČO (VAT groups and some non-profits).
 * Off with the "SK: Disable DIČ / IČ DPH match check" setting.
 */
final class IcDphTaxIdMatch extends AbstractCheck {

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
		return 'ic_dph_tax_id_match';
	}

	/**
	 * A Slovak IČ DPH, the match check on.
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool {
		return $context->entered( 'dic_dph' ) && 'SK' === $context->country() && $context->flag( 'check_dic_dph_match' );
	}

	/**
	 * Compare with the IČO and the DIČ.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void {
		$dic_dph = $context->number( 'dic_dph' );
		$dic     = $context->number( 'dic' );
		// The IČO the services tie the IČ DPH to ('' when none does, e.g. VIES).
		$dph_ico = $context->has( 'vat_id' ) ? (string) ( $context->vat_id( $dic_dph )['ico'] ?? '' ) : '';

		if ( SkLists::ico_differs( array( 'ico' => $dph_ico ), $context->number( 'ic' ) ) ) {
			$this->error( $result, 'dic_dph_mismatch' );
			return;
		}

		if ( '' === $dic_dph || '' === $dic || substr( $dic_dph, 2 ) === $dic ) {
			return;
		}

		// Both tied to the same IČO, if TaxIdRegistry looked the DIČ up.
		$dic_ico = TaxIdRegistry::looked_up( $context ) ? (string) ( $context->tax_id( $dic )['ico'] ?? '' ) : '';
		$linked  = '' !== $dph_ico && $dph_ico === $dic_ico;

		if ( ! $linked ) {
			$this->error( $result, 'dic_dph_mismatch' );
		}
	}
}
