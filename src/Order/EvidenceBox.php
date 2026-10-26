<?php
/**
 * The VAT ID check on the order screen and the orders list.
 *
 * @package KybernautIcDic
 * @since 2.0.0
 */

namespace KybernautIcDic\Order;

/**
 * The "VAT ID check" box on the order screen (Evidence, its earlier checks and a
 * button for Evidence::revalidate()), and a reverse-charge icon after the billing
 * address on the orders list.
 */
final class EvidenceBox {

	/**
	 * The AJAX action of the box's button.
	 */
	public const AJAX = 'woolab_icdic_revalidate';

	/**
	 * Hook the box and the icon, for HPOS and for orders kept as posts.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );
		add_action( 'wp_ajax_' . self::AJAX, array( self::class, 'ajax_revalidate' ) );
		// After WooCommerce's own cell (priority 10): HPOS draws it on this action, not
		// on woocommerce_shop_order_list_table_custom_column, which fires before.
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( self::class, 'column' ), 20, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( self::class, 'column' ), 20, 2 );
	}

	/**
	 * The box's button: Evidence::revalidate() without saving the order, for a VAT ID
	 * that was not edited (the script saves the order with the order action when it
	 * was).
	 */
	public static function ajax_revalidate(): void {
		$id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		check_ajax_referer( self::AJAX . '-' . $id );

		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( null, 403 );
		}

