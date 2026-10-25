<?php
/**
 * The Store API cart extension: the VAT update callback blocks.js calls with
 * extensionCartUpdate(), and the cart data it reads back.
 *
 * @package KybernautIcDic
 * @since 2.0.0
 */

namespace KybernautIcDic\Checkout;

/**
 * Namespace 'woolab-ic-dic' of the cart endpoint.
 */
final class CartExtension {

	/**
	 * Hook the registration.
	 */
	public static function register(): void {
		add_action( 'woocommerce_init', array( self::class, 'register_update_callback' ) );
	}

	/**
	 * Register the extensionCartUpdate() callback, and the cart data it sends back.
	 */
	public static function register_update_callback(): void {
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'woolab-ic-dic',
				'callback'  => array( self::class, 'update_vat_exempt' ),
			)
		);

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => 'cart',
				'namespace'       => 'woolab-ic-dic',
				'data_callback'   => array( self::class, 'data' ),
				'schema_callback' => array( self::class, 'schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Live exemption while the shopper types: blocks.js sends the VAT fields with
	 * extensionCartUpdate(), WooCommerce runs this, recalculates the cart and returns
	 * the new totals. The billing country comes with them: with "Use same address for
	 * billing" ticked, WooCommerce 10.0 pushes only the shipping address, so the cart
	 * customer's billing country is stale until the order is placed. It only steers
	 * this preview — the order POST decides again from the real address
	 * (VatExempt::blocks_customer_vat_exempt()).
	 *
	 * Since WooCommerce 10.9 the checkout PATCH no longer builds a draft order, so
	 * woocommerce_store_api_checkout_update_order_from_request does not run while the
	 * form is filled; this is the documented route for totals that depend on input.
	 *
	 * @param array<string,mixed> $data 'fields' => array( 'kybernaut/…' => value ), 'country' => billing country, 'addresses' => the shipping and billing address as typed (save_address()).
	 */
	public static function update_vat_exempt( $data ): void {
		if ( ! WC()->customer ) {
			return;
		}

		foreach ( isset( $data['addresses'] ) && is_array( $data['addresses'] ) ? $data['addresses'] : array() as $address ) {
			if ( is_array( $address ) ) {
				self::save_address( $address );
			}
		}

		$country = isset( $data['country'] ) ? strtoupper( wc_clean( (string) $data['country'] ) ) : '';
		if ( ! array_key_exists( $country, WC()->countries->get_allowed_countries() ) ) {
			$country = WC()->customer->get_billing_country();
		}

		VatExempt::blocks_apply_from_fields( $country, isset( $data['fields'] ) ? (array) $data['fields'] : array() );
		VatExempt::blocks_remember();
	}

	/**
	 * The address an ARES / Finančná správa autofill just wrote, sent with the VAT
	 * update it triggers: the Checkout block saves the address only after a pause, and
	 * this update would otherwise answer with the old one and the block store it over
	 * the filled one. Saving it here costs no request of its own.
	 *
	 * @param array<string,mixed> $address 'type' => 'shipping'|'billing', and the address fields.
	 */
	private static function save_address( $address ): void {
		$type = $address['type'] ?? '';
		if ( 'shipping' !== $type && 'billing' !== $type ) {
			return;
		}

		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email' ) as $key ) {
			$setter = 'set_' . $type . '_' . $key;
			if ( ! isset( $address[ $key ] ) || ! is_callable( array( WC()->customer, $setter ) ) ) {
				continue;
			}
			$value = wc_clean( (string) $address[ $key ] );
			if ( ( 'country' === $key && ! array_key_exists( $value, WC()->countries->get_allowed_countries() ) ) || ( 'email' === $key && '' !== $value && ! is_email( $value ) ) ) {
				continue;
			}
			WC()->customer->$setter( $value );
		}
	}

	/**
	 * The notice blocks.js shows (VatExempt::vat_notice()), or null.
	 *
	 * @return array{vat_notice:string|null}
	 */
	public static function data() {
		$session = WC()->session;
		$notice  = $session ? $session->get( 'woolab_icdic_vat_notice' ) : null;

		return array( 'vat_notice' => is_string( $notice ) ? $notice : null );
	}

	/**
	 * The schema of data().
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function schema() {
		return array(
			'vat_notice' => array(
				'description' => 'Why the order is not VAT exempt.',
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}
}
