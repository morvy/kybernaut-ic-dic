<?php
/**
 * The plugin's links on the Plugins screen.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Admin;

/**
 * GitHub and review links in the plugin row, Settings among the actions.
 */
final class PluginLinks {

	/**
	 * Hook the links.
	 */
	public static function register(): void {
		add_filter( 'plugin_row_meta', array( self::class, 'plugin_row_meta' ), 10, 2 );

		if ( is_admin() ) {
			add_filter( 'plugin_action_links_' . WOOLAB_IC_DIC_PLUGIN_BASENAME, array( self::class, 'plugin_action_links' ) );
		}
	}

	/**
	 * GitHub and review links in the plugin row.
	 *
	 * @param string[] $links Row meta.
	 * @param string   $file  Plugin file.
	 * @return string[]
	 */
	public static function plugin_row_meta( $links, $file ) {

		if ( WOOLAB_IC_DIC_PLUGIN_BASENAME === $file ) {

			$row_meta = array(
				'github' => '<a href="https://github.com/vyskoczilova/kybernaut-ic-dic" target="_blank" aria-label="' . esc_attr__( 'View GitHub', 'woolab-ic-dic' ) . '">' . esc_html__( 'GitHub', 'woolab-ic-dic' ) . '</a>',
				'review' => '<a href="http://wordpress.org/support/view/plugin-reviews/woolab-ic-dic" target="_blank" aria-label="' . esc_attr__( 'Write a Review', 'woolab-ic-dic' ) . '">' . esc_html__( 'Write a Review', 'woolab-ic-dic' ) . '</a>',
			);

			return array_merge( $links, $row_meta );
		}

		return (array) $links;
	}

	/**
	 * The Settings link among the plugin's actions.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public static function plugin_action_links( $links ) {
		$action_links = array(
			'settings' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=woolab_icdic' ) . '" aria-label="' . esc_attr__( 'View Kybernaut IČO DIČ settings', 'woolab-ic-dic' ) . '">' . esc_html__( 'Settings', 'woolab-ic-dic' ) . '</a>',
		);

		return array_merge( $action_links, $links );
	}
}
