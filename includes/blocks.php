<?php
/**
 * Cart & Checkout Blocks support.
 *
 * Every front-end hook this plugin uses is classic-checkout only. In the block
 * checkout `woocommerce_checkout_fields` never runs (so there are no inputs),
 * `woocommerce_checkout_process` never runs (so nothing is validated),
 * `woocommerce_checkout_update_order_review` never runs (so VAT exemption is never
 * recalculated) and `woocommerce_checkout_update_order_meta` never runs (so the
 * check-failed flag is never stored).
 *
 * This file re-attaches the same behaviour to the Store API through WooCommerce's
 * Additional Checkout Fields API. It is an adapter, exactly like the classic hooks
 * in filters-actions.php are: every decision still belongs to the pure functions —
 * woolab_icdic_validate_checkout(), woolab_icdic_vat_number_exempt(),
 * woolab_icdic_select_vat_number(), woolab_icdic_ares() — and none of them is
 * reimplemented here. VIES and ARES results keep the transient caches they already
 * have, because they are reached through the same functions.
 *
 * The three values are written back to `_billing_ic` / `_billing_dic` /
 * `_billing_dic_dph`, so everything downstream — the admin order screen, the address
 * formatting, the e-mails, invoicing plugins, order exports — keeps reading what it
 * always read and needs no change at all.
 *
 * Requires WooCommerce 11.0 — the conditional `hidden` rules need its schema parser,
 * which is why 2.0.0 raises the floor rather than feature-detecting the API.
 *
 * @since 2.0.0
 */

use KybernautIcDicDeps\Ibericode\Vat\Countries;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The additional fields: id => array(
 *     'legacy'    => the meta name the rest of the plugin already uses,
 *     'label'     => array( billing country, woolab_icdic_labels() key ),
 *     'countries' => array( 'only' | 'except' => country codes ), or null for all.
 * )
 *
 * The order meta key is the legacy name with a leading underscore (`_billing_ic`),
 * the customer meta key is the bare name (`billing_ic`) — the same split the
 * classic checkout produces.
 *
 * The DIČ field reads differently per billing country (includes/labels.php), and a
 * block field's label is fixed, so there is one field per label, each shown for
 * its countries and all saved to billing_dic.
 *
 * @return array<string,array>
 */
function woolab_icdic_blocks_fields() {
	return array(
		'kybernaut/ic'      => array( 'legacy' => 'billing_ic', 'label' => array( '', 'ic' ), 'countries' => null === woolab_icdic_ic_countries() ? null : array( 'only' => woolab_icdic_ic_countries() ) ),
		'kybernaut/dic-sk'  => array( 'legacy' => 'billing_dic', 'label' => array( 'SK', 'dic' ), 'countries' => array( 'only' => array( 'SK' ) ) ),
		'kybernaut/dic-cz'  => array( 'legacy' => 'billing_dic', 'label' => array( 'CZ', 'dic' ), 'countries' => array( 'only' => array( 'CZ' ) ) ),
		// The original id, so orders placed before the split keep their value.
		'kybernaut/dic'     => array( 'legacy' => 'billing_dic', 'label' => array( 'EU', 'dic' ), 'countries' => array( 'except' => array( 'SK', 'CZ' ) ) ),
		'kybernaut/dic-dph' => array( 'legacy' => 'billing_dic_dph', 'label' => array( 'SK', 'dic_dph' ), 'countries' => array( 'only' => array( 'SK' ) ) ),
	);
}

/**
 * What an optional field reads: WooCommerce's "Label (optional)", or the bare
 * label when the store hides it (woolab_icdic_hide_optional()).
 *
 * @param string $label
 * @return string
 */
function woolab_icdic_blocks_optional_label( $label ) {
	/* translators: %s Field label. */
	return woolab_icdic_hide_optional() ? $label : sprintf( __( '%s (optional)', 'woocommerce' ), $label );
}

/**
 * Whether a field of woolab_icdic_blocks_fields() is shown for a billing country.
 *
 * @param array  $field
 * @param string $country
 * @return bool
 */
function woolab_icdic_blocks_field_applies( array $field, $country ) {
	if ( empty( $field['countries'] ) ) {
		return true;
	}

	return isset( $field['countries']['only'] )
		? in_array( $country, $field['countries']['only'], true )
		: ! in_array( $country, $field['countries']['except'], true );
}

