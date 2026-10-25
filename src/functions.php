<?php
/**
 * The plugin's public functions, kept for code that calls them (themes, other
 * plugins, 1.x snippets). Each one hands over to the class that does the work.
 *
 * Loaded by Composer (autoload "files"), so they exist as soon as the plugin is.
 *
 * @package KybernautIcDic
 */

use KybernautIcDic\Checkout\Blocks;
use KybernautIcDic\Checkout\VatExempt;
use KybernautIcDic\Compat\PdfInvoices;
use KybernautIcDic\Compat\SuperFaktura;
use KybernautIcDic\Fields\Countries;
use KybernautIcDic\Fields\Labels;
use KybernautIcDic\Numbers\Checksum;
use KybernautIcDic\Order\AdminScreen;
use KybernautIcDic\Rest\Routes;
use KybernautIcDic\Services\Services;
use KybernautIcDic\Settings\Fields;
use KybernautIcDic\Settings\Flags;
use KybernautIcDic\Settings\Options;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\SkLists;
use KybernautIcDic\Validation\Validator;

const WOOLAB_ICDIC_SETTINGS = Options::NAME;

const WOOLAB_ICDIC_REST_NAMESPACE = Routes::REST_NAMESPACE;

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
 * The company name search: whether any country has a company_search service.
 *
 * @return bool
 */
