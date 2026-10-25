<?php
/**
 * What a validation error says, and under which field.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation;

/**
 * The built-in error codes' texts. A check of your own passes its text to
 * Result::add_error(), and its field, so both checkouts show it under that field.
 */
final class Errors {

	/**
	 * The checkout field of an error: billing_company, billing_ic, billing_dic or
	 * billing_dic_dph.
	 *
	 * @param array<string,mixed> $error   A Result entry (field, code) or a 1.x one (code).
	 * @param string              $country Billing country.
	 */
	public static function field( array $error, string $country ): string {
		if ( ! empty( $error['field'] ) ) {
			return 'billing_' . $error['field'];
		}

		$fields = array(
			'missing_company'     => 'billing_company',
			'missing_business_id' => 'billing_ic',
			'invalid_business_id' => 'billing_ic',
			'ares_unexpected'     => 'billing_ic',
			'ares_mismatch'       => 'billing_ic',
			'sk_mismatch'         => 'billing_ic',
			'invalid_dic_cz'      => 'billing_dic',
			'missing_dic'         => 'billing_dic',
			'invalid_tax_id_sk'   => 'billing_dic',
			'dic_ico_mismatch'    => 'billing_dic',
			'sk_unverifiable'     => 'billing_dic',
			'invalid_vat_dph'     => 'billing_dic_dph',
			'dic_dph_mismatch'    => 'billing_dic_dph',
		);

		// The rest is about the VAT number: the IČ DPH in Slovakia.
		return $fields[ $error['code'] ] ?? ( 'SK' === $country ? 'billing_dic_dph' : 'billing_dic' );
	}

	/**
	 * The translated text of an error: its own message, or the built-in code's.
	 *
	 * @param array<string,mixed> $error   A Result entry, or ['code' => string, 'data' => array].
	 * @param string              $country Billing country, for the field labels.
	 */
	public static function message( array $error, string $country = '' ): string {
		if ( ! empty( $error['message'] ) ) {
			return (string) $error['message'];
		}

		$labels    = woolab_icdic_labels( $country );
		$vat_label = 'SK' === $country ? $labels['dic_dph'] : $labels['dic'];

		switch ( $error['code'] ) {
			case 'missing_company':
				// WooCommerce's own required-field notice, so it reads like the core fields' notices.
				/* translators: %s: field name */
				return sprintf( __( '%s is a required field.', 'woocommerce' ), '<strong>' . esc_html( __( 'Company', 'woocommerce' ) ) . '</strong>' );

			case 'missing_business_id':
				return __( 'Enter a valid Business ID', 'woolab-ic-dic' );

			case 'invalid_business_id':
				$message = __( 'Enter a valid Business ID', 'woolab-ic-dic' );
				if ( isset( $error['data']['ares_message'] ) ) {
					$message .= ' ' . $error['data']['ares_message'];
				}
				return $message;

			case 'ares_unexpected':
				return __( 'Unexpected error occurred. Try it again.', 'woolab-ic-dic' );

			case 'ares_mismatch':
			case 'sk_mismatch':
				$names          = array(
					'tax_id'   => $labels['dic'],
					'company'  => __( 'Company', 'woocommerce' ),
					'postcode' => __( 'Postcode / ZIP', 'woocommerce' ),
					'city'     => __( 'Town / City', 'woocommerce' ),
					'address'  => __( 'Address', 'woocommerce' ),
				);
				$missing_fields = array();
				foreach ( $error['data']['fields'] as $field ) {
					$missing_fields[] = $names[ $field ];
				}
				if ( 'sk_mismatch' === $error['code'] ) {
					/* translators: %s: list of field labels, e.g. "Company and Business ID" */
					return sprintf( _n( '%s is not corresponding to Finančná správa.', '%s are not corresponding to Finančná správa.', count( $missing_fields ), 'woolab-ic-dic' ), wc_format_list_of_items( $missing_fields ) );
				}
				/* translators: %s: list of field labels, e.g. "Company and Business ID" */
				return sprintf( _n( '%s is not corresponding to ARES.', '%s are not corresponding to ARES.', count( $missing_fields ), 'woolab-ic-dic' ), wc_format_list_of_items( $missing_fields ) );

			// The VAT checks are on IČ DPH in Slovakia, on the DIČ field elsewhere; the
			// messages name the field as the form labels it (includes/labels.php).
			case 'vat_country_mismatch_billing':
				/* translators: %s: field label, e.g. "VAT ID" */
				return sprintf( _x( 'The billing country does not correspond to the country of the %s.', 'field label', 'woolab-ic-dic' ), $vat_label );

			case 'vat_country_mismatch_shipping':
				/* translators: %s: field label, e.g. "VAT ID" */
				return sprintf( _x( 'The shipping country does not correspond to the country of the %s.', 'field label', 'woolab-ic-dic' ), $vat_label );

			case 'vat_format':
				/* translators: %s: field label, e.g. "VAT ID" */
				return sprintf( _x( '%s has not a correct format.', 'field label', 'woolab-ic-dic' ), $vat_label );

			case 'invalid_vat':
			case 'missing_dic':
			case 'invalid_dic_cz':
			case 'invalid_vat_dph':
				/* translators: %s: field label, e.g. "VAT ID" */
				return sprintf( _x( 'Enter a valid %s.', 'field label', 'woolab-ic-dic' ), $vat_label );

			case 'vat_unverifiable':
				/* translators: %s: field label, e.g. "VAT ID" */
				return sprintf( _x( 'Could not validate %s.', 'field label', 'woolab-ic-dic' ), $vat_label );

			case 'invalid_tax_id_sk':
				/* translators: %s: field label, e.g. "VAT ID" */
				return sprintf( _x( 'Enter a valid %s.', 'field label', 'woolab-ic-dic' ), $labels['dic'] );

			case 'dic_dph_mismatch':
				/* translators: 1: Tax ID label, 2: VAT ID label */
				return sprintf( __( '%1$s or %2$s is not valid.', 'woolab-ic-dic' ), $labels['dic'], $labels['dic_dph'] );

			case 'dic_ico_mismatch':
				return __( 'Tax ID does not belong to the entered Business ID.', 'woolab-ic-dic' );

			case 'sk_unverifiable':
				return __( 'Could not validate Tax ID.', 'woolab-ic-dic' );
		}

		return '';
	}
}
