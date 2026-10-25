<?php
/**
 * The classic (shortcode) checkout: the company fields, their labels, the values
 * kept across a reload, and the validation on placing the order.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Checkout;

use KybernautIcDic\Services\Services;
use KybernautIcDic\Settings\Flags;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Errors;
use KybernautIcDic\Validation\Validator;
use KybernautIcDicDeps\Ibericode\Vat\Countries;

/**
 * The woocommerce_checkout_* and form hooks of the classic checkout.
 */
final class Classic {

	/**
	 * Hook the classic checkout.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_billing_fields', array( self::class, 'billing_fields' ), 10, 2 );
		add_filter( 'woocommerce_default_address_fields', array( self::class, 'default_address_fields' ) );
		add_filter( 'woocommerce_checkout_fields', array( self::class, 'checkout_fields' ) );
		add_action( 'woocommerce_checkout_process', array( self::class, 'checkout_field_process' ), 10, 0 );
		add_filter( 'woocommerce_checkout_posted_data', array( self::class, 'checkout_posted_data' ) );
		add_filter( 'woocommerce_form_field', array( self::class, 'form_field_label' ), 10, 3 );
		add_filter( 'default_checkout_billing_iscomp', array( self::class, 'toggle_iscomp_field' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( self::class, 'remember_checkout_fields' ) );
		add_filter( 'woocommerce_checkout_get_value', array( self::class, 'remembered_checkout_value' ), 10, 2 );
	}

	/**
	 * The three company identifier fields, labelled for a billing country
	 * (Fields\Labels). The marks come with form_field_label().
	 *
	 * @param string $country Billing country code.
	 * @return array<string,array<string,mixed>> WooCommerce form fields, keyed billing_ic, billing_dic, billing_dic_dph.
	 */
	public static function fields( $country ) {
		$labels = woolab_icdic_labels( $country );

		$field = ( fn( $key, $priority ) => array(
			'label'       => $labels[ $key ],
			'placeholder' => $labels[ $key ],
			'required'    => false,
			/**
			 * The CSS classes of a company field: woolab_icdic_class_billing_ic,
			 * woolab_icdic_class_billing_dic, woolab_icdic_class_billing_dic_dph.
			 *
			 * @since 1.2.0
			 * @param string[] $classes Classes.
			 */
			'class'       => apply_filters( 'woolab_icdic_class_billing_' . $key, array( 'form-row-wide', 'woolab-ic-dic-no_spaces' ) ),
			'clear'       => true,
			'priority'    => $priority,
		) );

		return array(
			'billing_ic'      => $field( 'ic', 31 ),
			'billing_dic'     => $field( 'dic', 32 ),
			'billing_dic_dph' => $field( 'dic_dph', 33 ),
		);
	}

