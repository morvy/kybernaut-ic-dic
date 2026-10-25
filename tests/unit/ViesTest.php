<?php

namespace KybernautIcDic\Test;

use Brain\Monkey\Functions;
use KybernautIcDic\Services\Vies\Vies;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * VIES REST answers mapped to the verifier's states (includes/vies.php). The
 * answers are the shapes the live API returned in October 2026.
 */
class ViesTest extends TestCase {

	#[DataProvider( 'providerAnswers' )]
	public function testAnswers( $code, $body, $expected ) {
		$this->assertSame( $expected, woolab_icdic_vies_state( $code, json_encode( $body ) ) );
	}

	public static function providerAnswers() {
		return array(
			'valid'             => array( 200, array( 'isValid' => true, 'userError' => 'VALID' ), 'valid' ),
			'invalid'           => array( 200, array( 'isValid' => false, 'userError' => 'INVALID' ), 'invalid' ),
			'invalid input'     => array( 200, array( 'isValid' => false, 'userError' => 'INVALID_INPUT' ), 'invalid' ),
			'member state down' => array( 200, array( 'isValid' => false, 'userError' => 'MS_UNAVAILABLE' ), 'unverifiable' ),
			'too many requests' => array( 200, array( 'isValid' => false, 'userError' => 'MS_MAX_CONCURRENT_REQ' ), 'unverifiable' ),
			'server error'      => array( 500, null, 'unverifiable' ),
			'request failed'    => array( 0, null, 'unverifiable' ),
		);
	}

	public function testSplitsCountryPrefix() {
		$this->assertSame( 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/EL/vat/094014201', woolab_icdic_vies_url( 'EL094014201' ) );
	}
	public function testRequesterInTheUrl() {
		$this->assertSame(
			'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/CZ/vat/27082440?requesterMemberStateCode=SK&requesterNumber=2020317068',
			Vies::url( 'CZ27082440', 'SK2020317068' )
		);
	}

	// The live API's answer with a requester (October 2026).
	private static function validBody() {
		return json_encode(
			array(
				'isValid'           => true,
				'requestDate'       => '2026-10-25T22:58:49.229Z',
				'userError'         => 'VALID',
				'name'              => 'Alza.cz a.s.',
				'address'           => "Jankovcova 1522/53\nPRAHA 7 - HOLEŠOVICE\n170 00  PRAHA 7",
				'requestIdentifier' => 'WAPIAAAAaF1SbcSt',
				'vatNumber'         => '27082440',
			)
		);
	}

	public function testEvidenceParsed() {
		$this->assertSame(
			array(
				'state'    => 'valid',
				'evidence' => array(
					'requested_at'        => '2026-10-25T22:58:49.229Z',
					'consultation_number' => 'WAPIAAAAaF1SbcSt',
					'name'                => 'Alza.cz a.s.',
					'address'             => "Jankovcova 1522/53\nPRAHA 7 - HOLEŠOVICE\n170 00  PRAHA 7",
					'source'              => 'vies',
				),
			),
			Vies::answer( 200, self::validBody() )
		);
	}

	public function testNoEvidenceWithoutAnAnswer() {
		$this->assertSame( array( 'state' => 'unverifiable' ), Vies::answer( 200, json_encode( array( 'isValid' => false, 'userError' => 'MS_UNAVAILABLE' ) ) ) );
	}

	public function testDashesAreEmpty() {
		$answer = Vies::answer( 200, json_encode( array( 'isValid' => false, 'userError' => 'INVALID', 'name' => '---', 'address' => '---', 'requestIdentifier' => '' ) ) );
		$this->assertSame( 'invalid', $answer['state'] );
		$this->assertSame( '', $answer['evidence']['name'] );
		$this->assertSame( '', $answer['evidence']['address'] );
	}

	private function shopVatId( $vat ) {
		Functions\when( 'get_option' )->justReturn( array( 'shop_vat_id' => $vat ) );
	}

	private function http( $body, &$urls ) {
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) use ( &$body, &$urls ) {
				$urls[] = $url;
				return array( 'body' => is_array( $body ) ? array_shift( $body ) : $body );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $response ) => $response['body'] );
	}

	public function testRequestNamesTheShopAndCachesTheWholeAnswer() {
		$this->shopVatId( 'sk 2020317068' );
		$urls = array();
		$this->http( self::validBody(), $urls );
		$stored = null;
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$answer = ( new Vies() )->validate_vat_id( 'CZ27082440' );

		$this->assertSame( array( 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/CZ/vat/27082440?requesterMemberStateCode=SK&requesterNumber=2020317068' ), $urls );
		$this->assertSame( 'valid', $answer['state'] );
		$this->assertSame( 'WAPIAAAAaF1SbcSt', $answer['evidence']['consultation_number'] );
		$this->assertSame( array( 'state' => 'valid' ) + array( 'evidence' => $answer['evidence'] ), $stored );
	}

	public function testCachedAnswerKeepsItsEvidence() {
		$this->shopVatId( 'SK2020317068' );
		$cached = Vies::answer( 200, self::validBody() );
		Functions\when( 'get_transient' )->justReturn( $cached );
		Functions\expect( 'wp_remote_get' )->never();

		$answer = ( new Vies() )->validate_vat_id( 'CZ27082441' );

		$this->assertSame( 'valid', $answer['state'] );
		$this->assertSame( '2026-10-25T22:58:49.229Z', $answer['evidence']['requested_at'] );
		$this->assertSame( 'WAPIAAAAaF1SbcSt', $answer['evidence']['consultation_number'] );
	}

	public function testFreshBypassesTheCache() {
		$this->shopVatId( '' );
		Functions\when( 'get_transient' )->justReturn( array( 'state' => 'invalid' ) );
		$urls = array();
		$this->http( self::validBody(), $urls );

		$this->assertSame( 'valid', ( new Vies() )->check( 'CZ27082442', true )['state'] );
		$this->assertSame( array( 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/CZ/vat/27082442' ), $urls );
	}

	public function testRejectedRequesterIsDropped() {
		$this->shopVatId( 'CZ12345678' );
		Functions\when( 'wc_get_logger' )->justReturn( \Mockery::spy( 'WC_Logger' ) );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		$urls = array();
		$this->http( array( json_encode( array( 'isValid' => false, 'userError' => 'INVALID_REQUESTER_INFO' ) ), self::validBody() ), $urls );

		$this->assertSame( 'valid', ( new Vies() )->check( 'CZ27082443' )['state'] );
		$this->assertCount( 2, $urls );
		$this->assertStringNotContainsString( 'requester', $urls[1] );
	}
}