/**
 * The field that holds a legacy value for a billing country, e.g. which of the
 * three DIČ fields is shown for SK.
 *
 * @param string $legacy  billing_ic, billing_dic or billing_dic_dph.
 * @param string $country
 * @return string|null Field id.
 */
function woolab_icdic_blocks_field_for( $legacy, $country ) {
	foreach ( woolab_icdic_blocks_fields() as $id => $field ) {
		if ( $legacy === $field['legacy'] && woolab_icdic_blocks_field_applies( $field, $country ) ) {
			return $id;
		}
	}

	return null;
}

/**
 * The DIČ field id per billing country, for assets/js/blocks.js: 'SK', 'CZ', and
 * '' for every other country.
 *
 * @return array<string,string>
 */
function woolab_icdic_blocks_dic_fields() {
	return array(
		'SK' => woolab_icdic_blocks_field_for( 'billing_dic', 'SK' ),
		'CZ' => woolab_icdic_blocks_field_for( 'billing_dic', 'CZ' ),
		''   => woolab_icdic_blocks_field_for( 'billing_dic', 'EU' ),
	);
}

/**
 * A `hidden` rule: hide the field outside its countries.
 *
 * @param array $countries woolab_icdic_blocks_fields() 'countries'.
 * @return array JSON Schema.
 */
function woolab_icdic_blocks_country_rule( array $countries ) {
	$country = isset( $countries['only'] )
		? array( 'not' => array( 'enum' => $countries['only'] ) )
		: array( 'enum' => $countries['except'] );

	return array(
		'properties' => array(
			'customer' => array(
				'properties' => array(
					'billing_address' => array(
						'properties' => array( 'country' => $country ),
					),
				),
			),
		),
	);
}

/**
 * Whether the company name belongs to this plugin's own section.
 *
 * With the toggle enabled, woolab_icdic_checkout_fields() puts billing_company in
 * the same group as the identifiers — it gets the `woolab-ic-dic-toggle` class and
 * public.js shows, hides and clears it together with IČO and DIČ. The block checkout
 * has to follow that: the company name belongs in the company section, not in the
 * address, or the toggle would hide the identifiers and leave the company behind.
 *
 * @return bool
 */
function woolab_icdic_blocks_owns_company() {
	return woolab_icdic_toggle_enabled();
}

/**
 * Whether the request is building or serving the block checkout.
 *
 * Used to scope the core-company override to that form only. My Account, the classic
 * checkout and every other PHP-rendered address keep the Company field WooCommerce's
 * own setting gives them.
 *
 * @return bool
 */
function woolab_icdic_blocks_context() {

	if ( WC()->is_store_api_request() ) {
		return true;
	}

	// A shortcode checkout is the classic one, where the toggle already handles the
	// company field in JS and taking it out of the address would break it.
	return did_action( 'wp' )
		&& is_checkout()
		&& ! is_order_received_page()
		&& ! is_checkout_pay_page()
		&& woolab_icdic_blocks_checkout();
}

/**
 * Take the core Company field out of the block address form.
 *
 * WC_Countries::get_default_address_fields() and CheckoutFields::get_core_fields()
 * both read this option, so filtering it is the one place that removes the field
 * from the address form — and, scoped to the block checkout, only from that one.
 *
 * @param mixed $value
 * @return mixed
 */
function woolab_icdic_blocks_hide_core_company( $value ) {
	return ( woolab_icdic_blocks_owns_company() && woolab_icdic_blocks_context() ) ? 'hidden' : $value;
}

/**
 * Whether the checkout page is built from the Checkout block.
 *
 * @return bool
 */
function woolab_icdic_blocks_checkout() {
	$page_id = wc_get_page_id( 'checkout' );

	return $page_id > 0 && has_block( 'woocommerce/checkout', $page_id );
}

/**
 * Register the company identifiers as additional checkout fields.
 *
 * They go in the 'order' location — one copy of each, in their own section, which
 * is where company identifiers belong. The 'address' location would render them in
 * the shipping form as well and store a second, unused copy under `_wc_shipping/`.
 */
