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
 * Turn a log timestamp into an absolute instant the browser can restate.
 *
 * Log lines are written with current_time(), so they carry the site's clock and
 * no offset. Pairing them with the site timezone yields a real instant, which
 * is what lets each viewer see the log in their own time without changing what
 * is stored in the file.
 *
 * @param string $timestamp 'Y-m-d H:i:s' in site time.
 * @return string ISO 8601 with offset, or empty when unparseable.
 */
function tcgiant_log_time_to_utc( $timestamp ) {
	$timestamp = trim( (string) $timestamp );
	if ( '' === $timestamp ) {
		return '';
	}

	try {
		$when = new DateTime( $timestamp, wp_timezone() );
	} catch ( Exception $e ) {
		return '';
	}

	return $when->format( DateTime::ATOM );
}

/**
 * TCGiant_Sync_Logger class
 */
class TCGiant_Sync_Logger {

	/**
	 * Instance of this class.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * Max log file size in bytes (500KB).
	 */
	const MAX_LOG_SIZE = 512000;

	/**
	 * Pending log lines, flushed on shutdown.
	 *
	 * @var string[]
	 */
	private static $buffer = array();

	/**
	 * Whether the shutdown flush has been registered.
	 *
	 * @var bool
	 */
	private static $flush_registered = false;

	/**
	 * Flush the buffer once it reaches this many lines, so a long-running
	 * import does not hold the whole run in memory.
	 */
	const BUFFER_LIMIT = 100;

	/**
	 * Severity ranking, used to filter by the configured minimum level.
	 */
	const LEVELS = array(
		'debug'   => 10,
		'info'    => 20,
		'success' => 20,
		'warning' => 30,
		'error'   => 40,
	);

	/**
	 * Main instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Minimum level that gets recorded.
	 *
	 * Defaults to 'info' (current behaviour). Setting log_level to 'warning' on
	 * a large store removes the bulk of the write volume, since the importer
	 * emits a success line per product.
	 *
	 * @return int Numeric threshold from self::LEVELS.
	 */
	private static function min_level() {
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		$configured = is_array( $settings ) && ! empty( $settings['log_level'] ) ? $settings['log_level'] : 'info';
		return self::LEVELS[ $configured ] ?? self::LEVELS['info'];
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
		return self::get_log_dir() . '/tcgiant-sync-' . self::log_file_token() . '.log';
	}

	/**
	 * Per-site random token appended to the log filename.
	 *
	 * The log directory is protected with an .htaccess deny rule, which nginx
	 * ignores entirely — so on nginx the log was readable at a predictable URL.
	 * It contains product titles, SKUs and raw eBay API error bodies. An
	 * unguessable filename closes that off regardless of web server.
	 *
	 * @return string
	 */
	private static function log_file_token() {
		$token = get_option( 'tcgiant_log_token' );
		if ( ! $token ) {
			$token = wp_generate_password( 16, false );
			add_option( 'tcgiant_log_token', $token, '', false );
		}
		return $token;
	}

	/**
	 * Log a message.
	 *
	 * @param string $message Message to log.
	 * @param string $level   Log level (info, error, warning, success, debug).
	 */
	public static function log( $message, $level = 'info' ) {
		$severity = self::LEVELS[ $level ] ?? self::LEVELS['info'];
		if ( $severity < self::min_level() ) {
			return;
		}

		// Collapse the message onto a single line. Every entry is one line by
		// construction — timestamp, level, text — and a message containing
		// newlines silently broke that: the continuation lines carried no
		// timestamp or level, so the reader showed each of them as its own
		// entry. Pasting an HTML error page into the log turned it into a
		// dozen meaningless rows.
		$message = trim( preg_replace( '/\s+/', ' ', (string) $message ) );

		$timestamp = current_time( 'Y-m-d H:i:s' );
		self::$buffer[] = "[$timestamp] [$level] $message" . PHP_EOL;

		// Flush once at the end of the request rather than opening, stat-ing
		// and appending to the file on every single call. An import emits
		// several lines per product, so this was tens of thousands of
		// unbuffered writes per run.
		if ( ! self::$flush_registered ) {
			self::$flush_registered = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 100 );
		}

		if ( count( self::$buffer ) >= self::BUFFER_LIMIT ) {
			self::flush();
		}

		// Errors and warnings are mirrored to the WooCommerce log so they show
		// up in WooCommerce → Status → Logs. Routine info lines are not — they
		// were doubling the I/O for no benefit.
		if ( $severity < self::LEVELS['warning'] || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger  = wc_get_logger();
		$context = array( 'source' => 'tcgiant-sync' );

		if ( 'error' === $level ) {
			$logger->error( $message, $context );
		} else {
			$logger->warning( $message, $context );
		}
	}

	/**
	 * Write any buffered lines to disk.
	 *
	 * Registered on shutdown; also called when the buffer hits BUFFER_LIMIT.
	 *
	 * @return void
	 */
	public static function flush() {
		if ( empty( self::$buffer ) ) {
			return;
		}

		$entries      = implode( '', self::$buffer );
		self::$buffer = array();

		$log_file = self::get_log_file();

		// Cap log file size — truncate to the last 200 lines if over limit.
		if ( file_exists( $log_file ) && filesize( $log_file ) > self::MAX_LOG_SIZE ) {
			$lines = file( $log_file );
			if ( is_array( $lines ) ) {
				file_put_contents( $log_file, implode( '', array_slice( $lines, -200 ) ), LOCK_EX );
			}
		}

		file_put_contents( $log_file, $entries, FILE_APPEND | LOCK_EX );
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
		// Drop anything buffered — it belongs to the log being cleared.
		self::$buffer = array();

		$log_file = self::get_log_file();
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '', LOCK_EX );
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
		// Make sure anything logged earlier in this request is on disk, so the
		// admin log view is not a step behind.
		self::flush();

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
