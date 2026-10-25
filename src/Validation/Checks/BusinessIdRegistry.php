<?php
/**
 * The IČO in the company_lookup service of the billing country: ARES (CZ),
 * Finančná správa (SK), or a store's own (Services\Services).
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation\Checks;

use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;

/**
 * Errors invalid_business_id (not found), ares_unexpected (no answer), and with the
 * autofill on ares_mismatch / sk_mismatch for what differs from the service.
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
	 * Whether the autofill compares the company with what the service has, and the
	 * error code when it differs: ARES (CZ) and Finančná správa (SK) with their
	 * autofill setting on.
	 *
	 * @param Context $context The checkout.
	 * @return array{fill:bool,mismatch:string}
	 */
	public static function comparison( Context $context ): array {
		return array(
			'fill'     => ( 'CZ' === $context->country() && $context->flag( 'ares_fill' ) ) || ( 'SK' === $context->country() && $context->flag( 'sk_fill' ) ),
			'mismatch' => 'SK' === $context->country() ? 'sk_mismatch' : 'ares_mismatch',
		);
	}

	/**
	 * An IČO entered where a service looks companies up.
	 *
	 * @param Context $context The checkout.
	 */
	public function applies( Context $context ): bool {
		return $context->entered( 'ic' ) && $context->has( 'company_lookup' );
	}

	/**
	 * Look it up, and with the autofill compare what the register has.
	 *
	 * @param Context $context The checkout.
	 * @param Result  $result  Where the findings go.
	 */
	public function run( Context $context, Result $result ): void {
		$register = self::comparison( $context );
		$subject  = $context->company( $context->number( 'ic' ) );

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
