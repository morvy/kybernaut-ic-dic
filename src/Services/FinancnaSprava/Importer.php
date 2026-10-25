<?php
/**
 * The daily import of the Finančná správa lists.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Services\FinancnaSprava;

use KybernautIcDic\Logger;
use KybernautIcDic\Services\Services;
use PDO;
use Throwable;
use XMLReader;

/**
 * WP-Cron (woolab_icdic_sk_import) and `wp woolab-icdic sk-import` download both
 * lists into a new SQLite file and switch to it; on any failure the previous file
 * stays in use.
 */
final class Importer {

	/**
	 * The last import error, for the settings' status.
	 *
	 * @var string|null
	 */
	private static ?string $last_error = null;

	/**
	 * Hook the import, its schedule, the admin notice and the WP-CLI command.
	 */
	public static function register(): void {
		add_action( 'woolab_icdic_sk_import', array( self::class, 'cron' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( 'admin_notices', array( self::class, 'admin_notice' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'woolab-icdic sk-import', array( self::class, 'cli' ) );
		}
	}

	/**
	 * Whether the lists are needed: Finančná správa is assigned to a feature
	 * (Services, the "SK: Validate in Finančná správa" setting by default). A store
	 * that replaced it downloads nothing.
	 */
	public static function needed(): bool {
		return Services::uses( 'financna_sprava' );
	}

	/**
	 * Keep the daily import scheduled exactly while the lists are needed.
	 */
	public static function schedule(): void {
		$next   = wp_next_scheduled( 'woolab_icdic_sk_import' );
		$needed = self::needed();

		if ( $needed && ! $next ) {
			// Nothing imported yet: run on the next cron tick. Afterwards daily, after
			// the lists are regenerated (around 00:40 UTC).
			$first = get_option( 'woolab_icdic_sk_updated' ) ? strtotime( 'tomorrow 03:00 UTC' ) : time();
			wp_schedule_event( $first, 'daily', 'woolab_icdic_sk_import' );
		} elseif ( ! $needed && $next ) {
			wp_clear_scheduled_hook( 'woolab_icdic_sk_import' );
		}
	}

	/**
	 * Without fresh lists every SK DIČ and IČ DPH is unverifiable, which blocks the
	 * order unless "Ignore when a check fails" is on. Say so.
	 */
	public static function admin_notice(): void {
		if ( ! self::needed() || Storage::fresh() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$message = Storage::supported()
			? __( 'Kybernaut IČO DIČ: the Finančná správa DIČ / IČ DPH lists are missing or older than a week, so those numbers cannot be validated. The import runs daily through WP-Cron (or run "wp woolab-icdic sk-import"); failures are logged in WooCommerce → Status → Logs.', 'woolab-ic-dic' )
			: __( 'Kybernaut IČO DIČ: the Finančná správa DIČ / IČ DPH lists cannot be imported, the zip, xmlreader and pdo_sqlite PHP extensions are required (ask your hosting).', 'woolab-ic-dic' );

		printf( '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>', esc_html( $message ), esc_url( woolab_icdic_settings_url( 'financna_sprava' ) ), esc_html__( 'Import status', 'woolab-ic-dic' ) );
	}

	/**
	 * The daily WP-Cron run.
	 */
	public static function cron(): void {
		self::import();
	}

	/**
	 * `wp woolab-icdic sk-import`
	 */
	public static function cli(): void {
		$counts = self::import();
		if ( ! $counts ) {
			\WP_CLI::error( 'Import failed, see the plugin log.' );
		}
		\WP_CLI::success( sprintf( 'Imported %d DIČ and %d IČ DPH numbers.', $counts['dic'], $counts['dph'] ) );
	}

	/**
	 * Log an import error and keep it for the status.
	 *
	 * @param string|null $message Null reads the last one back.
	 */
	public static function error( ?string $message = null ): ?string {
		if ( null !== $message ) {
			self::$last_error = $message;
			Logger::error( $message );
		}

		return self::$last_error;
	}

	/**
	 * Download both lists into a new SQLite file and switch to it. A failed attempt
	 * is shown in the settings until the next successful one.
	 *
	 * @return array{dic:int,dph:int}|false Imported row counts, or false.
	 */
	public static function import() {
		$counts = self::import_locked();

		if ( $counts ) {
			delete_option( 'woolab_icdic_sk_failed' );
		} else {
			update_option(
				'woolab_icdic_sk_failed',
				array(
					'time'  => time(),
					'error' => (string) self::error(),
				),
				false
			);
		}

		return $counts;
	}

	/**
	 * The import, one at a time.
	 *
	 * @return array{dic:int,dph:int}|false
	 */
	private static function import_locked() {
		if ( ! Storage::supported() ) {
			self::error( 'Finančná správa lists import needs the zip, xmlreader and pdo_sqlite (with FTS5) PHP extensions.' );
			return false;
		}

		$dir = Storage::dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			self::error( 'Finančná správa lists import could not create ' . $dir );
			return false;
		}

		// Apache honours Require; LiteSpeed only the rewrite rule; nginx neither, which
		// is what the random file name is for.
		// phpcs:disable WordPress.WP.AlternativeFunctions -- plain files next to the database, before WP_Filesystem is set up in cron.
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule .* - [F]\n</IfModule>\n" );

		// WP-Cron and WP-CLI can overlap. The OS releases the lock when a crashed
		// run's process ends.
		$lock = fopen( $dir . '/import.lock', 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			self::error( 'Finančná správa lists import skipped: another import is running.' );
			return false;
		}

		$file = 'sk-' . wp_generate_password( 32, false ) . '.sqlite';

		try {
			$counts = self::run( $dir . '/' . $file );
		} catch ( Throwable $e ) {
			self::error( 'Finančná správa lists import failed: ' . $e->getMessage() );
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
		// phpcs:enable WordPress.WP.AlternativeFunctions

		return $counts;
	}

	/**
	 * Build the new SQLite file.
	 *
	 * @param string $path The new file.
	 * @return array{dic:int,dph:int}|false
	 */
	private static function run( string $path ) {
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

		foreach ( Storage::LISTS as $key => $list ) {
			$file = download_url( $list['url'], 600 );

			if ( is_wp_error( $file ) ) {
				self::error( sprintf( 'Finančná správa lists import could not download %s: %s', $list['url'], $file->get_error_message() ) );
				return false;
			}

			$read = self::import_list( $file, $list, $db );
			wp_delete_file( $file );

			// Count what was stored, not what was read: duplicates are skipped.
			$count = $read ? (int) $db->query( 'SELECT COUNT(*) FROM n WHERE kind = ' . (int) $list['kind'] )->fetchColumn() : 0;

			if ( $count < $list['min'] ) {
				self::error( sprintf( 'Finančná správa lists import stopped: %s gave %d rows, expected at least %d.', $list['entry'], $count, $list['min'] ) );
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
	 * @param string              $zip  Downloaded file.
	 * @param array<string,mixed> $definition A Storage::LISTS entry.
	 * @param PDO                 $db   The new database.
	 * @return int Items read, or 0 when the XML is broken.
	 */
	private static function import_list( string $zip, array $definition, PDO $db ): int {
		$reader = new XMLReader();
		libxml_use_internal_errors( true );
		libxml_clear_errors();

		if ( ! $reader->open( 'zip://' . $zip . '#' . $definition['entry'] ) ) {
			return 0;
		}

		$insert  = $db->prepare( 'INSERT OR IGNORE INTO n (kind, num, ico) VALUES (?, ?, ?)' );
		$subject = $db->prepare( 'INSERT OR IGNORE INTO s (ico, name, street, psc, city) VALUES (?, ?, ?, ?, ?)' );
		$count   = 0;

		$db->beginTransaction();

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XMLReader's and the lists' own names.
		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType || 'ITEM' !== $reader->name ) {
				continue;
			}

			$item = simplexml_load_string( $reader->readOuterXml() );
			$num  = $item ? preg_replace( '/\D/', '', (string) $item->{$definition['tag']} ) : '';

			if ( '' === $num ) {
				continue;
			}

			// Some rows carry a 12-digit or otherwise malformed IČO; store those as unknown.
			$ico = preg_match( '/^\d{1,8}$/', (string) $item->ICO ) ? (int) $item->ICO : null;
			$insert->execute( array( $definition['kind'], (int) $num, $ico ) );
			if ( $ico ) {
				$subject->execute( array( $ico, trim( (string) $item->NAZOV_DS ), trim( (string) $item->ULICA_CISLO ), trim( (string) $item->PSC ), trim( (string) $item->OBEC ) ) );
			}
			++$count;
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$db->commit();
		$reader->close();

		if ( libxml_get_last_error() ) {
			libxml_clear_errors();
			return 0;
		}

		return $count;
	}
}
