<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * WooCommerce → Settings → IČO DIČ (includes/settings.php).
 */
class Woolab_Icdic_Settings_Page extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'woolab_icdic';
		$this->label = __( 'IČO DIČ', 'woolab-ic-dic' );
		parent::__construct();
	}

	protected function get_settings_for_default_section() {
		return woolab_icdic_settings();
	}
}