	/**
	 * Add the company fields (and the toggle) to the checkout.
	 *
	 * @param array<string,array<string,mixed>> $fields Checkout fields by group.
	 * @return array<string,array<string,mixed>>
	 */
	public static function checkout_fields( $fields ) {

		// Labelled for the country the form opens with; public.js relabels them when
		// the shopper picks another one.
		$country = WC()->customer ? WC()->customer->get_billing_country() : '';

		$fields['billing'] = array_merge( $fields['billing'], self::fields( $country ) );

		// Enable/Disable fields toggle, since 1.5.0.
		if ( Flags::toggle() ) {
			$fields['billing']['billing_iscomp'] = array(
				'type'        => 'checkbox',
				/**
				 * The label classes of the "Buying as a company" checkbox.
				 *
				 * @since 1.5.2
				 * @param string[] $classes Classes.
				 */
				'label_class' => apply_filters( 'woolab_icdic_label_class_billing_iscomp', array( 'woocommerce-form__label', 'woocommerce-form__label-for-checkbox', 'checkbox' ) ),
				'label'       => '<span>' . __( 'Buying as a company', 'woolab-ic-dic' ) . '</span>',
				'required'    => false,
				/**
				 * The input classes of the "Buying as a company" checkbox.
				 *
				 * @since 1.5.2
				 * @param string[] $classes Classes.
				 */
				'input_class' => apply_filters( 'woolab_icdic_class_billing_iscomp', array( 'woocommerce-form__input', 'woocommerce-form__input-checkbox', 'form-row-wide' ) ),
				'clear'       => true,
				'priority'    => 29,
				// WooCommerce refreshes the order review on a change here, which keeps the
				// fields across a reload (remember_checkout_fields()).
				'class'       => array( 'update_totals_on_change' ),
			);
			array_push( $fields['billing']['billing_ic']['class'], 'woolab-ic-dic-toggle' );
			array_push( $fields['billing']['billing_dic']['class'], 'woolab-ic-dic-toggle' );
			array_push( $fields['billing']['billing_dic_dph']['class'], 'woolab-ic-dic-toggle' );
		}

		// WooCommerce leaves the Company field out when its "Company name field" setting is
		// "Hidden", the default once a store has used the Checkout block. The toggle needs
		// it, so it brings WooCommerce's own field back, as the block checkout does
		// (kybernaut/company); without the toggle WooCommerce's setting stands.
		if ( Flags::toggle() ) {
			$fields['billing']['billing_company'] ??= array(
				'label'        => __( 'Company name', 'woocommerce' ),
				'class'        => array( 'form-row-wide' ),
				'autocomplete' => 'organization',
				'priority'     => 30,
			);

			$fields['billing']['billing_company']['class']   = (array) ( $fields['billing']['billing_company']['class'] ?? array() );
			$fields['billing']['billing_company']['class'][] = 'woolab-ic-dic-toggle';
		}

		if ( isset( $fields['billing']['billing_company'] ) ) {
			$fields['billing']['billing_company']['class']   = (array) ( $fields['billing']['billing_company']['class'] ?? array() );
			$fields['billing']['billing_company']['class'][] = 'update_totals_on_change';

			// Why there is no placeholder for Company field by default ?!
			$fields['billing']['billing_company']['placeholder'] = __( 'Company', 'woocommerce' );
		}

		return $fields;
	}

	/**
	 * The company fields of a billing address (My Account → Addresses).
	 *
	 * @param array<string,mixed> $fields  Billing fields.
	 * @param string              $country Billing country.
	 * @return array<string,mixed>
	 */
	public static function billing_fields( $fields, $country ) {

		$ids = self::fields( $country );

		// The VAT registration number is a Slovak field only.
		if ( 'SK' !== $country ) {
			unset( $ids['billing_dic_dph'] );
		}

		if ( ! woolab_icdic_ic_shown( $country ) ) {
			unset( $ids['billing_ic'] );
		}

		return array_merge( $fields, $ids );
	}

	/**
	 * Move Country above the toggle: the country decides which company fields there
	 * are and how they are labelled, so it is picked first.
	 *
	 * Set on the default address fields, not only on the checkout's billing fields:
	 * WooCommerce's address-i18n.js re-sorts the form by the country locale on load
	 * and on every country change, and the locale's default comes from here. With
	 * only the checkout field moved, the script put Country back at 40 — after the
	 * company fields.
	 *
	 * @since 1.5.0
	 * @param array<string,array<string,mixed>> $fields Default address fields.
	 * @return array<string,array<string,mixed>>
	 */
	public static function default_address_fields( $fields ) {
		if ( isset( $fields['country'] ) && Flags::country_ontop() ) {
			$fields['country']['priority'] = 28;
		}

		return $fields;
	}

