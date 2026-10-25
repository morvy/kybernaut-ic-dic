<?php
/**
 * Cart & Checkout Blocks support.
 *
 * Every front-end hook of the classic checkout (Checkout\Classic) is classic-checkout
 * only. In the block checkout `woocommerce_checkout_fields` never runs (so there are
 * no inputs), `woocommerce_checkout_process` never runs (so nothing is validated),
 * `woocommerce_checkout_update_order_review` never runs (so VAT exemption is never
 * recalculated) and `woocommerce_checkout_update_order_meta` never runs (so the
 * check-failed flag is never stored).
 *
 * This class re-attaches the same behaviour to the Store API through WooCommerce's
 * Additional Checkout Fields API. It is an adapter, exactly like Checkout\Classic is:
 * every decision still belongs to the Validator, VatExempt and the registers, and
 * none of them is reimplemented here. VIES and ARES results keep the transient
 * caches they already have, because they are reached through the same code.
 *
 * The three values are written back to `_billing_ic` / `_billing_dic` /
 * `_billing_dic_dph`, so everything downstream — the admin order screen, the address
 * formatting, the e-mails, invoicing plugins, order exports — keeps reading what it
 * always read and needs no change at all.
 *
 * Requires WooCommerce 10.0 — the conditional `hidden` / `required` rules are
 * JSON Schema with `properties` / `anyOf`, which 9.9 wraps a second time
 * (woocommerce PR #57965), so 2.0.0 raises the floor rather than
 * feature-detecting the API. tests/e2e runs the block checkout on 10.0.
 *
 * @package KybernautIcDic
 * @since 2.0.0
 */

namespace KybernautIcDic\Checkout;

use KybernautIcDic\Services\Services;
use KybernautIcDic\Settings\Flags;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Errors;
use KybernautIcDic\Validation\Validator;
use KybernautIcDicDeps\Ibericode\Vat\Countries;

/**
 * The additional checkout fields and the Store API hooks.
 */
final class Blocks {

	/**
	 * The Store API request being dispatched (remember_request()).
	 *
	 * @var \WP_REST_Request<array<string,mixed>>|null
	 */
	private static $request = null;

	/**
	 * Hook the block checkout.
	 */
	public static function register(): void {
		add_action( 'woocommerce_init', array( self::class, 'register_fields' ) );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( self::class, 'register_integration' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'remember_request' ), 10, 3 );
		add_action( 'woocommerce_blocks_validate_location_order_fields', array( self::class, 'validate' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'update_order' ), 10, 2 );
		add_filter( 'woocommerce_localisation_address_formats', array( self::class, 'strip_address_tokens' ), 20 );
		add_filter( 'option_woocommerce_checkout_company_field', array( self::class, 'hide_core_company' ) );
		add_filter( 'default_option_woocommerce_checkout_company_field', array( self::class, 'hide_core_company' ) );
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
	 * The DIČ field reads differently per billing country (Fields\Labels), and a
	 * block field's label is fixed, so there is one field per label, each shown for
	 * its countries and all saved to billing_dic. The shop's own country is marked
	 * apart from the rest (woolab_icdic_field_mark()), so outside SK and CZ it gets a
	 * field of its own too. The Business ID is split the same way.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields() {
		// The Business ID where every company has one: looked up, and required in the
		// "Required" mode. WooCommerce does not hold an order field to a `required`
		// rule on the billing country, so the other countries get a field of their own.
		$home   = woolab_icdic_ic_home_countries();
		$fields = array( 'kybernaut/ic' => self::field( 'billing_ic', '', 'ic', array( 'only' => $home ) ) );

		if ( null === woolab_icdic_ic_countries() ) {
			$fields['kybernaut/ic-eu'] = self::field( 'billing_ic', 'EU', 'ic', array( 'except' => $home ) );
		}

		$fields['kybernaut/dic-sk'] = self::field( 'billing_dic', 'SK', 'dic', array( 'only' => array( 'SK' ) ) );
		$fields['kybernaut/dic-cz'] = self::field( 'billing_dic', 'CZ', 'dic', array( 'only' => array( 'CZ' ) ) );

		$others = array( 'SK', 'CZ' );
		$base   = woolab_icdic_base_country();

		if ( '' !== $base && ! in_array( $base, $others, true ) ) {
			$fields[ 'kybernaut/dic-' . strtolower( $base ) ] = self::field( 'billing_dic', $base, 'dic', array( 'only' => array( $base ) ) );
			$others[] = $base;
		}

		// The original id, so orders placed before the split keep their value.
		$fields['kybernaut/dic']     = self::field( 'billing_dic', 'EU', 'dic', array( 'except' => $others ) );
		$fields['kybernaut/dic-dph'] = self::field( 'billing_dic_dph', 'SK', 'dic_dph', array( 'only' => array( 'SK' ) ) );

		return $fields;
	}

