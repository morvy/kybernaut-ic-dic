<?php

namespace KybernautIcDic\Test;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The ARES lookup (includes/ares.php): the IČO format, the HTTP failures, and
 * real subjects parsed from the live register.
 */
class AresTest extends TestCase {

	// The live register: these tests read real subjects from ARES.
	private function liveAres() {
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url ) {
				$curl = curl_init( $url );
				curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
				return curl_exec( $curl );
			}
		);
	}

	public function testIcoNotSet() {
		$this->assertSame( array( 'error' => 'Business ID not set.' ), woolab_icdic_ares() );
	}

	#[DataProvider( 'badFormats' )]
	public function testBadFormat( $ico ) {
		$this->assertSame( array( 'error' => 'Business ID must be a number and 8 digits long.' ), woolab_icdic_ares( $ico ) );
	}

	public static function badFormats() {
		return array(
			'too short'    => array( 123 ),
			'too long'     => array( 123456789 ),
			'with letters' => array( '1234567a' ),
		);
	}

	public function testWpError() {
		Functions\expect( 'wp_remote_get' )->once()->andReturn( false );
		Functions\expect( 'is_wp_error' )->once()->andReturn( true );

		$this->assertSame(
			array( 'error' => 'An error occured while connecting to ARES, try it again later.', 'internal_error' => true ),
			woolab_icdic_ares( 88922961 )
		);
	}

	#[DataProvider( 'subjects' )]
	public function testLiveSubjectParsed( $ico, $expected ) {
		$this->liveAres();
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturnFirstArg();

		$this->assertSame( $expected, woolab_icdic_ares( $ico ) );
	}

	public function testNotFound() {
		$this->liveAres();
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 404 );
		Functions\when( 'wp_remote_retrieve_body' )->returnArg();

		$this->assertSame( array( 'error' => "Entity doesn't exist in ARES." ), woolab_icdic_ares( 88922961 ) );
	}

	public function testOtherStatusCode() {
		$this->liveAres();
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 500 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturnFirstArg();

		$this->assertSame( array( 'error' => 'ARES is not responding.', 'internal_error' => true ), woolab_icdic_ares( 88922961 ) );
	}

	public static function subjects() {
		$subject = static fn ( $name, $ico, $dic, $address, $postcode, $city ) => array(
			'error'      => false,
			'spolecnost' => $name,
			'ico'        => $ico,
			'dic'        => $dic,
			'adresa'     => $address,
			'psc'        => $postcode,
			'mesto'      => $city,
		);

		return array(
			'88922961 (podnikatel, Praha)'                => array( 88922961, $subject( 'Karolína Vyskočilová', '88922961', 'CZ8956190067', 'Za Pohořelcem 672/15', '16900', 'Praha 6' ) ),
			'45211515 (vesnice bez ulice)'                => array( 45211515, $subject( 'Základní škola Valašská Polanka, okres Vsetín', '45211515', '', 'Valašská Polanka 301', '75611', 'Valašská Polanka' ) ),
			'16415345 (orientační číslo s písmenem)'      => array( 16415345, $subject( 'Roman Korecký', '16415345', 'CZ6207141193', 'Ruprechtická 319/16a', '46001', 'Liberec' ) ),
			'27082440 (firma, Praha)'                     => array( 27082440, $subject( 'Alza.cz a.s.', '27082440', 'CZ27082440', 'Jankovcova 1522/53', '17000', 'Praha 7' ) ),
			'02491427 (firma, Písek, úvodní nula)'        => array( '02491427', $subject( 'Západočeská společnost, o.s.', '02491427', '', 'Budovcova 105/4', '39701', 'Písek' ) ),
			'26219981 (firma, Brno)'                      => array( '26219981', $subject( 'JUMO Měření a regulace s.r.o.', '26219981', 'CZ26219981', 'Křídlovická 943/24a', '60300', 'Brno' ) ),
			// The DIČ is left out while the VAT registration has ended.
			'08911801 (zaniklá registrace k DPH)'         => array( '08911801', $subject( 'Petra Malichová', '08911801', '', 'Žádovice 291', '69649', 'Žádovice' ) ),
		);
	}
}
