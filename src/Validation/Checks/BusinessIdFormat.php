<?php
/**
 * The IČO's check digit, where no register checks it.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Numbers\Checksum;
use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;

/**
 * Error invalid_business_id, for CZ and SK.
 */
final class BusinessIdFormat extends AbstractCheck {

	/**
	 * The field its findings belong to.
	 *
	 * @var string
	 */
	protected string $field = 'ic';

	/**
	 * Unique id.
	 */
	public function id(): string {
		return 'business_id_format';
	}

	/**
	 * A Czech or Slovak IČO that no register checks (BusinessIdRegistry).
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool {
		if ( ! $context->entered( 'ic' ) || null !== BusinessIdRegistry::register( $context ) ) {
			return false;
		}

		return 'CZ' === $context->country() || ( 'SK' === $context->country() && '' !== $context->number( 'ic' ) );
	}

	/**
	 * Modulo 11.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void {
		if ( ! Checksum::ic( $context->number( 'ic' ) ) ) {
			$this->error( $result, 'invalid_business_id' );
		}
	}
}
