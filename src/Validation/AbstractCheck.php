<?php
/**
 * What the built-in checks share.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation;

/**
 * A check whose findings all belong to one field.
 */
abstract class AbstractCheck implements Check {

	/**
	 * The field the findings belong to.
	 *
	 * @var string
	 */
	protected string $field = '';

	/**
	 * The field its findings belong to.
	 */
	public function field(): string {
		return $this->field;
	}

	/**
	 * An error for this check's field.
	 *
	 * @param Result              $result Where it goes.
	 * @param string              $code   Error code (Errors::message()).
	 * @param array<string,mixed> $data   What the message needs.
	 */
	protected function error( Result $result, string $code, array $data = array() ): void {
		$result->add_error( $this->field(), $code, $data );
	}

	/**
	 * A register could not answer: an error, or the order goes on when "Ignore when
	 * a check fails" is on.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where it goes.
	 * @param string  $code    Error code when it is not ignored.
	 */
	protected function unverifiable( Context $context, Result $result, string $code ): void {
		if ( $context->flag( 'ignore_check_fail' ) ) {
			$result->ignore_check_fail();
		} else {
			$this->error( $result, $code );
		}
	}
}