	/**
	 * Mark the company fields of the checkout form the way the store asks for
	 * (woolab_icdic_field_mark()) instead of WooCommerce's "(optional)": they are not
	 * required fields to WooCommerce, the checkout validation asks for them. public.js
	 * swaps the marks with the labels when the billing country changes.
	 *
	 * @param string              $field Field HTML.
	 * @param string              $key   Field key.
	 * @param array<string,mixed> $args  Field arguments.
	 * @return string
	 */
	public static function form_field_label( $field, $key, $args ) {
		$keys = array(
			'billing_company' => 'company',
			'billing_ic'      => 'ic',
			'billing_dic'     => 'dic',
			'billing_dic_dph' => 'dic_dph',
		);

		// A field WooCommerce requires (Customizer → Company name, a snippet) keeps its marks.
		if ( ! isset( $keys[ $key ] ) || ! is_checkout() || empty( $args['label'] ) || ! empty( $args['required'] ) ) {
			return $field;
		}

		$country = WC()->customer ? WC()->customer->get_billing_country() : '';
		$label   = woolab_icdic_marked_label( wp_strip_all_tags( $args['label'] ), woolab_icdic_field_mark( $keys[ $key ], $country ), true );

		return (string) preg_replace_callback(
			'#(<label for="' . preg_quote( $key, '#' ) . '"[^>]*>).*?(</label>)#s',
			fn( $m ) => $m[1] . $label . $m[2],
			$field,
			1
		);
	}

	/**
	 * Do not store a Business ID for a country the field is hidden for: public.js
	 * empties it, this covers a form posted without the script.
	 *
	 * @param array<string,mixed> $data Posted checkout data.
	 * @return array<string,mixed>
	 */
	public static function checkout_posted_data( $data ) {
		if ( isset( $data['billing_ic'] ) && ! woolab_icdic_ic_shown( $data['billing_country'] ?? '' ) ) {
			$data['billing_ic'] = '';
		}

		return $data;
	}

