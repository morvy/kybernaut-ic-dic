<?php
/**
 * The proof of the VAT ID check kept with an order, and the admin's "Validate VAT
 * ID" order action that checks it again.
 *
 * @package KybernautIcDic
 * @since 2.0.0
 */

namespace KybernautIcDic\Order;

use KybernautIcDic\Checkout\VatExempt;
use KybernautIcDic\Fields\Countries;
use KybernautIcDic\Numbers\Checksum;
use KybernautIcDic\Services\Services;
use KybernautIcDic\Services\Vies\Vies;
use KybernautIcDic\Settings\Flags;

/**
 * Order meta `_woolab_icdic_vat_evidence`: vat_number, state, reason ('reverse_charge',
 * 'with_vat' or 'check_failed_ignored'), checked_at (ISO 8601, UTC), and what the
 * register returned (Service::validate_vat_id() 'evidence': requested_at,
 * consultation_number, name, address, source), and the location: ip_country,
 * billing_country, vat_country, location_match ('yes', 'no', '' when the IP country
 * is unknown). Each re-check keeps the previous one in
 * `_woolab_icdic_vat_evidence_history`, newest first.
 *
 * Kept only for orders with a VAT ID: a company's proof of the exemption, which the
 * VAT records are kept for (Directive 2006/112/EC Art. 242–249). A consumer's order
 * gets none. The IP address itself is not copied: WooCommerce keeps it with the order
 * (customer_ip_address), the evidence keeps its country only.
 */
final class Evidence {

	/**
	 * The evidence meta key.
	 */
	public const META = '_woolab_icdic_vat_evidence';

	/**
	 * The previous evidences meta key.
	 */
	public const HISTORY = '_woolab_icdic_vat_evidence_history';

	/**
	 * How many previous evidences are kept.
	 */
	private const HISTORY_SIZE = 5;

	/**
	 * The order action.
	 */
	public const ACTION = 'woolab_icdic_validate_vat';

	/**
	 * Hook the order action (the checkouts call record()).
	 */
	public static function register(): void {
		add_filter( 'woocommerce_order_actions', array( self::class, 'order_actions' ) );
		add_action( 'woocommerce_order_action_' . self::ACTION, array( self::class, 'revalidate' ) );
	}

	/**
	 * The VAT number of an order: the IČ DPH in Slovakia, the DIČ elsewhere.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function vat_number( \WC_Order $order ): string {
		return (string) VatExempt::vat_number( $order->get_billing_country(), (string) $order->get_meta( '_billing_dic' ), (string) $order->get_meta( '_billing_dic_dph' ) );
	}

	/**
	 * Why the order has or has no VAT.
	 *
	 * @param string $state             The VAT ID's state.
	 * @param bool   $exempt            Whether the order is VAT exempt.
	 * @param bool   $ignore_check_fail Whether a register outage lets the order through.
	 * @return string 'check_failed_ignored', 'reverse_charge' or 'with_vat'.
	 */
	public static function reason( string $state, bool $exempt, bool $ignore_check_fail ): string {
		if ( 'unverifiable' === $state && $ignore_check_fail ) {
			return 'check_failed_ignored';
		}

		return $exempt ? 'reverse_charge' : 'with_vat';
	}

	/**
	 * The evidence of one check.
	 *
	 * @param string                                             $vat        VAT number.
	 * @param array{state:string,evidence?:array<string,string>} $answer     The register's answer.
	 * @param string                                             $reason     reason().
	 * @param string                                             $checked_at When it was checked, ISO 8601.
	 * @return array<string,string>
	 */
	public static function build( string $vat, array $answer, string $reason, string $checked_at ): array {
		return array(
			'vat_number' => $vat,
			'state'      => $answer['state'],
			'reason'     => $reason,
			'checked_at' => $checked_at,
		) + ( $answer['evidence'] ?? array() );
	}

	/**
	 * Keep the evidence of a checkout order, from the answer the checkout already got
	 * (cached): orders with a VAT ID from another EU VAT country than the shop's,
	 * those the exemption is decided for. Saved with the order.
	 *
	 * @param \WC_Order $order Order, its billing fields and 'is_vat_exempt' set.
	 */
	public static function record( \WC_Order $order ): void {
		$vat = self::vat_number( $order );

		if ( '' === $vat || ( ! Flags::vat_exempt() && ! Flags::vies_check() ) || ! self::foreign_eu( $order->get_billing_country() ) ) {
			return;
		}

		$answer = Services::vat_answer( $vat );
		$reason = self::reason( $answer['state'], 'yes' === $order->get_meta( 'is_vat_exempt' ), Flags::ignore_check_fail() );

		$order->update_meta_data( self::META, self::build( $vat, $answer, $reason, gmdate( 'c' ) ) + self::order_location( $order, $vat ) );
	}

