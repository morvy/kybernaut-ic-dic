<?php
/**
 * End-to-end tests only (copied to mu-plugins by tests/e2e/setup.php): with the
 * cookie woolab_e2e_checkout=classic, the shortcode page is WooCommerce's checkout,
 * so one site tests both checkouts.
 */

add_filter(
	'woocommerce_get_checkout_page_id',
	function ( $id ) {
		$classic = isset( $_COOKIE['woolab_e2e_checkout'] ) && 'classic' === $_COOKIE['woolab_e2e_checkout'];
		return $classic ? (int) get_option( 'woolab_icdic_e2e_classic_checkout', $id ) : $id;
	}
);

/*
 * VIES answered here: the member states' registers are slow and often turn
 * requests away (MS_MAX_CONCURRENT_REQ), which would fail the tests on nights the
 * shop's code is fine. The answers are the live API's (tests/unit/ViesTest.php).
 * ARES stays live.
 */
add_filter(
	'pre_http_request',
	function ( $response, $args, $url ) {
		if ( ! str_starts_with( $url, 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/' ) ) {
			return $response;
		}
		$valid = str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/ms/CZ/vat/27082440' );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		// As the live API: a consultation number only when the request names the
		// shop's VAT ID as the requester, the name and address of a valid number.
		$answer = array(
			'isValid'           => $valid,
			'requestDate'       => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'userError'         => $valid ? 'VALID' : 'INVALID',
			'name'              => $valid ? 'Alza.cz a.s.' : '---',
			'address'           => $valid ? "Jankovcova 1522/53\nPRAHA 7 - HOLEŠOVICE\n170 00  PRAHA 7" : '---',
			'requestIdentifier' => empty( $query['requesterNumber'] ) ? '' : 'WAPIE2E' . strtoupper( substr( md5( $url . microtime() ), 0, 9 ) ),
		);
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $answer ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// With the cookie woolab_e2e_filter=sk_dic, a 1.x snippet turns the Slovak DIČ
// requirement off, the way a theme would (tests/e2e/settings.spec.js).
if ( isset( $_COOKIE['woolab_e2e_filter'] ) && 'sk_dic' === $_COOKIE['woolab_e2e_filter'] ) {
	add_filter( 'woolab_icdic_sk_required_ic_and_dic', '__return_false' );
}

// With the cookie woolab_e2e_check=pl, a store's check of its own (the
// woolab_icdic_checks filter): a Polish NIP not on its whitelist stops the order,
// with its message under the VAT ID field.
if ( isset( $_COOKIE['woolab_e2e_check'] ) && 'pl' === $_COOKIE['woolab_e2e_check'] ) {
	add_filter(
		'woolab_icdic_checks',
		function ( array $checks ) {
			$checks['e2e_pl_whitelist'] = new class() extends \KybernautIcDic\Validation\AbstractCheck {
				protected string $field = 'dic';

				public function id(): string {
					return 'e2e_pl_whitelist';
				}

				public function applies( \KybernautIcDic\Validation\Context $context ): bool {
					return 'PL' === $context->country() && $context->entered( 'dic' );
				}

				public function run( \KybernautIcDic\Validation\Context $context, \KybernautIcDic\Validation\Result $result ): void {
					if ( 'PL5260250995' !== $context->number( 'dic' ) ) {
						$result->add_error( $this->field(), 'e2e_pl_whitelist', array(), 'E2E: this NIP is not on the whitelist.' );
					}
				}
			};
			return $checks;
		}
	);
}

// With the header X-Woolab-E2E-Rate-Limit, a limit of 3 lookups per 10 s: a slow
// host answers 40 parallel ones too slowly to fill the default 25
// (tests/e2e/rest.spec.js).
if ( ! empty( $_SERVER['HTTP_X_WOOLAB_E2E_RATE_LIMIT'] ) ) {
	add_filter(
		'woocommerce_store_api_rate_limit_options',
		function ( $options ) {
			return array_merge( (array) $options, array( 'limit' => 3, 'seconds' => 10 ) );
		}
	);
}

// With the cookie woolab_e2e_service=pl, a store's own Polish register (the
// woolab_icdic_services filter) for the company lookup and search, knowing one
// company (tests/e2e/block-checkout.spec.js, classic-checkout.spec.js).
if ( isset( $_COOKIE['woolab_e2e_service'] ) && 'pl' === $_COOKIE['woolab_e2e_service'] ) {
	add_filter(
		'woolab_icdic_services',
		function ( array $map ) {
			$company  = array(
				'ico'      => '5260250995',
				'name'     => 'Test Polska sp. z o.o.',
				'address'  => 'ul. Testowa 1',
				'postcode' => '00-001',
				'city'     => 'Warszawa',
			);
			$register = new \KybernautIcDic\Services\CallbackService(
				'e2e_pl',
				array(
					'company_lookup' => static fn ( $ico ) => $company['ico'] === $ico
						? array(
							'error'      => false,
							'spolecnost' => $company['name'],
							'ico'        => $company['ico'],
							'dic'        => null,
							'adresa'     => $company['address'],
							'psc'        => $company['postcode'],
							'mesto'      => $company['city'],
						)
						: array( 'error' => 'E2E: not in the register.' ),
					'company_search' => static fn ( $name ) => false !== stripos( $company['name'], $name )
						? array(
							array(
								'ico'     => $company['ico'],
								'name'    => $company['name'],
								'address' => $company['address'] . ', ' . $company['postcode'] . ' ' . $company['city'],
							),
						)
						: array(),
				),
				'E2E register'
			);

			$map['PL']['company_lookup'] = array( $register );
			$map['PL']['company_search'] = array( $register );
			return $map;
		}
	);
}
