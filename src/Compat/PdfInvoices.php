<?php
/**
 * Compatibility with PDF Invoices and Packing Slips for WooCommerce by Acowebs.
 * https://wordpress.org/plugins/pdf-invoices-and-packing-slips-for-woocommerce/
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Compat;

/**
 * The company identifiers in the invoice's billing address.
 */
final class PdfInvoices {

	/**
	 * Hook the invoice's billing fields. The packing slip has a filter too,
	 * apifw_ps_custom_billing_fields, which was never hooked.
	 */
	public static function register(): void {
		add_filter( 'apifw_invoice_custom_billing_fields', array( self::class, 'custom_billing_fields' ), 10, 2 );
	}

	/**
	 * Render the company identifiers after the billing fields (the filter does not
	 * seem to be very stable).
	 *
	 * @param string $html     Html output.
	 * @param int    $order_id Order ID.
	 * @return string
	 */
	public static function custom_billing_fields( $html, $order_id ) {

		// Before there can be output ending by "<br/>" if there is no phone number in the order, otherwise no "<br/>" is added.
		$order = wc_get_order( $order_id );

		$phone  = $order->get_billing_phone();
		$dic    = $order->get_meta( '_billing_dic' );
		$ic     = $order->get_meta( '_billing_ic' );
		$ic_dph = $order->get_meta( '_billing_dic_dph' );

		if ( $phone ) {
			$html .= '<br/>';
		}

		if ( $ic ) {
			$html .= __( 'Business ID', 'woolab-ic-dic' ) . ': ' . esc_html( $ic ) . '<br/>';
		}
		if ( $dic ) {
			$html .= __( 'Tax ID', 'woolab-ic-dic' ) . ': ' . esc_html( $dic ) . '<br/>';
		}
		if ( $ic_dph ) {
			$html .= __( 'VAT reg. no.', 'woolab-ic-dic' ) . ': ' . esc_html( $ic_dph ) . '<br/>';
		}

		// Remove the last <br/> to keep the same look.
		$html = preg_replace( '/<br\/>$/', '', $html );

		return $html;
	}
}
