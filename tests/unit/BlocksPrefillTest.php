<?php

namespace KybernautIcDic\Test;

use Brain\Monkey\Functions;
use KybernautIcDic\Checkout\Blocks;
use Mockery;

/**
 * Blocks::stored_value(): the block checkout's prefill of the IČO / DIČ fields
 * (src/Checkout/Blocks.php).
 */
class BlocksPrefillTest extends TestCase {

	private function order( array $meta, int $id, string $status ) {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( static fn ( $key ) => $meta[ $key ] ?? '' );
		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'has_status' )->andReturnUsing( static fn ( $s ) => $s === $status );
		return $order;
	}

	private function customer( array $meta ) {
		$customer = Mockery::mock( 'WC_Customer' );
		$customer->shouldReceive( 'get_meta' )->andReturnUsing( static fn ( $key ) => $meta[ $key ] ?? '' );
		Functions\when( 'WC' )->justReturn( (object) array( 'customer' => $customer ) );
		return $customer;
	}

	public function testOrderMetaWins() {
		$this->customer( array( 'billing_ic' => '11111111' ) );
		$this->assertSame( '27082440', Blocks::stored_value( 'billing_ic', $this->order( array( '_billing_ic' => '27082440' ), 12, 'checkout-draft' ) ) );
	}

	public function testDraftOrderFallsBackToTheCustomer() {
		$this->customer( array( 'billing_ic' => '27082440' ) );
		$this->assertSame( '27082440', Blocks::stored_value( 'billing_ic', $this->order( array(), 12, 'checkout-draft' ) ) );
		$this->assertSame( '27082440', Blocks::stored_value( 'billing_ic', $this->order( array(), 0, 'pending' ) ) );
	}

	public function testPlacedOrderKeepsItsOwnEmptyValue() {
		$this->customer( array( 'billing_ic' => '27082440' ) );
		$this->assertSame( '', Blocks::stored_value( 'billing_ic', $this->order( array(), 12, 'processing' ) ) );
	}

	public function testCustomerReadsItsMeta() {
		$this->assertSame( 'CZ27082440', Blocks::stored_value( 'billing_dic', $this->customer( array( 'billing_dic' => 'CZ27082440' ) ) ) );
	}
}
