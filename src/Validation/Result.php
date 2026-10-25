<?php
/**
 * What the checks found.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation;

/**
 * Errors stop the order, notices are shown and let it through; check_fail_ignored
 * says a register could not answer and "Ignore when a check fails" let the order on.
 */
final class Result {

	/**
	 * Errors: field, code, data, message.
	 *
	 * @var array<int,array{field:string,code:string,data:array<string,mixed>,message:string}>
	 */
	private array $errors = array();

	/**
	 * Notices, same shape.
	 *
	 * @var array<int,array{field:string,code:string,data:array<string,mixed>,message:string}>
	 */
	private array $notices = array();

	/**
	 * Whether a failed register was ignored.
	 *
	 * @var bool
	 */
	private bool $check_fail_ignored = false;

	/**
	 * An error that stops the order.
	 *
	 * @param string              $field   'company', 'ic', 'dic' or 'dic_dph'.
	 * @param string              $code    Error code; Errors::message() has the text of the built-in ones.
	 * @param array<string,mixed> $data    What the message needs.
	 * @param string              $message Text for a code of your own (shown as is).
	 */
	public function add_error( string $field, string $code, array $data = array(), string $message = '' ): void {
		$this->errors[] = array(
			'field'   => $field,
			'code'    => $code,
			'data'    => $data,
			'message' => $message,
		);
	}

	/**
	 * A notice that lets the order through.
	 *
	 * @param string              $field   The field it is about.
	 * @param string              $code    Notice code.
	 * @param array<string,mixed> $data    What the message needs.
	 * @param string              $message Text for a code of your own.
	 */
	public function add_notice( string $field, string $code, array $data = array(), string $message = '' ): void {
		$this->notices[] = array(
			'field'   => $field,
			'code'    => $code,
			'data'    => $data,
			'message' => $message,
		);
	}

	/**
	 * A register could not answer and the order goes on anyway.
	 */
	public function ignore_check_fail(): void {
		$this->check_fail_ignored = true;
	}

	/**
	 * The errors.
	 *
	 * @return array<int,array{field:string,code:string,data:array<string,mixed>,message:string}>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * The notices.
	 *
	 * @return array<int,array{field:string,code:string,data:array<string,mixed>,message:string}>
	 */
	public function notices(): array {
		return $this->notices;
	}

	/**
	 * Whether a failed register was ignored.
	 */
	public function check_fail_ignored(): bool {
		return $this->check_fail_ignored;
	}

	/**
	 * Remove the errors or notices with a code, e.g. to let through what a check
	 * found (woolab_icdic_check_result).
	 *
	 * @param string $code Error or notice code.
	 */
	public function remove( string $code ): void {
		$keep          = static fn ( array $entry ) => $entry['code'] !== $code;
		$this->errors  = array_values( array_filter( $this->errors, $keep ) );
		$this->notices = array_values( array_filter( $this->notices, $keep ) );
	}

	/**
	 * Add another result's findings.
	 *
	 * @param Result $other A check's result.
	 */
	public function merge( Result $other ): void {
		$this->errors             = array_merge( $this->errors, $other->errors );
		$this->notices            = array_merge( $this->notices, $other->notices );
		$this->check_fail_ignored = $this->check_fail_ignored || $other->check_fail_ignored;
	}

	/**
	 * The array woolab_icdic_validate_checkout() returns: entries with 'code' and,
	 * when there is any, 'data'.
	 *
	 * @return array{errors:array<int,array<string,mixed>>,notices:array<int,array<string,mixed>>,check_fail_ignored:bool}
	 */
	public function to_array(): array {
		$legacy = static function ( array $entry ) {
			$out = array( 'code' => $entry['code'] );
			if ( $entry['data'] ) {
				$out['data'] = $entry['data'];
			}
			return $out;
		};

		return array(
			'errors'             => array_map( $legacy, $this->errors ),
			'notices'            => array_map( $legacy, $this->notices ),
			'check_fail_ignored' => $this->check_fail_ignored,
		);
	}
}
