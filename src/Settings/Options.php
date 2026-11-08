<?php
/**
 * Plugin settings, stored as one array in the `woolab_icdic_settings` option.
 *
 * Up to 1.x every setting was an option of its own (`woolab_icdic_ares_check`, …).
 * Options::all() copies those into the array the first time it finds none, and the
 * old options are left in place for one release so a downgrade to 1.x still finds
 * them.
 *
 * The array is autoloaded on purpose: the block checkout fields are registered on
 * every request (they read the toggle), so a non-autoloaded option would cost a
 * query on every page instead of none.
 *
 * @package KybernautIcDic
 * @since 2.0.0
 */

namespace KybernautIcDic\Settings;

/**
 * The settings array.
 */
final class Options {

	/**
	 * The option the settings are stored in.
	 */
	public const NAME = 'woolab_icdic_settings';

	/**
	 * Setting key => array( 1.x option name or null, default ).
	 *
	 * @return array<string,array{0:string|null,1:string}>
	 */
	public static function schema(): array {
		return array(
			'ares_check'            => array( 'woolab_icdic_ares_check', 'yes' ),
			'ares_fill'             => array( 'woolab_icdic_ares_fill', 'no' ),
			'sk_check'              => array( 'woolab_icdic_sk_check', 'no' ),
			'sk_fill'               => array( 'woolab_icdic_sk_fill', 'no' ),
			'vies_check'            => array( 'woolab_icdic_vies_check', 'yes' ),
			'shop_vat_id'           => array( null, '' ),
			'vat_exempt'            => array( 'woolab_icdic_vat_exempt_switch', 'no' ),
			'ignore_check_fail'     => array( 'woolab_icdic_ignore_check_fail', 'no' ),
			'disable_dic_dph_match' => array( 'woolab_icdic_disable_dic_dicdph_match', 'no' ),
			'dic_required'          => array( null, 'no' ),
			'toggle'                => array( 'woolab_icdic_toggle_switch', 'no' ),
			'country_ontop'         => array( 'woolab_icdic_country_switch', 'no' ),
			'ic_countries_only'     => array( null, 'no' ),
			'company_fields'        => array( null, 'optional' ),
			// Appearance (lookup.css); empty follows the theme and WooCommerce.
			'color_ok'              => array( null, '' ),
			'color_clear'           => array( null, '' ),
			'color_success'         => array( null, '' ),
			'color_error'           => array( null, '' ),
			'color_info'            => array( null, '' ),
			'color_list_bg'         => array( null, '' ),
			'color_list_text'       => array( null, '' ),
			'color_list_border'     => array( null, '' ),
			'color_list_highlight'  => array( null, '' ),
			'list_radius'           => array( null, '' ),
			// The shop's details in the EC Sales List files (EcSalesList\Xml).
			'ec_company'            => array( null, '' ),
			'ec_person'             => array( null, '' ),
			'ec_person_role'        => array( null, '' ),
			'ec_phone'              => array( null, '' ),
			'ec_cz_ufo'             => array( null, '' ),
			'ec_cz_pracufo'         => array( null, '' ),
			'ec_cz_type'            => array( null, 'P' ),
			// Hidden features (KybernautIcDic\Features): no field, off by default.
			'feature_ec_sales_list' => array( null, 'no' ),
		);
	}

	/**
	 * All settings, defaults filled in.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		$schema = self::schema();
		$stored = get_option( self::NAME );

		if ( false === $stored ) {
			$stored = self::migrate( $schema );
		}

		$settings = array();
		foreach ( $schema as $key => $definition ) {
			$settings[ $key ] = is_array( $stored ) && isset( $stored[ $key ] ) ? (string) $stored[ $key ] : $definition[1];
		}

		return $settings;
	}

	/**
	 * One setting.
	 *
	 * @param string $key A schema() key.
	 */
	public static function get( string $key ): string {
		return self::all()[ $key ] ?? '';
	}

	/**
	 * Copy the 1.x options into the settings array. Only what was actually set is
	 * copied, so unset options keep following the defaults.
	 *
	 * @param array<string,array{0:string|null,1:string}> $schema schema().
	 * @return array<string,string> The stored settings.
	 */
	public static function migrate( array $schema ): array {
		$settings = array();

		foreach ( $schema as $key => $definition ) {
			$legacy = $definition[0] ? get_option( $definition[0] ) : false;
			if ( false !== $legacy ) {
				$settings[ $key ] = (string) $legacy;
			}
		}

		add_option( self::NAME, $settings );

		return $settings;
	}
}