	/**
	 * The location evidence of an order: the country of its customer IP address, its
	 * billing country and its VAT ID's country.
	 *
	 * The country comes from what geolocates without sending the address anywhere
	 * (WooCommerce's MaxMind integration, or a woocommerce_geolocate_ip filter), never
	 * from the geolocation APIs. An order without an IP address (one created in the
	 * admin) has no IP country.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $vat   Its VAT number.
	 * @return array<string,string>
	 */
	public static function order_location( \WC_Order $order, string $vat ): array {
		$ip         = (string) $order->get_customer_ip_address();
		$ip_country = '' === $ip ? '' : (string) ( \WC_Geolocation::geolocate_ip( $ip, false, false )['country'] ?? '' );

		return self::location( strtoupper( $ip_country ), $order->get_billing_country(), Checksum::vat_country_code( $vat ) );
	}

	/**
	 * Whether the IP country, the billing country and the VAT ID's country agree. Only
	 * a note for the admin: it never stops an order or changes its VAT.
	 *
	 * @param string $ip_country      The IP address's country, '' when unknown.
	 * @param string $billing_country Billing country.
	 * @param string $vat_country     The VAT ID's country (GR for EL).
	 * @return array<string,string>
	 */
	public static function location( string $ip_country, string $billing_country, string $vat_country ): array {
		if ( $billing_country !== $vat_country || ( '' !== $ip_country && $ip_country !== $vat_country ) ) {
			$match = 'no';
		} else {
			$match = '' === $ip_country ? '' : 'yes';
		}

		return array(
			'ip_country'      => $ip_country,
			'billing_country' => $billing_country,
			'vat_country'     => $vat_country,
			'location_match'  => $match,
		);
	}

	/**
	 * Whether a country is an EU VAT country other than the shop's (the exemption's
	 * rule, VatExempt).
	 *
	 * @param string $country Billing country.
	 */
	public static function foreign_eu( string $country ): bool {
		return '' !== $country && Countries::base_country() !== $country
			&& in_array( $country, WC()->countries->get_european_union_countries( 'eu_vat' ), true );
	}

	/**
	 * What a re-check does to the order's VAT.
	 *
	 * @param string $state      The VAT ID's state.
	 * @param bool   $eu         Whether the billing country is an EU VAT country.
	 * @param bool   $foreign_eu foreign_eu().
	 * @return bool|null True: exempt; false: with VAT; null: left as it is (VIES did not
	 *                   answer, or a country outside the EU, e.g. an export).
	 */
	public static function decide( string $state, bool $eu, bool $foreign_eu ): ?bool {
		if ( ! $eu || 'unverifiable' === $state ) {
			return null;
		}

		return 'valid' === $state && $foreign_eu;
	}

	/**
	 * Add "Validate VAT ID" to the order actions.
	 *
	 * @param array<string,string> $actions Action => label.
	 * @return array<string,string>
	 */
	public static function order_actions( $actions ) {
		$actions[ self::ACTION ] = __( 'Validate VAT ID in VIES', 'woolab-ic-dic' );

		return $actions;
	}