	/**
	 * One entry of fields().
	 *
	 * @param string                      $legacy    The meta name.
	 * @param string                      $country   Billing country for woolab_icdic_labels().
	 * @param string                      $key       woolab_icdic_labels() key.
	 * @param array<string,string[]>|null $countries 'only' | 'except' => country codes, or null for all.
	 * @return array<string,mixed>
	 */
	private static function field( $legacy, $country, $key, $countries ) {
		return array(
			'legacy'    => $legacy,
			'label'     => array( $country, $key ),
			'countries' => $countries,
		);
	}

	/**
	 * Whether a field of fields() is shown for a billing country.
	 *
	 * @param array<string,mixed> $field   A fields() entry.
	 * @param string              $country Billing country.
	 * @return bool
	 */
	public static function field_applies( array $field, $country ) {
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
	 * @param string $country Billing country.
	 * @return string|null Field id.
	 */
	public static function field_for( $legacy, $country ) {
		foreach ( self::fields() as $id => $field ) {
			if ( $legacy === $field['legacy'] && self::field_applies( $field, $country ) ) {
				return $id;
			}
		}

		return null;
	}

	/**
	 * The DIČ field id per billing country, for assets/js/blocks.js: 'SK', 'CZ', the
	 * shop's country, and '' for every other one.
	 *
	 * @return array<string,string|null>
	 */
	public static function dic_fields() {
		$fields = array();

		foreach ( array( 'SK', 'CZ', woolab_icdic_base_country() ) as $country ) {
			$fields[ $country ] = self::field_for( 'billing_dic', $country );
		}
		$fields[''] = self::field_for( 'billing_dic', 'EU' );

		return $fields;
	}

	/**
	 * A `hidden` rule: hide the field outside its countries.
	 *
	 * @param array<string,string[]> $countries fields() 'countries'.
	 * @return array<string,mixed> JSON Schema.
	 */
	private static function country_rule( array $countries ) {
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
	 * Load blocks.js with the Checkout block, wherever it renders, through
	 * WooCommerce's integration registry (Checkout\BlocksIntegration).
	 *
	 * @param \Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry $registry The registry.
	 */
	public static function register_integration( $registry ): void {
		$registry->register( new BlocksIntegration() );
	}

	/**
	 * Whether the company name belongs to this plugin's own section.
	 *
	 * With the toggle enabled, Classic::checkout_fields() puts billing_company in
	 * the same group as the identifiers — it gets the `woolab-ic-dic-toggle` class and
	 * public.js shows, hides and clears it together with IČO and DIČ. The block checkout
	 * has to follow that: the company name belongs in the company section, not in the
	 * address, or the toggle would hide the identifiers and leave the company behind.
	 *
	 * @return bool
	 */
	public static function owns_company() {
		return Flags::toggle();
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
	private static function context() {

		if ( WC()->is_store_api_request() ) {
			return true;
		}

		// A shortcode checkout is the classic one, where the toggle already handles the
		// company field in JS and taking it out of the address would break it.
		return did_action( 'wp' )
			&& is_checkout()
			&& ! is_order_received_page()
			&& ! is_checkout_pay_page()
			&& self::checkout();
	}

	/**
	 * Take the core Company field out of the block address form.
	 *
	 * WC_Countries::get_default_address_fields() and CheckoutFields::get_core_fields()
	 * both read this option, so filtering it is the one place that removes the field
	 * from the address form — and, scoped to the block checkout, only from that one.
	 *
	 * @param mixed $value The option.
	 * @return mixed
	 */
	public static function hide_core_company( $value ) {
		return ( self::owns_company() && self::context() ) ? 'hidden' : $value;
	}

	/**
	 * Whether the checkout page is built from the Checkout block.
	 *
	 * @return bool
	 */
	public static function checkout() {
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
	public static function register_fields(): void {

		$toggle = Flags::toggle();

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

		// The "Required" mode (woolab_icdic_fields_mode()): a company must enter its name,
		// the Business ID where every company has one, and the shop's own DIČ when the
		// store requires it (woolab_icdic_dic_required()). "A company"
		// is the inverse of $not_a_company while the toggle is on, and a filled-in company
		// name (a core address field then) while it is off. WooCommerce evaluates the
		// rule in the browser — the field loses its "(optional)" suffix and blocks the
		// order — and again on the checkout POST (StoreApi Checkout route,
		// get_contextual_fields_for_location()), so validate() does not repeat it.
		$required = false;

		if ( 'required' === woolab_icdic_fields_mode() ) {
			$required = $toggle
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
									'company' => array(
										'type'    => 'string',
										'pattern' => '\\S',
									),
								),
								'required'   => array( 'company' ),
							),
						),
					),
				);
		}

