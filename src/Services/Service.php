<?php
/**
 * A service the checks and the checkout lookups ask: a company register, a tax or
 * VAT register.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Services;

/**
 * Each method is one feature; Services assigns services to features per billing
 * country (the woolab_icdic_services filter). A service answers null for a feature
 * it does not support (AbstractService does that for all four).
 */
interface Service {

	/**
	 * Unique id, e.g. 'ares', 'financna_sprava', 'vies'.
	 */
	public function id(): string;

	/**
	 * Display name, for the messages: 'ARES', 'Finančná správa', 'VIES'.
	 *
	 * @since 2.0.0
	 */
	public function name(): string;

	/**
	 * Companies by name, for the checkout suggestions.
	 *
	 * @param string $name Three letters or more.
	 * @return array<int,array{ico:string,name:string,address:string}>|false|null Up to 10, an
	 *         empty list when there are too many to list, false when the service is down,
	 *         null when it does not search.
	 */
	public function search_companies( string $name );

	/**
	 * The company with this IČO, for the autofill and the IČO check.
	 *
	 * @param string $ico The number as entered.
	 * @return array<string,mixed>|null On success 'error' => false and 'spolecnost', 'ico', 'dic',
	 *         'adresa', 'psc', 'mesto'; otherwise 'error' with the message, plus
	 *         'internal_error' => true when the service could not answer, or
	 *         'unlisted' => true when the number is fine but not listed. Null when it does
	 *         not look companies up.
	 */
	public function lookup_company( string $ico ): ?array;

	/**
	 * Whether a tax number (DIČ) is registered, and to which IČO.
	 *
	 * @param string $dic DIČ, whitespace stripped.
	 * @return array{state:string,ico:string}|null State 'valid', 'invalid' or 'unverifiable';
	 *         the IČO it belongs to, '' when unknown. Null when it does not validate DIČ.
	 */
	public function validate_tax_id( string $dic ): ?array;

	/**
	 * Whether a VAT number is valid, and to which IČO.
	 *
	 * @param string $vat VAT number with its country prefix (Greece: EL), no spaces.
	 * @return array{state:string,ico:string}|null State 'valid', 'bad_format', 'invalid',
	 *         'unverifiable', or 'unlisted' when this service does not know the number and
	 *         the next one assigned should be asked. Null when it does not validate VAT
	 *         numbers.
	 */
	public function validate_vat_id( string $vat ): ?array;
}
