<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * WooCommerce → Settings → IČO DIČ, in four sections: General, Validation,
 * Appearance and the Finančná správa status. Every field saves into the one
 * settings array (includes/options.php).
 */
add_filter( 'woocommerce_get_settings_pages', function ( $pages ) {
	static $page = null;

	// WC_Settings_Page exists only once WooCommerce loads its settings screens.
	if ( null === $page ) {
		require_once __DIR__ . '/class-woolab-icdic-settings-page.php';
		$page = new Woolab_Icdic_Settings_Page();
	}

	$pages[] = $page;

	return $pages;
} );

add_action( 'woocommerce_admin_field_woolab_icdic_sk_status', 'woolab_icdic_settings_sk_status_field' );

/**
 * The settings field id for a woolab_icdic_settings_schema() key.
 */
function woolab_icdic_settings_id( $key ) {
	return WOOLAB_ICDIC_SETTINGS . '[' . $key . ']';
}

/**
 * Settings URL, optionally of one section.
 */
function woolab_icdic_settings_url( $section = '' ) {
	return admin_url( 'admin.php?page=wc-settings&tab=woolab_icdic' . ( $section ? '&section=' . $section : '' ) );
}

/**
 * Red notice appended to a setting the store cannot use.
 */
function woolab_icdic_settings_warning( $message ) {
	return '<br><span style="color:#ca4a1f">' . $message . '</span>';
}

/**
 * General: how the fields behave in the checkout form.
 *
 * @return array WooCommerce settings fields.
 */
function woolab_icdic_settings_general() {

	$fluid_checkout = class_exists( 'FluidCheckout' ) ? woolab_icdic_settings_warning( __( 'This feature is not compatible with Fluid Checkout for WooCommerce.', 'woolab-ic-dic' ) ) : '';

	return array(
		array( 'title' => __( 'Kybernaut IČO DIČ options', 'woolab-ic-dic' ), 'type' => 'title', 'desc' => __( 'The following options affect how Business ID and VAT number behaves.', 'woolab-ic-dic' ), 'id' => 'woolab_icdic_options' ),
		array(
			'title'    => __( 'Toggle fields visibility', 'woolab-ic-dic' ),
			'desc'     => __( 'Enable toggle switch to show/hide input fields', 'woolab-ic-dic' ) . $fluid_checkout,
			'id'       => woolab_icdic_settings_id( 'toggle' ),
			'default'  => 'no',
			'type'     => 'checkbox',
			'disabled' => (bool) $fluid_checkout,
		),
		array(
			'title'    => __( 'Move Country to top', 'woolab-ic-dic' ),
			'desc'     => __( 'Move Country field above the "Buying as a company" toggle', 'woolab-ic-dic' ) . $fluid_checkout,
			'id'       => woolab_icdic_settings_id( 'country_ontop' ),
			'default'  => 'no',
			'type'     => 'checkbox',
			'disabled' => (bool) $fluid_checkout,
		),
		array(
			'title'   => __( 'Company fields', 'woolab-ic-dic' ),
			'desc'    => __( 'The company name, the Business ID (in the Czech Republic and Slovakia, where every company has one) and the Slovak Tax ID. "Required for companies" works best with the toggle: without it, a filled-in company name makes the customer a company. The VAT ID stays optional, abroad with "only if you are a VAT payer".', 'woolab-ic-dic' ),
			'id'      => woolab_icdic_settings_id( 'company_fields' ),
			'default' => 'optional',
			'type'    => 'select',
			'options' => array(
				'optional' => __( 'Optional: "(optional)", as WooCommerce marks fields', 'woolab-ic-dic' ),
				'plain'    => __( 'No mark: like any other field', 'woolab-ic-dic' ),
				'asterisk' => __( 'Asterisk: marked required, the order goes through without them', 'woolab-ic-dic' ),
				'required' => __( 'Required for companies: no order without them (recommended)', 'woolab-ic-dic' ),
			),
		),
		array(
			'title'   => __( 'Ignore when ARES, Finančná správa or VIES check fails for technical reasons', 'woolab-ic-dic' ),
			'desc'    => __( 'Allow the creation of an order if the verification of the Business ID in ARES or Finančná správa, or the VAT number in the European VIES database fails for technical reasons.', 'woolab-ic-dic' ),
			'id'      => woolab_icdic_settings_id( 'ignore_check_fail' ),
			'default' => 'no',
			'type'    => 'checkbox',
		),
		array( 'type' => 'sectionend', 'id' => 'woolab_icdic_options' ),
	);
}