function woolab_icdic_blocks_register_fields() {

	$toggle = woolab_icdic_toggle_enabled();

	if ( $toggle ) {
		woocommerce_register_additional_checkout_field(
			array(
				'id'                         => 'kybernaut/iscomp',
				'label'                      => __( 'Buying as a company', 'woolab-ic-dic' ),
				'location'                   => 'order',
				'type'                       => 'checkbox',
				'show_in_order_confirmation' => false,
			)
		);
	}

	// The classic checkout hides the fields behind the toggle in JS
	// (assets/js/public.js). Here it is a rule WooCommerce evaluates itself, in the
	// browser and again on the server. An absent value satisfies it too, so on a
	// fresh checkout the fields start hidden.
	$not_a_company = array(
		'checkout' => array(
			'properties' => array(
				'additional_fields' => array(
					'properties' => array(
						'kybernaut/iscomp' => array( 'not' => array( 'const' => true ) ),
					),
				),
			),
		),
	);

	// Opt-in: a company must enter its name and Business ID. "A company" is the inverse of
	// $not_a_company while the toggle is on, and a filled-in company name (a core
	// address field then) while it is off. WooCommerce evaluates the rule in the
	// browser — the field loses its "(optional)" suffix and blocks the order — and
	// again on the checkout POST (StoreApi Checkout route, get_contextual_fields_for_location()),
	// so woolab_icdic_blocks_validate() does not repeat it.
	$ic_required = false;

	if ( apply_filters( 'woolab_icdic_required_ic_for_company', false ) ) {
		$ic_required = $toggle
			? array(
				'checkout' => array(
					'properties' => array(
						'additional_fields' => array(
							'properties' => array(
								'kybernaut/iscomp' => array( 'const' => true ),
							),
							'required'   => array( 'kybernaut/iscomp' ),
						),
					),
				),
			)
			: array(
				'customer' => array(
					'properties' => array(
						'billing_address' => array(
							'properties' => array(
								'company' => array( 'type' => 'string', 'pattern' => '\\S' ),
							),
							'required'   => array( 'company' ),
						),
					),
				),
			);
	}

	// The company name comes first, above the identifiers — the same order the field
	// priorities give the classic checkout. It is a core order property rather than
	// meta, so it is registered here and not part of woolab_icdic_blocks_fields().
	if ( woolab_icdic_blocks_owns_company() ) {
		woocommerce_register_additional_checkout_field(
			array(
				'id'                         => 'kybernaut/company',
				'label'                      => __( 'Company', 'woocommerce' ),
				'optionalLabel'              => woolab_icdic_blocks_optional_label( __( 'Company', 'woocommerce' ) ),
				'location'                   => 'order',
				'type'                       => 'text',
				'hidden'                     => $not_a_company,
				// Same rule as the Business ID: required once "Buying as a company" is ticked.
				'required'                   => $ic_required,
				'attributes'                 => array( 'autocomplete' => 'organization' ),
				'show_in_order_confirmation' => false,
			)
		);

		add_filter( 'woocommerce_get_default_value_for_kybernaut/company', 'woolab_icdic_blocks_default_company', 10, 3 );
	}

	foreach ( woolab_icdic_blocks_fields() as $id => $field ) {

		$legacy = $field['legacy'];
		$hidden = $toggle ? $not_a_company : false;

		if ( $field['countries'] ) {
			$by_country = woolab_icdic_blocks_country_rule( $field['countries'] );
			$hidden     = $toggle
				? array( 'anyOf' => array( array( 'properties' => $not_a_company ), $by_country ) )
				: $by_country;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'                         => $id,
				'label'                      => woolab_icdic_form_labels( $field['label'][0] )[ $field['label'][1] ],
				'optionalLabel'              => woolab_icdic_blocks_optional_label( woolab_icdic_form_labels( $field['label'][0] )[ $field['label'][1] ] ),
				'location'                   => 'order',
				'type'                       => 'text',
				'hidden'                     => $hidden,
				'required'                   => 'kybernaut/ic' === $id ? $ic_required : false,
				'attributes'                 => array( 'autocomplete' => 'off' ),
				'show_in_order_confirmation' => false,
			)
		);

		// Prefill from whatever the classic checkout and My Account → Addresses
		// already stored for this customer, and let historic orders display their
		// values in the order confirmation.
		add_filter(
			"woocommerce_get_default_value_for_{$id}",
			function ( $value, $group, $wc_object ) use ( $legacy ) {
				$stored = $wc_object->get_meta( '_' . $legacy, true );

				if ( ! $stored ) {
					$stored = $wc_object->get_meta( $legacy, true );
				}

				return $stored ? $stored : $value;
			},
			10,
			3
		);
	}

	if ( $toggle ) {
		add_filter( 'woocommerce_get_default_value_for_kybernaut/iscomp', 'woolab_icdic_blocks_default_iscomp', 10, 3 );
	}
}

