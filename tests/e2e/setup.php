<?php
/**
 * Store for the end-to-end tests: a Slovak shop with 23 % VAT, one shippable
 * product, cash on delivery, the block checkout WooCommerce creates and a classic
 * one at /classic-checkout/ (see mu-plugin.php). Safe to run again.
 *
 * Playground runs it from the blueprint (tests/e2e/stack.js); on any other test
 * site: wp eval-file wp-content/plugins/woolab-ic-dic/tests/e2e/setup.php
 */

// The stack tests/e2e/stack.js asked for: fail the boot rather than test on
// whatever Playground resolved instead.
if ( defined( 'WOOLAB_E2E_WP' ) ) {
	$woolab_e2e_wp = get_bloginfo( 'version' );
	if ( ( WOOLAB_E2E_WP !== $woolab_e2e_wp && ! str_starts_with( $woolab_e2e_wp, WOOLAB_E2E_WP . '.' ) ) || WOOLAB_E2E_WC !== WC_VERSION ) {
		throw new RuntimeException( 'Expected WordPress ' . WOOLAB_E2E_WP . ' and WooCommerce ' . WOOLAB_E2E_WC . ', got ' . $woolab_e2e_wp . ' / ' . WC_VERSION );
	}
}

// No "Administration email verification" screen after the settings tests log in.
update_option( 'admin_email_lifespan', time() + 10 * YEAR_IN_SECONDS );

update_option( 'woocommerce_default_country', 'SK' );
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
update_option( 'woocommerce_coming_soon', 'no' );

foreach ( WC_Tax::get_rates_for_tax_class( '' ) as $rate ) {
	WC_Tax::_delete_tax_rate( $rate->tax_rate_id );
}
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
if ( ! $zone->get_shipping_methods() ) {
	$zone->add_shipping_method( 'free_shipping' );
}

update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes' ) );

if ( ! get_page_by_path( 'test-product', OBJECT, 'product' ) ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Test product' );
	$product->set_slug( 'test-product' );
	$product->set_regular_price( '10' );
	$product->save();
}

$classic = get_page_by_path( 'classic-checkout' );
update_option(
	'woolab_icdic_e2e_classic_checkout',
	$classic ? $classic->ID : wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Classic checkout',
			'post_name'    => 'classic-checkout',
			'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
		)
	)
);
wp_mkdir_p( WPMU_PLUGIN_DIR );
copy( __DIR__ . '/mu-plugin.php', WPMU_PLUGIN_DIR . '/woolab-icdic-e2e.php' );

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
