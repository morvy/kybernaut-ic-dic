<?php

namespace KybernautIcDic\Test;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Brain Monkey around every test: the WordPress hook functions (add_action,
 * apply_filters, …), translation and escaping as pass-throughs, and the few
 * other WordPress functions the code under test calls. A test overrides any of
 * them with Functions\when() or Functions\expect().
 */
abstract class TestCase extends PHPUnitTestCase {

	// Counts Mockery expectations as assertions and verifies them after each test.
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\stubs(
			array(
				'is_admin'          => false,
				'trailingslashit'   => static fn ( $value ) => rtrim( $value, '/\\' ) . '/',
				'untrailingslashit' => static fn ( $value ) => rtrim( $value, '/\\' ),
				'wp_parse_args'     => static fn ( $args, $defaults = array() ) => array_merge( (array) $defaults, (array) $args ),
				'wp_json_encode'    => static fn ( $data, $options = 0, $depth = 512 ) => json_encode( $data, $options, $depth ),
				// A cache miss, so the lookups always run.
				'get_transient'     => false,
				'set_transient'     => true,
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
