<?php
/**
 * Settings a filter in the theme or a plugin decides over: shown as such on the
 * settings page ("Set by the … filter"), and kept as stored when the page is saved.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Settings;

use KybernautIcDic\Fields\Countries;

/**
 * The 1.x filters over the settings, on the settings page.
 */
final class FilterOverrides {

	/**
	 * Hook the save.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_admin_settings_sanitize_option', array( self::class, 'keep_filtered' ), 10, 2 );
	}

	/**
	 * The settings a filter decides over, the 1.x ones kept working: key =>
	 * array( filter, the value in effect ).
	 *
	 * @return array<string,array{0:string,1:callable}>
	 */
	public static function settings_filters() {
		return array(
			'toggle'                => array( 'woolab_icdic_toggle', array( Flags::class, 'toggle' ) ),
			'country_ontop'         => array( 'woolab_icdic_country_ontop', array( Flags::class, 'country_ontop' ) ),
			'company_fields'        => array( 'woolab_icdic_fake_required', array( Countries::class, 'fields_mode' ) ),
			'ignore_check_fail'     => array( 'woolab_icdic_ignore_check_fail', array( Flags::class, 'ignore_check_fail' ) ),
			'ares_check'            => array( 'woolab_icdic_ares_check', array( Flags::class, 'ares_check' ) ),
			'ares_fill'             => array( 'woolab_icdic_ares_fill', array( Flags::class, 'ares_fill' ) ),
			'sk_check'              => array( 'woolab_icdic_sk_check', array( Flags::class, 'sk_check' ) ),
			'sk_fill'               => array( 'woolab_icdic_sk_fill', array( Flags::class, 'sk_fill' ) ),
			'dic_required'          => array(
				'woolab_icdic_sk_required_ic_and_dic',
				fn() => Countries::dic_required( Countries::base_country() ),
			),
			'disable_dic_dph_match' => array(
				'woolab_icdic_enable_dic_dicdph_match_check',
				fn() => ! Flags::dic_dph_match(),
			),
			'vies_check'            => array( 'woolab_icdic_vies_check', array( Flags::class, 'vies_check' ) ),
			'vat_exempt'            => array( 'woolab_icdic_vat_exempt_enabled', array( Flags::class, 'vat_exempt' ) ),
		);
	}

	/**
	 * The settings_filters() key of a settings field, if a filter in the theme or a
	 * plugin decides over it.
	 *
	 * @param array<string,mixed> $field WooCommerce settings field.
	 * @return string|null
	 */
	public static function filtered_key( $field ) {
		$filters = self::settings_filters();
		$key     = isset( $field['id'] ) && preg_match( '/^' . Options::NAME . '\[(\w+)\]$/', $field['id'], $m ) ? $m[1] : '';

		if ( ! isset( $filters[ $key ] ) || ! has_filter( $filters[ $key ][0] ) ) {
			return null;
		}

		// woolab_icdic_fake_required only turns "Optional" and "No mark" into "Asterisk".
		if ( 'company_fields' === $key && Countries::fields_mode() === Options::get( 'company_fields' ) ) {
			return null;
		}

		// woolab_icdic_sk_required_ic_and_dic only decides for a Slovak shop.
		if ( 'dic_required' === $key && 'SK' !== Countries::base_country() ) {
			return null;
		}

		return $key;
	}

	/**
	 * Show what a filter decided: the field reads the value in effect, cannot be
	 * changed here, and says why. Saving keeps the stored value (keep_filtered()),
	 * so it is back once the filter goes.
	 *
	 * @param array<int,array<string,mixed>> $fields WooCommerce settings fields.
	 * @return array<int,array<string,mixed>>
	 */
	public static function filtered( array $fields ) {
		foreach ( $fields as $i => $field ) {
			$key = self::filtered_key( $field );

			if ( null === $key ) {
				continue;
			}

			$filter = self::settings_filters()[ $key ];
			$value  = call_user_func( $filter[1] );

			$fields[ $i ]['value'] = is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value;
			/* translators: %s: filter name */
			$fields[ $i ]['desc'] = ( $field['desc'] ?? '' ) . '<br><em>' . sprintf( __( 'Set by the %s filter in the theme or a plugin.', 'woolab-ic-dic' ), '<code>' . esc_html( $filter[0] ) . '</code>' ) . '</em>';

			if ( 'checkbox' === $field['type'] ) {
				$fields[ $i ]['disabled'] = true;
			} else {
				$fields[ $i ]['custom_attributes'] = array( 'disabled' => 'disabled' );
			}
		}

		return $fields;
	}

	/**
	 * A field a filter decides over is not posted (it is disabled), so it would be
	 * saved empty: keep what is stored instead.
	 *
	 * @param mixed               $value  Sanitized value.
	 * @param array<string,mixed> $option WooCommerce settings field.
	 * @return mixed Null skips the field.
	 */
	public static function keep_filtered( $value, $option ) {
		return null === self::filtered_key( $option ) ? $value : null;
	}
}
