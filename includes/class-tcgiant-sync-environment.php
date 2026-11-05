<?php
/**
 * Whether this site may send changes to eBay.
 *
 * A staging copy of a shop carries the live shop's eBay connection with it.
 * Until 3.28.0 the only thing standing between a copy and the seller's real
 * listings was a check of the web request's host name against a short list of
 * substrings, and it was wrong in both directions:
 *
 * - Copies at staging.example.com, staging1.example.com, dev.example.com and
 *   Kinsta's stg- addresses were judged live, and so was every WP-CLI and
 *   system-cron run, which has no host name at all. Those copies ended live
 *   listings, changed live quantities and marked real orders shipped.
 * - Live shops whose own name happened to contain one of the substrings -
 *   webdev-supplies.com, shop.localfoo.com.au - were judged copies, and sent
 *   nothing to eBay for as long as they ran the plugin.
 * - Only Trading API calls on a fixed list were stopped. The Inventory API
 *   quantity update went out from copies regardless.
 *
 * This class makes the decision in one place, from the site's own stored
 * address rather than from whatever host name a request arrived on, so a web
 * request, WP-CLI and system cron all get the same answer. The API class asks
 * it before anything that would change something on eBay, and everything that
 * only reads from eBay carries on as normal: a copy still imports, and still
 * takes sold items off.
 *
 * Since 3.31.0 the eBay connection is also bound to the address it was made
 * at. A copy whose address no pattern recognises - a backup restored under
 * another domain, a host's temporary address - is still somewhere else than
 * the shop it was copied from, and sends nothing until an administrator says
 * it is the live shop (moved()). It cannot protect a copy made before the
 * update: that copy binds itself to its own address at its first request.
 *
 * It is required directly from tcgiant-sync.php, before the autoloader exists,
 * so it uses nothing that is not already loaded when plugins are.
 *
 * It also says whether the PHP the site runs on comes before the fix for
 * PHP2Shell (php2shell(), 3.28.4), for the dashboard's health panel.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Environment class
 */
final class TCGiant_Sync_Environment {

	/**
	 * The verdict as last recorded, so a change can be noticed and logged.
	 * Read on admin requests only, so it is not autoloaded.
	 */
	const VERDICT_OPTION = 'tcgiant_sync_env_verdict';

	/**
	 * An administrator's word that the shop at this address is the live one.
	 *
	 * Only a hash of the address is kept. A migration tool's search-and-replace
	 * rewrites every copy of the old domain it can find in the database, and a
	 * plain address stored here would be rewritten into the copy's own - which
	 * would then confirm itself.
	 */
	const CONFIRMED_OPTION = 'tcgiant_sync_live_confirmed';

	/**
	 * The addresses the eBay connection belongs to (3.31.0).
	 *
	 * Recorded when an account is connected, when "This is my live shop" is
	 * pressed, and - for a shop already connected when it updates to 3.31.0 -
	 * at its first request after the update. A copy of the shop carries this
	 * with its database, and its own address is not on it, so it sends
	 * nothing to eBay until an administrator says it is the live shop: a
	 * copy at an address none of the patterns above recognise, such as a
	 * backup restored under another domain, included.
	 *
	 * Each address is kept as a hash, to compare, and base64-encoded, to name
	 * it in the notice. A migration tool's search-and-replace rewrites the old
	 * domain wherever it finds it written out, and a plain address here would
	 * become the copy's own - binding the copy to itself. Neither form has a
	 * dot in it for the search to find.
	 *
	 * Autoloaded: boot() reads it on every request.
	 */
	const BOUND_OPTION = 'tcgiant_sync_bound_address';

	/**
	 * Addresses kept on that list, the most recent first. A shop that moves
	 * keeps its earlier addresses, so moving back needs no second press.
	 */
	const BOUND_KEEP = 10;

	/**
	 * Hosts' temporary and staging domains (3.31.0, fix round 1): addresses a
	 * shop's staging copy lives at, which no pattern recognises. A copy there
	 * that updates to 3.31.0 before the live shop binds itself to its own
	 * address, and a push of its database to live carries that list over -
	 * see rebind_after_staging_push().
	 */
	const TEMPORARY_HOSTS = array(
		'cloudwaysapps.com',    // Cloudways
		'flywheelstaging.com',  // Flywheel
		'flywheelsites.com',    // Flywheel
		'mystagingwebsite.com', // Pressable
		'myftpupload.com',      // GoDaddy
		'instawp.xyz',          // InstaWP
		'wpcomstaging.com',     // WordPress.com
		'pantheonsite.io',      // Pantheon
		'kinsta.cloud',         // Kinsta
		'wpengine.com',         // WP Engine
		'wpenginepowered.com',  // WP Engine
	);

	/**
	 * An address the administrator has been emailed about: one row each,
	 * named this and the address's hash, never autoloaded (3.31.0, fix round
	 * 2). The row is the lock - see mail_move().
	 */
	const MOVE_MAILED_PREFIX = 'tcgiant_sync_move_mailed_';

	/**
	 * What a staging-push rebind replaced in this request, for the log line
	 * written once the logger has loaded (fix round 2): array( was, now ).
	 *
	 * @var array|null
	 */
	private static $rebound = null;

	/**
	 * How many changes were held back in the last day, for the notice.
	 */
	const BLOCKED_TRANSIENT = 'tcgiant_sync_blocked_writes';

	/**
	 * Words that name a copy when they make up a whole subdomain, a whole first
	 * folder of the address, or (the shorter ones) one end of a subdomain.
	 *
	 * 'qa' is left out on purpose: it is Qatar's country code, and shops use it
	 * as a subdomain.
	 */
	const COPY_WORDS = 'staging|stage|stg|dev|development|test|testing|uat|preprod';
	const EDGE_WORDS = 'staging|stage|stg|dev|test';

	/**
	 * Top-level domains that never reach the public internet. Not .dev, which
	 * is a real one that anybody can buy.
	 */
	const LOCAL_TLDS = array( 'local', 'localhost', 'test', 'example', 'invalid', 'internal', 'localdomain', 'lan' );

	/**
	 * Domains that only ever point at the computer they run on: the local
	 * development tools DDEV, Lando and Docksal.
	 */
	const LOCAL_DOMAINS = array( 'ddev.site', 'lndo.site', 'docksal.site' );

	/**
	 * Second-level names that sit under a two-letter country code, as in
	 * co.uk or com.au. Under these the shop's own name is the third label from
	 * the end, not the second.
	 */
	const SECOND_LEVEL = 'co|com|net|org|gov|edu|ac|ltd|plc|me|or|ne|id|gen|biz|info';

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * What wp-config.php set TCGIANT_SYNC_IS_STAGING to, or null if it did not.
	 *
	 * @var bool|null
	 */
	private static $explicit = null;

	/**
	 * This request's verdict: '' for live, otherwise why it is a copy.
	 *
	 * @var string
	 */
	private static $reason = '';

	/**
	 * Reasons already written to the log in this request.
	 *
	 * @var array<string,bool>
	 */
	private static $noted = array();

