<?php

namespace KybernautIcDic\Test;

use PHPUnit\Framework\TestCase;

/**
 * The SK branch of woolab_icdic_validate_checkout() against the Finančná správa
 * lists (includes/financna-sprava.php), faked from real October 2026 rows.
 */
class ValidationSkTest extends TestCase {

	private const LISTS = array(
		'dic' => array( '2023913485' => '47455411', '1085180173' => '53102461', '2020377381' => '34109340' ),
		'dph' => array( 'SK2023913485' => '47455411', 'SK7020000009' => '34109340' ),
	);

	private const SUBJECTS = array(
		'47455411' => array( 'error' => false, 'spolecnost' => 'Invelity s.r.o.', 'ico' => '47455411', 'dic' => '2023913485', 'dic_dph' => 'SK2023913485', 'adresa' => 'Strážska cesta 22', 'psc' => '96001', 'mesto' => 'Zvolen' ),
		'53102461' => array( 'error' => false, 'spolecnost' => 'Ing. Peter Morvay', 'ico' => '53102461', 'dic' => '1085180173', 'dic_dph' => null, 'adresa' => 'Bardejovské Kúpele 2054/81', 'psc' => '08501', 'mesto' => 'Bardejov' ),
		'34109340' => array( 'error' => false, 'spolecnost' => 'VAT group member', 'ico' => '34109340', 'dic' => '2020377381', 'dic_dph' => 'SK7020000009', 'adresa' => '', 'psc' => '', 'mesto' => '' ),
		'33024610' => array( 'error' => false, 'spolecnost' => 'X', 'ico' => '33024610', 'dic' => null, 'dic_dph' => null, 'adresa' => '', 'psc' => '', 'mesto' => '' ),
	);

	private function run_sk( array $over, $lists_available = true ) {
		$lists    = self::LISTS;
		$subjects = self::SUBJECTS;

		return woolab_icdic_validate_checkout( array_merge( array(
			'country'               => 'SK',
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
			'vies_check'            => false,
			'ignore_check_fail'     => false,
			'check_country_match'   => true,
			'require_dic_for_firms' => true,
			'check_dic_dph_match'   => true,
			'country_in_eu'         => true,
			'sk_check'              => true,
			'sk_fill'               => false,
			'lookup_ares'           => function () {
				throw new \Exception( 'ARES must not be called for SK' );
			},
			'lookup_sk_ico'         => function ( $ico ) use ( $subjects ) {
				return $subjects[ $ico ] ?? array( 'error' => 'not in the lists', 'unlisted' => true );
			},
			'lookup_sk'             => function ( $list, $num ) use ( $lists, $lists_available ) {
				if ( ! $lists_available ) {
					return false;
				}
				return isset( $lists[ $list ][ $num ] ) ? array( 'ico' => $lists[ $list ][ $num ] ) : null;
			},
			// Stand-in for the verifier's SK-list-first path with VIES off.
			'verify_vat'            => function ( $vat ) use ( $lists, $lists_available ) {
				if ( ! $lists_available ) {
					return 'unverifiable';
				}
				return isset( $lists['dph'][ $vat ] ) ? 'valid' : 'invalid';
			},
		), $over ) );
	}

	private function codes( array $result ) {
		return array_column( $result['errors'], 'code' );
	}