/**
 * Prefill the company name from the address WooCommerce already has.
 *
 * The value lives on the order and the customer as billing_company, not as an
 * additional field, so nothing would come back without this.
 *
 * @param mixed   $value
 * @param string  $group
 * @param WC_Data $wc_object
 * @return mixed
 */
function woolab_icdic_blocks_default_company( $value, $group, $wc_object ) {

	if ( $wc_object instanceof WC_Order ) {
		return $wc_object->get_billing_company() ? $wc_object->get_billing_company() : $value;
	}

	if ( $wc_object instanceof WC_Customer ) {
		return $wc_object->get_billing_company() ? $wc_object->get_billing_company() : $value;
	}

	return $value;
}

/**
 * Tick "Buying as a company" for a customer who already has company data.
 *
 * The block equivalent of woolab_icdic_toggle_iscomp_field(), which does the same
 * for the classic field's default value. A checkbox field reads '1' as checked.
 *
 * @param mixed   $value
 * @param string  $group
 * @param WC_Data $wc_object
 * @return mixed
 */
function woolab_icdic_blocks_default_iscomp( $value, $group, $wc_object ) {

	// With a Business ID required, a pre-ticked toggle without a stored one could never
	// pass validation — tick it only for a customer who has one.
	$keys = apply_filters( 'woolab_icdic_required_ic_for_company', false )
		? array( 'billing_ic' )
		: array( 'billing_company', 'billing_ic', 'billing_dic', 'billing_dic_dph' );

	foreach ( $keys as $key ) {
		if ( ! empty( $wc_object->get_meta( $key, true ) ) || ! empty( $wc_object->get_meta( '_' . $key, true ) ) ) {
			return '1';
		}
	}

	return $value;
}

/**
 * Keep the Store API request being dispatched.
 *
 * The Store API validates additional fields *before* it copies the posted address
 * onto the customer, so WC()->customer is one request behind at validation time and
 * would hand the validator a stale billing country. WooCommerce solves this for its
 * own rules by building a document object from the request; this does the same,
 * with the request itself.
 *
 * @param mixed            $result
 * @param WP_REST_Server   $server
 * @param WP_REST_Request  $request
 * @return mixed
 */
function woolab_icdic_blocks_remember_request( $result, $server, $request ) {

	if ( 0 === strpos( $request->get_route(), '/wc/store/' ) ) {
		woolab_icdic_blocks_request( $request );
	}

	return $result;
}

/**
 * @param WP_REST_Request|null $request
 * @return WP_REST_Request|null
 */
function woolab_icdic_blocks_request( $request = null ) {
	static $current = null;

	if ( $request instanceof WP_REST_Request ) {
		$current = $request;
	}

	return $current;
}

/**
 * Validate the company identifiers on checkout.
 *
 * The block counterpart of woolab_icdic_checkout_field_process(): it resolves the
 * same settings into the same flags and hands them to the same pure orchestrator, so
 * the two checkouts cannot drift apart.
 *
 * Only the final POST is validated. The draft-order PUT fires on every change the
 * shopper makes, and ARES and VIES are network calls; the classic checkout validates
 * on submit as well. Live feedback while typing is assets/js/blocks.js's job.
 *
 * @param WP_Error $errors
 * @param array    $fields Posted values for the 'order' location.
 */
