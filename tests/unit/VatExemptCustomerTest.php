<?php

namespace KybernautIcDic\Test;

use Brain\Monkey\Functions;
use KybernautIcDic\Checkout\VatExempt;

/**
 * VatExempt::set_vat_exempt_for_customer() on `wp`: not on WooCommerce's AJAX
 * requests, where the checkout already decided (src/Checkout/VatExempt.php).
 */
class VatExemptCustomerTest extends TestCase {

	protected function tearDown(): void {
		unset( $_GET['wc-ajax'] );
		parent::tearDown();
	}

	public function testLeavesWcAjaxRequestsAlone() {
		$_GET['wc-ajax'] = 'checkout';
		Functions\expect( 'is_checkout' )->never();
		Functions\expect( 'WC' )->never();

		VatExempt::set_vat_exempt_for_customer();
		$this->addToAssertionCount( 1 );
	}

	public function testLeavesOtherPagesAlone() {
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\expect( 'WC' )->never();

		VatExempt::set_vat_exempt_for_customer();
		$this->addToAssertionCount( 1 );
	}
}
