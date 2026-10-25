<?php
/**
 * The Checkout block integration.
 *
 * @package KybernautIcDic
 * @since 2.0.0
 */

namespace KybernautIcDic\Checkout;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use KybernautIcDic\Assets;

/**
 * The blocks.js script as a Checkout block integration: WooCommerce enqueues it as a
 * dependency of the block's own script, so it loads exactly where the block
 * renders, and hands it get_script_data() as wcSettings 'woolab-ic-dic_data'.
 */
final class BlocksIntegration implements IntegrationInterface {

	/**
	 * The integration's name, the wcSettings key prefix.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'woolab-ic-dic';
	}

	/**
	 * Register blocks.js.
	 *
	 * @return void
	 */
	public function initialize() {
		$suffix = SCRIPT_DEBUG ? '' : '.min';

		wp_register_script(
			'woolab-icdic-blocks-js',
			WOOLAB_IC_DIC_URL . 'assets/js/blocks' . $suffix . '.js',
			array_merge( array( 'wp-data', 'wc-blocks-data-store', 'wc-settings', 'wc-blocks-checkout' ), Assets::register_suggest_script() ),
			WOOLAB_IC_DIC_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * The front-end scripts.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( 'woolab-icdic-blocks-js' );
	}

	/**
	 * No editor scripts.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * The settings blocks.js reads, as wcSettings 'woolab-ic-dic_data'.
	 *
	 * @return array<string,mixed>
	 */
	public function get_script_data() {
		return Assets::script_data( true );
	}
}
