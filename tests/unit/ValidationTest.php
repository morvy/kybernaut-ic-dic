<?php

namespace KybernautIcDic\Test;

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the pure checkout validation orchestrator in
 * includes/validation.php.
 *
 * woolab_icdic_validate_checkout() is pure (no WordPress, no network, no i18n),
 * so no WP_Mock setup is needed. Network is supplied via fake closures and
 * assertions are made on returned error *codes*, never translated strings.
 */
class ValidationTest extends TestCase {

	/**
	 * Build an $input array with neutral defaults; override per test.
	 */
	private function input( array $over = array() ) {
		return array_merge( array(
			'country'               => 'CZ',
			'ic'                    => '',
			'dic'                   => '',
			'dic_present'           => true,
			'dic_dph'               => '',
			'company'               => '',
			'postcode'              => '',
			'city'                  => '',
			'address_1'             => '',
			'ship_to_different'     => false,
			'shipping_country'      => null,
			'ares_check'            => true,
			'ares_fill'             => false,
			'vies_check'            => true,
			'ignore_check_fail'     => false,
			'check_country_match'   => true,
			'require_dic_for_firms' => false,
			'check_dic_dph_match'   => true,
			'country_in_eu'         => true,
			'verify_vat'            => function ( $v ) { return 'valid'; },
			'lookup_ares'           => function ( $ico ) { return false; },
		), $over );
	}

	/** Extract just the error codes, in order. */
	private function codes( array $result ) {
		return array_map( function ( $e ) { return $e['code']; }, $result['errors'] );
	}

	// --- empty / no-op -----------------------------------------------------

	public function testNoFieldsNoErrors() {
		$result = woolab_icdic_validate_checkout( $this->input() );
		$this->assertSame( array(), $this->codes( $result ) );
		$this->assertFalse( $result['check_fail_ignored'] );
	}

	// --- CZ Business ID, ARES disabled (math) ------------------------------

