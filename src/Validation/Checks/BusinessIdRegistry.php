<?php
/**
 * The IČO in its business register: ARES (CZ), the Finančná správa lists (SK).
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;

/**
 * Errors invalid_business_id (not found), ares_unexpected (no answer), and with the
 * autofill on ares_mismatch / sk_mismatch for what differs from the register.
 */
final class BusinessIdRegistry extends AbstractCheck {

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
		return 'business_id_registry';
	}

	/**
	 * The register for the billing country, if it is switched on.
	 *
	 * @param Context $context The checkout.
	 * @return array{register:string,fill:bool,mismatch:string}|null
	 */
	public static function register( Context $context ): ?array {
		if ( 'CZ' === $context->country() && $context->flag( 'ares_check' ) ) {
			return array(
				'register' => 'ares',
				'fill'     => $context->flag( 'ares_fill' ),
				'mismatch' => 'ares_mismatch',
			);
		}

		if ( 'SK' === $context->country() && $context->flag( 'sk_check' ) ) {
			return array(
				'register' => 'sk',
				'fill'     => $context->flag( 'sk_fill' ),
				'mismatch' => 'sk_mismatch',
			);
		}

		return null;
	}

	/**
	 * An IČO entered where a register checks it.
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool {
		return $context->entered( 'ic' ) && null !== self::register( $context );
	}

	/**
	 * Look it up, and with the autofill compare what the register has.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void {
		$register = (array) self::register( $context );
		$subject  = $context->business( $register['register'], $context->number( 'ic' ) );

		if ( ! $subject ) {
			$this->unverifiable( $context, $result, 'ares_unexpected' );
			return;
		}

		// Not in the Slovak lists yet (new, or not registered for tax): the lookup
		// checked the format, there is nothing to compare.
		if ( ! empty( $subject['unlisted'] ) ) {
			return;
		}

		if ( $subject['error'] ) {
			if ( ! empty( $subject['internal_error'] ) && $context->flag( 'ignore_check_fail' ) ) {
				$result->ignore_check_fail();
			} else {
				$this->error( $result, 'invalid_business_id', array( 'ares_message' => $subject['error'] ) );
			}
			return;
		}

		if ( ! $register['fill'] ) {
			return;
		}

		$differs = array();
		// A null DIČ means the Slovak lists are unavailable, not that there is none.
		if ( $context->flag( 'dic_present' ) && null !== $subject['dic'] && (string) $context->get( 'dic' ) !== (string) $subject['dic'] ) {
			$differs[] = 'tax_id';
		}
		if ( (string) $context->get( 'company' ) !== (string) $subject['spolecnost'] ) {
			$differs[] = 'company';
		}
		if ( str_replace( ' ', '', (string) $context->get( 'postcode' ) ) !== str_replace( ' ', '', (string) $subject['psc'] ) ) {
			$differs[] = 'postcode';
		}
		if ( (string) $context->get( 'city' ) !== (string) $subject['mesto'] ) {
			$differs[] = 'city';
		}
		if ( (string) $context->get( 'address_1' ) !== (string) $subject['adresa'] ) {
			$differs[] = 'address';
		}

		if ( $differs ) {
			$this->error( $result, $register['mismatch'], array( 'fields' => $differs ) );
		}
	}
}