	/**
	 * Decide, once, as the plugin loads.
	 *
	 * A define of TCGIANT_SYNC_IS_STAGING in wp-config.php wins either way, as
	 * it always has: true keeps a site off eBay whatever its address, and false
	 * lets it send whatever its address looks like.
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		if ( defined( 'TCGIANT_SYNC_IS_STAGING' ) ) {
			self::$explicit = (bool) TCGIANT_SYNC_IS_STAGING;
			self::$reason   = self::$explicit ? 'wp-config' : '';
			return;
		}

		self::$reason = self::copy_reason();

		// The live shop, with the list a staging copy recorded for itself at
		// the update and then pushed here with its database (fix round 1):
		// bound to this address instead, and live.
		if ( 'address:moved' === self::$reason && self::rebind_after_staging_push() ) {
			self::$reason = self::copy_reason();

			// The logger loads with the plugin's autoloader, after this file:
			// the line is written on init (fix round 2).
			if ( function_exists( 'add_action' ) ) {
				add_action( 'init', array( __CLASS__, 'log_rebind' ) );
			}
		}

		// A site no longer at the address its connection was made at: its
		// administrator is told by email too, once an address (fix round 1).
		if ( 'address:moved' === self::$reason && function_exists( 'add_action' ) ) {
			add_action( 'init', array( __CLASS__, 'mail_move' ) );
		}

		// A shop already connected when it updated to 3.31.0 has no address
		// on record: it is bound to the one it has now - only when nothing
		// else says it is a copy, so a copy is never bound to itself.
		if ( '' === self::$reason ) {
			self::bind_if_unbound();
		}

		define( 'TCGIANT_SYNC_IS_STAGING', '' !== self::$reason );
	}

	/**
	 * This request's verdict, as decided when the plugin loaded.
	 *
	 * @return string '' when the site is live, otherwise the reason it is not.
	 */
	public static function reason() {
		self::boot();
		return self::$reason;
	}

	/**
	 * The site's address, the same in a web request, WP-CLI and system cron.
	 *
	 * The stored value is read straight out of the options, without filters,
	 * so a plugin that changes the home address per request (a language on its
	 * own domain, for instance) cannot change the verdict. The request's own
	 * host name is never used: PHP's command line leaves it unset, and the
	 * WP-CLI handbook's shim sets it to a placeholder.
	 *
	 * @return string
	 */
	public static function current_url() {
		if ( defined( 'WP_HOME' ) && is_string( WP_HOME ) && '' !== WP_HOME ) {
			return WP_HOME;
		}

		if ( function_exists( 'wp_load_alloptions' ) ) {
			$all = wp_load_alloptions();
			if ( is_array( $all ) && ! empty( $all['home'] ) && is_string( $all['home'] ) ) {
				return $all['home'];
			}
		}

		return function_exists( 'get_option' ) ? (string) get_option( 'siteurl' ) : '';
	}

	/**
	 * An address reduced to what identifies the site.
	 *
	 * Lower case, no scheme, no leading www., no trailing dot or slash, and the
	 * port only when it is not 80 or 443. So moving to https, or between www
	 * and the bare domain, is the same address.
	 *
	 * @param string $url A full URL.
	 * @return string
	 */
	public static function address( $url ) {
		$parts = self::parse( $url );
		if ( '' === $parts['host'] ) {
			return '';
		}

		$host = preg_replace( '/^www\./', '', $parts['host'] );
		$port = ( $parts['port'] && ! in_array( $parts['port'], array( 80, 443 ), true ) ) ? ':' . $parts['port'] : '';

		return $host . $port . self::lower( rtrim( $parts['path'], '/' ) );
	}

	/**
	 * Whether an address looks like a copy, and by which rule.
	 *
	 * The shop's own registered name is never examined - only the subdomains in
	 * front of it, and the first folder after it. That is what lets
	 * webdev-supplies.com, dev-supplies.com and test.co.uk be live shops while
	 * dev.example.com is a copy.
	 *
	 * The rule names are built only from the fixed words above, never from the
	 * address itself, because they end up in error messages that other code
	 * reads (see message()).
	 *
	 * @param string $url A full URL.
	 * @return string '' when it looks live, otherwise the rule that matched.
	 */
	public static function copy_rule( $url ) {
		$parts = self::parse( $url );
		$host  = $parts['host'];
		if ( '' === $host ) {
			return '';
		}

		$bare = trim( $host, '[]' );
		if ( 'localhost' === $bare ) {
			return 'localhost';
		}
		if ( false !== filter_var( $bare, FILTER_VALIDATE_IP ) ) {
			return self::is_private_ip( $bare ) ? 'private-ip' : '';
		}

		$labels = explode( '.', preg_replace( '/^www\./', '', $host ) );
		$count  = count( $labels );
		$tld    = $labels[ $count - 1 ];

		if ( in_array( $tld, self::LOCAL_TLDS, true ) ) {
			return 'tld:' . $tld;
		}

		// A bare machine name - http://devbox, http://wordpress:8080 - is only
		// reachable on the network it is on. No live shop has one.
		if ( $count < 2 ) {
			return 'single-label';
		}

		$own         = ( $count >= 3 && 2 === strlen( $tld ) && 1 === preg_match( '/^(?:' . self::SECOND_LEVEL . ')$/', $labels[ $count - 2 ] ) ) ? 3 : 2;
		$registrable = implode( '.', array_slice( $labels, -$own ) );
		$subdomains  = array_slice( $labels, 0, max( 0, $count - $own ) );

		if ( in_array( $registrable, self::LOCAL_DOMAINS, true ) ) {
			return 'local-domain';
		}

		foreach ( $subdomains as $label ) {
			if ( preg_match( '/^(' . self::COPY_WORDS . ')\d*$/', $label, $m ) ) {
				return 'label:' . $m[1];
			}
			if ( preg_match( '/^(' . self::EDGE_WORDS . ')\d*-/', $label, $m ) ) {
				return 'prefix:' . $m[1];
			}
			if ( preg_match( '/-(' . self::EDGE_WORDS . ')\d*$/', $label, $m ) ) {
				return 'suffix:' . $m[1];
			}
		}

		// Host conventions the general rules cannot express without catching
		// live shops: Kinsta's premium staging is env-<site>-<env>.kinsta.cloud,
		// and WP Engine names environments <site>stg and <site>dev.
		if ( 'kinsta.cloud' === $registrable && $subdomains && 0 === strpos( $subdomains[0], 'env-' ) ) {
			return 'kinsta:env';
		}
		if ( in_array( $registrable, array( 'wpengine.com', 'wpenginepowered.com' ), true ) && $subdomains && preg_match( '/(stg|dev)$/', $subdomains[0], $m ) ) {
			return 'wpengine:' . $m[1];
		}

		$segments = explode( '/', trim( $parts['path'], '/' ) );
		$first    = self::lower( $segments[0] );
		if ( '' !== $first && preg_match( '/^(' . self::COPY_WORDS . ')\d*$/', $first, $m ) ) {
			return 'path:' . $m[1];
		}

		return '';
	}

	/**
	 * Why this site should not send changes to eBay, worked out afresh.
	 *
	 * In order: wp-config.php; WordPress's own environment type, which only an
	 * edit to wp-config.php or the host's settings can change; the address,
	 * which an administrator can overrule for this one address with the "This
	 * is my live shop" button; and last, whether the site is still at an
	 * address its eBay connection was made or confirmed at (3.31.0), which
	 * the same button answers.
	 *
	 * @return string '' when the site is live, otherwise the reason it is not.
	 */
	public static function copy_reason() {
		$reason = self::reason_before_binding();
		if ( '' !== $reason ) {
			return $reason;
		}

		return self::moved() ? 'address:moved' : '';
	}