		// The company name comes first, above the identifiers — the same order the field
		// priorities give the classic checkout. It is a core order property rather than
		// meta, so it is registered here and not part of fields().
		if ( self::owns_company() ) {
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => 'kybernaut/company',
					'label'                      => __( 'Company', 'woocommerce' ),
					'optionalLabel'              => woolab_icdic_marked_label( __( 'Company', 'woocommerce' ), woolab_icdic_field_mark( 'company', '' ) ),
					'location'                   => 'order',
					'type'                       => 'text',
					'hidden'                     => $not_a_company,
					'required'                   => $required,
					'attributes'                 => array( 'autocomplete' => 'organization' ),
					'show_in_order_confirmation' => false,
				)
			);

			add_filter( 'woocommerce_get_default_value_for_kybernaut/company', array( self::class, 'default_company' ), 10, 3 );
		}

		foreach ( self::fields() as $id => $field ) {

			$legacy = $field['legacy'];
			$hidden = $toggle ? $not_a_company : false;

			if ( $field['countries'] ) {
				$by_country = self::country_rule( $field['countries'] );
				$hidden     = $toggle
					? array( 'anyOf' => array( array( 'properties' => $not_a_company ), $by_country ) )
					: $by_country;
			}

			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => $id,
					'label'                      => woolab_icdic_labels( $field['label'][0] )[ $field['label'][1] ],
					'optionalLabel'              => woolab_icdic_form_labels( $field['label'][0] )[ $field['label'][1] ],
					'location'                   => 'order',
					'type'                       => 'text',
					'hidden'                     => $hidden,
					'required'                   => 'kybernaut/ic' === $id || ( self::field_for( 'billing_dic', woolab_icdic_base_country() ) === $id && woolab_icdic_dic_required( woolab_icdic_base_country() ) ) ? $required : false,
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

					return $stored ?: $value; // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- the stored value, else the default.
				},
				10,
				3
			);
		}

		if ( $toggle ) {
			add_filter( 'woocommerce_get_default_value_for_kybernaut/iscomp', array( self::class, 'default_iscomp' ), 10, 3 );
		}
	}

	/**
	 * Prefill the company name from the address WooCommerce already has.
	 *
	 * The value lives on the order and the customer as billing_company, not as an
	 * additional field, so nothing would come back without this.
	 *
	 * @param mixed    $value     Default value.
	 * @param string   $group     Field group.
	 * @param \WC_Data $wc_object Order or customer.
	 * @return mixed
	 */
	public static function default_company( $value, $group, $wc_object ) {

		if ( $wc_object instanceof \WC_Order ) {
			return $wc_object->get_billing_company() ?: $value; // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- the stored company, else the default.
		}

		if ( $wc_object instanceof \WC_Customer ) {
			return $wc_object->get_billing_company() ?: $value; // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- the stored company, else the default.
		}

		return $value;
	}

	/**
	 * Tick "Buying as a company" for a customer who already has company data.
	 *
	 * The block equivalent of Classic::toggle_iscomp_field(), which does the same
	 * for the classic field's default value. A checkbox field reads '1' as checked.
	 *
	 * @param mixed    $value     Default value.
	 * @param string   $group     Field group.
	 * @param \WC_Data $wc_object Order or customer.
	 * @return mixed
	 */
	public static function default_iscomp( $value, $group, $wc_object ) {

		foreach ( array( 'billing_company', 'billing_ic', 'billing_dic', 'billing_dic_dph' ) as $key ) {
			// The company is a core property of orders and customers, not meta.
			$stored = 'billing_company' === $key
				? ( is_callable( array( $wc_object, 'get_billing_company' ) ) ? $wc_object->get_billing_company() : '' )
				: ( $wc_object->get_meta( $key, true ) ?: $wc_object->get_meta( '_' . $key, true ) ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- the stored value, else the order meta.

			if ( ! empty( $stored ) ) {
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
	 * @param mixed                                 $result  Response to replace the requested one with.
	 * @param \WP_REST_Server                       $server  Server.
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 * @return mixed
	 */
	public static function remember_request( $result, $server, $request ) {

		if ( str_starts_with( $request->get_route(), '/wc/store/' ) ) {
			self::request( $request );
		}

		return $result;
	}

	/**
	 * The Store API request remember_request() kept.
	 *
	 * @param \WP_REST_Request<array<string,mixed>>|null $request Request to keep.
	 * @return \WP_REST_Request<array<string,mixed>>|null
	 */
	public static function request( $request = null ) {
		if ( $request instanceof \WP_REST_Request ) {
			self::$request = $request;
		}

		return self::$request;
	}

	/**
	 * Validate the company identifiers on checkout.
	 *
	 * The block counterpart of Classic::checkout_field_process(): it resolves the
	 * same settings into the same flags and hands them to the same Validator, so
	 * the two checkouts cannot drift apart.
	 *
	 * Only the final POST is validated. The draft-order PUT fires on every change the
	 * shopper makes, and ARES and VIES are network calls; the classic checkout validates
	 * on submit as well. Live feedback while typing is assets/js/blocks.js's job.
	 *
	 * @param \WP_Error           $errors Errors.
	 * @param array<string,mixed> $fields Posted values for the 'order' location.
	 */
	public static function validate( $errors, $fields ): void {

		$request = self::request();

		if ( ! $request || 'POST' !== $request->get_method() ) {
			return;
		}

		// Nothing to check when the shopper is not buying as a company. WooCommerce
		// blanks hidden fields when it persists them, but they are still posted.
		if ( Flags::toggle() && empty( $fields['kybernaut/iscomp'] ) ) {
			return;
		}

		$billing  = wc_clean( (array) ( $request['billing_address'] ?? array() ) );
		$shipping = wc_clean( (array) ( $request['shipping_address'] ?? array() ) );
		$customer = WC()->customer;

		$country = $billing['country'] ?? '';

		if ( '' === $country && ! empty( $customer ) ) {
			$country = $customer->get_billing_country();
		}

		// Mirrors the classic hook's "bail if the form is not fully filled in" guard.
		if ( '' === $country ) {
			return;
		}

		$countries = new Countries();
		$dic_field = self::field_for( 'billing_dic', $country );

		$input = array(
			'country'               => $country,
			'ic'                    => (string) ( $fields[ self::field_for( 'billing_ic', $country ) ] ?? '' ),
			'dic'                   => (string) ( $fields[ $dic_field ] ?? '' ),
			'dic_present'           => isset( $fields[ $dic_field ] ),
			'dic_dph'               => (string) ( $fields['kybernaut/dic-dph'] ?? '' ),
			// While the toggle owns the company name it is posted with the rest of
			// the section, not with the address.
			'company'               => self::owns_company()
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
			'shipping_country'      => $shipping['country'] ?? null,
			'ares_check'            => Flags::ares_check(),
			'ares_fill'             => Flags::ares_fill(),
			'vies_check'            => Flags::vies_check(),
			'sk_check'              => Flags::sk_check(),
			'sk_fill'               => Flags::sk_fill(),
			'ignore_check_fail'     => Flags::ignore_check_fail(),
			/** This filter is documented in src/Checkout/Classic.php */
			'check_country_match'   => (bool) apply_filters( 'woolab_icdic_check_billing_country_and_dic', true ),
			'require_dic_for_firms' => woolab_icdic_dic_required( $country ),
			'check_dic_dph_match'   => Flags::dic_dph_match(),
			'country_in_eu'         => $countries->isCountryCodeInEU( $country ),
		);

		$input['services'] = Services::for_country( $country );
		$result            = Validator::run( new Context( $input ) );

		foreach ( $result->errors() as $error ) {
			// WooCommerce shows these above the section, and keeps no field key in them,
			// so the code names the field and blocks.js moves the message under it.
			$legacy = Errors::field( $error, $country );
			$field  = 'billing_company' === $legacy
				? ( self::owns_company() ? 'kybernaut/company' : null )
				: self::field_for( $legacy, $country );
			$errors->add( $field ? 'woolab_icdic:' . $field . ':' . $error['code'] : 'woolab_icdic_' . $error['code'], Errors::message( $error, $country ) );
		}

		// Read back in update_order(), which stands in for
		// Order\Meta::save_order_metadata() on this checkout.
		WC()->session->set( 'woolab_icdic_vat_check_fail_ignored', $result->check_fail_ignored() );
	}

	/**
	 * Copy the additional fields onto the legacy meta.
	 *
	 * Runs when the order is created on placing it (and on a PATCH that updates an
	 * existing order, a failed-payment retry). The VAT exemption is not set here: the
	 * totals are already calculated by then (VatExempt::blocks_customer_vat_exempt()).
	 *
	 * @param \WC_Order                             $order   Order.
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	public static function update_order( $order, $request ): void {

		$is_submit = 'POST' === $request->get_method();
		$user_id   = $order->get_user_id();

		// The company name is a core order property, and while it belongs to this
		// plugin's section it is the only thing writing it — the address form no longer
		// carries the field. Clearing it when the shopper unticks the toggle mirrors
		// clear_company_data() in assets/js/public.js.
		if ( self::owns_company() ) {
			$company = (string) $order->get_meta( '_wc_other/kybernaut/company', true );

			$order->set_billing_company( $company );

			if ( $is_submit && $user_id ) {
				update_user_meta( $user_id, 'billing_company', $company );
			}
		}

		$country = $order->get_billing_country();

		foreach ( array( 'billing_ic', 'billing_dic', 'billing_dic_dph' ) as $legacy ) {
			// The field shown for this country; none (IČ DPH outside SK) stores empty.
			$id    = self::field_for( $legacy, $country );
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
	 * @param array<string,string> $formats Address formats by country.
	 * @return array<string,string>
	 */
	public static function strip_address_tokens( $formats ) {

		// Deliberately NOT context(): that also covers Store API requests, and the order
		// e-mails are rendered inside the checkout POST. Stripping the tokens there would
		// take IČO and DIČ out of every confirmation mail. Only the page render matters —
		// that is the request that ships countryData to the browser.
		if ( ! did_action( 'wp' ) || ! is_checkout() || is_order_received_page() || is_checkout_pay_page() || ! self::checkout() ) {
			return $formats;
		}

		$tokens = array( "\n{billing_ic}", "\n{billing_dic}", "\n{billing_dic_dph}" );

		foreach ( $formats as $country_code => $format ) {
			$formats[ $country_code ] = str_replace( $tokens, '', $format );
		}

		return $formats;
	}
}
