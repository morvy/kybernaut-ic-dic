<?php
/**
 * The plugin's public functions, kept for code that calls them (themes, other
 * plugins, 1.x snippets). Each one hands over to the class that does the work.
 *
 * Loaded by Composer (autoload "files"), so they exist as soon as the plugin is.
 *
 * @package KybernautIcDic
 */

use KybernautIcDic\Numbers\Checksum;
use KybernautIcDic\Settings\Flags;
use KybernautIcDic\Settings\Options;

const WOOLAB_ICDIC_SETTINGS = Options::NAME;

/**
 * Setting key => array( 1.x option name or null, default ).
 *
 * @return array<string,array{0:string|null,1:string}>
 */
function woolab_icdic_settings_schema() {
	return Options::schema();
}

/**
 * All settings, defaults filled in.
 *
 * @return array<string,string>
 */
function woolab_icdic_settings_all() {
	return Options::all();
}

/**
 * One setting.
 *
 * @param string $key A woolab_icdic_settings_schema() key.
 * @return string
 */
function woolab_icdic_setting( $key ) {
	return Options::get( (string) $key );
}

/**
 * Copy the 1.x options into the settings array.
 *
 * @param array<string,array{0:string|null,1:string}> $schema woolab_icdic_settings_schema().
 * @return array<string,string>
 */
function woolab_icdic_settings_migrate( array $schema ) {
	return Options::migrate( $schema );
}

/**
 * Whether a 1.x setting option is 'yes'.
 *
 * @deprecated 2.0.0 Use woolab_icdic_setting().
 * @param string $name    1.x option name.
 * @param string $default_value Default.
 * @return bool
 */
function woolab_icdic_get_option( $name, $default_value = 'yes' ) {
	foreach ( Options::schema() as $key => $definition ) {
		if ( $definition[0] === $name ) {
			return 'yes' === Options::get( $key );
		}
	}

	return 'yes' === get_option( $name, $default_value );
}

/**
 * Check the IČO in ARES.
 *
 * @return bool
 */
function woolab_icdic_ares_check() {
	return Flags::ares_check();
}

/**
 * Autofill the company from ARES.
 *
 * @return bool
 */
function woolab_icdic_ares_fill() {
	return Flags::ares_fill();
}

/**
 * Check SK numbers in the Finančná správa lists.
 *
 * @return bool
 */
function woolab_icdic_sk_check() {
	return Flags::sk_check();
}

/**
 * Autofill the company from the Finančná správa lists.
 *
 * @return bool
 */
function woolab_icdic_sk_fill() {
	return Flags::sk_fill();
}

/**
 * Check VAT numbers in VIES.
 *
 * @return bool
 */
function woolab_icdic_vies_check() {
	return Flags::vies_check();
}

/**
 * Let the order through when a register cannot answer.
 *
 * @return bool
 */
function woolab_icdic_ignore_check_fail() {
	return Flags::ignore_check_fail();
}

/**
 * Automatic VAT exemption for valid intra-EU B2B.
 *
 * @return bool
 */
function woolab_icdic_vat_exempt_enabled() {
	return Flags::vat_exempt();
}

/**
 * The SK IČ DPH must be "SK" + DIČ.
 *
 * @return bool
 */
function woolab_icdic_dic_dicdph_match_enabled() {
	return Flags::dic_dph_match();
}

/**
 * The "Buying as a company" toggle.
 *
 * @return bool
 */
function woolab_icdic_toggle_enabled() {
	return Flags::toggle();
}

/**
 * Country above the toggle.
 *
 * @return bool
 */
function woolab_icdic_country_ontop_enabled() {
	return Flags::country_ontop();
}

/**
 * The company name search.
 *
 * @return bool
 */
function woolab_icdic_suggest_enabled() {
	return Flags::suggest();
}

/**
 * Czech birth number.
 *
 * @param string $rc Birth number.
 * @return bool
 */
function woolab_icdic_verify_rc( $rc ) {
	return Checksum::rc( $rc );
}

/**
 * Czech or Slovak IČO.
 *
 * @param string|int $ic IČO.
 * @return bool
 */
function woolab_icdic_verify_ic( $ic ) {
	return Checksum::ic( $ic );
}

/**
 * Czech DIČ digits.
 *
 * @param string|int $dic DIČ without CZ.
 * @return bool
 */
function woolab_icdic_verify_dic( $dic ) {
	return Checksum::dic( $dic );
}

/**
 * Slovak DIČ.
 *
 * @param string|int $dic DIČ.
 * @return bool
 */
function woolab_icdic_verify_dic_sk( $dic ) {
	return Checksum::dic_sk( $dic );
}

/**
 * Slovak IČ DPH.
 *
 * @param string $dic_dph IČ DPH.
 * @return bool
 */
function woolab_icdic_verify_dic_dph_sk( $dic_dph ) {
	return Checksum::dic_dph_sk( $dic_dph );
}

/**
 * The WooCommerce country code of a VAT number.
 *
 * @param string $vat_number VAT number.
 * @return string
 */
function woolab_icdic_get_vat_number_country_code( $vat_number ) {
	return Checksum::vat_country_code( $vat_number );
}