	/**
	 * copy_reason() without the address binding: wp-config.php, the
	 * environment type and the address patterns.
	 *
	 * @return string '' when none of them says this is a copy.
	 */
	private static function reason_before_binding() {
		if ( null !== self::$explicit ) {
			return self::$explicit ? 'wp-config' : '';
		}

		$type = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production';
		if ( 'production' !== $type ) {
			return 'environment:' . $type;
		}

		$url = self::current_url();

		// From the command line, a wp-config.php that builds WP_HOME from the
		// request's host name, with no --url, gives no real address: no host
		// at all, the WP-CLI handbook's placeholder, or - on Bitnami, which
		// AWS Lightsail ships - 127.0.0.1. Judged on that, a live shop's
		// WP-CLI runs read as a copy and its stock pushes were dropped, and a
		// copy's read as live. Such a run goes by what the last admin page
		// load decided, from the shop's real address - and is live when none
		// has yet, as every command-line run was before 3.28.0.
		//
		// Not the binding's verdict, though: that is worked out afresh from the
		// stored address, which such a run reads like any other (copy_reason()).
		if ( self::home_from_request( $url ) ) {
			$stored = function_exists( 'get_option' ) ? get_option( self::VERDICT_OPTION, null ) : null;
			$was    = is_array( $stored ) && ! empty( $stored['copy'] ) ? (string) ( $stored['reason'] ?? '' ) : '';
			return self::is_address_reason( $was ) && 'address:moved' !== $was ? $was : '';
		}

		$rule = self::copy_rule( $url );
		if ( '' !== $rule && ! self::confirmed_here( $url ) ) {
			return 'address:' . $rule;
		}

		return '';
	}

	/**
	 * Whether this is a command-line run whose WP_HOME was built from a host
	 * name the command line made up, rather than one that names the site.
	 *
	 * WP-CLI's --url, a wp-cli.yml url: or an @alias puts the site's own host
	 * name in HTTP_HOST before wp-config.php runs, so WP_HOME matching it
	 * proves nothing: that run names the site, and its address decides. Only
	 * the names a wp-config.php makes up for the command line give no
	 * address - no host at all, Bitnami's 127.0.0.1, localhost, a bare
	 * machine name, the WP-CLI handbook's example.com - and none of those
	 * carries a port.
	 *
	 * @param string $url The address current_url() gave.
	 * @return bool
	 */
	private static function home_from_request( $url ) {
		if ( 'cli' !== PHP_SAPI || ! defined( 'WP_HOME' ) || WP_HOME !== $url ) {
			return false;
		}

		$parts = self::parse( $url );
		if ( '' === $parts['host'] ) {
			return true;
		}

		$request = isset( $_SERVER['HTTP_HOST'] ) ? self::parse( (string) $_SERVER['HTTP_HOST'] )['host'] : '';
		if ( '' === $request || $parts['host'] !== $request || 0 !== $parts['port'] ) {
			return false;
		}

		return in_array( self::copy_rule( $url ), array( 'localhost', 'private-ip', 'single-label' ), true )
			|| in_array( preg_replace( '/^www\./', '', $parts['host'] ), array( 'example.com', 'example.net', 'example.org' ), true );
	}

	/**
	 * Whether the reason is one the "This is my live shop" button can overrule.
	 *
	 * @param string $reason A reason from copy_reason().
	 * @return bool
	 */
	public static function is_address_reason( $reason ) {
		return 0 === strpos( (string) $reason, 'address:' );
	}

	/**
	 * The refusal for anything that would change something on eBay, or null
	 * when this site may send it.
	 *
	 * @return WP_Error|null
	 */
	public static function write_block() {
		self::boot();

		if ( ! TCGIANT_SYNC_IS_STAGING ) {
			return null;
		}

		$reason = '' !== self::$reason ? self::$reason : 'wp-config';

		return new WP_Error( 'staging_blocked', self::message( $reason ), array(
			'reason' => 'staging',
			'detail' => $reason,
		) );
	}

	/**
	 * The refusal's wording.
	 *
	 * The stock push and the exporter decide whether eBay said a listing had
	 * already ended by looking for 'ended', 'already been closed' and 'No
	 * changes allowed' in the error text. A refusal that contained any of them
	 * - "suspended", "intended", "recommended" - would be recorded as an ended
	 * listing, and the stock push would then never try that listing again. So
	 * the wording is fixed, and the reason in it is built from fixed words.
	 *
	 * @param string $reason A reason from copy_reason().
	 * @return string
	 */
	public static function message( $reason ) {
		return sprintf(
			/* translators: %s: why the site looks like a copy, e.g. 'a "staging" subdomain' */
			__( 'Not sent to eBay: this site looks like a staging or development copy (%s).', 'tcgiant-sync' ),
			self::describe( $reason )
		);
	}

	/**
	 * A reason in words.
	 *
	 * @param string $reason A reason from copy_reason().
	 * @return string
	 */
	public static function describe( $reason ) {
		$reason = (string) $reason;

		if ( 'wp-config' === $reason ) {
			return __( 'TCGIANT_SYNC_IS_STAGING is true in wp-config.php', 'tcgiant-sync' );
		}

		if ( 0 === strpos( $reason, 'environment:' ) ) {
			/* translators: %s: local, development or staging */
			return sprintf( __( 'WP_ENVIRONMENT_TYPE is %s', 'tcgiant-sync' ), substr( $reason, 12 ) );
		}

		$rule = explode( ':', self::is_address_reason( $reason ) ? substr( $reason, 8 ) : $reason, 2 );
		$kind = $rule[0];
		$word = $rule[1] ?? '';

		switch ( $kind ) {
			case 'label':
				/* translators: %s: a word such as staging or dev */
				return sprintf( __( 'its address has a "%s" subdomain', 'tcgiant-sync' ), $word );
			case 'prefix':
				/* translators: %s: a word such as staging or dev */
				return sprintf( __( 'its address has a subdomain starting "%s-"', 'tcgiant-sync' ), $word );
			case 'suffix':
				/* translators: %s: a word such as staging or dev */
				return sprintf( __( 'its address has a subdomain ending "-%s"', 'tcgiant-sync' ), $word );
			case 'tld':
				/* translators: %s: a top-level domain such as local or test */
				return sprintf( __( 'its address ends in .%s, which is only used for private sites', 'tcgiant-sync' ), $word );
			case 'localhost':
				return __( 'its address is localhost', 'tcgiant-sync' );
			case 'single-label':
				return __( 'its address is a machine name with no domain', 'tcgiant-sync' );
			case 'local-domain':
				return __( 'its address belongs to a local development tool (DDEV, Lando or Docksal)', 'tcgiant-sync' );
			case 'private-ip':
				return __( 'its address is a private network address', 'tcgiant-sync' );
			case 'kinsta':
				return __( 'its address is a Kinsta staging address', 'tcgiant-sync' );
			case 'wpengine':
				return __( 'its address is a WP Engine staging or development environment', 'tcgiant-sync' );
			case 'path':
				/* translators: %s: a word such as staging or dev */
				return sprintf( __( 'its address is in a "/%s" folder', 'tcgiant-sync' ), $word );
			case 'moved':
				return __( 'its address is not one its eBay connection was made or confirmed at', 'tcgiant-sync' );
		}

		return __( 'its address', 'tcgiant-sync' );
	}

