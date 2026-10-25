<?php
/**
 * The Slovak DIČ in the Finančná správa DIČ list.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Numbers\Checksum;
use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;
use KybernautIcDic\Validation\SkLists;

/**
 * Errors invalid_tax_id_sk (not listed), dic_ico_mismatch (listed under another
 * IČO), sk_unverifiable (lists unavailable).
 */
final class TaxIdRegistry extends AbstractCheck {

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
		return 'tax_id_registry';
	}

	/**
	 * Whether the DIČ is looked up: a well-formed Slovak DIČ, the lists on. Also
	 * what IcDphTaxIdMatch relies on.
	 *
	 * @param Context $context The checkout.
	 */
	public static function looked_up( Context $context ): bool {
		return TaxId::SK === TaxId::route( $context ) && $context->flag( 'sk_check' ) && Checksum::dic_sk( $context->number( 'dic' ) );
	}

	/**
	 * See looked_up().
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool {
		return self::looked_up( $context );
	}

	/**
	 * Find it, and compare its IČO.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void {
		$row = $context->sk_list( 'dic', $context->number( 'dic' ) );

		if ( false === $row ) {
			$this->unverifiable( $context, $result, 'sk_unverifiable' );
		} elseif ( null === $row ) {
			$this->error( $result, 'invalid_tax_id_sk' );
		} elseif ( SkLists::ico_differs( $row, $context->number( 'ic' ) ) ) {
			$this->error( $result, 'dic_ico_mismatch' );
		}
	}
}
