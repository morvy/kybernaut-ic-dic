<?php

/**
 * Finančná správa lists, the SK counterpart of ARES (includes/ares.php).
 *
 * The Finančná správa (Slovak Financial Administration) lists of subjects registered for
 * income tax (DIČ) and for VAT (IČ DPH), with their IČO, name and address. They
 * have no keyless API, but full dumps are refreshed every night, so they are
 * imported daily into a SQLite file (about 190 MB, outside the WordPress database)
 * and everything — IČO lookup, company name search, DIČ and IČ DPH checks — runs
 * locally: no network call at checkout, no dependency on RPO or VIES. IČ DPH is
 * not always "SK" + DIČ (VAT groups, some non-profits), so both lists are kept.
 * https://opendata.financnasprava.sk/
 */

use KybernautIcDic\Logger;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The imported lists. 'min' rejects a truncated download before it replaces good
 * data: in October 2026 the lists had 1.42M and 318k rows.
 */
const WOOLAB_ICDIC_SK_LISTS = array(
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

add_action( 'woolab_icdic_sk_import', 'woolab_icdic_sk_import' );
add_action( 'init', 'woolab_icdic_sk_schedule' );
add_action( 'admin_notices', 'woolab_icdic_sk_admin_notice' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'woolab-icdic sk-import', function () {
		$counts = woolab_icdic_sk_import();
		if ( ! $counts ) {
			WP_CLI::error( 'Import failed, see the plugin log.' );
		}
		WP_CLI::success( sprintf( 'Imported %d DIČ and %d IČ DPH numbers.', $counts['dic'], $counts['dph'] ) );
	} );
}

/**
 * Where the SQLite files live. Uploads is the one directory WordPress can always
 * write to; the files hold only already-public data, and their names are random
 * so they cannot be fetched where the .htaccess below is not honoured (nginx).
 */
function woolab_icdic_sk_dir() {
	return wp_upload_dir( null, false )['basedir'] . '/woolab-ic-dic';
}

/**
 * Keep the daily import scheduled exactly while the SK check is on.
 */
function woolab_icdic_sk_schedule() {
	$next = wp_next_scheduled( 'woolab_icdic_sk_import' );

	if ( woolab_icdic_sk_check() && ! $next ) {
		// Nothing imported yet: run on the next cron tick. Afterwards daily, after
		// the lists are regenerated (around 00:40 UTC).
		$first = get_option( 'woolab_icdic_sk_updated' ) ? strtotime( 'tomorrow 03:00 UTC' ) : time();
		wp_schedule_event( $first, 'daily', 'woolab_icdic_sk_import' );
	} elseif ( ! woolab_icdic_sk_check() && $next ) {
		wp_clear_scheduled_hook( 'woolab_icdic_sk_import' );
	}
}

/**
 * Without fresh lists every SK DIČ and IČ DPH is unverifiable, which blocks the
 * order unless "ignore when the check fails" is on. Say so.
 */
