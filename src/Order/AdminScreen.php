<?php
/**
 * The company fields on the admin order screen, the "VAT number check failed"
 * warnings (order lists, order edit, the admin e-mail) and the VAT ID evidence.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Order;

/**
 * The admin order screen, HPOS and posts.
 */
final class AdminScreen {

	/**
	 * Hook the order screen and the warnings.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_admin_billing_fields', array( self::class, 'admin_billing_fields' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( self::class, 'process_shop_order' ), 10, 2 );
		// HPOS off, and its HPOS counterpart.
		add_action( 'manage_shop_order_posts_custom_column', array( self::class, 'show_check_failed_notice_on_orders_table' ), 20, 2 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( self::class, 'show_check_failed_notice_on_orders_table_hpos' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( self::class, 'show_check_failed_notice_on_order_edit' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( self::class, 'show_vat_evidence' ), 20 );
		add_action( 'woocommerce_email_order_details', array( self::class, 'show_check_failed_notice_on_admin_email' ), 5, 3 );
	}

	/**
	 * Add custom user fields to admin order screen.
	 *
	 * @param array<string,array<string,mixed>> $fields Billing fields.
	 * @return array<string,array<string,mixed>>
	 */
	public static function admin_billing_fields( $fields ) {

		global $pagenow;

		// Return empty fields everytime someone wants admin billing fields.
		$fields += array(
			'billing_ic'      => array(
				'label' => woolab_icdic_labels( '' )['ic'],
				'show'  => false,
			),
			'billing_dic'     => array(
				'label' => woolab_icdic_labels( '' )['dic'],
				'show'  => false,
			),
			'billing_dic_dph' => array(
				'label' => woolab_icdic_labels( 'SK' )['dic_dph'],
				'show'  => false,
			),
		);

		$order = null;

		// HPOS ready.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- reads which order the screen shows, as in 1.x.
		if ( 'admin.php' === $pagenow && isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] && isset( $_GET['action'] ) && 'edit' === $_GET['action'] && isset( $_GET['id'] ) ) {
			$order = wc_get_order( $_GET['id'] );
		} else { // Either edit.php or post.php - double checked by the next conditional.
			// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput

			global $post;
			if ( null === $post ) {
				return $fields;
			}

			$order = wc_get_order( $post->ID );
		}

		if ( $order instanceof \WC_Order ) {

			$country = $order->get_billing_country();
			$labels  = woolab_icdic_labels( $country );

			$fields['billing_ic']['label']  = $labels['ic'];
			$fields['billing_dic']['label'] = $labels['dic'];

			$fields['billing_ic']['value']      = $order->get_meta( '_billing_ic', true );
			$fields['billing_dic']['value']     = $order->get_meta( '_billing_dic', true );
			$fields['billing_dic_dph']['value'] = $order->get_meta( '_billing_dic_dph', true );

			// Hide the VAT reg. no. field if not country SK.
			if ( 'SK' !== $country ) {
				$fields['billing_dic_dph']['show'] = false;
			}
		}

