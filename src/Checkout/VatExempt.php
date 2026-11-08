<?php
/**
 * The intra-EU VAT exemption.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Checkout;

use KybernautIcDic\Numbers\Checksum;
use KybernautIcDic\Services\Services;
use KybernautIcDic\Settings\Flags;

/**
 * Which number the exemption is decided by, the decision, and setting it on the
 * customer from both checkouts.
 */
final class VatExempt {

	/**
	 * Hook the exemption of both checkouts.
	 */
	public static function register(): void {
		add_action( 'wp', array( self::class, 'set_vat_exempt_for_customer' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( self::class, 'validate_vat_exempt_for_company' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( self::class, 'blocks_customer_vat_exempt' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'blocks_restore_vat_exempt' ) );
	}

	/**
	 * The field that carries the VIES-checkable VAT number: the IČ DPH in Slovakia,
	 * the DIČ everywhere else.
	 *
	 * @param string $country Billing country.
	 * @param string $dic     DIČ.
	 * @param string $dic_dph IČ DPH.
	 */
	public static function vat_number( string $country, string $dic, string $dic_dph ): string {
		return 'SK' === $country ? $dic_dph : $dic;
	}

	/**
	 * Whether a VIES state grants the exemption: a valid number does; VIES not
	 * answering does with "Ignore when a check fails"; anything else does not.
	 *
	 * @param string $state             'valid', 'bad_format', 'invalid' or 'unverifiable'.
	 * @param bool   $ignore_check_fail Whether a VIES outage still grants it.
	 */
	public static function from_state( string $state, bool $ignore_check_fail ): bool {
		if ( 'valid' === $state ) {
			return true;
		}

		return 'unverifiable' === $state && $ignore_check_fail;
	}

	/**
	 * Whether a tax number is a real one VIES does not list: every Czech, Polish and
	 * Hungarian business has one (Checksum::tax_number()), only VAT payers are in
	 * VIES. Such a company orders with VAT (the Validator).
	 *
	 * @param string $country Billing country.
	 * @param string $vat     The VAT number vat_number() picked.
	 * @return bool
	 */
	public static function vat_not_registered( $country, $vat ) {
		$vat = preg_replace( '/\s+/', '', (string) $vat );

		// The VIES answer is cached (Services\Vies\Vies), the exemption just asked.
		return substr( $vat, 0, 2 ) === $country && Flags::vies_check() && Checksum::tax_number( (string) $vat )
			&& 'invalid' === Services::vat_state( (string) $vat );
	}

	/**
	 * What the checkout tells a company about the VAT on its order: reverse charge
	 * when the order is VAT exempt, why it is not when its tax number is entered
	 * without the country code (Checksum::domestic_tax_number()) or is no VAT
	 * registration (vat_not_registered()), nothing otherwise.
	 *
	 * @param string $country Billing country.
	 * @param string $vat     The VAT number entered.
	 * @param bool   $exempt  Whether the order is VAT exempt.
	 * @return string|null
	 */
	public static function vat_notice( $country, $vat, $exempt ) {
		if ( $exempt ) {
			return __( 'Reverse charge: the order is without VAT, the buyer accounts for it.', 'woolab-ic-dic' );
		}

		if ( $vat && Checksum::domestic_tax_number( (string) $country, (string) preg_replace( '/\s+/', '', (string) $vat ) ) ) {
			/* translators: %s: country code, e.g. "PL" */
			return sprintf( __( 'This tax number is valid. Without the country code (%s) it is not an EU VAT ID, so the order is placed with VAT.', 'woolab-ic-dic' ), $country );
		}

		if ( $vat && self::vat_not_registered( $country, $vat ) ) {
			/* translators: %s: field label, e.g. "VAT ID" */
			return sprintf( __( 'This %s is not registered for VAT in the EU (VIES), so the order is placed with VAT.', 'woolab-ic-dic' ), woolab_icdic_labels( $country )['dic'] );
		}

		return null;
	}

	/**
	 * Whether a VAT number exempts the order: VIES says valid, or cannot answer and
	 * the store lets that pass.
	 *
	 * @param string $vat_num           VAT number.
	 * @param bool   $ignore_check_fail Whether a VIES outage still grants it.
	 * @return bool
	 */
	public static function vat_number_exempt( $vat_num, $ignore_check_fail ) {
		return self::from_state( Services::vat_state( (string) $vat_num ), (bool) $ignore_check_fail );
	}

	/**
	 * Set the customer's VAT exemption and remember that this plugin set it.
	 *
	 * @param bool $exempt Whether the customer is VAT exempt.
	 */
	public static function set( $exempt ): void {
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
	public static function reset(): void {
		if ( WC()->session && 'yes' === WC()->session->get( 'woolab_icdic_vat_exempt' ) ) {
			self::set( false );
		}
	}

	/**
	 * Set the VAT exemption from the customer's saved details when the cart or the
	 * checkout page loads, so the totals are right before anything is typed.
	 *
	 * On `wp`, which neither admin-ajax nor the Store API reach: the checkouts set it
	 * themselves while the shopper types (validate_vat_exempt_for_company(), the
	 * Store API hooks below), and every other page has no totals to show — it used to
	 * run on `init` for every request, with a VIES call each time the result was not
	 * cached.
	 */
	public static function set_vat_exempt_for_customer(): void {
		// Not WooCommerce's own AJAX (wc-ajax), which reaches `wp` too: the checkout set
		// the exemption while the shopper typed (validate_vat_exempt_for_company()), and
		// the saved details would undo it on placing the order where is_checkout() is
		// true for wc-ajax (Fluid Checkout).
		if ( ! empty( $_GET['wc-ajax'] ) || ( ! is_cart() && ! is_checkout() ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only whether it is set.
			return;
		}

		$customer = WC()->customer;

		if ( empty( $customer ) || ! Flags::vat_exempt() ) {
			return;
		}

		$vat_num      = null;
		$wc_countries = new \WC_Countries();
		$base_country = $wc_countries->get_base_country();
		/**
		 * The shop's country for the VAT exemption: an order from it is never exempt.
		 *
		 * @since 1.7.0
		 * @param string $base_country WooCommerce's store country.
		 */
		$base_country          = apply_filters( 'woolab_icdic_base_country', $base_country );
		$ignore_vat_check_fail = Flags::ignore_check_fail();
		$is_vat_exempt         = false;
		// A core customer property: get_meta() returned '' for it, so this never ran.
		$billing_country = $customer->get_billing_country();

		if ( ! empty( $billing_country ) && $billing_country !== $base_country ) {
			$vat_num = self::vat_number( (string) $billing_country, (string) $customer->get_meta( 'billing_dic' ), (string) $customer->get_meta( 'billing_dic_dph' ) );
		}

		if ( ! empty( $vat_num ) ) {
			$is_vat_exempt = self::vat_number_exempt( $vat_num, $ignore_vat_check_fail );
		}

		/**
		 * Whether a customer is VAT exempt when the cart or checkout loads.
		 *
		 * @since 1.7.0
		 * @param bool         $is_vat_exempt Whether VIES exempts the saved VAT number.
		 * @param string|null  $vat_num       The saved VAT number, null for the shop's own country.
		 * @param \WC_Customer $customer      Customer.
		 */
		$is_vat_exempt = apply_filters( 'woolab_icdic_vat_exempt_customer', $is_vat_exempt, $vat_num, $customer );

		self::set( $is_vat_exempt );
	}

	/**
	 * The exemption while the shopper fills in the classic checkout, on every order
	 * review update.
	 *
	 * @param string $post_data The checkout form, URL-encoded.
	 */
	public static function validate_vat_exempt_for_company( $post_data ): void {
		if ( ! Flags::vat_exempt() ) {
			return;
		}

		$data = array();
		wp_parse_str( $post_data, $data );
		$wc_countries = new \WC_Countries();
		$base_country = $wc_countries->get_base_country();
		/** This filter is documented in src/Checkout/VatExempt.php */
		$base_country          = apply_filters( 'woolab_icdic_base_country', $base_country );
		$country               = $data['billing_country'] ?? '';
		$vat_countries         = $wc_countries->get_european_union_countries( 'eu_vat' );
		$is_eu_country         = in_array( $country, $vat_countries, true );
		$ignore_vat_check_fail = Flags::ignore_check_fail();

		if ( empty( $country ) || $country === $base_country || ! $is_eu_country ) {
			// Skip check if company's billing country is the same as store's country or if company's billing country is not EU VAT country.
			self::reset();
			return;
		}

		$vat_num = self::vat_number( (string) $country, (string) ( $data['billing_dic'] ?? '' ), (string) ( $data['billing_dic_dph'] ?? '' ) );

		$is_company = ! isset( $data['billing_iscomp'] ) || 1 == $data['billing_iscomp']; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- the checkbox posts "1"; loose as in 1.x.

		if ( ! empty( $vat_num ) && $is_company ) {
			$is_vat_exempt = self::vat_number_exempt( $vat_num, $ignore_vat_check_fail );

			/**
			 * Whether a company's order is VAT exempt.
			 *
			 * @since 1.7.0
			 * @param bool                $is_vat_exempt Whether VIES exempts the VAT number.
			 * @param array<string,mixed> $data          The checkout form: billing_country, billing_dic, billing_dic_dph, billing_iscomp, …
			 */
			$is_vat_exempt = apply_filters( 'woolab_icdic_vat_exempt_company', $is_vat_exempt, $data );

			self::set( $is_vat_exempt );

			// Shown with the order review WooCommerce is sending back.
			$notice = self::vat_notice( $country, $vat_num, $is_vat_exempt );
			if ( $notice ) {
				wc_add_notice( $notice, 'notice' );
			}
		} else {
			self::set( false );
		}
	}

	/**
	 * The block counterpart of validate_vat_exempt_for_company().
	 *
	 * Same rule, same filter, same payload shape — only the source of the data differs,
	 * because there is no serialized order-review form to parse here.
	 *
	 * @param string $country    Billing country.
	 * @param string $dic        DIČ field value.
	 * @param string $dic_dph    IČ DPH field value.
	 * @param bool   $is_company "Buying as a company" ticked, or no toggle.
	 */
	public static function blocks_apply( $country, $dic, $dic_dph, $is_company ): void {

		if ( ! Flags::vat_exempt() || ! WC()->customer ) {
			return;
		}

		$wc_countries = new \WC_Countries();
		/** This filter is documented in src/Checkout/VatExempt.php */
		$base_country  = apply_filters( 'woolab_icdic_base_country', $wc_countries->get_base_country() );
		$vat_countries = $wc_countries->get_european_union_countries( 'eu_vat' );

		// The store's own country, or not an EU VAT country: no intra-EU exemption.
		if ( empty( $country ) || $country === $base_country || ! in_array( $country, $vat_countries, true ) ) {
			self::reset();
			if ( WC()->session ) {
				WC()->session->set( 'woolab_icdic_vat_notice', null );
			}
			return;
		}

		$vat_num = self::vat_number( (string) $country, (string) $dic, (string) $dic_dph );

		$is_vat_exempt = ( ! empty( $vat_num ) && $is_company )
			? self::vat_number_exempt( $vat_num, Flags::ignore_check_fail() )
			: false;

		$data = array(
			'billing_country' => $country,
			'billing_dic'     => $dic,
			'billing_dic_dph' => $dic_dph,
			'billing_iscomp'  => $is_company ? 1 : 0,
		);

		/** This filter is documented in src/Checkout/VatExempt.php */
		$is_vat_exempt = (bool) apply_filters( 'woolab_icdic_vat_exempt_company', $is_vat_exempt, $data );

		self::set( $is_vat_exempt );

		// For CartExtension::data(), which blocks.js shows as a notice.
		if ( WC()->session ) {
			WC()->session->set( 'woolab_icdic_vat_notice', $is_company ? self::vat_notice( $country, (string) $vat_num, $is_vat_exempt ) : null );
		}
	}

	/**
	 * The exemption for the posted additional fields of a Store API checkout request.
	 *
	 * @param string              $country Billing country.
	 * @param array<string,mixed> $fields  'kybernaut/…' => value.
	 */
	public static function blocks_apply_from_fields( $country, array $fields ): void {
		$dic_field = Blocks::field_for( 'billing_dic', $country );

		self::blocks_apply(
			$country,
			wc_clean( (string) ( $fields[ $dic_field ] ?? '' ) ),
			wc_clean( (string) ( $fields['kybernaut/dic-dph'] ?? '' ) ),
			! Flags::toggle() || ! empty( $fields['kybernaut/iscomp'] )
		);
	}

	/**
	 * The exemption on placing the order, from what was posted. Runs before
	 * WooCommerce calculates the totals and compares them with what the shopper saw.
	 *
	 * @param \WC_Customer                          $customer Customer.
	 * @param \WP_REST_Request<array<string,mixed>> $request  Request.
	 */
	public static function blocks_customer_vat_exempt( $customer, $request ): void {
		$billing = (array) ( $request['billing_address'] ?? array() );
		$country = ! empty( $billing['country'] ) ? wc_clean( $billing['country'] ) : $customer->get_billing_country();

		self::blocks_apply_from_fields( $country, (array) ( $request['additional_fields'] ?? array() ) );
		self::blocks_remember();
	}

	/**
	 * The last exemption decision, outside the session.
	 *
	 * Store API requests run in parallel, and each works on the session as it was
	 * when it started. Before WooCommerce 10.9 the Checkout block sends a checkout
	 * PATCH (`__experimental_calc_totals`) on the same change that sends ours —
	 * a field hidden by the new country is cleared — and that PATCH recalculates
	 * the totals with the exemption it loaded, answers after ours and wins: the
	 * shopper is back in the shop's country and still sees no VAT. The PATCH carries
	 * neither the country nor the VAT fields, so it cannot decide again; it can
	 * read the decision fresh from here when it calculates.
	 *
	 * @return string Transient key, '' without a session.
	 */
	private static function blocks_key() {
		$id = WC()->session instanceof \WC_Session_Handler ? WC()->session->get_customer_unique_id() : '';
		return '' === $id ? '' : 'woolab_icdic_vat_exempt_' . md5( $id );
	}

	/**
	 * Remember the decision just applied to the cart customer.
	 */
	public static function blocks_remember(): void {
		$key = self::blocks_key();
		if ( ! $key || ! WC()->customer ) {
			return;
		}
		$exempt = WC()->customer->get_is_vat_exempt() ? 'yes' : 'no';
		// A database write only when the decision changes.
		if ( get_transient( $key ) !== $exempt ) {
			set_transient( $key, $exempt, DAY_IN_SECONDS );
		}
	}

	/**
	 * Before every Store API totals calculation: the decision remembered above.
	 */
	public static function blocks_restore_vat_exempt(): void {
		if ( ! WC()->is_store_api_request() || ! WC()->customer || ! Flags::vat_exempt() ) {
			return;
		}

		$key      = self::blocks_key();
		$remember = $key ? get_transient( $key ) : false;
		if ( false !== $remember ) {
			self::set( 'yes' === $remember );
		}
	}
}
