<?php
/**
 * The company identifiers in addresses and the front-end order details.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Order;

/**
 * The {billing_ic}, {billing_dic} and {billing_dic_dph} address tokens, and the
 * identifiers block of the order details.
 */
final class Display {

	/**
	 * Whether core's order/order-details-customer.php is being rendered.
	 *
	 * @var bool
	 */
	private static $inside = false;

	/**
	 * Hook the address formats and the order details.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_my_account_my_address_formatted_address', array( self::class, 'my_address_formatted_address' ), 10, 3 );
		add_filter( 'woocommerce_localisation_address_formats', array( self::class, 'localisation_address_formats' ) );
		add_filter( 'woocommerce_formatted_address_replacements', array( self::class, 'formatted_address_replacements' ), 10, 2 );
		add_filter( 'woocommerce_order_formatted_billing_address', array( self::class, 'order_formatted_billing_address' ), 10, 2 );
		add_action( 'woocommerce_before_template_part', array( self::class, 'order_details_enter' ) );
		add_action( 'woocommerce_after_template_part', array( self::class, 'order_details_leave' ) );
		add_action( 'woocommerce_order_details_after_customer_address', array( self::class, 'order_details_ids' ), 10, 2 );
	}

	/**
	 * The identifiers of a My Account address.
	 *
	 * @param array<string,mixed> $fields      Address fields.
	 * @param int                 $customer_id Customer ID.
	 * @param string              $name        billing or shipping.
	 * @return array<string,mixed>
	 */
	public static function my_address_formatted_address( $fields, $customer_id, $name ) {
		$fields += array(
			'billing_ic'  => get_user_meta( $customer_id, $name . '_ic', true ),
			'billing_dic' => get_user_meta( $customer_id, $name . '_dic', true ),
		);
		// $single must be true: with '' this returned an array, never 'SK'.
		if ( 'SK' === get_user_meta( $customer_id, $name . '_country', true ) ) {
			$fields += array(
				'billing_dic_dph' => get_user_meta( $customer_id, $name . '_dic_dph', true ),
			);
		}

		return $fields;
	}

	/**
	 * Add ICO, DIC fields to customers address for all EU countries including Monaco.
	 *
	 * @since 1.7.3
	 * @param array<string,string> $address_formats Address formats array.
	 * @return array<string,string>
	 */
	public static function localisation_address_formats( $address_formats ) {

		$euvat = WC()->countries->get_european_union_countries( 'eu_vat' ); // Eu VAT Countries including Monaco.

		foreach ( $euvat as $country_code ) {
			if ( isset( $address_formats[ $country_code ] ) ) {
				$address_formats[ $country_code ] .= "\n{billing_ic}\n{billing_dic}";
				if ( 'SK' === $country_code ) {
					$address_formats[ $country_code ] .= "\n{billing_dic_dph}";
				}
			}
		}
		return $address_formats;
	}

	/**
	 * The address token values: the label, then the number; nothing without one.
	 *
	 * @param array<string,string> $replace Token => value.
	 * @param array<string,mixed>  $args    Address.
	 * @return array<string,string>
	 */
	public static function formatted_address_replacements( $replace, $args ) {

		$labels  = woolab_icdic_labels( $args['country'] ?? '' );
		$replace = (array) $replace;

		foreach ( array(
			'ic'      => 'billing_ic',
			'dic'     => 'billing_dic',
			'dic_dph' => 'billing_dic_dph',
		) as $key => $token ) {
			foreach ( array(
				''       => $token,
				'_upper' => $token . '_upper',
			) as $suffix => $arg ) {
				$line     = isset( $args[ $arg ] ) && '' != $args[ $arg ] ? woolab_icdic_label_prefix( $labels[ $key ] ) . esc_html( $args[ $arg ] ) : ''; // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- null and false are empty too.
				$replace += array( '{' . $token . $suffix . '}' => $suffix ? strtoupper( $line ) : $line );
			}
		}

		return $replace;
	}

	/**
	 * The identifiers as address lines of an order's billing address.
	 *
	 * @param array<string,mixed> $address Address.
	 * @param \WC_Order           $order   Order.
	 * @return array<string,mixed>
	 */
	public static function order_formatted_billing_address( $address, $order ) {

		// The front-end order details print the identifiers as their own block
		// (order_details_ids()), so they are not address lines there.
		if ( self::in_order_details() ) {
			return $address;
		}

		$address += array(
			'billing_ic'      => $order->get_meta( '_billing_ic' ),
			'billing_dic'     => $order->get_meta( '_billing_dic' ),
			'billing_dic_dph' => $order->get_meta( '_billing_dic_dph' ),
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
	public static function in_order_details( $set = null ) {
		if ( null !== $set ) {
			self::$inside = (bool) $set;
		}
		return self::$inside;
	}

	/**
	 * Enter the customer details section.
	 *
	 * @param string $template_name Template being rendered.
	 */
	public static function order_details_enter( $template_name ): void {
		if ( 'order/order-details-customer.php' === $template_name ) {
			self::in_order_details( true );
		}
	}

	/**
	 * Leave the customer details section.
	 *
	 * @param string $template_name Template rendered.
	 */
	public static function order_details_leave( $template_name ): void {
		if ( 'order/order-details-customer.php' === $template_name ) {
			self::in_order_details( false );
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
	 * @param string    $address_type billing or shipping.
	 * @param \WC_Order $order        Order.
	 */
	public static function order_details_ids( $address_type, $order ): void {
		if ( 'billing' !== $address_type || ! $order instanceof \WC_Order ) {
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
}