	/**
	 * Note a change that was not sent.
	 *
	 * One warning per reason per request, rather than one per product: a copy
	 * running a sync would otherwise write a line for every stock change it
	 * imported. Every one is counted, for the notice.
	 *
	 * @param string        $what  What was not sent, e.g. 'EndItem' or 'stock push'.
	 * @param WP_Error|null $block The refusal, if the caller already has it.
	 */
	public static function note_blocked( $what, $block = null ) {
		if ( ! $block instanceof WP_Error ) {
			$block = self::write_block();
		}
		if ( ! $block ) {
			return;
		}

		if ( function_exists( 'get_transient' ) && function_exists( 'set_transient' ) ) {
			$tally = get_transient( self::BLOCKED_TRANSIENT );
			if ( ! is_array( $tally ) || ( time() - (int) ( $tally['since'] ?? 0 ) ) > DAY_IN_SECONDS ) {
				$tally = array( 'since' => time(), 'count' => 0 );
			}
			$tally['count'] = (int) $tally['count'] + 1;
			set_transient( self::BLOCKED_TRANSIENT, $tally, DAY_IN_SECONDS );
		}

		$data   = $block->get_error_data();
		$reason = is_array( $data ) && isset( $data['detail'] ) ? (string) $data['detail'] : 'staging';
		if ( isset( self::$noted[ $reason ] ) ) {
			return;
		}
		self::$noted[ $reason ] = true;

		if ( class_exists( 'TCGiant_Sync_Logger' ) ) {
			TCGiant_Sync_Logger::log( sprintf(
				'%s, and anything else in this request that would change something on eBay, was not sent: this site looks like a staging or development copy (%s). Reading from eBay carries on as normal.',
				$what,
				self::describe( $reason )
			), 'warning' );
		}
	}

	/**
	 * How many changes were not sent in the last day.
	 *
	 * @return int
	 */
	public static function blocked_count() {
		$tally = function_exists( 'get_transient' ) ? get_transient( self::BLOCKED_TRANSIENT ) : false;
		return is_array( $tally ) ? (int) ( $tally['count'] ?? 0 ) : 0;
	}

	/**
	 * Record an administrator's word that this address is the live shop.
	 *
	 * Overrules an address rule for this address only. A copy made later at
	 * any other address is judged on its own address again, because only the
	 * hash of this one is kept. It cannot overrule WP_ENVIRONMENT_TYPE or the
	 * wp-config.php constant: whoever set those meant them.
	 *
	 * It also puts the address on the list the eBay connection is bound to
	 * (3.31.0), which is all it does for a site that has only moved.
	 *
	 * @param int $user_id Who confirmed it.
	 * @return string The address confirmed, or '' when nothing was recorded.
	 */
	public static function confirm_live( $user_id ) {
		$was = self::copy_reason();
		if ( ! self::is_address_reason( $was ) ) {
			return '';
		}

		$moved   = 'address:moved' === $was;
		$address = self::address( self::current_url() );

		if ( ! $moved ) {
			if ( '' === $address ) {
				return '';
			}

			update_option( self::CONFIRMED_OPTION, array(
				'hash' => self::confirm_hash( $address ),
				'user' => (int) $user_id,
				'at'   => time(),
			), true );
		}

		// Bound here too, so a site at a copy's address that has also moved
		// needs one press, not two.
		$bound = self::bind( 'confirm', $user_id );
		if ( $moved ) {
			if ( '' === $bound ) {
				return '';
			}
			$address = $bound;
		}

		// Nothing refused before this press is sent now. Say so once, as for
		// a shop 3.27 kept off eBay, rather than let the notice just vanish.
		if ( self::connected() ) {
			$stored = get_option( self::VERDICT_OPTION, null );
			if ( ! is_array( $stored ) ) {
				$stored = array( 'copy' => true, 'reason' => $was, 'at' => time() );
			}
			$stored['notice'] = 'now_live';
			update_option( self::VERDICT_OPTION, $stored, false );
		}

		return $address;
	}

	/**
	 * Whether an administrator confirmed this exact address as the live shop.
	 *
	 * @param string $url The site's address.
	 * @return bool
	 */
	private static function confirmed_here( $url ) {
		$record = function_exists( 'get_option' ) ? get_option( self::CONFIRMED_OPTION ) : false;
		if ( ! is_array( $record ) || empty( $record['hash'] ) || ! is_string( $record['hash'] ) ) {
			return false;
		}

		$address = self::address( $url );

		return '' !== $address && hash_equals( $record['hash'], self::confirm_hash( $address ) );
	}

	/**
	 * The stored form of a confirmed address.
	 *
	 * @param string $address From address().
	 * @return string
	 */
	private static function confirm_hash( $address ) {
		return hash( 'sha256', 'tcgiant-sync-live|' . $address );
	}

	/**
	 * The site's address as the database stores it, for the binding: the
	 * 'home' row, read straight out of the options (3.31.0).
	 *
	 * Never WP_HOME, and never the request's host name. A wp-config.php can
	 * build WP_HOME from the host name a request arrived with - Bitnami's
	 * does - so a request on another name, a bare IP address or whatever a
	 * bot puts in its Host header would read as a move, and a stock push
	 * made in it would be refused for good. The stored row changes only when
	 * someone changes it: in Settings > General, by a host's move, or by a
	 * migration tool making a copy.
	 *
	 * @return string address() of it; '' when there is none.
	 */
	public static function stored_address() {
		if ( function_exists( 'wp_load_alloptions' ) ) {
			$all = wp_load_alloptions();
			if ( is_array( $all ) && ! empty( $all['home'] ) && is_string( $all['home'] ) ) {
				return self::address( $all['home'] );
			}
		}

		return '';
	}

	/**
	 * Whether the eBay connection is bound to addresses and the site is not
	 * at any of them (3.31.0).
	 *
	 * Decided only when there is something to decide on: an eBay account
	 * connected, a list recorded, and a stored address to compare. Without
	 * any of them the answer is no, as it was before 3.31.0.
	 *
	 * @return bool
	 */
	public static function moved() {
		// The connection first: the settings are read on every request
		// anyway, while asking for a list a shop never connected has none of
		// would cost a query on every page.
		if ( ! function_exists( 'get_option' ) || ! self::connected() ) {
			return false;
		}

		$bound = self::bound();
		if ( null === $bound ) {
			return false;
		}

		$address = self::stored_address();

		return '' !== $address && ! isset( $bound[ self::bound_hash( $address ) ] );
	}

	/**
	 * The addresses the connection is bound to, the most recent first, for
	 * the notice.
	 *
	 * @return string[]
	 */
	public static function bound_addresses() {
		$out = array();
		foreach ( (array) self::bound() as $entry ) {
			$shown = is_array( $entry ) && isset( $entry['shown'] ) ? base64_decode( (string) $entry['shown'], true ) : false;
			if ( is_string( $shown ) && '' !== $shown ) {
				$out[] = $shown;
			}
		}
		return $out;
	}

