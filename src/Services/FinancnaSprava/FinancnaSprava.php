<?php
/**
 * Finančná správa, the Slovak Financial Administration, as a service: its DIČ and
 * IČ DPH lists imported daily (Importer, Storage).
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Services\FinancnaSprava;

use KybernautIcDic\Services\AbstractService;

/**
 * All four features for Slovakia, on the local SQLite file: company search and
 * lookup, DIČ and IČ DPH. Its import runs only while it is assigned to a feature
 * (Services::uses()).
 */
final class FinancnaSprava extends AbstractService {

	/**
	 * Unique id.
	 */
	public function id(): string {
		return 'financna_sprava';
	}

	/**
	 * Display name, for the messages.
	 */
	public function name(): string {
		return 'Finančná správa';
	}

	/**
	 * Whether the DIČ is in the DIČ list, and under which IČO.
	 *
	 * @param string $dic DIČ.
	 * @return array{state:string,ico:string}
	 */
	public function validate_tax_id( string $dic ): array {
		$row = self::find( 'dic', $dic );

		if ( false === $row ) {
			return array(
				'state' => 'unverifiable',
				'ico'   => '',
			);
		}

		return array(
			'state' => $row ? 'valid' : 'invalid',
			'ico'   => $row ? $row['ico'] : '',
		);
	}

	/**
	 * Whether the IČ DPH is in the IČ DPH list, and under which IČO. Not listed, or
	 * the lists unavailable: 'unlisted', for the next service (VIES) to answer.
	 *
	 * @param string $vat IČ DPH.
	 * @return array{state:string,ico:string,unavailable?:bool}|null Null for other countries' numbers.
	 */
	public function validate_vat_id( string $vat ): ?array {
		if ( 0 !== stripos( $vat, 'SK' ) ) {
			return null;
		}

		$row = self::find( 'dph', $vat );

		if ( $row ) {
			return array(
				'state' => 'valid',
				'ico'   => $row['ico'],
			);
		}

		return array(
			'state'       => 'unlisted',
			'ico'         => '',
			'unavailable' => false === $row,
		);
	}

	/**
	 * Look an IČO up. Same shape as Ares::lookup_company(), plus 'dic_dph'; 'dic' and
	 * 'dic_dph' are null when the subject has none.
	 *
	 * A subject missing from the lists is not necessarily invalid: one founded in the
	 * last few days, or not registered for tax, is not there yet. That comes back
	 * flagged 'unlisted', which the checkout treats as nothing to autofill rather
	 * than a wrong IČO.
	 *
	 * @param string $ico IČO.
	 * @return array<string,mixed>
	 */
	public function lookup_company( string $ico ): array {
		if ( '' === $ico ) {
			return array( 'error' => __( 'Business ID not set.', 'woolab-ic-dic' ) );
		}

		// No checksum here: the lists hold real IČOs that fail the mod-11 check.
		if ( ! preg_match( '/^\d{8}$/', $ico ) ) {
			return array( 'error' => __( 'Business ID must be a number and 8 digits long.', 'woolab-ic-dic' ) );
		}

		$rows = Storage::query( 'SELECT name, street, psc, city FROM s WHERE ico = ?', array( (int) $ico ) );

		if ( false === $rows ) {
			return array(
				'error'          => __( 'Finančná správa lists are not available.', 'woolab-ic-dic' ),
				'internal_error' => true,
			);
		}

		if ( ! $rows ) {
			return array(
				'error'    => __( 'Business ID not found in Finančná správa, fill in the details manually.', 'woolab-ic-dic' ),
				'unlisted' => true,
			);
		}

		list( $name, $street, $psc, $city ) = $rows[0];

		return array(
			'error'      => false,
			'spolecnost' => $name,
			'ico'        => $ico,
			'adresa'     => self::street( (string) $street, (string) $city ),
			'psc'        => str_replace( ' ', '', (string) $psc ),
			'mesto'      => $city,
			'dic'        => self::number_for_ico( 'dic', $ico ),
			'dic_dph'    => self::number_for_ico( 'dph', $ico ),
		);
	}