		return $fields;
	}

	/**
	 * Save meta data / custom fields when editing order in admin screen.
	 *
	 * @param int         $post_id Post ID.
	 * @param object|null $post    Post object.
	 */
	public static function process_shop_order( $post_id, $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the hook's arguments.

		if ( empty( $_POST['woocommerce_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- a nonce, compared as posted (as WooCommerce does).
			return;
		}

		// The nonce is only rendered on the gated order-edit screen, but verify the
		// capability explicitly so this handler can never write order/user meta for
		// a user who cannot edit shop orders.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$order = wc_get_order( $post_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		/**
		 * Copy the company fields edited on the order to the customer's profile.
		 *
		 * @since 1.2.0
		 * @param bool $update Whether to update the user meta.
		 */
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

			if ( $update_user_meta && 0 !== $user_id ) { // Update if not guest.
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
	 *
	 * @param string $column  Column ID.
	 * @param int    $post_id Post ID.
	 */
	public static function show_check_failed_notice_on_orders_table( $column, $post_id ): void {
		if ( 'order_number' !== $column ) {
			return;
		}

		$order        = wc_get_order( $post_id );
		$check_failed = ( $order instanceof \WC_Order )
			? ( 'yes' === $order->get_meta( 'woolab_icdic_vat_check_fail_ignored' ) )
			: false;

		if ( ! $check_failed ) {
			return;
		}

		?>

	<br>
	<strong style="color: #dba617;">
		<?php esc_html_e( 'Verification of VAT number has failed.', 'woolab-ic-dic' ); ?>
	</strong>

		<?php
	}

	/**
	 * Show notice about Business ID or VAT number check error
	 * below order number on orders table view.
	 *
	 * @param string    $column Column ID.
	 * @param \WC_Order $order  Order.
	 */
	public static function show_check_failed_notice_on_orders_table_hpos( $column, $order ): void {
		if ( 'order_number' !== $column ) {
			return;
		}

		$check_failed = ( $order instanceof \WC_Order )
			? ( 'yes' === $order->get_meta( 'woolab_icdic_vat_check_fail_ignored' ) )
			: false;

		if ( ! $check_failed ) {
			return;
		}

		?>

	<strong style="color: #dba617;">
		<?php esc_html_e( 'Verification of VAT number has failed.', 'woolab-ic-dic' ); ?>
	</strong>
	<br>

		<?php
	}

	/**
	 * Show notice about Business ID or VAT number check error
	 * below billing number fields on order edit screen.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function show_check_failed_notice_on_order_edit( $order ): void {
		$check_failed = ( 'yes' === $order->get_meta( 'woolab_icdic_vat_check_fail_ignored' ) );

		if ( ! $check_failed ) {
			return;
		}

		?>

	<div class="notice notice-warning inline">
		<p>
			<strong><?php esc_html_e( 'Caution!', 'woolab-ic-dic' ); ?></strong><br>
			<?php esc_html_e( 'Verification of VAT number has failed.', 'woolab-ic-dic' ); ?>
			<?php esc_html_e( 'Please, make sure the VAT number is valid before processing the order.', 'woolab-ic-dic' ); ?>
		</p>
	</div>

		<?php
	}

	/**
	 * The VAT ID check kept with the order (Evidence), under the billing address.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function show_vat_evidence( $order ): void {
		$evidence = $order instanceof \WC_Order ? $order->get_meta( Evidence::META ) : null;

		if ( ! is_array( $evidence ) || empty( $evidence['vat_number'] ) ) {
			return;
		}

		$date     = (string) ( empty( $evidence['requested_at'] ) ? ( $evidence['checked_at'] ?? '' ) : $evidence['requested_at'] );
		$time     = $date ? strtotime( $date ) : false;
		$rows     = array(
			_x( 'VAT ID', 'EU VAT number field', 'woolab-ic-dic' ) => $evidence['vat_number'],
			__( 'Result', 'woolab-ic-dic' )              => Evidence::state_label( (string) ( $evidence['state'] ?? '' ) ),
			__( 'Consultation number', 'woolab-ic-dic' ) => $evidence['consultation_number'] ?? '',
			__( 'Date of the check', 'woolab-ic-dic' )   => $time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '',
			__( 'Name', 'woolab-ic-dic' )                => $evidence['name'] ?? '',
			__( 'Address', 'woolab-ic-dic' )             => $evidence['address'] ?? '',
			__( 'Reason', 'woolab-ic-dic' )              => Evidence::reason_label( (string) ( $evidence['reason'] ?? '' ) ),
			__( 'IP address country', 'woolab-ic-dic' )  => $evidence['ip_country'] ?? '',
			__( 'Billing country', 'woolab-ic-dic' )     => $evidence['billing_country'] ?? '',
			__( 'VAT ID country', 'woolab-ic-dic' )      => $evidence['vat_country'] ?? '',
		);
		$mismatch = Evidence::mismatch( $evidence );

		?>
	<div class="woolab-icdic-vat-evidence">
		<h3><?php esc_html_e( 'VAT ID check', 'woolab-ic-dic' ); ?></h3>
		<p>
		<?php
		foreach ( array_filter( $rows, static fn ( $value ) => '' !== (string) $value ) as $label => $value ) {
			echo '<strong>' . esc_html( $label ) . ':</strong> ' . nl2br( esc_html( (string) $value ) ) . '<br>';
		}
		?>
		</p>
		<?php if ( $mismatch ) : ?>
		<p class="woolab-icdic-vat-evidence-mismatch" style="color:#996800;"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php echo esc_html( $mismatch ); ?></p>
		<?php endif; ?>
	</div>
		<?php
	}

	/**
	 * Show notice about Business ID or VAT number check error
	 * in the admin's order e-mail.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether the e-mail goes to the admin.
	 * @param bool      $plain_text    Whether the e-mail is plain text.
	 */
	public static function show_check_failed_notice_on_admin_email( $order, $sent_to_admin, $plain_text ): void {
		if ( ! $order instanceof \WC_Order || ! $sent_to_admin ) {
			return;
		}

		$evidence = $order->get_meta( Evidence::META );
		if ( is_array( $evidence ) && ! empty( $evidence['vat_number'] ) ) {
			/* translators: 1: VAT ID, 2: reason, e.g. "Reverse charge, without VAT" */
			$line = sprintf( __( 'VAT ID %1$s: %2$s.', 'woolab-ic-dic' ), $evidence['vat_number'], Evidence::reason_label( (string) ( $evidence['reason'] ?? '' ) ) );
			if ( ! empty( $evidence['consultation_number'] ) ) {
				/* translators: %s: VIES consultation number */
				$line .= ' ' . sprintf( __( 'Consultation number: %s.', 'woolab-ic-dic' ), $evidence['consultation_number'] );
			}
			$mismatch = Evidence::mismatch( $evidence );
			if ( $mismatch ) {
				$line .= ' ' . $mismatch;
			}
			echo $plain_text ? esc_html( $line ) . "\n\n" : '<p>' . esc_html( $line ) . '</p>';
		}

		$check_failed = ( 'yes' === $order->get_meta( 'woolab_icdic_vat_check_fail_ignored' ) );

		if ( ! $check_failed ) {
			return;
		}

		ob_start();

		?>

	<p>
		<strong><?php esc_html_e( 'Caution!', 'woolab-ic-dic' ); ?></strong><br>
		<?php esc_html_e( 'Verification of VAT number has failed.', 'woolab-ic-dic' ); ?>
		<?php esc_html_e( 'Please, make sure the VAT number is valid before processing the order.', 'woolab-ic-dic' ); ?>
	</p>

		<?php

		$content = ob_get_clean();

		// The escaped markup above; strip_tags() keeps its whitespace, as in 1.x.
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		echo ( $plain_text )
			? strip_tags( $content )
			: $content;
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}
}
