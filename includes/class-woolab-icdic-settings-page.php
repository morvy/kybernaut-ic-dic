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

	protected function get_own_sections() {
		return array(
			''                => __( 'General', 'woocommerce' ),
			'validation'      => __( 'Validation', 'woolab-ic-dic' ),
			'appearance'      => __( 'Appearance', 'woolab-ic-dic' ),
			'financna_sprava' => __( 'Finančná správa', 'woolab-ic-dic' ),
		);
	}

	protected function get_settings_for_default_section() {
		return woolab_icdic_settings_general();
	}

	protected function get_settings_for_validation_section() {
		return woolab_icdic_settings_validation();
	}

	protected function get_settings_for_appearance_section() {
		return woolab_icdic_settings_appearance();
	}

	protected function get_settings_for_financna_sprava_section() {
		// Read only, nothing to save.
		$GLOBALS['hide_save_button'] = true;
		return woolab_icdic_settings_financna_sprava();
	}
}