	public static function providerSk() {
		return array(
			'all valid'                                => array( array( 'ic' => '47455411', 'dic' => '2023913485', 'dic_dph' => 'SK2023913485' ), array() ),
			'sole trader, DIČ only'                    => array( array( 'ic' => '53102461', 'dic' => '1085180173' ), array() ),
			'sole trader with made-up IČ DPH'          => array( array( 'ic' => '53102461', 'dic' => '1085180173', 'dic_dph' => 'SK1085180173' ), array( 'invalid_vat_dph' ) ),
			'VAT group: IČ DPH != SK+DIČ, same IČO'    => array( array( 'ic' => '34109340', 'dic' => '2020377381', 'dic_dph' => 'SK7020000009' ), array() ),
			'VAT group, match check without lists'     => array( array( 'ic' => '34109340', 'dic' => '2020377381', 'dic_dph' => 'SK7020000009', 'sk_check' => false ), array( 'dic_dph_mismatch' ) ),
			'DIČ of another company'                   => array( array( 'ic' => '47455411', 'dic' => '1085180173' ), array( 'dic_ico_mismatch' ) ),
			'IČ DPH of another company'                => array( array( 'ic' => '53102461', 'dic' => '1085180173', 'dic_dph' => 'SK2023913485' ), array( 'dic_dph_mismatch' ) ),
			'unknown DIČ'                              => array( array( 'ic' => '47455411', 'dic' => '2023913496' ), array( 'invalid_tax_id_sk' ) ),
			'IČO not in the lists, accepted'           => array( array( 'ic' => '12345679', 'sk_fill' => true, 'company' => 'New s.r.o.', 'require_dic_for_firms'           => false ), array() ),
			'IČO not in the lists, foreign DIČ'        => array( array( 'ic' => '12345679', 'dic' => '2023913485' ), array( 'dic_ico_mismatch' ) ),
			'IČO failing checksum but listed'          => array( array( 'ic' => '33024610' ), array( 'invalid_tax_id_sk' ) ),
			'fill mismatch on city'                    => array( array( 'ic' => '47455411', 'dic' => '2023913485', 'sk_fill' => true, 'company' => 'Invelity s.r.o.', 'address_1' => 'Strážska cesta 22', 'postcode' => '960 01', 'city' => 'Bratislava' ), array( 'sk_mismatch' ) ),
			'IČ DPH under other IČO, opt-out on'       => array( array( 'ic' => '47455411', 'dic' => '2023913485', 'dic_dph' => 'SK7020000009', 'check_dic_dph_match' => false ), array() ),
			'IČ DPH under other IČO, check on'         => array( array( 'ic' => '47455411', 'dic' => '2023913485', 'dic_dph' => 'SK7020000009' ), array( 'dic_dph_mismatch' ) ),
			'fill, null DIČ not compared'              => array( array( 'ic' => '33024610', 'dic' => '1085180173', 'sk_fill' => true, 'company' => 'X' ), array( 'dic_ico_mismatch' ) ),
		);
	}

	/**
	 * @dataProvider providerSk
	 */
	public function testSk( array $over, array $expected ) {
		$this->assertSame( $expected, $this->codes( $this->run_sk( $over ) ) );
	}

	public function testIcoLookupUnavailable() {
		$result = $this->run_sk( array(
			'ic'                    => '47455411',
			'require_dic_for_firms' => false,
			'lookup_sk_ico'         => function () {
				return array( 'error' => 'down', 'internal_error' => true );
			},
		) );
		$this->assertSame( array( 'invalid_business_id' ), $this->codes( $result ) );
	}

	public function testFillWithDicUnknownToListsKeepsEnteredDic() {
		$result = $this->run_sk( array(
			'ic'        => '33024610',
			'dic'       => '2020377381',
			'sk_fill'   => true,
			'company'   => 'X',
			'lookup_sk' => function () {
				return array( 'ico' => '' );
			},
		) );
		$this->assertSame( array(), $this->codes( $result ) );
	}

	public function testListsUnavailableBlocks() {
		$result = $this->run_sk( array( 'ic' => '47455411', 'dic' => '2023913485', 'dic_dph' => 'SK2023913485' ), false );
		$this->assertSame( array( 'sk_unverifiable', 'vat_unverifiable' ), $this->codes( $result ) );
	}

	public function testListsUnavailableIgnored() {
		$result = $this->run_sk( array( 'ic' => '47455411', 'dic' => '2023913485', 'dic_dph' => 'SK2023913485', 'ignore_check_fail' => true ), false );
		$this->assertSame( array(), $this->codes( $result ) );
		$this->assertTrue( $result['check_fail_ignored'] );
	}

	public function testCzStillUsesAres() {
		$ares   = array( 'error' => false, 'spolecnost' => 'A', 'ico' => '27082440', 'dic' => 'CZ27082440', 'adresa' => 'B 1', 'psc' => '11000', 'mesto' => 'Praha' );
		$result = $this->run_sk( array(
			'country'     => 'CZ',
			'ic'          => '27082440',
			'ares_fill'   => true,
			'company'     => 'A',
			'address_1'   => 'B 1',
			'postcode'    => '110 00',
			'city'        => 'Praha',
			'dic'         => 'CZ27082440',
			'lookup_ares' => function () use ( $ares ) {
				return $ares;
			},
		) );
		$this->assertSame( array(), $this->codes( $result ) );
	}
}
