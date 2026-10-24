<?php
/**
 * REST routes for the checkout lookups: woolab-ic-dic/v1/lookup (IČO → company)
 * and woolab-ic-dic/v1/search (company name → IČO suggestions).
 *
 * They are public, like the checkout, so they are rate limited with the limiter
 * the Store API uses (RateLimits: N requests per window per client, stored in
 * wc_rate_limits). Its limit, window and proxy settings come from
 * `woocommerce_store_api_rate_limit_options` (25 per 10 s by default), but here it
 * is on by default: these routes call ARES and VIES on the visitor's behalf.
 *
 * @since 2.0.0
 */

use Automattic\WooCommerce\StoreApi\Utilities\RateLimits;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

const WOOLAB_ICDIC_REST_NAMESPACE = 'woolab-ic-dic/v1';

add_action( 'rest_api_init', 'woolab_icdic_rest_routes' );

function woolab_icdic_rest_routes() {

	$country = array(
		'type'    => 'string',
		'enum'    => array( 'CZ', 'SK' ),
		'default' => 'CZ',
	);

	register_rest_route(
		WOOLAB_ICDIC_REST_NAMESPACE,
		'/lookup',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'woolab_icdic_rest_lookup',
			'permission_callback' => '__return_true',
			'args'                => array(
				// Checked by the lookups themselves, which explain what is wrong.
				'ico'     => array( 'type' => 'string', 'required' => true, 'maxLength' => 20 ),
				'country' => $country,
			),
		)
	);

	register_rest_route(
		WOOLAB_ICDIC_REST_NAMESPACE,
		'/search',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'woolab_icdic_rest_search',
			'permission_callback' => '__return_true',
			'args'                => array(
				'name'    => array( 'type' => 'string', 'required' => true, 'minLength' => 3, 'maxLength' => 100 ),
				'country' => $country,
			),
		)
	);
}

/**
 * The base URL the checkout scripts call.
 *
 * @return string
 */
function woolab_icdic_rest_url() {
	return esc_url_raw( rest_url( WOOLAB_ICDIC_REST_NAMESPACE . '/' ) );
}

/**
 * IČO → company, from ARES (CZ) or the Finančná správa lists (SK). Same result as
 * woolab_icdic_ares() / woolab_icdic_sk_lookup().
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function woolab_icdic_rest_lookup( $request ) {
	$limited = woolab_icdic_rest_rate_limit();
	if ( $limited ) {
		return $limited;
	}

	$ico = wc_clean( $request['ico'] );

	if ( 'SK' === $request['country'] ) {
		$result = woolab_icdic_sk_check() ? woolab_icdic_sk_lookup( $ico ) : null;
	} else {
		$result = woolab_icdic_ares_check() ? woolab_icdic_ares( $ico ) : null;
	}

	// The register is switched off: nothing to look up for anyone.
	if ( null === $result ) {
		return new WP_REST_Response( array( 'error' => __( 'Unexpected error occurred. Try it again.', 'woolab-ic-dic' ) ), 403 );
	}

	return new WP_REST_Response( $result );
}

/**
 * Company name → up to 10 suggestions, only where that register also autofills
 * (picking a suggestion fills the IČO, and the IČO lookup fills the rest).
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function woolab_icdic_rest_search( $request ) {
	$limited = woolab_icdic_rest_rate_limit();
	if ( $limited ) {
		return $limited;
	}

	$search = 'SK' === $request['country']
		? ( woolab_icdic_sk_check() && woolab_icdic_sk_fill() ? 'woolab_icdic_sk_search' : null )
		: ( woolab_icdic_ares_check() && woolab_icdic_ares_fill() ? 'woolab_icdic_ares_search' : null );

	if ( ! $search ) {
		return new WP_REST_Response( array( 'results' => array() ) );
	}

	$results = $search( trim( wc_clean( $request['name'] ) ) );

	return new WP_REST_Response( false === $results ? array( 'results' => array(), 'internal_error' => true ) : array( 'results' => $results ) );
}

/**
 * Count the request against the client's limit.
 *
 * @return WP_REST_Response|null A 429 response once the limit is exceeded, null otherwise.
 */
function woolab_icdic_rest_rate_limit() {

	/**
	 * Whether the lookup routes are rate limited.
	 *
	 * @since 2.0.0
	 * @param bool $enabled
	 */
	if ( ! class_exists( RateLimits::class ) || ! apply_filters( 'woolab_icdic_rest_rate_limit', true ) ) {
		return null;
	}

	$options = RateLimits::get_options();
	$server  = rest_get_server();

	// The client the way the Store API identifies it: behind a proxy only when the
	// store says so, as the forwarded headers can be forged.
	$ip = $options->proxy_support
		? WC_Geolocation::get_ip_address()
		: ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );

	$action_id = 'woolab_icdic_lookup_' . md5( $ip );
	$retry     = RateLimits::is_exceeded_retry_after( $action_id );

	$server->send_header( 'RateLimit-Limit', $options->limit );

	if ( false !== $retry ) {
		$server->send_header( 'RateLimit-Remaining', 0 );
		$server->send_header( 'RateLimit-Retry-After', $retry );
		$server->send_header( 'RateLimit-Reset', time() + $retry );

		/** This action is documented in woocommerce/src/StoreApi/Authentication.php */
		do_action( 'woocommerce_store_api_rate_limit_exceeded', $ip, $action_id );

		$response = new WP_REST_Response(
			array(
				'code'           => 'rate_limit_exceeded',
				'error'          => __( 'Unexpected error occurred. Try it again.', 'woolab-ic-dic' ),
				'internal_error' => true,
			),
			429
		);
		$response->header( 'Retry-After', $retry );

		return $response;
	}

	$limit = RateLimits::update_rate_limit( $action_id );
	$server->send_header( 'RateLimit-Remaining', $limit->remaining );
	$server->send_header( 'RateLimit-Reset', $limit->reset );

	return null;
}
