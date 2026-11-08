<?php
/**
 * Features not ready for every shop: off by default and not in the UI, as
 * WooCommerce's hidden features.
 *
 * @package KybernautIcDic
 * @since 2.0.0
 */

namespace KybernautIcDic;

use KybernautIcDic\Settings\Options;

/**
 * A feature is a `feature_{id}` key of the settings (Settings\Options), without a
 * field. Turn one on with `wp option patch insert woolab_icdic_settings
 * feature_ec_sales_list yes` (`update` once it is stored), or in code with
 * `add_filter( 'woolab_icdic_feature_enabled', fn( $on, $id ) => 'ec_sales_list' === $id || $on, 10, 2 );`.
 */
final class Features {

	/**
	 * Whether a feature is on; one the settings do not know is not.
	 *
	 * @param string $feature Feature ID, e.g. 'ec_sales_list'.
	 */
	public static function enabled( string $feature ): bool {
		$key = 'feature_' . $feature;
		if ( ! array_key_exists( $key, Options::schema() ) ) {
			return false;
		}

		/**
		 * Turn a hidden feature on or off in code.
		 *
		 * @since 2.0.0
		 * @param bool   $enabled The setting.
		 * @param string $feature Feature ID.
		 */
		return (bool) apply_filters( 'woolab_icdic_feature_enabled', 'yes' === Options::get( $key ), $feature );
	}
}