/**
 * Validation: the registers, per country.
 *
 * @return array WooCommerce settings fields.
 */
function woolab_icdic_settings_validation() {

	$vies_desc = __( 'Enable validation of VAT number in EU database VIES.', 'woolab-ic-dic' );

	$vat_desc = __( 'Enable VAT exemption for valid EU VAT numbers', 'woolab-ic-dic' );
	if ( ! wc_tax_enabled() ) {
		$vat_desc .= woolab_icdic_settings_warning( __( 'To enable this feature, turn on taxes in your store.', 'woolab-ic-dic' ) );

		$wc_countries = WC()->countries;
		if ( ! in_array( $wc_countries->get_base_country(), $wc_countries->get_european_union_countries( 'eu_vat' ), true ) ) {
			$vat_desc .= woolab_icdic_settings_warning( __( 'To enable this feature, set your base country to one of the EU VAT countries.', 'woolab-ic-dic' ) );
		}
	}

	$sk_desc = __( 'Check Business ID, DIČ and IČ DPH in the Finančná správa lists, downloaded daily (about 70 MB) into a SQLite file in the uploads folder (about 190 MB). Business IDs not in the lists yet (new, or not registered for tax) are accepted without autofill. IČ DPH is checked in VIES only when the lists do not have it.', 'woolab-ic-dic' )
		. ' <a href="' . esc_url( woolab_icdic_settings_url( 'financna_sprava' ) ) . '">' . esc_html__( 'Import status', 'woolab-ic-dic' ) . '</a>';
	if ( ! woolab_icdic_sk_import_supported() ) {
		$sk_desc .= woolab_icdic_settings_warning( __( 'Kybernaut IČO DIČ: the Finančná správa DIČ / IČ DPH lists cannot be imported, the zip, xmlreader and pdo_sqlite PHP extensions are required (ask your hosting).', 'woolab-ic-dic' ) );
	}

	return array(

		array( 'title' => __( 'Czech Republic', 'woolab-ic-dic' ), 'type' => 'title', 'id' => 'woolab_icdic_cz' ),
		array(
			'title'   => __( 'CZ: Validate Business ID in ARES', 'woolab-ic-dic' ),
			'desc'    => __( 'Enable validation of Business ID in Czech database ARES.', 'woolab-ic-dic' ),
			'id'      => woolab_icdic_settings_id( 'ares_check' ),
			'default' => 'yes',
			'type'    => 'checkbox',
		),
		array(
			'title'   => __( 'CZ: Validate and autofill based on ARES', 'woolab-ic-dic' ),
			'desc'    => __( 'Enable company name search, and autofill and validation for Company, VAT number, Address, City, and Postcode fields based on Czech database ARES. Requires checked the option above.', 'woolab-ic-dic' ),
			'id'      => woolab_icdic_settings_id( 'ares_fill' ),
			'default' => 'no',
			'type'    => 'checkbox',
		),
		array( 'type' => 'sectionend', 'id' => 'woolab_icdic_cz' ),

		array( 'title' => __( 'Slovakia', 'woolab-ic-dic' ), 'type' => 'title', 'id' => 'woolab_icdic_sk' ),
		array(
			'title'    => __( 'SK: Validate in Finančná správa', 'woolab-ic-dic' ),
			'desc'     => $sk_desc,
			'id'       => woolab_icdic_settings_id( 'sk_check' ),
			'default'  => 'no',
			'type'     => 'checkbox',
			'disabled' => ! woolab_icdic_sk_import_supported(),
		),
		array(
			'title'   => __( 'SK: Validate and autofill based on Finančná správa', 'woolab-ic-dic' ),
			'desc'    => __( 'Enable company name search, and autofill and validation for Company, DIČ, IČ DPH, Address, City, and Postcode fields. Requires checked the option above.', 'woolab-ic-dic' ),
			'id'      => woolab_icdic_settings_id( 'sk_fill' ),
			'default' => 'no',
			'type'    => 'checkbox',
		),
		array(
			'title'   => __( 'SK: Disable DIČ / IČ DPH match check', 'woolab-ic-dic' ),
			'desc'    => __( 'Disable validation that IČ DPH must equal "SK" + DIČ. Some non-profit organizations have IČ DPH that does not match their DIČ.', 'woolab-ic-dic' ),
			'id'      => woolab_icdic_settings_id( 'disable_dic_dph_match' ),
			'default' => 'no',
			'type'    => 'checkbox',
		),
		array( 'type' => 'sectionend', 'id' => 'woolab_icdic_sk' ),

		array( 'title' => __( 'European Union', 'woolab-ic-dic' ), 'type' => 'title', 'id' => 'woolab_icdic_eu' ),
		array(
			'title'    => __( 'EU: Validate VAT number in VIES', 'woolab-ic-dic' ),
			'desc'     => $vies_desc,
			'id'       => woolab_icdic_settings_id( 'vies_check' ),
			'default'  => 'yes',
			'type'     => 'checkbox',
		),
		array(
			'title'   => __( 'Business ID only where companies have one', 'woolab-ic-dic' ),
			'desc'    => __( 'Hide the Business ID field for billing countries other than Slovakia and the Czech Republic, whose companies have no IČO. The list can be changed with the woolab_icdic_ic_countries filter.', 'woolab-ic-dic' ),
			'id'      => woolab_icdic_settings_id( 'ic_countries_only' ),
			'default' => 'no',
			'type'    => 'checkbox',
		),
		array(
			'title'    => __( 'EU: VAT exempt', 'woolab-ic-dic' ),
			'desc'     => $vat_desc,
			'id'       => woolab_icdic_settings_id( 'vat_exempt' ),
			'default'  => 'no',
			'type'     => 'checkbox',
			'disabled' => ! wc_tax_enabled(),
		),
		array( 'type' => 'sectionend', 'id' => 'woolab_icdic_eu' ),
	);
}

