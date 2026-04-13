<?php
/**
 * Logger Class
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Logger class
 */
class TCGiant_Sync_Logger {

	/**
	 * Instance of this class.
	 */
	private static $_instance = null;

	/**
	 * Max log file size in bytes (500KB).
	 */
	const MAX_LOG_SIZE = 512000;

	/**
	 * Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Get the log directory path (inside wp-content/uploads).
	 */
	public static function get_log_dir() {
		$upload_dir = wp_upload_dir();
		$log_dir = $upload_dir['basedir'] . '/tcgiant-sync';
		if ( ! file_exists( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
			// Protect directory from direct access.
			file_put_contents( $log_dir . '/.htaccess', 'deny from all' );
			file_put_contents( $log_dir . '/index.php', '<?php // Silence is golden.' );
		}
		return $log_dir;
	}

	/**
	 * Get the log file path.
	 */
	public static function get_log_file() {
		return self::get_log_dir() . '/tcgiant-sync.log';
	}

	/**
	 * Log a message.
	 *
	 * @param string $message Message to log.
	 * @param string $level   Log level (info, error, warning, success, debug).
	 */
	public static function log( $message, $level = 'info' ) {
		$log_file = self::get_log_file();
		$timestamp = current_time( 'Y-m-d H:i:s' );
		$entry = "[$timestamp] [$level] $message" . PHP_EOL;

		// Cap log file size - truncate to last 200 lines if over limit.
		if ( file_exists( $log_file ) && filesize( $log_file ) > self::MAX_LOG_SIZE ) {
			$lines = file( $log_file );
			$lines = array_slice( $lines, -200 );
			file_put_contents( $log_file, implode( '', $lines ) );
		}

		file_put_contents( $log_file, $entry, FILE_APPEND );

		// Also write to WooCommerce logger if available.
		if ( ! class_exists( 'WC_Logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		$context = array( 'source' => 'tcgiant-sync' );

		switch ( $level ) {
			case 'error':
				$logger->error( $message, $context );
				break;
			case 'warning':
				$logger->warning( $message, $context );
				break;
			case 'debug':
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$logger->debug( $message, $context );
				}
				break;
			case 'success':
			case 'info':
			default:
				$logger->info( $message, $context );
				break;
		}
	}

	/**
	 * Static helper for errors.
	 */
	public static function error( $message ) {
		self::log( $message, 'error' );
	}

	/**
	 * Static helper for warnings.
	 */
	public static function warning( $message ) {
		self::log( $message, 'warning' );
	}

	/**
	 * Clear the log file completely.
	 */
	public static function clear() {
		$log_file = self::get_log_file();
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}
		self::log( 'Log cleared by admin.' );
	}

	/**
	 * Get recent log entries parsed into structured arrays.
	 *
	 * @param int $limit Number of entries to return.
	 * @return array Array of parsed log entries.
	 */
	public static function get_recent_entries( $limit = 20 ) {
		$log_file = self::get_log_file();
		if ( ! file_exists( $log_file ) ) {
			return array();
		}

		$lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$lines = array_slice( $lines, -$limit );
		$lines = array_reverse( $lines );

		$entries = array();
		foreach ( $lines as $line ) {
			$entry = array(
				'raw'       => $line,
				'timestamp' => '',
				'level'     => 'info',
				'message'   => $line,
			);

			// Parse: [2026-04-02 19:00:42] [info] Some message
			if ( preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s*\[(\w+)\]\s*(.*)$/', $line, $m ) ) {
				$entry['timestamp'] = $m[1];
				$entry['level']     = $m[2];
				$entry['message']   = $m[3];
			}

			$entries[] = $entry;
		}

		return $entries;
	}
}