function woolab_icdic_blocks_validate( $errors, $fields ) {

	$request = woolab_icdic_blocks_request();

	if ( ! $request || 'POST' !== $request->get_method() ) {
		return;
	}

	// Nothing to check when the shopper is not buying as a company. WooCommerce
	// blanks hidden fields when it persists them, but they are still posted.
	if ( woolab_icdic_toggle_enabled() && empty( $fields['kybernaut/iscomp'] ) ) {
		return;
	}

	$billing  = wc_clean( (array) ( $request['billing_address'] ?? array() ) );
	$shipping = wc_clean( (array) ( $request['shipping_address'] ?? array() ) );
	$customer = WC()->customer;

	$country = isset( $billing['country'] ) ? $billing['country'] : '';

	if ( '' === $country && ! empty( $customer ) ) {
		$country = $customer->get_billing_country();
	}

	// Mirrors the classic hook's "bail if the form is not fully filled in" guard.
	if ( '' === $country ) {
		return;
	}

	$countries = new Countries();
	$dic_field = woolab_icdic_blocks_field_for( 'billing_dic', $country );

	$result = woolab_icdic_validate_checkout(
		array(
			'country'               => $country,
			'ic'                    => (string) ( $fields['kybernaut/ic'] ?? '' ),
			'dic'                   => (string) ( $fields[ $dic_field ] ?? '' ),
			'dic_present'           => isset( $fields[ $dic_field ] ),
			'dic_dph'               => (string) ( $fields['kybernaut/dic-dph'] ?? '' ),
			// While the toggle owns the company name it is posted with the rest of
			// the section, not with the address.
			'company'               => woolab_icdic_blocks_owns_company()
				? (string) ( $fields['kybernaut/company'] ?? '' )
				: (string) ( $billing['company'] ?? '' ),
			'postcode'              => (string) ( $billing['postcode'] ?? '' ),
			'city'                  => (string) ( $billing['city'] ?? '' ),
			'address_1'             => (string) ( $billing['address_1'] ?? '' ),
			// The block checkout has no ship-to-different-address checkbox; it
			// always posts both addresses. The only thing the validator does with
			// this pair is compare the shipping country to the VAT country, so a
			// differing shipping country is the faithful equivalent.
			'ship_to_different'     => isset( $shipping['country'] ) && $shipping['country'] !== $country,
			'shipping_country'      => isset( $shipping['country'] ) ? $shipping['country'] : null,
			'ares_check'            => (bool) woolab_icdic_ares_check(),
			'ares_fill'             => (bool) woolab_icdic_ares_fill(),
			'vies_check'            => (bool) woolab_icdic_vies_check(),
			'sk_check'              => (bool) woolab_icdic_sk_check(),
			'sk_fill'               => (bool) woolab_icdic_sk_fill(),
			'ignore_check_fail'     => (bool) woolab_icdic_ignore_check_fail(),
			'check_country_match'   => (bool) apply_filters( 'woolab_icdic_check_billing_country_and_dic', true ),
			'require_sk_ic_and_dic' => (bool) apply_filters( 'woolab_icdic_sk_required_ic_and_dic', true ),
			'check_dic_dph_match'   => (bool) woolab_icdic_dic_dicdph_match_enabled(),
			'country_in_eu'         => $countries->isCountryCodeInEU( $country ),
			'verify_vat'            => woolab_icdic_make_vies_verifier( 'Could not validate VAT number: %s, returned the following exception:' ),
			'lookup_ares'           => 'woolab_icdic_ares',
			'lookup_sk_ico'         => 'woolab_icdic_sk_lookup',
			'lookup_sk'             => 'woolab_icdic_sk_find',
		)
	);

	foreach ( $result['errors'] as $error ) {
		$errors->add( 'woolab_icdic_' . $error['code'], woolab_icdic_checkout_error_message( $error, $country ) );
	}

	// Read back in woolab_icdic_blocks_update_order(), which stands in for
	// woolab_icdic_save_order_metadata() on this checkout.
	WC()->session->set( 'woolab_icdic_vat_check_fail_ignored', $result['check_fail_ignored'] );
}

/**
 * Copy the additional fields onto the legacy meta and recalculate VAT exemption.
 *
 * Runs on the draft-order PUT as well as on the final POST, which is what makes the
 * exemption show up in the totals while the shopper is still filling the form —
 * WooCommerce recalculates them straight after this hook.
 *
 * @param WC_Order        $order
 * @param WP_REST_Request $request
 */
