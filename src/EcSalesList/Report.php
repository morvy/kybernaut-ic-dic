<?php
/**
 * The EC Sales List of a month or quarter: the reverse-charge sales to companies in
 * other EU countries, per customer VAT number and goods / services.
 *
 * @package KybernautIcDic
 * @since 2.0.0
 */

namespace KybernautIcDic\EcSalesList;

use KybernautIcDic\Order\Evidence;

/**
 * The orders and refunds of a period (entries()), and the statement's rows (rows()).
 *
 * In the statement: exempt orders whose VAT ID evidence (Order\Evidence) says reverse
 * charge with a valid VAT ID; flagged for the shop to check: exempt orders with any
 * other evidence (VIES did not answer) and exempt orders with a VAT ID but no evidence
 * (created in the admin, or before 2.0.0). An order counts in the period it first
 * reached a paid status (DATE_META, kept from then on), older orders in the period
 * they were paid, or created when not paid; a refund in the period of its own date,
 * negative. Values are net: line totals after discounts, without tax.
 */
final class Report {

	/**
	 * When the order first reached a paid status, UTC ISO 8601: its period, which a
	 * later status change does not move.
	 */
	public const DATE_META = '_woolab_icdic_ec_date';

	/**
	 * The currency of each official file; the CSV takes every currency.
	 */
	public const CURRENCIES = array(
		'sk' => 'EUR',
		'cz' => 'CZK',
	);

	/**
	 * The codes of goods and services: SK kod, CZ k_pln_eu.
	 */
	public const CODES = array(
		'sk' => array(
			'goods'    => '0',
			'services' => '2',
		),
		'cz' => array(
			'goods'    => '0',
			'services' => '3',
		),
	);

	/**
	 * Orders loaded at a time.
	 */
	public const BATCH = 100;

	/**
	 * The order statuses in the statement: WooCommerce's paid ones (custom ones
	 * included), and refunded: a refunded order's sale still counts, its refund
	 * counts negative.
	 *
	 * @return array<int,string>
	 */
	public static function statuses(): array {
		return array_values( array_unique( array_merge( wc_get_is_paid_statuses(), array( 'refunded' ) ) ) );
	}

	/**
	 * Keep the period date of an order with a VAT ID the first time it is in a counted
	 * status. Not saved: the caller saves the order.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool Whether it was set now.
	 */
	public static function stamp( \WC_Order $order ): bool {
		if ( $order->get_meta( self::DATE_META ) || ! in_array( $order->get_status(), self::statuses(), true ) || '' === Evidence::vat_number( $order ) ) {
			return false;
		}

		$order->update_meta_data( self::DATE_META, gmdate( 'c' ) );

		return true;
	}

	/**
	 * The status change: keep the period date.
	 *
	 * @param int       $id    Order ID.
	 * @param string    $from  Old status.
	 * @param string    $to    New status.
	 * @param \WC_Order $order Order.
	 */
	public static function status_changed( $id, $from, $to, $order ): void {
		if ( $order instanceof \WC_Order && self::stamp( $order ) ) {
			$order->save_meta_data();
		}
	}

