<?php
/**
 * The IČO, in the "Required" mode, where every company has one.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;

/**
 * Error missing_business_id.
 */
final class BusinessIdRequired extends AbstractCheck {

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
		return 'business_id_required';
	}

	/**
	 * A company ("Required" mode) in a country where the IČO is asked for, without it.
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool {
		return $context->is_company() && $context->flag( 'require_ic' ) && '' === $context->number( 'ic' );
	}

	/**
	 * Report it.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void {
		$this->error( $result, 'missing_business_id' );
	}
}
