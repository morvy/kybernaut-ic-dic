<?php
/**
 * Remove the imported Slovak lists (includes/financna-sprava.php).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

$dir = wp_upload_dir( null, false )['basedir'] . '/woolab-ic-dic';

foreach ( glob( $dir . '/{*,.htaccess}', GLOB_BRACE ) ?: array() as $file ) {
	wp_delete_file( $file );
}

if ( is_dir( $dir ) ) {
	rmdir( $dir );
}

foreach ( array( 'woolab_icdic_sk_updated', 'woolab_icdic_sk_check', 'woolab_icdic_sk_fill' ) as $option ) {
	delete_option( $option );
}
