<?php
/**
 * The DIČ's format, where VIES does not check it.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Numbers\Checksum;
use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;

/**
 * Errors invalid_dic_cz (VIES off) and invalid_tax_id_sk.
 */
final class TaxIdFormat extends AbstractCheck {

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
		return 'tax_id_format';
	}

	/**
	 * A Czech DIČ with VIES off, or a Slovak DIČ.
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool {
		return in_array( TaxId::route( $context ), array( TaxId::CZ, TaxId::SK ), true );
	}

	/**
	 * "CZ" and an IČO or birth number; 10 digits in Slovakia.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void {
		$dic = $context->number( 'dic' );

		if ( TaxId::CZ === TaxId::route( $context ) ) {
			if ( ! Checksum::cz_dic( $dic ) ) {
				$this->error( $result, 'invalid_dic_cz' );
			}
		} elseif ( ! Checksum::dic_sk( $dic ) ) {
			$this->error( $result, 'invalid_tax_id_sk' );
		}
	}
}
