<?php
/**
 * Which country gets which company field, and how strictly: the shop's country,
 * the countries where every company has a Business ID, the "Require DIČ" and
 * company fields settings.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Fields;

use KybernautIcDic\Settings\Options;

/**
 * The country rules the labels, the checkouts and the validation share.
 */
final class Countries {

	/**
	 * How the company fields are marked (Settings → IČO DIČ → General):
	 * - 'optional': WooCommerce's "(optional)", the default;
	 * - 'plain':    no mark, like any other field;
	 * - 'asterisk': marked required, not enforced (the 1.x woolab_icdic_fake_required filter);
	 * - 'required': marked, and a company cannot place the order without them.
	 *
	 * @return string
	 */
	public static function fields_mode() {
		$mode = Options::get( 'company_fields' );

		/**
		 * Mark the company name and Business ID required without enforcing it.
		 *
		 * @since 1.5.0
		 * @param bool $fake_required
		 */
		if ( in_array( $mode, array( 'optional', 'plain' ), true ) && apply_filters( 'woolab_icdic_fake_required', false ) ) {
			$mode = 'asterisk';
		}

		return in_array( $mode, array( 'optional', 'plain', 'asterisk', 'required' ), true ) ? $mode : 'optional';
	}

	/**
	 * The shop's country, as the VAT exemption sees it.
	 *
	 * @return string
	 */
	public static function base_country() {
		/**
		 * The shop's country for the VAT exemption and the VAT ID's mark.
		 *
		 * @since 1.0.0
		 * @param string $country WooCommerce's base country.
		 */
		return (string) apply_filters( 'woolab_icdic_base_country', WC()->countries->get_base_country() );
	}

	/**
	 * Whether a country is an EU member state, by WooCommerce's list (GR for Greece).
	 *
	 * @param string $country Country code.
	 */
	public static function in_eu( string $country ): bool {
		return in_array( $country, WC()->countries->get_european_union_countries(), true );
	}

	/**
	 * Whether a company from this country needs a DIČ (Settings → IČO DIČ → General →
	 * "Require DIČ"): with an IČO, and in the "Required" mode. Only in the shop's own
	 * country, and off by default: non-profit organisations may have an IČO but no
	 * DIČ, and abroad the DIČ is the VAT ID, which only VAT payers have.
	 *
	 * @param string $country Billing country.
	 * @return bool
	 */
	public static function dic_required( $country ) {
		if ( '' === $country || self::base_country() !== $country ) {
			return false;
		}

		$required = 'yes' === Options::get( 'dic_required' );

		if ( 'SK' !== $country ) {
			return $required;
		}

		/**
		 * Require the Slovak DIČ (for a Slovak shop).
		 *
		 * @since 1.4.0
		 * @param bool $required The setting.
		 */
		return (bool) apply_filters( 'woolab_icdic_sk_required_ic_and_dic', $required );
	}

	/**
	 * The countries the Business ID field is shown for, or null for every country
	 * (the default, Settings → IČO DIČ → Validation → "Business ID only for …").
	 *
	 * @return string[]|null
	 */
	public static function ic_countries() {
		return 'yes' === Options::get( 'ic_countries_only' ) ? self::ic_home_countries() : null;
	}

	/**
	 * The countries where every company has a Business ID: the field can be limited
	 * to them, and only there the "Required" mode asks for it.
	 *
	 * @return string[]
	 */
	public static function ic_home_countries() {
		/**
		 * Countries where every company has a Business ID (IČO).
		 *
		 * @since 2.0.0
		 * @param string[] $countries Country codes.
		 */
		return array_values( (array) apply_filters( 'woolab_icdic_ic_countries', array( 'CZ', 'SK' ) ) );
	}

	/**
	 * Whether the Business ID field is shown for a billing country.
	 *
	 * @param string $country Billing country code.
	 * @return bool
	 */
	public static function ic_shown( $country ) {
		$countries = self::ic_countries();
		return null === $countries || in_array( $country, $countries, true );
	}
}