	/**
	 * Company name search: every word of the query matches the start of a word in
	 * the name, diacritics ignored ("kavia" finds "Kaviareň").
	 *
	 * @param string $name Company name.
	 * @return array<int,array{ico:string,name:string,address:string}>|false
	 */
	public function search_companies( string $name ) {
		preg_match_all( '/[\p{L}\p{N}]+/u', $name, $words );
		if ( ! $words[0] ) {
			return array();
		}

		$match = implode( ' ', array_map( static fn ( $word ) => '"' . $word . '"*', $words[0] ) );

		// Names starting with what was typed first ("alza" → Alza.sk before Ľudmila Alzate).
		$rows = Storage::query( "SELECT s.ico, s.name, s.street, s.psc, s.city FROM f JOIN s ON s.ico = f.rowid WHERE f MATCH ? ORDER BY s.name LIKE ? ESCAPE '\\' DESC, f.rank LIMIT 10", array( $match, addcslashes( trim( $name ), '%_\\' ) . '%' ) );

		if ( false === $rows ) {
			return false;
		}

		return array_map(
			static fn ( $row ) => array(
				'ico'     => str_pad( (string) $row[0], 8, '0', STR_PAD_LEFT ),
				'name'    => (string) $row[1],
				'address' => implode( ', ', array_filter( array( self::street( (string) $row[2], (string) $row[4] ), trim( $row[3] . ' ' . $row[4] ) ) ) ),
			),
			$rows
		);
	}

	/**
	 * Find a DIČ ('dic') or IČ DPH ('dph') in the lists.
	 *
	 * @param string $which  'dic' or 'dph'.
	 * @param string $number With or without the SK prefix and spaces.
	 * @return array{ico:string}|false|null The IČO ('' when unknown) when listed, null when
	 *                                      not, false when the lists are unavailable.
	 */
	public static function find( string $which, string $number ) {
		$digits = (string) preg_replace( '/^SK/i', '', (string) preg_replace( '/\s+/', '', $number ) );

		if ( ! preg_match( '/^\d{1,18}$/', $digits ) ) {
			return Storage::fresh() ? null : false;
		}

		$rows = Storage::query( 'SELECT ico FROM n WHERE kind = ? AND num = ?', array( Storage::LISTS[ $which ]['kind'], (int) $digits ) );

		if ( false === $rows ) {
			return false;
		}

		if ( ! $rows ) {
			return null;
		}

		return array( 'ico' => null === $rows[0][0] ? '' : str_pad( (string) $rows[0][0], 8, '0', STR_PAD_LEFT ) );
	}

	/**
	 * The DIČ ('dic') or IČ DPH ('dph') listed for an IČO: 'SK'-prefixed for IČ DPH,
	 * null when unknown (some rows carry no IČO, new registrations arrive a day
	 * later) or the lists are unavailable.
	 *
	 * @param string $which 'dic' or 'dph'.
	 * @param string $ico   IČO.
	 */
	public static function number_for_ico( string $which, string $ico ): ?string {
		$rows = Storage::query( 'SELECT num FROM n WHERE ico = ? AND kind = ? ORDER BY num LIMIT 1', array( (int) $ico, Storage::LISTS[ $which ]['kind'] ) );

		if ( ! $rows ) {
			return null;
		}

		return ( 'dph' === $which ? 'SK' : '' ) . str_pad( (string) $rows[0][0], 10, '0', STR_PAD_LEFT );
	}

	/**
	 * The lists give villages without street names as the house number alone
	 * ("621"); prefix the municipality, as addresses are written there.
	 *
	 * @param string $street Street and number.
	 * @param string $city   Municipality.
	 */
	public static function street( string $street, string $city ): string {
		return preg_match( '/\p{L}/u', $street ) ? $street : trim( $city . ' ' . $street );
	}
}
