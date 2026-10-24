<?php
/**
 * Rector: PHP 8.1 (the plugin's minimum) and the WordPress deprecations up to
 * 6.9 (its minimum WordPress). Run `composer lint:rector` for a dry run.
 */

use Fsylum\RectorWordPress\Set\WordPressLevelSetList;
use Rector\Config\RectorConfig;
use Rector\Php54\Rector\Array_\LongArrayToShortArrayRector;

return RectorConfig::configure()
	->withPaths(
		array(
			__DIR__ . '/woolab-ic-dic.php',
			__DIR__ . '/uninstall.php',
			__DIR__ . '/includes',
		)
	)
	->withPhpSets( php81: true )
	->withSets( array( WordPressLevelSetList::UP_TO_WP_6_7 ) )
	->withBootstrapFiles( array( __DIR__ . '/tests/phpstan/bootstrap.php' ) )
	->withAutoloadPaths( array( __DIR__ . '/deps', __DIR__ . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php', __DIR__ . '/vendor/php-stubs/woocommerce-stubs/woocommerce-stubs.php' ) )
	->withSkip(
		array_merge(
			// The WordPress coding standards (phpcs.xml.dist) require array().
			array( LongArrayToShortArrayRector::class ),
		)
	);