/**
 * Appearance: colours of the lookup UI (src/css/lookup.css). Empty follows the
 * theme and WooCommerce, as without these settings.
 *
 * @return array WooCommerce settings fields.
 */
function woolab_icdic_settings_appearance() {

	$color = function ( $key, $title, $desc ) {
		return array(
			'title'    => $title,
			'desc'     => $desc,
			'desc_tip' => true,
			'id'       => woolab_icdic_settings_id( $key ),
			'default'  => '',
			'type'     => 'color',
			'css'      => 'width:6em;',
		);
	};

	$theme = __( 'Leave empty to follow the theme.', 'woolab-ic-dic' );

	return array(
		array( 'title' => __( 'Status icons', 'woolab-ic-dic' ), 'type' => 'title', 'desc' => __( 'The padlock on the company name and Business ID after an autofill, and the checkmark on the other filled-in fields.', 'woolab-ic-dic' ), 'id' => 'woolab_icdic_icons' ),
		$color( 'color_ok', __( 'Verified', 'woolab-ic-dic' ), __( 'Padlock and checkmark. Leave empty for green (#007518).', 'woolab-ic-dic' ) ),
		$color( 'color_clear', __( 'Clear', 'woolab-ic-dic' ), __( 'The X the padlock turns into. Leave empty for red (#a00).', 'woolab-ic-dic' ) ),
		array( 'type' => 'sectionend', 'id' => 'woolab_icdic_icons' ),

		array( 'title' => __( 'Company name suggestions', 'woolab-ic-dic' ), 'type' => 'title', 'desc' => __( 'By default the list takes its colours from the company field.', 'woolab-ic-dic' ), 'id' => 'woolab_icdic_list' ),
		$color( 'color_list_bg', __( 'Background', 'woolab-ic-dic' ), $theme ),
		$color( 'color_list_text', __( 'Text', 'woolab-ic-dic' ), $theme ),
		$color( 'color_list_border', __( 'Border', 'woolab-ic-dic' ), $theme ),
		$color( 'color_list_highlight', __( 'Highlighted suggestion', 'woolab-ic-dic' ), __( 'Leave empty for a tint of the text colour.', 'woolab-ic-dic' ) ),
		array( 'type' => 'sectionend', 'id' => 'woolab_icdic_list' ),
	);
}

/**
 * Finančná správa: the import status, read only.
 *
 * @return array WooCommerce settings fields.
 */
function woolab_icdic_settings_financna_sprava() {
	return array(
		array( 'title' => __( 'Finančná správa lists', 'woolab-ic-dic' ), 'type' => 'title', 'desc' => __( 'The DIČ and IČ DPH lists the Slovak checks run against, imported daily.', 'woolab-ic-dic' ), 'id' => 'woolab_icdic_sk_status' ),
		array( 'type' => 'woolab_icdic_sk_status', 'id' => 'woolab_icdic_sk_status_table' ),
		array( 'type' => 'sectionend', 'id' => 'woolab_icdic_sk_status' ),
	);
}

/**
 * Rows of the Finančná správa status.
 */
