<?php
/**
 * Runs the checks.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation;

use KybernautIcDic\Validation\Checks;

/**
 * Both checkouts validate through here. Every check is hookable:
 *
 *     // A check of your own, or one replaced (same id).
 *     add_filter( 'woolab_icdic_checks', function ( array $checks ) {
 *         $checks['vat_id_vies'] = new My\PolishWhitelist();
 *         return $checks;
 *     } );
 *
 *     // A check switched off for one case.
 *     add_filter( 'woolab_icdic_check_applies', function ( $applies, $check, $context ) {
 *         return 'vat_id_country' === $check->id() && 'PL' === $context->country() ? false : $applies;
 *     }, 10, 3 );
 */
final class Validator {

	/**
	 * The built-in checks in the order they run (and their errors are listed).
	 *
	 * @return array<string,Check>
	 */
	public static function default_checks(): array {
		$checks = array(
			new Checks\CompanyRequired(),
			new Checks\BusinessIdRequired(),
			new Checks\BusinessIdRegistry(),
			new Checks\BusinessIdFormat(),
			new Checks\DomesticTaxNumber(),
			new Checks\VatIdCountry(),
			new Checks\VatIdVies(),
			new Checks\TaxIdFormat(),
			new Checks\TaxIdRegistry(),
			new Checks\TaxIdRequired(),
			new Checks\IcDphCountry(),
			new Checks\IcDphVerify(),
			new Checks\IcDphTaxIdMatch(),
		);

		$by_id = array();
		foreach ( $checks as $check ) {
			$by_id[ $check->id() ] = $check;
		}

		return $by_id;
	}

	/**
	 * Run the checks for a checkout.
	 *
	 * @param Context $context The checkout.
	 */
	public static function run( Context $context ): Result {
		/**
		 * The checks, keyed by id, in the order they run. Add one of your own (a
		 * KybernautIcDic\Validation\Check), replace a built-in one under its id, or
		 * unset it.
		 *
		 * @since 2.0.0
		 * @param array<string,Check> $checks  Validator::default_checks().
		 * @param Context             $context The checkout.
		 */
		$checks = apply_filters( 'woolab_icdic_checks', self::default_checks(), $context );
		$result = new Result();

		foreach ( (array) $checks as $check ) {
			if ( ! $check instanceof Check ) {
				continue;
			}

			/**
			 * Whether a check runs for this checkout.
			 *
			 * @since 2.0.0
			 * @param bool    $applies What the check decided (Check::applies()).
			 * @param Check   $check   The check; $check->id() names it.
			 * @param Context $context The checkout.
			 */
			if ( ! apply_filters( 'woolab_icdic_check_applies', $check->applies( $context ), $check, $context ) ) {
				continue;
			}

			$found = new Result();
			$check->run( $context, $found );

			/**
			 * What one check found: remove an error (Result::remove()) or add one.
			 *
			 * @since 2.0.0
			 * @param Result  $found   This check's errors and notices.
			 * @param Check   $check   The check.
			 * @param Context $context The checkout.
			 */
			$found = apply_filters( 'woolab_icdic_check_result', $found, $check, $context );

			if ( $found instanceof Result ) {
				$result->merge( $found );
			}
		}

		/**
		 * Everything the checks found.
		 *
		 * @since 2.0.0
		 * @param Result  $result  The errors and notices.
		 * @param Context $context The checkout.
		 */
		$final = apply_filters( 'woolab_icdic_validation_result', $result, $context );

		return $final instanceof Result ? $final : $result;
	}
}
