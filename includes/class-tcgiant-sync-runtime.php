<?php
/**
 * What the host will let a long job do, and what is left when it stops one.
 *
 * Three things every long-running job here needs and none of them had:
 *
 * - Raising the time limit without dying. set_time_limit() and
 *   ignore_user_abort() are listed in disable_functions on plenty of shared
 *   PHP 8 hosts, where calling them is not a warning but a fatal "Call to
 *   undefined function" - so every delta, scan and image batch died on its
 *   first line, before it had done anything at all.
 *
 * - Knowing when to stop. A job that runs until the host kills it leaves no
 *   record of how far it got. Measuring from the start of the request, and
 *   stopping well short of whatever limit applies, turns a death into an
 *   orderly hand-over to the next request.
 *
 * - Leaving a record when it dies anyway. A PHP fatal skips every `finally`
 *   and every line still sitting in the log buffer that would have said what
 *   was being worked on. Two shops spent weeks with a sync that restarted the
 *   same window every hour and a log that never once said why.
 *
 * @package TCGiant_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCGiant_Sync_Runtime {

	/**
	 * What the time budget assumes when the host sets no limit at all.
	 *
	 * max_execution_time 0 means PHP will not stop the request, but the web
	 * server in front of it usually will - commonly somewhere between one and
	 * five minutes - and nothing in PHP can see that figure.
	 */
	const UNLIMITED_ASSUMED_SECONDS = 120;

	/**
	 * Longest a single run of a job should go on, however generous the host.
	 *
	 * Short runs chained together cost almost nothing more than one long one,
	 * and every run that ends cleanly is one that cannot be killed half way.
	 */
	const BUDGET_CAP_SECONDS = 60;

	/**
	 * Share of the memory limit a job may reach before it hands over.
	 *
	 * Well short of the limit, because what tips a request over is the next
	 * allocation, and a single eBay listing with a compatibility table can be
	 * several megabytes once parsed.
	 */
	const BUDGET_MEMORY_SHARE = 0.60;

	/**
	 * Kept in hand while a job is watched, and released first when it dies,
	 * so the record of the death has room to be written after running out of
	 * memory.
	 */
	const RESERVE_BYTES = 262144;

	/**
	 * Extra memory allowed after an out-of-memory error, so the record of it
	 * can be written. What PHP already allocated is still allocated, and the
	 * reserve alone is not always enough.
	 */
	const AFTER_DEATH_HEADROOM = 33554432;

	/**
	 * Seconds the last raise_time_limit() secured, or 0 if it could not.
	 *
	 * @var int
	 */
	private static $raised_to = 0;

	/**
	 * Test seam: a callable returning the current time as a float.
	 *
	 * @var callable|null
	 */
	public static $clock = null;

	/**
	 * Test seam: a callable returning current memory use in bytes.
	 *
	 * @var callable|null
	 */
	public static $memory_probe = null;

	/**
	 * The job being watched, if any.
	 *
	 * @var array|null
	 */
	private static $watch = null;

	/**
	 * Memory held back for the crash record.
	 *
	 * @var string
	 */
	private static $reserve = '';

	/**
	 * Whether the shutdown handler has been registered in this request.
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * The error PHP stopped on, caught before anything else in shutdown can
	 * raise a notice of its own and take its place in error_get_last().
	 *
	 * @var array|null
	 */
	private static $death = null;

	/**
	 * Ask for more time, where the host allows it to be asked for.
	 *
	 * @param int $seconds Seconds wanted.
	 * @return bool Whether the limit was actually raised.
	 */
	public static function raise_time_limit( $seconds = 300 ) {
		if ( function_exists( 'ignore_user_abort' ) && ! self::is_disabled( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$raised = false;

		if ( function_exists( 'set_time_limit' ) && ! self::is_disabled( 'set_time_limit' ) ) {
			$raised = (bool) @set_time_limit( (int) $seconds ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		self::$raised_to = $raised ? (int) $seconds : 0;

		return $raised;
	}

	/**
	 * Whether a function is listed in disable_functions.
	 *
	 * PHP 8 removes disabled functions outright, so function_exists() alone
	 * answers it there; PHP 7.4 keeps them defined and only warns, which is
	 * why the list itself is checked too.
	 *
	 * @param string $name Function name.
	 * @return bool
	 */
	private static function is_disabled( $name ) {
		$disabled = (string) ini_get( 'disable_functions' );

		if ( '' === $disabled ) {
			return false;
		}

		return in_array( $name, array_map( 'trim', explode( ',', $disabled ) ), true );
	}

	/**
	 * The time limit this request is really working to, in seconds.
	 *
	 * @return int
	 */
	public static function effective_time_limit() {
		if ( self::$raised_to > 0 ) {
			return self::$raised_to;
		}

		$limit = (int) ini_get( 'max_execution_time' );

		return $limit > 0 ? $limit : self::UNLIMITED_ASSUMED_SECONDS;
	}

	/**
	 * Now, as a float. Overridable for tests.
	 *
	 * @return float
	 */
	public static function now() {
		return self::$clock ? (float) call_user_func( self::$clock ) : microtime( true );
	}

	/**
	 * Seconds since the request began.
	 *
	 * Counted from the request, not from when the job started, because the
	 * limit a host enforces is on the request - and on a cron request other
	 * jobs may already have used some of it before this one got going.
	 *
	 * @return float
	 */
	public static function elapsed() {
		$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;

		if ( $start <= 0 ) {
			return 0.0;
		}

		return max( 0.0, self::now() - $start );
	}

	/**
	 * How long a job in this request should keep starting new work.
	 *
	 * @return float Seconds from the start of the request.
	 */
	public static function time_budget() {
		return (float) min( self::BUDGET_CAP_SECONDS, self::effective_time_limit() / 2 );
	}

	/**
	 * The memory limit in bytes, or 0 for none.
	 *
	 * @return int
	 */
	public static function memory_limit() {
		$raw = trim( (string) ini_get( 'memory_limit' ) );

		if ( '' === $raw || '-1' === $raw ) {
			return 0;
		}

		if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
			return max( 0, (int) wp_convert_hr_to_bytes( $raw ) );
		}

		$value = (int) $raw;
		$unit  = strtolower( substr( $raw, -1 ) );

		if ( 'g' === $unit ) {
			$value *= 1073741824;
		} elseif ( 'm' === $unit ) {
			$value *= 1048576;
		} elseif ( 'k' === $unit ) {
			$value *= 1024;
		}

		return max( 0, $value );
	}

	/**
	 * Memory in use now, in bytes. Overridable for tests.
	 *
	 * @return int
	 */
	public static function memory_used() {
		return self::$memory_probe ? (int) call_user_func( self::$memory_probe ) : memory_get_usage();
	}

	/**
	 * Share of the memory limit in use, 0 when there is no limit.
	 *
	 * @return float
	 */
	public static function memory_share() {
		$limit = self::memory_limit();

		return $limit > 0 ? self::memory_used() / $limit : 0.0;
	}

	/**
	 * Why a job should stop starting new work now, or '' to carry on.
	 *
	 * @return string
	 */
	public static function over_budget() {
		$elapsed = self::elapsed();
		$budget  = self::time_budget();

		if ( $elapsed >= $budget ) {
			return sprintf( '%d s of this request used, of a %d s budget', (int) $elapsed, (int) $budget );
		}

		$share = self::memory_share();

		if ( $share >= self::BUDGET_MEMORY_SHARE ) {
			return sprintf( '%d%% of the memory limit in use', (int) round( $share * 100 ) );
		}

		return '';
	}

	/**
	 * Watch a job, so that if PHP kills it the log says what it was doing.
	 *
	 * @param string   $job      Short name for the log line, e.g. "Delta sync".
	 * @param callable $on_death Optional. Called on death with the message,
	 *                           after the line is written - for releasing
	 *                           locks and handing work on. It must allocate
	 *                           very little.
	 */
	public static function watch( $job, $on_death = null ) {
		self::$watch = array(
			'job'      => (string) $job,
			'context'  => '',
			'on_death' => $on_death,
		);

		self::$reserve = str_repeat( ' ', self::RESERVE_BYTES );

		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );

			// Shutdown functions run in the order they were registered, and
			// WordPress's, WooCommerce's and the shutdown action's come first.
			// After an out-of-memory error any of them can fail again and take
			// the rest down with it, so the reserve is let go at the first point
			// WordPress lets a plugin act.
			if ( function_exists( 'add_action' ) ) {
				add_action( 'shutdown', array( __CLASS__, 'make_room' ), PHP_INT_MIN );
			}
		}
	}

	/**
	 * Let go of the reserve, and after an out-of-memory error allow a little
	 * more, so what runs during shutdown can finish. Does nothing for a job
	 * that ended normally.
	 */
	public static function make_room() {
		if ( null === self::$watch ) {
			return;
		}

		self::$reserve = '';

		if ( null === self::$death ) {
			$error       = error_get_last();
			self::$death = is_array( $error ) ? $error : array();
		}

		$error = self::$death;
		if ( isset( $error['type'], $error['message'] ) && E_ERROR === (int) $error['type'] && false !== strpos( (string) $error['message'], 'Allowed memory size' )
			&& function_exists( 'ini_set' ) && ! self::is_disabled( 'ini_set' ) ) {
			$limit = self::memory_limit();
			if ( $limit > 0 ) {
				@ini_set( 'memory_limit', (string) ( $limit + self::AFTER_DEATH_HEADROOM ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
			}
		}
	}

	/**
	 * Say what the watched job is working on right now.
	 *
	 * @param string $context E.g. "eBay item 1234 (5 of 1795)".
	 */
	public static function context( $context ) {
		if ( null !== self::$watch ) {
			self::$watch['context'] = (string) $context;
		}
	}

	/**
	 * The watched job finished, one way or another, without PHP killing it.
	 */
	public static function unwatch() {
		self::$watch   = null;
		self::$reserve = '';
	}

	/**
	 * Shutdown: if a watched job is still marked running, it did not end on
	 * its own - record how it ended.
	 */
	public static function on_shutdown() {
		if ( null === self::$watch ) {
			return;
		}

		// Room to work in, first of all - in case nothing ran make_room().
		self::make_room();

		$watch       = self::$watch;
		self::$watch = null;

		$error       = self::$death;
		self::$death = null;
		$fatal = is_array( $error ) && in_array( (int) $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true );

		if ( $fatal ) {
			$how = sprintf(
				'stopped by a PHP fatal error: %s in %s:%d',
				$error['message'],
				basename( (string) $error['file'] ),
				(int) $error['line']
			);
		} else {
			$how = 'ended without finishing, by exit() or a host limit';
		}

		$message = sprintf(
			'%s %s%s. %d s into the request; memory peak %d MB of %s; time limit %d s.',
			$watch['job'],
			$how,
			'' !== $watch['context'] ? ' while working on ' . $watch['context'] : '',
			(int) self::elapsed(),
			(int) round( memory_get_peak_usage() / 1048576 ),
			(string) ini_get( 'memory_limit' ),
			self::effective_time_limit()
		);

		// The line first: an append to a file, and the one thing that must
		// not be lost.
		if ( class_exists( 'TCGiant_Sync_Logger' ) && method_exists( 'TCGiant_Sync_Logger', 'write_now' ) ) {
			TCGiant_Sync_Logger::write_now( $message, 'error' );
		}

		// Then the job's own clean-up - releasing its lock, handing on - before
		// anything that touches the options table.
		if ( is_callable( $watch['on_death'] ) ) {
			call_user_func( $watch['on_death'], $message );
		}

		self::record_crash( $message );
	}

	/**
	 * Keep the death for the admin screens, in an option of its own.
	 *
	 * Written straight to the table rather than through update_option(), which
	 * reloads every autoloaded option to keep its cache in step - on a big site
	 * a megabyte or more, in a request that has just run out of memory.
	 *
	 * @param string $message What happened.
	 */
	private static function record_crash( $message ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'replace' ) ) {
			if ( function_exists( 'update_option' ) ) {
				update_option( 'tcgiant_sync_last_crash', array( 'at' => time(), 'message' => $message ), false );
			}
			return;
		}

		$wpdb->replace(
			$wpdb->options,
			array(
				'option_name'  => 'tcgiant_sync_last_crash',
				'option_value' => serialize( array( 'at' => time(), 'message' => $message ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				'autoload'     => 'no',
			),
			array( '%s', '%s', '%s' )
		);

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'tcgiant_sync_last_crash', 'options' );
		}

		// WordPress remembers options it found missing. Without this, a site
		// with a persistent object cache that had looked for the record before
		// the first death would go on reporting there is none.
		if ( function_exists( 'wp_cache_get' ) && function_exists( 'wp_cache_set' ) ) {
			$missing = wp_cache_get( 'notoptions', 'options' );
			if ( is_array( $missing ) && isset( $missing['tcgiant_sync_last_crash'] ) ) {
				unset( $missing['tcgiant_sync_last_crash'] );
				wp_cache_set( 'notoptions', $missing, 'options' );
			}
		}
	}
}
