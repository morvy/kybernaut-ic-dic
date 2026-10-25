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

require_once dirname( __DIR__ ) . '/includes/ares.php';
require_once dirname( __DIR__ ) . '/includes/vies.php';
require_once dirname( __DIR__ ) . '/includes/helpers.php';
require_once dirname( __DIR__ ) . '/includes/validation.php';
require_once dirname( __DIR__ ) . '/includes/logger.php';
require_once dirname( __DIR__ ) . '/includes/filters-actions.php';
require_once dirname( __DIR__ ) . '/includes/options.php';