function woolab_icdic_settings_sk_status_field() {

	$status   = woolab_icdic_sk_status();
	$datetime = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	$ago      = function ( $time ) use ( $datetime ) {
		/* translators: 1: date and time, 2: time span, e.g. "3 hours" */
		return sprintf( __( '%1$s (%2$s ago)', 'woolab-ic-dic' ), wp_date( $datetime, $time ), human_time_diff( $time ) );
	};
	$ok       = '<span style="color:#008a20">✔</span> ';
	$warn     = '<span style="color:#ca4a1f">✖</span> ';

	if ( 'yes' !== woolab_icdic_setting( 'sk_check' ) ) {
		$state = sprintf(
			/* translators: %s: link to the Validation settings */
			esc_html__( 'Off. Enable "SK: Validate in Finančná správa" in %s.', 'woolab-ic-dic' ),
			'<a href="' . esc_url( woolab_icdic_settings_url( 'validation' ) ) . '">' . esc_html__( 'Validation', 'woolab-ic-dic' ) . '</a>'
		);
	} elseif ( $status['fresh'] ) {
		$state = $ok . esc_html__( 'Up to date', 'woolab-ic-dic' );
	} elseif ( $status['updated'] ) {
		$state = $warn . esc_html__( 'Older than a week: Slovak numbers are checked in VIES only, or let through when "ignore when the check fails" is on.', 'woolab-ic-dic' );
	} else {
		$state = $warn . esc_html__( 'Not imported yet', 'woolab-ic-dic' );
	}

	$rows = array( __( 'Status', 'woolab-ic-dic' ) => $state );

	if ( $status['updated'] ) {
		$rows[ __( 'Last import', 'woolab-ic-dic' ) ] = esc_html( $ago( $status['updated']['time'] ) ) . '<br>'
			/* translators: 1: number of DIČ, 2: number of IČ DPH */
			. esc_html( sprintf( __( '%1$s DIČ, %2$s IČ DPH', 'woolab-ic-dic' ), number_format_i18n( $status['updated']['counts']['dic'] ), number_format_i18n( $status['updated']['counts']['dph'] ) ) );
		$rows[ __( 'Database', 'woolab-ic-dic' ) ] = false === $status['file_size']
			? $warn . esc_html__( 'The file is missing, the next import creates it again.', 'woolab-ic-dic' )
			: esc_html( size_format( $status['file_size'], 1 ) ) . ' <code>' . esc_html( 'uploads/woolab-ic-dic/' . $status['updated']['file'] ) . '</code>';
	}

	if ( is_array( $status['failed'] ) && ( ! $status['updated'] || $status['failed']['time'] > $status['updated']['time'] ) ) {
		$rows[ __( 'Last failed import', 'woolab-ic-dic' ) ] = $warn . esc_html( $ago( $status['failed']['time'] ) ) . '<br><code>' . esc_html( $status['failed']['error'] ) . '</code>';
	}

	if ( 'yes' === woolab_icdic_setting( 'sk_check' ) ) {
		if ( ! $status['next'] ) {
			$next = $warn . esc_html__( 'Not scheduled, it is scheduled again on the next page load.', 'woolab-ic-dic' );
		} elseif ( $status['next'] < time() - HOUR_IN_SECONDS ) {
			/* translators: %s: date and time */
			$next = $warn . esc_html( sprintf( __( '%s, overdue: WP-Cron has not run since. It runs on page visits, or set up a server cron job for wp-cron.php.', 'woolab-ic-dic' ), wp_date( $datetime, $status['next'] ) ) );
		} else {
			$next = esc_html( wp_date( $datetime, $status['next'] ) );
		}
		$rows[ __( 'Next import', 'woolab-ic-dic' ) ] = $next;
	}

	$extensions = array();
	foreach ( woolab_icdic_sk_requirements() as $extension => $available ) {
		$extensions[] = ( $available ? $ok : $warn ) . esc_html( $extension );
	}
	$rows[ __( 'PHP extensions', 'woolab-ic-dic' ) ] = implode( ' &nbsp; ', $extensions );

	$rows[ __( 'Import now', 'woolab-ic-dic' ) ] = '<code>wp woolab-icdic sk-import</code><br>'
		. sprintf(
			/* translators: %s: link to WooCommerce → Status → Logs */
			esc_html__( 'Every import is logged in %s.', 'woolab-ic-dic' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . esc_html__( 'WooCommerce → Status → Logs', 'woolab-ic-dic' ) . '</a>'
		);

	foreach ( $rows as $label => $value ) {
		// Values are escaped above; only the icons and links are markup.
		printf( '<tr><th scope="row" class="titledesc">%s</th><td class="forminp">%s</td></tr>', esc_html( $label ), wp_kses_post( $value ) );
	}
}