function woolab_icdic_sk_admin_notice() {
	if ( ! woolab_icdic_sk_check() || woolab_icdic_sk_lists_fresh() || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$message = woolab_icdic_sk_import_supported()
		? __( 'Kybernaut IČO DIČ: the Finančná správa DIČ / IČ DPH lists are missing or older than a week, so those numbers cannot be validated. The import runs daily through WP-Cron (or run "wp woolab-icdic sk-import"); failures are logged in WooCommerce → Status → Logs.', 'woolab-ic-dic' )
		: __( 'Kybernaut IČO DIČ: the Finančná správa DIČ / IČ DPH lists cannot be imported, the zip, xmlreader and pdo_sqlite PHP extensions are required (ask your hosting).', 'woolab-ic-dic' );

	printf( '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>', esc_html( $message ), esc_url( woolab_icdic_settings_url( 'financna_sprava' ) ), esc_html__( 'Import status', 'woolab-ic-dic' ) );
}

/**
 * What the import needs from PHP.
 *
 * @return array<string,bool> PHP extension => available.
 */
function woolab_icdic_sk_requirements() {
	static $requirements = null;

	if ( null === $requirements ) {
		$sqlite       = class_exists( 'PDO' ) && in_array( 'sqlite', PDO::getAvailableDrivers(), true );
		$requirements = array(
			'zip'        => class_exists( 'ZipArchive' ),
			'xmlreader'  => class_exists( 'XMLReader' ),
			'pdo_sqlite' => $sqlite,
			// The name search needs SQLite's full-text search, part of nearly every build.
			'FTS5'       => false,
		);

		if ( $sqlite ) {
			try {
				$requirements['FTS5'] = false !== ( new PDO( 'sqlite::memory:' ) )->exec( 'CREATE VIRTUAL TABLE t USING fts5(a)' );
			} catch ( PDOException $e ) {
				$requirements['FTS5'] = false;
			}
		}
	}

	return $requirements;
}

function woolab_icdic_sk_import_supported() {
	return ! in_array( false, woolab_icdic_sk_requirements(), true );
}

/**
 * Log an import error and remember it for the status in the settings
 * (woolab_icdic_sk_status()).
 *
 * @param string|null $message Null reads the last one back.
 * @return string|null
 */
function woolab_icdic_sk_import_error( $message = null ) {
	static $last = null;

	if ( null !== $message ) {
		$last = $message;
		Logger::error( $message );
	}

	return $last;
}

/**
 * The lists as the settings show them.
 *
 * @return array{updated:array|false, failed:array|false, next:int|false, file_size:int|false, fresh:bool}
 */
function woolab_icdic_sk_status() {
	$updated = get_option( 'woolab_icdic_sk_updated' );
	$updated = is_array( $updated ) && ! empty( $updated['file'] ) ? $updated : false;
	$path    = $updated ? woolab_icdic_sk_dir() . '/' . $updated['file'] : '';

	return array(
		'updated'   => $updated,
		'failed'    => get_option( 'woolab_icdic_sk_failed' ),
		'next'      => wp_next_scheduled( 'woolab_icdic_sk_import' ),
		'file_size' => $path && is_readable( $path ) ? filesize( $path ) : false,
		'fresh'     => woolab_icdic_sk_lists_fresh(),
	);
}

/**
 * Download both lists into a new SQLite file and switch to it. On any failure the
 * previous file stays in use.
 *
 * @return array|false Imported row counts keyed 'dic' and 'dph', or false.
 */
function woolab_icdic_sk_import() {
	$counts = woolab_icdic_sk_import_locked();

	// A failed attempt is shown in the settings until the next successful one.
	if ( $counts ) {
		delete_option( 'woolab_icdic_sk_failed' );
	} else {
		update_option( 'woolab_icdic_sk_failed', array( 'time' => time(), 'error' => (string) woolab_icdic_sk_import_error() ), false );
	}

	return $counts;
}

/**
 * @return array|false
 */
function woolab_icdic_sk_import_locked() {

	if ( ! woolab_icdic_sk_import_supported() ) {
		woolab_icdic_sk_import_error( 'Finančná správa lists import needs the zip, xmlreader and pdo_sqlite (with FTS5) PHP extensions.' );
		return false;
	}

	$dir = woolab_icdic_sk_dir();

	if ( ! wp_mkdir_p( $dir ) ) {
		woolab_icdic_sk_import_error( 'Finančná správa lists import could not create ' . $dir );
		return false;
	}

	// Apache honours Require; LiteSpeed only the rewrite rule; nginx neither, which
	// is what the random file name is for.
	file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
	file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule .* - [F]\n</IfModule>\n" );

	// One import at a time: WP-Cron and WP-CLI can overlap. The OS releases the
	// lock when a crashed run's process ends.
	$lock = fopen( $dir . '/import.lock', 'c' );
	if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
		woolab_icdic_sk_import_error( 'Finančná správa lists import skipped: another import is running.' );
		return false;
	}

	$file = 'sk-' . wp_generate_password( 32, false ) . '.sqlite';

	try {
		$counts = woolab_icdic_sk_import_run( $dir . '/' . $file );
	} catch ( Throwable $e ) {
		woolab_icdic_sk_import_error( 'Finančná správa lists import failed: ' . $e->getMessage() );
		$counts = false;
	}

	if ( $counts ) {
		$previous = get_option( 'woolab_icdic_sk_updated' );
		update_option(
			'woolab_icdic_sk_updated',
			array(
				'time'   => time(),
				'counts' => $counts,
				'file'   => $file,
			),
			false
		);
		Logger::info( sprintf( 'Finančná správa lists imported: %d DIČ, %d IČ DPH.', $counts['dic'], $counts['dph'] ) );

		// Requests still reading the old file keep their open handle.
		if ( ! empty( $previous['file'] ) ) {
			wp_delete_file( $dir . '/' . $previous['file'] );
		}
	} else {
		wp_delete_file( $dir . '/' . $file );
	}

	flock( $lock, LOCK_UN );
	fclose( $lock );

	return $counts;
}

