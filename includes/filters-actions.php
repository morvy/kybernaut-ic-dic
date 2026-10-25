<?php

// If this file is called directly, abort.

use KybernautIcDicDeps\Ibericode\Vat\Countries;
use KybernautIcDicDeps\Ibericode\Vat\Validator;

if ( ! defined( 'WPINC' ) ) {
	die;
}

// add plugin links
function woolab_icdic_plugin_row_meta( $links, $file ) {

	if ( WOOLAB_IC_DIC_PLUGIN_BASENAME == $file ) {

		$row_meta = array(
			'github' => '<a href="https://github.com/vyskoczilova/kybernaut-ic-dic" target="_blank" aria-label="' . esc_attr__( 'View GitHub', 'woolab-ic-dic' ) . '">' . esc_html__( 'GitHub', 'woolab-ic-dic' ) . '</a>',
			'review' => '<a href="http://wordpress.org/support/view/plugin-reviews/woolab-ic-dic" target="_blank" aria-label="' . esc_attr__( 'Write a Review', 'woolab-ic-dic' ) . '">' . esc_html__( 'Write a Review', 'woolab-ic-dic' ) . '</a>',
		);

		return array_merge( $links, $row_meta );
	}

	return (array) $links;
}

/**
 * The three company identifier fields, labelled for a billing country
 * (includes/labels.php). The marks come with woolab_icdic_form_field_label().
 *
 * @param string $country Billing country code.
 * @return array<string,array> WooCommerce form fields, keyed billing_ic, billing_dic, billing_dic_dph.
 */
function woolab_icdic_fields( $country ) {
	$labels = woolab_icdic_labels( $country );

	$field = function ( $key, $priority ) use ( $labels ) {
		return array(
			'label'       => $labels[ $key ],
			'placeholder' => $labels[ $key ],
			'required'    => false,
			'class'       => apply_filters( 'woolab_icdic_class_billing_' . $key, array( 'form-row-wide', 'woolab-ic-dic-no_spaces' ) ),
			'clear'       => true,
			'priority'    => $priority,
		);
	};

	return array(
		'billing_ic'      => $field( 'ic', 31 ),
		'billing_dic'     => $field( 'dic', 32 ),
		'billing_dic_dph' => $field( 'dic_dph', 33 ),
	);
}

