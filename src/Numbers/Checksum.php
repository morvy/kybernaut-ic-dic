<?php
/**
 * The format and check digit of each number, without asking any register.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Numbers;

/**
 * Pure functions: whitespace is stripped, nothing else is assumed.
 */
final class Checksum {

	/**
	 * Czech or Slovak IČO: 8 digits, modulo 11.
	 *
	 * @see https://phpfashion.com/jak-overit-platne-ic-a-rodne-cislo
	 * @param string|int $ic IČO.
	 */
	public static function ic( $ic ): bool {
		$ic = (string) preg_replace( '#\s+#', '', (string) $ic );

		if ( ! preg_match( '#^\d{8}$#', $ic ) ) {
			return false;
		}

		$sum = 0;
		for ( $i = 0; $i < 7; $i++ ) {
			$sum += (int) $ic[ $i ] * ( 8 - $i );
		}

		$sum %= 11;
		if ( 0 === $sum ) {
			$check = 1;
		} elseif ( 1 === $sum ) {
			$check = 0;
		} else {
			$check = 11 - $sum;
		}

		return (int) $ic[7] === $check;
	}

	/**
	 * Czech birth number (rodné číslo), which an individual's DIČ can be.
	 *
	 * @param string $rc Birth number, with or without the slash.
	 */
	public static function rc( $rc ): bool {
		if ( ! preg_match( '#^\s*(\d\d)(\d\d)(\d\d)[ /]*(\d\d\d)(\d?)\s*$#', (string) $rc, $matches ) ) {
			return false;
		}

		list( , $year, $month, $day, $ext, $check ) = $matches;

		$year  = (int) $year;
		$month = (int) $month;

		if ( '' === $check ) {
			$year += $year < 54 ? 1900 : 1800;
		} else {
			$mod = (int) ( $matches[1] . $matches[2] . $day . $ext ) % 11;
			if ( 10 === $mod ) {
				$mod = 0;
			}
			if ( (int) $check !== $mod ) {
				return false;
			}

			$year += $year < 54 ? 2000 : 1900;
		}

		// 20, 50 or 70 can be added to the month.
		if ( $month > 70 && $year > 2003 ) {
			$month -= 70;
		} elseif ( $month > 50 ) {
			$month -= 50;
		} elseif ( $month > 20 && $year > 2003 ) {
			$month -= 20;
		}

		return checkdate( $month, (int) $day, $year );
	}

	/**
	 * Czech DIČ without the CZ prefix: a legal entity's IČO, or an individual's.
	 *
	 * @see https://gist.githubusercontent.com/svschannak/e79892f4fbc56df15bdb5496d0e67b85/raw/5283c4a8056dad3d789e0eb77b2ce2e89a5b1f2c/vat_validation.js
	 * @param string|int $dic DIČ digits.
	 */
	public static function dic( $dic ): bool {
		$dic         = (string) preg_replace( '#\s+#', '', (string) $dic );
		$multipliers = array( 8, 7, 6, 5, 4, 3, 2 );
		$total       = 0;

		// 8 digits: legal entities.
		if ( preg_match( '/^\d{8}$/', $dic ) ) {
			for ( $i = 0; $i < 7; $i++ ) {
				$total += (int) $dic[ $i ] * $multipliers[ $i ];
			}

			$total = 11 - $total % 11;
			if ( 10 === $total ) {
				$total = 0;
			} elseif ( 11 === $total ) {
				$total = 1;
			}

			return (int) $dic[7] === $total;
		}

		// 9 digits, individuals: no check digit.
		if ( preg_match( '/^[0-5][0-9][0|1|5|6][0-9][0-3][0-9]\d{3}$/', $dic ) ) {
			return (int) substr( $dic, 0, 2 ) <= 62;
		}

		// 9 digits, individuals, special cases: a check digit from a lookup table.
		if ( preg_match( '/^6\d{8}$/', $dic ) ) {
			for ( $i = 0; $i < 7; $i++ ) {
				$total += (int) $dic[ $i + 1 ] * $multipliers[ $i ];
			}

			$rounded = 0 === $total % 11 ? $total + 11 : (int) ceil( $total / 11 ) * 11;
			$lookup  = array( 8, 7, 6, 5, 4, 3, 2, 1, 0, 9, 8 );

			return $lookup[ $rounded - $total - 1 ] === (int) $dic[8];
		}

		// 10 digits, individuals.
		if ( preg_match( '/^\d{2}[0-3|5-8][0-9][0-3][0-9]\d{4}$/', $dic ) ) {
			$sum = (int) substr( $dic, 0, 2 ) + (int) substr( $dic, 2, 4 ) + (int) substr( $dic, 4, 6 ) + (int) substr( $dic, 6, 8 ) + (int) substr( $dic, 8 );

			return 0 === $sum % 11 && 0 === (int) $dic % 11;
		}

		return false;
	}

	/**
	 * Slovak DIČ: 10 digits. Finančná správa publishes no check digit for it.
	 *
	 * @param string|int $dic DIČ.
	 */
	public static function dic_sk( $dic ): bool {
		return (bool) preg_match( '#^\d{10}$#', (string) preg_replace( '#\s+#', '', (string) $dic ) );
	}

	/**
	 * Slovak IČ DPH: SK and 10 digits divisible by 11.
	 *
	 * @param string $dic_dph IČ DPH.
	 */
	public static function dic_dph_sk( $dic_dph ): bool {
		$dic_dph = (string) preg_replace( '#\s+#', '', (string) $dic_dph );

		return (bool) preg_match( '#^SK\d{10}$#', $dic_dph ) && 0 === (int) substr( $dic_dph, 2 ) % 11;
	}

	/**
	 * The WooCommerce country code of a VAT number: its prefix, EL for Greece is GR.
	 *
	 * @param string $vat_number VAT number with its prefix.
	 */
	public static function vat_country_code( $vat_number ): string {
		$code = substr( (string) $vat_number, 0, 2 );

		return 'EL' === $code ? 'GR' : $code;
	}
}