/**
 * @param string $path The new SQLite file.
 * @return array|false
 */
function woolab_icdic_sk_import_run( $path ) {

	require_once ABSPATH . 'wp-admin/includes/file.php';

	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 0 );
	}

	$db = new PDO( 'sqlite:' . $path, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
	// A half-written file is deleted anyway, so skip the journal.
	$db->exec( 'PRAGMA journal_mode = OFF; PRAGMA synchronous = OFF;' );
	$db->exec( 'CREATE TABLE n (kind INTEGER NOT NULL, num INTEGER NOT NULL, ico INTEGER, PRIMARY KEY (kind, num)) WITHOUT ROWID' );
	// Name and address per IČO. Rows without an IČO (natural persons in the DIČ
	// list) are left out.
	$db->exec( 'CREATE TABLE s (ico INTEGER PRIMARY KEY, name TEXT, street TEXT, psc TEXT, city TEXT)' );

	$counts = array();

	foreach ( WOOLAB_ICDIC_SK_LISTS as $key => $list ) {
		$file = download_url( $list['url'], 600 );

		if ( is_wp_error( $file ) ) {
			woolab_icdic_sk_import_error( sprintf( 'Finančná správa lists import could not download %s: %s', $list['url'], $file->get_error_message() ) );
			return false;
		}

		$read = woolab_icdic_sk_import_list( $file, $list, $db );
		wp_delete_file( $file );

		// Count what was stored, not what was read: duplicates are skipped.
		$count = $read ? (int) $db->query( 'SELECT COUNT(*) FROM n WHERE kind = ' . (int) $list['kind'] )->fetchColumn() : 0;

		if ( $count < $list['min'] ) {
			woolab_icdic_sk_import_error( sprintf( 'Finančná správa lists import stopped: %s gave %d rows, expected at least %d.', $list['entry'], $count, $list['min'] ) );
			return false;
		}

		$counts[ $key ] = $count;
	}

	$db->exec( 'CREATE INDEX ico ON n (ico, kind)' );
	// Prefix-searchable names, diacritics folded. External content: the names are not stored twice.
	$db->exec( "CREATE VIRTUAL TABLE f USING fts5(name, content='s', content_rowid='ico', tokenize='unicode61 remove_diacritics 2', prefix='3')" );
	$db->exec( 'INSERT INTO f (rowid, name) SELECT ico, name FROM s' );

	return $counts;
}

/**
 * Stream one zipped XML list into the database.
 *
 * @return int Items read, or 0 when the XML is broken.
 */
