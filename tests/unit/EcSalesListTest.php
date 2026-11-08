<?php

namespace KybernautIcDic\Test;

use Brain\Monkey\Functions;
use KybernautIcDic\EcSalesList\ExportPage;
use KybernautIcDic\EcSalesList\Report;
use KybernautIcDic\EcSalesList\Xml;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The EC Sales List (src/EcSalesList/): the rows (goods / services, rounding,
 * refunds, currencies), the CSV, and the SK and CZ files against their XSDs
 * (tests/fixtures/, from .omc/research/xsd/).
 */
class EcSalesListTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WOOLAB_IC_DIC_VERSION' ) ) {
			define( 'WOOLAB_IC_DIC_VERSION', '2.0.0' );
		}
	}

	private static function entry( $order_id, $country, $vat, $goods, $services = 0.0, $currency = 'EUR', $refund_id = 0, $unverified = false ) {
		return array(
			'order_id'   => $order_id,
			'refund_id'  => $refund_id,
			'currency'   => $currency,
			'country'    => $country,
			'vat'        => $vat,
			'goods'      => (float) $goods,
			'services'   => (float) $services,
			'flag'       => $unverified ? 'unverified' : '',
		);
	}

	public function testOrdersOfOneVatNumberMergeAndSplitIntoGoodsAndServices() {
		$rows = Report::rows(
			array(
				self::entry( 1, 'CZ', '27082440', 100.20, 50.00 ),
				self::entry( 2, 'CZ', '27082440', 10.10 ),
				self::entry( 3, 'DE', '123456789', 0, 20.00 ),
			),
			'csv'
		)['rows'];

		$this->assertSame(
			array(
				array( 'CZ', '27082440', 'goods', 110.30, 2 ),
				array( 'CZ', '27082440', 'services', 50.00, 1 ),
				array( 'DE', '123456789', 'services', 20.00, 1 ),
			),
			array_map( static fn ( $row ) => array( $row['country'], $row['vat'], $row['kind'], $row['value'], $row['orders'] ), $rows )
		);
	}

	#[DataProvider( 'roundings' )]
	public function testRoundingOncePerRow( $values, $format, $expected ) {
		$entries = array();
		foreach ( $values as $i => $value ) {
			$entries[] = self::entry( $i + 1, 'DE', '123456789', $value, 0, 'cz' === $format ? 'CZK' : 'EUR' );
		}

		$this->assertSame( $expected, Report::rows( $entries, $format )['rows'][0]['value'] );
	}

	public static function roundings() {
		return array(
			'SK half up: .50 up'          => array( array( 10.50 ), 'sk', 11.0 ),
			'SK half up: .49 down'        => array( array( 10.49 ), 'sk', 10.0 ),
			'SK: the sum is rounded'      => array( array( 10.25, 10.25 ), 'sk', 21.0 ),
			'CZ up: .01 up'               => array( array( 100.01 ), 'cz', 101.0 ),
			'CZ up: whole stays'          => array( array( 100.00 ), 'cz', 100.0 ),
			'CZ: the sum is rounded'      => array( array( 0.40, 0.40 ), 'cz', 1.0 ),
			'CSV: cents'                  => array( array( 0.1, 0.2 ), 'csv', 0.3 ),
			'CZ negative: up, toward 0'   => array( array( -10.40 ), 'cz', -10.0 ),
			'SK negative: half away'      => array( array( -10.50 ), 'sk', -11.0 ),
		);
	}

	public function testARefundCountsNegative() {
		$rows = Report::rows(
			array(
				self::entry( 1, 'CZ', '27082440', 100 ),
				self::entry( 1, 'CZ', '27082440', -30, 0, 'EUR', 9 ),
				// Only a refund in this period: the sale was in an earlier one.
				self::entry( 2, 'AT', 'U12345678', -40, 0, 'EUR', 10 ),
				// Sold and refunded in full in the period: no row.
				self::entry( 3, 'DE', '123456789', 0, 25 ),
				self::entry( 3, 'DE', '123456789', 0, -25, 'EUR', 11 ),
			),
			'sk'
		)['rows'];

		$this->assertSame( array( 'AT' => -40.0, 'CZ' => 70.0 ), array_column( $rows, 'value', 'country' ) );
		$this->assertSame( array( 1, 1 ), array_column( $rows, 'orders' ) );
	}

	public function testOtherCurrenciesAreSkippedInTheOfficialFilesOnly() {
		$entries = array(
			self::entry( 1, 'DE', '123456789', 100, 0, 'EUR' ),
			self::entry( 2, 'DE', '123456789', 2500, 0, 'CZK' ),
			self::entry( 3, 'AT', 'U12345678', 50, 0, 'USD' ),
		);

		$sk = Report::rows( $entries, 'sk' );
		$this->assertSame( array( 100.0 ), array_column( $sk['rows'], 'value' ) );
		$this->assertSame( array( 2 => 'CZK', 3 => 'USD' ), $sk['skipped'] );

		$cz = Report::rows( $entries, 'cz' );
		$this->assertSame( array( 2500.0 ), array_column( $cz['rows'], 'value' ) );
		$this->assertSame( array( 1 => 'EUR', 3 => 'USD' ), $cz['skipped'] );

		$csv = Report::rows( $entries, 'csv' );
		$this->assertCount( 3, $csv['rows'] );
		$this->assertSame( array(), $csv['skipped'] );
	}

	public function testAnUnverifiedOrderFlagsItsRow() {
		$rows = Report::rows( array( self::entry( 1, 'DE', '123456789', 10 ), self::entry( 2, 'DE', '123456789', 10, 0, 'EUR', 0, true ) ), 'csv' )['rows'];

		$this->assertTrue( $rows[0]['unverified'] );
	}

	#[DataProvider( 'customers' )]
	public function testCustomer( $vat, $billing, $expected ) {
		$this->assertSame( $expected, Report::customer( $vat, $billing ) );
	}

	public static function customers() {
		return array(
			'Czech'               => array( 'CZ27082440', 'CZ', array( 'CZ', '27082440' ) ),
			'Austrian, U kept'    => array( 'ATU12345678', 'AT', array( 'AT', 'U12345678' ) ),
			'Greek EL'            => array( 'EL123456789', 'GR', array( 'EL', '123456789' ) ),
			'spaces, lower case'  => array( 'de 123 456 789', 'DE', array( 'DE', '123456789' ) ),
			'no prefix: Greek'    => array( '123456789', 'GR', array( 'EL', '123456789' ) ),
			'no prefix: Polish'   => array( '5260250995', 'PL', array( 'PL', '5260250995' ) ),
		);
	}

	private function item( $total, $virtual ) {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'is_virtual' )->andReturn( $virtual );
		$item = Mockery::mock( 'WC_Order_Item_Product' );
		$item->shouldReceive( 'get_product' )->andReturn( $product );
		$item->shouldReceive( 'get_total' )->andReturn( (string) $total );

		return $item;
	}

	private function order( $class, $items, $shipping = 0, $fees = array(), $amount = 0 ) {
		$order = Mockery::mock( $class );
		$order->shouldReceive( 'get_items' )->andReturn( $items );
		$order->shouldReceive( 'get_shipping_total' )->andReturn( (string) $shipping );
		$order->shouldReceive( 'get_fees' )->andReturn(
			array_map(
				static function ( $total ) {
					$fee = Mockery::mock( 'WC_Order_Item_Fee' );
					$fee->shouldReceive( 'get_total' )->andReturn( (string) $total );
					return $fee;
				},
				$fees
			)
		);
		$order->shouldReceive( 'get_amount' )->andReturn( (string) $amount );

		return $order;
	}

	public function testVirtualProductsAreServicesAndShippingFollowsTheGoods() {
		$order = $this->order( 'WC_Order', array( $this->item( 100, false ), $this->item( 40, true ) ), 5, array( 2 ) );

		$this->assertTrue( Report::has_goods( $order ) );
		$this->assertSame( array( 'goods' => 107.0, 'services' => 40.0 ), Report::amounts( $order, true ) );
	}

	public function testShippingOfAServicesOnlyOrderIsAService() {
		$order = $this->order( 'WC_Order', array( $this->item( 40, true ) ), 5 );

		$this->assertFalse( Report::has_goods( $order ) );
		$this->assertSame( array( 'goods' => 0.0, 'services' => 45.0 ), Report::amounts( $order, false ) );
	}

	public function testARefundOfLinesFollowsTheLines() {
		$refund = $this->order( 'WC_Order_Refund', array( $this->item( -40, true ) ), -5, array(), 45 );

		$this->assertSame( array( 'goods' => -5.0, 'services' => -40.0 ), Report::amounts( $refund, true ) );
	}

	public function testWhatARefundReturnsBeyondItsLinesGoesWithTheGoods() {
		// One service line of 40 refunded, and 10 more typed in: 50.
		$refund = $this->order( 'WC_Order_Refund', array( $this->item( -40, true ) ), 0, array(), 50 );

		$this->assertSame( array( 'goods' => -10.0, 'services' => -40.0 ), Report::amounts( $refund, true ) );
	}

	public function testARefundOfAnAmountOnlyGoesWithTheGoods() {
		$refund = $this->order( 'WC_Order_Refund', array(), 0, array(), 30 );

		$this->assertSame( array( 'goods' => -30.0, 'services' => 0.0 ), Report::amounts( $refund, true ) );
		$this->assertSame( array( 'goods' => 0.0, 'services' => -30.0 ), Report::amounts( $refund, false ) );
	}

	private static function shop( $vat ) {
		return array(
			'vat'      => $vat,
			'company'  => 'Example & Synovia s.r.o.',
			'person'   => 'Ján Príklad',
			'role'     => 'jednatel',
			'phone'    => '+421 900 000 000',
			'ufo'      => '451',
			'pracufo'  => '2001',
			'type'     => 'P',
			'street'   => 'Hlavná',
			'number'   => '12/3',
			'postcode' => '81101',
			'city'     => 'Bratislava',
			'country'  => 'SK',
		);
	}

	private static function rows( $count, $format ) {
		$entries = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$entries[] = self::entry( $i, 'DE', sprintf( '%09d', $i ), $i * 10.5, 0 === $i % 3 ? 20 : 0, 'sk' === $format ? 'EUR' : 'CZK' );
		}

		return Report::rows( $entries, $format )['rows'];
	}

	private function assertValid( $xml, $xsd ) {
		$doc = new \DOMDocument();
		$doc->loadXML( $xml );
		$previous = libxml_use_internal_errors( true );
		$valid    = $doc->schemaValidate( dirname( __DIR__ ) . '/fixtures/' . $xsd );
		$errors   = array_map( static fn ( $error ) => trim( $error->message ), libxml_get_errors() );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$this->assertTrue( $valid, implode( "\n", $errors ) );

		return $doc;
	}

	public function testSkFileIsPaginatedByTwelveAndValid() {
		$rows = self::rows( 13, 'sk' );
		$this->assertCount( 17, $rows );

		$xml   = Xml::sk( $rows, self::shop( 'SK2020123456' ), 'month', 9, 2026, '2026-10-20' );
		$doc   = $this->assertValid( $xml, 'svdph20.xsd' );
		$xpath = new \DOMXPath( $doc );

		$this->assertSame( 2, $xpath->query( '/dokument/telo/strana' )->length );
		$this->assertSame( 12, $xpath->query( '/dokument/telo/strana[2]/zaznam' )->length );
		$this->assertSame( 12, $xpath->query( '/dokument/telo/strana[2]/zaznamCast2' )->length );
		$this->assertSame( 5, $xpath->query( '/dokument/telo/strana[2]/zaznam[kodStatu != ""]' )->length );
		$this->assertSame( (string) array_sum( array_column( $rows, 'value' ) ), $xpath->evaluate( 'string(/dokument/hlavicka/celkovaHodnota)' ) );
		$this->assertSame( '2020123456', $xpath->evaluate( 'string(/dokument/hlavicka/identifikacneCislo/dic)' ) );
		$this->assertSame( '09', $xpath->evaluate( 'string(/dokument/hlavicka/obdobie/mesiac)' ) );
		$this->assertSame( 'Example & Synovia s.r.o.', $xpath->evaluate( 'string(/dokument/hlavicka/obchodneMeno/riadok[1])' ) );
		// 3 × 10.50 = 31.50, half up; a services row has kod 2.
		$this->assertSame( '32', $xpath->evaluate( 'string(//zaznam[idCislo="000000003"][kod="0"]/hodnota)' ) );
		$this->assertSame( '20', $xpath->evaluate( 'string(//zaznam[idCislo="000000003"][kod="2"]/hodnota)' ) );
	}

	public function testSkFileOfAQuarterWithoutRowsIsValid() {
		$xml   = Xml::sk( array(), self::shop( 'SK2020123456' ), 'quarter', 3, 2026, '2026-10-20' );
		$xpath = new \DOMXPath( $this->assertValid( $xml, 'svdph20.xsd' ) );

		$this->assertSame( '3', $xpath->evaluate( 'string(//obdobie/stvrtrok)' ) );
		$this->assertSame( '', $xpath->evaluate( 'string(//obdobie/mesiac)' ) );
		$this->assertSame( 0, $xpath->query( '//strana' )->length );
	}

	public function testCzFileIsValid() {
		$rows  = self::rows( 4, 'cz' );
		$xml   = Xml::cz( $rows, array( 'country' => 'CZ' ) + self::shop( 'CZ12345678' ), 'quarter', 2, 2026 );
		$xpath = new \DOMXPath( $this->assertValid( $xml, 'dphshv_epo2.xsd' ) );

		$this->assertSame( '2', $xpath->evaluate( 'string(//VetaD/@ctvrt)' ) );
		$this->assertSame( '', $xpath->evaluate( 'string(//VetaD/@mesic)' ) );
		$this->assertSame( '12345678', $xpath->evaluate( 'string(//VetaP/@dic)' ) );
		$this->assertSame( '12', $xpath->evaluate( 'string(//VetaP/@c_pop)' ) );
		$this->assertSame( '3', $xpath->evaluate( 'string(//VetaP/@c_orient)' ) );
		$this->assertSame( 'Príklad', $xpath->evaluate( 'string(//VetaP/@opr_prijmeni)' ) );
		$this->assertSame( 'ČESKÁ REPUBLIKA', $xpath->evaluate( 'string(//VetaP/@stat)' ) );
		$this->assertSame( 5, $xpath->query( '//VetaR' )->length );
		// 3 × 10.50 = 31.50, up; services k_pln_eu 3.
		$this->assertSame( '32', $xpath->evaluate( 'string(//VetaR[@c_vat="000000003"][@k_pln_eu="0"]/@pln_hodnota)' ) );
		$this->assertSame( '20', $xpath->evaluate( 'string(//VetaR[@c_vat="000000003"][@k_pln_eu="3"]/@pln_hodnota)' ) );
	}

	public function testCzFileOfANaturalPersonIsValid() {
		$shop  = array( 'type' => 'F', 'company' => '', 'role' => '', 'pracufo' => '', 'number' => 'A1' ) + self::shop( 'CZ8001011234' );
		$xpath = new \DOMXPath( $this->assertValid( Xml::cz( self::rows( 1, 'cz' ), $shop, 'month', 10, 2026 ), 'dphshv_epo2.xsd' ) );

		$this->assertSame( 'Príklad', $xpath->evaluate( 'string(//VetaP/@prijmeni)' ) );
		$this->assertSame( 'Ján', $xpath->evaluate( 'string(//VetaP/@jmeno)' ) );
		$this->assertSame( '', $xpath->evaluate( 'string(//VetaP/@c_pop)' ) );
	}

	public function testMissingShopDetails() {
		$this->assertSame( array(), Xml::missing( 'sk', self::shop( 'SK2020123456' ) ) );
		$this->assertSame( array(), Xml::missing( 'cz', self::shop( 'CZ12345678' ) ) );
		$this->assertSame( array( 'Your VAT ID in Validation (SK and 10 digits)' ), Xml::missing( 'sk', self::shop( 'CZ12345678' ) ) );
		$this->assertSame( array( 'Your VAT ID in Validation (CZ and 8 to 10 digits)', 'Tax office code' ), Xml::missing( 'cz', array( 'ufo' => '45' ) + self::shop( 'SK2020123456' ) ) );
	}

	public function testCsvHasABomAndEveryCurrency() {
		$rows = Report::rows( array( self::entry( 1, 'CZ', '27082440', 10, 0, 'EUR' ), self::entry( 2, 'DE', '123456789', 0, 2500.5, 'CZK', 0, true ) ), 'csv' )['rows'];
		$csv  = ExportPage::csv( $rows );

		$this->assertStringStartsWith( "\xEF\xBB\xBFCountry,", $csv );
		$this->assertStringContainsString( "\nCZ,27082440,goods,0,0,10.00,EUR,1,\n", $csv );
		$this->assertStringContainsString( "\nDE,123456789,services,2,3,2500.50,CZK,1,yes\n", $csv );
	}

	public function testPeriodInTheSiteTimeZone() {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Europe/Bratislava' ) );

		$this->assertSame( array( strtotime( '2026-09-30T22:00:00Z' ), strtotime( '2026-10-31T23:00:00Z' ) ), Report::period( 'month', 10, 2026 ) );
		$this->assertSame( array( strtotime( '2026-09-30T22:00:00Z' ), strtotime( '2026-12-31T23:00:00Z' ) ), Report::period( 'quarter', 4, 2026 ) );
	}

	/**
	 * A Slovak shop selling to the EU: WooCommerce's paid statuses, the EU VAT list.
	 */
	private function shop_context() {
		$countries = Mockery::mock( 'WC_Countries' );
		$countries->shouldReceive( 'get_base_country' )->andReturn( 'SK' );
		$countries->shouldReceive( 'get_european_union_countries' )->andReturn( array( 'CZ', 'SK', 'DE', 'AT' ) );
		Functions\when( 'WC' )->justReturn( (object) array( 'countries' => $countries ) );
		Functions\when( 'wc_get_is_paid_statuses' )->justReturn( array( 'processing', 'completed', 'shipped' ) );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
	}

	private function date( $iso ) {
		if ( null === $iso ) {
			return null;
		}
		$date = Mockery::mock( 'WC_DateTime' );
		$date->shouldReceive( 'getTimestamp' )->andReturn( strtotime( $iso ) );

		return $date;
	}

	/**
	 * An order of 10 goods: its meta, status, dates.
	 */
	private function placed( $id, $meta, $status = 'processing', $created = '2026-10-10T10:00:00Z', $paid = null, $country = 'CZ' ) {
		$meta += array(
			'is_vat_exempt'         => 'yes',
			'_billing_dic'          => 'CZ27082440',
			'_billing_dic_dph'      => '',
			Report::DATE_META       => '',
			'_woolab_icdic_vat_evidence' => '',
		);
		$order = $this->order( 'WC_Order', array( $this->item( 10, false ) ) );
		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		$order->shouldReceive( 'get_currency' )->andReturn( 'EUR' );
		$order->shouldReceive( 'get_billing_country' )->andReturn( $country );
		$order->shouldReceive( 'get_date_created' )->andReturn( $this->date( $created ) );
		$order->shouldReceive( 'get_date_paid' )->andReturn( $this->date( $paid ) );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( static fn ( $key ) => $meta[ $key ] ?? '' );

		return $order;
	}

	private static function evidence( $reason = 'reverse_charge', $state = 'valid' ) {
		return array(
			'_woolab_icdic_vat_evidence' => array(
				'vat_number' => 'CZ27082440',
				'state'      => $state,
				'reason'     => $reason,
			),
		);
	}

	#[DataProvider( 'standings' )]
	public function testStanding( $meta, $country, $expected ) {
		$this->shop_context();

		$this->assertSame( $expected, Report::standing( $this->placed( 1, $meta, 'processing', '2026-10-10T10:00:00Z', null, $country ) ) );
	}

	public static function standings() {
		return array(
			'reverse charge, valid'                => array( self::evidence(), 'CZ', '' ),
			'reverse charge, VIES down on re-check' => array( self::evidence( 'reverse_charge', 'unverifiable' ), 'CZ', 'unverified' ),
			'check failed, let through'            => array( self::evidence( 'check_failed_ignored', 'unverifiable' ), 'CZ', 'unverified' ),
			'VAT put back by the admin'            => array( array( 'is_vat_exempt' => 'no' ) + self::evidence(), 'CZ', null ),
			'with VAT'                             => array( array( 'is_vat_exempt' => 'no' ) + self::evidence( 'with_vat', 'invalid' ), 'CZ', null ),
			'no evidence, exempt, EU VAT ID'       => array( array(), 'CZ', 'no_evidence' ),
			'no evidence, exempt, no VAT ID'       => array( array( '_billing_dic' => '' ), 'CZ', null ),
			'no evidence, exempt, an export'       => array( array( '_billing_dic' => 'CHE123456789' ), 'CH', null ),
			'no evidence, not exempt'              => array( array( 'is_vat_exempt' => 'no' ), 'CZ', null ),
		);
	}

	public function testStatusesAreThePaidOnesAndRefunded() {
		$this->shop_context();

		$this->assertSame( array( 'processing', 'completed', 'shipped', 'refunded' ), Report::statuses() );
	}

	public function testThePeriodDateIsKeptOnceInACountedStatus() {
		$this->shop_context();

		$pending = $this->placed( 1, array(), 'pending' );
		$pending->shouldNotReceive( 'update_meta_data' );
		$this->assertFalse( Report::stamp( $pending ) );

		$kept = $this->placed( 2, array( Report::DATE_META => '2026-09-30T10:00:00+00:00' ), 'completed' );
		$kept->shouldNotReceive( 'update_meta_data' );
		$this->assertFalse( Report::stamp( $kept ) );

		$shipped = $this->placed( 3, array(), 'shipped' );
		$shipped->shouldReceive( 'update_meta_data' )->once()->with( Report::DATE_META, Mockery::pattern( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/' ) );
		$shipped->shouldReceive( 'save_meta_data' )->once();
		Report::status_changed( 3, 'pending', 'shipped', $shipped );
	}

	public function testThePeriodDateBeforeThePaidAndCreatedDates() {
		$kept = $this->placed( 1, array( Report::DATE_META => '2026-09-30T23:00:00+00:00' ), 'completed', '2026-09-29T10:00:00Z', '2026-10-03T10:00:00Z' );
		$this->assertSame( strtotime( '2026-09-30T23:00:00Z' ), Report::date( $kept ) );

		$paid = $this->placed( 2, array(), 'completed', '2026-09-29T10:00:00Z', '2026-10-03T10:00:00Z' );
		$this->assertSame( strtotime( '2026-10-03T10:00:00Z' ), Report::date( $paid ) );

		$created = $this->placed( 3, array(), 'processing', '2026-09-29T10:00:00Z' );
		$this->assertSame( strtotime( '2026-09-29T10:00:00Z' ), Report::date( $created ) );
	}

	/**
	 * wc_get_orders() answering by query: the orders whose kept date is in the period,
	 * those without one by paid / created date, the refunds; paged.
	 */
	private function orders( $kept, $paid, $created, $refunds = array() ) {
		$calls = array();
		Functions\when( 'wc_get_orders' )->alias(
			function ( $args ) use ( $kept, $paid, $created, $refunds, &$calls ) {
				if ( 'shop_order_refund' === $args['type'] ) {
					$list = $refunds;
				} elseif ( isset( $args['date_paid'] ) ) {
					$list = $paid;
				} elseif ( isset( $args['date_created'] ) ) {
					$list = $created;
				} else {
					$this->assertSame( 'BETWEEN', $args['meta_query'][0]['compare'] );
					$list = $kept;
				}
				$this->assertSame( array( 'processing', 'completed', 'shipped', 'refunded' ), $args['status'] ?? Report::statuses() );
				$calls[] = $args['page'];
				return array_slice( $list, ( $args['page'] - 1 ) * Report::BATCH, Report::BATCH );
			}
		);

		return function () use ( &$calls ) {
			return $calls;
		};
	}

	public function testPostsStorageGetsTheMetaQueryThroughWpQuery() {
		$this->shop_context();
		$seen = array();
		Functions\when( 'wc_get_orders' )->alias(
			function ( $args ) use ( &$seen ) {
				$seen[] = $args;
				return array();
			}
		);
		\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = false;
		try {
			Report::entries( 0, 100 );
		} finally {
			\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = true;
		}

		// Not as meta_query, which the posts storage ignores, but as the plugin's own var.
		foreach ( array_filter( $seen, static fn ( $a ) => 'shop_order' === $a['type'] ) as $args ) {
			$this->assertArrayNotHasKey( 'meta_query', $args );
			$this->assertArrayHasKey( 'woolab_icdic_meta_query', $args );
		}

		$query = Report::cpt_meta_query( array( 'meta_query' => array( array( 'key' => 'a' ) ), 'woolab_icdic_meta_query' => 'x' ), array( 'woolab_icdic_meta_query' => array( 'key' => 'b' ) ) );
		$this->assertSame( array( array( 'key' => 'a' ), array( 'key' => 'b' ) ), $query['meta_query'] );
		$this->assertArrayNotHasKey( 'woolab_icdic_meta_query', $query );
		$this->assertSame( array( 'post_type' => 'x' ), Report::cpt_meta_query( array( 'post_type' => 'x' ), array() ) );
	}

	public function testEntriesOfAPeriod() {
		$this->shop_context();
		list( $start, $end ) = Report::period( 'month', 10, 2026 );

		// Kept in October, though paid in November.
		$kept = $this->placed( 1, array( Report::DATE_META => '2026-10-31T10:00:00+00:00' ) + self::evidence(), 'completed', '2026-10-30T10:00:00Z', '2026-11-02T10:00:00Z' );
		// Created in September, paid in October, no kept date: October.
		$paid = $this->placed( 2, self::evidence(), 'completed', '2026-09-28T10:00:00Z', '2026-10-02T10:00:00Z' );
		// Created in October but paid in November: not October.
		$later = $this->placed( 3, self::evidence(), 'completed', '2026-10-28T10:00:00Z', '2026-11-02T10:00:00Z' );
		// Created in October, COD processing, no evidence: flagged.
		$cod = $this->placed( 4, array() );
		// The admin put the VAT back.
		$taxed = $this->placed( 5, array( 'is_vat_exempt' => 'no' ) + self::evidence() );

		$refund = $this->order( 'WC_Order_Refund', array(), 0, array(), 4 );
		$refund->shouldReceive( 'get_id' )->andReturn( 9 );
		$refund->shouldReceive( 'get_parent_id' )->andReturn( 1 );
		Functions\when( 'wc_get_order' )->justReturn( $kept );

		$this->orders( array( $kept ), array( $paid, $later ), array( $later, $cod, $taxed ), array( $refund ) );

		$entries = Report::entries( $start, $end );
		$this->assertSame(
			array(
				array( 1, 0, '', 10.0 ),
				array( 2, 0, '', 10.0 ),
				array( 4, 0, 'no_evidence', 10.0 ),
				array( 1, 9, '', -4.0 ),
			),
			array_map( static fn ( $e ) => array( $e['order_id'], $e['refund_id'], $e['flag'], $e['goods'] ), $entries )
		);
		$this->assertSame( array( 'CZ', '27082440' ), array( $entries[2]['country'], $entries[2]['vat'] ) );
	}

	public function testEntriesPagePastOneBatch() {
		$this->shop_context();
		list( $start, $end ) = Report::period( 'month', 10, 2026 );

		$orders = array();
		for ( $id = 1; $id <= Report::BATCH + 3; $id++ ) {
			$orders[] = $this->placed( $id, array( Report::DATE_META => '2026-10-15T10:00:00+00:00' ) + self::evidence() );
		}
		$calls = $this->orders( $orders, array(), array() );

		$this->assertCount( Report::BATCH + 3, Report::entries( $start, $end ) );
		// The kept dates: pages 1 and 2; paid, created, refunds: page 1 each.
		$this->assertSame( array( 1, 2, 1, 1, 1 ), $calls() );
	}

	public function testCzPhoneWithoutSpacesNeverCut() {
		$this->assertSame( '+420777123456', Xml::cz_phone( '+420 777 123 456' ) );
		$this->assertSame( '', Xml::cz_phone( '+420 777 123 456 789' ) );
	}
}