// add checkout fields
function woolab_icdic_checkout_fields( $fields ) {

	// Labelled for the country the form opens with; public.js relabels them when
	// the shopper picks another one.
	$country = WC()->customer ? WC()->customer->get_billing_country() : '';

	$fields['billing'] = array_merge( $fields['billing'], woolab_icdic_fields( $country ) );

	/**
	 * Enable/Disable fields toggle
	 * @since 1.5.0
	 */
	if( woolab_icdic_toggle_enabled() ) {
		$fields['billing']['billing_iscomp'] = array(
			'type'        => 'checkbox',
			'label_class' => apply_filters( 'woolab_icdic_label_class_billing_iscomp', array('woocommerce-form__label', 'woocommerce-form__label-for-checkbox', 'checkbox') ),
			'label'       => '<span>' . __('Buying as a company', 'woolab-ic-dic') . '</span>',
			'required'    => false,
			'input_class' => apply_filters( 'woolab_icdic_class_billing_iscomp', array('woocommerce-form__input', 'woocommerce-form__input-checkbox', 'form-row-wide') ),
			'clear'       => true,
			'priority'    => 29,
			// WooCommerce refreshes the order review on a change here, which keeps the
			// fields across a reload (woolab_icdic_remember_checkout_fields()).
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
	if ( woolab_icdic_toggle_enabled() ) {
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

function woolab_icdic_billing_fields( $fields, $country ) {

	$ids = woolab_icdic_fields( $country );

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
 * @param array $fields Default address fields.
 * @return array
 */
function woolab_icdic_default_address_fields( $fields ) {
	if ( isset( $fields['country'] ) && woolab_icdic_country_ontop_enabled() ) {
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
function woolab_icdic_form_field_label( $field, $key, $args ) {
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
		function ( $m ) use ( $label ) {
			return $m[1] . $label . $m[2];
		},
		$field,
		1
	);
}

/**
 * Do not store a Business ID for a country the field is hidden for: public.js
 * empties it, this covers a form posted without the script.
 *
 * @param array $data Posted checkout data.
 * @return array
 */
function woolab_icdic_checkout_posted_data( $data ) {
	if ( isset( $data['billing_ic'] ) && ! woolab_icdic_ic_shown( isset( $data['billing_country'] ) ? $data['billing_country'] : '' ) ) {
		$data['billing_ic'] = '';
	}

	return $data;
}

/**
 * Build the VIES verification closure shared by the checkout-validation and
 * VAT-exemption hooks. Keeps the format check (ibericode Validator) and VIES
 * (includes/vies.php) behind a seam and collapses validation into the four-state
 * string the pure modules consume
 * ('valid'|'bad_format'|'invalid'|'unverifiable').
 *
 * $log_message is the sprintf format logged (with the VAT number) when VIES is
 * unreachable. It differs per call site — the checkout path and the exemption
 * path used distinct wording — so it stays parameterized to preserve the
 * original log output verbatim.
 *
 * @param string $log_message sprintf format with a single %s for the VAT number.
 * @return callable fn(string $vat): string
 */
function woolab_icdic_make_vies_verifier( $log_message ) {
	return function ( $vat ) use ( $log_message ) {
		$validator = new Validator();

		if ( ! $validator->validateVatNumberFormat( $vat ) ) {
			return 'bad_format';
		}

		// Slovak numbers: the Finančná správa list first (includes/financna-sprava.php).
		// VIES only confirms what the list does not have yet, and only when enabled.
		if ( woolab_icdic_sk_check() && 'SK' === woolab_icdic_get_vat_number_country_code( $vat ) ) {
			$row = woolab_icdic_sk_find( 'dph', $vat );
			if ( $row ) {
				return 'valid';
			}
			if ( ! woolab_icdic_vies_check() ) {
				return false === $row ? 'unverifiable' : 'invalid';
			}
		}

		// A definitive VIES result (valid/invalid) is stable, so cache it. This
		// matters most for woolab_icdic_set_vat_exempt_for_customer(), which runs
		// on every cart and checkout page load and would otherwise issue a fresh
		// synchronous VIES request each time. Outages ('unverifiable') are never
		// cached so the next request can retry.
		$cache_key = 'woolab_icdic_vies_' . md5( $vat );
		$cached    = get_transient( $cache_key );
		if ( 'valid' === $cached || 'invalid' === $cached ) {
			return $cached;
		}

		$state = woolab_icdic_vies_rest( $vat, $log_message );

		if ( 'unverifiable' !== $state ) {
			$ttl = apply_filters( 'woolab_icdic_vies_cache_ttl', DAY_IN_SECONDS, $vat, $state );
			if ( $ttl > 0 ) {
				set_transient( $cache_key, $state, $ttl );
			}
		}

		return $state;
	};
}

// check field on checkout
//
// Thin adapter: cleans $_POST, resolves all settings/filters/options into plain
// flags, builds the two network closures (ARES + VIES), delegates the decision
// to the pure orchestrator woolab_icdic_validate_checkout() (includes/validation.php),
// then maps the returned structured error codes back to the exact original notices.
function woolab_icdic_checkout_field_process() {

	// Bail if form not fully filled.
	// NOTE: this early return also skips writing the session flag below — preserved on purpose.
	if (!isset($_POST['billing_country'])) {
		return false;
	}

	$country = wc_clean( wp_unslash( $_POST['billing_country'] ) );

	$verify_vat = woolab_icdic_make_vies_verifier( 'Could not validate VAT number: %s, returned the following exception:' );

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
		'ares_check'            => (bool) woolab_icdic_ares_check(),
		'ares_fill'             => (bool) woolab_icdic_ares_fill(),
		'vies_check'            => (bool) woolab_icdic_vies_check(),
		'sk_check'              => (bool) woolab_icdic_sk_check(),
		'sk_fill'               => (bool) woolab_icdic_sk_fill(),
		'ignore_check_fail'     => (bool) woolab_icdic_ignore_check_fail(),
		/**
		 * Match the VAT number's country prefix to the billing and shipping country.
		 *
		 * @since 1.4.0
		 * @param bool $check
		 */
		'check_country_match'   => (bool) apply_filters( 'woolab_icdic_check_billing_country_and_dic', true ),
		'require_dic_for_firms' => woolab_icdic_dic_required( $country ),
		'check_dic_dph_match'   => (bool) woolab_icdic_dic_dicdph_match_enabled(),
		'require_company'       => 'required' === woolab_icdic_fields_mode(),
		// Only where every company has one, and not where the field is hidden.
		'require_ic'            => 'required' === woolab_icdic_fields_mode() && woolab_icdic_ic_shown( $country ) && in_array( $country, woolab_icdic_ic_home_countries(), true ),
		'toggle_enabled'        => (bool) woolab_icdic_toggle_enabled(),
		// The toggle is a plain checkbox: posted as "1" when ticked, absent otherwise.
		'iscomp'                => ! empty( $_POST['billing_iscomp'] ),
		'country_in_eu'         => $countries->isCountryCodeInEU( $country ),
		'verify_vat'            => $verify_vat,
		// Raw woolab_icdic_ares() result (or falsy) — passed as a callable directly.
		'lookup_ares'           => 'woolab_icdic_ares',
		'lookup_sk_ico'         => 'woolab_icdic_sk_lookup',
		'lookup_sk'             => 'woolab_icdic_sk_find',
	);

	$result = \KybernautIcDic\Validation\Validator::run( new \KybernautIcDic\Validation\Context( $input ) );

	foreach ( $result->errors() as $error ) {
		// Under its field, as WooCommerce shows its own (checkout.js show_inline_errors()).
		wc_add_notice( \KybernautIcDic\Validation\Errors::message( $error, $country ), 'error', array( 'id' => \KybernautIcDic\Validation\Errors::field( $error, $country ) ) );
	}

	// Saved with the order (woolab_icdic_save_order_metadata()).
	WC()->session->set( 'woolab_icdic_vat_check_fail_ignored', $result->check_fail_ignored() );

}

// My address formatted
function woolab_icdic_my_address_formatted_address( $fields, $customer_id, $name ) {
	$fields += array(
		'billing_ic'  => get_user_meta( $customer_id, $name . '_ic', true ),
		'billing_dic' => get_user_meta( $customer_id, $name . '_dic', true )
	);
	// $single must be true: with '' this returned an array, never 'SK'.
	if ( 'SK' === get_user_meta( $customer_id, $name . '_country', true ) ) {
		$fields += array(
			'billing_dic_dph' => get_user_meta( $customer_id, $name . '_dic_dph', true )
		);
	}

	return $fields;
}

/**
 * Add ICO, DIC fields to customers address for all EU countries including Monaco.
 * @param array $address_formats Address formats array.
 * @return array 
 * 
 * @updated 1.7.3
 */
function woolab_icdic_localisation_address_formats($address_formats) {

	$euvat = WC()->countries->get_european_union_countries( 'eu_vat' ); // Eu VAT Countries including Monaco.

	foreach ( $euvat as $country_code ) {
		if (isset($address_formats[$country_code])) {
			$address_formats[$country_code] .= "\n{billing_ic}\n{billing_dic}";
			if ( $country_code == 'SK' ) {
				$address_formats[$country_code] .= "\n{billing_dic_dph}";
			}
		}
	}
	return $address_formats;
}

// Formatting
function woolab_icdic_formatted_address_replacements( $replace, $args ) {

	$labels  = woolab_icdic_labels( isset( $args['country'] ) ? $args['country'] : '' );
	$replace = (array) $replace;

	foreach ( array( 'ic' => 'billing_ic', 'dic' => 'billing_dic', 'dic_dph' => 'billing_dic_dph' ) as $key => $token ) {
		foreach ( array( '' => $token, '_upper' => $token . '_upper' ) as $suffix => $arg ) {
			$line = isset( $args[ $arg ] ) && '' != $args[ $arg ] ? woolab_icdic_label_prefix( $labels[ $key ] ) . esc_html( $args[ $arg ] ) : '';
			$replace += array( '{' . $token . $suffix . '}' => $suffix ? strtoupper( $line ) : $line );
		}
	}

	return $replace;
}

function woolab_icdic_order_formatted_billing_address($address, $order) {

	// The front-end order details print the identifiers as their own block
	// (woolab_icdic_order_details_ids()), so they are not address lines there.
	if ( woolab_icdic_in_order_details() ) {
		return $address;
	}

	$address += array(
		'billing_ic'      => $order->get_meta('_billing_ic'),
		'billing_dic'     => $order->get_meta('_billing_dic'),
		'billing_dic_dph' => $order->get_meta('_billing_dic_dph'),
	);

	return $address;

}

/**
 * Whether the front-end customer details (thank-you page, My Account → order) are
 * being rendered: core's `order/order-details-customer.php` template. Block themes
 * render the order confirmation with blocks instead (the identifiers then stay
 * lines of the billing address), and fire `woocommerce_after_order_details` from
 * the totals block without that template following, so the section is bounded by
 * the template itself.
 *
 * @param bool|null $set Pass true/false to enter/leave the section.
 * @return bool
 */
function woolab_icdic_in_order_details( $set = null ) {
	static $inside = false;
	if ( null !== $set ) {
		$inside = (bool) $set;
	}
	return $inside;
}

/**
 * Enter the customer details section.
 *
 * @param string $template_name Template being rendered.
 */
function woolab_icdic_order_details_enter( $template_name ) {
	if ( 'order/order-details-customer.php' === $template_name ) {
		woolab_icdic_in_order_details( true );
	}
}

/**
 * Leave the customer details section.
 *
 * @param string $template_name Template rendered.
 */
function woolab_icdic_order_details_leave( $template_name ) {
	if ( 'order/order-details-customer.php' === $template_name ) {
		woolab_icdic_in_order_details( false );
	}
}

/**
 * Company identifiers under the billing address in the front-end order details.
 *
 * Elsewhere (e-mails, the admin order screen, PDF invoices) they stay address lines:
 * the address-format replacements are escaped by WooCommerce and printed as plain text
 * or with only <br> allowed, so markup cannot travel inside the address. Here they are
 * a block of their own a theme can style: `p.woolab-ic-dic-customer-details`.
 *
 * @param string   $address_type billing or shipping.
 * @param WC_Order $order        Order.
 */
function woolab_icdic_order_details_ids( $address_type, $order ) {
	if ( 'billing' !== $address_type || ! $order instanceof WC_Order ) {
		return;
	}

	$labels = woolab_icdic_labels( $order->get_billing_country() );
	$lines  = array();
	foreach (
		array(
			'ic'      => array( woolab_icdic_label_prefix( $labels['ic'] ), $order->get_meta( '_billing_ic' ) ),
			'dic'     => array( woolab_icdic_label_prefix( $labels['dic'] ), $order->get_meta( '_billing_dic' ) ),
			'dic-dph' => array( woolab_icdic_label_prefix( $labels['dic_dph'] ), $order->get_meta( '_billing_dic_dph' ) ),
		) as $key => $row
	) {
		if ( '' !== (string) $row[1] ) {
			$lines[] = '<span class="woolab-ic-dic-customer-details__' . esc_attr( $key ) . '">' . esc_html( $row[0] . $row[1] ) . '</span>';
		}
	}

	if ( $lines ) {
		echo '<p class="woolab-ic-dic-customer-details">' . implode( '<br>', $lines ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	}
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
 * @return void
 */
function woolab_icdic_remember_checkout_fields( $post_data ) {
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
	if ( woolab_icdic_toggle_enabled() ) {
		$fields['billing_iscomp'] = empty( $data['billing_iscomp'] ) ? '' : '1';
	}

	WC()->session->set( 'woolab_icdic_checkout_fields', $fields );
}

/**
 * The value woolab_icdic_remember_checkout_fields() kept, before the customer's
 * stored one: it is what the shopper typed last.
 *
 * @param mixed  $value Null, or the value another filter decided.
 * @param string $input Field key.
 * @return mixed
 */
function woolab_icdic_remembered_checkout_value( $value, $input ) {
	if ( null !== $value || ! WC()->session ) {
		return $value;
	}

	$fields = WC()->session->get( 'woolab_icdic_checkout_fields' );

	return is_array( $fields ) && isset( $fields[ $input ] ) ? $fields[ $input ] : $value;
}

function woolab_icdic_toggle_iscomp_field($value, $input) {
	$customer = WC()->customer;

	if ( empty($customer) ) {
		return $value;
	}

	// The company is a core customer property: get_meta() does not see it.
	if ( !empty($customer->get_billing_company())
		|| !empty($customer->get_meta('billing_ic'))
		|| !empty($customer->get_meta('billing_dic'))
		|| !empty($customer->get_meta('billing_dic_dph'))
		) {
		return true;
	}

	return $value;
}

/**
 * Resolve whether a VAT number grants exemption, via the shared VIES seam.
 *
 * Wraps the verifier (which swallows VIES outages and returns 'unverifiable' —
 * it must never re-throw, since an uncaught exception on init / checkout AJAX
 * would fatal the whole request when VIES is down) and the pure exemption
 * decision. Shared by both VAT-exempt hooks.
 *
 * @param string $vat_num
 * @param bool   $ignore_check_fail
 * @return bool
 */
/**
 * Whether a tax number is a real one VIES does not list: every Czech, Polish and
 * Hungarian business has one (woolab_icdic_tax_number_valid()), only VAT payers
 * are in VIES. Such a company orders with VAT (woolab_icdic_validate_checkout()).
 *
 * @param string $country Billing country.
 * @param string $vat     The VAT number woolab_icdic_select_vat_number() picked.
 * @return bool
 */
function woolab_icdic_vat_not_registered( $country, $vat ) {
	$vat = preg_replace( '/\s+/', '', (string) $vat );

	// The VIES answer is cached (woolab_icdic_make_vies_verifier()), the exemption just asked.
	return substr( $vat, 0, 2 ) === $country && woolab_icdic_vies_check() && woolab_icdic_tax_number_valid( $vat )
		&& 'invalid' === call_user_func( woolab_icdic_make_vies_verifier( 'Could not validate VAT number: %s' ), $vat );
}

/**
 * What the checkout tells a company about the VAT on its order: reverse charge
 * when the order is VAT exempt, why it is not when its tax number is entered
 * without the country code (woolab_icdic_domestic_tax_number()) or is no VAT
 * registration (woolab_icdic_vat_not_registered()), nothing otherwise.
 *
 * @param string $country Billing country.
 * @param string $vat     The VAT number entered.
 * @param bool   $exempt  Whether the order is VAT exempt.
 * @return string|null
 */
function woolab_icdic_vat_notice( $country, $vat, $exempt ) {
	if ( $exempt ) {
		return __( 'Reverse charge: the order is without VAT, the buyer accounts for it.', 'woolab-ic-dic' );
	}

	if ( $vat && woolab_icdic_domestic_tax_number( $country, preg_replace( '/\s+/', '', (string) $vat ) ) ) {
		/* translators: %s: country code, e.g. "PL" */
		return sprintf( __( 'This tax number is valid. Without the country code (%s) it is not an EU VAT ID, so the order is placed with VAT.', 'woolab-ic-dic' ), $country );
	}

	if ( $vat && woolab_icdic_vat_not_registered( $country, $vat ) ) {
		/* translators: %s: field label, e.g. "VAT ID" */
		return sprintf( __( 'This %s is not registered for VAT in the EU (VIES), so the order is placed with VAT.', 'woolab-ic-dic' ), woolab_icdic_labels( $country )['dic'] );
	}

	return null;
}

/**
 * Whether a VAT number exempts the order: VIES says valid, or cannot answer and
 * the store lets that pass.
 *
 * @param string $vat_num
 * @param bool   $ignore_check_fail
 * @return bool
 */
function woolab_icdic_vat_number_exempt( $vat_num, $ignore_check_fail ) {
	$verify_vat = woolab_icdic_make_vies_verifier( 'Could not validate if VAT number is exempt: %s, returned the following exception:' );
	return woolab_icdic_vat_exempt_from_state( $verify_vat( $vat_num ), $ignore_check_fail );
}

/**
 * Set the VAT exemption from the customer's saved details when the cart or the
 * checkout page loads, so the totals are right before anything is typed.
 *
 * On `wp`, which neither admin-ajax nor the Store API reach: the checkouts set it
 * themselves while the shopper types (woolab_icdic_validate_vat_exempt_for_company(),
 * the Store API hooks in includes/blocks.php), and every other page has no totals to
 * show — it used to run on `init` for every request, with a VIES call each time
 * the result was not cached.
 */
/**
 * Set the customer's VAT exemption and remember that this plugin set it.
 *
 * @param bool $exempt
 */
function woolab_icdic_vat_exempt_set( $exempt ) {
	WC()->customer->set_is_vat_exempt( $exempt );
	if ( WC()->session ) {
		WC()->session->set( 'woolab_icdic_vat_exempt', $exempt ? 'yes' : null );
	}
}

/**
 * Lift an exemption this plugin set, once its rule no longer applies (the
 * shopper switched to the store's own or a non-EU country). One another plugin
 * set, e.g. for exports, is left alone.
 */
function woolab_icdic_vat_exempt_reset() {
	if ( WC()->session && 'yes' === WC()->session->get( 'woolab_icdic_vat_exempt' ) ) {
		woolab_icdic_vat_exempt_set( false );
	}
}

function woolab_icdic_set_vat_exempt_for_customer() {
	if ( ! is_cart() && ! is_checkout() ) {
		return;
	}

	$customer = WC()->customer;

	if ( empty( $customer ) || ! woolab_icdic_vat_exempt_enabled() ) {
		return;
	}

	$vat_num               = null;
	$wc_countries          = new WC_Countries();
	$base_country          = $wc_countries->get_base_country();
	$base_country          = apply_filters( 'woolab_icdic_base_country', $base_country );
	$ignore_vat_check_fail = woolab_icdic_ignore_check_fail();
	$is_vat_exempt         = false;
	// A core customer property: get_meta() returned '' for it, so this never ran.
	$billing_country       = $customer->get_billing_country();

	if (!empty($billing_country) && $billing_country !== $base_country) {
		$vat_num = woolab_icdic_select_vat_number( $billing_country, $customer->get_meta('billing_dic'), $customer->get_meta('billing_dic_dph') );
	}

	if (!empty($vat_num)) {
		$is_vat_exempt = woolab_icdic_vat_number_exempt( $vat_num, $ignore_vat_check_fail );
	}

	$is_vat_exempt = apply_filters( 'woolab_icdic_vat_exempt_customer', $is_vat_exempt, $vat_num, $customer );

	woolab_icdic_vat_exempt_set( $is_vat_exempt );
}

function woolab_icdic_validate_vat_exempt_for_company( $post_data ) {
	if ( ! woolab_icdic_vat_exempt_enabled() ) {
		return;
	}

	$data          = array();
	wp_parse_str($post_data, $data);
	$wc_countries          = new WC_Countries();
	$base_country          = $wc_countries->get_base_country();
	$base_country          = apply_filters( 'woolab_icdic_base_country', $base_country );
	$country               = isset( $data['billing_country'] ) ? $data['billing_country'] : '';
	$vat_countries         = $wc_countries->get_european_union_countries('eu_vat');
	$is_eu_country         = in_array($country, $vat_countries);
	$ignore_vat_check_fail = woolab_icdic_ignore_check_fail();

	if ( empty( $country ) || $country === $base_country || !$is_eu_country) {
		// Skip check if company's billing country is the same as store's country or if company's billing country is not EU VAT country.
		woolab_icdic_vat_exempt_reset();
		return;
	}

	$vat_num = woolab_icdic_select_vat_number( $country, isset( $data['billing_dic'] ) ? $data['billing_dic'] : '', isset( $data['billing_dic_dph'] ) ? $data['billing_dic_dph'] : '' );

	$is_company = ! isset($data['billing_iscomp']) || $data['billing_iscomp'] == 1;

	if ( !empty($vat_num) && $is_company ) {
		$is_vat_exempt = woolab_icdic_vat_number_exempt( $vat_num, $ignore_vat_check_fail );

		$is_vat_exempt = apply_filters( 'woolab_icdic_vat_exempt_company', $is_vat_exempt, $data );

		woolab_icdic_vat_exempt_set( $is_vat_exempt );

		// Shown with the order review WooCommerce is sending back.
		$notice = woolab_icdic_vat_notice( $country, $vat_num, $is_vat_exempt );
		if ( $notice ) {
			wc_add_notice( $notice, 'notice' );
		}
	} else {
		woolab_icdic_vat_exempt_set( false );
	}
}

/**
 * Saves order metadata on WooCommerce checkout.
 * @param int $order_id
 */
function woolab_icdic_save_order_metadata( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$vat_check_fail_ignored = WC()->session->get( 'woolab_icdic_vat_check_fail_ignored' );

	$order->update_meta_data(
		'woolab_icdic_vat_check_fail_ignored',
		$vat_check_fail_ignored ? 'yes' : 'no'
	);

	$order->save_meta_data();
}

// admin
function woolab_icdic_customer_meta_fields($fields) {
	// The profile being edited, for its billing country (user-edit.php sets it).
	$country = isset( $GLOBALS['profileuser'] ) ? (string) get_user_meta( $GLOBALS['profileuser']->ID, 'billing_country', true ) : '';
	$labels  = woolab_icdic_labels( $country );

	$fields['billing']['fields'] += array(
		'billing_ic' => array(
			'label'       => $labels['ic'],
			'description' => ''
		),
		'billing_dic' => array(
			'label'       => $labels['dic'],
			'description' => ''
		),
		'billing_dic_dph' => array(
			'label'       => woolab_icdic_labels( 'SK' )['dic_dph'],
			'description' => ''
		)
	);
	return $fields;
}

// Add custom user fields to admin order screen
function woolab_icdic_admin_billing_fields ( $fields ) {

	global $pagenow;
	
	// Return empty fields everytime someone wants admin billing fields.
	$fields += array(
		'billing_ic' => array(
			'label' => woolab_icdic_labels( '' )['ic'],
			'show'  => false,
		),
		'billing_dic' => array(
			'label' => woolab_icdic_labels( '' )['dic'],
			'show'  => false,
		),
		'billing_dic_dph' => array(
			'label' => woolab_icdic_labels( 'SK' )['dic_dph'],
			'show'  => false,
			)
		);
		
	$order = null;

	// HPOS ready.
	if ( $pagenow == 'admin.php' && isset($_GET['page']) && $_GET['page'] == 'wc-orders' && isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id']) ) {
		$order = wc_get_order($_GET['id']);		
	} else { // either edit.php or post.php - double checked by the next conditional.
		
		global $post;
		if ($post === null) {
			return $fields;
		}

		$order = wc_get_order( $post->ID );
	}

	if ( $order instanceof \WC_Order ) {

		$country = $order->get_billing_country();
		$labels  = woolab_icdic_labels( $country );

		$fields['billing_ic']['label']  = $labels['ic'];
		$fields['billing_dic']['label'] = $labels['dic'];

		$fields['billing_ic']['value'] = $order->get_meta( '_billing_ic', true );
		$fields['billing_dic']['value'] = $order->get_meta( '_billing_dic', true );
		$fields['billing_dic_dph']['value'] = $order->get_meta( '_billing_dic_dph', true );

		// Hide the VAT reg. no. field if not country SK.
		if ( $country !== 'SK' ) {
			$fields['billing_dic_dph']['show'] = false;
		}

	}

	return $fields;

}

// Load customer user fields via ajax on admin order screen from a customer record
// https://www.jnorton.co.uk/woocommerce-custom-fields
function woolab_icdic_ajax_get_customer_details( $data, $customer, $user_id ){

	$country = get_user_meta( $user_id, 'billing_country', true );
	$data['billing']['billing_ic']  = get_user_meta( $user_id,  'billing_ic', true );
	$data['billing']['billing_dic'] = get_user_meta( $user_id,  'billing_dic', true );
	if ( $country == 'SK' ) {
		$data['billing']['billing_dic_dph'] = get_user_meta( $user_id, 'billing_dic_dph', true );
	}

	return $data;

}

/**
 * Save meta data / custom fields when editing order in admin screen
 *
 * @param int $post_id Post ID.
 * @param object $post Post object.
 *
 * @return void
 */
function woolab_icdic_process_shop_order ( $post_id, $post ) {

	if ( empty( $_POST['woocommerce_meta_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' ) ) {
		return;
	}

	// The nonce is only rendered on the gated order-edit screen, but verify the
	// capability explicitly so this handler can never write order/user meta for
	// a user who cannot edit shop orders.
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		return;
	}

	$order = wc_get_order( $post_id );

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$update_user_meta = apply_filters( 'woolab_icdic_update_user_meta', false );
	$user_id          = $order->get_user_id();

	$fields = array(
		'_billing_billing_ic'      => array( '_billing_ic', 'billing_ic' ),
		'_billing_billing_dic'     => array( '_billing_dic', 'billing_dic' ),
		'_billing_billing_dic_dph' => array( '_billing_dic_dph', 'billing_dic_dph' ),
	);

	$changed = false;

	foreach ( $fields as $post_key => $keys ) {
		list( $meta_key, $user_meta_key ) = $keys;

		if ( ! isset( $_POST[ $post_key ] ) ) {
			continue;
		}

		$value = wc_clean( wp_unslash( $_POST[ $post_key ] ) );

		// On a full admin order save these inputs are always posted, and other
		// plugins hooked to woocommerce_process_shop_order_meta save the order
		// too — skip untouched values so we don't add another save to that
		// cascade (it can corrupt totals recalculated by WooCommerce core).
		if ( (string) $value === (string) $order->get_meta( $meta_key, true ) ) {
			continue;
		}

		$order->update_meta_data( $meta_key, $value );
		$changed = true;

		if ( $update_user_meta && $user_id !== 0 ) { // Update if not guest.
			update_user_meta( $user_id, $user_meta_key, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
		}
	}

	if ( $changed ) {
		// Persist only the meta rows — a full $order->save() here would run the
		// whole order-save pipeline once more in the middle of WooCommerce's own
		// meta-box save sequence.
		$order->save_meta_data();
	}

}

/**
 * Show notice about Business ID or VAT number check error
 * below order number on orders table view.
 * @param string $column Column ID.
 * @param int $post_id	Post ID.
 */
function woolab_icdic_show_check_failed_notice_on_orders_table( $column, $post_id ) {
    if ( $column !== 'order_number' ) {
        return;
    }

    $order        = wc_get_order( $post_id );
	$check_failed = ( $order instanceof WC_Order )
        ? ( $order->get_meta( 'woolab_icdic_vat_check_fail_ignored' ) === 'yes' )
        : false;

    if ( ! $check_failed ) {
        return;
    }

    ?>

    <br>
    <strong style="color: #dba617;">
        <?php esc_html_e( 'Verification of VAT number has failed.', 'woolab-ic-dic' ) ?>
    </strong>

    <?php
}

/**
 * Show notice about Business ID or VAT number check error
 * below order number on orders table view.
 * @param string $column
 * @param WC_Order $order
 */
function woolab_icdic_show_check_failed_notice_on_orders_table_hpos( $column, $order ) {
    if ( $column !== 'order_number' ) {
        return;
    }

	$check_failed = ( $order instanceof WC_Order )
        ? ( $order->get_meta( 'woolab_icdic_vat_check_fail_ignored' ) === 'yes' )
        : false;

    if ( ! $check_failed ) {
        return;
    }

    ?>

	<strong style="color: #dba617;">
		<?php esc_html_e( 'Verification of VAT number has failed.', 'woolab-ic-dic' ) ?>
	</strong>
	<br>

    <?php
}

/**
 * Show notice about Business ID or VAT number check error
 * below billing number fields on order edit screen.
 * @param WC_Order $order
 */
function woolab_icdic_show_check_failed_notice_on_order_edit( $order ) {
	$check_failed = ( $order->get_meta( 'woolab_icdic_vat_check_fail_ignored' ) === 'yes' );

	if ( ! $check_failed ) {
		return;
	}

	?>

	<div class="notice notice-warning inline">
		<p>
			<strong><?php esc_html_e( 'Caution!', 'woolab-ic-dic' ) ?></strong><br>
			<?php esc_html_e( 'Verification of VAT number has failed.', 'woolab-ic-dic' ) ?>
			<?php esc_html_e( 'Please, make sure the VAT number is valid before processing the order.', 'woolab-ic-dic' ) ?>
		</p>
	</div>

	<?php
}

/**
 * Show notice about Business ID or VAT number check error
 * below billing number fields on order edit screen.
 * @param string $address_type 'billing' or 'shipping'.
 * @param WC_Order $order
 * @param bool $sent_to_admin
 * @param bool $plain_text
 */
function woolab_icdic_show_check_failed_notice_on_admin_email(
	$order,
	$sent_to_admin,
	$plain_text
) {
	if ( ! $order instanceof WC_Order || ! $sent_to_admin ) {
		return;
	}

	$check_failed = ( $order->get_meta( 'woolab_icdic_vat_check_fail_ignored' ) === 'yes' );

	if ( ! $check_failed ) {
		return;
	}

	ob_start();

	?>

	<p>
		<strong><?php esc_html_e( 'Caution!', 'woolab-ic-dic' ) ?></strong><br>
		<?php esc_html_e( 'Verification of VAT number has failed.', 'woolab-ic-dic' ) ?>
		<?php esc_html_e( 'Please, make sure the VAT number is valid before processing the order.', 'woolab-ic-dic' ) ?>
	</p>

	<?php

	$content = ob_get_clean();

	echo ( $plain_text )
		? strip_tags( $content )
		: $content;
}

// Add settings link
function woolab_icdic_plugin_action_links( $links ) {
	$action_links = array(
		'settings' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=woolab_icdic' ) . '" aria-label="' . esc_attr__( 'View Kybernaut IČO DIČ settings', 'woolab-ic-dic' ) . '">' . esc_html__( 'Settings', 'woolab-ic-dic' ) . '</a>',
	);

	return array_merge( $action_links, $links );
}
