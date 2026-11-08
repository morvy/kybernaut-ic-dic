<?php

namespace KybernautIcDic\Test;

use KybernautIcDic\Plugin;

/**
 * The WooCommerce version guard (src/Plugin.php).
 */
class PluginTest extends TestCase {

	public function testMinimumMatchesTheHeader() {
		$header = file_get_contents( dirname( __DIR__, 2 ) . '/woolab-ic-dic.php' );
		$this->assertMatchesRegularExpression( '/^\s*\*\s*WC requires at least:\s*' . preg_quote( Plugin::MIN_WC, '/' ) . '\s*$/m', $header );
	}
}
