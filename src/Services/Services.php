<?php
/**
 * Which service answers which feature, per billing country.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Services;

use KybernautIcDic\Numbers\Checksum;
use KybernautIcDic\Services\Ares\Ares;
use KybernautIcDic\Services\FinancnaSprava\FinancnaSprava;
use KybernautIcDic\Services\Vies\Vies;
use KybernautIcDic\Settings\Flags;

/**
 * The map: country => feature => services, asked in order. '*' is every other
 * country. A store replaces a service with the woolab_icdic_services filter.
 *
 * For example, all of Slovakia to a register of your own:
 *
 *     add_filter( 'woolab_icdic_services', function ( array $map ) {
 *         $mine = new My\SlovakRegister();   // a KybernautIcDic\Services\Service
 *         foreach ( array( 'company_search', 'company_lookup', 'tax_id', 'vat_id' ) as $feature ) {
 *             $map['SK'][ $feature ] = array( $mine );
 *         }
 *         return $map;   // Finančná správa is no longer used: its import stops.
 *     } );
 */
final class Services {

	/**
	 * The features: company name search, company by IČO, DIČ validation, VAT ID
	 * validation (the Service methods).
	 */
	public const FEATURES = array( 'company_search', 'company_lookup', 'tax_id', 'vat_id' );

	/**
	 * The map in effect.
	 *
	 * @return array<string,array<string,array<int,Service>>>
	 */
	public static function map(): array {
		$map  = array();
		$ares = new Ares();
		$fs   = new FinancnaSprava();
		$vies = new Vies();

		if ( Flags::ares_check() ) {
			$map['CZ']['company_lookup'] = array( $ares );
			if ( Flags::ares_fill() ) {
				$map['CZ']['company_search'] = array( $ares );
			}
		}

		if ( Flags::sk_check() ) {
			$map['SK']['company_lookup'] = array( $fs );
			$map['SK']['tax_id']         = array( $fs );
			// The lists first; VIES for what they do not have yet.
			$map['SK']['vat_id'] = Flags::vies_check() ? array( $fs, $vies ) : array( $fs );
			if ( Flags::sk_fill() ) {
				$map['SK']['company_search'] = array( $fs );
			}
		}

		if ( Flags::vies_check() ) {
			$map['*']['vat_id'] = array( $vies );
		}

		/**
		 * Which services answer which feature: country ('*' for every other) =>
		 * feature ('company_search', 'company_lookup', 'tax_id', 'vat_id') => services
		 * asked in order. The defaults follow the settings (ARES, Finančná správa,
		 * VIES); a service of your own implements KybernautIcDic\Services\Service.
		 *
		 * @since 2.0.0
		 * @param array<string,array<string,array<int,Service>>> $map The defaults.
		 */
		$map = apply_filters( 'woolab_icdic_services', $map );

		$clean = array();
		foreach ( (array) $map as $country => $features ) {
			foreach ( (array) $features as $feature => $services ) {
				$services = array_values( array_filter( (array) $services, static fn ( $service ) => $service instanceof Service ) );
				if ( $services ) {
					$clean[ (string) $country ][ (string) $feature ] = $services;
				}
			}
		}

		return $clean;
	}

	/**
	 * The services of a country, feature => services ('*' fills what the country
	 * does not set).
	 *
	 * @param string $country Billing country.
	 * @return array<string,array<int,Service>>
	 */
	public static function for_country( string $country ): array {
		$map = self::map();

		return ( $map[ $country ] ?? array() ) + ( $map['*'] ?? array() );
	}

	/**
	 * Whether the checkouts fill the company in for a country: a company_search
	 * service is assigned (CZ and SK: their autofill settings decide that).
	 *
	 * @param string $country Billing country.
	 */
	public static function autofill( string $country ): bool {
		return ! empty( self::for_country( $country )['company_search'] );
	}

	/**
	 * Whether a service is assigned to any feature anywhere, e.g. whether the
	 * Finančná správa lists are needed (their import runs only then).
	 *
	 * @param string $id Service::id().
	 */
	public static function uses( string $id ): bool {
		foreach ( self::map() as $features ) {
			foreach ( $features as $services ) {
				foreach ( $services as $service ) {
					if ( $id === $service->id() ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Every country a feature has a service for ('*' not included).
	 *
	 * @param string $feature A FEATURES value.
	 * @return array<int,string>
	 */
	public static function countries( string $feature ): array {
		$countries = array();
		foreach ( self::map() as $country => $features ) {
			if ( '*' !== $country && ! empty( $features[ $feature ] ) ) {
				$countries[] = $country;
			}
		}

		return $countries;
	}

	/**
	 * The first service's answer that is not null.
	 *
	 * @param array<int,Service> $services Assigned services.
	 * @param string             $method   Service method.
	 * @param string             $value    Its argument.
	 * @return mixed
	 */
	public static function first( array $services, string $method, string $value ) {
		foreach ( $services as $service ) {
			$answer = $service->{$method}( $value );
			if ( null !== $answer ) {
				return $answer;
			}
		}

		return null;
	}

	/**
	 * A VAT number's answer from the services in order: one that does not know the
	 * number ('unlisted') passes it on; when none knows it, it is invalid, or
	 * unverifiable if one could not answer.
	 *
	 * @param array<int,Service> $services Assigned services.
	 * @param string             $vat      VAT number with its prefix.
	 * @return array{state:string,ico:string}|null Null when no service validates VAT numbers.
	 */
	public static function vat_id( array $services, string $vat ): ?array {
		$asked       = false;
		$unavailable = false;

		foreach ( $services as $service ) {
			$answer = $service->validate_vat_id( $vat );
			if ( null === $answer ) {
				continue;
			}
			$asked = true;
			if ( 'unlisted' !== $answer['state'] ) {
				return $answer;
			}
			$unavailable = $unavailable || ! empty( $answer['unavailable'] );
		}

		if ( ! $asked ) {
			return null;
		}

		return array(
			'state' => $unavailable ? 'unverifiable' : 'invalid',
			'ico'   => '',
		);
	}

	/**
	 * The state of a VAT number for the exemption: the services assigned to its
	 * country, VIES when none is (the exemption is decided even with the VIES check
	 * off, as in 1.x).
	 *
	 * @param string $vat VAT number with its prefix.
	 * @return string 'valid', 'bad_format', 'invalid' or 'unverifiable'.
	 */
	public static function vat_state( string $vat ): string {
		$services = self::for_country( Checksum::vat_country_code( $vat ) )['vat_id'] ?? array( new Vies() );

		return (string) ( self::vat_id( $services, $vat )['state'] ?? 'unverifiable' );
	}
}
