<?php
/**
 * Remove the plugin settings and the imported Slovak lists (includes/financna-sprava.php).
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

// The settings array (includes/options.php), the 1.x options it was migrated from,
// and the Slovak import state.
$options = array(
	'woolab_icdic_settings',
	'woolab_icdic_sk_updated',
	'woolab_icdic_ares_check',
	'woolab_icdic_ares_fill',
	'woolab_icdic_sk_check',
	'woolab_icdic_sk_fill',
	'woolab_icdic_vies_check',
	'woolab_icdic_vat_exempt_switch',
	'woolab_icdic_ignore_check_fail',
	'woolab_icdic_disable_dic_dicdph_match',
	'woolab_icdic_toggle_switch',
	'woolab_icdic_country_switch',
	'woolab_icdic_notice_settings',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