function woolab_icdic_blocks_update_order( $order, $request ) {

	$is_submit = 'POST' === $request->get_method();
	$user_id   = $order->get_user_id();

	// The company name is a core order property, and while it belongs to this
	// plugin's section it is the only thing writing it — the address form no longer
	// carries the field. Clearing it when the shopper unticks the toggle mirrors
	// clear_company_data() in assets/js/public.js.
	if ( woolab_icdic_blocks_owns_company() ) {
		$company = (string) $order->get_meta( '_wc_other/kybernaut/company', true );

		$order->set_billing_company( $company );

		if ( $is_submit && $user_id ) {
			update_user_meta( $user_id, 'billing_company', $company );
		}
	}

	$country = $order->get_billing_country();

	foreach ( array( 'billing_ic', 'billing_dic', 'billing_dic_dph' ) as $legacy ) {
		// The field shown for this country; none (IČ DPH outside SK) stores empty.
		$id    = woolab_icdic_blocks_field_for( $legacy, $country );
		$value = $id ? (string) $order->get_meta( '_wc_other/' . $id, true ) : '';

		$order->update_meta_data( '_' . $legacy, $value );

		// My Account → Addresses is still the classic form, reading the bare key.
		if ( $is_submit && $user_id ) {
			update_user_meta( $user_id, $legacy, $value );
		}
	}

	$order->update_meta_data(
		'woolab_icdic_vat_check_fail_ignored',
		WC()->session->get( 'woolab_icdic_vat_check_fail_ignored' ) ? 'yes' : 'no'
	);

	woolab_icdic_blocks_apply_vat_exempt( $order );
}

/**
 * The block counterpart of woolab_icdic_validate_vat_exempt_for_company().
 *
 * Same rule, same filter, same payload shape — only the source of the data differs,
 * because there is no serialized order-review form to parse here.
 *
 * @param WC_Order $order
 */
function woolab_icdic_blocks_apply_vat_exempt( $order ) {

	if ( ! woolab_icdic_vat_exempt_enabled() || empty( WC()->customer ) ) {
		return;
	}

	$wc_countries  = new WC_Countries();
	$base_country  = apply_filters( 'woolab_icdic_base_country', $wc_countries->get_base_country() );
	$country       = $order->get_billing_country();
	$vat_countries = $wc_countries->get_european_union_countries( 'eu_vat' );

	// Skip if the billing country is the store's own or is not an EU VAT country.
	if ( empty( $country ) || $country === $base_country || ! in_array( $country, $vat_countries, true ) ) {
		return;
	}

	$dic     = (string) $order->get_meta( '_billing_dic', true );
	$dic_dph = (string) $order->get_meta( '_billing_dic_dph', true );
	$vat_num = woolab_icdic_select_vat_number( $country, $dic, $dic_dph );

	$is_company = ! woolab_icdic_toggle_enabled() || $order->get_meta( '_wc_other/kybernaut/iscomp', true );

	$is_vat_exempt = ( ! empty( $vat_num ) && $is_company )
		? woolab_icdic_vat_number_exempt( $vat_num, woolab_icdic_ignore_check_fail() )
		: false;

	$data = array(
		'billing_country' => $country,
		'billing_dic'     => $dic,
		'billing_dic_dph' => $dic_dph,
		'billing_iscomp'  => $is_company ? 1 : 0,
	);

	WC()->customer->set_is_vat_exempt( apply_filters( 'woolab_icdic_vat_exempt_company', $is_vat_exempt, $data ) );
}

/**
 * Drop this plugin's tokens from the address formats the Checkout block exports.
 *
 * Checkout::enqueue_data() ships WC()->countries->get_address_formats() to the
 * browser as `countryData`, and the React address card substitutes tokens from a
 * fixed table that no PHP filter can extend — so `{billing_ic}`, `{billing_dic}` and
 * `{billing_dic_dph}` print literally in the address preview.
 *
 * Removing them for the duration of a checkout-page render is enough: every address
 * rendered by PHP — e-mails, the admin, invoices, the order-received page — still
 * gets them, and WC_Countries caches the formats per request, so this cannot leak
 * into another context.
 *
 * @param array $formats
 * @return array
 */
function woolab_icdic_blocks_strip_address_tokens( $formats ) {

	// Deliberately NOT woolab_icdic_blocks_context(): that also covers Store API
	// requests, and the order e-mails are rendered inside the checkout POST. Stripping
	// the tokens there would take IČO and DIČ out of every confirmation mail. Only the
	// page render matters — that is the request that ships countryData to the browser.
	if ( ! did_action( 'wp' ) || ! is_checkout() || is_order_received_page() || is_checkout_pay_page() || ! woolab_icdic_blocks_checkout() ) {
		return $formats;
	}

	$tokens = array( "\n{billing_ic}", "\n{billing_dic}", "\n{billing_dic_dph}" );

	foreach ( $formats as $country_code => $format ) {
		$formats[ $country_code ] = str_replace( $tokens, '', $format );
	}

	return $formats;
}
