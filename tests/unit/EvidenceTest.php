<?php

namespace KybernautIcDic\Test;

use Brain\Monkey\Functions;
use KybernautIcDic\Order\Evidence;
use KybernautIcDic\Order\Privacy;
use KybernautIcDic\Services\Vies\Vies;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The VAT ID evidence kept with an order (src/Order/Evidence.php): its shape, the
 * reason, the location, and what the admin's "Validate VAT ID" does to the VAT.
 */
class EvidenceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// A Slovak shop, Czech and Slovak VAT countries, the default settings.
		$countries = Mockery::mock( 'WC_Countries' );
		$countries->shouldReceive( 'get_base_country' )->andReturn( 'SK' );
		$countries->shouldReceive( 'get_european_union_countries' )->andReturn( array( 'CZ', 'SK', 'DE', 'GR' ) );
		Functions\when( 'WC' )->justReturn( (object) array( 'countries' => $countries ) );
		Functions\when( 'get_option' )->justReturn( array( 'vat_exempt' => 'yes', 'shop_vat_id' => 'SK2020317068' ) );
		Functions\when( 'wc_tax_enabled' )->justReturn( true );
	}

	#[DataProvider( 'reasons' )]
	public function testReason( $state, $exempt, $ignore, $expected ) {
		$this->assertSame( $expected, Evidence::reason( $state, $exempt, $ignore ) );
	}

	public static function reasons() {
		return array(
			'valid, exempt'           => array( 'valid', true, false, 'reverse_charge' ),
			'invalid'                 => array( 'invalid', false, false, 'with_vat' ),
			'outage, let through'     => array( 'unverifiable', true, true, 'check_failed_ignored' ),
			'outage, not let through' => array( 'unverifiable', false, false, 'with_vat' ),
		);
	}

	#[DataProvider( 'decisions' )]
	public function testRevalidateDecision( $state, $eu, $foreign, $expected ) {
		$this->assertSame( $expected, Evidence::decide( $state, $eu, $foreign ) );
	}

	public static function decisions() {
		return array(
			'valid, other EU country: exempt'   => array( 'valid', true, true, true ),
			'valid, the shop\'s country: VAT'   => array( 'valid', true, false, false ),
			'invalid: VAT'                      => array( 'invalid', true, true, false ),
			'bad format: VAT'                   => array( 'bad_format', true, true, false ),
			'VIES down: left as it is'          => array( 'unverifiable', true, true, null ),
			'outside the EU: left as it is'     => array( 'valid', false, false, null ),
		);
	}

	#[DataProvider( 'locations' )]
	public function testLocation( $ip, $billing, $vat, $expected ) {
		$this->assertSame( $expected, Evidence::location( $ip, $billing, $vat )['location_match'] );
	}

	public static function locations() {
		return array(
			'all agree'           => array( 'CZ', 'CZ', 'CZ', 'yes' ),
			'IP abroad'           => array( 'AT', 'CZ', 'CZ', 'no' ),
			'billing not the VAT' => array( 'CZ', 'DE', 'CZ', 'no' ),
			'IP unknown'          => array( '', 'CZ', 'CZ', '' ),
		);
	}

	public function testMismatchIsANoteOnly() {
		$this->assertSame( '', Evidence::mismatch( Evidence::location( 'CZ', 'CZ', 'CZ' ) ) );
		$this->assertStringContainsString( 'IP address AT, billing CZ, VAT ID CZ', Evidence::mismatch( Evidence::location( 'AT', 'CZ', 'CZ' ) ) );
	}

	private function order( array $meta, $country = 'CZ', $ip = '' ) {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_billing_country' )->andReturn( $country );
		$order->shouldReceive( 'get_customer_ip_address' )->andReturn( $ip );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( static fn ( $key ) => $meta[ $key ] ?? '' );
		return $order;
	}

	public function testCheckoutOrderKeepsTheCachedAnswer() {
		$cached = Vies::answer( 200, json_encode( array( 'isValid' => true, 'userError' => 'VALID', 'requestDate' => '2026-10-25T22:58:49.229Z', 'requestIdentifier' => 'WAPIAAAAaF1SbcSt', 'name' => 'Alza.cz a.s.', 'address' => 'Jankovcova 1522/53' ) ) );
		Functions\when( 'get_transient' )->justReturn( $cached );
		Functions\expect( 'wp_remote_get' )->never();

		$order = $this->order( array( '_billing_dic' => 'CZ27082440', 'is_vat_exempt' => 'yes' ) );
		$saved = null;
		$order->shouldReceive( 'update_meta_data' )->once()->with( Evidence::META, Mockery::capture( $saved ) );

		Evidence::record( $order );

		$this->assertSame(
			array( 'vat_number', 'state', 'reason', 'checked_at', 'requested_at', 'consultation_number', 'name', 'address', 'source', 'ip_country', 'billing_country', 'vat_country', 'location_match' ),
			array_keys( $saved )
		);
		$this->assertSame( 'CZ27082440', $saved['vat_number'] );
		$this->assertSame( 'valid', $saved['state'] );
		$this->assertSame( 'reverse_charge', $saved['reason'] );
		$this->assertSame( 'WAPIAAAAaF1SbcSt', $saved['consultation_number'] );
		// The date VIES checked it, not when the cached answer was read.
		$this->assertSame( '2026-10-25T22:58:49.229Z', $saved['requested_at'] );
		$this->assertSame( 'vies', $saved['source'] );
		// No IP address on the order: no IP country, and nothing geolocated.
		$this->assertSame( '', $saved['ip_country'] );
		$this->assertSame( 'CZ', $saved['vat_country'] );
	}

	public function testConsumerAndDomesticOrdersGetNothing() {
		Functions\expect( 'wp_remote_get' )->never();

		$consumer = $this->order( array() );
		$consumer->shouldNotReceive( 'update_meta_data' );
		Evidence::record( $consumer );

		$domestic = $this->order( array( '_billing_dic_dph' => 'SK2020317068' ), 'SK' );
		$domestic->shouldNotReceive( 'update_meta_data' );
		Evidence::record( $domestic );
	}

	public function testEraserKeepsTheEvidence() {
		Functions\when( 'get_user_by' )->justReturn( false );
		$order = $this->order( array( Evidence::META => array( 'vat_number' => 'CZ27082440' ) ) );
		$order->shouldReceive( 'get_order_number' )->andReturn( '42' );
		$order->shouldNotReceive( 'delete_meta_data' );
		Functions\when( 'wc_get_orders' )->justReturn( array( $order ) );

		$result = Privacy::erase( 'buyer@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertStringContainsString( 'order 42', $result['messages'][0] );
		$this->assertTrue( $result['done'] );
	}

	public function testExporterListsTheEvidence() {
		Functions\when( 'get_user_by' )->justReturn( false );
		$order = $this->order( array( Evidence::META => array( 'vat_number' => 'CZ27082440', 'consultation_number' => 'WAPI1', 'ip_country' => '' ) ) );
		$order->shouldReceive( 'get_order_number' )->andReturn( '42' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		Functions\when( 'wc_get_orders' )->justReturn( array( $order ) );

		$export = Privacy::export( 'buyer@example.com' );

		$this->assertSame(
			array(
				array( 'name' => 'Order number', 'value' => '42' ),
				array( 'name' => 'VAT ID', 'value' => 'CZ27082440' ),
				array( 'name' => 'Consultation number', 'value' => 'WAPI1' ),
			),
			$export['data'][0]['data']
		);
	}
}
