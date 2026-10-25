<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Pure validation of the company-identifier checkout fields.
 *
 * No WordPress, no WooCommerce, no network, no i18n. The hook adapter in
 * filters-actions.php resolves all settings/filters/options into the flags,
 * builds the two network closures, and maps the returned error codes back to
 * the exact original wc_add_notice() strings.
 *
 * @param array $input {
 *     @type string      country            Billing country code (wc_clean'd).
 *     @type string      ic                 billing_ic, wc_clean'd (whitespace stripped here).
 *     @type string      dic                billing_dic, wc_clean'd (whitespace stripped here for VAT use).
 *     @type bool        dic_present        Whether billing_dic was present in the request (isset).
 *     @type string      dic_dph            billing_dic_dph, wc_clean'd (whitespace stripped here).
 *     @type string      company            billing_company, wc_clean'd.
 *     @type string      postcode           billing_postcode, wc_clean'd.
 *     @type string      city               billing_city, wc_clean'd.
 *     @type string      address_1          billing_address_1, wc_clean'd.
 *     @type bool        ship_to_different  Whether ship-to-different-address is checked.
 *     @type string|null shipping_country   Shipping country code, or null when not present.
 *     @type bool        ares_check
 *     @type bool        ares_fill
 *     @type bool        vies_check
 *     @type bool        sk_check           Optional. Check SK numbers in the Finančná správa lists.
 *     @type bool        sk_fill            Optional. Compare the SK company and address with those lists.
 *     @type bool        ignore_check_fail
 *     @type bool        check_country_match
 *     @type bool        require_dic_for_firms A company from this country needs a DIČ (woolab_icdic_dic_required()).
 *     @type bool        check_dic_dph_match
 *     @type bool        require_company    Optional. A company must enter its name ("Required" mode).
 *     @type bool        require_ic         Optional. A company must enter a Business ID in this country ("Required" mode).
 *     @type bool        toggle_enabled     Optional. Whether the "Buying as a company" toggle is on.
 *     @type bool        iscomp             Optional. Whether "Buying as a company" is checked.
 *     @type bool        country_in_eu      Resolved from ibericode Countries::isCountryCodeInEU().
 *     @type callable    verify_vat         fn(string $vat): string 'valid'|'bad_format'|'invalid'|'unverifiable'
 *     @type callable    lookup_ares        fn(string $ico): array   raw woolab_icdic_ares() result (or falsy)
 *     @type callable    lookup_sk_ico      Optional. fn(string $ico): array, woolab_icdic_sk_lookup() — same shape as lookup_ares.
 *     @type callable    lookup_sk          Optional. fn(string $list, string $number): array|null|false, woolab_icdic_sk_find().
 * }
 *
 * @return array {
 *     @type array $errors             List of ['code' => string, 'data' => array]; 'data' omitted when unused.
 *     @type array $notices            Same shape, for what does not stop the order ('vat_not_registered').
 *     @type bool  $check_fail_ignored Whether a VAT/ARES check failure was ignored.
 * }
 */
function woolab_icdic_validate_checkout( array $input ) {

	$country = $input['country'];

	$errors                 = array();
	$notices                = array();
	$vat_check_fail_ignored = false;
	$sk_check               = ! empty( $input['sk_check'] );
	$sk_dic                 = null;

	// Remove white spaces (matches the original preg_replace cleaning).
	$ico     = preg_replace( '/\s+/', '', $input['ic'] );
	$dic     = preg_replace( '/\s+/', '', $input['dic'] );
	$dic_dph = preg_replace( '/\s+/', '', $input['dic_dph'] );

	// COMPANY NAME + BUSINESS ID
	// In the "Required" mode a company must enter its name, and its Business ID where
	// every company has one. A company is "Buying as a company" checked when the
	// toggle is on; with the toggle off it is a filled-in company name, so only the
	// IDs can be missing.
	$has_company = '' !== trim( (string) $input['company'] );
	$is_company  = ! empty( $input['require_company'] ) && ( ! empty( $input['toggle_enabled'] ) ? ! empty( $input['iscomp'] ) : $has_company );
	if ( $is_company && ! $has_company ) {
		$errors[] = array( 'code' => 'missing_company' );
	}
	if ( $is_company && ! empty( $input['require_ic'] ) && '' === $ico ) {
		$errors[] = array( 'code' => 'missing_business_id' );
	}

	if ( $input['ic'] ) {

		// CZ is checked in ARES, SK in the Slovak lists. Both lookups return the same shape.
		$registry = null;
		if ( $country == 'CZ' && $input['ares_check'] ) {
			$registry = array( 'lookup' => $input['lookup_ares'], 'fill' => $input['ares_fill'], 'mismatch' => 'ares_mismatch' );
		} elseif ( $country == 'SK' && $sk_check ) {
			$registry = array( 'lookup' => $input['lookup_sk_ico'], 'fill' => ! empty( $input['sk_fill'] ), 'mismatch' => 'sk_mismatch' );
		}

		if ( $registry ) {

			$ares = $registry['lookup']( $ico );
			if ( $ares ) {
				if ( ! empty( $ares['unlisted'] ) ) {
					// Not in the Slovak lists yet (new, or not registered for tax): the
					// lookup checked the format, there is nothing to compare.
				} elseif ( $ares['error'] ) {
					$is_internal_error = ( ! empty( $ares['internal_error'] ) );

					if ( $is_internal_error && $input['ignore_check_fail'] ) {
						$vat_check_fail_ignored = true;
					} else {
						$errors[] = array( 'code' => 'invalid_business_id', 'data' => array( 'ares_message' => $ares['error'] ) );
					}
				} elseif ( $registry['fill'] ) {
					$missing_fields = array();
					// A null DIČ means the Slovak lists are unavailable, not that there is none.
					if ( $input['dic_present'] && $ares['dic'] !== null && $input['dic'] != $ares['dic'] ) {
						$missing_fields[] = 'tax_id';
					}
					if ( $input['company'] != $ares['spolecnost'] ) {
						$missing_fields[] = 'company';
					}
					if ( str_replace( ' ', '', $input['postcode'] ) != str_replace( ' ', '', $ares['psc'] ) ) {
						$missing_fields[] = 'postcode';
					}
					if ( $input['city'] != $ares['mesto'] ) {
						$missing_fields[] = 'city';
					}
					if ( $input['address_1'] != $ares['adresa'] ) {
						$missing_fields[] = 'address';
					}
					if ( $missing_fields ) {
						$errors[] = array( 'code' => $registry['mismatch'], 'data' => array( 'fields' => $missing_fields ) );
					}
				}
			} else {
				if ( $input['ignore_check_fail'] ) {
					$vat_check_fail_ignored = true;
				} else {
					$errors[] = array( 'code' => 'ares_unexpected' );
				}
			}

		// Registry check disabled
		} elseif ( $country == 'CZ' ) {
			if ( ! woolab_icdic_verify_ic( $ico ) ) {
				$errors[] = array( 'code' => 'invalid_business_id' );
			}
		} elseif ( $country == 'SK' ) {
			if ( $ico ) {
				if ( ! woolab_icdic_verify_ic( $ico ) ) {
					$errors[] = array( 'code' => 'invalid_business_id' );
				}
			}
		}

	}

	// VAT / DIC
	if ( $input['dic'] ) {

		// Check if in EU
		if ( $input['country_in_eu'] ) {

			if ( woolab_icdic_domestic_tax_number( $country, $dic ) ) {
				// A Polish or Hungarian tax number without its country code: the
				// business enters it as a taxpayer, not as a VAT payer. Nothing to ask
				// VIES, the order is placed with VAT.
				$notices[] = array( 'code' => 'tax_number_only' );
			} elseif ( $input['vies_check'] && 'SK' !== $country ) {
				// Validate in VIES. The Slovak DIČ cannot (and shouldn't) be.

				// Match VAT country prefix and country code.
				if ( $input['check_country_match'] && woolab_icdic_get_vat_number_country_code( $dic ) !== $country ) {
					$errors[] = array( 'code' => 'vat_country_mismatch_billing' );
				}

				// Match VAT country prefix and shipping country code.
				if ( $input['check_country_match'] && $input['ship_to_different'] && $input['shipping_country'] !== null && woolab_icdic_get_vat_number_country_code( $dic ) !== $input['shipping_country'] ) {
					$errors[] = array( 'code' => 'vat_country_mismatch_shipping' );
				}

				$state = $input['verify_vat']( $dic );

				if ( $state === 'bad_format' ) {
					// Original emits BOTH notices: validateVatNumberFormat() false, then
					// validateVatNumber() short-circuits to false (no VIES call, no exception).
					$errors[] = array( 'code' => 'vat_format' );
					$errors[] = array( 'code' => 'invalid_vat' );
				} elseif ( 'invalid' === $state && substr( $dic, 0, 2 ) === $country && woolab_icdic_tax_number_valid( $dic ) ) {
					// Not an error: every Czech, Polish and Hungarian business has a tax
					// number, VIES lists only the VAT payers. The order goes through as a
					// company's, with VAT.
					$notices[] = array( 'code' => 'vat_not_registered' );
				} elseif ( 'invalid' === $state ) {
					$errors[] = array( 'code' => 'invalid_vat' );
				} elseif ( $state === 'unverifiable' ) {
					if ( $input['ignore_check_fail'] ) {
						$vat_check_fail_ignored = true;
					} else {
						$errors[] = array( 'code' => 'vat_unverifiable' );
					}
				}
			} elseif ( 'CZ' === $country ) {
				// Without VIES, CZ and SK are validated mathematically.
				if ( ! woolab_icdic_cz_dic_valid( $dic ) ) {
					$errors[] = array( 'code' => 'invalid_dic_cz' );
				}
			} elseif ( 'SK' === $country ) {
				if ( ! woolab_icdic_verify_dic_sk( $dic ) ) {
					$errors[] = array( 'code' => 'invalid_tax_id_sk' );
				} elseif ( $sk_check ) {
					$sk_dic = $input['lookup_sk']( 'dic', $dic );
					if ( false === $sk_dic ) {
						if ( $input['ignore_check_fail'] ) {
							$vat_check_fail_ignored = true;
						} else {
							$errors[] = array( 'code' => 'sk_unverifiable' );
						}
					} elseif ( null === $sk_dic ) {
						$errors[] = array( 'code' => 'invalid_tax_id_sk' );
					} elseif ( woolab_icdic_sk_ico_differs( $sk_dic, $ico ) ) {
						$errors[] = array( 'code' => 'dic_ico_mismatch' );
					}
				}
			}

		}

	} elseif ( ! empty( $input['require_dic_for_firms'] ) && ( $is_company || ! empty( $input['ic'] ) ) ) {
		// Opt-in, with an IČO and for a company in the "Required" mode: non-profit
		// organisations may have an IČO but no DIČ.
		$errors[] = array( 'code' => 'SK' === $country ? 'invalid_tax_id_sk' : 'missing_dic' );
	}

	// IC DPH / DIC DPH
	if ( $input['dic_dph'] && $country == 'SK' ) {

		// Match VAT country prefix and country code.
		if ( $input['check_country_match'] && woolab_icdic_get_vat_number_country_code( $dic_dph ) !== $country ) {
			$errors[] = array( 'code' => 'vat_country_mismatch_billing' );
		}

		// Verify IC DPH
		// If Validate in VIES. With sk_check, verify_vat looks in the Slovak list first
		// and asks VIES only if enabled (woolab_icdic_make_vies_verifier()).
		if ( $input['vies_check'] || $sk_check ) {

			$state = $input['verify_vat']( $dic_dph );

			if ( $state === 'unverifiable' ) {
				if ( $input['ignore_check_fail'] ) {
					$vat_check_fail_ignored = true;
				} else {
					$errors[] = array( 'code' => 'vat_unverifiable' );
				}
			} elseif ( $state !== 'valid' ) {
				// Original only calls validateVatNumber() here (no separate format
				// notice), so both 'bad_format' and 'invalid' yield a single notice.
				$errors[] = array( 'code' => 'invalid_vat_dph' );
			}

		} else {

			if ( ! woolab_icdic_verify_dic_dph_sk( $dic_dph ) ) {
				$errors[] = array( 'code' => 'invalid_vat_dph' );
			}

		}

		$sk_dph = $sk_check ? $input['lookup_sk']( 'dph', $dic_dph ) : null;

		// Same opt-out as the "SK" + DIČ rule: a VAT group's IČ DPH is listed under
		// one member's IČO only.
		if ( $input['check_dic_dph_match'] && $sk_dph && woolab_icdic_sk_ico_differs( $sk_dph, $ico ) ) {
			$errors[] = array( 'code' => 'dic_dph_mismatch' );

		// IC DPH has to match to Tax ID number without SK
		} elseif ( $input['check_dic_dph_match'] && $dic_dph && $dic ) {
			// Unless the lists tie both to the same IČO: VAT groups and some
			// non-profits have an IČ DPH that is not "SK" + DIČ.
			$linked = $sk_dph && $sk_dic && '' !== $sk_dph['ico'] && $sk_dph['ico'] === $sk_dic['ico'];
			if ( $dic != substr( $dic_dph, 2 ) && ! $linked ) {
				$errors[] = array( 'code' => 'dic_dph_mismatch' );
			}
		}
	}

	return array(
		'errors'             => $errors,
		'notices'            => $notices,
		'check_fail_ignored' => $vat_check_fail_ignored,
	);
}

/**
 * Whether a Czech DIČ is well-formed: "CZ" and an IČO or a birth number.
 *
 * @param string $dic Whitespace stripped.
 * @return bool
 */
function woolab_icdic_cz_dic_valid( $dic ) {
	return 'CZ' === substr( $dic, 0, 2 ) && ( woolab_icdic_verify_rc( substr( $dic, 2 ) ) || woolab_icdic_verify_dic( substr( $dic, 2 ) ) );
}

/**
 * Whether a Polish NIP or Hungarian adószám is entered without its country code,
 * as a business does that is a taxpayer but no VAT payer. Dashes are fine
 * (526-025-09-95); a full adószám (12892312-2-41) is checked by its first 8 digits.
 *
 * @param string $country Billing country.
 * @param string $number  Whitespace stripped.
 * @return bool
 */
function woolab_icdic_domestic_tax_number( $country, $number ) {
	if ( ! in_array( $country, array( 'PL', 'HU' ), true ) || ! preg_match( '/^[0-9-]+$/', $number ) ) {
		return false;
	}

	$digits = str_replace( '-', '', $number );

	if ( 'HU' === $country && 11 === strlen( $digits ) ) {
		$digits = substr( $digits, 0, 8 );
	}

	return woolab_icdic_tax_number_valid( $country . $digits );
}

/**
 * Whether a VAT number is the well-formed tax number of a country where every
 * business has one in that form, VAT payer or not: the Czech DIČ, the Polish NIP,
 * the Hungarian adószám (its first 8 digits). VIES lists only the VAT payers.
 *
 * @param string $vat Country prefix and number, whitespace stripped.
 * @return bool
 */
function woolab_icdic_tax_number_valid( $vat ) {
	$prefix = substr( $vat, 0, 2 );
	$number = substr( $vat, 2 );

	if ( 'CZ' === $prefix ) {
		return woolab_icdic_cz_dic_valid( $vat );
	}

	// Weighted sums: modulo 11 is the NIP's last digit, 10 minus modulo 10 the adószám's.
	$checks = array(
		'PL' => array( 10, array( 6, 5, 7, 2, 3, 4, 5, 6, 7 ) ),
		'HU' => array( 8, array( 9, 7, 3, 1, 9, 7, 3 ) ),
	);

	if ( ! isset( $checks[ $prefix ] ) || ! preg_match( '/^\d{' . $checks[ $prefix ][0] . '}$/', $number ) ) {
		return false;
	}

	$sum = 0;
	foreach ( $checks[ $prefix ][1] as $i => $weight ) {
		$sum += $weight * (int) $number[ $i ];
	}

	$check = 'PL' === $prefix ? $sum % 11 : ( 10 - $sum % 10 ) % 10;

	return (int) substr( $number, -1 ) === $check;
}

/**
 * Whether a Slovak list row belongs to another IČO than the one entered. Rows
 * without an IČO, or no IČO entered, never count as a mismatch.
 *
 * @param array  $row ['ico' => string] from woolab_icdic_sk_find().
 * @param string $ico Entered Business ID, whitespace stripped.
 * @return bool
 */
function woolab_icdic_sk_ico_differs( array $row, $ico ) {
	return '' !== $row['ico'] && '' !== $ico && $row['ico'] !== str_pad( $ico, 8, '0', STR_PAD_LEFT );
}

/**
 * Pure VAT-exemption decision from a four-state VIES result.
 *
 * Mirrors the original exemption body verbatim: a valid VAT number is exempt; a
 * VIES outage (ViesException, surfaced as 'unverifiable') falls back to the
 * ignore-check-fail setting; everything else (bad format, format-ok-but-invalid)
 * is not exempt. Shared by both VAT-exempt hooks.
 *
 * @param string $state             'valid'|'bad_format'|'invalid'|'unverifiable' from verify_vat.
 * @param bool   $ignore_check_fail Whether a VIES outage should still grant exemption.
 * @return bool
 */
function woolab_icdic_vat_exempt_from_state( $state, $ignore_check_fail ) {
	if ( $state === 'valid' ) {
		return true;
	}
	if ( $state === 'unverifiable' ) {
		return (bool) $ignore_check_fail;
	}
	return false;
}

/**
 * Pure selection of which company field carries the VIES-checkable VAT number.
 *
 * Slovakia uses the separate DIČ DPH (VAT registration) field; every other
 * country uses the DIČ field. This rule is shared by the checkout validation
 * and both VAT-exempt hooks.
 *
 * @param string $country Billing country code.
 * @param string $dic     DIČ value.
 * @param string $dic_dph DIČ DPH value.
 * @return string
 */
function woolab_icdic_select_vat_number( $country, $dic, $dic_dph ) {
	return $country === 'SK' ? $dic_dph : $dic;
}
