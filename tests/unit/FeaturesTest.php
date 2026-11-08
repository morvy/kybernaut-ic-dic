<?php

namespace KybernautIcDic\Test;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use KybernautIcDic\Features;

/**
 * The hidden features (src/Features.php): a `feature_*` key of the settings, off
 * unless it says 'yes' or the filter turns it on.
 */
class FeaturesTest extends TestCase {

	private function settings( array $settings ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $settings ) {
				return 'woolab_icdic_settings' === $name ? $settings : $default;
			}
		);
	}

	public function testEcSalesListIsOffByDefault() {
		$this->settings( array() );
		$this->assertFalse( Features::enabled( 'ec_sales_list' ) );
	}

	public function testOnlyYesTurnsItOn() {
		$this->settings( array( 'feature_ec_sales_list' => 'yes' ) );
		$this->assertTrue( Features::enabled( 'ec_sales_list' ) );

		$this->settings( array( 'feature_ec_sales_list' => '1' ) );
		$this->assertFalse( Features::enabled( 'ec_sales_list' ) );
	}

	public function testTheFilterTurnsItOn() {
		$this->settings( array() );
		Filters\expectApplied( 'woolab_icdic_feature_enabled' )->once()->with( false, 'ec_sales_list' )->andReturn( true );
		$this->assertTrue( Features::enabled( 'ec_sales_list' ) );
	}

	public function testUnknownFeatureIsOff() {
		$this->settings( array( 'feature_nope' => 'yes' ) );
		$this->assertFalse( Features::enabled( 'nope' ) );
	}
}
