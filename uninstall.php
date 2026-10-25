<?php
/**
 * Remove the plugin settings and the imported Slovak lists
 * (Services\FinancnaSprava\Storage).
 *
 * @package KybernautIcDic
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

$woolab_icdic_dir   = wp_upload_dir( null, false )['basedir'] . '/woolab-ic-dic';
$woolab_icdic_files = glob( $woolab_icdic_dir . '/{*,.htaccess}', GLOB_BRACE );

foreach ( false === $woolab_icdic_files ? array() : $woolab_icdic_files as $woolab_icdic_file ) {
	wp_delete_file( $woolab_icdic_file );
}

if ( is_dir( $woolab_icdic_dir ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- the plugin's own, now empty, directory; WP_Filesystem may ask for FTP credentials here.
	rmdir( $woolab_icdic_dir );
}

// The settings array (Settings\Options), the 1.x options it was migrated from,
// and the Slovak import state.
$woolab_icdic_options = array(
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

foreach ( $woolab_icdic_options as $woolab_icdic_option ) {
	delete_option( $woolab_icdic_option );
}