function woolab_icdic_sk_import_list( $zip, array $list, PDO $db ) {
	$reader = new XMLReader();
	libxml_use_internal_errors( true );
	libxml_clear_errors();

	if ( ! $reader->open( 'zip://' . $zip . '#' . $list['entry'] ) ) {
		return 0;
	}

	$insert  = $db->prepare( 'INSERT OR IGNORE INTO n (kind, num, ico) VALUES (?, ?, ?)' );
	$subject = $db->prepare( 'INSERT OR IGNORE INTO s (ico, name, street, psc, city) VALUES (?, ?, ?, ?, ?)' );
	$count  = 0;

	$db->beginTransaction();

	while ( $reader->read() ) {
		if ( XMLReader::ELEMENT !== $reader->nodeType || 'ITEM' !== $reader->name ) {
			continue;
		}

		$item = simplexml_load_string( $reader->readOuterXml() );
		$num  = $item ? preg_replace( '/\D/', '', (string) $item->{$list['tag']} ) : '';

		if ( '' === $num ) {
			continue;
		}

		// Some rows carry a 12-digit or otherwise malformed IČO; store those as unknown.
		$ico = preg_match( '/^\d{1,8}$/', (string) $item->ICO ) ? (int) $item->ICO : null;
		$insert->execute( array( $list['kind'], (int) $num, $ico ) );
		if ( $ico ) {
			$subject->execute( array( $ico, trim( (string) $item->NAZOV_DS ), trim( (string) $item->ULICA_CISLO ), trim( (string) $item->PSC ), trim( (string) $item->OBEC ) ) );
		}
		$count++;
	}

	$db->commit();
	$reader->close();

	if ( libxml_get_last_error() ) {
		libxml_clear_errors();
		return 0;
	}

	return $count;
}

/**
 * Whether imported lists exist and are recent enough to trust. Stale lists miss
 * new registrations, so after a week of failed imports checks fall back to VIES
 * or the "ignore when the check fails" setting.
 */
function woolab_icdic_sk_lists_fresh() {
	$updated = get_option( 'woolab_icdic_sk_updated' );
	return is_array( $updated ) && ! empty( $updated['file'] ) && time() - $updated['time'] < WEEK_IN_SECONDS;
}

/**
 * A query on the current lists.
 *
 * @return array|null|false Rows; false when the lists are unavailable.
 */
function woolab_icdic_sk_query( $sql, array $params ) {
	static $db = null, $path = null;

	if ( ! woolab_icdic_sk_lists_fresh() ) {
		return false;
	}

	$current = woolab_icdic_sk_dir() . '/' . get_option( 'woolab_icdic_sk_updated' )['file'];

	try {
		if ( $current !== $path ) {
			if ( ! is_readable( $current ) ) {
				return false;
			}
			$db   = new PDO( 'sqlite:' . $current, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY ) );
			$path = $current;
		}
		$statement = $db->prepare( $sql );
		$statement->execute( $params );
		return $statement->fetchAll( PDO::FETCH_NUM );
	} catch ( PDOException $e ) {
		Logger::error( 'Finančná správa lists lookup failed: ' . $e->getMessage() );
		return false;
	}
}

/**
 * Find a DIČ ('dic') or IČ DPH ('dph') in the imported lists.
 *
 * @param string $list   'dic' or 'dph'.
 * @param string $number With or without the SK prefix and spaces.
 * @return array|null|false ['ico' => string, '' when unknown] when listed, null
 *                          when not, false when the lists are unavailable.
 */
function woolab_icdic_sk_find( $list, $number ) {
	$digits = preg_replace( '/^SK/i', '', preg_replace( '/\s+/', '', (string) $number ) );

	if ( ! preg_match( '/^\d{1,18}$/', $digits ) ) {
		return woolab_icdic_sk_lists_fresh() ? null : false;
	}

	$rows = woolab_icdic_sk_query( 'SELECT ico FROM n WHERE kind = ? AND num = ?', array( WOOLAB_ICDIC_SK_LISTS[ $list ]['kind'], (int) $digits ) );

	if ( false === $rows ) {
		return false;
	}

	if ( ! $rows ) {
		return null;
	}

	return array( 'ico' => null === $rows[0][0] ? '' : str_pad( $rows[0][0], 8, '0', STR_PAD_LEFT ) );
}