	/**
	 * The period's first second and the next period's first second, in the site's
	 * time zone.
	 *
	 * @param string $type   'month' or 'quarter'.
	 * @param int    $number Month 1–12 or quarter 1–4.
	 * @param int    $year   Year.
	 * @return array{0:int,1:int}
	 */
	public static function period( string $type, int $number, int $year ): array {
		$quarter = 'quarter' === $type;
		$start   = new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $quarter ? $number * 3 - 2 : $number ), wp_timezone() );

		return array( $start->getTimestamp(), $start->modify( $quarter ? '+3 months' : '+1 month' )->getTimestamp() );
	}

	/**
	 * The orders and refunds of a period, each an entry: order_id, refund_id (0 for
	 * the order itself), currency, country (the VAT number's prefix, EL for Greece),
	 * vat (without the prefix), goods and services (net amounts), flag ('' verified,
	 * 'unverified', 'no_evidence').
	 *
	 * @param int $start The period's first second.
	 * @param int $end   The next period's first second.
	 * @return array<int,array{order_id:int,refund_id:int,currency:string,country:string,vat:string,goods:float,services:float,flag:string}>
	 */
	public static function entries( int $start, int $end ): array {
		$range    = $start . '...' . ( $end - 1 );
		$statuses = self::statuses();
		// Exempt orders, or orders with evidence (an exemption decided by it).
		$candidate = array(
			'relation' => 'OR',
			array(
				'key'     => Evidence::META,
				'compare' => 'EXISTS',
			),
			array(
				'key'   => 'is_vat_exempt',
				'value' => 'yes',
			),
		);

		// The kept period date in the period; orders without one: paid in the period,
		// or created in it. The date that counts is checked below.
		$orders = self::query(
			array(
				'type'       => 'shop_order',
				'status'     => $statuses,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Orders with a VAT ID only.
					array(
						'key'     => self::DATE_META,
						'value'   => array( gmdate( 'c', $start ), gmdate( 'c', $end ) ),
						'compare' => 'BETWEEN',
					),
				),
			)
		);
		foreach ( array( 'date_paid', 'date_created' ) as $date ) {
			$orders += self::query(
				array(
					'type'       => 'shop_order',
					'status'     => $statuses,
					$date        => $range,
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Orders with a VAT ID only.
						array(
							'key'     => self::DATE_META,
							'compare' => 'NOT EXISTS',
						),
						$candidate,
					),
				)
			);
		}

		$entries = array();
		foreach ( $orders as $order ) {
			$date = self::date( $order );
			$flag = self::standing( $order );

			if ( null !== $flag && null !== $date && $date >= $start && $date < $end ) {
				$entries[] = self::entry( $order, $order, $flag );
			}
		}

		$refunds = self::query(
			array(
				'type'         => 'shop_order_refund',
				'date_created' => $range,
			)
		);
		foreach ( $refunds as $refund ) {
			$order = wc_get_order( $refund->get_parent_id() );
			$flag  = $order instanceof \WC_Order && in_array( $order->get_status(), $statuses, true ) ? self::standing( $order ) : null;

			if ( null !== $flag ) {
				$entries[] = self::entry( $order, $refund, $flag );
			}
		}

		return $entries;
	}

	/**
	 * The timestamp the order's period follows: its kept period date, else its paid
	 * date, else its creation.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function date( \WC_Order $order ): ?int {
		$kept = (string) $order->get_meta( self::DATE_META );
		if ( '' !== $kept ) {
			$time = strtotime( $kept );
			return false === $time ? null : $time;
		}

		return ( $order->get_date_paid() ?? $order->get_date_created() )?->getTimestamp();
	}

	/**
	 * Orders in batches, by ID.
	 *
	 * @param array<string,mixed> $args wc_get_orders() arguments.
	 * @return array<int,\WC_Order|\WC_Order_Refund>
	 */
	private static function query( array $args ): array {
		$found = array();

		for ( $page = 1; ; $page++ ) {
			$batch = wc_get_orders(
				$args + array(
					'limit'   => self::BATCH,
					'page'    => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			$batch = is_array( $batch ) ? $batch : array();

			foreach ( $batch as $order ) {
				$found[ $order->get_id() ] = $order;
			}

			if ( count( $batch ) < self::BATCH ) {
				return $found;
			}
		}
	}

	/**
	 * Whether an order is in the statement, and how sure it is. Only an exempt order
	 * is: one the admin put the VAT back on is not.
	 *
	 * @param \WC_Order $order Order.
	 * @return string|null '' (reverse charge, VIES said valid), 'unverified' (other
	 *                     evidence, e.g. VIES did not answer), 'no_evidence' (exempt
	 *                     with a VAT ID to another EU country, never checked) or null
	 *                     (not in the statement).
	 */
	public static function standing( \WC_Order $order ): ?string {
		if ( 'yes' !== $order->get_meta( 'is_vat_exempt' ) ) {
			return null;
		}

		$evidence = $order->get_meta( Evidence::META );
		if ( is_array( $evidence ) && ! empty( $evidence['vat_number'] ) ) {
			return 'reverse_charge' === ( $evidence['reason'] ?? '' ) && 'valid' === ( $evidence['state'] ?? '' ) ? '' : 'unverified';
		}

		return '' !== Evidence::vat_number( $order ) && Evidence::foreign_eu( $order->get_billing_country() ) ? 'no_evidence' : null;
	}

	/**
	 * The entry of an order or of its refund.
	 *
	 * @param \WC_Order          $order  Order.
	 * @param \WC_Abstract_Order $source The order or its refund.
	 * @param string             $flag   standing().
	 * @return array{order_id:int,refund_id:int,currency:string,country:string,vat:string,goods:float,services:float,flag:string}
	 */
	private static function entry( \WC_Order $order, \WC_Abstract_Order $source, string $flag ): array {
		$evidence              = $order->get_meta( Evidence::META );
		$vat                   = is_array( $evidence ) && ! empty( $evidence['vat_number'] ) ? (string) $evidence['vat_number'] : Evidence::vat_number( $order );
		list( $country, $vat ) = self::customer( $vat, $order->get_billing_country() );

		return array(
			'order_id'  => $order->get_id(),
			'refund_id' => $source === $order ? 0 : $source->get_id(),
			'currency'  => $order->get_currency(),
			'country'   => $country,
			'vat'       => $vat,
			'flag'      => $flag,
		) + self::amounts( $source, self::has_goods( $order ) );
	}

	/**
	 * A customer's VAT number as the statements list it: its country prefix (EL for
	 * Greece) and the rest. A number without a prefix (a Polish or Hungarian domestic
	 * one) takes the billing country.
	 *
	 * @param string $vat             VAT number.
	 * @param string $billing_country Billing country.
	 * @return array{0:string,1:string}
	 */
	public static function customer( string $vat, string $billing_country ): array {
		$vat = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $vat ) );

		if ( preg_match( '/^[A-Z]{2}/', $vat ) ) {
			return array( substr( $vat, 0, 2 ), substr( $vat, 2 ) );
		}

		return array( 'GR' === $billing_country ? 'EL' : $billing_country, $vat );
	}

	/**
	 * Whether an order has a line of goods: a product that is not virtual (a deleted
	 * product counts as goods).
	 *
	 * @param \WC_Abstract_Order $order Order.
	 */
	public static function has_goods( \WC_Abstract_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && 'goods' === self::kind( $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Goods or services: a virtual product is a service.
	 *
	 * @param \WC_Order_Item_Product $item Order line.
	 */
	public static function kind( \WC_Order_Item_Product $item ): string {
		$product = $item->get_product();

		return $product instanceof \WC_Product && $product->is_virtual() ? 'services' : 'goods';
	}

	/**
	 * The net amounts of an order or a refund (negative), goods and services.
	 * Shipping and fees go with the goods when the order has any, otherwise with the
	 * services; so does what a refund returns beyond its lines (an amount only, or
	 * lines plus an extra amount).
	 *
	 * @param \WC_Abstract_Order $order     Order or refund.
	 * @param bool               $has_goods has_goods() of the order (the parent of a refund).
	 * @return array{goods:float,services:float}
	 */
	public static function amounts( \WC_Abstract_Order $order, bool $has_goods ): array {
		$amounts = array(
			'goods'    => 0.0,
			'services' => 0.0,
		);

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$amounts[ self::kind( $item ) ] += (float) $item->get_total();
			}
		}

		if ( $order instanceof \WC_Order_Refund ) {
			// The refunded amount less its product lines: shipping, fees and any extra.
			// An exempt order has no tax, so the amount is net.
			$other = - (float) $order->get_amount() - $amounts['goods'] - $amounts['services'];
		} else {
			$other = (float) $order->get_shipping_total();
			foreach ( $order->get_fees() as $fee ) {
				$other += (float) $fee->get_total();
			}
		}

		$amounts[ $has_goods ? 'goods' : 'services' ] += $other;

		return $amounts;
	}

	/**
	 * The statement's rows: per currency, country, VAT number and goods / services,
	 * summed, then rounded once (SK: whole euros, half up; CZ: whole crowns, up; CSV:
	 * cents). The SK and CZ files take one currency, the entries in another are
	 * skipped.
	 *
	 * @param array<int,array{order_id:int,refund_id:int,currency:string,country:string,vat:string,goods:float,services:float,flag:string}> $entries entries().
	 * @param string                                                                                                                        $format  'sk', 'cz' or 'csv'.
	 * @return array{rows:array<int,array{currency:string,country:string,vat:string,kind:string,value:float,orders:int,unverified:bool}>,skipped:array<int,string>} Skipped: order ID => currency.
	 */
	public static function rows( array $entries, string $format ): array {
		$currency = self::CURRENCIES[ $format ] ?? null;
		$rows     = array();
		$orders   = array();
		$skipped  = array();

		foreach ( $entries as $entry ) {
			if ( null !== $currency && $currency !== $entry['currency'] ) {
				$skipped[ $entry['order_id'] ] = $entry['currency'];
				continue;
			}

			foreach ( array( 'goods', 'services' ) as $kind ) {
				if ( 0.0 === round( $entry[ $kind ], 2 ) ) {
					continue;
				}

				$key            = implode( '|', array( $entry['currency'], $entry['country'], $entry['vat'], $kind ) );
				$rows[ $key ] ??= array(
					'currency'   => $entry['currency'],
					'country'    => $entry['country'],
					'vat'        => $entry['vat'],
					'kind'       => $kind,
					'value'      => 0.0,
					'orders'     => 0,
					'unverified' => false,
				);

				$rows[ $key ]['value']               += $entry[ $kind ];
				$rows[ $key ]['unverified']           = $rows[ $key ]['unverified'] || '' !== $entry['flag'];
				$orders[ $key ][ $entry['order_id'] ] = true;
			}
		}

		ksort( $rows );
		ksort( $skipped );

		foreach ( $rows as $key => $row ) {
			// A sale refunded in the same period is no supply.
			if ( 0.0 === round( $row['value'], 2 ) ) {
				unset( $rows[ $key ] );
				continue;
			}

			$rows[ $key ]['value']  = self::round( $row['value'], $format );
			$rows[ $key ]['orders'] = count( $orders[ $key ] );
		}

		return array(
			'rows'    => array_values( $rows ),
			'skipped' => $skipped,
		);
	}

	/**
	 * A row's value as the statement lists it.
	 *
	 * @param float  $value  The summed value.
	 * @param string $format 'sk' (§ 80(6) ZDPH: whole euros, half up), 'cz' (EPO
	 *                       DPHSHV pln_hodnota: whole crowns, up) or 'csv' (cents).
	 */
	public static function round( float $value, string $format ): float {
		$value = round( $value, 2 );

		$rounded = match ( $format ) {
			'sk'    => round( $value, 0, PHP_ROUND_HALF_UP ),
			'cz'    => ceil( $value ),
			default => $value,
		};

		// No "-0".
		return $rounded + 0.0;
	}
}
