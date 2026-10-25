<?php
/**
 * The imported Finančná správa lists: where they are, whether they can be trusted,
 * and queries on them.
 *
 * The Finančná správa (Slovak Financial Administration) lists of subjects registered
 * for income tax (DIČ) and for VAT (IČ DPH), with their IČO, name and address. They
 * have no keyless API, but full dumps are refreshed every night, so they are
 * imported daily into a SQLite file (about 190 MB, outside the WordPress database)
 * and everything — IČO lookup, company name search, DIČ and IČ DPH checks — runs
 * locally: no network call at checkout, no dependency on RPO or VIES. IČ DPH is not
 * always "SK" + DIČ (VAT groups, some non-profits), so both lists are kept.
 *
 * @see https://opendata.financnasprava.sk/
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Services\FinancnaSprava;

use KybernautIcDic\Logger;
use PDO;
use PDOException;

/**
 * The SQLite file under uploads.
 */
final class Storage {

	/**
	 * The imported lists. 'min' rejects a truncated download before it replaces good
	 * data: in October 2026 the lists had 1.42M and 318k rows.
	 */
	public const LISTS = array(
		'dic' => array(
			'kind'  => 1,
			'url'   => 'https://report.financnasprava.sk/ds_dsrdp.zip',
			'entry' => 'ds_dsrdp.xml',
			'tag'   => 'DIC',
			'min'   => 1000000,
		),
		'dph' => array(
			'kind'  => 2,
			'url'   => 'https://report.financnasprava.sk/ds_dphs.zip',
			'entry' => 'ds_dphs.xml',
			'tag'   => 'IC_DPH',
			'min'   => 200000,
		),
	);

	/**
	 * The open read-only database and its path.
	 *
	 * @var array{0:PDO|null,1:string|null}
	 */
	private static array $db = array( null, null );

	/**
	 * What the import needs from PHP, once per request.
	 *
	 * @var array<string,bool>|null
	 */
	private static ?array $requirements = null;

	/**
	 * Where the SQLite files live. Uploads is the one directory WordPress can always
	 * write to; the files hold only already-public data, and their names are random
	 * so they cannot be fetched where the .htaccess is not honoured (nginx).
	 */
	public static function dir(): string {
		return wp_upload_dir( null, false )['basedir'] . '/woolab-ic-dic';
	}

	/**
	 * What the import needs from PHP.
	 *
	 * @return array<string,bool> PHP extension => available.
	 */
	public static function requirements(): array {
		if ( null === self::$requirements ) {
			$sqlite             = class_exists( 'PDO' ) && in_array( 'sqlite', PDO::getAvailableDrivers(), true );
			self::$requirements = array(
				'zip'        => class_exists( 'ZipArchive' ),
				'xmlreader'  => class_exists( 'XMLReader' ),
				'pdo_sqlite' => $sqlite,
				// The name search needs SQLite's full-text search, part of nearly every build.
				'FTS5'       => false,
			);

			if ( $sqlite ) {
				try {
					self::$requirements['FTS5'] = false !== ( new PDO( 'sqlite::memory:' ) )->exec( 'CREATE VIRTUAL TABLE t USING fts5(a)' );
				} catch ( PDOException ) {
					self::$requirements['FTS5'] = false;
				}
			}
		}

		return self::$requirements;
	}

	/**
	 * Whether PHP can run the import.
	 */
	public static function supported(): bool {
		return ! in_array( false, self::requirements(), true );
	}

	/**
	 * Whether imported lists exist and are recent enough to trust. Stale lists miss
	 * new registrations, so after a week of failed imports the checks fall back to
	 * VIES or "Ignore when a check fails".
	 */
	public static function fresh(): bool {
		$updated = get_option( 'woolab_icdic_sk_updated' );

		return is_array( $updated ) && ! empty( $updated['file'] ) && time() - $updated['time'] < WEEK_IN_SECONDS;
	}

	/**
	 * The lists as the settings show them.
	 *
	 * @return array{updated:array<string,mixed>|false, failed:mixed, next:int|false, file_size:int|false, fresh:bool}
	 */
	public static function status(): array {
		$updated = get_option( 'woolab_icdic_sk_updated' );
		$updated = is_array( $updated ) && ! empty( $updated['file'] ) ? $updated : false;
		$path    = $updated ? self::dir() . '/' . $updated['file'] : '';

		return array(
			'updated'   => $updated,
			'failed'    => get_option( 'woolab_icdic_sk_failed' ),
			'next'      => wp_next_scheduled( 'woolab_icdic_sk_import' ),
			'file_size' => $path && is_readable( $path ) ? filesize( $path ) : false,
			'fresh'     => self::fresh(),
		);
	}

	/**
	 * A query on the current lists.
	 *
	 * @param string           $sql    SQL with placeholders.
	 * @param array<int,mixed> $params Their values.
	 * @return array<int,array<int,mixed>>|false Rows; false when the lists are unavailable.
	 */
	public static function query( string $sql, array $params ) {
		if ( ! self::fresh() ) {
			return false;
		}

		$current = self::dir() . '/' . get_option( 'woolab_icdic_sk_updated' )['file'];

		try {
			if ( $current !== self::$db[1] ) {
				if ( ! is_readable( $current ) ) {
					return false;
				}
				self::$db = array(
					new PDO(
						'sqlite:' . $current,
						null,
						null,
						array(
							PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
							PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
						)
					),
					$current,
				);
			}
			$statement = self::$db[0]->prepare( $sql );
			$statement->execute( $params );
			return $statement->fetchAll( PDO::FETCH_NUM );
		} catch ( PDOException $e ) {
			Logger::error( 'Finančná správa lists lookup failed: ' . $e->getMessage() );
			return false;
		}
	}
}
