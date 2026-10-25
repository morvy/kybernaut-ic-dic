<?php

namespace KybernautIcDic\Test;

use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for woolab_icdic_process_shop_order() in includes/filters-actions.php.
 *
 * On a full admin order save the three IČO/DIČ/IČ DPH inputs are always
 * present in $_POST, and several plugins hooked to
 * woocommerce_process_shop_order_meta save the order in the same request.
 * The handler must therefore write nothing when the values are untouched,
 * and must persist changed values via save_meta_data() rather than a full
 * $order->save().
 */
class ProcessShopOrderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Build a WC_Order mock with the given stored meta values.
	 */
	private function orderWithMeta( array $meta ) {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_user_id' )->andReturn( 0 );
		foreach ( $meta as $key => $value ) {
			$order->shouldReceive( 'get_meta' )->with( $key, true )->andReturn( $value );
		}
		return $order;
	}

	private function mockRequestGuards() {
		$_POST['woocommerce_meta_nonce'] = 'nonce';

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_clean' )->returnArg();
	}

	public function testMissingNonceBailsOutBeforeLoadingOrder() {
		Functions\expect( 'wc_get_order' )->never();

		woolab_icdic_process_shop_order( 123, null );
	}

	public function testUnchangedValuesWriteAndSaveNothing() {
		$this->mockRequestGuards();

		$_POST['_billing_billing_ic']      = '88922961';
		$_POST['_billing_billing_dic']     = 'CZ88922961';
		$_POST['_billing_billing_dic_dph'] = '';

		$order = $this->orderWithMeta( array(
			'_billing_ic'      => '88922961',
			'_billing_dic'     => 'CZ88922961',
			'_billing_dic_dph' => '',
		) );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save_meta_data' );
		$order->shouldNotReceive( 'save' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		woolab_icdic_process_shop_order( 123, null );
	}

	public function testChangedValueIsWrittenViaSaveMetaData() {
		$this->mockRequestGuards();

		$_POST['_billing_billing_ic']      = '27082440';
		$_POST['_billing_billing_dic']     = 'CZ88922961';
		$_POST['_billing_billing_dic_dph'] = '';

		$order = $this->orderWithMeta( array(
			'_billing_ic'      => '88922961',
			'_billing_dic'     => 'CZ88922961',
			'_billing_dic_dph' => '',
		) );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_billing_ic', '27082440' );
		$order->shouldReceive( 'save_meta_data' )->once();
		$order->shouldNotReceive( 'save' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		woolab_icdic_process_shop_order( 123, null );
	}

	public function testMissingFieldIsIgnored() {
		$this->mockRequestGuards();

		// Only the IČO field posted, unchanged — DIČ fields absent entirely.
		$_POST['_billing_billing_ic'] = '88922961';

		$order = $this->orderWithMeta( array( '_billing_ic' => '88922961' ) );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save_meta_data' );
		$order->shouldNotReceive( 'save' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		woolab_icdic_process_shop_order( 123, null );
	}
}
