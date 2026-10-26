<?php

namespace KybernautIcDic\Test;

use Brain\Monkey\Functions;

/**
 * The settings array and its migration from the 1.x options (includes/options.php).
 */
class OptionsTest extends TestCase {

	/** get_option() answering from a fixed table, false for anything else. */
	private function options( array $table ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $table ) {
				return array_key_exists( $name, $table ) ? $table[ $name ] : $default;
			}
		);
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
		Functions\expect( 'add_option' )->once()->andReturnUsing(
			function ( $name, $value ) use ( &$added ) {
				$added = array( $name, $value );
				return true;
			}
		);

		$this->assertSame( 'no', woolab_icdic_setting( 'ares_check' ) );
		$this->assertSame( array( 'woolab_icdic_settings', array( 'ares_check' => 'no', 'vat_exempt' => 'yes', 'toggle' => 'yes' ) ), $added );
	}

	public function testTheMessageColoursBecomeCustomProperties() {
		$this->options( array( 'woolab_icdic_settings' => array( 'color_success' => '#123456', 'color_error' => 'red', 'color_info' => '' ) ) );
		Functions\when( 'sanitize_hex_color' )->alias( static fn ( $color ) => preg_match( '/^#[0-9a-f]{6}$/i', $color ) ? $color : null );

		// Not a hex colour, or empty: lookup.css falls back to its default.
		$this->assertSame( ':root{--woolab-ic-dic-color-success:#123456}', \KybernautIcDic\Assets::lookup_colors_css() );
	}
}
