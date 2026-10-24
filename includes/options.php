<?php
/**
 * Plugin settings, stored as one array in the `woolab_icdic_settings` option.
 *
 * Up to 1.x every setting was an option of its own (`woolab_icdic_ares_check`, …).
 * woolab_icdic_settings_all() copies those into the array the first time it finds
 * none, and the old options are left in place for one release so a downgrade to
 * 1.x still finds them.
 *
 * The array is autoloaded on purpose: the block checkout fields are registered on
 * every request (woolab_icdic_blocks_register_fields() reads the toggle), so a
 * non-autoloaded option would cost a query on every page instead of none.
 *
 * @since 2.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

const WOOLAB_ICDIC_SETTINGS = 'woolab_icdic_settings';

/**
 * Setting key => array( 1.x option name or null, default ).
 *
 * @return array<string,array{0:string|null,1:string}>
 */
function woolab_icdic_settings_schema() {
	return array(
		'ares_check'            => array( 'woolab_icdic_ares_check', 'yes' ),
		'ares_fill'             => array( 'woolab_icdic_ares_fill', 'no' ),
		'sk_check'              => array( 'woolab_icdic_sk_check', 'no' ),
		'sk_fill'               => array( 'woolab_icdic_sk_fill', 'no' ),
		'vies_check'            => array( 'woolab_icdic_vies_check', 'yes' ),
		'vat_exempt'            => array( 'woolab_icdic_vat_exempt_switch', 'no' ),
		'ignore_check_fail'     => array( 'woolab_icdic_ignore_check_fail', 'no' ),
		'disable_dic_dph_match' => array( 'woolab_icdic_disable_dic_dicdph_match', 'no' ),
		'toggle'                => array( 'woolab_icdic_toggle_switch', 'no' ),
		'country_ontop'         => array( 'woolab_icdic_country_switch', 'no' ),
		// Appearance (lookup.css); empty follows the theme and WooCommerce.
		'color_ok'              => array( null, '' ),
		'color_clear'           => array( null, '' ),
		'color_list_bg'         => array( null, '' ),
		'color_list_text'       => array( null, '' ),
		'color_list_border'     => array( null, '' ),
		'color_list_highlight'  => array( null, '' ),
	);
}

/**
 * All settings, defaults filled in.
 *
 * @return array<string,string>
 */
function woolab_icdic_settings_all() {
	$schema = woolab_icdic_settings_schema();
	$stored = get_option( WOOLAB_ICDIC_SETTINGS );

	if ( false === $stored ) {
		$stored = woolab_icdic_settings_migrate( $schema );
	}

	$settings = array();
	foreach ( $schema as $key => $definition ) {
		$settings[ $key ] = is_array( $stored ) && isset( $stored[ $key ] ) ? (string) $stored[ $key ] : $definition[1];
	}

	return $settings;
}

/**
 * @param string $key A woolab_icdic_settings_schema() key.
 * @return string
 */
function woolab_icdic_setting( $key ) {
	$settings = woolab_icdic_settings_all();
	return isset( $settings[ $key ] ) ? $settings[ $key ] : '';
}

/**
 * Copy the 1.x options into the settings array. Only what was actually set is
 * copied, so unset options keep following the defaults.
 *
 * @param array $schema woolab_icdic_settings_schema().
 * @return array The stored settings.
 */
function woolab_icdic_settings_migrate( array $schema ) {
	$settings = array();

	foreach ( $schema as $key => $definition ) {
		$legacy = $definition[0] ? get_option( $definition[0] ) : false;
		if ( false !== $legacy ) {
			$settings[ $key ] = (string) $legacy;
		}
	}

	add_option( WOOLAB_ICDIC_SETTINGS, $settings );

	return $settings;
}