function woolab_icdic_suggest_enabled() {
	return (bool) array_column( Services::map(), 'company_search' );
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
/**
 * Validate the company fields of a checkout: every check in Validation\Validator
 * (hookable with woolab_icdic_checks, woolab_icdic_check_applies,
 * woolab_icdic_check_result and woolab_icdic_validation_result).
 *
 * Both checkouts build the input: the settings and filters resolved into flags, and
 * the registers as callables.
 *
 * @param array<string,mixed> $input {
 *     The checkout.
 *
 *     @type string      country            Billing country code (wc_clean'd).
 *     @type string      ic                 billing_ic, wc_clean'd (whitespace stripped here).
 *     @type string      dic                billing_dic, wc_clean'd (whitespace stripped here for VAT use).
 *     @type bool        dic_present        Whether billing_dic was present in the request (isset).
 *     @type string      dic_dph            billing_dic_dph, wc_clean'd (whitespace stripped here).
 *     @type string      company            billing_company, wc_clean'd.
 *     @type string      postcode           billing_postcode, wc_clean'd.
 *     @type string      city               billing_city, wc_clean'd.
 *     @type string      address_1          billing_address_1, wc_clean'd.
 *     @type bool        ship_to_different  Whether ship-to-different-address is checked.
 *     @type string|null shipping_country   Shipping country code, or null when not present.
 *     @type bool        ares_check
 *     @type bool        ares_fill
 *     @type bool        vies_check
 *     @type bool        sk_check           Optional. Check SK numbers in the Finančná správa lists.
 *     @type bool        sk_fill            Optional. Compare the SK company and address with those lists.
 *     @type bool        autofill           Optional. Outside CZ and SK: compare the company and address with the lookup service (Services::autofill()).
 *     @type bool        ignore_check_fail
 *     @type bool        check_country_match
 *     @type bool        require_dic_for_firms A company from this country needs a DIČ (woolab_icdic_dic_required()).
 *     @type bool        check_dic_dph_match
 *     @type bool        require_company    Optional. A company must enter its name ("Required" mode).
 *     @type bool        require_ic         Optional. A company must enter a Business ID in this country ("Required" mode).
 *     @type bool        toggle_enabled     Optional. Whether the "Buying as a company" toggle is on.
 *     @type bool        iscomp             Optional. Whether "Buying as a company" is checked.
 *     @type bool        country_in_eu      Resolved from ibericode Countries::isCountryCodeInEU().
 *     @type callable    verify_vat         fn(string $vat): string 'valid'|'bad_format'|'invalid'|'unverifiable'
 *     @type callable    lookup_ares        fn(string $ico): array   raw woolab_icdic_ares() result (or falsy)
 *     @type callable    lookup_sk_ico      Optional. fn(string $ico): array, woolab_icdic_sk_lookup() — same shape as lookup_ares.
 *     @type callable    lookup_sk          Optional. fn(string $list, string $number): array|null|false, woolab_icdic_sk_find().
 * }
 *
 * @return array{errors:array<int,array<string,mixed>>,notices:array<int,array<string,mixed>>,check_fail_ignored:bool}
 *         Errors and notices as ['code' => string, 'data' => array] ('data' omitted
 *         when unused); check_fail_ignored when a register failed and was ignored.
 */
function woolab_icdic_validate_checkout( array $input ) {
	return Validator::run( new Context( $input ) )->to_array();
}

/**
 * Czech DIČ: "CZ" and an IČO or a birth number.
 *
 * @param string $dic Whitespace stripped.
 * @return bool
 */
function woolab_icdic_cz_dic_valid( $dic ) {
	return Checksum::cz_dic( (string) $dic );
}

/**
 * A Polish NIP or Hungarian adószám entered without its country code.
 *
 * @param string $country Billing country.
 * @param string $number  Whitespace stripped.
 * @return bool
 */
function woolab_icdic_domestic_tax_number( $country, $number ) {
	return Checksum::domestic_tax_number( (string) $country, (string) $number );
}

/**
 * A well-formed Czech, Polish or Hungarian tax number (with its prefix).
 *
 * @param string $vat Country prefix and number.
 * @return bool
 */
function woolab_icdic_tax_number_valid( $vat ) {
	return Checksum::tax_number( (string) $vat );
}

/**
 * Whether a Finančná správa row belongs to another IČO than the one entered.
 *
 * @param array<string,mixed> $row Row with 'ico'.
 * @param string              $ico Entered IČO.
 * @return bool
 */
function woolab_icdic_sk_ico_differs( array $row, $ico ) {
	return SkLists::ico_differs( $row, (string) $ico );
}

/**
 * Whether a VIES state grants the VAT exemption.
 *
 * @param string $state             'valid', 'bad_format', 'invalid' or 'unverifiable'.
 * @param bool   $ignore_check_fail Whether a VIES outage still grants it.
 * @return bool
 */
function woolab_icdic_vat_exempt_from_state( $state, $ignore_check_fail ) {
	return VatExempt::from_state( (string) $state, (bool) $ignore_check_fail );
}

/**
 * The VIES-checkable VAT number: the IČ DPH in Slovakia, the DIČ elsewhere.
 *
 * @param string $country Billing country.
 * @param string $dic     DIČ.
 * @param string $dic_dph IČ DPH.
 * @return string
 */
function woolab_icdic_select_vat_number( $country, $dic, $dic_dph ) {
	return VatExempt::vat_number( (string) $country, (string) $dic, (string) $dic_dph );
}

/**
 * The field a validation error is about.
 *
 * @param string $code    Error code.
 * @param string $country Billing country.
 * @return string billing_company, billing_ic, billing_dic or billing_dic_dph.
 */
function woolab_icdic_checkout_error_field( $code, $country ) {
	return \KybernautIcDic\Validation\Errors::field( array( 'code' => (string) $code ), (string) $country );
}

/**
 * The translated text of a validation error.
 *
 * @param array<string,mixed> $error   ['code' => string, 'data' => array].
 * @param string              $country Billing country, for the field labels.
 * @return string
 */
function woolab_icdic_checkout_error_message( array $error, $country = '' ) {
	return \KybernautIcDic\Validation\Errors::message( $error, (string) $country );
}

const WOOLAB_ICDIC_SK_LISTS = \KybernautIcDic\Services\FinancnaSprava\Storage::LISTS;

if ( ! function_exists( 'woolab_icdic_ares' ) ) {
	/**
	 * The company with this IČO in ARES. To replace ARES, use the
	 * woolab_icdic_services filter.
	 *
	 * @param string|int $ico IČO.
	 * @return array<string,mixed>
	 */
	function woolab_icdic_ares( $ico = '' ) {
		return ( new \KybernautIcDic\Services\Ares\Ares() )->lookup_company( (string) $ico );
	}
}

/**
 * Companies by name in ARES.
 *
 * @param string $name Company name.
 * @return array<int,array{ico:string,name:string,address:string}>|false
 */
function woolab_icdic_ares_search( $name ) {
	return ( new \KybernautIcDic\Services\Ares\Ares() )->search_companies( (string) $name );
}

/**
 * Ask VIES about a VAT number, uncached.
 *
 * @param string $vat         VAT number with its prefix.
 * @param string $log_message sprintf format with %s, logged when VIES cannot answer.
 * @return string 'valid', 'invalid' or 'unverifiable'.
 */
function woolab_icdic_vies_rest( $vat, $log_message = 'Could not validate VAT number: %s' ) {
	return \KybernautIcDic\Services\Vies\Vies::ask( (string) $vat, (string) $log_message );
}

/**
 * The VIES REST URL of a VAT number.
 *
 * @param string $vat VAT number with its prefix.
 * @return string
 */
function woolab_icdic_vies_url( $vat ) {
	return \KybernautIcDic\Services\Vies\Vies::url( (string) $vat );
}

/**
 * A VIES REST answer as the verifier's state.
 *
 * @param int    $code HTTP status.
 * @param string $body JSON body.
 * @return string
 */
function woolab_icdic_vies_state( $code, $body ) {
	return \KybernautIcDic\Services\Vies\Vies::state( (int) $code, (string) $body );
}

/**
 * Where the Finančná správa SQLite files live.
 *
 * @return string
 */
function woolab_icdic_sk_dir() {
	return \KybernautIcDic\Services\FinancnaSprava\Storage::dir();
}

/**
 * What the Finančná správa import needs from PHP.
 *
 * @return array<string,bool>
 */
function woolab_icdic_sk_requirements() {
	return \KybernautIcDic\Services\FinancnaSprava\Storage::requirements();
}

/**
 * Whether PHP can run the Finančná správa import.
 *
 * @return bool
 */
function woolab_icdic_sk_import_supported() {
	return \KybernautIcDic\Services\FinancnaSprava\Storage::supported();
}

/**
 * The Finančná správa lists as the settings show them.
 *
 * @return array<string,mixed>
 */
function woolab_icdic_sk_status() {
	return \KybernautIcDic\Services\FinancnaSprava\Storage::status();
}

/**
 * Whether the imported lists are recent enough to trust.
 *
 * @return bool
 */
function woolab_icdic_sk_lists_fresh() {
	return \KybernautIcDic\Services\FinancnaSprava\Storage::fresh();
}

/**
 * Import the Finančná správa lists now.
 *
 * @return array{dic:int,dph:int}|false
 */
function woolab_icdic_sk_import() {
	return \KybernautIcDic\Services\FinancnaSprava\Importer::import();
}

/**
 * A DIČ ('dic') or IČ DPH ('dph') in the lists.
 *
 * @param string $which  'dic' or 'dph'.
 * @param string $number The number.
 * @return array{ico:string}|false|null
 */
function woolab_icdic_sk_find( $which, $number ) {
	return \KybernautIcDic\Services\FinancnaSprava\FinancnaSprava::find( (string) $which, (string) $number );
}

/**
 * The company with this IČO in the Finančná správa lists.
 *
 * @param string $ico IČO.
 * @return array<string,mixed>
 */
function woolab_icdic_sk_lookup( $ico = '' ) {
	return ( new \KybernautIcDic\Services\FinancnaSprava\FinancnaSprava() )->lookup_company( (string) $ico );
}

/**
 * Companies by name in the Finančná správa lists.
 *
 * @param string $name Company name.
 * @return array<int,array{ico:string,name:string,address:string}>|false
 */
function woolab_icdic_sk_search( $name ) {
	return ( new \KybernautIcDic\Services\FinancnaSprava\FinancnaSprava() )->search_companies( (string) $name );
}

/**
 * Save the company fields edited on the admin order screen.
 *
 * @param int         $post_id Post ID.
 * @param object|null $post    Post object.
 * @return void
 */
function woolab_icdic_process_shop_order( $post_id, $post ) {
	AdminScreen::process_shop_order( $post_id, $post );
}

/**
 * The block checkout's DIČ field id per billing country.
 *
 * @return array<string,string|null>
 */
function woolab_icdic_blocks_dic_fields() {
	return Blocks::dic_fields();
}

/**
 * Whether the company name belongs to the plugin's section of the block checkout.
 *
 * @return bool
 */
function woolab_icdic_blocks_owns_company() {
	return Blocks::owns_company();
}

/**
 * Whether the checkout page is built from the Checkout block.
 *
 * @return bool
 */
function woolab_icdic_blocks_checkout() {
	return Blocks::checkout();
}

/**
 * Labels of the company identifier fields for a billing country.
 *
 * @param string $country Billing country code, '' for the Slovak set.
 * @return array{ic:string, dic:string, dic_dph:string}
 */
function woolab_icdic_labels( $country ) {
	return Labels::labels( $country );
}

/**
 * What follows a label in the checkout form: 'optional', 'required', 'vat_payer' or ''.
 *
 * @param string $key     'company', 'ic', 'dic' or 'dic_dph'.
 * @param string $country Billing country code, '' when unknown.
 * @return string
 */
function woolab_icdic_field_mark( $key, $country ) {
	return Labels::field_mark( $key, $country );
}

/**
 * A label with its mark, as text or HTML.
 *
 * @param string $label Field label.
 * @param string $mark  woolab_icdic_field_mark().
 * @param bool   $html  HTML instead of text.
 * @return string
 */
function woolab_icdic_marked_label( $label, $mark, $html = false ) {
	return Labels::marked_label( $label, $mark, $html );
}

/**
 * The labels as the checkout form shows them, marked.
 *
 * @param string $country Billing country code.
 * @param bool   $html    HTML instead of text.
 * @return array{ic:string, dic:string, dic_dph:string}
 */
function woolab_icdic_form_labels( $country, $html = false ) {
	return Labels::form_labels( $country, $html );
}

/**
 * The labels for the classic checkout script, by billing country.
 *
 * @param bool $form Form labels as HTML, or the bare ones.
 * @return array<string,array<string,string>>
 */
function woolab_icdic_labels_by_country( $form = true ) {
	return Labels::labels_by_country( $form );
}

/**
 * A label in front of a value: "VAT ID: CZ27082440".
 *
 * @param string $label Field label.
 * @return string
 */
function woolab_icdic_label_prefix( $label ) {
	return Labels::label_prefix( $label );
}

/**
 * How the company fields are marked: 'optional', 'plain', 'asterisk' or 'required'.
 *
 * @return string
 */
function woolab_icdic_fields_mode() {
	return Countries::fields_mode();
}

/**
 * The shop's country, as the VAT exemption sees it.
 *
 * @return string
 */
function woolab_icdic_base_country() {
	return Countries::base_country();
}

/**
 * Whether a company from this country needs a DIČ.
 *
 * @param string $country Billing country.
 * @return bool
 */
function woolab_icdic_dic_required( $country ) {
	return Countries::dic_required( $country );
}

/**
 * The countries the Business ID field is shown for, or null for every country.
 *
 * @return string[]|null
 */
function woolab_icdic_ic_countries() {
	return Countries::ic_countries();
}

/**
 * The countries where every company has a Business ID.
 *
 * @return string[]
 */
function woolab_icdic_ic_home_countries() {
	return Countries::ic_home_countries();
}

/**
 * Whether the Business ID field is shown for a billing country.
 *
 * @param string $country Billing country code.
 * @return bool
 */
function woolab_icdic_ic_shown( $country ) {
	return Countries::ic_shown( $country );
}

/**
 * The base URL of the REST routes the checkout scripts call.
 *
 * @return string
 */
function woolab_icdic_rest_url() {
	return Routes::url();
}

/**
 * Settings URL, optionally of one section.
 *
 * @param string $section Section id, '' for General.
 * @return string
 */
function woolab_icdic_settings_url( $section = '' ) {
	return Fields::url( $section );
}

/**
 * Company identifiers for SuperFaktúra's client.
 *
 * @param array<string,mixed> $client_data SuperFaktúra client data.
 * @param WC_Order            $order       Order.
 * @return array<string,mixed>
 */
function woolab_icdic_superfaktura_client_data( $client_data, $order ) {
	return SuperFaktura::client_data( $client_data, $order );
}

/**
 * The company identifiers in the billing address of PDF Invoices and Packing Slips
 * for WooCommerce (Acowebs).
 *
 * @param string $html     Html output.
 * @param int    $order_id Order ID.
 * @return string
 */
function woolab_icdic_apifw_ps_custom_billing_fields( $html, $order_id ) {
	return PdfInvoices::custom_billing_fields( $html, $order_id );
}
