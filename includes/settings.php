<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The plugin's own tab in WooCommerce → Settings. The option names are unchanged
 * from when these lived in the General tab, so nothing needs migrating.
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

/**
 * @return array WooCommerce settings fields.
 */
function woolab_icdic_settings() {

    $vies_check_disabled = false;
    $vat_check_disabled  = false;

    if ( class_exists('SoapClient') ) {
        $vies_desc  = __( 'Enable validation of VAT number in EU database VIES.', 'woolab-ic-dic' );
        $vies_check = 'yes';
    } else {
        $vies_desc  = '<span style="color:#ca4a1f">' . __( 'To enable this feature, turn on Soap Client (ask your hosting).', 'woolab-ic-dic' ) . '</span> ' . __( 'Enable validation of VAT number in EU database VIES.', 'woolab-ic-dic' ) ;
        $vies_check = 'yes';
        $vies_check_disabled = true;
    }

    $vat_desc      = __( 'Enable VAT exemption for valid EU VAT numbers', 'woolab-ic-dic' );
    if ( wc_tax_enabled() ) {
        $vat_check = 'no';
    } else {
        $vat_desc  = $vat_desc . '<br><span style="color:#ca4a1f">' . __( 'To enable this feature, turn on taxes in your store.', 'woolab-ic-dic' ) . '</span>';
        $vat_check = 'no';
        $vat_check_disabled  = true;

        $wc_countries  = new WC_Countries();
        $vat_countries = $wc_countries->get_european_union_countries('eu_vat');
        $base_country  = $wc_countries->get_base_country();

        if ( !in_array($base_country, $vat_countries) ) {
            $vat_desc  = $vat_desc . '<br><span style="color:#ca4a1f">' . __( 'To enable this feature, set your base country to one of the EU VAT countries.', 'woolab-ic-dic' ) . '</span>';
        }
    }

    $fluid_checkout = class_exists( 'FluidCheckout' ) ? ' <br><span style="color:#ca4a1f">' . __( 'This feature is not compatible with Fluid Checkout for WooCommerce.', 'woolab-ic-dic' ) . '</span>' : '';

    $sk_updated = get_option( 'woolab_icdic_sk_updated' );
    $sk_status  = is_array( $sk_updated )
        /* translators: 1: date and time, 2: number of DIČ, 3: number of IČ DPH */
        ? sprintf( __( 'Lists last imported %1$s (%2$s DIČ, %3$s IČ DPH).', 'woolab-ic-dic' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $sk_updated['time'] ), number_format_i18n( $sk_updated['counts']['dic'] ), number_format_i18n( $sk_updated['counts']['dph'] ) )
        : __( 'Lists not imported yet; the first import runs on the next WP-Cron run after enabling (or run "wp woolab-icdic sk-import").', 'woolab-ic-dic' );

    return array(

        // Checkout form
        array( 'title' => __( 'Kybernaut IČO DIČ options', 'woolab-ic-dic' ), 'type' => 'title', 'desc' => __( 'The following options affect how Business ID and VAT number behaves.', 'woolab-ic-dic' ), 'id' => 'woolab_icdic_options' ),
        array(
            'title'    => __( 'Toggle fields visibility', 'woolab-ic-dic' ),
            'desc'     => __( 'Enable toggle switch to show/hide input fields', 'woolab-ic-dic' ) . $fluid_checkout,
            'id'       => 'woolab_icdic_settings[toggle]',
            'default'  => 'no',
            'type'     => 'checkbox',
            'disabled' => (bool) $fluid_checkout,
        ),
        array(
            'title'    => __( 'Move Country to top', 'woolab-ic-dic' ),
            'desc'     => __( 'Move Country field above the "Buying as a company" toggle', 'woolab-ic-dic' ) . $fluid_checkout,
            'id'       => 'woolab_icdic_settings[country_ontop]',
            'default'  => 'no',
            'type'     => 'checkbox',
            'disabled' => (bool) $fluid_checkout,
        ),
        array(
            'title'   => __( 'Ignore when ARES, Finančná správa or VIES check fails for technical reasons', 'woolab-ic-dic' ),
            'desc'    => __( 'Allow the creation of an order if the verification of the Business ID in ARES or Finančná správa, or the VAT number in the European VIES database fails for technical reasons.', 'woolab-ic-dic' ),
            'id'      => 'woolab_icdic_settings[ignore_check_fail]',
            'default' => 'no',
            'type'    => 'checkbox',
        ),
        array( 'type' => 'sectionend', 'id' => 'woolab_icdic_options' ),

        // Czech Republic
        array( 'title' => __( 'Czech Republic', 'woolab-ic-dic' ), 'type' => 'title', 'id' => 'woolab_icdic_cz' ),
        array(
            'title'   => __( 'CZ: Validate Business ID in ARES', 'woolab-ic-dic' ),
            'desc'    => __( 'Enable validation of Business ID in Czech database ARES.', 'woolab-ic-dic' ),
            'id'      => 'woolab_icdic_settings[ares_check]',
            'default' => 'yes',
            'type'    => 'checkbox',
        ),
        array(
            'title'   => __( 'CZ: Validate and autofill based on ARES', 'woolab-ic-dic' ),
            'desc'    => __( 'Enable company name search, and autofill and validation for Company, VAT number, Address, City, and Postcode fields based on Czech database ARES. Requires checked the option above.', 'woolab-ic-dic' ),
            'id'      => 'woolab_icdic_settings[ares_fill]',
            'default' => 'no',
            'type'    => 'checkbox',
        ),
        array( 'type' => 'sectionend', 'id' => 'woolab_icdic_cz' ),

        // Slovakia
        array( 'title' => __( 'Slovakia', 'woolab-ic-dic' ), 'type' => 'title', 'id' => 'woolab_icdic_sk' ),
        array(
            'title'    => __( 'SK: Validate in Finančná správa', 'woolab-ic-dic' ),
            'desc'     => __( 'Check Business ID, DIČ and IČ DPH in the Finančná správa lists, downloaded daily (about 70 MB) into a SQLite file in the uploads folder (about 190 MB). Business IDs not in the lists yet (new, or not registered for tax) are accepted without autofill. IČ DPH is checked in VIES only when the lists do not have it.', 'woolab-ic-dic' ) . '<br>' . $sk_status . '<br>' . __( 'Source: Finančná správa SR.', 'woolab-ic-dic' ),
            'id'       => 'woolab_icdic_settings[sk_check]',
            'default'  => 'no',
            'type'     => 'checkbox',
            'disabled' => ! woolab_icdic_sk_import_supported(),
        ),
        array(
            'title'   => __( 'SK: Validate and autofill based on Finančná správa', 'woolab-ic-dic' ),
            'desc'    => __( 'Enable company name search, and autofill and validation for Company, DIČ, IČ DPH, Address, City, and Postcode fields. Requires checked the option above.', 'woolab-ic-dic' ),
            'id'      => 'woolab_icdic_settings[sk_fill]',
            'default' => 'no',
            'type'    => 'checkbox',
        ),
        array(
            'title'   => __( 'SK: Disable DIČ / IČ DPH match check', 'woolab-ic-dic' ),
            'desc'    => __( 'Disable validation that IČ DPH must equal "SK" + DIČ. Some non-profit organizations have IČ DPH that does not match their DIČ.', 'woolab-ic-dic' ),
            'id'      => 'woolab_icdic_settings[disable_dic_dph_match]',
            'default' => 'no',
            'type'    => 'checkbox',
        ),
        array( 'type' => 'sectionend', 'id' => 'woolab_icdic_sk' ),

        // European Union
        array( 'title' => __( 'European Union', 'woolab-ic-dic' ), 'type' => 'title', 'id' => 'woolab_icdic_eu' ),
        array(
            'title'    => __( 'EU: Validate VAT number in VIES', 'woolab-ic-dic' ),
            'desc'     => $vies_desc,
            'id'       => 'woolab_icdic_settings[vies_check]',
            'default'  => $vies_check,
            'type'     => 'checkbox',
            'disabled' => $vies_check_disabled,
        ),
        array(
            'title'    => __( 'EU: VAT exempt', 'woolab-ic-dic' ),
            'desc'     => $vat_desc,
            'id'       => 'woolab_icdic_settings[vat_exempt]',
            'default'  => $vat_check,
            'type'     => 'checkbox',
            'disabled' => $vat_check_disabled,
        ),
        array( 'type' => 'sectionend', 'id' => 'woolab_icdic_eu' ),
    );
}
