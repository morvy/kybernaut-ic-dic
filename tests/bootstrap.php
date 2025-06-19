<?php

namespace KybernautIcDic\Test;

use WP_Mock;

// First we need to load the composer autoloader so we can use WP Mock.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/WPMock/wp-functions-mock.php';

// Load dependencies autoloader if it exists
if (file_exists(__DIR__ . '/../deps/scoper-autoload.php')) {
    require_once __DIR__ . '/../deps/scoper-autoload.php';
}
if (file_exists(__DIR__ . '/../deps/autoload.php')) {
    require_once __DIR__ . '/../deps/autoload.php';
}

// Mock WooCommerce classes that might be needed
if (!class_exists('WC_Order')) {
    class WC_Order {}
}

// Now call the bootstrap method of WP Mock.
// https://wp-mock.gitbook.io/documentation/getting-started/introduction
WP_Mock::setUsePatchwork(false);
WP_Mock::bootstrap();

require_once dirname(__DIR__) . '/includes/helpers.php'; // Contains woolab_icdic_get_vat_number_country_code
require_once dirname(__DIR__) . '/includes/logger.php'; // Already there in original, kept for explicitness
require_once dirname(__DIR__) . '/includes/filters-actions.php'; // Contains the function to test
require_once dirname(__DIR__) . '/includes/ares.php'; // Was in original, kept