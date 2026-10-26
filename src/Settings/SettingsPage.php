<?php
/**
 * WooCommerce → Settings → IČO DIČ.
 *
 * Loaded only from Fields::settings_pages(): its parent, WC_Settings_Page, exists
 * once WooCommerce loads its settings screens.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Settings;

use WC_Settings_Page;

/**
 * The IČO DIČ settings tab: its sections and their fields (Fields).
 */
final class SettingsPage extends WC_Settings_Page {

	/**
	 * The tab's id and label.
	 */
	public function __construct() {
		$this->id    = 'woolab_icdic';
		$this->label = __( 'IČO DIČ', 'woolab-ic-dic' );
		parent::__construct();
	}

	/**
	 * The sections, General first.
	 *
	 * @return array<string,string>
	 */
	protected function get_own_sections() {
		return array(
			''                => __( 'General', 'woocommerce' ),
			'validation'      => __( 'Validation', 'woolab-ic-dic' ),
			'appearance'      => __( 'Appearance', 'woolab-ic-dic' ),
			'financna_sprava' => __( 'Finančná správa', 'woolab-ic-dic' ),
			'ec_sales_list'   => __( 'EC Sales List', 'woolab-ic-dic' ),
		);
	}

	/**
	 * General, with what a filter decides shown as such.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_default_section() {
		return FilterOverrides::filtered( Fields::general() );
	}

	/**
	 * Validation, with what a filter decides shown as such.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_validation_section() {
		return FilterOverrides::filtered( Fields::validation() );
	}

	/**
	 * Appearance.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_appearance_section() {
		return Fields::appearance();
	}

	/**
	 * Finančná správa: the import status.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_financna_sprava_section() {
		// Read only, nothing to save.
		$GLOBALS['hide_save_button'] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce's own switch for the Save button.
		return Fields::financna_sprava();
	}

	/**
	 * EC Sales List: the shop's details in the files.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_ec_sales_list_section() {
		return Fields::ec_sales_list();
	}
}
