<?php
/**
 * Rector: PHP 8.1 (the plugin's minimum), and the WordPress and WooCommerce
 * deprecations up to its minimum WordPress 6.7 and WooCommerce 10.0
 * (fsylum/rector-wordpress, morvy/rector-woocommerce). Run `composer lint:rector` for a dry run.
 */

use Fsylum\RectorWordPress\Set\WordPressLevelSetList;
use Morvy\RectorWooCommerce\Set\WooCommerceLevelSetList;
use Rector\Config\RectorConfig;
use Rector\Php54\Rector\Array_\LongArrayToShortArrayRector;
use Rector\Php71\Rector\List_\ListToArrayDestructRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;

return RectorConfig::configure()
	->withPaths(
		array(
			__DIR__ . '/woolab-ic-dic.php',
			__DIR__ . '/uninstall.php',
			__DIR__ . '/src',
		)
	)
	->withPhpSets( php81: true )
	->withSets( array( WordPressLevelSetList::UP_TO_WP_6_7, WooCommerceLevelSetList::UP_TO_WC_10_0 ) )
	->withBootstrapFiles( array( __DIR__ . '/tests/phpstan/bootstrap.php' ) )
	->withAutoloadPaths( array( __DIR__ . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php', __DIR__ . '/vendor/php-stubs/woocommerce-stubs/woocommerce-stubs.php' ) )
	->withSkip(
		array(
			// The WordPress coding standards (phpcs.xml.dist) require array() and list().
			LongArrayToShortArrayRector::class,
			ListToArrayDestructRector::class,
			// Hooks stay array( Class::class, 'method' ): a first-class callable is a new
			// Closure, which remove_action() / remove_filter() cannot match.
			ArrayToFirstClassCallableRector::class,
		)
	);