	public function testCzIcAresDisabledValid() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'ic'         => '27082440',
			'ares_check' => false,
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
	}

	public function testCzIcAresDisabledInvalid() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'ic'         => '27082441',
			'ares_check' => false,
		) ) );
		$this->assertSame( array( 'invalid_business_id' ), $this->codes( $result ) );
	}

	// --- CZ Business ID, ARES enabled --------------------------------------

	public function testCzIcAresErrorMessage() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'ic'          => '27082440',
			'lookup_ares' => function ( $ico ) {
				return array( 'error' => 'Subject not found.' );
			},
		) ) );
		$this->assertSame( array( 'invalid_business_id' ), $this->codes( $result ) );
		$this->assertSame( 'Subject not found.', $result['errors'][0]['data']['ares_message'] );
		$this->assertFalse( $result['check_fail_ignored'] );
	}

	public function testCzIcAresInternalErrorIgnored() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'ic'                => '27082440',
			'ignore_check_fail' => true,
			'lookup_ares'       => function ( $ico ) {
				return array( 'error' => 'ARES is not responding.', 'internal_error' => true );
			},
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
		$this->assertTrue( $result['check_fail_ignored'] );
	}

	public function testCzIcAresInternalErrorNotIgnored() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'ic'          => '27082440',
			'lookup_ares' => function ( $ico ) {
				return array( 'error' => 'ARES is not responding.', 'internal_error' => true );
			},
		) ) );
		$this->assertSame( array( 'invalid_business_id' ), $this->codes( $result ) );
		$this->assertFalse( $result['check_fail_ignored'] );
	}

	public function testCzIcAresFalsyUnexpected() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'ic'          => '27082440',
			'lookup_ares' => function ( $ico ) { return false; },
		) ) );
		$this->assertSame( array( 'ares_unexpected' ), $this->codes( $result ) );
	}

	public function testCzIcAresFalsyIgnored() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'ic'                => '27082440',
			'ignore_check_fail' => true,
			'lookup_ares'       => function ( $ico ) { return false; },
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
		$this->assertTrue( $result['check_fail_ignored'] );
	}

	public function testCzIcAresFillMismatch() {
		$ares = array(
			'error'      => false,
			'dic'        => 'CZ27082440',
			'spolecnost' => 'Alza.cz a.s.',
			'psc'        => '17000',
			'mesto'      => 'Praha 7',
			'adresa'     => 'Jankovcova 1522/53',
		);
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'ic'          => '27082440',
			'ares_fill'   => true,
			// All form values intentionally differ from ARES.
			'dic'         => 'CZ99999999',
			'company'     => 'Wrong s.r.o.',
			'postcode'    => '10000',
			'city'        => 'Brno',
			'address_1'   => 'Somewhere 1',
			'lookup_ares' => function ( $ico ) use ( $ares ) { return $ares; },
		) ) );
		$this->assertSame( array( 'ares_mismatch' ), $this->codes( $result ) );
		$this->assertSame(
			array( 'tax_id', 'company', 'postcode', 'city', 'address' ),
			$result['errors'][0]['data']['fields']
		);
	}

	public function testCzIcAresFillAllMatch() {
		$ares = array(
			'error'      => false,
			'dic'        => 'CZ27082440',
			'spolecnost' => 'Alza.cz a.s.',
			'psc'        => '17000',
			'mesto'      => 'Praha 7',
			'adresa'     => 'Jankovcova 1522/53',
		);
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'ic'          => '27082440',
			'ares_fill'   => true,
			'dic'         => 'CZ27082440',
			'company'     => 'Alza.cz a.s.',
			'postcode'    => 17000,
			'city'        => 'Praha 7',
			'address_1'   => 'Jankovcova 1522/53',
			'lookup_ares' => function ( $ico ) use ( $ares ) { return $ares; },
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
	}

	public function testCzIcAresFillDicAbsentSkipsTaxIdMismatch() {
		// Quirk preserved from the original: when billing_dic is absent from the
		// request, the Tax ID mismatch check is skipped entirely (isset guard).
		$ares = array(
			'error'      => false,
			'dic'        => 'CZ27082440',
			'spolecnost' => 'Alza.cz a.s.',
			'psc'        => '17000',
			'mesto'      => 'Praha 7',
			'adresa'     => 'Jankovcova 1522/53',
		);
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'ic'          => '27082440',
			'ares_fill'   => true,
			'dic'         => '',
			'dic_present' => false,
			'company'     => 'Alza.cz a.s.',
			'postcode'    => 17000,
			'city'        => 'Praha 7',
			'address_1'   => 'Jankovcova 1522/53',
			'lookup_ares' => function ( $ico ) use ( $ares ) { return $ares; },
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
	}

	// --- SK Business ID ----------------------------------------------------

	public function testSkIcInvalid() {
		// Provide a valid DIC so the SK-required-DIC rule does not also fire,
		// isolating the IC validation.
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country' => 'SK',
			'ic'      => '27082441',
			'dic'     => '2020317057',
		) ) );
		$this->assertSame( array( 'invalid_business_id' ), $this->codes( $result ) );
	}

	// --- CZ / SK DIC, mathematical path (VIES off or SK) -------------------

	public function testCzDicMathValid() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'dic'        => 'CZ27082440',
			'vies_check' => false,
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
	}

	public function testCzDicMathInvalid() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'dic'        => 'CZ12345678',
			'vies_check' => false,
		) ) );
		$this->assertSame( array( 'invalid_dic_cz' ), $this->codes( $result ) );
	}

	public function testSkDicMathValid() {
		// SK never goes through VIES, even with vies_check on.
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country' => 'SK',
			'dic'     => '2020317057',
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
	}

	public function testSkDicMathInvalid() {
		// 9 digits — fails the SK DIC 10-digit numeric check.
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country' => 'SK',
			'dic'     => '123456789',
		) ) );
		$this->assertSame( array( 'invalid_tax_id_sk' ), $this->codes( $result ) );
	}

	public function testDicNotInEuSkipsBlock() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'       => 'US',
			'dic'           => 'GARBAGE',
			'country_in_eu' => false,
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
	}

	// --- EU DIC, VIES path -------------------------------------------------

	public function testEuDicViesValid() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'    => 'DE',
			'dic'        => 'DE123456789',
			'verify_vat' => function ( $v ) { return 'valid'; },
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
	}

	public function testEuDicViesInvalid() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'    => 'DE',
			'dic'        => 'DE123456789',
			'verify_vat' => function ( $v ) { return 'invalid'; },
		) ) );
		$this->assertSame( array( 'invalid_vat' ), $this->codes( $result ) );
	}

	// A Czech business that is no VAT payer: its DIČ is real, VIES does not list it.
	public function testCzDicNotInViesOrdersWithVat() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'dic'        => 'CZ27082440',
			'verify_vat' => function ( $v ) { return 'invalid'; },
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
		$this->assertSame( array( array( 'code' => 'vat_not_registered' ) ), $result['notices'] );
	}

	/**
	 * @dataProvider taxNumbers
	 */
	public function testTaxNumberValid( $vat, $valid ) {
		$this->assertSame( $valid, woolab_icdic_tax_number_valid( $vat ) );
	}

	public static function taxNumbers() {
		return array(
			'CZ IČO-based'      => array( 'CZ27082440', true ),
			'CZ bad checksum'   => array( 'CZ27082441', false ),
			'PL NIP'            => array( 'PL5260250995', true ),
			'PL bad checksum'   => array( 'PL5260250994', false ),
			'PL too short'      => array( 'PL526025099', false ),
			'HU adószám'        => array( 'HU12892312', true ),
			'HU bad checksum'   => array( 'HU12892313', false ),
			'DE: not this kind' => array( 'DE123456789', false ),
		);
	}

	/**
	 * @dataProvider notVatPayers
	 */
	public function testPlAndHuTaxNumbersNotInViesOrderWithVat( $country, $vat ) {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'    => $country,
			'dic'        => $vat,
			'verify_vat' => function ( $v ) { return 'invalid'; },
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
		$this->assertSame( array( array( 'code' => 'vat_not_registered' ) ), $result['notices'] );
	}

	public static function notVatPayers() {
		return array(
			'PL' => array( 'PL', 'PL5260250995' ),
			'HU' => array( 'HU', 'HU12892312' ),
		);
	}

	/**
	 * @dataProvider domesticTaxNumbers
	 */
	public function testTaxNumberWithoutCountryCodeSkipsVies( $country, $number ) {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'    => $country,
			'dic'        => $number,
			'verify_vat' => function ( $v ) { throw new \Exception( 'VIES asked' ); },
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
		$this->assertSame( array( array( 'code' => 'tax_number_only' ) ), $result['notices'] );
	}

	public static function domesticTaxNumbers() {
		return array(
			'PL NIP'              => array( 'PL', '5260250995' ),
			'PL NIP with dashes'  => array( 'PL', '526-025-09-95' ),
			'HU adószám'          => array( 'HU', '12892312' ),
			'HU adószám, in full' => array( 'HU', '12892312-2-41' ),
		);
	}

	public function testPlTaxNumberWithoutCountryCodeAndBadChecksumIsAnError() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'    => 'PL',
			'dic'        => '5260250994',
			'verify_vat' => function ( $v ) { return 'bad_format'; },
		) ) );
		$this->assertNotSame( array(), $this->codes( $result ) );
	}

	public function testCzDicNotInViesAndMalformedIsAnError() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'dic'        => 'CZ27082441',
			'verify_vat' => function ( $v ) { return 'invalid'; },
		) ) );
		$this->assertSame( array( 'invalid_vat' ), $this->codes( $result ) );
		$this->assertSame( array(), $result['notices'] );
	}

	public function testEuDicViesBadFormatEmitsBothNotices() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'    => 'DE',
			'dic'        => 'DExx',
			'verify_vat' => function ( $v ) { return 'bad_format'; },
		) ) );
		$this->assertSame( array( 'vat_format', 'invalid_vat' ), $this->codes( $result ) );
	}

	public function testEuDicViesUnverifiableNotIgnored() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'    => 'DE',
			'dic'        => 'DE123456789',
			'verify_vat' => function ( $v ) { return 'unverifiable'; },
		) ) );
		$this->assertSame( array( 'vat_unverifiable' ), $this->codes( $result ) );
		$this->assertFalse( $result['check_fail_ignored'] );
	}

	public function testEuDicViesUnverifiableIgnored() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'           => 'DE',
			'dic'               => 'DE123456789',
			'ignore_check_fail' => true,
			'verify_vat'        => function ( $v ) { return 'unverifiable'; },
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
		$this->assertTrue( $result['check_fail_ignored'] );
	}

	public function testEuDicViesBillingCountryMismatch() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country' => 'DE',
			'dic'     => 'FR123456789',
		) ) );
		$this->assertSame( array( 'vat_country_mismatch_billing' ), $this->codes( $result ) );
	}

	public function testEuDicViesShippingCountryMismatch() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'          => 'DE',
			'dic'              => 'DE123456789',
			'ship_to_different'=> true,
			'shipping_country' => 'FR',
		) ) );
		$this->assertSame( array( 'vat_country_mismatch_shipping' ), $this->codes( $result ) );
	}

	public function testEuDicViesCountryMatchDisabled() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'             => 'DE',
			'dic'                 => 'FR123456789',
			'check_country_match' => false,
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
	}

	// --- SK required IC + DIC ----------------------------------------------

	public function testSkRequiredDicMissing() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'               => 'SK',
			'ic'                    => '27082440',
			'dic'                   => '',
			'require_dic_for_firms' => true,
		) ) );
		$this->assertSame( array( 'invalid_tax_id_sk' ), $this->codes( $result ) );
	}

	public function testSkRequiredDisabledNoError() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'               => 'SK',
			'ic'                    => '27082440',
			'dic'                   => '',
			'require_dic_for_firms' => false,
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
	}

	// --- "Required" company fields -----------------------------------------

	/**
	 * @dataProvider requiredModeCases
	 */
	public function testRequiredMode( array $over, array $codes ) {
		$base = array(
			'require_company' => true,
			'require_ic'      => true,
			'toggle_enabled'  => true,
			'iscomp'          => true,
		);
		$this->assertSame( $codes, $this->codes( woolab_icdic_validate_checkout( $this->input( array_merge( $base, $over ) ) ) ) );
	}

	public static function requiredModeCases() {
		return array(
			'CZ company, nothing entered'        => array( array(), array( 'missing_company', 'missing_business_id' ) ),
			'CZ company, all entered'            => array( array( 'company' => 'Alza.cz a.s.', 'ic' => '27082440', 'ares_check' => false ), array() ),
			'SK non-profit, no Tax ID'           => array( array( 'country' => 'SK', 'company' => 'Združenie o.z.', 'ic' => '27082440', 'require_dic_for_firms'           => false ), array() ),
			'SK, Tax ID required by the store'   => array( array( 'country' => 'SK', 'company' => 'Firma s.r.o.', 'ic' => '27082440', 'require_dic_for_firms' => true ), array( 'invalid_tax_id_sk' ) ),
			'SK, Tax ID required, no IČO either' => array( array( 'country' => 'SK', 'company' => 'Firma s.r.o.', 'require_dic_for_firms' => true ), array( 'missing_business_id', 'invalid_tax_id_sk' ) ),
			'CZ shop, DIČ required by the store' => array( array( 'company' => 'Firma s.r.o.', 'ic' => '27082440', 'ares_check' => false, 'require_dic_for_firms' => true ), array( 'missing_dic' ) ),
			'CZ non-profit, no DIČ'              => array( array( 'company' => 'Spolek z.s.', 'ic' => '27082440', 'ares_check' => false ), array() ),
			'AT company, no Business ID asked'   => array( array( 'country' => 'AT', 'company' => 'Firma GmbH', 'require_ic' => false ), array() ),
			'not buying as a company'            => array( array( 'iscomp' => false ), array() ),
			'mode off'                           => array( array( 'require_company' => false, 'require_ic' => false ), array() ),
			'no toggle: a company name is one'   => array( array( 'toggle_enabled' => false, 'company' => 'Alza.cz a.s.' ), array( 'missing_business_id' ) ),
			'no toggle, no company name: no one' => array( array( 'toggle_enabled' => false ), array() ),
		);
	}

	// --- SK IC DPH ---------------------------------------------------------

	public function testIcDphViesValidAndMatches() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'    => 'SK',
			'dic'        => '2020317057',
			'dic_dph'    => 'SK2020317057',
			'verify_vat' => function ( $v ) { return 'valid'; },
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
	}

	public function testIcDphViesInvalid() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'    => 'SK',
			'dic'        => '2020317057',
			'dic_dph'    => 'SK2020317057',
			'verify_vat' => function ( $v ) { return 'invalid'; },
		) ) );
		$this->assertSame( array( 'invalid_vat_dph' ), $this->codes( $result ) );
	}

	public function testIcDphMathInvalid() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'    => 'SK',
			'dic_dph'    => 'SK2020317058',
			'vies_check' => false,
		) ) );
		$this->assertSame( array( 'invalid_vat_dph' ), $this->codes( $result ) );
	}

	public function testIcDphCountryMismatch() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'    => 'SK',
			'dic_dph'    => 'CZ2020317057',
			'vies_check' => false,
		) ) );
		// Country-prefix mismatch first, then the non-VIES math check fails too,
		// then the DIC/DIC-DPH match check is skipped (no $dic). Order preserved.
		$this->assertSame(
			array( 'vat_country_mismatch_billing', 'invalid_vat_dph' ),
			$this->codes( $result )
		);
	}

	public function testIcDphDoesNotMatchDic() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country' => 'SK',
			'dic'     => '2020317057',
			'dic_dph' => 'SK9999999999',
		) ) );
		$this->assertSame( array( 'dic_dph_mismatch' ), $this->codes( $result ) );
	}

	public function testIcDphMatchCheckDisabled() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'             => 'SK',
			'dic'                 => '2020317057',
			'dic_dph'             => 'SK9999999999',
			'check_dic_dph_match' => false,
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
	}

	public function testIcDphViesUnverifiableIgnored() {
		$result = woolab_icdic_validate_checkout( $this->input( array(
			'country'           => 'SK',
			'dic'               => '2020317057',
			'dic_dph'           => 'SK2020317057',
			'ignore_check_fail' => true,
			'verify_vat'        => function ( $v ) { return 'unverifiable'; },
		) ) );
		$this->assertSame( array(), $this->codes( $result ) );
		$this->assertTrue( $result['check_fail_ignored'] );
	}

	// --- VAT-exemption helpers (candidate 3) -------------------------------

	/**
	 * @dataProvider providerVatExemptFromState
	 */
	public function testVatExemptFromState( $state, $ignore, $expected ) {
		$this->assertSame( $expected, woolab_icdic_vat_exempt_from_state( $state, $ignore ) );
	}

	public static function providerVatExemptFromState() {
		return array(
			'valid is exempt (ignore off)'        => array( 'valid', false, true ),
			'valid is exempt (ignore on)'         => array( 'valid', true, true ),
			'invalid not exempt'                  => array( 'invalid', false, false ),
			'invalid not exempt (ignore on)'      => array( 'invalid', true, false ),
			'bad format not exempt'               => array( 'bad_format', false, false ),
			'bad format not exempt (ignore on)'   => array( 'bad_format', true, false ),
			'unverifiable follows ignore: off'    => array( 'unverifiable', false, false ),
			'unverifiable follows ignore: on'     => array( 'unverifiable', true, true ),
		);
	}

	/**
	 * @dataProvider providerSelectVatNumber
	 */
	public function testSelectVatNumber( $country, $dic, $dic_dph, $expected ) {
		$this->assertSame( $expected, woolab_icdic_select_vat_number( $country, $dic, $dic_dph ) );
	}

	public static function providerSelectVatNumber() {
		return array(
			'SK uses dic_dph'        => array( 'SK', '2020317057', 'SK2020317057', 'SK2020317057' ),
			'CZ uses dic'            => array( 'CZ', 'CZ27082440', '', 'CZ27082440' ),
			'other EU uses dic'      => array( 'DE', 'DE123456789', '', 'DE123456789' ),
			'SK empty dic_dph'       => array( 'SK', '2020317057', '', '' ),
			'CZ empty dic'           => array( 'CZ', '', '', '' ),
		);
	}
}
