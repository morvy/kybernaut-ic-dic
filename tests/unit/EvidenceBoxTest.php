<?php

namespace KybernautIcDic\Test;

use KybernautIcDic\Order\Evidence;
use KybernautIcDic\Order\EvidenceBox;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The orders list "Reverse charge" column (src/Order/EvidenceBox.php).
 */
class EvidenceBoxTest extends TestCase {

	private function order( array $meta, $country = 'CZ' ) {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_billing_country' )->andReturn( $country );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( static fn ( $key ) => $meta[ $key ] ?? '' );
		return $order;
	}

	#[DataProvider( 'orders' )]
	public function testReverseCharge( $meta, $expected ) {
		$this->assertSame( $expected, EvidenceBox::reverse_charge( $this->order( $meta ) ) );
	}

	public static function orders() {
		return array(
			'evidence: reverse charge'               => array( array( Evidence::META => array( 'reason' => 'reverse_charge' ) ), true ),
			'evidence: with VAT, though exempt'      => array( array( Evidence::META => array( 'reason' => 'with_vat' ), 'is_vat_exempt' => 'yes', '_billing_dic' => 'CZ27082440' ), false ),
			'evidence: check failed, let through'    => array( array( Evidence::META => array( 'reason' => 'check_failed_ignored' ) ), false ),
			'no evidence: exempt with a VAT ID'      => array( array( 'is_vat_exempt' => 'yes', '_billing_dic' => 'CZ27082440' ), true ),
			'no evidence: exempt without a VAT ID'   => array( array( 'is_vat_exempt' => 'yes' ), false ),
			'no evidence: a VAT ID, with VAT'        => array( array( '_billing_dic' => 'CZ27082440' ), false ),
		);
	}

	public function testTheColumnFollowsTheOrderNumber() {
		$columns = EvidenceBox::columns( array( 'cb' => '', 'order_number' => 'Order', 'order_date' => 'Date' ) );

		$this->assertSame( array( 'cb', 'order_number', EvidenceBox::COLUMN, 'order_date' ), array_keys( $columns ) );
	}

	public function testTheColumnGoesLastWithoutAnOrderNumber() {
		$columns = EvidenceBox::columns( array( 'cb' => '', 'order_date' => 'Date' ) );

		$this->assertSame( array( 'cb', 'order_date', EvidenceBox::COLUMN ), array_keys( $columns ) );
	}
}