	/**
	 * The order action: ask VIES again (not cached), keep the evidence, and set or
	 * remove the exemption, totals recalculated. Runs in the order save, after
	 * WooCommerce checked its nonce and saved the billing fields edited with it.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function revalidate( $order ): void {
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$vat = self::vat_number( $order );

		if ( '' === $vat ) {
			$order->add_order_note( __( 'Validate VAT ID: the order has no VAT ID.', 'woolab-ic-dic' ), 0, true );
			return;
		}

		$country = $order->get_billing_country();
		$answer  = ( new Vies() )->check( $vat, true );
		$exempt  = self::decide( $answer['state'], in_array( $country, WC()->countries->get_european_union_countries( 'eu_vat' ), true ), self::foreign_eu( $country ) );

		if ( null !== $exempt ) {
			$order->update_meta_data( 'is_vat_exempt', $exempt ? 'yes' : 'no' );
			$order->calculate_totals( true );
		}

		$evidence = self::build( $vat, $answer, self::reason( $answer['state'], 'yes' === $order->get_meta( 'is_vat_exempt' ), false ), gmdate( 'c' ) ) + self::order_location( $order, $vat );
		$previous = $order->get_meta( self::META );
		if ( is_array( $previous ) && $previous ) {
			$history = $order->get_meta( self::HISTORY );
			$order->update_meta_data( self::HISTORY, array_slice( array_merge( array( $previous ), is_array( $history ) ? $history : array() ), 0, self::HISTORY_SIZE ) );
		}
		$order->update_meta_data( self::META, $evidence );
		$order->save();

		$order->add_order_note( self::note( $evidence, $exempt ), 0, true );
	}

	/**
	 * The order note of a re-check.
	 *
	 * @param array<string,string> $evidence build().
	 * @param bool|null            $exempt   decide().
	 */
	public static function note( array $evidence, ?bool $exempt ): string {
		$vat    = $evidence['vat_number'];
		$number = empty( $evidence['consultation_number'] ) ? ''
			/* translators: %s: VIES consultation number */
			: ' ' . sprintf( __( 'Consultation number: %s.', 'woolab-ic-dic' ), $evidence['consultation_number'] );

		if ( true === $exempt ) {
			/* translators: %s: VAT ID */
			return sprintf( __( 'VAT ID %s is valid in VIES: the order is without VAT (reverse charge), totals recalculated.', 'woolab-ic-dic' ), $vat ) . $number;
		}

		if ( false === $exempt ) {
			/* translators: 1: VAT ID, 2: result, e.g. "Valid" */
			return sprintf( __( 'VAT ID %1$s checked in VIES (%2$s): the order is with VAT, totals recalculated.', 'woolab-ic-dic' ), $vat, self::state_label( $evidence['state'] ) ) . $number;
		}

		if ( 'unverifiable' === $evidence['state'] ) {
			/* translators: %s: VAT ID */
			return sprintf( __( 'VAT ID %s could not be checked, VIES did not answer: the VAT is left as it was.', 'woolab-ic-dic' ), $vat );
		}

		/* translators: 1: VAT ID, 2: result, e.g. "Valid" */
		return sprintf( __( 'VAT ID %1$s checked in VIES (%2$s): the billing country is not an EU VAT country, the VAT is left as it was.', 'woolab-ic-dic' ), $vat, self::state_label( $evidence['state'] ) ) . $number;
	}

	/**
	 * The evidence fields, for people (the personal data export).
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'vat_number'          => _x( 'VAT ID', 'EU VAT number field', 'woolab-ic-dic' ),
			'state'               => __( 'Result', 'woolab-ic-dic' ),
			'reason'              => __( 'Reason', 'woolab-ic-dic' ),
			'checked_at'          => __( 'Checked', 'woolab-ic-dic' ),
			'requested_at'        => __( 'Date of the check', 'woolab-ic-dic' ),
			'consultation_number' => __( 'Consultation number', 'woolab-ic-dic' ),
			'name'                => __( 'Name', 'woolab-ic-dic' ),
			'address'             => __( 'Address', 'woolab-ic-dic' ),
			'source'              => __( 'Register', 'woolab-ic-dic' ),
			'ip_country'          => __( 'IP address country', 'woolab-ic-dic' ),
			'billing_country'     => __( 'Billing country', 'woolab-ic-dic' ),
			'vat_country'         => __( 'VAT ID country', 'woolab-ic-dic' ),
			'location_match'      => __( 'Countries match', 'woolab-ic-dic' ),
		);
	}

	/**
	 * The note on countries that differ, '' when they do not.
	 *
	 * @param array<string,string> $evidence The evidence.
	 */
	public static function mismatch( array $evidence ): string {
		if ( 'no' !== ( $evidence['location_match'] ?? '' ) ) {
			return '';
		}

		$country = static fn ( $key ) => empty( $evidence[ $key ] ) ? '–' : $evidence[ $key ];

		/* translators: 1: IP address country, 2: billing country, 3: VAT ID country; "–" for unknown */
		return sprintf( __( 'The countries differ (IP address %1$s, billing %2$s, VAT ID %3$s): check that the company orders from the country of its VAT ID. The order and its VAT are not changed.', 'woolab-ic-dic' ), $country( 'ip_country' ), $country( 'billing_country' ), $country( 'vat_country' ) );
	}

	/**
	 * A state, for people.
	 *
	 * @param string $state The VAT ID's state.
	 */
	public static function state_label( string $state ): string {
		$labels = array(
			'valid'        => __( 'Valid', 'woolab-ic-dic' ),
			'invalid'      => __( 'Invalid', 'woolab-ic-dic' ),
			'bad_format'   => __( 'Wrong format', 'woolab-ic-dic' ),
			'unverifiable' => __( 'Could not be checked', 'woolab-ic-dic' ),
		);

		return $labels[ $state ] ?? $state;
	}

	/**
	 * A reason, for people.
	 *
	 * @param string $reason reason().
	 */
	public static function reason_label( string $reason ): string {
		$labels = array(
			'reverse_charge'       => __( 'Reverse charge, without VAT', 'woolab-ic-dic' ),
			'with_vat'             => __( 'With VAT', 'woolab-ic-dic' ),
			'check_failed_ignored' => __( 'The check failed and was ignored', 'woolab-ic-dic' ),
		);

		return $labels[ $reason ] ?? $reason;
	}
}
