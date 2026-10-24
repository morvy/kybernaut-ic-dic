<?php
/**
 * Field labels by billing country.
 *
 * In Slovakia there are three numbers: IČO, DIČ (income tax) and IČ DPH (VAT).
 * Everywhere else the DIČ field holds the VAT number, so in English it is a
 * "VAT ID", and in Slovak a foreign one is an "IČ DPH". The field ids stay the
 * same (billing_ic, billing_dic, billing_dic_dph); only what the shopper reads
 * follows the country.
 * https://github.com/vyskoczilova/kybernaut-ic-dic/issues/75
 *
 * @since 2.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * @param string $country Billing country code. Unknown (''): the Slovak set,
 *                        the one with all three fields told apart.
 * @return array{ic:string, dic:string, dic_dph:string}
 */
function woolab_icdic_labels( $country ) {

	// Trimmed: some translations carry a trailing space (the Slovak "IČO ").
	if ( 'SK' === $country || '' === $country ) {
		$dic = trim( __( 'Tax ID', 'woolab-ic-dic' ) );
	} elseif ( 'CZ' === $country ) {
		$dic = _x( 'VAT ID', 'Czech DIČ field', 'woolab-ic-dic' );
	} else {
		$dic = _x( 'VAT ID', 'EU VAT number field', 'woolab-ic-dic' );
	}

	$labels = array(
		'ic'      => trim( __( 'Business ID', 'woolab-ic-dic' ) ),
		'dic'     => $dic,
		'dic_dph' => _x( 'VAT ID', 'Slovak IČ DPH field', 'woolab-ic-dic' ),
	);

	/**
	 * Labels of the company identifier fields for a billing country.
	 *
	 * @since 2.0.0
	 * @param array  $labels  'ic', 'dic' and 'dic_dph'.
	 * @param string $country Billing country code, '' when unknown.
	 */
	return apply_filters( 'woolab_icdic_labels', $labels, $country );
}

/**
 * The countries the Business ID field is shown for, or null for every country
 * (the default, Settings → IČO DIČ → Validation → "Business ID only for …").
 *
 * @return string[]|null
 */
function woolab_icdic_ic_countries() {
	if ( 'yes' !== woolab_icdic_setting( 'ic_countries_only' ) ) {
		return null;
	}

	/**
	 * Countries whose companies have a Business ID (IČO), when the field is limited
	 * to them.
	 *
	 * @since 2.0.0
	 * @param string[] $countries Country codes.
	 */
	return array_values( (array) apply_filters( 'woolab_icdic_ic_countries', array( 'CZ', 'SK' ) ) );
}

/**
 * Whether the Business ID field is shown for a billing country.
 *
 * @param string $country
 * @return bool
 */
function woolab_icdic_ic_shown( $country ) {
	$countries = woolab_icdic_ic_countries();
	return null === $countries || in_array( $country, $countries, true );
}

/**
 * The labels for the classic checkout script, which swaps them when the billing
 * country changes: 'SK', 'CZ', and '' for every other country.
 *
 * @return array<string,array>
 */
function woolab_icdic_labels_by_country() {
	return array(
		'SK' => woolab_icdic_labels( 'SK' ),
		'CZ' => woolab_icdic_labels( 'CZ' ),
		''   => woolab_icdic_labels( 'EU' ),
	);
}

/**
 * A label in front of a value, as in an address: "VAT ID: CZ27082440".
 *
 * @param string $label
 * @return string
 */
function woolab_icdic_label_prefix( $label ) {
	/* translators: %s: field label, e.g. "Business ID" */
	return sprintf( _x( '%s: ', 'label before a value', 'woolab-ic-dic' ), $label );
}
