<?php

namespace KybernautIcDic\Test;

use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * The settings array and its migration from the 1.x options (includes/options.php).
 */
class OptionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Same as ProcessShopOrderTest: discard expectations older tests leaked.
		Mockery::resetContainer();
		WP_Mock::setUp();
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/** get_option() answering from a fixed table, false for anything else. */
	private function options( array $table ) {
		WP_Mock::userFunction( 'get_option', array(
			'return' => function ( $name, $default = false ) use ( $table ) {
				return array_key_exists( $name, $table ) ? $table[ $name ] : $default;
			},
		) );
	}

	public function testStoredValuesWinOverDefaults() {
		$this->options( array( 'woolab_icdic_settings' => array( 'ares_check' => 'no', 'toggle' => 'yes' ) ) );

		$this->assertSame( 'no', woolab_icdic_setting( 'ares_check' ) );
		$this->assertSame( 'yes', woolab_icdic_setting( 'toggle' ) );
		// Not stored: the default.
		$this->assertSame( 'yes', woolab_icdic_setting( 'vies_check' ) );
		$this->assertSame( 'no', woolab_icdic_setting( 'sk_check' ) );
	}

	public function testMigratesOnlyTheLegacyOptionsThatWereSet() {
		$this->options( array(
			'woolab_icdic_ares_check'        => 'no',
			'woolab_icdic_toggle_switch'     => 'yes',
			'woolab_icdic_vat_exempt_switch' => 'yes',
		) );

		$added = null;
		WP_Mock::userFunction( 'add_option', array(
			'times'  => 1,
			'return' => function ( $name, $value ) use ( &$added ) {
				$added = array( $name, $value );
				return true;
			},
		) );

		$this->assertSame( 'no', woolab_icdic_setting( 'ares_check' ) );
		$this->assertSame( array( 'woolab_icdic_settings', array( 'ares_check' => 'no', 'vat_exempt' => 'yes', 'toggle' => 'yes' ) ), $added );
	}
}