/**
 * The DIČ ('dic') or IČ DPH ('dph') listed for an IČO.
 *
 * @return string|null The number ('SK'-prefixed for IČ DPH), or null when unknown:
 *                     not listed under this IČO (some rows carry no IČO, new
 *                     registrations arrive a day later) or the lists are unavailable.
 */
function woolab_icdic_sk_number_for_ico( $list, $ico ) {
	$rows = woolab_icdic_sk_query( 'SELECT num FROM n WHERE ico = ? AND kind = ? ORDER BY num LIMIT 1', array( (int) $ico, WOOLAB_ICDIC_SK_LISTS[ $list ]['kind'] ) );

	if ( ! $rows ) {
		return null;
	}

	return ( 'dph' === $list ? 'SK' : '' ) . str_pad( $rows[0][0], 10, '0', STR_PAD_LEFT );
}


/**
 * Look an IČO up in the imported lists. Same result shape as woolab_icdic_ares(),
 * plus 'dic_dph'; 'dic' and 'dic_dph' are null when the subject has none.
 *
 * A subject missing from the lists is not necessarily invalid: one founded in the
 * last few days, or not registered for tax, is not there yet. That comes back as an
 * error flagged 'unlisted', which the checkout treats as nothing to autofill rather
 * than a wrong Business ID.
 *
 * @param string $ico
 * @return array
 */
function woolab_icdic_sk_lookup( $ico = '' ) {

	if ( $ico == '' ) {
		return array( 'error' => __( 'Business ID not set.', 'woolab-ic-dic' ) );
	}

	// No checksum here: the lists hold real IČOs that fail the mod-11 check.
	if ( ! preg_match( '/^\d{8}$/', $ico ) ) {
		return array( 'error' => __( 'Business ID must be a number and 8 digits long.', 'woolab-ic-dic' ) );
	}

	$rows = woolab_icdic_sk_query( 'SELECT name, street, psc, city FROM s WHERE ico = ?', array( (int) $ico ) );

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
		'adresa'     => woolab_icdic_sk_street( $street, $city ),
		'psc'        => str_replace( ' ', '', $psc ),
		'mesto'      => $city,
		'dic'        => woolab_icdic_sk_number_for_ico( 'dic', $ico ),
		'dic_dph'    => woolab_icdic_sk_number_for_ico( 'dph', $ico ),
	);
}

/**
 * Company name search in the imported lists, same contract as
 * woolab_icdic_ares_search(): every word of the query matches the start of a word
 * in the name, diacritics ignored ("kavia" finds "Kaviareň").
 */
function woolab_icdic_sk_search( $name ) {

	preg_match_all( '/[\p{L}\p{N}]+/u', $name, $words );
	if ( ! $words[0] ) {
		return array();
	}

	$match = implode( ' ', array_map( function ( $word ) {
		return '"' . $word . '"*';
	}, $words[0] ) );

	// Names starting with what was typed first ("alza" → Alza.sk before Ľudmila Alzate).
	$rows = woolab_icdic_sk_query( "SELECT s.ico, s.name, s.street, s.psc, s.city FROM f JOIN s ON s.ico = f.rowid WHERE f MATCH ? ORDER BY s.name LIKE ? ESCAPE '\\' DESC, f.rank LIMIT 10", array( $match, addcslashes( trim( $name ), '%_\\' ) . '%' ) );

	if ( false === $rows ) {
		return false;
	}

	return array_map( function ( $row ) {
		return array(
			'ico'     => str_pad( $row[0], 8, '0', STR_PAD_LEFT ),
			'name'    => $row[1],
			'address' => implode( ', ', array_filter( array( woolab_icdic_sk_street( $row[2], $row[4] ), trim( $row[3] . ' ' . $row[4] ) ) ) ),
		);
	}, $rows );
}

/**
 * The lists give villages without street names as the house number alone ("621");
 * prefix the municipality, as addresses are written there.
 */
function woolab_icdic_sk_street( $street, $city ) {
	return preg_match( '/\p{L}/u', (string) $street ) ? (string) $street : trim( $city . ' ' . $street );
}
