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
 * How the company fields are marked (Settings → IČO DIČ → General):
 * - 'optional': WooCommerce's "(optional)", the default;
 * - 'plain':    no mark, like any other field;
 * - 'asterisk': marked required, not enforced (the 1.x woolab_icdic_fake_required filter);
 * - 'required': marked, and a company cannot place the order without them.
 *
 * @return string
 */
function woolab_icdic_fields_mode() {
	$mode = woolab_icdic_setting( 'company_fields' );

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
function woolab_icdic_base_country() {
	/**
	 * The shop's country for the VAT exemption and the VAT ID's mark.
	 *
	 * @since 1.0.0
	 * @param string $country WooCommerce's base country.
	 */
	return (string) apply_filters( 'woolab_icdic_base_country', WC()->countries->get_base_country() );
}

/**
 * What follows a label in the checkout form: 'optional', 'required' (an asterisk),
 * 'vat_payer' ("(optional, only if you are a VAT payer)") or '' (nothing).
 *
 * The company name, the Business ID where every company has one and, when the
 * store requires it, the Slovak Tax ID follow woolab_icdic_fields_mode(). The rest
 * is always optional, marked only in the "optional" mode: the Business ID
 * elsewhere, the Slovak Tax ID otherwise (non-profit organisations have an IČO
 * but no DIČ), and the VAT ID in the shop's own country, where it exempts nothing. Abroad only a VAT payer's number exempts the
 * order, so there the VAT ID says who enters it.
 *
 * @param string $key     'company', 'ic', 'dic' or 'dic_dph'.
 * @param string $country Billing country code, '' when unknown (the Slovak set).
 * @return string
 */
function woolab_icdic_field_mark( $key, $country ) {
	$mode = woolab_icdic_fields_mode();
	$sk   = 'SK' === $country || '' === $country;

	if ( ( $sk ? 'dic_dph' : 'dic' ) === $key ) {
		if ( ( $sk ? 'SK' : $country ) !== woolab_icdic_base_country() ) {
			return 'vat_payer';
		}

		return 'optional' === $mode ? 'optional' : '';
	}

	if ( ( 'ic' === $key && ! $sk && ! in_array( $country, woolab_icdic_ic_home_countries(), true ) ) || ( 'dic' === $key && $sk && ! woolab_icdic_sk_dic_required() ) ) {
		$mode = 'optional' === $mode ? 'optional' : 'plain';
	}

	if ( 'optional' === $mode ) {
		return 'optional';
	}

	return 'plain' === $mode ? '' : 'required';
}

/**
 * A label with its mark (woolab_icdic_field_mark()): text for the block checkout,
 * or HTML in WooCommerce's own markup for the classic one.
 *
 * @param string $label Field label.
 * @param string $mark  woolab_icdic_field_mark().
 * @param bool   $html  HTML instead of text.
 * @return string
 */
function woolab_icdic_marked_label( $label, $mark, $html = false ) {
	if ( 'optional' === $mark ) {
		/* translators: %s Field label. */
		return $html ? esc_html( $label ) . '&nbsp;<span class="optional">(' . esc_html__( 'optional', 'woocommerce' ) . ')</span>' : sprintf( __( '%s (optional)', 'woocommerce' ), $label );
	}

	if ( 'vat_payer' === $mark ) {
		$hint = __( 'optional, only if you are a VAT payer', 'woolab-ic-dic' );
		return $html ? esc_html( $label ) . '&nbsp;<span class="optional">(' . esc_html( $hint ) . ')</span>' : $label . ' (' . $hint . ')';
	}

	// The block checkout marks nothing it requires, so neither does this.
	if ( 'required' === $mark && $html ) {
		return esc_html( $label ) . '&nbsp;<span class="required" aria-hidden="true">*</span>';
	}

	return $html ? esc_html( $label ) : $label;
}

/**
 * The labels as the checkout form shows them, marked (woolab_icdic_field_mark()).
 *
 * @param string $country Billing country code.
 * @param bool   $html    woolab_icdic_marked_label().
 * @return array{ic:string, dic:string, dic_dph:string}
 */
function woolab_icdic_form_labels( $country, $html = false ) {
	$labels = woolab_icdic_labels( $country );

	foreach ( $labels as $key => $label ) {
		$labels[ $key ] = woolab_icdic_marked_label( $label, woolab_icdic_field_mark( $key, $country ), $html );
	}

	return $labels;
}

/**
 * Whether a Slovak company needs a DIČ (Settings → IČO DIČ → Validation): with an
 * IČO, and in the "Required" mode. Off by default: non-profit organisations have
 * an IČO but no DIČ.
 *
 * @return bool
 */
function woolab_icdic_sk_dic_required() {
	/**
	 * Require the Slovak DIČ.
	 *
	 * @since 1.4.0
	 * @param bool $required The setting.
	 */
	return (bool) apply_filters( 'woolab_icdic_sk_required_ic_and_dic', 'yes' === woolab_icdic_setting( 'sk_dic_required' ) );
}

/**
 * The countries the Business ID field is shown for, or null for every country
 * (the default, Settings → IČO DIČ → Validation → "Business ID only for …").
 *
 * @return string[]|null
 */
function woolab_icdic_ic_countries() {
	return 'yes' === woolab_icdic_setting( 'ic_countries_only' ) ? woolab_icdic_ic_home_countries() : null;
}

/**
 * The countries where every company has a Business ID: the field can be limited
 * to them, and only there the "Required" mode asks for it.
 *
 * @return string[]
 */
function woolab_icdic_ic_home_countries() {
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
 * @param string $country
 * @return bool
 */
function woolab_icdic_ic_shown( $country ) {
	$countries = woolab_icdic_ic_countries();
	return null === $countries || in_array( $country, $countries, true );
}

/**
 * The labels for the classic checkout script, which swaps them when the billing
 * country changes: 'SK', 'CZ', the shop's country, and '' for every other one.
 *
 * @param bool $form Form labels as HTML (woolab_icdic_form_labels()), or the bare
 *                   ones the placeholders use.
 * @return array<string,array>
 */
function woolab_icdic_labels_by_country( $form = true ) {
	$labels = array();

	// The shop's own country is marked apart from the rest (woolab_icdic_field_mark()).
	$countries = array(
		'SK'                        => 'SK',
		'CZ'                        => 'CZ',
		woolab_icdic_base_country() => woolab_icdic_base_country(),
	);

	// And the Business ID's countries (woolab_icdic_field_mark()).
	foreach ( woolab_icdic_ic_home_countries() as $country ) {
		$countries[ $country ] = $country;
	}
	$countries[''] = 'EU';

	foreach ( $countries as $key => $country ) {
		$labels[ $key ] = $form ? woolab_icdic_form_labels( $country, true ) : woolab_icdic_labels( $country );
	}

	return $labels;
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
