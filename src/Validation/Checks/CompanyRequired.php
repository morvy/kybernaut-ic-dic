<?php
/**
 * The company name, in the "Required" mode.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;

/**
 * Error missing_company: a company without its name.
 */
final class CompanyRequired extends AbstractCheck {

	/**
	 * The field its findings belong to.
	 *
	 * @var string
	 */
	protected string $field = 'company';

	/**
	 * Unique id.
	 */
	public function id(): string {
		return 'company_required';
	}

	/**
	 * A company ("Required" mode) that left the name empty.
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool {
		return $context->is_company() && ! $context->has_company();
	}

	/**
	 * Report it.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void {
		$this->error( $result, 'missing_company' );
	}
}
