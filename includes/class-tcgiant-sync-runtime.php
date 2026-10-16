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
 * - Surviving another plugin's save loop. A product save fires hooks other
 *   plugins work in, and one that saves the product again from them goes
 *   round until the request runs out of memory - see guard_loops().
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
	 * How many times one product-save hook may be running inside itself, for
	 * the same product, before the loop breaker stops it.
	 *
	 * A plugin that saves the product once more from inside the hook - to
	 * keep something it worked out from the save - has it running twice: the
	 * save, and its own save inside it. Two such plugins, or one answering
	 * another, make three. Nothing that finishes needs five. Each round is a
	 * whole WooCommerce save, so five of them cost a few hundred queries and
	 * cache reads; Jon's shop went round thousands of times in one listing -
	 * 1.9 million object-cache reads and 480 MB - before PHP stopped it.
	 */
	const LOOP_DEPTH = 5;

	/**
	 * How many of the hooks WordPress is running, from the innermost out, are
	 * looked at for a loop: room for five rounds of forty hooks each.
	 */
	const LOOP_WINDOW = 200;

	/**
	 * The hooks a product save fires that another plugin can save the product
	 * again from - and so go round for ever, each save firing the hook again.
	 *
	 * From WooCommerce's and WordPress's own code:
	 * - woocommerce_update_product and _variation end every update, whether
	 *   anything changed or not, so a bare save() from one never ends.
	 * - woocommerce_new_product and _variation end a create. A save from one
	 *   is an update, which goes round through the hook above.
	 * - woocommerce_before_product_object_save and _after_ come either side
	 *   of every save(), a variation's included.
	 * - woocommerce_product_object_updated_props ends every create and update
	 *   too, with nothing checked first (WC_Product_Data_Store_CPT's
	 *   handle_updated_props()).
	 * - save_post and its product and variation forms come from
	 *   wp_update_post(), which a save uses when the title, description or
	 *   status changed. WooCommerce steps around save_post itself, but not
	 *   around the forms named for the post type, nor another plugin's
	 *   wp_update_post().
	 * - woocommerce_product_set_stock and _variation_set_stock follow a stock
	 *   change, and wc_update_product_stock() from one saves the product and
	 *   fires it again.
	 */
	const LOOP_HOOKS = array(
		'woocommerce_update_product',
		'woocommerce_update_product_variation',
		'woocommerce_new_product',
		'woocommerce_new_product_variation',
		'woocommerce_before_product_object_save',
		'woocommerce_after_product_object_save',
		'woocommerce_product_object_updated_props',
		'save_post',
		'save_post_product',
		'save_post_product_variation',
		'woocommerce_product_set_stock',
		'woocommerce_variation_set_stock',
	);

	/**
	 * The loop breaker, while on: how deep it is nested; for each hook it has
	 * cut short, what it took off and will put back; and which product each
	 * run of a watched hook is for, by its place in WordPress's list of the
	 * hooks running.
	 *
	 * @var array|null
	 */
	private static $loops = null;

	/**
	 * Every save loop stopped in this request, by what caused it - so each is
	 * warned about once, and the record of a death can name it.
	 *
	 * @var array
	 */
	private static $loop_culprits = array();

	/**
	 * What more stopping a save loop cost than the warning about its culprit
	 * said, said once each in this request - see report_more().
	 *
	 * @var array
	 */
	private static $loop_more = array();

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
		self::drop_copies();

		// What was let go sits among what the request keeps, and PHP holds
		// its limit against the pages it has taken from the system, not what
		// is in use in them. Without this a request whose other code kept
		// what it read went on growing towards the limit however often the
		// copies were dropped. Hands back pages left empty; reads nothing.
		if ( function_exists( 'gc_mem_caches' ) ) {
			gc_mem_caches();
		}
	}

	/**
	 * The release itself: this request's copies, and nothing else.
	 */
	private static function drop_copies() {
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
	 * Stop another plugin's product save going round for ever while this
	 * plugin saves products.
	 *
	 * Jon's WordPress.com shop, after 3.27.3: every delta run still died in
	 * the first listing it saved, and the record of the death showed why.
	 * WordPress was running woocommerce_update_product inside
	 * woocommerce_update_product inside woocommerce_update_product: something
	 * hooked to it saves the product again, which fires it again, thousands
	 * deep, until the request ran out of memory. Emptying the object cache
	 * could not help - the memory was the recursion. This plugin registers
	 * nothing on that hook (tools/hooks.txt) and cannot stop another plugin
	 * saving the product, but it can stop the hook going round.
	 *
	 * While on, the first callback on each of LOOP_HOOKS is this one. Once a
	 * hook is running LOOP_DEPTH times inside itself for the same product, the
	 * callback that saved again - the one the run of the hook a level out is
	 * in - is taken off it, and a stand-in that does nothing holds its place.
	 * The innermost save runs everything else on the hook; each save outside
	 * it carries on past that callback to the end, its write included. So the
	 * product is saved, and every other plugin on the hook still runs, before
	 * the culprit or after it - one that saves the product once more from the
	 * hook included, since a save inside a save is no loop. Only the culprit
	 * is skipped, for the rest of that save.
	 *
	 * Unless the hook goes on round without it. Another callback that saves
	 * again from two rounds running is taken off as well, for the rest of
	 * that save; and once the hook is running twice LOOP_DEPTH times inside
	 * itself for the product, whatever keeps it going, everything on it but
	 * this one is taken off, so the loop is certain to end, and what other
	 * plugins had still to do on it in that save is skipped. The log says
	 * which, after the warning that named the culprit.
	 *
	 * When the backtrace cannot say which callback it was - it names one that
	 * nothing on the hook can be matched to, such as a __call() trampoline -
	 * everything on the hook but this one is taken off at once instead. The
	 * loop stops just the same and the product is still saved. Plugins that
	 * run on that hook before the one that loops have run in every round but
	 * the last; those after it are skipped, for this product's save and for
	 * every other product that goes round the same way - which is still
	 * better than the run dying.
	 *
	 * What was taken off goes back at the start of the next save from outside
	 * any loop, in its place among the callbacks at its priority, so it runs
	 * on every product after - each of whose saves can go round LOOP_DEPTH
	 * times at most. That is told from the backtrace, so a save that threw
	 * out of the hook earlier in the run, leaving its name on WordPress's
	 * list of the hooks running, does not keep it off; nor is such a name
	 * from before the guard was on counted as a round. It goes back when the
	 * guard comes off too, and on the way out of a request that died with it
	 * off.
	 *
	 * Depth-counted like guard_cache(), so a stock write that turns it on
	 * inside a delta run does not turn it off for the rest of the run.
	 */
	public static function guard_loops() {
		if ( null !== self::$loops ) {
			self::$loops['depth']++;
			return;
		}

		if ( ! function_exists( 'add_action' ) || ! function_exists( 'remove_action' ) ) {
			return;
		}

		self::$loops = array(
			'depth' => 1,
			'cut'   => array(),
			'ids'   => array(),
		);

		foreach ( self::LOOP_HOOKS as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_save_hook' ), PHP_INT_MIN, 2 );
		}
	}

	/**
	 * Stop watching for save loops - once whoever else turned it on has
	 * finished too - and put back whatever was taken off a hook.
	 */
	public static function unguard_loops() {
		if ( null === self::$loops ) {
			return;
		}

		if ( --self::$loops['depth'] > 0 ) {
			return;
		}

		self::restore_loops();
		self::$loops = null;

		if ( function_exists( 'remove_action' ) ) {
			foreach ( self::LOOP_HOOKS as $hook ) {
				remove_action( $hook, array( __CLASS__, 'on_save_hook' ), PHP_INT_MIN );
			}
		}

		// WooCommerce saves the parent of every variation saved in this request
		// once more, on the shutdown action - WC_Post_Data's deferred product
		// sync, at priority 10 - long after the job that saved the variations
		// has finished. That save goes round like any other, and with nothing
		// left to stop it the request dies on its way out, the parents' prices
		// and stock never synced. So the breaker is on for that as well.
		global $wc_deferred_product_sync;
		if ( ! empty( $wc_deferred_product_sync ) && function_exists( 'add_action' ) ) {
			add_action( 'shutdown', array( __CLASS__, 'guard_loops' ), 9, 0 );
			add_action( 'shutdown', array( __CLASS__, 'unguard_loops' ), 11, 0 );
		}
	}

	/**
	 * Each of LOOP_HOOKS, before anything else on it: how many times is it
	 * running inside itself for this product? At LOOP_DEPTH the loop is cut;
	 * on a hook that was cut, at a save from outside any loop, whatever was
	 * taken off goes back and runs in this one.
	 *
	 * Never throws. An exception from here would unwind through WordPress's
	 * own dispatch, leaving its list of the hooks running and WP_Hook's count
	 * of how deep each one is wrong for the rest of the request.
	 *
	 * @param mixed $first  The hook's first argument: a product or post ID, or
	 *                      the product.
	 * @param mixed $second Its second, if it has one.
	 * @return mixed $first, untouched.
	 */
	public static function on_save_hook( $first = null, $second = null ) {
		global $wp_current_filter;

		if ( null === self::$loops ) {
			return $first;
		}

		try {
			$hook = function_exists( 'current_filter' ) ? (string) current_filter() : '';
			$id   = self::loop_product_id( $first, $second );

			// Which product this run is for, by its place in WordPress's list of
			// the hooks running. A save that goes round is one product, round
			// after round. One product's save that saves the next, along a chain
			// of linked products, is not a loop however far it goes.
			if ( '' !== $hook && is_array( $wp_current_filter ) && $wp_current_filter ) {
				self::$loops['ids'][ $hook ][ count( $wp_current_filter ) - 1 ] = $id;
			}

			$cut = isset( self::$loops['cut'][ $hook ] );

			// Whether this save is from outside any run of the hook is read
			// from the backtrace, not WordPress's list: a save that threw out
			// of the hook - an item the delta gave up on, going on to the
			// next - leaves its name there, and by the list the hook would be
			// running for the rest of the run, and stay cut.
			if ( $cut && 1 === self::live_runs( $hook ) ) {
				self::restore_hook( $hook );
			} elseif ( self::running_count( $hook, $cut, $id ) >= self::LOOP_DEPTH ) {
				self::cut_loop( $hook, $id );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return $first;
	}

	/**
	 * Stands in for a callback the loop breaker has taken off a hook, at its
	 * priority, until it goes back - see cut_loop(). Does nothing.
	 *
	 * @param mixed $first The hook's first argument.
	 * @return mixed $first, untouched.
	 */
	public static function hold_place( $first = null ) {
		return $first;
	}

	/**
	 * How many runs of a hook are under way, this one included, read from the
	 * backtrace - see hook_runs(). It takes a backtrace, so it is asked only
	 * of a hook cut short.
	 *
	 * @param string $hook Hook name.
	 * @return int
	 */
	private static function live_runs( $hook ) {
		list( , $same ) = self::hook_runs( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS ), $hook ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace

		return count( $same );
	}

	/**
	 * How many times a hook is running for a product, counting the run in
	 * progress - up to as many as are asked about.
	 *
	 * Read from WordPress's list of the hooks running, which costs nothing,
	 * from the innermost out, and for a hook not cut short only as far as
	 * LOOP_WINDOW names: five rounds of a loop sit close together at the top
	 * of the list. Reading all of it on every save would make a loop this
	 * cannot cut - through a hook it does not watch - cost more with every
	 * round, ten thousand names deep. A hook it has cut is read whole, since
	 * a save that goes round again inside the loop it cut is a round of it,
	 * however deep the work in between.
	 *
	 * Only the names the breaker saw run are counted, each by the product it
	 * was for. Those for another product are not rounds of this one's save.
	 * Nor are those it has no product for, from before it was on: a run that
	 * began before then, or a name WordPress left on the list when a save
	 * threw out of the hook - as a queue runner that catches a failed action
	 * leaves it - which would otherwise be counted for every product saved
	 * after.
	 *
	 * @param string $hook  Hook name.
	 * @param bool   $whole Read the whole list.
	 * @param int    $id    The product.
	 * @param int    $upto  Stop counting here.
	 * @return int
	 */
	private static function running_count( $hook, $whole, $id, $upto = self::LOOP_DEPTH ) {
		global $wp_current_filter;

		$count = 0;

		if ( '' === (string) $hook || ! is_array( $wp_current_filter ) ) {
			return 0;
		}

		$ids = isset( self::$loops['ids'][ $hook ] ) ? self::$loops['ids'][ $hook ] : array();

		// A list: WordPress only ever adds to the end of it and takes from there.
		$last = count( $wp_current_filter ) - 1;
		$stop = $whole ? 0 : max( 0, $last - self::LOOP_WINDOW + 1 );

		for ( $i = $last; $i >= $stop; $i-- ) {
			if ( ! isset( $wp_current_filter[ $i ] ) || $wp_current_filter[ $i ] !== $hook || ! isset( $ids[ $i ] ) || $ids[ $i ] !== $id ) {
				continue;
			}
			if ( ++$count >= $upto ) {
				break;
			}
		}

		return $count;
	}

	/**
	 * Stop a hook going round: take off the callback that saved again - or,
	 * when that cannot be told, every callback but this one - and keep what
	 * was taken off to put back.
	 *
	 * Through WordPress's own add_filter() and remove_filter(), which re-point
	 * every run of the hook in progress (WP_Hook's resort_active_iterations()).
	 * A run keeps its place by priority alone. Empty the culprit's priority
	 * and each run that is on it now is sent past the next priority as well,
	 * so a stand-in holds it first. Taking off everything but this one, at
	 * the lowest priority there is, leaves each run nothing after it to run.
	 * This one stays either way, so the hook is never emptied and WordPress
	 * never drops it.
	 *
	 * A hook cut already, and going round again, has more taken off only
	 * where that carries the loop on. A callback that saved again from two
	 * rounds running - another that loops, or the culprit put back - is
	 * taken off as well. Left alone are a callback that saves the product
	 * once more from inside the loop, a save inside a save; one taken off
	 * already, still running from a round that had it in hand when it went;
	 * and one that took itself off the hook round its own save. Once the hook
	 * is running twice LOOP_DEPTH times inside itself for the product,
	 * everything goes, so the loop is certain to end. At the first cut, a
	 * callback that cannot be told has everything go at once.
	 *
	 * @param string $hook Hook name.
	 * @param int    $id   The product.
	 */
	private static function cut_loop( $hook, $id ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] )
			|| ! isset( $wp_filter[ $hook ]->callbacks ) || ! is_array( $wp_filter[ $hook ]->callbacks )
			|| ! function_exists( 'add_filter' ) || ! function_exists( 'remove_filter' ) || ! function_exists( 'remove_all_filters' ) ) {
			return;
		}

		// Already cut, and going round again. Whatever is taken off now is
		// kept with the rest, and the loop is not counted twice.
		$again   = isset( self::$loops['cut'][ $hook ] );
		$state   = $again ? self::$loops['cut'][ $hook ] : array(
			'kept'  => array(),
			'held'  => array(),
			'order' => array(),
			'all'   => false,
			'first' => '',
		);
		$was_all = $state['all'];

		// Who it was, and which callback, from the backtrace - read before
		// anything is taken off. Should that fail, the loop must still stop.
		$culprit = array( 'kind' => '', 'slug' => '', 'method' => '', 'call' => '', 'callback' => null, 'previous' => null );
		$found   = null;
		try {
			$culprit = self::loop_culprit( $hook );
			$found   = self::loop_callbacks( $hook, $culprit['callback'] );
		} catch ( \Throwable $e ) {
			$found = null;
		}

		if ( null === $found || ( ! $again && ! $found )
			|| ( $again && self::running_count( $hook, true, $id, 2 * self::LOOP_DEPTH ) >= 2 * self::LOOP_DEPTH ) ) {
			$state = self::cut_all( $hook, $state );
		} elseif ( $found && ( ! $again || self::runs_any( $found, $culprit['previous'] ) ) ) {
			$state = self::cut_callbacks( $hook, $found, $state );
		} else {
			// Nothing to take off. The callback that saved again is off
			// already - WordPress runs each priority from a copy of its
			// callbacks, and a run that had it in hand before it went still
			// runs it - or it took itself off round its own save, or it saved
			// from this round but not from the one before: once more, not
			// round. Should it save again from inside this save, it goes then.
			return;
		}

		if ( ! $again ) {
			$state['first'] = self::loop_call( $culprit );
		}

		self::$loops['cut'][ $hook ] = $state;

		if ( ! $again ) {
			self::report_loop( $hook, $culprit, $id, ! $state['all'] );
		} elseif ( ! $was_all ) {
			// Each cut so far took off just the callback that saved again,
			// and the first warning said only the culprit was skipped.
			self::report_more( $hook, $culprit, $state['first'], $id, $state['all'] );
		}
	}

	/**
	 * Whether any of some callbacks on a hook is the one a frame of the
	 * backtrace was running.
	 *
	 * @param array      $callbacks Priority => id => callback, as on the hook.
	 * @param array|null $frame     From loop_culprit(); null when the backtrace
	 *                              has none, which counts as yes.
	 * @return bool
	 */
	private static function runs_any( array $callbacks, $frame ) {
		if ( ! is_array( $frame ) ) {
			return true;
		}

		foreach ( $callbacks as $at ) {
			foreach ( $at as $callback ) {
				if ( self::runs_frame( $callback, $frame ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Take some callbacks off a hook, the priority each was at held by a
	 * stand-in, and keep them to put back.
	 *
	 * @param string $hook  Hook name.
	 * @param array  $cut   Priority => id => callback, as on the hook.
	 * @param array  $state What has been cut from the hook so far.
	 * @return array $state, with these.
	 */
	private static function cut_callbacks( $hook, array $cut, array $state ) {
		global $wp_filter;

		foreach ( $cut as $priority => $callbacks ) {
			if ( ! isset( $state['order'][ $priority ] ) && isset( $wp_filter[ $hook ]->callbacks[ $priority ] ) ) {
				$state['order'][ $priority ] = array_keys( (array) $wp_filter[ $hook ]->callbacks[ $priority ] );
			}

			// The stand-in first, so the priority is never empty for a moment.
			add_filter( $hook, array( __CLASS__, 'hold_place' ), $priority, 1 );
			$state['held'][ $priority ] = true;

			foreach ( $callbacks as $id => $callback ) {
				if ( ! isset( $state['kept'][ $priority ][ $id ] ) ) {
					$state['kept'][ $priority ][ $id ] = $callback;
				}
				remove_filter( $hook, $callback['function'], $priority );
			}
		}

		return $state;
	}

	/**
	 * Take every callback but this one off a hook, and keep them to put back.
	 *
	 * @param string $hook  Hook name.
	 * @param array  $state What has been cut from the hook so far.
	 * @return array $state, with these.
	 */
	private static function cut_all( $hook, array $state ) {
		global $wp_filter;

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			$shared = false;

			if ( ! isset( $state['order'][ $priority ] ) ) {
				$state['order'][ $priority ] = array_keys( (array) $callbacks );
			}

			foreach ( (array) $callbacks as $id => $callback ) {
				if ( self::is_own_callback( $callback, 'on_save_hook' ) ) {
					$shared = true;
					continue;
				}
				if ( ! self::is_own_callback( $callback, 'hold_place' ) && ! isset( $state['kept'][ $priority ][ $id ] ) ) {
					$state['kept'][ $priority ][ $id ] = $callback;
				}
			}

			if ( ! $shared ) {
				remove_all_filters( $hook, $priority );
				continue;
			}

			// This one's own priority: everything else on it, one by one.
			foreach ( (array) $callbacks as $callback ) {
				if ( ! self::is_own_callback( $callback, 'on_save_hook' ) && isset( $callback['function'] ) ) {
					remove_filter( $hook, $callback['function'], $priority );
				}
			}
		}

		// The stand-ins went with the rest.
		$state['held'] = array();
		$state['all']  = true;

		return $state;
	}

	/**
	 * Whether a callback on a hook is one of the loop breaker's own.
	 *
	 * @param mixed  $callback One entry of WP_Hook::$callbacks.
	 * @param string $method   on_save_hook, or hold_place for a stand-in.
	 * @return bool
	 */
	private static function is_own_callback( $callback, $method ) {
		return is_array( $callback ) && isset( $callback['function'] ) && is_array( $callback['function'] )
			&& isset( $callback['function'][0], $callback['function'][1] )
			&& __CLASS__ === $callback['function'][0] && $method === $callback['function'][1];
	}

	/**
	 * The callbacks on a hook now that are the one a frame of the backtrace
	 * was running, the breaker's own and its stand-ins aside.
	 *
	 * @param string     $hook  Hook name.
	 * @param array|null $frame From loop_culprit(): its class and function,
	 *                          and the file and line of the call it made.
	 * @return array|null Priority => id => callback, empty when none of them
	 *                    is; null when there is no frame to go by.
	 */
	private static function loop_callbacks( $hook, $frame ) {
		global $wp_filter;

		if ( ! is_array( $frame ) ) {
			return null;
		}

		$now = array();
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( (array) $callbacks as $id => $callback ) {
				if ( ! self::is_own_callback( $callback, 'on_save_hook' ) && ! self::is_own_callback( $callback, 'hold_place' ) && self::runs_frame( $callback, $frame ) ) {
					$now[ $priority ][ $id ] = $callback;
				}
			}
		}

		return $now;
	}

	/**
	 * Whether a callback is the function a frame of the backtrace was running.
	 *
	 * A frame names a class and a method, or a function, but not the object.
	 * A method is matched by its name and its class, or a class it inherits
	 * from: the frame names the class the method is written in. A closure is
	 * matched by where it is written, since the call it made on the way to
	 * the save is inside it.
	 *
	 * @param mixed $callback One entry of WP_Hook::$callbacks.
	 * @param array $frame    From loop_culprit().
	 * @return bool
	 */
	private static function runs_frame( $callback, array $frame ) {
		if ( ! is_array( $callback ) || ! isset( $callback['function'] ) ) {
			return false;
		}

		$function = $callback['function'];
		$name     = strtolower( (string) $frame['function'] );
		$class    = (string) $frame['class'];

		if ( $function instanceof \Closure ) {
			$code = new \ReflectionFunction( $function );
			if ( '' === (string) $frame['file'] || ( 0 !== strpos( $name, '{closure' ) && strtolower( $code->getName() ) !== $name ) ) {
				return false;
			}
			return strtolower( self::plain_path( (string) $code->getFileName() ) ) === strtolower( self::plain_path( (string) $frame['file'] ) )
				&& (int) $frame['line'] >= (int) $code->getStartLine() && (int) $frame['line'] <= (int) $code->getEndLine();
		}

		if ( is_string( $function ) && false === strpos( $function, '::' ) ) {
			return '' === $class && strtolower( ltrim( $function, '\\' ) ) === $name;
		}

		if ( is_string( $function ) ) {
			$function = explode( '::', $function, 2 );
		} elseif ( is_object( $function ) ) {
			$function = array( $function, '__invoke' );
		}

		if ( ! is_array( $function ) || ! isset( $function[0], $function[1] ) || ! is_string( $function[1] )
			|| '' === $class || strtolower( $function[1] ) !== $name ) {
			return false;
		}

		$owner = is_object( $function[0] ) ? get_class( $function[0] ) : ltrim( (string) $function[0], '\\' );

		return 0 === strcasecmp( $owner, $class )
			|| ( ( is_object( $function[0] ) || class_exists( $owner, false ) ) && is_subclass_of( $owner, $class ) );
	}

	/**
	 * Put back what was taken off one hook, each callback in its place among
	 * those at its priority.
	 *
	 * With WordPress's add_filter(), which is what lets them run in the save
	 * already under way when this is called from on_save_hook(): WP_Hook
	 * re-points a run in progress to include what is added to it, and this
	 * one runs first of all. A callback can only be added at the end of its
	 * priority, so whatever came after it there is moved along behind it.
	 *
	 * @param string $hook Hook name.
	 */
	private static function restore_hook( $hook ) {
		global $wp_filter;

		if ( null === self::$loops || ! isset( self::$loops['cut'][ $hook ] ) ) {
			return;
		}

		$state = self::$loops['cut'][ $hook ];
		unset( self::$loops['cut'][ $hook ] );

		if ( ! function_exists( 'add_filter' ) || ! function_exists( 'remove_filter' ) ) {
			return;
		}

		$now = isset( $wp_filter[ $hook ] ) && is_object( $wp_filter[ $hook ] ) && isset( $wp_filter[ $hook ]->callbacks ) && is_array( $wp_filter[ $hook ]->callbacks )
			? $wp_filter[ $hook ]->callbacks
			: array();

		foreach ( $state['kept'] as $priority => $kept ) {
			$after = false;

			foreach ( isset( $state['order'][ $priority ] ) ? $state['order'][ $priority ] : array() as $id ) {
				$here = isset( $now[ $priority ][ $id ]['function'] ) && ! self::is_own_callback( $now[ $priority ][ $id ], 'hold_place' );

				if ( isset( $kept[ $id ] ) ) {
					$back = $kept[ $id ];
					unset( $kept[ $id ] );
				} elseif ( $after && $here ) {
					$back = $now[ $priority ][ $id ];
				} else {
					continue;
				}

				// Never the first put back, so the priority is never left empty.
				if ( $after && $here ) {
					remove_filter( $hook, $now[ $priority ][ $id ]['function'], $priority );
				}

				self::put_back( $hook, $back, $priority );
				$after = true;
			}

			// Anything kept that was not on the hook when it was first cut.
			foreach ( $kept as $callback ) {
				self::put_back( $hook, $callback, $priority );
			}
		}

		// The stand-ins last, for the same reason.
		foreach ( array_keys( $state['held'] ) as $priority ) {
			remove_filter( $hook, array( __CLASS__, 'hold_place' ), $priority );
		}
	}

	/**
	 * Add one callback back to a hook, as it was added before.
	 *
	 * @param string $hook     Hook name.
	 * @param mixed  $callback One entry of WP_Hook::$callbacks.
	 * @param int    $priority Its priority.
	 */
	private static function put_back( $hook, $callback, $priority ) {
		if ( is_array( $callback ) && isset( $callback['function'] ) ) {
			add_filter( $hook, $callback['function'], $priority, isset( $callback['accepted_args'] ) ? (int) $callback['accepted_args'] : 1 );
		}
	}

	/**
	 * Put back everything the loop breaker has taken off any hook.
	 */
	private static function restore_loops() {
		if ( null === self::$loops || ! self::$loops['cut'] ) {
			return;
		}

		foreach ( array_keys( self::$loops['cut'] ) as $hook ) {
			self::restore_hook( $hook );
		}
	}

	/**
	 * The product a hook was fired for, from its arguments.
	 *
	 * @param mixed $first  The hook's first argument.
	 * @param mixed $second Its second.
	 * @return int 0 when neither says.
	 */
	private static function loop_product_id( $first, $second ) {
		if ( is_numeric( $first ) ) {
			return (int) $first;
		}

		foreach ( array( $first, $second ) as $arg ) {
			if ( is_object( $arg ) && method_exists( $arg, 'get_id' ) ) {
				return (int) $arg->get_id();
			}
		}

		return 0;
	}

	/**
	 * Which plugin or theme made the hook go round: whose code, inside the
	 * run of the hook one level out, called the save that fired it again -
	 * which of that run's callbacks it was in, and which the run a level
	 * further out was in.
	 *
	 * Read from one backtrace, without arguments: the runs of this hook, told
	 * from every other by hook_runs(). Between this run and the one a level
	 * out is one round of the loop. The first code from the outside of it in
	 * that is a plugin's or a theme's - neither WordPress's, nor
	 * WooCommerce's, nor this plugin's - is what started the save again. It
	 * is named by its folder, and by the function the call was made from.
	 * Failing that, this plugin's code is named; failing that, WooCommerce's,
	 * if the callback itself is WooCommerce's.
	 *
	 * @param string $hook Hook name.
	 * @return array kind (plugin, mu-plugin, theme, tcgiant, woocommerce or
	 *               ''), slug, method; call, the callback the run one level
	 *               out was in; callback, what is needed to find it on the
	 *               hook, and previous, the same for the run a level further
	 *               out - each null when the backtrace has no such run.
	 */
	private static function loop_culprit( $hook ) {
		$none   = array( 'kind' => '', 'slug' => '', 'method' => '', 'call' => '', 'callback' => null, 'previous' => null );
		$frames = debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace

		list( $runs, $same ) = self::hook_runs( $frames, $hook );

		if ( ! $runs ) {
			return $none;
		}

		// This run is the innermost of any hook: the breaker is the first
		// thing it runs. Where the same hook is running one level out: if the
		// backtrace has no such run, the nearest run of any hook will do to
		// name the culprit - but not to say which callback to take off.
		$inner = $runs[0];
		$exact = isset( $same[1] );
		$outer = $exact ? $same[1] : ( isset( $runs[1] ) ? $runs[1] : count( $frames ) );

		$call = self::run_callback( $frames, $outer, $inner );
		if ( null !== $call ) {
			$none['call']   = self::frame_name( $frames[ $call ] );
			$none['method'] = $none['call'];
			if ( $exact ) {
				$none['callback'] = self::callback_frame( $frames, $call );
				$before           = isset( $same[2] ) ? self::run_callback( $frames, $same[2], $same[1] ) : null;
				$none['previous'] = null !== $before ? self::callback_frame( $frames, $before ) : null;
			}
		}

		$own = null;
		for ( $i = $outer - 1; $i > $inner; $i-- ) {
			$owner = self::code_owner( isset( $frames[ $i ]['file'] ) ? (string) $frames[ $i ]['file'] : '' );
			if ( null === $owner || 'woocommerce' === $owner['kind'] ) {
				continue;
			}

			$found = array(
				'kind'     => $owner['kind'],
				'slug'     => $owner['slug'],
				'method'   => self::frame_name( isset( $frames[ $i + 1 ] ) ? $frames[ $i + 1 ] : array() ),
				'call'     => $none['call'],
				'callback' => $none['callback'],
				'previous' => $none['previous'],
			);

			if ( 'tcgiant' !== $owner['kind'] ) {
				return $found;
			}

			if ( null === $own ) {
				$own = $found;
			}
		}

		if ( null !== $own ) {
			return $own;
		}

		// WooCommerce's own save code is in every round. Only a callback that
		// is itself WooCommerce's means WooCommerce saved again.
		if ( null !== $call && isset( $frames[ $call - 1 ]['file'] ) ) {
			$owner = self::code_owner( (string) $frames[ $call - 1 ]['file'] );
			if ( null !== $owner && 'woocommerce' === $owner['kind'] ) {
				$none['kind'] = 'woocommerce';
				$none['slug'] = $owner['slug'];
			}
		}

		// Otherwise nothing in a plugin's or a theme's folder: the callback the
		// run one level out was in can still be named.
		return $none;
	}

	/**
	 * The runs of hooks a backtrace is inside, from the innermost out: the
	 * frame of each - WP_Hook::apply_filters(), or do_all_hook() for the
	 * 'all' hook - and of those, the runs of one hook.
	 *
	 * WordPress runs each hook through a frame of apply_filters() on the
	 * hook's own WP_Hook object, so the runs of one hook are told from every
	 * other by that object - not by WordPress's list of the hooks running,
	 * which keeps the name of any hook a callback threw out of, as a queue
	 * runner that catches a failed action leaves it. A frame is gone the
	 * moment its run is, however it ended.
	 *
	 * @param array  $frames A backtrace, with the objects.
	 * @param string $hook   Hook name.
	 * @return array The runs of any hook, and of this one: two lists of
	 *               places in $frames.
	 */
	private static function hook_runs( array $frames, $hook ) {
		global $wp_filter;

		$object = isset( $wp_filter[ $hook ] ) && is_object( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ] : null;
		$runs   = array();
		$same   = array();

		foreach ( $frames as $i => $frame ) {
			if ( ! isset( $frame['class'] ) || 'WP_Hook' !== $frame['class']
				|| ( 'apply_filters' !== $frame['function'] && 'do_all_hook' !== $frame['function'] ) ) {
				continue;
			}
			$runs[] = $i;
			if ( null !== $object && 'apply_filters' === $frame['function'] && isset( $frame['object'] ) && $frame['object'] === $object ) {
				$same[] = $i;
			}
		}

		return array( $runs, $same );
	}

	/**
	 * The frame of the callback a run of a hook was in: the first frame
	 * inside it that is not PHP's own call_user_func.
	 *
	 * @param array $frames The backtrace.
	 * @param int   $run    The run's WP_Hook frame.
	 * @param int   $inner  The next run in, which the callback led to.
	 * @return int|null
	 */
	private static function run_callback( array $frames, $run, $inner ) {
		for ( $i = $run - 1; $i > $inner; $i-- ) {
			$function = isset( $frames[ $i ]['function'] ) ? (string) $frames[ $i ]['function'] : '';
			if ( 'call_user_func_array' !== $function && 'call_user_func' !== $function ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * What runs_frame() needs of a callback's frame: its class and function,
	 * and the file and line of the call it made.
	 *
	 * @param array $frames The backtrace.
	 * @param int   $call   The callback's frame.
	 * @return array
	 */
	private static function callback_frame( array $frames, $call ) {
		return array(
			'class'    => isset( $frames[ $call ]['class'] ) ? (string) $frames[ $call ]['class'] : '',
			'function' => isset( $frames[ $call ]['function'] ) ? (string) $frames[ $call ]['function'] : '',
			'file'     => isset( $frames[ $call - 1 ]['file'] ) ? (string) $frames[ $call - 1 ]['file'] : '',
			'line'     => isset( $frames[ $call - 1 ]['line'] ) ? (int) $frames[ $call - 1 ]['line'] : 0,
		);
	}

	/**
	 * Class::method, or the function, a backtrace frame is running.
	 *
	 * @param array $frame One frame.
	 * @return string '' when there is none.
	 */
	private static function frame_name( array $frame ) {
		$function = isset( $frame['function'] ) ? (string) $frame['function'] : '';

		// PHP 8.4 names a closure by its file and line; the file is said
		// already.
		if ( 0 === strpos( $function, '{closure' ) ) {
			$function = '{closure}';
		}

		if ( '' === $function || in_array( $function, array( 'include', 'include_once', 'require', 'require_once' ), true ) ) {
			return '';
		}

		return isset( $frame['class'] ) && '' !== (string) $frame['class'] ? $frame['class'] . '::' . $function : $function;
	}

	/**
	 * Whose code a file is: a plugin's, a must-use plugin's or a theme's, by
	 * its folder - or WooCommerce's, or this plugin's.
	 *
	 * @param string $file Path.
	 * @return array|null kind, slug; null for WordPress itself, a drop-in,
	 *                    or anything else outside those folders.
	 */
	private static function code_owner( $file ) {
		global $wp_plugin_paths;

		$file = self::plain_path( $file );

		if ( '' === $file ) {
			return null;
		}

		$own = self::plain_path( dirname( __DIR__ ) );
		if ( '' !== $own && self::path_in( $file, $own ) ) {
			return array( 'kind' => 'tcgiant', 'slug' => basename( $own ) );
		}

		if ( defined( 'WC_ABSPATH' ) ) {
			$wc = self::plain_path( (string) WC_ABSPATH );
			if ( '' !== $wc && self::path_in( $file, $wc ) ) {
				return array( 'kind' => 'woocommerce', 'slug' => basename( $wc ) );
			}
		}

		// A host that keeps plugins elsewhere and links their folders into the
		// plugins folder - WordPress.com does, from /wordpress/plugins/<name>/
		// <version> - has PHP name the file where it really is. WordPress
		// keeps each linked folder in $wp_plugin_paths, and plugin_basename()
		// reads it the same way, the longest match first.
		if ( is_array( $wp_plugin_paths ) ) {
			$real   = '';
			$linked = '';
			foreach ( $wp_plugin_paths as $dir => $realdir ) {
				$path = self::plain_path( $realdir );
				if ( '' !== $path && strlen( $path ) > strlen( $real ) && self::path_in( $file, $path ) ) {
					$real   = $path;
					$linked = self::plain_path( $dir );
				}
			}
			if ( '' !== $real && '' !== $linked ) {
				$file = $linked . substr( $file, strlen( $real ) );
			}
		}

		$roots = array(
			array( 'plugin', defined( 'WP_PLUGIN_DIR' ) ? (string) WP_PLUGIN_DIR : '' ),
			array( 'mu-plugin', defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : '' ),
		);
		foreach ( self::theme_roots() as $root ) {
			$roots[] = array( 'theme', $root );
		}

		foreach ( $roots as $root ) {
			$kind = $root[0];
			$path = self::plain_path( $root[1] );
			if ( '' === $path || ! self::path_in( $file, $path ) ) {
				continue;
			}

			$rest = substr( $file, strlen( $path ) + 1 );
			$cut  = strpos( $rest, '/' );
			$slug = false === $cut ? $rest : substr( $rest, 0, $cut );

			if ( 'plugin' === $kind && 'woocommerce' === strtolower( $slug ) ) {
				return array( 'kind' => 'woocommerce', 'slug' => $slug );
			}

			return array( 'kind' => $kind, 'slug' => $slug );
		}

		// Not linked, but still in a folder named plugins, outside WordPress's
		// own: the copies a host loads itself - WordPress.com's managed plugins,
		// and its own must-use plugin.
		$wp = defined( 'ABSPATH' ) ? self::plain_path( (string) ABSPATH ) : '';
		if ( ( '' === $wp || ! self::path_in( $file, $wp ) ) && preg_match( '#/plugins/([^/]+)/#', $file, $m ) ) {
			return array( 'kind' => 'woocommerce' === strtolower( $m[1] ) ? 'woocommerce' : 'plugin', 'slug' => $m[1] );
		}

		return null;
	}

	/**
	 * Every folder themes are kept in: WordPress's own, and any added with
	 * register_theme_directory() - by a host, or by a plugin.
	 *
	 * @return string[]
	 */
	private static function theme_roots() {
		$roots = array();

		if ( isset( $GLOBALS['wp_theme_directories'] ) && is_array( $GLOBALS['wp_theme_directories'] ) ) {
			foreach ( $GLOBALS['wp_theme_directories'] as $root ) {
				if ( is_string( $root ) ) {
					$roots[] = $root;
				}
			}
		}

		if ( function_exists( 'get_theme_root' ) ) {
			$roots[] = (string) get_theme_root();
		} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[] = WP_CONTENT_DIR . '/themes';
		}

		return array_values( array_unique( $roots ) );
	}

	/**
	 * Whether a file is inside a folder, both as plain_path() gives them.
	 *
	 * @param string $file   Path.
	 * @param string $folder Folder.
	 * @return bool
	 */
	private static function path_in( $file, $folder ) {
		return 0 === strpos( strtolower( $file ), strtolower( $folder ) . '/' );
	}

	/**
	 * A path with forward slashes and no slash at the end.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function plain_path( $path ) {
		return rtrim( (string) preg_replace( '#/+#', '/', str_replace( '\\', '/', (string) $path ) ), '/' );
	}

	/**
	 * The name a shop owner knows a plugin or theme by, from its own header,
	 * with the folder it is in.
	 *
	 * @param array $culprit From loop_culprit().
	 * @return string E.g. 'the plugin "Some SEO" (folder some-seo)'.
	 */
	private static function culprit_name( array $culprit ) {
		$slug = (string) $culprit['slug'];
		$name = '';

		try {
			if ( 'plugin' === $culprit['kind'] && defined( 'WP_PLUGIN_DIR' ) && function_exists( 'get_option' ) && function_exists( 'get_file_data' ) ) {
				$active = (array) get_option( 'active_plugins', array() );
				if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
					$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
				}
				foreach ( $active as $main ) {
					$main = (string) $main;
					if ( $main === $slug || 0 === strpos( $main, $slug . '/' ) ) {
						$header = is_readable( WP_PLUGIN_DIR . '/' . $main ) ? get_file_data( WP_PLUGIN_DIR . '/' . $main, array( 'name' => 'Plugin Name' ) ) : array();
						$name   = isset( $header['name'] ) ? trim( (string) $header['name'] ) : '';
						break;
					}
				}
			} elseif ( 'theme' === $culprit['kind'] && function_exists( 'wp_get_theme' ) ) {
				$theme = wp_get_theme( $slug );
				$name  = is_object( $theme ) && method_exists( $theme, 'exists' ) && $theme->exists() ? trim( (string) $theme->get( 'Name' ) ) : '';
			}
		} catch ( \Throwable $e ) {
			$name = '';
		}

		switch ( $culprit['kind'] ) {
			case 'plugin':
				return '' !== $name ? sprintf( 'the plugin "%s" (folder %s)', $name, $slug ) : sprintf( 'the plugin in the folder %s', $slug );
			case 'mu-plugin':
				return sprintf( 'the must-use plugin %s', $slug );
			case 'theme':
				return '' !== $name ? sprintf( 'the theme "%s" (folder %s)', $name, $slug ) : sprintf( 'the theme in the folder %s', $slug );
		}

		return '';
	}

	/**
	 * Say once, for each plugin that caused one, that a save went round and
	 * was stopped - in words a shop owner can act on.
	 *
	 * @param string $hook       The hook that went round.
	 * @param array  $culprit    From loop_culprit().
	 * @param int    $product_id The product being saved.
	 * @param bool   $one        Only the callback that saved again was taken
	 *                           off - not everything after the breaker.
	 */
	private static function report_loop( $hook, array $culprit, $product_id, $one ) {
		// Code that is no plugin's or theme's is told apart by its callback.
		$key = $culprit['kind'] . ':' . $culprit['slug'] . ( '' === $culprit['kind'] ? ':' . $culprit['method'] : '' );

		// Said already. Counted, and moved to the end: the record of a death
		// names the last loop stopped.
		if ( isset( self::$loop_culprits[ $key ] ) ) {
			$seen = self::$loop_culprits[ $key ];
			$seen['times']++;
			unset( self::$loop_culprits[ $key ] );
			self::$loop_culprits[ $key ] = $seen;
			return;
		}

		$who = self::loop_who( $culprit );

		self::$loop_culprits[ $key ] = array(
			'who'    => $who,
			'method' => (string) $culprit['method'],
			'hook'   => (string) $hook,
			'times'  => 1,
		);

		$saving = self::loop_saving( $product_id );
		$from   = '' !== $culprit['method'] ? ' (' . $culprit['method'] . ')' : '';

		// The stock hooks follow a stock change, not every save.
		$fired = in_array( $hook, array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ), true ) ? 'every stock change fired' : 'every save fired';

		// What stopping it cost: the one callback that saved again, or - when
		// that could not be told - whatever runs on the hook after it. Should
		// it cost more later in the save, report_more() says so.
		$call = self::loop_call( $culprit );
		if ( $one ) {
			$stopped = sprintf( 'TCGiant stopped it after %d rounds by skipping %s for the rest of that save; the product was saved, and everything else on %s still ran.', self::LOOP_DEPTH, $call, $hook );
		} else {
			$stopped = sprintf( 'TCGiant stopped it after %d rounds; the product was saved, but what other plugins do on %s after %s was skipped for this save.', self::LOOP_DEPTH, $hook, $call );
		}

		if ( 'tcgiant' === $culprit['kind'] || 'woocommerce' === $culprit['kind'] ) {
			$message = sprintf(
				'%s went into a loop: %s %s, and %s saved the product again from it%s. %s Please send this log to TCGiant support.',
				$saving,
				$fired,
				$hook,
				$who,
				$from,
				$stopped
			);
		} elseif ( '' !== $who ) {
			$message = sprintf(
				'%s went into a loop: %s %s, and %s saved the product again from it%s. %s This loop is in %s, not in TCGiant, and it can slow every product save on this site - update or deactivate it, or ask its author.',
				$saving,
				$fired,
				$hook,
				$who,
				$from,
				$stopped,
				$who
			);
		} else {
			$message = sprintf(
				'%s went into a loop: %s %s, and something hooked to it saved the product again%s, from code outside any plugin or theme folder. %s It can slow every product save on this site - deactivating plugins one at a time while a sync runs will show which one it is.',
				$saving,
				$fired,
				$hook,
				$from,
				$stopped
			);
		}

		if ( class_exists( 'TCGiant_Sync_Logger' ) ) {
			TCGiant_Sync_Logger::warning( $message );
		}
	}

	/**
	 * Say once, for each hook and each callback that carried a loop on, what
	 * more stopping it cost than the warning about its culprit said - which
	 * was that only the culprit was skipped.
	 *
	 * @param string $hook       The hook that went round.
	 * @param array  $culprit    From loop_culprit(), for the round just cut.
	 * @param string $first      The callback the first cut skipped.
	 * @param int    $product_id The product being saved.
	 * @param bool   $all        Everything on the hook but the breaker was
	 *                           taken off, not just the callback that saved
	 *                           again.
	 */
	private static function report_more( $hook, array $culprit, $first, $product_id, $all ) {
		$call = self::loop_call( $culprit );
		$key  = ( $all ? 'all' : 'one' ) . ':' . $hook . ':' . $call;

		// The culprit, put back and taken off again, is skipped as was said.
		if ( ( ! $all && $call === $first ) || isset( self::$loop_more[ $key ] ) ) {
			return;
		}
		self::$loop_more[ $key ] = true;

		$who  = self::loop_who( $culprit );
		$from = '' !== $culprit['method'] ? ' (' . $culprit['method'] . ')' : '';
		$by   = '' !== $who
			? sprintf( '%s saved the product again from it as well%s', $who, $from )
			: sprintf( 'something else hooked to it saved the product again%s', $from );

		if ( $all ) {
			$message = sprintf(
				'%s kept looping on %s after TCGiant skipped %s%s. TCGiant stopped it after %d rounds; the product was saved, but what other plugins had still to do on %s in that save was skipped.',
				self::loop_saving( $product_id ),
				$hook,
				$first,
				$call !== $first ? ': ' . $by : '',
				self::running_count( $hook, true, $product_id, PHP_INT_MAX ),
				$hook
			);
		} else {
			$message = sprintf(
				'%s kept looping on %s after TCGiant skipped %s: %s. TCGiant skipped %s too, for the rest of that save.',
				self::loop_saving( $product_id ),
				$hook,
				$first,
				$by,
				$call
			);
		}

		if ( class_exists( 'TCGiant_Sync_Logger' ) ) {
			TCGiant_Sync_Logger::warning( $message );
		}
	}

	/**
	 * Who a culprit is, in words a shop owner knows.
	 *
	 * @param array $culprit From loop_culprit().
	 * @return string '' for code outside any plugin or theme folder.
	 */
	private static function loop_who( array $culprit ) {
		if ( 'tcgiant' === $culprit['kind'] ) {
			return 'TCGiant itself';
		}
		if ( 'woocommerce' === $culprit['kind'] ) {
			return 'WooCommerce itself';
		}

		return '' !== $culprit['kind'] ? self::culprit_name( $culprit ) : '';
	}

	/**
	 * The callback a culprit's loop went round through, by name.
	 *
	 * @param array $culprit From loop_culprit().
	 * @return string
	 */
	private static function loop_call( array $culprit ) {
		return '' !== $culprit['call'] ? $culprit['call'] : ( '' !== $culprit['method'] ? $culprit['method'] : 'it' );
	}

	/**
	 * How a warning about a save loop starts: the product, and its listing.
	 *
	 * @param int $product_id The product being saved.
	 * @return string E.g. 'Saving WC #101 (eBay item 297598275224)'.
	 */
	private static function loop_saving( $product_id ) {
		$item = '';
		if ( $product_id > 0 && function_exists( 'get_post_meta' ) ) {
			$item = (string) get_post_meta( $product_id, '_ebay_item_id', true );
			if ( '' === $item && function_exists( 'wp_get_post_parent_id' ) && wp_get_post_parent_id( $product_id ) ) {
				$item = (string) get_post_meta( (int) wp_get_post_parent_id( $product_id ), '_ebay_item_id', true );
			}
		}

		return $product_id > 0
			? sprintf( 'Saving WC #%d%s', $product_id, '' !== $item ? ' (eBay item ' . $item . ')' : '' )
			: 'Saving a product';
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

		// A job that died never reached its unguard_loops(). Whatever the loop
		// breaker took off a hook goes back now that there is room for it, so
		// what still runs - WooCommerce's deferred product syncs on the
		// shutdown action, other plugins' own shutdown work - finds every
		// plugin's callbacks where they were. Once is enough: this runs from
		// the shutdown action and again from on_shutdown().
		self::restore_loops();
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
			'%s %s%s%s. %d s into the request; memory peak %d MB of %s (%d MB in use); time limit %d s.%s',
			$watch['job'],
			$how,
			'' !== $watch['context'] ? ' while working on ' . $watch['context'] : '',
			'' !== $watch['stage'] ? ' - ' . $watch['stage'] : '',
			(int) self::elapsed(),
			(int) round( memory_get_peak_usage( true ) / 1048576 ),
			$limit_was,
			(int) round( memory_get_peak_usage() / 1048576 ),
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
	 * plugin's work on a product save shows here, and a save loop - and what
	 * the object cache was holding. Counting allocates next to nothing, so
	 * this is safe after running out of memory.
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

			// Six names cannot show a hook running inside itself a thousand
			// times over - a save loop the breaker was not watching for, or
			// that ran while it was off.
			$counts  = array();
			$deepest = '';
			foreach ( $hooks as $name ) {
				if ( is_string( $name ) ) {
					$counts[ $name ] = isset( $counts[ $name ] ) ? $counts[ $name ] + 1 : 1;
					if ( '' === $deepest || $counts[ $name ] > $counts[ $deepest ] ) {
						$deepest = $name;
					}
				}
			}
			// Only a product-save hook doing it is a save loop.
			if ( '' !== $deepest && $counts[ $deepest ] >= self::LOOP_DEPTH ) {
				$parts[] = in_array( $deepest, self::LOOP_HOOKS, true )
					? sprintf( '%s was running %d times inside itself - a save loop', $deepest, $counts[ $deepest ] )
					: sprintf( '%s was running %d times at once - a hook running inside itself', $deepest, $counts[ $deepest ] );
			}
			unset( $counts );
		}

		// Save loops stopped earlier in the request, and what caused them.
		if ( self::$loop_culprits ) {
			$stopped = 0;
			foreach ( self::$loop_culprits as $seen ) {
				$stopped += (int) $seen['times'];
			}
			$last    = end( self::$loop_culprits );
			$parts[] = sprintf(
				'%d save loop(s) stopped in this request, the last caused by %s%s, on %s',
				$stopped,
				'' !== $last['who'] ? $last['who'] : 'code outside any plugin or theme folder',
				'' !== $last['method'] ? ' in ' . $last['method'] : '',
				$last['hook']
			);
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
