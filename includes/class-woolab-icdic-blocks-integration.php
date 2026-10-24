<?php

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * blocks.js as a Checkout block integration: WooCommerce enqueues it as a
 * dependency of the block's own script, so it loads exactly where the block
 * renders, and hands it get_script_data() as wcSettings 'woolab-ic-dic_data'.
 *
 * @since 2.0.0
 */
class Woolab_Icdic_Blocks_Integration implements IntegrationInterface {

	public function get_name() {
		return 'woolab-ic-dic';
	}

	public function initialize() {
		$suffix = SCRIPT_DEBUG ? '' : '.min';

		wp_register_script(
			'woolab-icdic-blocks-js',
			WOOLAB_IC_DIC_URL . 'assets/js/blocks' . $suffix . '.js',
			array_merge( array( 'wp-data', 'wc-blocks-data-store', 'wc-settings' ), woolab_icdic_register_suggest_script() ),
			WOOLAB_IC_DIC_VERSION,
			array( 'in_footer' => true )
		);
	}

	public function get_script_handles() {
		return array( 'woolab-icdic-blocks-js' );
	}

	public function get_editor_script_handles() {
		return array();
	}

	public function get_script_data() {
		return woolab_icdic_script_data( true );
	}
}
