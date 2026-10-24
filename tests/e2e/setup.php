<?php
/**
 * Store for the Playground end-to-end tests: a Slovak shop with 23 % VAT, one
 * shippable product, cash on delivery and the block checkout WooCommerce creates.
 */

// The point of these tests is the oldest supported stack: fail the boot rather
// than pass on whatever Playground resolved "latest" to.
if ( ! str_starts_with( get_bloginfo( 'version' ), '6.7' ) || ! str_starts_with( WC_VERSION, '10.0.' ) ) {
	throw new RuntimeException( 'Expected WordPress 6.7 and WooCommerce 10.0, got ' . get_bloginfo( 'version' ) . ' / ' . WC_VERSION );
}

update_option( 'woocommerce_default_country', 'SK' );
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
update_option( 'woocommerce_coming_soon', 'no' );

WC_Tax::_insert_tax_rate(
	array(
		'tax_rate_country'  => '',
		'tax_rate'          => '23.0000',
		'tax_rate_name'     => 'VAT',
		'tax_rate_priority' => 1,
		'tax_rate_shipping' => 1,
		'tax_rate_order'    => 0,
		'tax_rate_class'    => '',
	)
);

$zone = new WC_Shipping_Zone( 0 );
$zone->add_shipping_method( 'free_shipping' );

update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes' ) );

$product = new WC_Product_Simple();
$product->set_name( 'Test product' );
$product->set_regular_price( '10' );
$product->save();
update_option( 'woolab_icdic_e2e_product', $product->get_id() );

update_option(
	'woolab_icdic_settings',
	array(
		'ares_check' => 'yes',
		'ares_fill'  => 'yes',
		'vies_check' => 'yes',
		'vat_exempt' => 'yes',
		'toggle'     => 'yes',
	)
);
