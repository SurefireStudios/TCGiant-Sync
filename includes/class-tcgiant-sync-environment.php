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
 * It is required directly from tcgiant-sync.php, before the autoloader exists,
 * so it uses nothing that is not already loaded when plugins are.
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

		return $host . $port . strtolower( rtrim( $parts['path'], '/' ) );
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
		$first    = strtolower( $segments[0] );
		if ( '' !== $first && preg_match( '/^(' . self::COPY_WORDS . ')\d*$/', $first, $m ) ) {
			return 'path:' . $m[1];
		}

		return '';
	}

	/**
	 * Why this site should not send changes to eBay, worked out afresh.
	 *
	 * In order: wp-config.php; WordPress's own environment type, which only an
	 * edit to wp-config.php or the host's settings can change; and then the
	 * address, which an administrator can overrule for this one address with
	 * the "This is my live shop" button.
	 *
	 * @return string '' when the site is live, otherwise the reason it is not.
	 */
	public static function copy_reason() {
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
		if ( self::home_from_request( $url ) ) {
			$stored = function_exists( 'get_option' ) ? get_option( self::VERDICT_OPTION, null ) : null;
			$was    = is_array( $stored ) && ! empty( $stored['copy'] ) ? (string) ( $stored['reason'] ?? '' ) : '';
			return self::is_address_reason( $was ) ? $was : '';
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
	 * @param int $user_id Who confirmed it.
	 * @return string The address confirmed, or '' when nothing was recorded.
	 */
	public static function confirm_live( $user_id ) {
		if ( ! self::is_address_reason( self::copy_reason() ) ) {
			return '';
		}

		$address = self::address( self::current_url() );
		if ( '' === $address ) {
			return '';
		}

		$was = self::copy_reason();

		update_option( self::CONFIRMED_OPTION, array(
			'hash' => self::confirm_hash( $address ),
			'user' => (int) $user_id,
			'at'   => time(),
		), true );

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
	 * @param string $url A URL, with or without its scheme.
	 * @return array{host: string, port: int, path: string}
	 */
	private static function parse( $url ) {
		$url = trim( (string) $url );
		if ( '' !== $url && false === strpos( $url, '://' ) ) {
			$url = 'http://' . ltrim( $url, '/' );
		}

		$parts = false;
		if ( '' !== $url ) {
			$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- only before WordPress has loaded http.php.
		}
		if ( ! is_array( $parts ) ) {
			$parts = array();
		}

		return array(
			'host' => strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) ),
			'port' => (int) ( $parts['port'] ?? 0 ),
			'path' => (string) ( $parts['path'] ?? '' ),
		);
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
}
