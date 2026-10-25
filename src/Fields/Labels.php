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
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Fields;

/**
 * The labels, their marks ("(optional)", "only if you are a VAT payer", the
 * asterisk) and the sets the checkout scripts swap.
 */
final class Labels {

	/**
	 * The labels of the company identifier fields for a billing country.
	 *
	 * @param string $country Billing country code. Unknown (''): the Slovak set,
	 *                        the one with all three fields told apart.
	 * @return array{ic:string, dic:string, dic_dph:string}
	 */
	public static function labels( $country ) {

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
	 * What follows a label in the checkout form: 'optional', 'required' (an asterisk),
	 * 'vat_payer' ("(optional, only if you are a VAT payer)") or '' (nothing).
	 *
	 * The company name, the Business ID where every company has one and, when the
	 * store requires it (Countries::dic_required()), the DIČ of the shop's own
	 * country follow Countries::fields_mode(). The rest is always optional, marked
	 * only in the "optional" mode: the Business ID elsewhere, that DIČ otherwise
	 * (non-profit organisations have an IČO but no DIČ), and the IČ DPH of a Slovak
	 * shop. Abroad only a VAT payer's number exempts the order, so there the VAT ID
	 * says who enters it.
	 *
	 * @param string $key     'company', 'ic', 'dic' or 'dic_dph'.
	 * @param string $country Billing country code, '' when unknown (the Slovak set).
	 * @return string
	 */
	public static function field_mark( $key, $country ) {
		$mode = Countries::fields_mode();
		$sk   = 'SK' === $country || '' === $country;
		$code = $sk ? 'SK' : $country;

		if ( ( $sk ? 'dic_dph' : 'dic' ) === $key && Countries::base_country() !== $code ) {
			return 'vat_payer';
		}

		$optional = ( 'ic' === $key && ! $sk && ! in_array( $country, Countries::ic_home_countries(), true ) )
			|| ( 'dic' === $key && ! Countries::dic_required( $code ) )
			|| 'dic_dph' === $key;

		if ( $optional ) {
			$mode = 'optional' === $mode ? 'optional' : 'plain';
		}

		if ( 'optional' === $mode ) {
			return 'optional';
		}

		return 'plain' === $mode ? '' : 'required';
	}

	/**
	 * A label with its mark (field_mark()): text for the block checkout, or HTML in
	 * WooCommerce's own markup for the classic one.
	 *
	 * @param string $label Field label.
	 * @param string $mark  field_mark().
	 * @param bool   $html  HTML instead of text.
	 * @return string
	 */
	public static function marked_label( $label, $mark, $html = false ) {
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
	 * The labels as the checkout form shows them, marked (field_mark()).
	 *
	 * @param string $country Billing country code.
	 * @param bool   $html    marked_label().
	 * @return array{ic:string, dic:string, dic_dph:string}
	 */
	public static function form_labels( $country, $html = false ) {
		$labels = self::labels( $country );

		foreach ( $labels as $key => $label ) {
			$labels[ $key ] = self::marked_label( $label, self::field_mark( $key, $country ), $html );
		}

		return $labels;
	}

	/**
	 * The labels for the classic checkout script, which swaps them when the billing
	 * country changes: 'SK', 'CZ', the shop's country, and '' for every other one.
	 *
	 * @param bool $form Form labels as HTML (form_labels()), or the bare ones the
	 *                   placeholders use.
	 * @return array<string,array<string,string>>
	 */
	public static function labels_by_country( $form = true ) {
		$labels = array();

		// The shop's own country is marked apart from the rest (field_mark()).
		$countries = array(
			'SK'                      => 'SK',
			'CZ'                      => 'CZ',
			Countries::base_country() => Countries::base_country(),
		);

		// And the Business ID's countries (field_mark()).
		foreach ( Countries::ic_home_countries() as $country ) {
			$countries[ $country ] = $country;
		}
		$countries[''] = 'EU';

		foreach ( $countries as $key => $country ) {
			$labels[ $key ] = $form ? self::form_labels( $country, true ) : self::labels( $country );
		}

		return $labels;
	}

	/**
	 * A label in front of a value, as in an address: "VAT ID: CZ27082440".
	 *
	 * @param string $label Field label.
	 * @return string
	 */
	public static function label_prefix( $label ) {
		/* translators: %s: field label, e.g. "Business ID" */
		return sprintf( _x( '%s: ', 'label before a value', 'woolab-ic-dic' ), $label );
	}
}
