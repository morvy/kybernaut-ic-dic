<?php
/**
 * The DIČ, where the shop requires it ("Require DIČ").
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;

/**
 * Errors missing_dic, invalid_tax_id_sk in Slovakia. Opt-in: non-profit
 * organisations may have an IČO but no DIČ.
 */
final class TaxIdRequired extends AbstractCheck {

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
		return 'tax_id_required';
	}

	/**
	 * No DIČ from a company of the shop's country ("Require DIČ"): one that entered
	 * its IČO, or a company in the "Required" mode.
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool {
		return ! $context->entered( 'dic' ) && $context->flag( 'require_dic_for_firms' ) && ( $context->is_company() || $context->entered( 'ic' ) );
	}

	/**
	 * Report it.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void {
		$this->error( $result, 'SK' === $context->country() ? 'invalid_tax_id_sk' : 'missing_dic' );
	}
}
