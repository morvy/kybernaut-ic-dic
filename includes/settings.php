<?php

add_filter('woocommerce_general_settings', 'woolab_icdic_icdic_general_settings');
function woolab_icdic_icdic_general_settings($settings) {

    if ( class_exists('SoapClient') ) {
        $vies_desc = __( 'Enable validation of VAT number in EU database VIES.', 'woolab-ic-dic' );
        $vies_check = 'yes';
    } else {
        $vies_desc = '<span style="color:#ca4a1f">' . __( 'To enable this feature, turn on Soap Client (ask your hosting).', 'woolab-ic-dic' ) . '</span> ' . __( 'Enable validation of VAT number in EU database VIES.', 'woolab-ic-dic' ) ;
        $vies_check = 'yes';
    }

    $settings[] = array( 'title' => __( 'Kybernaut IČO DIČ options', 'woolab-ic-dic' ), 'type' => 'title', 'desc' => __( 'The following options affect how Business ID and VAT number behaves.', 'woolab-ic-dic' ), 'id' => 'woolab_icdic_options' );
    $settings[] = array(
        'title'   => __( 'CZ: Validate Business ID in ARES', 'woolab-ic-dic' ),
        'desc'    => __( 'Enable validation of Business ID in Czech database ARES.', 'woolab-ic-dic' ),
        'id'      => 'woolab_icdic_ares_check',
        'default' => 'yes',
        'type'    => 'checkbox',
    );
    $settings[] = array(
        'title'   => __( 'CZ: Validate and autofill based on ARES', 'woolab-ic-dic' ),
        'desc'    => __( 'Enable autofill and validation for Company, VAT number, Address, City, and Postcode fields based on Czech database ARES. Requires checked the option above.', 'woolab-ic-dic' ),
        'id'      => 'woolab_icdic_ares_fill',
        'default' => 'false',
        'type'    => 'checkbox',
    );
    $settings[] = array(
        'title'   => __( 'EU: Validate VAT number in VIES', 'woolab-ic-dic' ),
        'desc'    => $vies_desc,
        'id'      => 'woolab_icdic_vies_check',
        'default' => $vies_check,
        'type'    => 'checkbox',
    );
    $settings[] = array(
        'title'   => __( 'EU: VAT exempt', 'woolab-ic-dic' ),
        'desc'    => __( 'Enable VAT exemption for valid EU VAT numbers', 'woolab-ic-dic' ),
        'id'      => 'woolab_icdic_vat_exempt_switch',
        'default' => 'no',
        'type'    => 'checkbox',
    );
    $settings[] = array(
        'title'   => __( 'Toggle fields visibility', 'woolab-ic-dic' ),
        'desc'    => __( 'Enable toggle switch to show/hide input fields', 'woolab-ic-dic' ),
        'id'      => 'woolab_icdic_toggle_switch',
        'default' => 'no',
        'type'    => 'checkbox',
    );
    $settings[] = array(
        'title'   => __( 'Move Country to top', 'woolab-ic-dic' ),
        'desc'    => __( 'Move Country field above the "Buying as a company" toggle', 'woolab-ic-dic' ),
        'id'      => 'woolab_icdic_country_switch',
        'default' => 'no',
        'type'    => 'checkbox',
    );
    $settings[] = array( 'type' => 'sectionend', 'id' => 'woolab_icdic_options' );

    return $settings;
}
