<?php

/**
 * MFCR INFO ARES API
 * https://ares.gov.cz/stranky/vyvojar-info
 * 
 * ADDITIONAL CREDITS
 * https://github.com/svecon/web-utilities/blob/master/Ares/Ares.php
 * https://webtrh.cz/279860-script-nacitani-dat-ares-jquery
 * http://www.garth.cz/ostatni/ares-ziskani-dat-pomoci-php/
 */

use KybernautIcDic\Logger;

if ( ! function_exists( 'woolab_icdic_ares') ) {

    function woolab_icdic_ares( $ico = '' ) {

        if ( $ico == '' ) {
            return array( 'error' => __('Business ID not set.', 'woolab-ic-dic'));
        }

        // Check format before asking ARES.
        if ( ! is_numeric( $ico ) || strlen( $ico ) != 8) {
            return array( 'error' => __('Business ID must be a number and 8 digits long.', 'woolab-ic-dic'));
        }

        // Serve a cached lookup when available. ARES data changes rarely, and the
        // AJAX autofill endpoint is unauthenticated, so caching both spares the
        // remote registry and prevents the store from being rate-limited by ARES.
        $cache_key = 'woolab_icdic_ares_' . $ico;
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $url = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/' . $ico;
        $response = wp_remote_get( $url );
        $logger = Logger::getInstance();

        if ( ! is_wp_error( $response ) ) {

            $status_code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);

            if ( $status_code === 200 && $data ) {

                $return = array( 'error' => false );
                $return['spolecnost'] = $data->obchodniJmeno ?? '';
                $return['ico'] = $data->ico ?? '';

                // Check VAT registration status before including DIC
                // Only autofill DIC if VAT registration is active
                $stav_dph = $data->seznamRegistraci->stavZdrojeDph ?? null;
                $return['dic'] = ($stav_dph === 'AKTIVNI') ? ($data->dic ?? '') : '';

                $cislo_orientacni = $data->sidlo->cisloOrientacni ?? '';
                $cislo_domovni = $data->sidlo->cisloDomovni ?? '';
                $pismeno_orientacni = $data->sidlo->cisloOrientacniPismeno ?? ''; // TEST
                $cp = ($cislo_orientacni !== "" ? $cislo_domovni . "/".$cislo_orientacni . $pismeno_orientacni : $cislo_domovni);
                $ulice  = $data->sidlo->nazevUlice ?? $data->sidlo->nazevObce ?? '';

                $return['adresa'] = sprintf( '%s %s', $ulice, $cp );
                $return['psc'] = $data->sidlo->psc ?? '';
                $return['mesto'] = $data->sidlo->nazevMestskehoObvodu ?? $data->sidlo->nazevObce ?? '';

                $logger->logInfo('Ares response:');
                $logger->logInfo($body);

            } elseif ( $status_code === 404 ) {                
                $logger->log(sprintf('Entity doesn\'t exist in ARES: %s', $ico));
                $logger->log($response);
                $return = array( 'error' => __('Entity doesn\'t exist in ARES.', 'woolab-ic-dic'));
            } else {
                $logger->log(sprintf('Ares is not responding, used IČO: %s', $ico));
                $logger->log($response);
                $return = array(
					'error'          => __('ARES is not responding.', 'woolab-ic-dic'),
	                'internal_error' => true,
                );
            }

        } else {
            $logger->log(sprintf('An error occured while connecting to ARES: %s', $ico));
            $logger->log($response);
            $return = array(
				'error'          => __('An error occured while connecting to ARES, try it again later.', 'woolab-ic-dic'),
				'internal_error' => true,
            );
        }

        // Cache only definitive answers (a parsed subject or a confirmed 404).
        // Transient failures (network error, ARES down) carry 'internal_error'
        // and must not be cached so the next request can retry.
        if ( empty( $return['internal_error'] ) ) {
            $ttl = apply_filters( 'woolab_icdic_ares_cache_ttl', DAY_IN_SECONDS, $ico, $return );
            if ( $ttl > 0 ) {
                set_transient( $cache_key, $return, $ttl );
            }
        }

        return $return;

    }
}

/**
 * Company name search for the checkout suggestions: up to 10 active subjects as
 * array( 'ico', 'name', 'address' ), an empty list when ARES finds too many to
 * return, or false when ARES is unavailable.
 */
function woolab_icdic_ares_search( $name ) {

    // Unauthenticated and fired while typing: cache to spare ARES.
    $cache_key = 'woolab_icdic_ares_search_' . md5( mb_strtolower( $name ) );
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    $response = wp_remote_post( 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/vyhledat', array(
        'timeout' => 10,
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( array( 'obchodniJmeno' => $name, 'pocet' => 10 ) ),
    ) );
    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    // A short or common name matches more than ARES returns; the shopper types on.
    if ( 400 === $code && 'VYSTUP_PRILIS_MNOHO_VYSLEDKU' === ( $data['subKod'] ?? '' ) ) {
        return array();
    }

    if ( 200 !== $code || ! isset( $data['ekonomickeSubjekty'] ) ) {
        Logger::getInstance()->log( sprintf( 'ARES search is not responding, used name: %s', $name ) );
        Logger::getInstance()->log( $response );
        return false;
    }

    $results = array();
    foreach ( $data['ekonomickeSubjekty'] as $subject ) {
        if ( empty( $subject['ico'] ) || ! empty( $subject['datumZaniku'] ) ) {
            continue;
        }
        $results[] = array(
            'ico'     => $subject['ico'],
            'name'    => $subject['obchodniJmeno'] ?? '',
            'address' => $subject['sidlo']['textovaAdresa'] ?? '',
        );
    }

    set_transient( $cache_key, $results, DAY_IN_SECONDS );

    return $results;
}
