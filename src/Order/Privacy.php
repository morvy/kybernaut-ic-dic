<?php
/**
 * The VAT ID evidence and the WordPress privacy tools: the suggested privacy policy
 * text, the personal data export, and the erasure, which keeps the evidence.
 *
 * @package KybernautIcDic
 * @since 2.0.0
 */

namespace KybernautIcDic\Order;

/**
 * Tools → Export / Erase Personal Data, and Settings → Privacy.
 */
final class Privacy {

	/**
	 * Exporter and eraser id.
	 */
	private const ID = 'woolab-ic-dic';

	/**
	 * Orders per page, as WooCommerce's own exporter.
	 */
	private const PAGE = 10;

	/**
	 * Hook the privacy tools.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
	}

	/**
	 * The suggested privacy policy text.
	 */
	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			'Kybernaut IČO DIČ',
			wp_kses_post(
				wpautop(
					__( 'When you order as a company and enter a VAT ID, we check it in the EU VIES register run by the European Commission (and a Business ID in the Czech ARES or the Slovak Finančná správa register), sending it with our own VAT ID. We keep the result with the order as the proof of the VAT treatment: the VAT ID, the result, the VIES consultation number and date, the name and address VIES returned, and the countries of the billing address, of the VAT ID and of the IP address your order was placed from (the IP address itself is the one stored with every order). It is part of our VAT records, kept for the period the tax law requires, and an erasure request does not delete it. Orders without a VAT ID get none of this.', 'woolab-ic-dic' )
				)
			)
		);
	}

	/**
	 * Register the exporter.
	 *
	 * @param array<string,array<string,mixed>> $exporters Exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public static function exporters( $exporters ) {
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'VAT ID checks', 'woolab-ic-dic' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string,array<string,mixed>> $erasers Erasers.
	 * @return array<string,array<string,mixed>>
	 */
	public static function erasers( $erasers ) {
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'VAT ID checks', 'woolab-ic-dic' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * One page of the orders of an e-mail address, as WooCommerce's order exporter
	 * finds them: by billing e-mail, and by the account with that e-mail.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page, from 1.
	 * @return array<int,\WC_Order>
	 */
	private static function orders( $email, $page ): array {
		$customer = array( $email );
		$user     = get_user_by( 'email', $email );
		if ( $user instanceof \WP_User ) {
			$customer[] = (int) $user->ID;
		}

		$orders = wc_get_orders(
			array(
				'customer' => $customer,
				'limit'    => self::PAGE,
				'page'     => (int) $page,
				'type'     => 'shop_order',
			)
		);

		return array_values( array_filter( is_array( $orders ) ? $orders : array(), static fn ( $order ) => $order instanceof \WC_Order ) );
	}

	/**
	 * The evidence of the orders of an e-mail address.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page, from 1.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public static function export( $email, $page = 1 ): array {
		$orders = self::orders( (string) $email, (int) $page );
		$data   = array();

		foreach ( $orders as $order ) {
			$evidence = $order->get_meta( Evidence::META );
			if ( ! is_array( $evidence ) || ! $evidence ) {
				continue;
			}

			$fields = array(
				array(
					'name'  => __( 'Order number', 'woolab-ic-dic' ),
					'value' => $order->get_order_number(),
				),
			);
			foreach ( Evidence::labels() as $key => $label ) {
				if ( isset( $evidence[ $key ] ) && '' !== (string) $evidence[ $key ] ) {
					$fields[] = array(
						'name'  => $label,
						'value' => (string) $evidence[ $key ],
					);
				}
			}

			$data[] = array(
				'group_id'    => 'woolab_icdic_vat_evidence',
				'group_label' => __( 'VAT ID checks', 'woolab-ic-dic' ),
				'item_id'     => 'woolab-icdic-vat-evidence-' . $order->get_id(),
				'data'        => $fields,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $orders ) < self::PAGE,
		);
	}

	/**
	 * The erasure keeps the evidence: it proves the VAT treatment of a sale, and VAT
	 * records are kept for the period national law sets (Directive 2006/112/EC,
	 * Art. 242–249; GDPR Art. 17(3)(b)). Only orders with a VAT ID have one.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page, from 1.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public static function erase( $email, $page = 1 ): array {
		$orders   = self::orders( (string) $email, (int) $page );
		$messages = array();

		foreach ( $orders as $order ) {
			if ( $order->get_meta( Evidence::META ) ) {
				/* translators: %s: order number */
				$messages[] = sprintf( __( 'The VAT ID check of order %s has been retained: it is part of the VAT records the shop must keep (Directive 2006/112/EC, Art. 242–249).', 'woolab-ic-dic' ), $order->get_order_number() );
			}
		}

		return array(
			'items_removed'  => false,
			'items_retained' => (bool) $messages,
			'messages'       => $messages,
			'done'           => count( $orders ) < self::PAGE,
		);
	}
}
