<?php

namespace KybernautIcDic\Test;

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
}
