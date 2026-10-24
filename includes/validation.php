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
 *     @type bool        require_sk_ic_and_dic
 *     @type bool        check_dic_dph_match
 *     @type bool        require_ic_for_company Optional. A company must enter its name and a Business ID.
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
 *     @type bool  $check_fail_ignored Whether a VAT/ARES check failure was ignored.
 * }
 */
function woolab_icdic_validate_checkout( array $input ) {

	$country = $input['country'];

	$errors                 = array();
	$vat_check_fail_ignored = false;
	$sk_check               = ! empty( $input['sk_check'] );
	$sk_dic                 = null;

	// Remove white spaces (matches the original preg_replace cleaning).
	$ico     = preg_replace( '/\s+/', '', $input['ic'] );
	$dic     = preg_replace( '/\s+/', '', $input['dic'] );
	$dic_dph = preg_replace( '/\s+/', '', $input['dic_dph'] );

	// COMPANY NAME + BUSINESS ID
	// Every Slovak and Czech business entity has a Business ID, so a company must enter
	// its name and that ID. Opt-in (woolab_icdic_required_ic_for_company). A company is
	// "Buying as a company" checked when the toggle is on; with the toggle off it is a
	// filled-in company name, so only the ID can be missing.
	if ( ! empty( $input['require_ic_for_company'] ) ) {
		$has_company = '' !== trim( (string) $input['company'] );
		$is_company  = ! empty( $input['toggle_enabled'] ) ? ! empty( $input['iscomp'] ) : $has_company;
		if ( $is_company && ! $has_company ) {
			$errors[] = array( 'code' => 'missing_company' );
		}
		if ( $is_company && '' === $ico ) {
			$errors[] = array( 'code' => 'missing_business_id' );
		}
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

			// If Validate in VIES
			// Slovak DIC cannot (and shouldn't) be validated in VIES
			if ( $input['vies_check'] && $country != 'SK' ) {

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
				} elseif ( $state === 'invalid' ) {
					$errors[] = array( 'code' => 'invalid_vat' );
				} elseif ( $state === 'unverifiable' ) {
					if ( $input['ignore_check_fail'] ) {
						$vat_check_fail_ignored = true;
					} else {
						$errors[] = array( 'code' => 'vat_unverifiable' );
					}
				}

			// Validate CZ and SK mathematicaly
			} else {
				if ( $country == 'CZ' ) {
					if ( ! ( woolab_icdic_verify_rc( substr( $dic, 2 ) ) || woolab_icdic_verify_dic( substr( $dic, 2 ) ) ) || substr( $dic, 0, 2 ) != 'CZ' ) {
						$errors[] = array( 'code' => 'invalid_dic_cz' );
					}
				} elseif ( $country == 'SK' ) {
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

		}

	}
	// DIC is mandatory in Slovakia, this is not a VAT number
	else {
		// if IC is set, DIC must be set as well in Slovakia
		if ( $input['require_sk_ic_and_dic'] && ! empty( $input['ic'] ) && empty( $input['dic'] ) && $country == 'SK' ) {
			$errors[] = array( 'code' => 'invalid_tax_id_sk' );
		}
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
		'check_fail_ignored' => $vat_check_fail_ignored,
	);
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
