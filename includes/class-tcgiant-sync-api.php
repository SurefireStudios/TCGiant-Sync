<?php
/**
 * eBay API Wrapper
 *
 * Provides methods to interact with eBay Inventory and Fulfillment APIs.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_API class
 */
class TCGiant_Sync_API {

	/**
	 * Instance of this class.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * eBay REST Base URLs.
	 */
	const BASE_URL_PRODUCTION = 'https://api.ebay.com/';

	/**
	 * Default daily Trading API call limit.
	 *
	 * eBay approved our Application Growth Check on 2026-07-21, raising
	 * the Trading API limit from 5K to 50K calls/day for our production
	 * AppID. The fleet shares it.
	 *
	 * Past 80% of it - the soft ceiling - calls are refused here before they
	 * are sent, so that what is left goes to the calls that stop a sale
	 * happening twice. Until 3.28.0 nothing was kept back: the ceiling
	 * refused every call alike, a website sale's EndItem included, and a coin
	 * sold on the website stayed for sale on eBay until the next day. Now
	 * these go past it:
	 *
	 * - CompleteSale, which tells eBay a real buyer's order has shipped;
	 * - the reads and writes of a stock push that prevents an oversell - a
	 *   website sale, a manual push, anything going to 0 - made inside
	 *   with_stock_priority().
	 *
	 * A hand edit or a CSV import raising stock still waits for the next day.
	 * eBay's own limit (518, HTTP 429) is never bypassed.
	 */
	const DAILY_CALL_LIMIT = 50000;
	const DAILY_CALL_SOFT_CEILING = 0.80;

	/**
	 * The calls a stock push may make past the soft ceiling, inside
	 * with_stock_priority(). GeteBayOfficialTime is the outage question a
	 * sale check or a push asks before it gives up - one call per five
	 * minutes, shared (3.28.0 release fix round 1).
	 */
	const STOCK_PRIORITY_CALLS = array( 'GetItem', 'ReviseInventoryStatus', 'EndItem', 'GeteBayOfficialTime' );

	/**
	 * Depth of with_stock_priority() calls under way.
	 *
	 * @var int
	 */
	private $stock_priority = 0;

	/**
	 * eBay's own Timestamp on the latest Trading answer this request read,
	 * as a Unix time; 0 before any. Stamps a record of eBay's sales, so
	 * order sync can tell whether a read has seen a sale yet by eBay's clock
	 * alone (3.28.0 release fix round 2). Never the server's clock.
	 *
	 * @var int
	 */
	private $ebay_time = 0;

	/**
	 * How large a GetSellerEvents answer may be, as a share of the memory limit.
	 *
	 * The call has no pagination: it returns every listing changed in the
	 * period asked for, in full, in one response. A shop on WordPress.com with
	 * a 512 MB limit ran out of memory reading one, every time, so its newest
	 * listings never arrived.
	 *
	 * Reading a response costs a multiple of its size, measured at 2 to 4 times
	 * for most listings, nearer 7 for ones with many item specifics and little
	 * description, and up to 9.5 for ones with many variations. A twentieth of
	 * the limit keeps even that worst case under half of it, leaving the rest
	 * for WordPress, WooCommerce and the import that follows. At 512 MB that is about 25 MB, which is more than eBay's own
	 * ceiling of 3000 ordinary listings, so most shops never meet it.
	 */
	const EVENTS_BYTES_DIVISOR = 20;

	/**
	 * Bounds on that figure. The upper one also applies when PHP has no memory
	 * limit at all, because the host's container still does.
	 */
	const EVENTS_BYTES_MIN = 4194304;
	const EVENTS_BYTES_MAX = 33554432;

	/**
	 * Size of the last Trading API response body, in bytes.
	 *
	 * @var int
	 */
	private $last_body_bytes = 0;

	/**
	 * Supported Marketplaces.
	 */
	const MARKETPLACES = array(
		'EBAY_US' => array( 'label' => 'United States',  'site_id' => '0',   'country' => 'US', 'currency' => 'USD', 'site' => 'US' ),
		'EBAY_GB' => array( 'label' => 'United Kingdom', 'site_id' => '3',   'country' => 'GB', 'currency' => 'GBP', 'site' => 'UK' ),
		'EBAY_CA' => array( 'label' => 'Canada',         'site_id' => '2',   'country' => 'CA', 'currency' => 'CAD', 'site' => 'Canada' ),
		'EBAY_AU' => array( 'label' => 'Australia',      'site_id' => '15',  'country' => 'AU', 'currency' => 'AUD', 'site' => 'Australia' ),
		'EBAY_DE' => array( 'label' => 'Germany',        'site_id' => '77',  'country' => 'DE', 'currency' => 'EUR', 'site' => 'Germany' ),
		'EBAY_FR' => array( 'label' => 'France',         'site_id' => '71',  'country' => 'FR', 'currency' => 'EUR', 'site' => 'France' ),
		'EBAY_IT' => array( 'label' => 'Italy',          'site_id' => '101', 'country' => 'IT', 'currency' => 'EUR', 'site' => 'Italy' ),
		'EBAY_ES' => array( 'label' => 'Spain',          'site_id' => '186', 'country' => 'ES', 'currency' => 'EUR', 'site' => 'Spain' ),
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
	 * Largest GetSellerEvents response this site should try to read, in bytes.
	 *
	 * @return int
	 */
	public static function get_events_byte_limit() {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		if ( $limit <= 0 ) {
			return self::EVENTS_BYTES_MAX;
		}

		return (int) max( self::EVENTS_BYTES_MIN, min( self::EVENTS_BYTES_MAX, (int) floor( $limit / self::EVENTS_BYTES_DIVISOR ) ) );
	}

	/**
	 * Size of the last Trading API response body, in bytes.
	 *
	 * @return int
	 */
	public function get_last_body_bytes() {
		return $this->last_body_bytes;
	}

	/**
	 * Get configured marketplace settings.
	 */
	public function get_marketplace_config() {
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$marketplace_id = ! empty( $settings['marketplace'] ) ? $settings['marketplace'] : 'EBAY_US';
		
		if ( ! isset( self::MARKETPLACES[ $marketplace_id ] ) ) {
			$marketplace_id = 'EBAY_US';
		}
		
		return array(
			'marketplace_id' => $marketplace_id,
			'site_id'        => self::MARKETPLACES[ $marketplace_id ]['site_id'],
			'country'        => self::MARKETPLACES[ $marketplace_id ]['country'],
			'currency'       => self::MARKETPLACES[ $marketplace_id ]['currency'],
			'site'           => self::MARKETPLACES[ $marketplace_id ]['site'],
		);
	}

	/**
	 * Send request to eBay API.
	 *
	 * @param string  $endpoint   API Endpoint.
	 * @param string  $method     HTTP Method.
	 * @param array   $body       Request body.
	 * @param array   $params     Query parameters.
	 * @param boolean $log_errors Whether to log errors automatically.
	 */
	public function request( $endpoint, $method = 'GET', $body = array(), $params = array(), $log_errors = true ) {
		// Anything but a read changes something on eBay - the Inventory API's
		// quantity update is a PUT that eBay applies to the live listing at
		// once - so a staging copy stops here. Before the token, so a refused
		// write never sets off a token refresh either.
		$method = strtoupper( (string) $method );
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			$path    = explode( '?', (string) $endpoint, 2 );
			$blocked = $this->blocked_write( $method . ' ' . $path[0] );
			if ( $blocked ) {
				return $blocked;
			}
		}

		$token = TCGiant_Sync_OAuth::instance()->get_access_token();

		if ( ! $token ) {
			TCGiant_Sync_Logger::error( 'eBay API Error: No valid access token found.' );
			return new WP_Error( 'no_token', __( 'No valid eBay token.', 'tcgiant-sync' ) );
		}

		$url = self::BASE_URL_PRODUCTION . $endpoint;
		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'timeout' => 30,
		);

		if ( 'GET' !== $method && ! empty( $body ) ) {
			$args['body'] = json_encode( $body );
		}

		$response = $this->request_with_retry( $url, $args );

		if ( is_wp_error( $response ) ) {
			if ( 'rate_limited' !== $response->get_error_code() ) {
				TCGiant_Sync_Logger::error( 'eBay API Request Failed: ' . $response->get_error_message() );
			}
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code > 299 ) {
			if ( $log_errors ) {
				TCGiant_Sync_Logger::error( sprintf( 'eBay API Error (%d): %s', $code, wp_remote_retrieve_body( $response ) ) );
			}
			return new WP_Error( 'api_error', sprintf( 'eBay API Error %d', $code ), $body );
		}

