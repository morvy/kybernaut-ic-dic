<?php
/**
 * The company fields of a customer: the user profile and the order screen's
 * "Load billing address".
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Order;

/**
 * The billing_ic, billing_dic and billing_dic_dph user meta in the admin.
 */
final class Customer {

	/**
	 * Hook the profile fields and the AJAX customer details.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_customer_meta_fields', array( self::class, 'customer_meta_fields' ) );
		add_filter( 'woocommerce_ajax_get_customer_details', array( self::class, 'ajax_get_customer_details' ), 10, 3 );
	}

	/**
	 * The company fields on the user profile.
	 *
	 * @param array<string,array<string,mixed>> $fields Customer meta fields by section.
	 * @return array<string,array<string,mixed>>
	 */
	public static function customer_meta_fields( $fields ) {
		// The profile being edited, for its billing country (user-edit.php sets it).
		$country = isset( $GLOBALS['profileuser'] ) ? (string) get_user_meta( $GLOBALS['profileuser']->ID, 'billing_country', true ) : '';
		$labels  = woolab_icdic_labels( $country );

		$fields['billing']['fields'] += array(
			'billing_ic'      => array(
				'label'       => $labels['ic'],
				'description' => '',
			),
			'billing_dic'     => array(
				'label'       => $labels['dic'],
				'description' => '',
			),
			'billing_dic_dph' => array(
				'label'       => woolab_icdic_labels( 'SK' )['dic_dph'],
				'description' => '',
			),
		);
		return $fields;
	}

	/**
	 * Load customer user fields via ajax on admin order screen from a customer record
	 * (https://www.jnorton.co.uk/woocommerce-custom-fields).
	 *
	 * @param array<string,mixed> $data     Customer details.
	 * @param \WC_Customer        $customer Customer.
	 * @param int                 $user_id  User ID.
	 * @return array<string,mixed>
	 */
	public static function ajax_get_customer_details( $data, $customer, $user_id ) {

		$country                        = get_user_meta( $user_id, 'billing_country', true );
		$data['billing']['billing_ic']  = get_user_meta( $user_id, 'billing_ic', true );
		$data['billing']['billing_dic'] = get_user_meta( $user_id, 'billing_dic', true );
		if ( 'SK' === $country ) {
			$data['billing']['billing_dic_dph'] = get_user_meta( $user_id, 'billing_dic_dph', true );
		}

		return $data;
	}
}
