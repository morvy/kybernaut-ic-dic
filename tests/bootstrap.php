<?php

namespace KybernautIcDic\Test;

// Brain Monkey (tests/TestCase.php) defines the WordPress functions per test.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/TestCase.php';

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// The scoped ibericode/vat lives in deps/, which the unit tests do not load (and
// 2.0.9's constructor is deprecated on PHP 8.4, which fails the run): stubbed, every
// VAT number has a valid format.
if ( ! class_exists( \KybernautIcDicDeps\Ibericode\Vat\Validator::class, false ) ) {
	eval( 'namespace KybernautIcDicDeps\\Ibericode\\Vat; class Validator { public function validateVatNumberFormat( $vat ) { return true; } }' ); // phpcs:ignore
}
