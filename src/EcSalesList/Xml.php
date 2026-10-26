<?php
/**
 * The official EC Sales List files: the Slovak súhrnný výkaz (SVDPHv20, for the
 * Finančná správa eForm) and the Czech souhrnné hlášení (DPHSHV, for EPO).
 *
 * @package KybernautIcDic
 * @since 2.0.0
 */

namespace KybernautIcDic\EcSalesList;

use KybernautIcDic\Settings\Options;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument's own names.

/**
 * The shop's details (shop()), what a file still lacks (missing()), and the files:
 * a regular statement (riadny, R) only, no corrections, no call-off stock.
 */
final class Xml {

	/**
	 * The DPHSHV version the file follows (dphshv_epo2.xsd).
	 */
	private const DPHSHV_VERSION = '02.01.05';

	/**
	 * SK rows per page (strana): the form's 12 lines.
	 */
	public const SK_PAGE = 12;

	/**
	 * The shop's details: the EC Sales List settings, the VAT ID from "Your VAT ID",
	 * the address from WooCommerce's store address.
	 *
	 * @return array<string,string>
	 */
	public static function shop(): array {
		$settings = Options::all();
		$wc       = WC()->countries;
		$address  = trim( $wc->get_base_address() );

		// "Hlavná 12/3": the street, then its number.
		if ( preg_match( '/^(.*?)\s+(\d[\w\/-]*)$/u', $address, $m ) ) {
			list( , $street, $number ) = $m;
		} else {
			list( $street, $number ) = array( $address, '' );
		}

		return array(
			'vat'      => strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $settings['shop_vat_id'] ) ),
			'company'  => $settings['ec_company'],
			'person'   => $settings['ec_person'],
			'role'     => $settings['ec_person_role'],
			'phone'    => $settings['ec_phone'],
			'ufo'      => $settings['ec_cz_ufo'],
			'pracufo'  => $settings['ec_cz_pracufo'],
			'type'     => 'F' === $settings['ec_cz_type'] ? 'F' : 'P',
			'street'   => $street,
			'number'   => $number,
			'postcode' => str_replace( ' ', '', $wc->get_base_postcode() ),
			'city'     => $wc->get_base_city(),
			'country'  => $wc->get_base_country(),
		);
	}

	/**
	 * What a file lacks, for people; empty when it can be made.
	 *
	 * @param string               $format 'sk' or 'cz'.
	 * @param array<string,string> $shop   shop().
	 * @return array<int,string>
	 */
	public static function missing( string $format, array $shop ): array {
		$missing = array();

		if ( 'sk' === $format ) {
			if ( ! preg_match( '/^SK\d{10}$/', $shop['vat'] ) ) {
				$missing[] = __( 'Your VAT ID in Validation (SK and 10 digits)', 'woolab-ic-dic' );
			}
			if ( '' === $shop['company'] ) {
				$missing[] = __( 'Company name', 'woolab-ic-dic' );
			}
			if ( '' === $shop['person'] ) {
				$missing[] = __( 'Authorised person', 'woolab-ic-dic' );
			}

			return $missing;
		}

		if ( ! preg_match( '/^CZ\d{8,10}$/', $shop['vat'] ) ) {
			$missing[] = __( 'Your VAT ID in Validation (CZ and 8 to 10 digits)', 'woolab-ic-dic' );
		}
		if ( ! preg_match( '/^\d{3}$/', $shop['ufo'] ) ) {
			$missing[] = __( 'Tax office code', 'woolab-ic-dic' );
		}
		if ( 'P' === $shop['type'] && '' === $shop['company'] ) {
			$missing[] = __( 'Company name', 'woolab-ic-dic' );
		}
		if ( '' === $shop['person'] ) {
			$missing[] = __( 'Authorised person', 'woolab-ic-dic' );
		}
		if ( 'P' === $shop['type'] && '' === $shop['role'] ) {
			$missing[] = __( 'Position of the authorised person', 'woolab-ic-dic' );
		}

		return $missing;
	}

	/**
	 * The Slovak súhrnný výkaz: 12 rows per page, padded with empty ones, as the
	 * form; part II (call-off stock) empty.
	 *
	 * @param array<int,array{country:string,vat:string,kind:string,value:float}> $rows   Report::rows() for 'sk'.
	 * @param array<string,string>                                                $shop   shop().
	 * @param string                                                              $type   'month' or 'quarter'.
	 * @param int                                                                 $number Month or quarter.
	 * @param int                                                                 $year   Year.
	 * @param string                                                              $date   The statement's date, Y-m-d.
	 */
	public static function sk( array $rows, array $shop, string $type, int $number, int $year, string $date ): string {
		$doc  = new \DOMDocument( '1.0', 'UTF-8' );
		$root = $doc->appendChild( $doc->createElement( 'dokument' ) );
		$head = self::el( $root, 'hlavicka' );

		self::children(
			self::el( $head, 'identifikacneCislo' ),
			array(
				'kodStatu' => 'SK',
				'dic'      => substr( $shop['vat'], 2 ),
			)
		);
		self::el( $head, 'danovyUrad' );
		self::children(
			self::el( $head, 'druhSV' ),
			array(
				'riadny'    => 'true',
				'opravny'   => 'false',
				'dodatocny' => 'false',
			)
		);
		self::children(
			self::el( $head, 'obdobie' ),
			array(
				'mesiac'   => 'month' === $type ? sprintf( '%02d', $number ) : '',
				'stvrtrok' => 'quarter' === $type ? (string) $number : '',
				'rok'      => (string) $year,
			)
		);

		$name = self::el( $head, 'obchodneMeno' );
		foreach ( array( $shop['company'], '', '', '' ) as $line ) {
			self::el( $name, 'riadok', $line );
		}

		self::children(
			self::el( $head, 'adresa' ),
			array(
				'ulica' => $shop['street'],
				'cislo' => $shop['number'],
				'psc'   => $shop['postcode'],
				'obec'  => $shop['city'],
				'tel'   => $shop['phone'],
				'email' => '',
			)
		);

		$total = array_sum( array_column( $rows, 'value' ) );
		self::el( $head, 'celkovaHodnota', $rows ? self::number( $total ) : '' );
		self::el( $head, 'konatel', $shop['person'] );

		list( $y, $m, $d ) = explode( '-', $date );
		self::children(
			self::el( $head, 'datumVyhlasenia' ),
			array(
				'den'    => $d,
				'mesiac' => $m,
				'rok'    => $y,
			)
		);

		$body = self::el( $root, 'telo' );
		foreach ( array_chunk( $rows, self::SK_PAGE ) as $page_rows ) {
			$page = self::el( $body, 'strana' );

			for ( $i = 0; $i < self::SK_PAGE; $i++ ) {
				$row = $page_rows[ $i ] ?? null;
				self::children(
					self::el( $page, 'zaznam' ),
					array(
						'kodStatu' => $row ? $row['country'] : '',
						'idCislo'  => $row ? $row['vat'] : '',
						'hodnota'  => $row ? self::number( $row['value'] ) : '',
						'kod'      => $row ? Report::CODES['sk'][ $row['kind'] ] : '',
					)
				);
			}

			for ( $i = 0; $i < self::SK_PAGE; $i++ ) {
				self::children( self::el( $page, 'zaznamCast2' ), array_fill_keys( array( 'kodStatu', 'idCislo', 'idCislo2', 'vratenieTovaru', 'oprava' ), '' ) );
			}
		}

		$doc->formatOutput = true;

		return (string) $doc->saveXML();
	}

	/**
	 * The Czech souhrnné hlášení, regular (R).
	 *
	 * @param array<int,array{country:string,vat:string,kind:string,value:float,orders:int}> $rows   Report::rows() for 'cz'.
	 * @param array<string,string>                                                           $shop   shop().
	 * @param string                                                                         $type   'month' or 'quarter'.
	 * @param int                                                                            $number Month or quarter.
	 * @param int                                                                            $year   Year.
	 */
	public static function cz( array $rows, array $shop, string $type, int $number, int $year ): string {
		$doc  = new \DOMDocument( '1.0', 'UTF-8' );
		$root = self::el(
			$doc,
			'Pisemnost',
			'',
			array(
				'nazevSW' => 'Kybernaut IC DIC',
				'verzeSW' => WOOLAB_IC_DIC_VERSION,
			)
		);
		$form = self::el( $root, 'DPHSHV', '', array( 'verzePis' => self::DPHSHV_VERSION ) );

		self::el(
			$form,
			'VetaD',
			'',
			array(
				'k_uladis'                              => 'DPH',
				'dokument'                              => 'SHV',
				'rok'                                   => (string) $year,
				'quarter' === $type ? 'ctvrt' : 'mesic' => (string) $number,
				'shvies_forma'                          => 'R',
			)
		);

		// "12/3": číslo popisné 12, číslo orientační 3.
		list( $c_pop, $c_orient ) = array_pad( explode( '/', $shop['number'], 2 ), 2, '' );
		list( $last, $first )     = self::name( $shop['person'] );

		$taxpayer = 'F' === $shop['type']
			? array(
				'prijmeni' => mb_substr( $last, 0, 36 ),
				'jmeno'    => mb_substr( $first, 0, 30 ),
			)
			: array(
				'zkrobchjm'     => mb_substr( $shop['company'], 0, 255 ),
				'opr_prijmeni'  => mb_substr( $last, 0, 36 ),
				'opr_jmeno'     => mb_substr( $first, 0, 20 ),
				'opr_postaveni' => mb_substr( $shop['role'], 0, 40 ),
			);

		self::el(
			$form,
			'VetaP',
			'',
			array(
				'c_ufo'     => $shop['ufo'],
				'c_pracufo' => $shop['pracufo'],
				'dic'       => substr( $shop['vat'], 2 ),
				'typ_ds'    => $shop['type'],
			) + $taxpayer + array(
				'naz_obce'      => mb_substr( $shop['city'], 0, 48 ),
				'ulice'         => mb_substr( $shop['street'], 0, 38 ),
				'c_pop'         => preg_match( '/^\d{1,6}$/', $c_pop ) ? $c_pop : '',
				'c_orient'      => mb_substr( $c_orient, 0, 4 ),
				'psc'           => mb_substr( $shop['postcode'], 0, 10 ),
				'stat'          => 'CZ' === $shop['country'] ? 'ČESKÁ REPUBLIKA' : '',
				'sest_prijmeni' => mb_substr( $last, 0, 36 ),
				'sest_jmeno'    => mb_substr( $first, 0, 20 ),
				'sest_telef'    => self::cz_phone( $shop['phone'] ),
			)
		);

		foreach ( $rows as $row ) {
			self::el(
				$form,
				'VetaR',
				'',
				array(
					'k_stat'      => $row['country'],
					'c_vat'       => $row['vat'],
					'k_pln_eu'    => Report::CODES['cz'][ $row['kind'] ],
					'pln_pocet'   => (string) $row['orders'],
					'pln_hodnota' => self::number( $row['value'] ),
				)
			);
		}

		$doc->formatOutput = true;

		return (string) $doc->saveXML();
	}

	/**
	 * The phone as DPHSHV sest_telef takes it: without spaces, at most 14
	 * characters; '' when longer (never cut).
	 *
	 * @param string $phone Phone.
	 */
	public static function cz_phone( string $phone ): string {
		$phone = (string) preg_replace( '/\s+/u', '', $phone );

		return strlen( $phone ) <= 14 ? $phone : '';
	}

	/**
	 * A person's surname and first name, from "First Last".
	 *
	 * @param string $name Full name.
	 * @return array{0:string,1:string}
	 */
	public static function name( string $name ): array {
		$parts = preg_split( '/\s+/u', trim( $name ) );
		$parts = is_array( $parts ) ? $parts : array();
		$last  = (string) array_pop( $parts );

		return array( $last, implode( ' ', $parts ) );
	}

	/**
	 * A whole number, as the files list values.
	 *
	 * @param float $value Rounded value.
	 */
	private static function number( float $value ): string {
		return (string) (int) $value;
	}

	/**
	 * Append an element, with its text and the attributes that are not empty.
	 *
	 * @param \DOMNode             $node       Parent node.
	 * @param string               $name       Element name.
	 * @param string               $text       Text.
	 * @param array<string,string> $attributes Attributes.
	 */
	private static function el( \DOMNode $node, string $name, string $text = '', array $attributes = array() ): \DOMElement {
		$doc     = $node instanceof \DOMDocument ? $node : $node->ownerDocument;
		$element = $doc->createElement( $name );

		// textContent escapes, createElement()'s value does not. No empty text node:
		// it would stop the indentation.
		if ( '' !== $text ) {
			$element->textContent = $text;
		}
		foreach ( array_filter( $attributes, static fn ( $value ) => '' !== $value ) as $attribute => $value ) {
			$element->setAttribute( $attribute, $value );
		}

		$node->appendChild( $element );

		return $element;
	}

	/**
	 * Append text elements.
	 *
	 * @param \DOMElement          $node     Parent element.
	 * @param array<string,string> $children Name => text.
	 */
	private static function children( \DOMElement $node, array $children ): void {
		foreach ( $children as $name => $text ) {
			self::el( $node, $name, $text );
		}
	}
}