		return $body;
	}

	/**
	 * Send XML request to eBay Trading API.
	 *
	 * @param string $call_name   Name of the Trading API call.
	 * @param string $xml_body    The inner XML body.
	 * @param bool   $allow_retry Whether to allow a single retry on token expiration.
	 * @param int    $max_body_bytes Refuse a response larger than this, before reading it. 0 for no limit.
	 * @return array|WP_Error Parsed API response or error.
	 */
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		// A staging copy may read from eBay but not change anything there.
		//
		// Every call that is not plainly a read is treated as a write, rather
		// than writes being listed: a list has to be remembered whenever a new
		// call is added, and the one that is forgotten - as CompleteSale once
		// was, which marks a real buyer's order shipped - goes out from every
		// copy. First in the method, ahead of the budget check and the call
		// counter, so a refused call is not counted against the day's budget.
		if ( ! self::is_read_call( $call_name ) ) {
			$blocked = $this->blocked_write( $call_name );
			if ( $blocked ) {
				return $blocked;
			}
		}

		// Pre-flight: check if we're approaching the daily call limit. What
		// stops an oversell goes past it - see DAILY_CALL_LIMIT.
		if ( ! $this->bypasses_soft_ceiling( $call_name ) && $this->is_daily_budget_exhausted() ) {
			// The figure that stopped it: every shop's, when the relay's is
			// in use, or this site's own.
			$relay = $this->get_relay_budget();
			TCGiant_Sync_Logger::log( sprintf(
				'eBay daily API budget reached (%d/%d calls used today %s, %d%% ceiling). Blocking %s to preserve remaining quota.',
				false !== $relay ? $this->fleet_calls_now( $relay ) : $this->get_daily_call_count(),
				false !== $relay ? (int) $relay['daily_limit'] : self::DAILY_CALL_LIMIT,
				false !== $relay ? 'by every shop together' : 'by this site',
				(int) ( self::DAILY_CALL_SOFT_CEILING * 100 ),
				$call_name
			), 'warning' );
			return new WP_Error( 'rate_limited', 'Daily API call budget exhausted. Will resume tomorrow.', array( 'daily_budget' => true ) );
		}

		$token = TCGiant_Sync_OAuth::instance()->get_access_token();

		if ( ! $token ) {
			TCGiant_Sync_Logger::error( 'eBay API Error: No valid access token found for Trading API.' );
			return new WP_Error( 'no_token', __( 'No valid eBay token.', 'tcgiant-sync' ) );
		}

		$url = 'https://api.ebay.com/ws/api.dll';

		$xml_envelope = '<?xml version="1.0" encoding="utf-8"?>