	/**
	 * Validate the company fields on placing the order.
	 *
	 * Thin adapter: cleans $_POST, resolves the settings and filters into plain flags,
	 * hands them to the Validator, then shows its errors under their fields.
	 */
	public static function checkout_field_process(): void {
		// WooCommerce verified the checkout nonce before woocommerce_checkout_process.
		// phpcs:disable WordPress.Security.NonceVerification.Missing

		// Bail if form not fully filled.
		// NOTE: this early return also skips writing the session flag below — preserved on purpose.
		if ( ! isset( $_POST['billing_country'] ) ) {
			return;
		}

		$country = wc_clean( wp_unslash( $_POST['billing_country'] ) );

		$countries = new Countries();

		$input = array(
			'country'               => $country,
			'ic'                    => isset( $_POST['billing_ic'] ) && woolab_icdic_ic_shown( $country ) ? wc_clean( wp_unslash( $_POST['billing_ic'] ) ) : '',
			'dic'                   => isset( $_POST['billing_dic'] ) ? wc_clean( wp_unslash( $_POST['billing_dic'] ) ) : '',
			'dic_present'           => isset( $_POST['billing_dic'] ),
			'dic_dph'               => isset( $_POST['billing_dic_dph'] ) ? wc_clean( wp_unslash( $_POST['billing_dic_dph'] ) ) : '',
			'company'               => wc_clean( wp_unslash( $_POST['billing_company'] ?? '' ) ),
			'postcode'              => wc_clean( wp_unslash( $_POST['billing_postcode'] ?? '' ) ),
			'city'                  => wc_clean( wp_unslash( $_POST['billing_city'] ?? '' ) ),
			'address_1'             => wc_clean( wp_unslash( $_POST['billing_address_1'] ?? '' ) ),
			'ship_to_different'     => ! empty( $_POST['ship_to_different_address'] ),
			'shipping_country'      => isset( $_POST['shipping_country'] ) ? wc_clean( wp_unslash( $_POST['shipping_country'] ) ) : null,
			'ares_check'            => Flags::ares_check(),
			'ares_fill'             => Flags::ares_fill(),
			'vies_check'            => Flags::vies_check(),
			'sk_check'              => Flags::sk_check(),
			'sk_fill'               => Flags::sk_fill(),
			'autofill'              => Services::autofill( $country ),
			'ignore_check_fail'     => Flags::ignore_check_fail(),
			/**
			 * Match the VAT number's country prefix to the billing and shipping country.
			 *
			 * @since 1.7.4
			 * @param bool $check
			 */
			'check_country_match'   => (bool) apply_filters( 'woolab_icdic_check_billing_country_and_dic', true ),
			'require_dic_for_firms' => woolab_icdic_dic_required( $country ),
			'check_dic_dph_match'   => Flags::dic_dph_match(),
			'require_company'       => 'required' === woolab_icdic_fields_mode(),
			// Only where every company has one, and not where the field is hidden.
			'require_ic'            => 'required' === woolab_icdic_fields_mode() && woolab_icdic_ic_shown( $country ) && in_array( $country, woolab_icdic_ic_home_countries(), true ),
			'toggle_enabled'        => Flags::toggle(),
			// The toggle is a plain checkbox: posted as "1" when ticked, absent otherwise.
			'iscomp'                => ! empty( $_POST['billing_iscomp'] ),
			'country_in_eu'         => $countries->isCountryCodeInEU( $country ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$input['services'] = Services::for_country( $country );
		$result            = Validator::run( new Context( $input ) );

		foreach ( $result->errors() as $error ) {
			// Under its field, as WooCommerce shows its own (checkout.js show_inline_errors()).
			wc_add_notice( Errors::message( $error, $country ), 'error', array( 'id' => Errors::field( $error, $country ) ) );
		}

		// Saved with the order (Order\Meta::save_order_metadata()).
		WC()->session->set( 'woolab_icdic_vat_check_fail_ignored', $result->check_fail_ignored() );
	}

	/**
	 * Keep what the shopper typed into the company fields across a reload of the
	 * classic checkout.
	 *
	 * WooCommerce's order review update stores only the address fields it sends on its
	 * own (country, postcode, city, address); the company name, the IDs and the toggle
	 * reach it only in the form's post data, so a guest lost them on reload. The block
	 * checkout keeps them itself (in the customer session, or the checkout draft).
	 *
	 * @param string $post_data The checkout form, URL-encoded.
	 */
	public static function remember_checkout_fields( $post_data ): void {
		if ( ! WC()->session ) {
			return;
		}

		parse_str( (string) $post_data, $data );

		$fields = array();
		foreach ( array( 'billing_company', 'billing_ic', 'billing_dic', 'billing_dic_dph' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$fields[ $key ] = wc_clean( $data[ $key ] );
			}
		}

		// An unticked checkbox is not posted.
		if ( Flags::toggle() ) {
			$fields['billing_iscomp'] = empty( $data['billing_iscomp'] ) ? '' : '1';
		}

		WC()->session->set( 'woolab_icdic_checkout_fields', $fields );
	}

	/**
	 * The value remember_checkout_fields() kept, before the customer's stored one:
	 * it is what the shopper typed last.
	 *
	 * @param mixed  $value Null, or the value another filter decided.
	 * @param string $input Field key.
	 * @return mixed
	 */
	public static function remembered_checkout_value( $value, $input ) {
		if ( null !== $value || ! WC()->session ) {
			return $value;
		}

		$fields = WC()->session->get( 'woolab_icdic_checkout_fields' );

		return is_array( $fields ) && isset( $fields[ $input ] ) ? $fields[ $input ] : $value;
	}

	/**
	 * Tick "Buying as a company" for a customer who already has company data.
	 *
	 * @param mixed  $value Default value.
	 * @param string $input Field key.
	 * @return mixed
	 */
	public static function toggle_iscomp_field( $value, $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the filter's arguments.
		$customer = WC()->customer;

		if ( empty( $customer ) ) {
			return $value;
		}

		// The company is a core customer property: get_meta() does not see it.
		if ( ! empty( $customer->get_billing_company() )
			|| ! empty( $customer->get_meta( 'billing_ic' ) )
			|| ! empty( $customer->get_meta( 'billing_dic' ) )
			|| ! empty( $customer->get_meta( 'billing_dic_dph' ) )
			) {
			return true;
		}

		return $value;
	}
}