		Evidence::revalidate( $order );
		wp_send_json_success();
	}

	/**
	 * Add the box to the order screen.
	 */
	public static function add_meta_box(): void {
		add_meta_box( 'woolab-icdic-vat-evidence', __( 'VAT ID check', 'woolab-ic-dic' ), array( self::class, 'render' ), wc_get_page_screen_id( 'shop-order' ), 'side', 'default' );
	}

	/**
	 * Whether the order is without VAT by reverse charge: as its evidence says, or for
	 * an order from before the evidence, exempt with a VAT ID.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function reverse_charge( \WC_Order $order ): bool {
		$evidence = $order->get_meta( Evidence::META );

		if ( is_array( $evidence ) && ! empty( $evidence['reason'] ) ) {
			return 'reverse_charge' === $evidence['reason'];
		}

		return 'yes' === $order->get_meta( 'is_vat_exempt' ) && '' !== Evidence::vat_number( $order );
	}

	/**
	 * The box: the latest check, the button and the earlier checks. An order without a
	 * VAT ID says so; one with a VAT ID but no check yet (e.g. created in the admin)
	 * gets the button.
	 *
	 * @param \WP_Post|\WC_Order $item The post (posts storage) or the order (HPOS).
	 */
	public static function render( $item ): void {
		$order = $item instanceof \WP_Post ? wc_get_order( $item->ID ) : $item;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$evidence = $order->get_meta( Evidence::META );
		$evidence = is_array( $evidence ) && ! empty( $evidence['vat_number'] ) ? $evidence : null;
		$vat      = $evidence ? $evidence['vat_number'] : Evidence::vat_number( $order );

		echo '<div class="woolab-icdic-vat-evidence">';

		if ( '' === $vat ) {
			echo '<p class="description">' . esc_html__( 'The order has no VAT ID.', 'woolab-ic-dic' ) . '</p></div>';
			return;
		}

		if ( $evidence ) {
			self::render_evidence( $evidence );
		} else {
			/* translators: %s: VAT ID */
			echo '<p>' . esc_html( sprintf( __( 'VAT ID %s has not been checked yet.', 'woolab-ic-dic' ), $vat ) ) . '</p>';
		}

		?>
		<p class="woolab-icdic-revalidate-row">
			<button type="button" class="button woolab-icdic-revalidate" data-action="<?php echo esc_attr( Evidence::ACTION ); ?>" data-order="<?php echo esc_attr( (string) $order->get_id() ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX . '-' . $order->get_id() ) ); ?>" data-error="<?php esc_attr_e( 'The VAT ID could not be checked. Reload the page and try again.', 'woolab-ic-dic' ); ?>"><?php esc_html_e( 'Validate again in VIES', 'woolab-ic-dic' ); ?></button>
		</p>
		<p class="description"><?php esc_html_e( 'Sets or removes the VAT by the answer. An edited VAT ID is saved with the order first.', 'woolab-ic-dic' ); ?></p>
		<?php

		$history = $order->get_meta( Evidence::HISTORY );
		if ( is_array( $history ) && $history ) {
			echo '<details class="woolab-icdic-vat-evidence-history"><summary>';
			/* translators: %d: number of earlier checks */
			echo esc_html( sprintf( _n( '%d earlier check', '%d earlier checks', count( $history ), 'woolab-ic-dic' ), count( $history ) ) );
			echo '</summary><ul>';
			foreach ( $history as $earlier ) {
				if ( ! is_array( $earlier ) ) {
					continue;
				}
				$parts = array_filter(
					array(
						self::date( $earlier ),
						$earlier['vat_number'] ?? '',
						Evidence::state_label( (string) ( $earlier['state'] ?? '' ) ),
						Evidence::reason_label( (string) ( $earlier['reason'] ?? '' ) ),
						$earlier['consultation_number'] ?? '',
					)
				);
				echo '<li>' . esc_html( implode( ' · ', $parts ) ) . '</li>';
			}
			echo '</ul></details>';
		}

		echo '</div>';
	}

	/**
	 * The latest check: the result as a badge, then its details.
	 *
	 * @param array<string,string> $evidence The evidence.
	 */
	private static function render_evidence( array $evidence ): void {
		$state     = (string) ( $evidence['state'] ?? '' );
		$countries = array_filter(
			array(
				/* translators: %s: country code */
				empty( $evidence['billing_country'] ) ? '' : sprintf( __( 'billing %s', 'woolab-ic-dic' ), $evidence['billing_country'] ),
				/* translators: %s: country code */
				empty( $evidence['vat_country'] ) ? '' : sprintf( __( 'VAT ID %s', 'woolab-ic-dic' ), $evidence['vat_country'] ),
				/* translators: %s: country code */
				empty( $evidence['ip_country'] ) ? '' : sprintf( __( 'IP address %s', 'woolab-ic-dic' ), $evidence['ip_country'] ),
			)
		);
		$rows      = array(
			_x( 'VAT ID', 'EU VAT number field', 'woolab-ic-dic' ) => $evidence['vat_number'],
			__( 'Consultation number', 'woolab-ic-dic' ) => $evidence['consultation_number'] ?? '',
			__( 'Date of the check', 'woolab-ic-dic' )   => self::date( $evidence ),
			__( 'Name', 'woolab-ic-dic' )                => $evidence['name'] ?? '',
			__( 'Address', 'woolab-ic-dic' )             => $evidence['address'] ?? '',
			__( 'Countries', 'woolab-ic-dic' )           => implode( ', ', $countries ),
		);
		$mismatch  = Evidence::mismatch( $evidence );

		?>
		<p class="woolab-icdic-vat-evidence-result">
			<mark class="woolab-icdic-badge woolab-icdic-badge--<?php echo esc_attr( 'valid' === $state ? 'valid' : ( 'unverifiable' === $state ? 'unverifiable' : 'invalid' ) ); ?>"><?php echo esc_html( Evidence::state_label( $state ) ); ?></mark>
			<?php echo esc_html( Evidence::reason_label( (string) ( $evidence['reason'] ?? '' ) ) ); ?>
		</p>
		<dl>
		<?php foreach ( array_filter( $rows, static fn ( $value ) => '' !== (string) $value ) as $label => $value ) : ?>
			<dt><?php echo esc_html( $label ); ?></dt>
			<dd><?php echo nl2br( esc_html( (string) $value ) ); ?></dd>
		<?php endforeach; ?>
		</dl>
		<?php if ( $mismatch ) : ?>
		<p class="woolab-icdic-vat-evidence-mismatch"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php echo esc_html( $mismatch ); ?></p>
			<?php
		endif;
	}

	/**
	 * When a check was made: VIES's date, else when the plugin recorded it.
	 *
	 * @param array<string,string> $evidence The evidence.
	 */
	private static function date( array $evidence ): string {
		$date = (string) ( empty( $evidence['requested_at'] ) ? ( $evidence['checked_at'] ?? '' ) : $evidence['requested_at'] );
		$time = $date ? strtotime( $date ) : false;

		return $time ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '';
	}

	/**
	 * The billing address cell: the icon at its end when the order is without VAT by
	 * reverse charge.
	 *
	 * @param string        $column Column ID.
	 * @param \WC_Order|int $order  The order (HPOS) or its post ID.
	 */
	public static function column( $column, $order ): void {
		if ( 'billing_address' !== $column ) {
			return;
		}

		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof \WC_Order || ! self::reverse_charge( $order ) ) {
			return;
		}

		// The order script moves it before WooCommerce's "via …" line.
		echo ' <span class="woolab-icdic-reverse-charge"><span class="dashicons dashicons-update" title="' . esc_attr__( 'Reverse charge, without VAT', 'woolab-ic-dic' ) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Reverse charge, without VAT', 'woolab-ic-dic' ) . '</span></span>';
	}
}