<' . $call_name . 'Request xmlns="urn:ebay:apis:eBLBaseComponents">
' . $xml_body . '
</' . $call_name . 'Request>';

		$config = $this->get_marketplace_config();

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'X-EBAY-API-SITEID'              => $config['site_id'],
				'X-EBAY-API-COMPATIBILITY-LEVEL' => '1323',
				'X-EBAY-API-CALL-NAME'           => $call_name,
				'X-EBAY-API-IAF-TOKEN'           => $token,
				'Content-Type'                   => 'text/xml',
			),
			'body'    => $xml_envelope,
			'timeout' => 45,
		);

		// Stop keeping the answer once it passes the ceiling, rather than hold
		// all of something that is about to be refused. One byte over is enough
		// to know.
		if ( $max_body_bytes > 0 ) {
			$args['limit_response_size'] = $max_body_bytes + 1;
		}

		$this->last_body_bytes = 0;

		$response = $this->request_with_retry( $url, $args );

		// Track the call regardless of outcome — eBay counts it either way.
		$this->increment_daily_call_count();

		// request_with_retry() surfaces HTTP 429 as a rate_limited error so the
		// importer's existing backoff handling picks it up.
		if ( is_wp_error( $response ) && 'rate_limited' === $response->get_error_code() ) {
			return $response;
		}

		if ( is_wp_error( $response ) ) {
			TCGiant_Sync_Logger::error( 'eBay Trading API Request Failed: ' . $response->get_error_message() );
			return $response;
		}

		// The answer is read through four forms - the raw text, an XML tree, a
		// JSON string and finally the array - and every one of them used to be
		// kept until this function returned, along with a second copy of the
		// raw text inside the response. For a large answer that alone was
		// enough to exhaust a 512 MB limit. Each form is now let go as soon as
		// the next exists, which roughly halves the peak and returns most of
		// the memory before the import that follows begins. None of it changes
		// the array handed back.
		$body = wp_remote_retrieve_body( $response );
		unset( $response );

		$this->last_body_bytes = strlen( $body );

		if ( $max_body_bytes > 0 && $this->last_body_bytes > $max_body_bytes ) {
			unset( $body );
			return new WP_Error(
				'response_too_large',
				sprintf( 'eBay\'s answer to %s was larger than %d MB.', $call_name, (int) round( $max_body_bytes / 1048576 ) ),
				array( 'limit' => $max_body_bytes )
			);
		}

		$xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA );
		unset( $body );
		if ( ! $xml ) {
			TCGiant_Sync_Logger::error( 'eBay Trading API Error: Invalid XML Response.' );
			return new WP_Error( 'invalid_xml', 'Invalid XML from eBay Trading API.' );
		}

		// Unescaped, because escaping every non-Latin character as \uXXXX made
		// the same listing cost a German or Russian shop more to read than an
		// English one. It decodes to exactly the same array either way.
		$json = wp_json_encode( $xml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		unset( $xml );
		$array = json_decode( $json, true );
		unset( $json );

		if ( is_array( $array ) && isset( $array['Timestamp'] ) && is_string( $array['Timestamp'] ) ) {
			$stamp = strtotime( $array['Timestamp'] );
			if ( false !== $stamp && $stamp > $this->ebay_time ) {
				$this->ebay_time = (int) $stamp;
			}
		}

		if ( isset( $array['Ack'] ) && 'Failure' === $array['Ack'] ) {
			// Normalize: eBay may return a single error object or an indexed array of errors.
			if ( isset( $array['Errors'][0] ) ) {
				$first_error = $array['Errors'][0];
				$error_parts = array();
				foreach ( $array['Errors'] as $err ) {
					// Prefer LongMessage (detailed) over ShortMessage (generic).
					$msg = $err['LongMessage'] ?? $err['ShortMessage'] ?? '';
					if ( ! empty( $msg ) ) {
						$error_parts[] = $msg;
					}
				}
				$error_msg  = ! empty( $error_parts ) ? implode( ' | ', $error_parts ) : 'Unknown Trading API Error';
				$error_code = isset( $first_error['ErrorCode'] ) ? (string) $first_error['ErrorCode'] : '';
			} else {
				$error_msg  = $array['Errors']['LongMessage'] ?? $array['Errors']['ShortMessage'] ?? 'Unknown Trading API Error';
				$error_code = isset( $array['Errors']['ErrorCode'] ) ? (string) $array['Errors']['ErrorCode'] : '';
			}

			// Check for token expiration / invalid token errors:
			// 21916013 / 21917053 = "IAF token expired" or "Token expiration time exceeded"
			$token_error_codes = array( '21916013', '21917053', '21916017', '21917054' );
			$error_str = is_string( $error_msg ) ? strtolower( $error_msg ) : '';
			$is_token_expired = in_array( $error_code, $token_error_codes, true )
				|| false !== strpos( $error_str, 'token expiration' )
				|| false !== strpos( $error_str, 'token expired' )
				|| false !== strpos( $error_str, 'iaf token' );

			if ( $is_token_expired ) {
				if ( $allow_retry ) {
					TCGiant_Sync_Logger::log( sprintf( 'eBay token expired during %s (%s). Refreshing token and retrying...', $call_name, $error_msg ), 'info' );
					$new_token = TCGiant_Sync_OAuth::instance()->refresh_access_token();
					if ( $new_token ) {
						return $this->trading_api_request( $call_name, $xml_body, false, $max_body_bytes );
					}
				}

				// eBay will not take the token, and no fresh one could be had.
				// That says nothing about what was asked for, so it is reported
				// as no token - which the delta waits out - rather than as an
				// error about the listing, which it moved past.
				TCGiant_Sync_Logger::error( sprintf( 'eBay Trading API Error (%s): %s - the eBay token could not be refreshed.', $call_name, is_string( $error_msg ) ? $error_msg : 'token rejected' ) );
				return new WP_Error( 'no_token', is_string( $error_msg ) ? $error_msg : __( 'No valid eBay token.', 'tcgiant-sync' ), $array );
			}

			// eBay rate limit error codes:
			// 518   = "Calls to this call have exceeded the call limit."
			// 10007 = "Internal error to the application." (daily limit)
			$rate_limit_codes = array( '518', '10007' );
			$is_rate_limited = false;

			if ( in_array( $error_code, $rate_limit_codes, true ) ) {
				$is_rate_limited = true;
			} elseif ( isset( $array['Errors'][0] ) ) {
				// Multiple errors returned as indexed array.
				foreach ( $array['Errors'] as $err ) {
					if ( isset( $err['ErrorCode'] ) && in_array( (string) $err['ErrorCode'], $rate_limit_codes, true ) ) {
						$is_rate_limited = true;
						$error_msg = $err['ShortMessage'] ?? $error_msg;
						break;
					}
				}
			}

			// Also detect rate limiting from HTTP 429 or specific rate limit error messages.
			// Note: Do not match plain "exceeded" as it causes false positives on token/item errors.
			if ( ! $is_rate_limited ) {
				if ( false !== strpos( $error_str, 'call limit' ) || false !== strpos( $error_str, 'rate limit' ) || false !== strpos( $error_str, 'limit exceeded' ) || false !== strpos( $error_str, 'quota exceeded' ) ) {
					$is_rate_limited = true;
				}
			}

			if ( $is_rate_limited ) {
				$daily_count = $this->get_daily_call_count();
				TCGiant_Sync_Logger::log( sprintf(
					'eBay API rate limit reached during %s (%d calls today). Will retry later.',
					$call_name, $daily_count
				), 'warning' );
				return new WP_Error( 'rate_limited', is_string( $error_msg ) ? $error_msg : 'API call limit exceeded', $array );
			}

			TCGiant_Sync_Logger::error( sprintf( 'eBay Trading API Error (%s): %s', $call_name, is_string( $error_msg ) ? $error_msg : wp_json_encode( $error_msg ) ) );
			return new WP_Error( 'api_error', is_string( $error_msg ) ? $error_msg : 'API Error', $array );
		}

		return $array;
	}

	/**
	 * Whether a Trading API call only reads.
	 *
	 * eBay names every call that only reads Get... or Verify... (VerifyAddItem
	 * checks a listing and quotes its fees without creating it). Anything else
	 * may change the seller's account.
	 *
	 * @param string $name Trading API call name.
	 * @return bool
	 */
	public static function is_read_call( $name ) {
		return 1 === preg_match( '/^(Get|Verify)[A-Za-z]+$/', (string) $name );
	}

	/**
	 * eBay's own Timestamp on the latest Trading answer this request read,
	 * as a Unix time; 0 before any (3.28.0 release fix round 2).
	 *
	 * @return int
	 */
	public function ebay_time() {
		return $this->ebay_time;
	}

	/**
	 * Run a stock push's calls with the soft ceiling lifted for them.
	 *
	 * For the pushes that stop an oversell: a website sale, a manual push,
	 * and anything going to 0. Only the calls in STOCK_PRIORITY_CALLS go
	 * past it; any other call made inside is gated as usual, and eBay's own
	 * limit still applies to all of them. The staging guard comes before
	 * any of this.
	 *
	 * A counter, not a flag, so a nested call cannot end the outer one's
	 * priority; and parameters are not added to trading_api_request() or
	 * get_item(), which harnesses and other plugins override.
	 *
	 * @param callable $fn What to run.
	 * @return mixed What it returns.
	 */
	public function with_stock_priority( callable $fn ) {
		$this->stock_priority++;

		try {
			return $fn();
		} finally {
			$this->stock_priority--;
		}
	}

	/**
	 * Whether a call goes past the 80% soft ceiling.
	 *
	 * @param string $call_name Trading API call.
	 * @return bool
	 */
	private function bypasses_soft_ceiling( $call_name ) {
		return 'CompleteSale' === $call_name
			|| ( $this->stock_priority > 0 && in_array( $call_name, self::STOCK_PRIORITY_CALLS, true ) );
	}

	/**
	 * eBay's error codes in a Trading API refusal.
	 *
	 * trading_api_request() hands back eBay's decoded answer as the error's
	 * data; Errors holds one error, or a list of them.
	 *
	 * @param mixed $error WP_Error from a Trading call.
	 * @return string[] ErrorCode of each, in eBay's order.
	 */
	public static function error_codes( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return array();
		}

		$data   = $error->get_error_data();
		$errors = is_array( $data ) && isset( $data['Errors'] ) && is_array( $data['Errors'] ) ? $data['Errors'] : array();

		if ( isset( $errors['ErrorCode'] ) || isset( $errors['ShortMessage'] ) || isset( $errors['LongMessage'] ) ) {
			$errors = array( $errors );
		}

		$codes = array();
		foreach ( $errors as $one ) {
			if ( is_array( $one ) && isset( $one['ErrorCode'] ) ) {
				$code = self::scalar_value( $one['ErrorCode'] );
				if ( '' !== $code ) {
					$codes[] = $code;
				}
			}
		}

		return $codes;
	}

	/**
	 * Whether a failed call is worth trying again later, as opposed to an
	 * answer from eBay about the listing.
	 *
	 * Out of calls, no token, a timeout, an answer that could not be read,
	 * or anything that never reached eBay at all. A copy's refusal
	 * (staging_blocked) is final: it will be refused again, and is never
	 * tried again.
	 *
	 * @param mixed $error WP_Error.
	 * @return bool
	 */
	public static function is_transient_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$code = (string) $error->get_error_code();

		if ( 'staging_blocked' === $code ) {
			return false;
		}

		if ( in_array( $code, array( 'rate_limited', 'no_token', 'invalid_xml', 'http_request_failed', 'response_too_large' ), true ) ) {
			return true;
		}

		$codes = self::error_codes( $error );

		// No answer from eBay at all: the request did not get through.
		if ( ! $codes ) {
			$data = $error->get_error_data();
			return ! ( is_array( $data ) && isset( $data['Errors'] ) );
		}

		return (bool) array_intersect( $codes, array( '518', '10007' ) );
	}

	/**
	 * The refusal for a change this site may not send to eBay, or null.
	 *
	 * @param string $what What was asked for, for the log.
	 * @return WP_Error|null
	 */
	private function blocked_write( $what ) {
		$blocked = TCGiant_Sync_Environment::write_block();
		if ( $blocked ) {
			TCGiant_Sync_Environment::note_blocked( $what, $blocked );
		}
		return $blocked;
	}

	/**
	 * Whether an HTTP request on its way to eBay would change something there.
	 *
	 * A Trading API call is always a POST, reads included, so it is judged by
	 * the call it names; judging it by its method would stop every read.
	 *
	 * @param array $args wp_remote_request() arguments.
	 * @return bool
	 */
	private static function is_write_request( $args ) {
		if ( isset( $args['headers']['X-EBAY-API-CALL-NAME'] ) ) {
			return ! self::is_read_call( $args['headers']['X-EBAY-API-CALL-NAME'] );
		}

		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		return 'GET' !== $method && 'HEAD' !== $method;
	}

	/**
	 * The day's Trading API call count: one wp_options row per UTC day, named
	 * this and the date, never autoloaded (3.28.0).
	 *
	 * It was a transient, _transient_tcgiant_api_calls_<date>. A transient is
	 * fair game for any clean-up - WP Rocket's, LiteSpeed's, WP-Optimize's,
	 * `wp transient delete --all` - and once its row was deleted on a host
	 * with a persistent object cache, add_option() found the value it had
	 * cached and refused to add the row again: the count read 0 until UTC
	 * midnight.
	 */
	const CALL_COUNT_PREFIX = 'tcgiant_sync_api_calls_';

	/**
	 * 3.27's name for the count, for carrying the update's day over and
	 * clearing what it left.
	 */
	const CALL_COUNT_OLD_PREFIX = '_transient_tcgiant_api_calls_';

	/**
	 * The UTC day the counts were last tidied - see tidy_call_counts().
	 */
	const CALL_COUNT_TIDIED_OPTION = 'tcgiant_sync_call_count_tidied';

	/**
	 * Days' counts kept before the day of the call that tidies. A week: the
	 * rows are tiny, and a server clock running up to a week ahead, even for
	 * one call, then cannot take today's count with the old ones.
	 */
	const CALL_COUNT_KEEP_DAYS = 7;

	/**
	 * Get the number of Trading API calls made today.
	 *
	 * Read from the row increment_daily_call_count() writes, in the table
	 * itself. It was read with get_transient(), which on a site with a
	 * persistent object cache - Redis, Memcached, LiteSpeed's, WordPress.com -
	 * reads the cache and never the table, and the increment empties that
	 * cache key: those sites counted 0 all day, reported 0 to the relay, and
	 * their own 80% ceiling never came into play. The day is the UTC day, as
	 * the increment's.
	 *
	 * @return int Number of calls made today.
	 */
	public function get_daily_call_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			self::CALL_COUNT_PREFIX . gmdate( 'Y-m-d' )
		) );
	}

	/**
	 * Increment the daily Trading API call counter.
	 *
	 * One statement, which the database makes atomic whoever else is counting:
	 * a new row at 1 for the first call of the day, or 1 more. It never asks
	 * the object cache, so neither a value cached elsewhere nor a row deleted
	 * under it can stop it counting. The first call of the day used to try an
	 * UPDATE and then add_option(), and two workers there at once each wrote
	 * 1, losing a call.
	 */
	private function increment_daily_call_count() {
		global $wpdb;

		$date = gmdate( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, '1', 'no' )
			 ON DUPLICATE KEY UPDATE option_value = option_value + 1",
			self::CALL_COUNT_PREFIX . $date
		) );

		if ( get_option( self::CALL_COUNT_TIDIED_OPTION, '' ) !== $date ) {
			$this->tidy_call_counts( $date );
		}
	}

	/**
	 * Once a day, at its first call: carry over the count 3.27 kept for the
	 * day the plugin was updated, and delete counts no longer read - those
	 * older than CALL_COUNT_KEEP_DAYS, and every one 3.27 kept. On a host with
	 * a persistent object cache WordPress never deletes an expired transient's
	 * rows, so 3.27's counts piled up there, two rows a day.
	 *
	 * @param string $date Today, UTC, Y-m-d.
	 * @return void
	 */
	private function tidy_call_counts( $date ) {
		global $wpdb;

		// First, so the requests after this one have nothing to do.
		update_option( self::CALL_COUNT_TIDIED_OPTION, $date, true );

		// Taken out of 3.27's row in one statement, only while the row still
		// holds at least what was read: so two requests tidying at once
		// cannot both add it, nor one add a row 3.27 started again. A 3.27
		// job still counting into the row meanwhile no longer stops the
		// carry: it used to make the DELETE match nothing, and the whole
		// count was lost with the row below. Only its own calls since the
		// read are left in the row (fix round 2).
		$old = self::CALL_COUNT_OLD_PREFIX . $date;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$kept = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $old ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $kept > 0 && $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value - %d WHERE option_name = %s AND option_value + 0 >= %d", $kept, $old, $kept ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = option_value + %d WHERE option_name = %s",
				(int) $kept,
				self::CALL_COUNT_PREFIX . $date
			) );
		}

		$oldest = gmdate( 'Y-m-d', (int) strtotime( $date . ' 00:00:00 UTC' ) - self::CALL_COUNT_KEEP_DAYS * DAY_IN_SECONDS );

		// The LIKE patterns escaped as wpdb::esc_like() escapes them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options}
			 WHERE ( option_name LIKE %s AND option_name < %s )
			    OR option_name LIKE %s
			    OR option_name LIKE %s",
			addcslashes( self::CALL_COUNT_PREFIX, '_%\\' ) . '%',
			self::CALL_COUNT_PREFIX . $oldest,
			addcslashes( self::CALL_COUNT_OLD_PREFIX, '_%\\' ) . '%',
			addcslashes( '_transient_timeout_tcgiant_api_calls_', '_%\\' ) . '%'
		) );
	}

	/**
	 * Perform an HTTP request with retries for transient failures.
	 *
	 * A single DNS blip, connection reset or 5xx used to fail the whole job,
	 * because the caller returned immediately on is_wp_error(). eBay's 429
	 * responses were not recognised at all.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args wp_remote_request() arguments.
	 * @return array|WP_Error Response array, or WP_Error after the final attempt.
	 */
	private function request_with_retry( $url, $args ) {
		// The last door to eBay. Every method that changes something already
		// asks before it gets here, and logs why; this only makes sure a method
		// added later that forgets to cannot send from a staging copy.
		if ( self::is_write_request( $args ) ) {
			$blocked = TCGiant_Sync_Environment::write_block();
			if ( $blocked ) {
				return $blocked;
			}
		}

		$max_attempts = 3;
		$response     = null;

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$response = wp_remote_request( $url, $args );

			if ( ! is_wp_error( $response ) ) {
				$code = (int) wp_remote_retrieve_response_code( $response );

				// Explicit throttling — honour Retry-After when eBay sends it.
				if ( 429 === $code ) {
					$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
					TCGiant_Sync_Logger::warning( sprintf(
						'eBay returned HTTP 429 (rate limited)%s.',
						$retry_after ? sprintf( ', Retry-After: %ds', $retry_after ) : ''
					) );
					return new WP_Error(
						'rate_limited',
						'eBay returned HTTP 429 (too many requests).',
						array( 'retry_after' => $retry_after )
					);
				}

				// Retry server-side failures; anything else is the caller's to handle.
				if ( $code < 500 || $attempt === $max_attempts ) {
					return $response;
				}

				TCGiant_Sync_Logger::log( sprintf(
					'eBay returned HTTP %d — attempt %d of %d, retrying.',
					$code, $attempt, $max_attempts
				), 'warning' );
			} elseif ( $attempt === $max_attempts ) {
				return $response;
			} else {
				TCGiant_Sync_Logger::log( sprintf(
					'eBay request failed (%s) — attempt %d of %d, retrying.',
					$response->get_error_message(), $attempt, $max_attempts
				), 'warning' );
			}

			// Exponential backoff with jitter, capped so we never stall a
			// worker for long: ~1s, ~2s.
			usleep( ( ( 2 ** ( $attempt - 1 ) ) * 1000000 ) + wp_rand( 0, 250000 ) );
		}

		return $response;
	}

	/**
	 * Check if the daily API call budget is exhausted.
	 *
	 * Checks the relay-provided global budget first (all client sites combined).
	 * Falls back to local-only tracking when relay data is unavailable.
	 *
	 * @return bool True if the daily budget is exhausted.
	 */
	public function is_daily_budget_exhausted() {
		// 1. Check global budget from relay (preferred — covers all client sites).
		$relay = $this->get_relay_budget();
		if ( false !== $relay ) {
			$global_ceiling   = (int) ( $relay['daily_limit'] * self::DAILY_CALL_SOFT_CEILING );
			$global_used      = $this->fleet_calls_now( $relay );
			return $global_used >= $global_ceiling;
		}

		// 2. Fallback: local-only tracking (no relay data available).
		$count   = $this->get_daily_call_count();
		$ceiling = (int) ( self::DAILY_CALL_LIMIT * self::DAILY_CALL_SOFT_CEILING );
		return $count >= $ceiling;
	}

	/**
	 * Get remaining daily API call budget.
	 *
	 * Uses relay-provided global data when available, otherwise local estimate.
	 *
	 * @return int Estimated remaining calls before soft ceiling.
	 */
	public function get_remaining_daily_budget() {
		// 1. Relay-informed global budget.
		$relay = $this->get_relay_budget();
		if ( false !== $relay ) {
			$ceiling = (int) ( $relay['daily_limit'] * self::DAILY_CALL_SOFT_CEILING );
			$used    = $this->fleet_calls_now( $relay );
			return max( 0, $ceiling - $used );
		}

		// 2. Fallback: local-only.
		$count   = $this->get_daily_call_count();
		$ceiling = (int) ( self::DAILY_CALL_LIMIT * self::DAILY_CALL_SOFT_CEILING );
		return max( 0, $ceiling - $count );
	}

	/**
	 * Every shop's calls today as far as this site can tell: the relay's
	 * figure from the last token refresh, plus this site's own calls since.
	 *
	 * The relay's figure can be two hours old, and a busy site spent
	 * thousands of calls in that time with nothing counting them against the
	 * ceiling. The refresh keeps this site's own count at the moment it was
	 * sent (local_calls). A figure kept by 3.27, without it, is used as it was.
	 *
	 * @param array $relay get_relay_budget()'s answer.
	 * @return int
	 */
	private function fleet_calls_now( array $relay ) {
		$used = (int) $relay['global_calls'];

		if ( isset( $relay['local_calls'] ) ) {
			$used += max( 0, $this->get_daily_call_count() - (int) $relay['local_calls'] );
		}

		return $used;
	}

	/**
	 * Get the relay-provided global API budget data.
	 *
	 * Returns the cached budget array from the last token refresh, or false
	 * if no relay data is available or the data is older than 2 hours.
	 *
	 * @return array|false Budget array with keys: remaining, global_calls, daily_limit, updated_at.
	 */
	public function get_relay_budget() {
		$date_key = gmdate( 'Y-m-d' );
		$budget   = get_transient( 'tcgiant_relay_budget_' . $date_key );

		if ( ! is_array( $budget ) || empty( $budget['updated_at'] ) ) {
			return false;
		}

		// Treat relay data as stale after 2 hours to avoid acting on outdated info.
		if ( ( time() - (int) $budget['updated_at'] ) > 2 * HOUR_IN_SECONDS ) {
			return false;
		}

		return $budget;
	}

	/**
	 * Get all active listings via Trading API.
	 *
	 * @param int    $page_number     Page number (1-indexed).
	 * @param int    $entries_per_page Limit per page.
	 * @param string $mod_time_from   Optional ISO 8601 timestamp. When provided, uses
	 *                                ModTimeFrom/ModTimeTo to only return items modified
	 *                                since this time (delta sync mode). When omitted,
	 *                                uses EndTimeFrom/EndTimeTo for a full scan.
	 * @param string $end_time_from   Optional ISO 8601 timestamp: for a full scan, the
	 *                                listings ending from then on, rather than from now.
	 *                                The importer passes the moment its scan began.
	 */
	public function get_active_listings( $page_number = 1, $entries_per_page = 200, $mod_time_from = '', $end_time_from = '' ) {
		// Use GetSellerList instead of GetMyeBaySelling because only GetSellerList
		// returns Storefront (StoreCategoryID) data needed for store category filtering.

		// GetSellerList requires either EndTime or ModTime range — they are mutually exclusive.
		if ( ! empty( $mod_time_from ) ) {
			// Delta sync mode: only items modified since the given timestamp.
			$xml = '
<ModTimeFrom>' . $mod_time_from . '</ModTimeFrom>
<ModTimeTo>' . gmdate( 'Y-m-d\TH:i:s.000\Z' ) . '</ModTimeTo>';
		} else {
			// Full scan mode: all active listings.
			// eBay limits EndTimeFrom to EndTimeTo range to a maximum of 120 days.
			// Active GTC listings renew every 30 days, so +120 days captures all active items.
			//
			// From when the scan began, when the importer says so: the same
			// window on every page, so a listing that ends between two reads
			// stays in the answer, ended, rather than dropping out and moving
			// every listing after it up a place - see scan_end_from().
			$from_ts  = '' !== (string) $end_time_from ? strtotime( (string) $end_time_from ) : false;
			$from_ts  = false === $from_ts ? time() : $from_ts;
			$end_from = gmdate( 'Y-m-d\TH:i:s.000\Z', $from_ts );
			$end_to   = gmdate( 'Y-m-d\TH:i:s.000\Z', $from_ts + 120 * DAY_IN_SECONDS );
			$xml = '
<EndTimeFrom>' . $end_from . '</EndTimeFrom>
<EndTimeTo>' . $end_to . '</EndTimeTo>';
		}

		$xml .= '
<DetailLevel>ReturnAll</DetailLevel>
<Pagination>
	<EntriesPerPage>' . (int) $entries_per_page . '</EntriesPerPage>
	<PageNumber>' . (int) $page_number . '</PageNumber>
</Pagination>
<IncludeItemSpecifics>true</IncludeItemSpecifics>
<IncludeVariationSpecifics>true</IncludeVariationSpecifics>
<HideVariations>false</HideVariations>';

		return $this->trading_api_request( 'GetSellerList', $xml );
	}

	/**
	 * Get modified items with full detail via GetSellerEvents (Trading API).
	 *
	 * Unlike GetSellerList, GetSellerEvents returns complete item data inline
	 * (Description, ItemSpecifics, Variations, PictureDetails, ShippingPackageDetails, etc.)
	 * so individual GetItem calls are not needed.
	 *
	 * @param string $mod_time_from ISO 8601 UTC timestamp (start of window).
	 * @param string $mod_time_to   ISO 8601 UTC timestamp (end of window). Max 48hr span.
	 * @param int    $max_body_bytes Refuse an answer larger than this rather than read it. 0 for no limit.
	 * @return array|WP_Error Parsed response or error.
	 */
	public function get_seller_events( $mod_time_from, $mod_time_to, $max_body_bytes = 0 ) {
		// In English, as the listing calls already ask. The importer knows
		// eBay's "too many results" refusal by its wording, and eBay answers in
		// the language of the seller's own site - so for a shop on eBay.de or
		// eBay.fr the refusal went unrecognised and the window never narrowed.
		$xml = '<ErrorLanguage>en_US</ErrorLanguage>
<ModTimeFrom>' . $mod_time_from . '</ModTimeFrom>
<ModTimeTo>' . $mod_time_to . '</ModTimeTo>
<DetailLevel>ReturnAll</DetailLevel>
<IncludeItemSpecifics>true</IncludeItemSpecifics>
<IncludeVariationSpecifics>true</IncludeVariationSpecifics>
<HideVariations>false</HideVariations>';

		return $this->trading_api_request( 'GetSellerEvents', $xml, true, $max_body_bytes );
	}

	/**
	 * Get full item details via Trading API.
	 *
	 * @param string $item_id The Item ID.
	 */
	/**
	 * eBay's clock: the cheapest call there is, for finding out whether eBay
	 * answers at all.
	 *
	 * @return array|WP_Error
	 */
	public function get_official_time() {
		return $this->trading_api_request( 'GeteBayOfficialTime', '' );
	}

	public function get_item( $item_id ) {
		$xml = '
<ItemID>' . esc_attr( $item_id ) . '</ItemID>
<DetailLevel>ReturnAll</DetailLevel>
<IncludeItemSpecifics>true</IncludeItemSpecifics>';

		return $this->trading_api_request( 'GetItem', $xml );
	}

	/**
	 * The eBay account these credentials belong to.
	 *
	 * Cached for a day. Used to prove that a listing somebody is linking a
	 * product to is actually theirs: GetItem answers for any public listing on
	 * eBay, so without this the plugin would cheerfully attach a product to a
	 * stranger's item and then try to revise it on every push.
	 *
	 * Returns '' when it cannot be determined, which callers should treat as
	 * "unknown", not as "no match".
	 *
	 * @return string
	 */
	public function get_seller_user_id() {
		$cached = get_transient( 'tcgiant_ebay_user_id' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = $this->trading_api_request( 'GetUser', '<DetailLevel>ReturnSummary</DetailLevel>' );

		if ( is_wp_error( $response ) || empty( $response['User']['UserID'] ) ) {
			return '';
		}

		$user_id = (string) $response['User']['UserID'];
		set_transient( 'tcgiant_ebay_user_id', $user_id, DAY_IN_SECONDS );

		return $user_id;
	}

	/**
	 * Get user's eBay Store Details and Categories via Trading API.
	 */
	public function get_store() {
		$xml = '<DetailLevel>ReturnAll</DetailLevel><CategoryStructureOnly>true</CategoryStructureOnly>';
		return $this->trading_api_request( 'GetStore', $xml );
	}

	/**
	 * Get all active inventory items.
	 *
	 * @param int $limit  Results per page.
	 * @param int $offset Offset.
	 */
	public function get_inventory_items( $limit = 100, $offset = 0 ) {
		return $this->request( 'sell/inventory/v1/inventory_item', 'GET', array(), array(
			'limit'  => $limit,
			'offset' => $offset,
		) );
	}

	/**
	 * Get inventory item by SKU.
	 *
	 * @param string  $sku        The SKU.
	 * @param boolean $log_errors Whether to log errors automatically.
	 */
	public function get_inventory_item( $sku, $log_errors = true ) {
		return $this->request( 'sell/inventory/v1/inventory_item/' . rawurlencode( $sku ), 'GET', array(), array(), $log_errors );
	}

	/**
	 * Get offers for a specific SKU.
	 *
	 * @param string $sku The SKU.
	 */
	public function get_offers( $sku ) {
		// add_query_arg() does not encode what it is given: a SKU with a space,
		// a plus or an ampersand would ask for another SKU, or for nothing.
		return $this->request( 'sell/inventory/v1/offer', 'GET', array(), array(
			'sku' => rawurlencode( (string) $sku ),
		) );
	}

	/**
	 * Update inventory item quantity.
	 * 
	 * Note: Inventory API uses SKU to manage availability.
	 *
	 * @deprecated 3.28.0 The plugin no longer calls this: stock changes go
	 *             through TCGiant_Sync_Inventory::process_stock_push(), which
	 *             finds the listing from the product itself. Kept for other
	 *             code that calls it, including its old end-at-0 behaviour.
	 *
	 * @param string $sku      The SKU eBay knows the product by.
	 * @param int    $quantity New quantity.
	 * @param string $item_id  The listing the product follows, when the caller
	 *                         knows it.
	 */
	public function update_inventory_item_availability( $sku, $quantity, $item_id = '' ) {
		// Check if this is a Trading API listing (EBAY-{ItemID} SKU pattern).
		// These listings don't exist in the Inventory (REST) API and will always 404.
		//
		// The number names the listing the SKU was made from. A product that
		// has moved on to a relist can still carry it - eBay copies the SKU on
		// to the relist, and the import writes eBay's SKU back - and the change
		// then went to the listing that had ended. The listing the product
		// follows decides: the one the caller names, else the one the product
		// with this SKU follows (its parent's, for a variation), and the number
		// in the SKU only when no product has it.
		if ( preg_match( '/^EBAY-(\d+)$/', $sku, $matches ) ) {
			$item_id = (string) $item_id;
			if ( 1 !== preg_match( '/^[1-9]\d{0,18}$/', $item_id ) ) {
				$item_id = (string) $this->resolve_ebay_item_id_from_sku( $sku );
			}
			return $this->update_trading_api_stock( 1 === preg_match( '/^[1-9]\d{0,18}$/', $item_id ) ? $item_id : $matches[1], $quantity, $sku );
		}

		// Try the Inventory API first.
		$item = $this->get_inventory_item( $sku, false );

		// If 404, the listing was created via Trading API with a custom SKU.
		// Fall back to ReviseInventoryStatus using the stored eBay Item ID.
		if ( is_wp_error( $item ) ) {
			$error_data = $item->get_error_data();
			$is_not_found = ( isset( $error_data['errors'][0]['errorId'] ) && 25710 === $error_data['errors'][0]['errorId'] );

			if ( $is_not_found ) {
				// The listing the caller names, else the one the product with
				// this SKU follows.
				$item_id = 1 === preg_match( '/^[1-9]\d{0,18}$/', (string) $item_id ) ? (string) $item_id : $this->resolve_ebay_item_id_from_sku( $sku );
				if ( $item_id ) {
					return $this->update_trading_api_stock( $item_id, $quantity, $sku );
				}
				
				return new WP_Error( 'not_found_on_ebay', __( 'Item not found on eBay. It may not be linked.', 'tcgiant-sync' ) );
			}
			return $item;
		}

		$item['availability']['shipToLocationAvailability']['quantity'] = (int) $quantity;
		
		return $this->request( 'sell/inventory/v1/inventory_item/' . rawurlencode( $sku ), 'PUT', $item );
	}

	/**
	 * Update stock quantity via the Trading API's ReviseInventoryStatus.
	 *
	 * This works for listings created through the Trading API (legacy listings)
	 * that don't exist in the Inventory (REST) API.
	 *
	 * @deprecated 3.28.0 Use revise_inventory_status(), which never turns 0
	 *             into EndItem and leaves the caller to say which variation.
	 *             Kept, with its end-at-0 behaviour, for other code calling it.
	 *
	 * @param string $item_id  The eBay Item ID (numeric).
	 * @param int    $quantity New stock quantity.
	 * @param string $sku      The SKU, sent only for a variation.
	 * @return array|WP_Error API response or error.
	 */
	public function update_trading_api_stock( $item_id, $quantity, $sku = '' ) {
		// eBay's ReviseInventoryStatus rejects qty = 0.
		// When stock is depleted, the correct action is to end the listing.
		if ( (int) $quantity <= 0 ) {
			return $this->end_item( $item_id );
		}

		// An Item ID alone identifies a listing, but not which variation within
		// one. eBay requires both for a listing that has variations, and without
		// the SKU it cannot tell which quantity is being set — so stock changes on
		// a variable product had nothing to land on.
		//
		// The SKU is only sent when the product really is a variation. On an
		// ordinary listing eBay only accepts a SKU if the listing was created to
		// be tracked that way, so sending one regardless would break the plain
		// case to fix the awkward one.
		$sku_xml = '';
		if ( '' !== (string) $sku && self::sku_belongs_to_variation( $sku ) ) {
			$sku_xml = "\t" . '<SKU>' . esc_xml( (string) $sku ) . '</SKU>' . "\n";
		}

		$xml = '
<InventoryStatus>
	<ItemID>' . esc_attr( $item_id ) . '</ItemID>
' . $sku_xml . '	<Quantity>' . (int) $quantity . '</Quantity>
</InventoryStatus>';

		return $this->trading_api_request( 'ReviseInventoryStatus', $xml );
	}

	/**
	 * Set what a listing, or one variation of it, has available.
	 *
	 * One ReviseInventoryStatus. Quantity is what is AVAILABLE, not the total
	 * listed. 0 is sent as 0: eBay keeps the listing at 0, hidden from search,
	 * when the seller has Out-of-Stock control on, and refuses it otherwise -
	 * the caller reads the listing back to find out which, rather than
	 * trusting any one error code. Nothing here decides to end a listing.
	 *
	 * A SKU is sent only when the caller gives one: a variation listing needs
	 * it to say which variation, and a listing tracked by SKU needs it too.
	 *
	 * @param string $item_id  The listing.
	 * @param int    $quantity Available quantity; below 0 is sent as 0.
	 * @param string $sku      eBay's SKU for the variation, or ''.
	 * @return array|WP_Error
	 */
	public function revise_inventory_status( $item_id, $quantity, $sku = '' ) {
		$sku_xml = '' !== (string) $sku ? "\t<SKU>" . esc_xml( (string) $sku ) . "</SKU>\n" : '';

		$xml = '
<InventoryStatus>
	<ItemID>' . esc_attr( $item_id ) . '</ItemID>
' . $sku_xml . '	<Quantity>' . max( 0, (int) $quantity ) . '</Quantity>
</InventoryStatus>';

		return $this->trading_api_request( 'ReviseInventoryStatus', $xml );
	}

	/**
	 * Set what several variations of one listing have available, in one
	 * call: eBay takes up to four InventoryStatus entries in a
	 * ReviseInventoryStatus.
	 *
	 * @param string $item_id    The listing.
	 * @param array  $quantities eBay variation SKU => available quantity; at
	 *                           most four (the rest are not sent).
	 * @return array|WP_Error
	 */
	public function revise_inventory_statuses( $item_id, array $quantities ) {
		$xml = '';
		foreach ( array_slice( $quantities, 0, 4, true ) as $sku => $quantity ) {
			$xml .= '
<InventoryStatus>
	<ItemID>' . esc_attr( $item_id ) . '</ItemID>
	<SKU>' . esc_xml( (string) $sku ) . '</SKU>
	<Quantity>' . max( 0, (int) $quantity ) . '</Quantity>
</InventoryStatus>';
		}

		return $this->trading_api_request( 'ReviseInventoryStatus', $xml );
	}

	/**
	 * Whether a SKU belongs to a product variation rather than a whole product.
	 *
	 * @param string $sku WooCommerce SKU.
	 * @return bool
	 */
	private static function sku_belongs_to_variation( $sku ) {
		if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return false;
		}

		$product_id = wc_get_product_id_by_sku( $sku );

		return $product_id && 'product_variation' === get_post_type( $product_id );
	}

	/**
	 * End (close) an eBay listing via Trading API EndItem.
	 *
	 * Used when WooCommerce stock hits 0 — eBay does not allow setting
	 * quantity to 0 via ReviseInventoryStatus, so we end the listing instead.
	 *
	 * @param string $item_id The eBay Item ID (numeric).
	 * @return array|WP_Error API response or error.
	 */
	public function end_item( $item_id ) {
		$xml = '
<ItemID>' . esc_attr( $item_id ) . '</ItemID>
<EndingReason>NotAvailable</EndingReason>';

		$result = $this->trading_api_request( 'EndItem', $xml );

		if ( ! is_wp_error( $result ) ) {
			TCGiant_Sync_Logger::log(
				sprintf( 'eBay listing %s ended (stock = 0).', $item_id ),
				'success'
			);
		}

		return $result;
	}

	/**
	 * Verify an item before listing (dry run).
	 *
	 * Calls VerifyAddItem to check for errors and return estimated fees
	 * without actually creating the listing.
	 *
	 * @param string $item_xml The <Item> XML body (same as AddItem).
	 * @return array|WP_Error Fees array on success, WP_Error on failure.
	 */
	public function verify_add_item( $item_xml ) {
		return $this->verify_answer( $this->trading_api_request( 'VerifyAddItem', $item_xml ) );
	}

	/**
	 * Verify a fixed-price item before listing (dry run).
	 *
	 * The fixed-price twin of verify_add_item(), for what a push would send
	 * through AddFixedPriceItem. A listing with variations can only be made
	 * that way, and VerifyAddItem ignores them, so a variation listing checked
	 * through it was checked without the variations that make it what it is.
	 *
	 * @param string $item_xml The <Item> XML body (same as AddFixedPriceItem).
	 * @return array|WP_Error Fees array on success, WP_Error on failure.
	 */
	public function verify_add_fixed_price_item( $item_xml ) {
		return $this->verify_answer( $this->trading_api_request( 'VerifyAddFixedPriceItem', $item_xml ) );
	}

	/**
	 * The fees and warnings in a Verify call's answer.
	 *
	 * @param array|WP_Error $result The call's answer.
	 * @return array|WP_Error
	 */
	private function verify_answer( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Parse fees from response.
		$fees = array();
		if ( isset( $result['Fees']['Fee'] ) ) {
			$fee_list = isset( $result['Fees']['Fee']['Name'] ) ? array( $result['Fees']['Fee'] ) : $result['Fees']['Fee'];
			foreach ( $fee_list as $fee ) {
				$name   = $fee['Name'] ?? 'Unknown';
				$amount = $fee['Fee'] ?? '0.0';
				if ( is_array( $amount ) ) {
					$amount = $amount['_'] ?? $amount['#'] ?? '0.0';
				}
				$fees[ $name ] = (float) $amount;
			}
		}

		return array(
			'success'  => true,
			'fees'     => $fees,
			'warnings' => $result['Errors'] ?? array(),
		);
	}

	/**
	 * Normalise a scalar value out of eBay's XML→JSON conversion.
	 *
	 * An element carrying attributes (for example
	 * `<CurrentPrice currencyID="USD">29.99</CurrentPrice>`) does not survive
	 * the round-trip as a plain string — depending on the converter it arrives
	 * as an array keyed by '#text', '__text', 'value', or SimpleXML's numeric
	 * '0'. Casting such an array straight to float silently yields 1.0, which
	 * is how eBay orders were being imported with a $1.00 total.
	 *
	 * @param mixed $raw Raw value from a decoded eBay response.
	 * @return string Scalar string, or '' when nothing usable is present.
	 */
	public static function scalar_value( $raw ) {
		if ( is_string( $raw ) || is_numeric( $raw ) ) {
			return (string) $raw;
		}

		if ( is_array( $raw ) ) {
			foreach ( array( '#text', '__text', 'value', 0, '0' ) as $key ) {
				if ( isset( $raw[ $key ] ) && ( is_string( $raw[ $key ] ) || is_numeric( $raw[ $key ] ) ) ) {
					return (string) $raw[ $key ];
				}
			}
		}

		return '';
	}

	/**
	 * Normalise an eBay monetary value to a float.
	 *
	 * @param mixed $raw Raw value from a decoded eBay response.
	 * @return float Amount, or 0.0 when absent/unparseable.
	 */
	public static function money_value( $raw ) {
		$value = self::scalar_value( $raw );
		return ( '' === $value ) ? 0.0 : (float) $value;
	}

	/**
	 * Generate a UUID in the format eBay accepts.
	 *
	 * eBay requires exactly 32 hexadecimal characters. wp_generate_uuid4()
	 * returns 36 characters with hyphens, which is rejected with
	 * "The UUID can only contain Numbers and Letters from A to F".
	 *
	 * @return string 32-character hex UUID.
	 */
	public static function generate_ebay_uuid() {
		return str_replace( '-', '', wp_generate_uuid4() );
	}

	/**
	 * Relist an ended eBay listing.
	 *
	 * @param string $item_id eBay Item ID of the ended listing.
	 * @return array|WP_Error Response on success, WP_Error on failure.
	 */
	/**
	 * Relist an ended fixed-price listing.
	 *
	 * Carries the same restriction as revising: a listing with variations
	 * cannot go through RelistItem. eBay does not treat that as an error — it
	 * relists the item and drops the variations, so an auto-relist would quietly
	 * turn a multi-variation listing into an empty one.
	 *
	 * @param string   $item_id        Ended eBay Item ID.
	 * @param int|null $quantity       The shop's quantity, for a listing
	 *                                 without variations.
	 * @param string   $variations_xml A Variations block giving every
	 *                                 variation's quantity, for one with.
	 * @return array|WP_Error
	 */
	public function relist_fixed_price_item( $item_id, $quantity = null, $variations_xml = '' ) {
		// Without a quantity eBay relists at the quantity the listing was
		// first given, whatever sold since, so the shop's own figure is sent
		// when there is one.
		//
		// A variation listing takes its quantities per variation instead: a
		// variation that had none left when the listing ended comes back at
		// the quantity it was first listed with unless one is sent.
		$xml = '
<Item>
<ItemID>' . esc_attr( $item_id ) . '</ItemID>
<UUID>' . self::generate_ebay_uuid() . '</UUID>'
			. ( null !== $quantity ? "\n<Quantity>" . max( 1, (int) $quantity ) . '</Quantity>' : '' )
			. ( '' !== (string) $variations_xml ? "\n" . $variations_xml : '' ) . '
</Item>';

		$result = $this->trading_api_request( 'RelistFixedPriceItem', $xml );

		if ( ! is_wp_error( $result ) ) {
			$new_item_id = $result['ItemID'] ?? $item_id;
			TCGiant_Sync_Logger::log(
				sprintf( 'eBay listing %s relisted → new Item ID: %s', $item_id, $new_item_id ),
				'success'
			);
		}

		return $result;
	}

	public function relist_item( $item_id, $quantity = null ) {
		$xml = '
<Item>
<ItemID>' . esc_attr( $item_id ) . '</ItemID>
<UUID>' . self::generate_ebay_uuid() . '</UUID>'
			. ( null !== $quantity ? "\n<Quantity>" . max( 1, (int) $quantity ) . '</Quantity>' : '' ) . '
</Item>';

		$result = $this->trading_api_request( 'RelistItem', $xml );

		if ( ! is_wp_error( $result ) ) {
			$new_item_id = $result['ItemID'] ?? $item_id;
			TCGiant_Sync_Logger::log(
				sprintf( 'eBay listing %s relisted → new Item ID: %s', $item_id, $new_item_id ),
				'success'
			);
		}

		return $result;
	}

	/**
	 * Look up the eBay Item ID from a WooCommerce product by its SKU.
	 *
	 * A variation's listing is its parent's: only the parent holds the link.
	 *
	 * @deprecated 3.28.0 Only update_inventory_item_availability() uses it. A
	 *             SKU two products share finds either; stock pushes are now
	 *             routed by the product they are about.
	 *
	 * @param string $sku The product SKU.
	 * @return string|false The eBay Item ID, or false if not found.
	 */
	private function resolve_ebay_item_id_from_sku( $sku ) {
		if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return false;
		}

		$product_id = wc_get_product_id_by_sku( $sku );
		if ( ! $product_id ) {
			return false;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$item_id = $product->get_meta( '_ebay_item_id' );
		if ( empty( $item_id ) && $product->get_parent_id() ) {
			$item_id = get_post_meta( (int) $product->get_parent_id(), '_ebay_item_id', true );
		}

		return ! empty( $item_id ) ? $item_id : false;
	}
	/**
	 * Get recent orders from eBay Fulfillment API.
	 *
	 * @param int $limit  Results per page.
	 * @param int $offset Offset.
	 */
	public function get_orders( $limit = 50, $offset = 0 ) {
		return $this->request( 'sell/fulfillment/v1/order', 'GET', array(), array(
			'limit'  => $limit,
			'offset' => $offset,
			'filter' => 'creationdate:[' . gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '-24 hours' ) ) . '..]'
		) );
	}

	/**
	 * Create a new eBay listing via Trading API AddItem.
	 *
	 * A UUID (v4) is injected into the request to prevent duplicate listings.
	 * If the same UUID is sent twice (e.g. due to network timeout + retry),
	 * eBay will return the original ItemID instead of creating a duplicate.
	 *
	 * @param string $item_xml The <Item>...</Item> XML block to wrap in the request.
	 * @return array|WP_Error Parsed API response or error. On success, contains 'ItemID'.
	 */
	public function add_item( $item_xml ) {
		// Inject UUID for duplicate prevention — insert right after <Item>.
		// Callers that supply their own are left alone: adding a second one gave
		// eBay two <UUID> elements in the same item, which defeats the point of
		// having one at all.
		if ( false === strpos( $item_xml, '<UUID>' ) ) {
			$uuid_tag = '<UUID>' . self::generate_ebay_uuid() . '</UUID>' . "\n";
			$item_xml = preg_replace( '/(<Item>\s*\n?)/', '$1' . $uuid_tag, $item_xml, 1 );
		}

		$xml = '<ErrorLanguage>en_US</ErrorLanguage>
<WarningLevel>High</WarningLevel>
' . $item_xml;
		return $this->trading_api_request( 'AddItem', $xml );
	}

	/**
	 * Revise an existing eBay listing via Trading API ReviseItem.
	 *
	 * @param string $item_xml The <Item>...</Item> XML block to wrap in the request.
	 *                         Must include <ItemID> inside the block.
	 * @return array|WP_Error Parsed API response or error.
	 */
	/**
	 * Create a fixed-price listing.
	 *
	 * eBay treats fixed-price listings as their own kind of call, and a listing
	 * with variations can only be created this way — AddItem has no concept of
	 * them.
	 *
	 * @param string $item_xml Item body, including its own UUID.
	 * @return array|WP_Error
	 */
	public function add_fixed_price_item( $item_xml ) {
		if ( false === strpos( $item_xml, '<UUID>' ) ) {
			$uuid_tag = '<UUID>' . self::generate_ebay_uuid() . '</UUID>' . "\n";
			$item_xml = preg_replace( '/(<Item>\s*\n?)/', '$1' . $uuid_tag, $item_xml, 1 );
		}

		$xml = '<ErrorLanguage>en_US</ErrorLanguage>
<WarningLevel>High</WarningLevel>
' . $item_xml;

		return $this->trading_api_request( 'AddFixedPriceItem', $xml );
	}

	/**
	 * Revise a fixed-price listing.
	 *
	 * Required for anything with variations. eBay states plainly that ReviseItem
	 * does not support multiple-variation listings, and it does not report this
	 * as a failure — the variations are simply ignored while the rest of the
	 * revision goes through, so a seller sees prices update and new variations
	 * never appear.
	 *
	 * @param string $item_xml Item body including ItemID.
	 * @return array|WP_Error
	 */
	public function revise_fixed_price_item( $item_xml ) {
		$xml = '<ErrorLanguage>en_US</ErrorLanguage>
<WarningLevel>High</WarningLevel>
' . $item_xml;

		return $this->trading_api_request( 'ReviseFixedPriceItem', $xml );
	}

	public function revise_item( $item_xml ) {
		$xml = '<ErrorLanguage>en_US</ErrorLanguage>
<WarningLevel>High</WarningLevel>
' . $item_xml;
		return $this->trading_api_request( 'ReviseItem', $xml );
	}

	/**
	 * Fetch fulfillment (shipping) policies from eBay Account API.
	 *
	 * @param string $marketplace_id eBay marketplace ID, default EBAY_US.
	 * @return array|WP_Error List of fulfillment policies or error.
	 */
	public function get_fulfillment_policies( $marketplace_id = null ) {
		if ( null === $marketplace_id ) {
			$config = $this->get_marketplace_config();
			$marketplace_id = $config['marketplace_id'];
		}
		return $this->request( 'sell/account/v1/fulfillment_policy', 'GET', array(), array(
			'marketplace_id' => $marketplace_id,
		) );
	}

	/**
	 * Fetch return policies from eBay Account API.
	 *
	 * @param string $marketplace_id eBay marketplace ID, default EBAY_US.
	 * @return array|WP_Error List of return policies or error.
	 */
	public function get_return_policies( $marketplace_id = null ) {
		if ( null === $marketplace_id ) {
			$config = $this->get_marketplace_config();
			$marketplace_id = $config['marketplace_id'];
		}
		return $this->request( 'sell/account/v1/return_policy', 'GET', array(), array(
			'marketplace_id' => $marketplace_id,
		) );
	}

	/**
	 * Fetch payment policies from eBay Account API.
	 *
	 * @param string $marketplace_id eBay marketplace ID, default EBAY_US.
	 * @return array|WP_Error List of payment policies or error.
	 */
	public function get_payment_policies( $marketplace_id = null ) {
		if ( null === $marketplace_id ) {
			$config = $this->get_marketplace_config();
			$marketplace_id = $config['marketplace_id'];
		}
		return $this->request( 'sell/account/v1/payment_policy', 'GET', array(), array(
			'marketplace_id' => $marketplace_id,
		) );
	}

	// =========================================================================
	// Category Tree (Taxonomy API)
	// =========================================================================

	/**
	 * eBay marketplace ID → Taxonomy API category_tree_id mapping.
	 */
	const CATEGORY_TREE_IDS = array(
		'EBAY_US' => '0',
		'EBAY_GB' => '3',
		'EBAY_CA' => '2',
		'EBAY_AU' => '15',
		'EBAY_DE' => '77',
		'EBAY_FR' => '71',
		'EBAY_IT' => '101',
		'EBAY_ES' => '186',
	);

	/**
	 * Get top-level eBay categories (root children) from the Taxonomy API.
	 * Cached in a transient for 7 days since eBay's tree rarely changes.
	 *
	 * @return array|WP_Error Array of categories [{ id, name, leaf }] or error.
	 */
	public function get_category_tree_root() {
		$config  = $this->get_marketplace_config();
		$tree_id = self::CATEGORY_TREE_IDS[ $config['marketplace_id'] ] ?? '0';

		$transient_key = 'tcgiant_cat_tree_root_' . $tree_id;
		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = $this->request(
			'commerce/taxonomy/v1/category_tree/' . $tree_id,
			'GET',
			array(),
			array(),
			false
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$categories = array();
		if ( ! empty( $response['rootCategoryNode']['childCategoryTreeNodes'] ) ) {
			foreach ( $response['rootCategoryNode']['childCategoryTreeNodes'] as $node ) {
				$categories[] = array(
					'id'   => $node['category']['categoryId'] ?? '',
					'name' => $node['category']['categoryName'] ?? '',
					'leaf' => empty( $node['childCategoryTreeNodes'] ),
				);
			}
		}

		set_transient( $transient_key, $categories, 7 * DAY_IN_SECONDS );
		return $categories;
	}

	/**
	 * Get children of a specific eBay category via the Taxonomy API.
	 *
	 * @param string $category_id The parent category ID.
	 * @return array|WP_Error Array of categories [{ id, name, leaf }] or error.
	 */
	public function get_category_subtree( $category_id ) {
		$config  = $this->get_marketplace_config();
		$tree_id = self::CATEGORY_TREE_IDS[ $config['marketplace_id'] ] ?? '0';

		$transient_key = 'tcgiant_cat_sub_' . $tree_id . '_' . $category_id;
		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = $this->request(
			'commerce/taxonomy/v1/category_tree/' . $tree_id . '/get_category_subtree',
			'GET',
			array(),
			array( 'category_id' => $category_id ),
			false
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$categories = array();
		if ( ! empty( $response['categorySubtreeNode']['childCategoryTreeNodes'] ) ) {
			foreach ( $response['categorySubtreeNode']['childCategoryTreeNodes'] as $node ) {
				$categories[] = array(
					'id'   => $node['category']['categoryId'] ?? '',
					'name' => $node['category']['categoryName'] ?? '',
					'leaf' => empty( $node['childCategoryTreeNodes'] ),
				);
			}
		}

		set_transient( $transient_key, $categories, 7 * DAY_IN_SECONDS );
		return $categories;
	}

	/**
	 * Upload an image to eBay Picture Services (EPS) and return its eBay URL.
	 *
	 * Listings normally reference PictureURL values pointing back at the
	 * WooCommerce site, which requires eBay's fetcher to reach it. That fails
	 * on password-protected sites, behind Cloudflare bot protection, on
	 * staging, and breaks retroactively whenever the domain changes. Hosting
	 * the picture on eBay removes that dependency entirely.
	 *
	 * @param string $file_path Absolute path to a local image file.
	 * @param string $name      Picture name shown in Seller Hub.
	 * @return string|WP_Error EPS FullURL on success.
	 */
	public function upload_picture( $file_path, $name = '' ) {
		// Uploading puts a picture into the seller's eBay account. First, so a
		// staging copy does not read the file or spend a token refresh on it.
		// The exporter does not ask on a copy at all: it decides the photo as
		// one eBay would not take (TCGiant_Sync_Exporter::eps_picture_url()) -
		// with the setting on and no photo on eBay, the listing's photos all go
		// as their addresses on the site; beside a photo already on eBay, a new
		// listing goes without it and a revise keeps the listing's own.
		$blocked = $this->blocked_write( 'UploadSiteHostedPictures' );
		if ( $blocked ) {
			return $blocked;
		}

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'file_missing', __( 'Image file could not be read.', 'tcgiant-sync' ) );
		}

		$token = TCGiant_Sync_OAuth::instance()->get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'no_token', __( 'No valid eBay token.', 'tcgiant-sync' ) );
		}

		if ( $this->is_daily_budget_exhausted() ) {
			return new WP_Error( 'rate_limited', 'Daily API call budget exhausted.' );
		}

		$config   = $this->get_marketplace_config();
		$boundary = 'TCGIANT' . wp_generate_password( 16, false );
		$name     = $name ? mb_substr( $name, 0, 80 ) : basename( $file_path );

		// UploadSiteHostedPictures takes a multipart body: the XML request
		// first, then the raw image bytes.
		$xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
			. '<UploadSiteHostedPicturesRequest xmlns="urn:ebay:apis:eBLBaseComponents">' . "\n"
			. '<PictureName>' . esc_xml( $name ) . '</PictureName>' . "\n"
			. '<PictureSet>Supersize</PictureSet>' . "\n"
			. '</UploadSiteHostedPicturesRequest>';

		$eol  = "\r\n";
		$body = '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="XML Payload"' . $eol
			. 'Content-Type: text/xml;charset=utf-8' . $eol . $eol
			. $xml . $eol
			. '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="dummy"; filename="' . basename( $file_path ) . '"' . $eol
			. 'Content-Transfer-Encoding: binary' . $eol
			. 'Content-Type: application/octet-stream' . $eol . $eol
			. file_get_contents( $file_path ) . $eol
			. '--' . $boundary . '--' . $eol;

		$response = $this->request_with_retry( 'https://api.ebay.com/ws/api.dll', array(
			'method'  => 'POST',
			'headers' => array(
				'X-EBAY-API-SITEID'              => $config['site_id'],
				'X-EBAY-API-COMPATIBILITY-LEVEL' => '1323',
				'X-EBAY-API-CALL-NAME'           => 'UploadSiteHostedPictures',
				'X-EBAY-API-IAF-TOKEN'           => $token,
				'Content-Type'                   => 'multipart/form-data; boundary=' . $boundary,
			),
			'body'    => $body,
			'timeout' => 60,
		) );

		$this->increment_daily_call_count();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$xml_body = simplexml_load_string( wp_remote_retrieve_body( $response ), 'SimpleXMLElement', LIBXML_NOCDATA );
		if ( ! $xml_body ) {
			return new WP_Error( 'invalid_xml', 'Invalid XML from UploadSiteHostedPictures.' );
		}

		$parsed = json_decode( wp_json_encode( $xml_body ), true );

		if ( isset( $parsed['Ack'] ) && in_array( $parsed['Ack'], array( 'Failure', 'PartialFailure' ), true ) ) {
			$errors = $parsed['Errors'] ?? array();
			$first  = isset( $errors[0] ) ? $errors[0] : $errors;
			$msg    = $first['LongMessage'] ?? $first['ShortMessage'] ?? 'Unknown upload error';
			return new WP_Error( 'upload_failed', is_string( $msg ) ? $msg : 'Picture upload failed.' );
		}

		$url = $parsed['SiteHostedPictureDetails']['FullURL'] ?? '';
		if ( empty( $url ) ) {
			return new WP_Error( 'no_url', 'eBay did not return a picture URL.' );
		}

		return $url;
	}

	/**
	 * Get the item aspects (item specifics) eBay defines for a category.
	 *
	 * eBay rejects or downranks listings that omit an aspect marked REQUIRED,
	 * and until now the exporter sent whatever specifics a product happened to
	 * carry with no idea which were mandatory. This is the Taxonomy API
	 * equivalent of the Trading API's GetCategorySpecifics.
	 *
	 * Cached for 7 days — eBay's aspect definitions change rarely, and this is
	 * consulted on every product edit screen.
	 *
	 * @param string $category_id eBay leaf category ID.
	 * @return array|WP_Error List of aspects, each with name/required/cardinality/values.
	 */
	public function get_category_aspects( $category_id ) {
		$category_id = trim( (string) $category_id );
		if ( '' === $category_id ) {
			return new WP_Error( 'no_category', __( 'No eBay category set.', 'tcgiant-sync' ) );
		}

		$config  = $this->get_marketplace_config();
		$tree_id = self::CATEGORY_TREE_IDS[ $config['marketplace_id'] ] ?? '0';

		$transient_key = 'tcgiant_aspects_' . $tree_id . '_' . $category_id;
		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = $this->request(
			'commerce/taxonomy/v1/category_tree/' . $tree_id . '/get_item_aspects_for_category',
			'GET',
			array(),
			array( 'category_id' => $category_id ),
			// Logged. This passed false, so a failed lookup left no trace and was
			// indistinguishable from 'nothing required' - which is how a merchant
			// came to hear about a missing aspect from eBay instead of from us.
			true
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$aspects = array();
		foreach ( $response['aspects'] ?? array() as $aspect ) {
			$constraint = $aspect['aspectConstraint'] ?? array();

			// Suggested values, where eBay provides a closed or guided list.
			$values = array();
			foreach ( $aspect['aspectValues'] ?? array() as $value ) {
				if ( ! empty( $value['localizedValue'] ) ) {
					$values[] = $value['localizedValue'];
				}
			}

			$aspects[] = array(
				'name'        => $aspect['localizedAspectName'] ?? '',
				'required'    => ! empty( $constraint['aspectRequired'] ),
				'multi'       => ( 'MULTI' === ( $constraint['itemToAspectCardinality'] ?? '' ) ),
				'mode'        => $constraint['aspectMode'] ?? 'FREE_TEXT',
				'selection'   => $constraint['aspectUsage'] ?? '',
				'values'      => $values,
			);
		}

		set_transient( $transient_key, $aspects, 7 * DAY_IN_SECONDS );
		return $aspects;
	}

	/**
	 * Get condition policies for a category via the Metadata API.
	 *
	 * Returns the full condition descriptor schema including valid
	 * conditionDescriptorIds and conditionDescriptorValueIds.
	 *
	 * @param string $category_id eBay category ID.
	 * @return array|WP_Error Parsed JSON response or error.
	 */
	public function get_condition_policies( $category_id ) {
		$config = $this->get_marketplace_config();
		$marketplace_id = $config['marketplace_id'];

		return $this->request(
			'sell/metadata/v1/marketplace/' . $marketplace_id . '/get_item_condition_policies',
			'GET',
			array(),
			array( 'filter' => 'categoryId:{' . $category_id . '}' )
		);
	}

	/**
	 * Get suggested categories for a query string (product title) via the Taxonomy API.
	 *
	 * Uses the getCategorySuggestions endpoint to return leaf-level categories
	 * that eBay thinks are the best match for the given title.
	 *
	 * @param string $title The product title to find categories for.
	 * @return array|WP_Error Array of suggestions [{ id, name }] or error.
	 */
	public function get_suggested_categories( $title ) {
		$config  = $this->get_marketplace_config();
		$tree_id = self::CATEGORY_TREE_IDS[ $config['marketplace_id'] ] ?? '0';

		$response = $this->request(
			'commerce/taxonomy/v1/category_tree/' . $tree_id . '/get_category_suggestions',
			'GET',
			array(),
			array( 'q' => $title ),
			false
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$suggestions = array();
		if ( ! empty( $response['categorySuggestions'] ) ) {
			foreach ( $response['categorySuggestions'] as $s ) {
				$cat = $s['category'] ?? array();
				if ( empty( $cat['categoryId'] ) ) {
					continue;
				}
				// Build the full path name from ancestors if available.
				$name = $cat['categoryName'] ?? '';
				if ( ! empty( $s['categoryTreeNodeAncestors'] ) ) {
					$ancestors = array_reverse( $s['categoryTreeNodeAncestors'] );
					$path      = array();
					foreach ( $ancestors as $a ) {
						$path[] = $a['categoryName'] ?? '';
					}
					$path[] = $name;
					$name   = implode( ' > ', array_filter( $path ) );
				}
				$suggestions[] = array(
					'id'   => $cat['categoryId'],
					'name' => $name,
				);
				if ( count( $suggestions ) >= 5 ) {
					break;
				}
			}
		}

		return $suggestions;
	}
}
