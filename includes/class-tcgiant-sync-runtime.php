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
	 * The last death of any job. Each job's own is kept beside it, under this
	 * name and the job's - see crash_option().
	 */
	const CRASH_OPTION = 'tcgiant_sync_last_crash';

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
	 * Object-cache reads between the guard's looks at memory.
	 */
	const GUARD_EVERY = 250;

	/**
	 * Share of the memory limit below which the guard never empties the
	 * object cache's copies part way through an item.
	 */
	const GUARD_MEMORY_SHARE = 0.5;

	/**
	 * How far an item must have taken memory, as a share of the limit, beyond
	 * where it began, before the guard empties the cache part way through it.
	 * A request that is simply heavy - other plugins, a low limit - is not
	 * the item's doing, and emptying the cache would not change it.
	 */
	const GUARD_GROWTH_SHARE = 0.1;

	/**
	 * Share of the limit an emptying must give back to count as having done
	 * any good - the mark of copies piling up in the cache.
	 */
	const GUARD_FREED_SHARE = 0.05;

	/**
	 * The cache guard, while on: how deep it is nested, the share it acts at
	 * for this item and the floor it may not drop below, what it has seen of
	 * this item, and whether the request is dying.
	 *
	 * @var array|null
	 */
	private static $guard = null;

	/**
	 * The memory limit as it was when the request ran out of memory, before
	 * make_room() raised it.
	 *
	 * @var string|null
	 */
	private static $limit_at_death = null;

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
	 * Hand back memory a long job has no more use for.
	 *
	 * Called between items - and, by the cache guard (on_cache_read()), from
	 * inside a wp_cache_get() part way through one. So it only ever drops
	 * this request's own copies: wpdb's saved queries, and the drop-in's
	 * cache, group_ops and memcache_debug, or wp_cache_flush_runtime() where
	 * the drop-in offers it. Never wp_cache_flush(), and nothing here may read
	 * or write the object cache. Two things grow with every
	 * query and cache lookup until the request ends:
	 *
	 * - With SAVEQUERIES on, wpdb keeps every query with the call stack that
	 *   made it. Nothing reads them in a background run.
	 * - A persistent object cache keeps its own copy of every key the request
	 *   has touched. Automattic's memcached drop-in (WordPress.com, VIP) also
	 *   keeps a record of every operation, and a call stack for each one
	 *   slower than 5 ms - on a busy cache server, a lot of them. A shop on
	 *   WordPress.com had delta runs die at 512 MB in exactly those two
	 *   places: the drop-in itself, then wp_debug_backtrace_summary().
	 *
	 * The cache is emptied the way the drop-in offers. One that offers no way
	 * gets the reset WP-CLI gives every drop-in in its long commands: the
	 * request's own copies go, the stored values stay. WordPress's built-in
	 * cache, which holds the only copy, is left alone.
	 */
	public static function release_memory() {
		global $wpdb, $wp_object_cache;

		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && is_object( $wpdb ) ) {
			self::empty_public_array( $wpdb, 'queries' );
		}

		if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			return;
		}

		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' )
			&& function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
			return;
		}

		if ( is_object( $wp_object_cache ) ) {
			foreach ( array( 'cache', 'group_ops', 'memcache_debug' ) as $property ) {
				self::empty_public_array( $wp_object_cache, $property );
			}
		}
	}

	/**
	 * Watch the object cache while items are worked on, and empty its copies
	 * part way through one when the request runs short of memory.
	 *
	 * release_memory() runs between items. What it cannot reach is an item
	 * that fills the request on its own: on a WordPress.com shop each delta
	 * run died inside the one listing it started, about 100 s and 500 MB in,
	 * at a point where the memcached drop-in was holding a copy of everything
	 * read since - which only grows if something reads posts by the thousand,
	 * as other plugins can when a product is saved. Every read goes through
	 * the drop-in's pre_wp_cache_get filter, so that is where to look. The
	 * values themselves stay in memcached; only this request's copies go, as
	 * WP-CLI drops them in its long commands. Automattic's memcached drop-in
	 * (WordPress.com, VIP, wp-memcached 4.x) applies that filter; others -
	 * Redis Object Cache, LiteSpeed, older memcached ones - do not, and there
	 * the guard stays idle and only the release between items applies.
	 * Without a persistent object cache there is nothing of this kind to
	 * empty, and the guard stays off.
	 */
	public static function guard_cache() {
		if ( null !== self::$guard ) {
			self::$guard['depth']++;
			return;
		}

		if ( ! function_exists( 'add_filter' ) || ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			return;
		}

		self::$guard = array(
			'depth'    => 1,
			'reads'    => 0,
			'releases' => 0,
			'freed'    => 0,
			'floor'    => self::GUARD_MEMORY_SHARE,
			'at'       => self::GUARD_MEMORY_SHARE,
			'frozen'   => false,
		);
		self::guard_from_here();
		add_filter( 'pre_wp_cache_get', array( __CLASS__, 'on_cache_read' ), PHP_INT_MAX );
	}

	/**
	 * Measure the item's starting point: the guard acts only once memory has
	 * grown well past it, and never below half the limit.
	 */
	private static function guard_from_here() {
		if ( null === self::$guard ) {
			return;
		}

		self::$guard['floor'] = max( self::GUARD_MEMORY_SHARE, self::memory_share() + self::GUARD_GROWTH_SHARE );
		self::$guard['at']    = self::$guard['floor'];
	}

	/**
	 * Stop watching the object cache - once whoever else turned it on has
	 * finished too.
	 */
	public static function unguard_cache() {
		if ( null === self::$guard ) {
			return;
		}

		if ( --self::$guard['depth'] > 0 ) {
			return;
		}

		self::$guard = null;
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'pre_wp_cache_get', array( __CLASS__, 'on_cache_read' ), PHP_INT_MAX );
		}
	}

	/**
	 * What the guard has seen since it was last asked - reads, how often it
	 * emptied the cache, and how many bytes that gave back - and start
	 * counting again.
	 *
	 * @return array reads, releases, freed (bytes).
	 */
	public static function cache_guard_take() {
		if ( null === self::$guard ) {
			return array( 'reads' => 0, 'releases' => 0, 'freed' => 0 );
		}

		$seen = array(
			'reads'    => self::$guard['reads'],
			'releases' => self::$guard['releases'],
			'freed'    => self::$guard['freed'],
		);

		self::$guard['reads']    = 0;
		self::$guard['releases'] = 0;
		self::$guard['freed']    = 0;

		return $seen;
	}

	/**
	 * pre_wp_cache_get: count, and now and then look at memory. The value is
	 * passed on untouched - this never answers a read.
	 *
	 * @param mixed $value What earlier filters made of the read.
	 * @return mixed
	 */
	public static function on_cache_read( $value ) {
		if ( null === self::$guard || self::$guard['frozen'] ) {
			return $value;
		}

		self::$guard['reads']++;
		if ( 0 !== self::$guard['reads'] % self::GUARD_EVERY ) {
			return $value;
		}

		// A request that has died is reading on its way out - WordPress's own
		// fatal error handler runs before anything of the plugin's. What the
		// cache holds now is what the record of the death needs to count.
		$error = error_get_last();
		if ( is_array( $error ) && in_array( (int) $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			self::$guard['frozen'] = true;
			return $value;
		}

		if ( self::memory_share() >= self::$guard['at'] ) {
			$before = self::memory_used();
			self::release_memory();
			$after = self::memory_used();

			self::$guard['releases']++;
			$limit = self::memory_limit();
			if ( $limit > 0 && ( $before - $after ) >= self::GUARD_FREED_SHARE * $limit ) {
				self::$guard['freed'] += $before - $after;
			}

			// Not again until memory has grown that far once more - from
			// here, if emptying did not bring it down: the memory is held
			// somewhere else, and emptying again would only slow the item.
			self::$guard['at'] = max( self::$guard['floor'], self::memory_share() + self::GUARD_GROWTH_SHARE );
		}

		return $value;
	}

	/**
	 * Empty one array property of an object, if it is public and not empty.
	 *
	 * get_object_vars() from here sees public properties only, so a drop-in's
	 * private state is never touched.
	 *
	 * @param object $object   The object.
	 * @param string $property Property name.
	 */
	private static function empty_public_array( $object, $property ) {
		$vars = get_object_vars( $object );
		if ( ! isset( $vars[ $property ] ) || ! is_array( $vars[ $property ] ) || array() === $vars[ $property ] ) {
			return;
		}
		unset( $vars );

		try {
			$object->$property = array();
		} catch ( \Throwable $e ) {
			// Read-only. Leave it.
			unset( $e );
		}
	}

	/**
	 * Why a job should stop starting new work now, or '' to carry on.
	 *
	 * @return string
	 */
	public static function over_budget() {
		$late = self::over_time_budget();

		if ( '' !== $late ) {
			return $late;
		}

		$share = self::memory_share();

		if ( $share >= self::BUDGET_MEMORY_SHARE ) {
			return sprintf( '%d%% of the memory limit in use', (int) round( $share * 100 ) );
		}

		return '';
	}

	/**
	 * Why a job should stop for time alone, or '' to carry on.
	 *
	 * For stopping part way through one item - between the photos of one
	 * product, say - where memory already in use is the item's own and would
	 * only be needed again to finish it.
	 *
	 * @return string
	 */
	public static function over_time_budget() {
		$elapsed = self::elapsed();
		$budget  = self::time_budget();

		if ( $elapsed >= $budget ) {
			return sprintf( '%d s of this request used, of a %d s budget', (int) $elapsed, (int) $budget );
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
			'stage'    => '',
			'began_at' => null,
			'began_mb' => null,
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

		// The request is on its way out: the cache guard must not empty what
		// the record of a death is about to count.
		if ( null !== self::$guard ) {
			self::$guard['frozen'] = true;
		}

		$error = self::$death;
		if ( null === self::$limit_at_death && isset( $error['type'], $error['message'] ) && E_ERROR === (int) $error['type'] && false !== strpos( (string) $error['message'], 'Allowed memory size' )
			&& function_exists( 'ini_set' ) && ! self::is_disabled( 'ini_set' ) ) {
			$limit = self::memory_limit();
			if ( $limit > 0 ) {
				// Once: this runs from the shutdown action and again from
				// on_shutdown(). The record gives the limit that was reached.
				self::$limit_at_death = (string) ini_get( 'memory_limit' );
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
			$named                   = '' !== (string) $context;
			self::$watch['context']  = (string) $context;
			self::$watch['stage']    = '';
			self::$watch['began_at'] = $named ? self::elapsed() : null;
			self::$watch['began_mb'] = $named ? (int) round( memory_get_usage() / 1048576 ) : null;
		}

		self::guard_from_here();
	}

	/**
	 * Say which step of the current item the job is on: if PHP kills it, the
	 * record names the step, and what memory and time the item began with.
	 * A death in one listing used to say only which listing - not whether it
	 * was eBay's answer, the product save, or something else hooked into it.
	 *
	 * @param string $stage E.g. "saving the product".
	 */
	public static function stage( $stage ) {
		if ( null !== self::$watch ) {
			self::$watch['stage'] = (string) $stage;
		}
	}

	/**
	 * Tell Action Scheduler to claim nothing more in this queue run.
	 *
	 * A job that stops for memory hands on to a fresh request by queueing its
	 * next run; the runner otherwise goes on claiming actions in this same,
	 * heavy request - that next run among them - until its own, looser limit
	 * of 90% is reached. Other actions it had claimed wait for the next queue
	 * run, as they would after any batch the runner ends early.
	 */
	public static function end_queue_runs() {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( 'action_scheduler_memory_exceeded', '__return_true' );
		add_filter( 'action_scheduler_maximum_execution_time_likely_to_be_exceeded', '__return_true' );
		add_action( 'action_scheduler_after_process_queue', array( __CLASS__, 'resume_queue_runs' ) );
	}

	/**
	 * The queue run end_queue_runs() ended is over: a later one in this
	 * process is not held to it.
	 */
	public static function resume_queue_runs() {
		if ( ! function_exists( 'remove_filter' ) ) {
			return;
		}

		remove_filter( 'action_scheduler_memory_exceeded', '__return_true' );
		remove_filter( 'action_scheduler_maximum_execution_time_likely_to_be_exceeded', '__return_true' );
		remove_action( 'action_scheduler_after_process_queue', array( __CLASS__, 'resume_queue_runs' ) );
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
		$limit_was   = null !== self::$limit_at_death ? self::$limit_at_death : (string) ini_get( 'memory_limit' );
		self::$limit_at_death = null;
		// No error at all is an empty array, not one with a type in it.
		$fatal = is_array( $error ) && isset( $error['type'] ) && in_array( (int) $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true );

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
			'%s %s%s%s. %d s into the request; memory peak %d MB of %s; time limit %d s.%s',
			$watch['job'],
			$how,
			'' !== $watch['context'] ? ' while working on ' . $watch['context'] : '',
			'' !== $watch['stage'] ? ' - ' . $watch['stage'] : '',
			(int) self::elapsed(),
			(int) round( memory_get_peak_usage() / 1048576 ),
			$limit_was,
			self::effective_time_limit(),
			self::death_detail( $watch )
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

		self::record_crash( $message, $watch['job'] );
	}

	/**
	 * What else the record of a death needs to say where the memory or time
	 * went: when the item began, which WordPress hooks were running - another
	 * plugin's work on a product save shows here - and what the object cache
	 * was holding. Counting allocates nothing, so this is safe after running
	 * out of memory.
	 *
	 * @param array $watch The watched job.
	 * @return string '' or a sentence, with a leading space.
	 */
	private static function death_detail( array $watch ) {
		global $wp_current_filter, $wp_object_cache;

		$parts = array();

		if ( null !== $watch['began_at'] ) {
			$parts[] = sprintf( 'the item began %d s into the request, at %d MB', (int) $watch['began_at'], (int) $watch['began_mb'] );
		}

		// What WordPress was running when it stopped. The shutdown action has
		// been and gone by now; what it left is the stack at the time of death.
		$hooks = is_array( $wp_current_filter ) ? array_values( array_diff( $wp_current_filter, array( 'shutdown' ) ) ) : array();
		if ( $hooks ) {
			$parts[] = 'WordPress hooks running: ' . implode( ' > ', array_slice( $hooks, -6 ) );
		}

		if ( is_object( $wp_object_cache ) ) {
			$vars  = get_object_vars( $wp_object_cache );
			$held  = isset( $vars['cache'] ) && is_array( $vars['cache'] ) ? count( $vars['cache'] ) : null;
			$ops   = 0;
			$kinds = isset( $vars['group_ops'] ) && is_array( $vars['group_ops'] ) ? $vars['group_ops'] : array();
			foreach ( $kinds as $list ) {
				$ops += is_array( $list ) ? count( $list ) : 0;
			}
			unset( $vars, $kinds );
			if ( null !== $held ) {
				$parts[] = sprintf( 'object cache holding %d keys, %d operations recorded', $held, $ops );
			}
		}

		if ( null !== self::$guard && self::$guard['reads'] > 0 && null !== $watch['began_at'] ) {
			$parts[] = sprintf( '%d object-cache reads during the item, the cache emptied %d time(s)', self::$guard['reads'], self::$guard['releases'] );
		}

		return $parts ? ' (' . implode( '; ', $parts ) . '.)' : '';
	}

	/**
	 * The last time PHP killed a job, or null if nothing recorded it.
	 *
	 * Each job's death is kept apart from the others'. 3.27.0 and 3.27.1 kept
	 * one record for every job, so a photo download dying on one product every
	 * quarter of an hour was what "eBay changes are not reaching your shop"
	 * gave as its reason. A record from then names no job, but its message
	 * starts with the job's name, which is how it is matched.
	 *
	 * @param string $job Job name, as passed to watch().
	 * @return array|null at, message.
	 */
	public static function last_crash( $job ) {
		$found = null;

		$own = get_option( self::crash_option( $job ), array() );
		if ( is_array( $own ) && ! empty( $own['at'] ) ) {
			$found = array( 'at' => (int) $own['at'], 'message' => (string) ( $own['message'] ?? '' ) );
		}

		// The shared record too, if it is this job's and newer: written alone
		// by 3.27.0 or 3.27.1, after going back to one of them for a while.
		$any = get_option( self::CRASH_OPTION, array() );
		if ( is_array( $any ) && ! empty( $any['at'] ) && isset( $any['message'] ) ) {
			$theirs = isset( $any['job'] )
				? (string) $any['job'] === (string) $job
				: 0 === strpos( (string) $any['message'], $job . ' ' );

			if ( $theirs && ( null === $found || (int) $any['at'] > $found['at'] ) ) {
				$found = array( 'at' => (int) $any['at'], 'message' => (string) $any['message'] );
			}
		}

		return $found;
	}

	/**
	 * Where one job's last death is kept.
	 *
	 * @param string $job Job name, e.g. "Delta sync".
	 * @return string E.g. tcgiant_sync_last_crash_delta_sync.
	 */
	public static function crash_option( $job ) {
		return self::CRASH_OPTION . '_' . trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( (string) $job ) ), '_' );
	}

	/**
	 * Keep the death for the admin screens, in an option of its own.
	 *
	 * Written straight to the table rather than through update_option(), which
	 * reloads every autoloaded option to keep its cache in step - on a big site
	 * a megabyte or more, in a request that has just run out of memory.
	 *
	 * Kept twice: as the last death of any job, as 3.27.0 kept it, and as this
	 * job's own, so that one job dying often cannot hide another's death.
	 * Each is a whole-row write, with nothing read first, so two jobs dying at
	 * once cannot lose either's own record.
	 *
	 * @param string $message What happened.
	 * @param string $job     Which job it happened to.
	 */
	private static function record_crash( $message, $job = '' ) {
		global $wpdb;

		$record = array( 'at' => time(), 'message' => $message, 'job' => (string) $job );
		$names  = array( self::CRASH_OPTION );
		if ( '' !== (string) $job ) {
			$names[] = self::crash_option( $job );
		}

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'replace' ) ) {
			if ( function_exists( 'update_option' ) ) {
				foreach ( $names as $name ) {
					update_option( $name, $record, false );
				}
			}
			return;
		}

		foreach ( $names as $name ) {
			$wpdb->replace(
				$wpdb->options,
				array(
					'option_name'  => $name,
					'option_value' => serialize( $record ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					'autoload'     => 'no',
				),
				array( '%s', '%s', '%s' )
			);

			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $name, 'options' );
			}
		}

		// WordPress remembers options it found missing. Without this, a site
		// with a persistent object cache that had looked for the record before
		// the first death would go on reporting there is none.
		if ( function_exists( 'wp_cache_get' ) && function_exists( 'wp_cache_set' ) ) {
			$missing = wp_cache_get( 'notoptions', 'options' );
			if ( is_array( $missing ) && array_intersect_key( $missing, array_flip( $names ) ) ) {
				wp_cache_set( 'notoptions', array_diff_key( $missing, array_flip( $names ) ), 'options' );
			}
		}
	}
}
