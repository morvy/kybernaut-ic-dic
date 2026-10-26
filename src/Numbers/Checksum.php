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
	 * Czech DIČ: "CZ" and an IČO or a birth number.
	 *
	 * @param string $dic Whitespace stripped.
	 */
	public static function cz_dic( string $dic ): bool {
		return str_starts_with( $dic, 'CZ' ) && ( self::rc( substr( $dic, 2 ) ) || self::dic( substr( $dic, 2 ) ) );
	}

	/**
	 * Whether a VAT number is the well-formed tax number of a country where every
	 * business has one in that form, VAT payer or not: the Czech DIČ, the Polish NIP,
	 * the Hungarian adószám (its first 8 digits). VIES lists only the VAT payers.
	 *
	 * @param string $vat Country prefix and number, whitespace stripped.
	 */
	public static function tax_number( string $vat ): bool {
		$prefix = substr( $vat, 0, 2 );
		$number = substr( $vat, 2 );

		if ( 'CZ' === $prefix ) {
			return self::cz_dic( $vat );
		}

		// Weighted sums: modulo 11 is the NIP's last digit, 10 minus modulo 10 the adószám's.
		$checks = array(
			'PL' => array( 10, array( 6, 5, 7, 2, 3, 4, 5, 6, 7 ) ),
			'HU' => array( 8, array( 9, 7, 3, 1, 9, 7, 3 ) ),
		);

		if ( ! isset( $checks[ $prefix ] ) || ! preg_match( '/^\d{' . $checks[ $prefix ][0] . '}$/', $number ) ) {
			return false;
		}

		$sum = 0;
		foreach ( $checks[ $prefix ][1] as $i => $weight ) {
			$sum += $weight * (int) $number[ $i ];
		}

		$check = 'PL' === $prefix ? $sum % 11 : ( 10 - $sum % 10 ) % 10;

		return (int) substr( $number, -1 ) === $check;
	}

	/**
	 * Whether a Polish NIP or Hungarian adószám is entered without its country code,
	 * as a business does that is a taxpayer but no VAT payer. Dashes are fine
	 * (526-025-09-95); a full adószám (12892312-2-41) is checked by its first 8 digits.
	 *
	 * @param string $country Billing country.
	 * @param string $number  Whitespace stripped.
	 */
	public static function domestic_tax_number( string $country, string $number ): bool {
		if ( ! in_array( $country, array( 'PL', 'HU' ), true ) || ! preg_match( '/^[0-9-]+$/', $number ) ) {
			return false;
		}

		$digits = str_replace( '-', '', $number );

		if ( 'HU' === $country && 11 === strlen( $digits ) ) {
			$digits = substr( $digits, 0, 8 );
		}

		return self::tax_number( $country . $digits );
	}

	/**
	 * Whether a VAT number has its country's format (not whether it was issued): the
	 * patterns and handling of ibericode/vat 2.0's Validator, so only uppercased.
	 *
	 * @see https://ec.europa.eu/taxation_customs/vies/faq.html#item_11
	 * @param string $vat VAT number with its prefix (EL for Greece).
	 */
	public static function vat_format( string $vat ): bool {
		$patterns = array(
			'AT' => 'U[A-Z\d]{8}',
			'BE' => '(0|1)\d{9}',
			'BG' => '\d{9,10}',
			'CY' => '\d{8}[A-Z]',
			'CZ' => '\d{8,10}',
			'DE' => '\d{9}',
			'DK' => '(\d{2} ?){3}\d{2}',
			'EE' => '\d{9}',
			'EL' => '\d{9}',
			'ES' => '([A-Z]\d{7}[A-Z]|\d{8}[A-Z]|[A-Z]\d{8})',
			'EU' => '\d{9}',
			'FI' => '\d{8}',
			'FR' => '[A-Z\d]{2}\d{9}',
			'GB' => '(\d{9}|\d{12}|(GD|HA)\d{3})',
			'HR' => '\d{11}',
			'HU' => '\d{8}',
			'IE' => '([A-Z\d]{8}|[A-Z\d]{9})',
			'IT' => '\d{11}',
			'LT' => '(\d{9}|\d{12})',
			'LU' => '\d{8}',
			'LV' => '\d{11}',
			'MT' => '\d{8}',
			'NL' => '\d{9}B\d{2}',
			'PL' => '\d{10}',
			'PT' => '\d{9}',
			'RO' => '\d{2,10}',
			'SE' => '\d{12}',
			'SI' => '\d{8}',
			'SK' => '\d{10}',
			'SM' => '\d{5}',
		);

		$vat    = strtoupper( $vat );
		$prefix = substr( $vat, 0, 2 );

		return isset( $patterns[ $prefix ] ) && 1 === preg_match( '/^' . $patterns[ $prefix ] . '$/', substr( $vat, 2 ) );
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
