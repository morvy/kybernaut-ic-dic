<?php
/**
 * One rule for one field.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation;

/**
 * A check finds what is wrong with one field: Validator asks applies(), then run().
 * Add, replace or remove checks with the woolab_icdic_checks filter (Validator).
 */
interface Check {

	/**
	 * Unique id, the key in the woolab_icdic_checks list, e.g. 'vat_id_vies'.
	 */
	public function id(): string;

	/**
	 * The field its findings belong to: 'company', 'ic', 'dic' or 'dic_dph'. The
	 * checkouts show an error under that field.
	 */
	public function field(): string;

	/**
	 * Whether the check runs for this checkout: the country, what is filled in, the
	 * settings.
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool;

	/**
	 * Add the errors and notices found to the result.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void;
}