	/**
	 * Put the site's stored address on the list the connection is bound to.
	 *
	 * Never on a site anything else judges a copy - wp-config.php, the
	 * environment type or an address pattern not confirmed - so a copy is
	 * not bound to itself, and a database pushed from a copy to the live
	 * shop carries nothing that would hold the live shop back.
	 *
	 * @param string $how     'connect', 'confirm' or 'update', kept for support.
	 * @param int    $user_id Who did it; 0 for the update.
	 * @return string The address bound, or '' when nothing was recorded.
	 */
	public static function bind( $how, $user_id = 0 ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) || '' !== self::reason_before_binding() ) {
			return '';
		}

		$address = self::stored_address();
		if ( '' === $address ) {
			return '';
		}

		$record = get_option( self::BOUND_OPTION );
		$list   = is_array( $record ) && isset( $record['addresses'] ) && is_array( $record['addresses'] ) ? $record['addresses'] : array();
		$hash   = self::bound_hash( $address );

		unset( $list[ $hash ] );
		$list = array( $hash => array(
			'shown'  => base64_encode( $address ),
			'how'    => (string) $how,
			'user'   => (int) $user_id,
			'at'     => time(),
			// The database's table prefix: wpstg0_ and on are WP Staging's
			// copies (rebind_after_staging_push(), fix round 2).
			'tables' => isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && isset( $GLOBALS['wpdb']->prefix ) ? (string) $GLOBALS['wpdb']->prefix : '',
		) ) + $list;

		update_option( self::BOUND_OPTION, array( 'addresses' => array_slice( $list, 0, self::BOUND_KEEP, true ) ), true );

		return $address;
	}

	/**
	 * The live shop, holding the list a staging copy left it (3.31.0, fix
	 * round 1).
	 *
	 * A staging copy at a host's temporary address that updates to 3.31.0
	 * before the live shop binds itself to that address at its first
	 * request. Pushed to live, its database replaces the live shop's list, and
	 * the live shop - whose own address the host has put back - is judged
	 * moved: every stock push for a website sale refused, for good.
	 *
	 * So when every address on the list was bound at the update (never by a
	 * connection or a press) and is either on a host's temporary domain, or
	 * a folder under this site's own address that a WP Staging copy recorded
	 * (its tables are wpstg0_ and on), the list is replaced with this
	 * address - unless this site is itself on a host's temporary domain,
	 * where a staging copy cloned again would be let through (fix round 2).
	 * Replaced, not added to: a copy made later at the old staging address is
	 * still a copy. A copy of the live shop is still caught - its list holds
	 * the live shop's own address - and so is a copy of a shop in a folder
	 * made at the root of its domain, or a real move out of a folder, which
	 * both need the button (fix round 2: until then any folder counted).
	 *
	 * What this cannot tell from the live shop: a copy, at a domain of its
	 * own, of a staging copy at a temporary address that updated first - the
	 * two databases are the same. Nor a staging copy on a domain of the
	 * shop's own, pushed to live: that still needs the button. And a shop
	 * that lives on a host's temporary domain is not protected by the
	 * binding.
	 *
	 * @return bool Whether the list was replaced.
	 */
	private static function rebind_after_staging_push() {
		$bound = self::bound();
		$here  = self::stored_address();
		if ( null === $bound || '' === $here || self::on_temporary_host( $here ) ) {
			return false;
		}

		$was = array();
		foreach ( $bound as $entry ) {
			$shown = is_array( $entry ) && isset( $entry['shown'] ) ? base64_decode( (string) $entry['shown'], true ) : false;
			if ( ! is_string( $shown ) || '' === $shown || 'update' !== ( $entry['how'] ?? '' ) ) {
				return false;
			}
			$wp_staging = 0 === strpos( $shown, $here . '/' ) && 0 === strpos( (string) ( $entry['tables'] ?? '' ), 'wpstg' );
			if ( ! $wp_staging && ! self::on_temporary_host( $shown ) ) {
				return false;
			}
			$was[] = $shown;
		}

		update_option( self::BOUND_OPTION, array( 'addresses' => array() ), true );
		if ( '' === self::bind( 'staging-push' ) ) {
			return false;
		}

		self::$rebound = array( implode( ', ', $was ), $here );

		return true;
	}

	/**
	 * Log a staging-push rebind made as the plugin loaded (fix round 2: it
	 * was logged then, before the logger existed, so never). Hooked on init.
	 *
	 * @return void
	 */
	public static function log_rebind() {
		if ( null === self::$rebound || ! class_exists( 'TCGiant_Sync_Logger' ) ) {
			return;
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Staging check: this site\'s eBay connection was bound only to %s, which a staging copy recorded for itself when it was updated before this shop, and its database was then copied here. It is now bound to %s, this site\'s address, and changes are sent to eBay from it.',
			self::$rebound[0],
			self::$rebound[1]
		), 'warning' );

		self::$rebound = null;
	}

	/**
	 * Whether an address is on one of TEMPORARY_HOSTS.
	 *
	 * @param string $address From address().
	 * @return bool
	 */
	private static function on_temporary_host( $address ) {
		$host = preg_replace( '/[:\/].*$/s', '', (string) $address );
		foreach ( self::TEMPORARY_HOSTS as $domain ) {
			$tail = '.' . $domain;
			if ( $host === $domain || substr( $host, -strlen( $tail ) ) === $tail ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tell the administrator, by email, that this site sends nothing to eBay
	 * because it is not at the address its connection was made at (3.31.0,
	 * fix round 1). Once an address, from any request - cron and WP-CLI
	 * included - so a shop that has moved hears of it without opening
	 * wp-admin. A staging copy sends one too.
	 *
	 * Hooked on init by boot(), once wp_mail() exists. To stop the emails,
	 * remove that hook; nothing else depends on them.
	 *
	 * @return bool Whether an email was sent.
	 */
	public static function mail_move() {
		if ( 'address:moved' !== self::reason() || ! function_exists( 'wp_mail' ) || ! function_exists( 'update_option' ) ) {
			return false;
		}

		$now = self::stored_address();
		if ( '' === $now ) {
			return false;
		}

		// The address's row, inserted only if it is not there: the one request
		// that inserts it sends the email, whoever else runs at the same
		// moment (fix round 2). Not add_option(), which checks the cache and
		// then writes INSERT ... ON DUPLICATE KEY UPDATE, so two requests can
		// both succeed. A database that cannot write it sends nothing, rather
		// than an email every request.
		//
		// Looked for first (fix round 3): a copy whose options table lost its
		// UNIQUE key on option_name - some migration tools drop indexes -
		// takes the INSERT every time, and sent an email on every request.
		// Now one row, once there, ends it: at most the requests that run in
		// the same moment as the first send one each. It also spares a moved
		// site a write on every request.
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$row = self::MOVE_MAILED_PREFIX . substr( self::bound_hash( $now ), 0, 32 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$seen = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
			$row
		) );
		if ( null === $seen || 0 !== (int) $seen ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$claimed = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
			$row,
			(string) time()
		) );
		if ( 1 !== (int) $claimed ) {
			return false;
		}

		$to = (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return false;
		}

		$then  = array_slice( self::bound_addresses(), 0, 3 );
		$then  = $then ? implode( ', ', $then ) : __( 'another address', 'tcgiant-sync' );
		$admin = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=tcgiant-sync' ) : '';

		$subject = sprintf(
			/* translators: %s: the site's address */
			__( 'TCGiant Sync is not sending changes to eBay from %s', 'tcgiant-sync' ),
			$now
		);

		$body = implode( "\n\n", array(
			sprintf(
				/* translators: 1: the address or addresses the eBay connection was made at, 2: the site's address now */
				__( 'TCGiant Sync on %2$s is sending nothing to eBay: its eBay connection was made at %1$s, and this site is now at %2$s. No stock, listing, relist or tracking changes go to eBay from it. It still reads from eBay.', 'tcgiant-sync' ),
				$then,
				$now
			),
			__( 'If this is your live shop - you moved it to this address, or copied a staging site over it - log in and press "This is my live shop" in the TCGiant Sync notice. Until you do, sales on your website are not sent to eBay, and they are not sent afterwards either: check them against your eBay listings.', 'tcgiant-sync' ) . ( '' !== $admin ? ' ' . $admin : '' ),
			__( 'If this site is a copy of your shop - a staging or test site, or a backup restored somewhere else - nothing needs doing. Your live shop is not affected. This site sends this email once for each address it finds itself at; a copy made again from your live shop may send it again.', 'tcgiant-sync' ),
		) );

		return (bool) wp_mail( $to, $subject, $body );
	}

	/**
	 * Bind a connected shop that has no list yet: one connected before
	 * 3.31.0, at its first request after the update.
	 *
	 * @return void
	 */
	private static function bind_if_unbound() {
		if ( ! function_exists( 'get_option' ) || ! self::connected() || null !== self::bound() ) {
			return;
		}

		self::bind( 'update' );
	}

	/**
	 * The list as stored, keyed by bound_hash(); null when there is none.
	 *
	 * @return array|null
	 */
	private static function bound() {
		$record = function_exists( 'get_option' ) ? get_option( self::BOUND_OPTION ) : false;
		if ( ! is_array( $record ) || empty( $record['addresses'] ) || ! is_array( $record['addresses'] ) ) {
			return null;
		}

		return $record['addresses'];
	}

	/**
	 * The stored form of a bound address, to compare.
	 *
	 * @param string $address From address().
	 * @return string
	 */
	private static function bound_hash( $address ) {
		return hash( 'sha256', 'tcgiant-sync-bound|' . self::ascii_host( $address ) );
	}

	/**
	 * An address with an international domain name written as its ASCII
	 * (punycode) form, as the binding compares it: a home saved as bücher.de
	 * and later as xn--bcher-kva.de is one address.
	 *
	 * Encoded here, by RFC 3492, never by PHP's intl extension (fix round 2):
	 * with intl one process, without it another, the same home bound under
	 * one was "moved" under the other - every write refused, an email sent.
	 * Each label with a letter beyond ASCII becomes "xn--" and its punycode,
	 * as intl gives for any label in lower case; letters are not case-folded
	 * beyond ASCII, which a home written by WordPress or a browser never
	 * needs. An ASCII address comes back as it is.
	 *
	 * @param string $address From address().
	 * @return string
	 */
	private static function ascii_host( $address ) {
		$address = (string) $address;
		if ( ! preg_match( '/^([^\/:]+)(.*)$/s', $address, $m ) || ! preg_match( '/[^\x00-\x7f]/', $m[1] ) ) {
			return $address;
		}

		$labels = explode( '.', $m[1] );
		foreach ( $labels as $i => $label ) {
			if ( ! preg_match( '/[^\x00-\x7f]/', $label ) ) {
				continue;
			}
			$code = self::punycode( $label );
			if ( null === $code ) {
				return $address;
			}
			$labels[ $i ] = 'xn--' . $code;
		}

		return implode( '.', $labels ) . $m[2];
	}

	/**
	 * RFC 3492's encoding of one label, or null when it is not valid UTF-8.
	 *
	 * @param string $label A label of a host name, UTF-8.
	 * @return string|null
	 */
	private static function punycode( $label ) {
		$chars = preg_split( '//u', (string) $label, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return null;
		}

		$points = array();
		foreach ( $chars as $char ) {
			$b = array_values( (array) unpack( 'C*', $char ) );
			switch ( count( $b ) ) {
				case 1:
					$points[] = $b[0];
					break;
				case 2:
					$points[] = ( ( $b[0] & 0x1F ) << 6 ) | ( $b[1] & 0x3F );
					break;
				case 3:
					$points[] = ( ( $b[0] & 0x0F ) << 12 ) | ( ( $b[1] & 0x3F ) << 6 ) | ( $b[2] & 0x3F );
					break;
				case 4:
					$points[] = ( ( $b[0] & 0x07 ) << 18 ) | ( ( $b[1] & 0x3F ) << 12 ) | ( ( $b[2] & 0x3F ) << 6 ) | ( $b[3] & 0x3F );
					break;
				default:
					return null;
			}
		}

		$base   = 36;
		$tmin   = 1;
		$tmax   = 26;
		$output = '';
		foreach ( $points as $point ) {
			if ( $point < 0x80 ) {
				$output .= chr( $point );
			}
		}
		$basic = strlen( $output );
		$done  = $basic;
		if ( $basic > 0 ) {
			$output .= '-';
		}

		$n     = 0x80;
		$delta = 0;
		$bias  = 72;
		$total = count( $points );

		while ( $done < $total ) {
			$next = PHP_INT_MAX;
			foreach ( $points as $point ) {
				if ( $point >= $n && $point < $next ) {
					$next = $point;
				}
			}
			$delta += ( $next - $n ) * ( $done + 1 );
			$n      = $next;

			foreach ( $points as $point ) {
				if ( $point < $n ) {
					$delta++;
				}
				if ( $point !== $n ) {
					continue;
				}
				$q = $delta;
				for ( $k = $base; ; $k += $base ) {
					$t = $k <= $bias ? $tmin : ( $k >= $bias + $tmax ? $tmax : $k - $bias );
					if ( $q < $t ) {
						break;
					}
					$output .= self::punycode_digit( $t + ( $q - $t ) % ( $base - $t ) );
					$q       = intdiv( $q - $t, $base - $t );
				}
				$output .= self::punycode_digit( $q );

				// The bias adapts to the deltas so far (RFC 3492, 6.1).
				$delta  = $done === $basic ? intdiv( $delta, 700 ) : intdiv( $delta, 2 );
				$delta += intdiv( $delta, $done + 1 );
				$k      = 0;
				while ( $delta > ( ( $base - $tmin ) * $tmax ) >> 1 ) {
					$delta = intdiv( $delta, $base - $tmin );
					$k    += $base;
				}
				$bias  = $k + intdiv( ( $base - $tmin + 1 ) * $delta, $delta + 38 );
				$delta = 0;
				$done++;
			}
			$delta++;
			$n++;
		}

		return $output;
	}

	/**
	 * A punycode digit: 0-25 are a-z, 26-35 are 0-9.
	 *
	 * @param int $d 0 to 35.
	 * @return string
	 */
	private static function punycode_digit( $d ) {
		return $d < 26 ? chr( 97 + $d ) : chr( 22 + $d );
	}

	/**
	 * Keep the stored verdict up to date, and notice when it changes.
	 *
	 * Runs on admin requests. The first time, on a site that has just updated
	 * from 3.27, it also works out what 3.27 would have said, because a shop
	 * whose verdict flipped needs telling:
	 *
	 * - judged a copy before and live now: it has sent nothing to eBay for as
	 *   long as it has run the plugin, so eBay may be out of step with it, and
	 *   it is told how to check. Nothing is pushed for it - a mass push of
	 *   stale figures is the last thing that shop needs.
	 * - judged live before and a copy now: changes it was sending stop, so the
	 *   staging notice is shown on every admin screen, with its button.
	 */
	public static function record_verdict() {
		$reason = self::reason();
		$copy   = '' !== $reason;
		$stored = get_option( self::VERDICT_OPTION, null );

		if ( ! is_array( $stored ) ) {
			// 3.27 judged from the request's host name, so the comparison is
			// only meaningful in a request that has one.
			if ( empty( $_SERVER['HTTP_HOST'] ) ) {
				return;
			}

			$was_copy = self::legacy_verdict();
			$notice   = '';
			if ( $was_copy && ! $copy && self::connected() ) {
				$notice = 'now_live';
			} elseif ( ! $was_copy && $copy && self::connected() ) {
				$notice = 'now_copy';
			}

			update_option( self::VERDICT_OPTION, array(
				'copy'   => $copy,
				'reason' => $reason,
				'at'     => time(),
				'notice' => $notice,
			), false );

			if ( $was_copy !== $copy && class_exists( 'TCGiant_Sync_Logger' ) ) {
				TCGiant_Sync_Logger::log( sprintf(
					'Staging check: this site was judged %s by TCGiant Sync 3.27 and is now judged %s. %s',
					$was_copy ? 'a staging copy' : 'the live shop',
					$copy ? 'a staging copy (' . self::describe( $reason ) . ')' : 'the live shop',
					$copy ? 'Changes are no longer sent to eBay from it.' : 'Changes are now sent to eBay from it; nothing that was held back before has been sent.'
				), 'warning' );
			}
			return;
		}

		if ( (bool) ( $stored['copy'] ?? false ) === $copy && (string) ( $stored['reason'] ?? '' ) === $reason ) {
			return;
		}

		if ( class_exists( 'TCGiant_Sync_Logger' ) ) {
			$before = (string) ( $stored['reason'] ?? '' );
			TCGiant_Sync_Logger::log( sprintf(
				'Staging check changed: this site was judged %s and is now judged %s.',
				'' !== $before ? 'a staging copy (' . self::describe( $before ) . ')' : 'the live shop',
				$copy ? 'a staging copy (' . self::describe( $reason ) . ') - changes are no longer sent to eBay from it' : 'the live shop - changes are sent to eBay from it'
			), 'warning' );
		}

		$stored['copy']   = $copy;
		$stored['reason'] = $reason;
		$stored['at']     = time();
		// The "this used to be live" notice has nothing left to say once the
		// site is live again.
		if ( ! $copy && 'now_copy' === ( $stored['notice'] ?? '' ) ) {
			$stored['notice'] = '';
		}
		update_option( self::VERDICT_OPTION, $stored, false );
	}

	/**
	 * The one-time notice the verdict recorded, if it is still to be shown.
	 *
	 * @return string 'now_live', 'now_copy' or ''.
	 */
	public static function pending_notice() {
		$stored = get_option( self::VERDICT_OPTION, null );
		return is_array( $stored ) ? (string) ( $stored['notice'] ?? '' ) : '';
	}

	/**
	 * Put the one-time notice away.
	 */
	public static function dismiss_notice() {
		$stored = get_option( self::VERDICT_OPTION, null );
		if ( is_array( $stored ) && ! empty( $stored['notice'] ) ) {
			$stored['notice'] = '';
			update_option( self::VERDICT_OPTION, $stored, false );
		}
	}

	/**
	 * Whether an eBay account is connected.
	 *
	 * @return bool
	 */
	private static function connected() {
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		return is_array( $settings ) && ! empty( $settings['refresh_token'] );
	}

	/**
	 * What TCGiant Sync 3.27 decided, for the one-time comparison above.
	 *
	 * The 3.27 check, unchanged: the environment type, then the request's host
	 * name against a list of substrings. Remove in 3.29, once every shop has
	 * been through the comparison.
	 *
	 * @return bool True when 3.27 would have kept this site off eBay.
	 */
	private static function legacy_verdict() {
		if ( null !== self::$explicit ) {
			return self::$explicit;
		}

		$tcgiant_env  = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$tcgiant_host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
		$is_staging   = ! in_array( $tcgiant_env, array( 'production', 'live' ), true );

		// Common staging URL patterns.
		if ( ! $is_staging ) {
			$staging_patterns = array( '.staging.', '.test.', '.dev.', '.local', 'staging-', 'dev-', 'localhost' );
			foreach ( $staging_patterns as $pattern ) {
				if ( strpos( $tcgiant_host, $pattern ) !== false ) {
					$is_staging = true;
					break;
				}
			}
		}

		return $is_staging;
	}

	/**
	 * A URL's host, port and path, lower-cased where case does not matter.
	 *
	 * A URL with bytes beyond ASCII - an international domain name such as
	 * сайт.рф - is parsed with those bytes, and every '%' with them,
	 * percent-encoded, and its host and path decoded again after (3.31.0,
	 * fix round 3). parse_url() rewrites the bytes 0x80-0x9F as '_' under a
	 * single-byte locale (cp1252, ISO-8859-x, which PHP 7.4 takes from its
	 * environment): one home came out one way in a page load and another in
	 * a WP-CLI or cron run, and was "moved" in one of them. Decoding gives
	 * back exactly what was encoded, since the only '%' left are the ones
	 * added. An ASCII URL is parsed as it always was.
	 *
	 * @param string $url A URL, with or without its scheme.
	 * @return array{host: string, port: int, path: string}
	 */
	private static function parse( $url ) {
		$url = trim( (string) $url );
		if ( '' !== $url && false === strpos( $url, '://' ) ) {
			$url = 'http://' . ltrim( $url, '/' );
		}

		$wide = (bool) preg_match( '/[\x80-\xff]/', $url );
		if ( $wide ) {
			$url = (string) preg_replace_callback(
				'/[%\x80-\xff]/',
				function ( $byte ) {
					return rawurlencode( $byte[0] );
				},
				$url
			);
		}

		$parts = false;
		if ( '' !== $url ) {
			$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- only before WordPress has loaded http.php.
		}
		if ( ! is_array( $parts ) ) {
			$parts = array();
		}
		if ( $wide ) {
			foreach ( array( 'host', 'path' ) as $key ) {
				if ( isset( $parts[ $key ] ) ) {
					$parts[ $key ] = rawurldecode( (string) $parts[ $key ] );
				}
			}
		}

		return array(
			'host' => self::lower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) ),
			'port' => (int) ( $parts['port'] ?? 0 ),
			'path' => (string) ( $parts['path'] ?? '' ),
		);
	}

	/**
	 * Lower case, by the ASCII letters only (3.31.0, fix round 1).
	 *
	 * strtolower() before PHP 8.2 follows the locale, and before PHP 8.0 a
	 * request inherits the locale of whatever started it: under a Latin-1
	 * locale it rewrites the bytes of a UTF-8 address such as bucher.de's
	 * u-umlaut, so one address hashed one way in a page load and another in
	 * a WP-CLI run. PHP 8.2's strtolower() works this way everywhere. An
	 * ASCII address comes out exactly as strtolower() made it.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lower( $text ) {
		return strtr( (string) $text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	/**
	 * Whether an IP address is one only a private network can reach.
	 *
	 * Spelled out rather than left to filter_var()'s range flags, whose idea
	 * of "reserved" has changed between PHP versions; the verdict must be the
	 * same on every PHP the plugin runs on.
	 *
	 * @param string $ip A valid IPv4 or IPv6 address.
	 * @return bool
	 */
	private static function is_private_ip( $ip ) {
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $ip );
			foreach ( array( '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.168.0.0/16' ) as $cidr ) {
				list( $net, $bits ) = explode( '/', $cidr );
				$mask = -1 << ( 32 - (int) $bits );
				if ( ( $long & $mask ) === ( ip2long( $net ) & $mask ) ) {
					return true;
				}
			}
			return false;
		}

		$bin = inet_pton( $ip );
		if ( false === $bin || 16 !== strlen( $bin ) ) {
			return false;
		}

		// ::ffff:a.b.c.d is an IPv4 address written as IPv6.
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $bin, 0, 12 ) ) {
			$v4 = inet_ntop( substr( $bin, 12 ) );
			return false !== $v4 && self::is_private_ip( $v4 );
		}

		$first  = ord( $bin[0] );
		$second = ord( $bin[1] );

		return str_repeat( "\0", 15 ) . "\1" === $bin      // ::1, loopback
			|| str_repeat( "\0", 16 ) === $bin             // ::, unspecified
			|| 0xfc === ( $first & 0xfe )                  // fc00::/7, unique local
			|| ( 0xfe === $first && 0x80 === ( $second & 0xc0 ) ); // fe80::/10, link-local
	}

	/**
	 * PHP2Shell (CVE-2026-109618, CVE-2026-109619), a flaw in PHP itself,
	 * not in this plugin: the first release of each PHP branch with the fix
	 * (3.28.4). PHP 8.1 and older have no official fix. The dashboard's health
	 * panel says so when the PHP the site runs on comes before it.
	 */
	const PHP2SHELL_FIXED = array(
		'8.2' => '8.2.35',
		'8.3' => '8.3.36',
		'8.4' => '8.4.27',
		'8.5' => '8.5.12',
		'8.6' => '8.6.0RC4',
	);

	/**
	 * The PHP version php2shell() judges when given none: PHP_VERSION, unless
	 * a test sets this.
	 *
	 * @var string|null
	 */
	public static $php_version = null;

	/**
	 * Whether a PHP version comes before the fix for PHP2Shell.
	 *
	 * By the version alone: a host may add the fix to an older release without
	 * changing its number, so this can only say "may be affected". A
	 * distribution's suffix ("8.3.36-1+ubuntu24.04.1") is that release; a
	 * pre-release (dev, alpha, beta, RC) comes before it. A branch after the
	 * table (8.7, 9) is taken as fixed, and a version that cannot be read is
	 * said nothing about.
	 *
	 * @param string|null $version A PHP version; this site's when omitted.
	 * @return string 'unsupported' for 8.1 and older, 'unfixed' for a release
	 *                of a later branch before its fix, '' otherwise.
	 */
	public static function php2shell( $version = null ) {
		$have = self::php_release( self::php_version( $version ) );

		if ( null === $have ) {
			return '';
		}

		if ( $have[0] < 8 || ( 8 === $have[0] && $have[1] < 2 ) ) {
			return 'unsupported';
		}

		$branch = $have[0] . '.' . $have[1];
		if ( ! isset( self::PHP2SHELL_FIXED[ $branch ] ) ) {
			return '';
		}

		$fixed = self::php_release( self::PHP2SHELL_FIXED[ $branch ] );

		// The release, then how far along its way to release a build is.
		foreach ( array( 2, 3, 4 ) as $part ) {
			if ( $have[ $part ] !== $fixed[ $part ] ) {
				return $have[ $part ] < $fixed[ $part ] ? 'unfixed' : '';
			}
		}

		return '';
	}

	/**
	 * The dashboard's health line for PHP2Shell, or null when there is none to
	 * show. Nothing is stored, and nothing is asked of anyone.
	 *
	 * @param string|null $version A PHP version; this site's when omitted.
	 * @return array|null label, status, text and detail, as get_system_health() rows.
	 */
	public static function php2shell_row( $version = null ) {
		$version = self::php_version( $version );
		$verdict = self::php2shell( $version );

		if ( '' === $verdict ) {
			return null;
		}

		$fixed = self::PHP2SHELL_FIXED;

		if ( 'unsupported' === $verdict ) {
			$have   = self::php_release( $version );
			$detail = sprintf(
				/* translators: 1: the site's PHP version, 2: its branch, e.g. 8.1, 3-6: the first fixed release of PHP 8.2, 8.3, 8.4 and 8.5 */
				__( 'PHP %1$s may be affected by PHP2Shell (CVE-2026-109618, CVE-2026-109619), a security flaw in PHP itself. PHP no longer updates %2$s, so only your host can have added the fix: ask your host whether it has. PHP %3$s, %4$s, %5$s and %6$s have the fix.', 'tcgiant-sync' ),
				$version,
				$have[0] . '.' . $have[1],
				$fixed['8.2'],
				$fixed['8.3'],
				$fixed['8.4'],
				$fixed['8.5']
			);
		} else {
			$detail = sprintf(
				/* translators: 1: the site's PHP version, 2-5: the first fixed release of PHP 8.2, 8.3, 8.4 and 8.5 */
				__( 'PHP %1$s may be affected by PHP2Shell (CVE-2026-109618, CVE-2026-109619), a security flaw in PHP itself. Ask your host whether it has the fix: PHP %2$s, %3$s, %4$s and %5$s do. Some hosts add the fix without changing the version number.', 'tcgiant-sync' ),
				$version,
				$fixed['8.2'],
				$fixed['8.3'],
				$fixed['8.4'],
				$fixed['8.5']
			);
		}

		return array(
			'label'  => __( 'PHP security', 'tcgiant-sync' ),
			'status' => 'warning',
			'text'   => __( 'Check with your host', 'tcgiant-sync' ),
			'detail' => $detail,
		);
	}

	/**
	 * The version to judge: the one given, else this site's.
	 *
	 * @param string|null $version A PHP version, or null.
	 * @return string
	 */
	private static function php_version( $version ) {
		if ( null !== $version ) {
			return (string) $version;
		}

		return null !== self::$php_version ? (string) self::$php_version : PHP_VERSION;
	}

	/**
	 * A PHP version, read: major, minor, release, then where the build is on
	 * its way to that release - 0 dev, 1 alpha, 2 beta, 3 RC, 4 the release
	 * itself or a distribution's build of it - and the pre-release's number.
	 *
	 * @param string $version e.g. 8.3.20, 8.6.0RC3, 8.1.2-1ubuntu2.14.
	 * @return int[]|null Null when it is not a version.
	 */
	private static function php_release( $version ) {
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)\.(\d+)(.*)$/s', trim( (string) $version ), $m ) ) {
			return null;
		}

		$stage = 4;
		$count = 0;
		if ( 1 === preg_match( '/^[-.]?(dev|alpha|beta|RC)(\d*)/i', $m[4], $pre ) ) {
			$stage = (int) array_search( strtolower( $pre[1] ), array( 'dev', 'alpha', 'beta', 'rc' ), true );
			$count = (int) $pre[2];
		}

		return array( (int) $m[1], (int) $m[2], (int) $m[3], $stage, $count );
	}
}
