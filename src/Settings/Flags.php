<?php
/**
 * The switches the plugin runs by: a setting, and the 1.x filter that can decide
 * over it (Settings\FilterOverrides shows that on the settings page).
 *
 * @package KybernautIcDic
 * @since 2.0.0
 */

namespace KybernautIcDic\Settings;

/**
 * Each switch keeps its 1.x filter contract, and they differ: most filters get the
 * bool, the toggle and "Move Country to top" ones the raw 'yes' / 'no' (normalised
 * with `!== 'no'` afterwards), the VAT exemption ANDs in wc_tax_enabled(), and the
 * DIČ / IČ DPH match is stored as an inverted "disable" setting. Do not consolidate
 * them.
 */
final class Flags {

	/**
	 * Check the IČO in ARES (CZ).
	 */
	public static function ares_check(): bool {
		/**
		 * Check the IČO in ARES.
		 *
		 * @since 1.3.2
		 * @param bool $check The setting.
		 */
		return (bool) apply_filters( 'woolab_icdic_ares_check', 'yes' === Options::get( 'ares_check' ) );
	}

	/**
	 * Autofill and compare the company from ARES.
	 */
	public static function ares_fill(): bool {
		/**
		 * Autofill and compare the company from ARES.
		 *
		 * @since 1.3.2
		 * @param bool $fill The setting.
		 */
		return (bool) apply_filters( 'woolab_icdic_ares_fill', 'yes' === Options::get( 'ares_fill' ) );
	}

	/**
	 * Check SK numbers in the Finančná správa lists.
	 */
	public static function sk_check(): bool {
		/**
		 * Check SK numbers in the Finančná správa lists.
		 *
		 * @since 2.0.0
		 * @param bool $check The setting.
		 */
		return (bool) apply_filters( 'woolab_icdic_sk_check', 'yes' === Options::get( 'sk_check' ) );
	}

	/**
	 * Autofill and compare the company from the Finančná správa lists.
	 */
	public static function sk_fill(): bool {
		/**
		 * Autofill and compare the company from the Finančná správa lists.
		 *
		 * @since 2.0.0
		 * @param bool $fill The setting.
		 */
		return (bool) apply_filters( 'woolab_icdic_sk_fill', 'yes' === Options::get( 'sk_fill' ) );
	}

	/**
	 * Check VAT numbers in VIES.
	 */
	public static function vies_check(): bool {
		/**
		 * Check VAT numbers in VIES.
		 *
		 * @since 1.3.2
		 * @param bool $check The setting.
		 */
		return (bool) apply_filters( 'woolab_icdic_vies_check', 'yes' === Options::get( 'vies_check' ) );
	}

	/**
	 * Let the order through when a register cannot answer.
	 */
	public static function ignore_check_fail(): bool {
		/**
		 * Let the order through when a register cannot answer.
		 *
		 * @since 1.9.0
		 * @param bool $ignore The setting.
		 */
		return (bool) apply_filters( 'woolab_icdic_ignore_check_fail', 'yes' === Options::get( 'ignore_check_fail' ) );
	}

	/**
	 * Automatic VAT exemption for valid intra-EU B2B, only with taxes on.
	 */
	public static function vat_exempt(): bool {
		/**
		 * Automatic VAT exemption for valid intra-EU B2B.
		 *
		 * @since 1.7.0
		 * @param bool $enabled The setting, and taxes on.
		 */
		return (bool) apply_filters( 'woolab_icdic_vat_exempt_enabled', 'no' !== Options::get( 'vat_exempt' ) && wc_tax_enabled() );
	}

	/**
	 * The SK IČ DPH must be "SK" + DIČ. Stored as the inverted "disable" setting.
	 */
	public static function dic_dph_match(): bool {
		/**
		 * Require the SK IČ DPH to be "SK" + DIČ.
		 *
		 * @since 1.10.1
		 * @param bool $check The setting (stored inverted).
		 */
		return (bool) apply_filters( 'woolab_icdic_enable_dic_dicdph_match_check', 'yes' !== Options::get( 'disable_dic_dph_match' ) );
	}

	/**
	 * The "Buying as a company" toggle.
	 */
	public static function toggle(): bool {
		/**
		 * The "Buying as a company" toggle.
		 *
		 * @since 1.5.2
		 * @param string $toggle The setting, 'yes' or 'no'.
		 */
		return 'no' !== apply_filters( 'woolab_icdic_toggle', Options::get( 'toggle' ) );
	}

	/**
	 * Country above the toggle.
	 */
	public static function country_ontop(): bool {
		/**
		 * Move Country above the toggle.
		 *
		 * @since 1.5.2
		 * @param string $ontop The setting, 'yes' or 'no'.
		 */
		return 'no' !== apply_filters( 'woolab_icdic_country_ontop', Options::get( 'country_ontop' ) );
	}
}
