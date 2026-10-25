<?php
/**
 * The plugin's log, in WooCommerce → Status → Logs (source kybernaut-ic-dic).
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic;

/**
 * Errors are always logged; the rest only with the woolab_icdic_logger_log_all
 * filter on.
 */
final class Logger {

	private const LEVELS = array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' );

	/**
	 * Log a message.
	 *
	 * @param mixed  $message Anything other than a string or a number is logged as JSON.
	 * @param string $level   One of the PSR-3 levels; info when unknown.
	 */
	public static function log( $message, string $level = 'info' ): void {
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			$level = 'info';
		}

		/**
		 * Log every event, not only errors.
		 *
		 * @since 1.6.0
		 * @param bool $log_all
		 */
		if ( 'error' !== $level && ! apply_filters( 'woolab_icdic_logger_log_all', false ) ) {
			return;
		}

		$text = is_string( $message ) || is_numeric( $message ) ? (string) $message : (string) wp_json_encode( $message );

		wc_get_logger()->log( $level, wp_strip_all_tags( $text ), array( 'source' => 'kybernaut-ic-dic' ) );
	}

	/**
	 * Log an error.
	 *
	 * @param string $message Message.
	 */
	public static function error( string $message ): void {
		self::log( $message, 'error' );
	}

	/**
	 * Log an info message (with woolab_icdic_logger_log_all only).
	 *
	 * @param string $message Message.
	 */
	public static function info( string $message ): void {
		self::log( $message, 'info' );
	}
}
