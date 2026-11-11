<?php
/**
 * License Manager
 *
 * Handles freemium licensing via LemonSqueezy.
 * Free tier: 50 active synced products.
 * Pro tier:  Unlimited (valid license key required).
 *
 * Since 3.36.0 only a definitive answer from Lemon Squeezy takes Pro away
 * (read_answer()); an answer that says nothing for sure changes nothing, and
 * the next check backs off. The key is checked in the background - a day
 * after it was found valid, 6 hours after it was found not valid - and no
 * page waits on Lemon Squeezy. A renewal date passed holds Pro for
 * GRACE_SECONDS while Lemon Squeezy cannot be asked. A move to Pro imports
 * the new eBay listings the free limit held back (TCGiant_Sync_Limit_Held).
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * TCGiant_Sync_License class
 */
class TCGiant_Sync_License extends TCGiant_Sync_Entitlements
{

	/**
	 * Instance.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * Free tier product limit.
	 */
	const FREE_LIMIT = 50;

	/**
	 * Option key for license data.
	 */
	const LICENSE_OPTION = 'tcgiant_sync_license';

	/**
	 * Transient key for cached validation.
	 */
	const VALIDATION_TRANSIENT = 'tcgiant_sync_license_valid';

	/**
	 * LemonSqueezy API base.
	 */
	const LS_API_BASE = 'https://api.lemonsqueezy.com/v1';

	/**
	 * Upgrade URL.
	 */
	const UPGRADE_URL = 'https://tcgiant.com/pro';

	/**
	 * The owner's Lemon Squeezy store, "TCGiant Sync", which a key's answer
	 * is compared with (check_ids(); logged only). 0: not compared.
	 * Filterable: tcgiant_sync_license_ids.
	 */
	const LS_STORE_ID = 335114;

	/**
	 * The owner's Lemon Squeezy products: one, "TCGiant Sync", whose four
	 * variants are the Founder's Lifetime, Annual, Monthly and StackSocial
	 * plans. A list, since a deal may one day be a product of its own.
	 * Empty: not compared.
	 */
	const LS_PRODUCT_IDS = array(958193);

	/**
	 * Action Scheduler hook of a check of the key with Lemon Squeezy, run in
	 * the background (run_check()), never in a page.
	 */
	const CHECK_HOOK = 'tcgiant_sync_license_check';

	/**
	 * Transient: a check was queued lately, so pages do not queue another.
	 */
	const QUEUED_TRANSIENT = 'tcgiant_sync_license_queued';

	/**
	 * Seconds a check waits for Lemon Squeezy.
	 */
	const CHECK_TIMEOUT = 8;

	/**
	 * Seconds to the next check after Lemon Squeezy says the key is valid.
	 */
	const RECHECK_VALID = 86400;

	/**
	 * Seconds to the next check of a key Lemon Squeezy says is not valid: at
	 * most every 6 hours, however often the admin is opened.
	 */
	const RECHECK_EXPIRED = 21600;

	/**
	 * Seconds to the next check after an answer that says nothing for sure,
	 * doubling with each one in a row up to UNCLEAR_MAX_DELAY.
	 */
	const UNCLEAR_DELAY = 3600;

	/**
	 * The longest wait between checks while answers say nothing for sure.
	 */
	const UNCLEAR_MAX_DELAY = 21600;

	/**
	 * How long Pro holds past a key's renewal date with no definitive answer
	 * from Lemon Squeezy: 14 days.
	 */
	const GRACE_SECONDS = 1209600;

	/**
	 * With no check finished for this long, the licence card says checks are
	 * not running (fix round 1): 3 days, when one is due a day at most after
	 * the last.
	 */
	const STALE_SECONDS = 259200;

	/**
	 * The HTTP codes whose JSON body can be Lemon Squeezy's word on the key:
	 * 200, and the 4xx its License API documents (400 general, 404 not found,
	 * 422 a field invalid or missing). Anything else - a 429, a 5xx - says
	 * nothing about the key. See read_answer().
	 */
	const ANSWER_CODES = array(200, 400, 404, 422);

	/**
	 * Main instance.
	 *
	 * @return self
	 */
	public static function instance()
	{
		if (is_null(self::$_instance)) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		// Periodic re-validation (3.36.0): a check that is due is queued, from
		// admin pages and from the hourly ended-listing check, so a site
		// nobody opens the admin of is checked too. No page waits on Lemon
		// Squeezy: the check runs in Action Scheduler (run_check()), which
		// queues the next one when it is done.
		if (is_admin()) {
			add_action('admin_init', array($this, 'maybe_revalidate'));
			add_action('admin_notices', array($this, 'lapse_notice'));
			add_action('admin_post_tcgiant_recheck_license', array($this, 'handle_recheck'));
		}
		add_action('tcgiant_sync_check_ended_listings', array($this, 'maybe_revalidate'));
		add_action(self::CHECK_HOOK, array($this, 'run_check'));
		add_action(TCGiant_Sync_Limit_Held::REPLAY_HOOK, array('TCGiant_Sync_Limit_Held', 'replay'));
	}

	/**
	 * Get stored license data.
	 *
	 * @return array License data.
	 */
	public function get_license_data()
	{
		return get_option(self::LICENSE_OPTION, array(
			'key' => '',
			'status' => '', // active, expired, invalid, ''
			'instance_id' => '',
			'customer_name' => '',
			'customer_email' => '',
			'variant' => '', // monthly, annual, lifetime
			'expires_at' => '',
			'activated_at' => '',
			// 3.36.0: the checks with Lemon Squeezy (Unix seconds). Each may be
			// absent from a licence stored before.
			'checked_at' => 0,     // its last definitive answer
			'valid_at' => 0,       // its last answer that the key is valid
			'asked_at' => 0,       // its last check, whatever the answer
			'next_check' => 0,     // when the next check is due
			'unclear_count' => 0,  // answers in a row that said nothing for sure
			'unclear_since' => 0,  // the first of them
			'unclear_reason' => '', // what the last of them was
			'ls_status' => '',     // license_key.status at the last definitive answer
			'ls_error' => '',      // its error, if any
			'store_id' => '',      // what the key belongs to, in Lemon Squeezy
			'product_id' => '',
			'variant_id' => '',
			'ids_noted' => '',     // a store or product mismatch already logged
			'lapse_noted' => 0,    // the grace's end already logged
		));
	}

	/**
	 * Update license data.
	 *
	 * @param array $updates Key-value pairs.
	 */
	private function update_license_data($updates)
	{
		$data = $this->get_license_data();
		$data = array_merge($data, $updates);
		update_option(self::LICENSE_OPTION, $data);
	}

	/**
	 * Whether the key on this site is no longer the one a request was made
	 * about (3.36.0 fix round 1): its key or its instance_id changed while
	 * the request was out - another key activated, or this one deactivated,
	 * in another request. The record is read from the table, not this
	 * request's copy of it, which can be older than the request.
	 *
	 * This request's copy is dropped whatever the answer (fix round 2,
	 * forget_cached_record()), so every write after the request merges onto
	 * the record as the table holds it now. Dropped only on a change, the
	 * key unchanged, an unclear answer merged onto a copy older than the
	 * request put back a status another request had just written: free
	 * again after a Re-check found the key valid, or Pro after a definitive
	 * "no".
	 *
	 * @param array $before The record the request was made from.
	 * @return bool
	 */
	private function changed_meanwhile($before)
	{
		global $wpdb;

		$now = null;

		if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var') && isset($wpdb->options)) {
			$raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::LICENSE_OPTION));
			$now = is_string($raw) ? maybe_unserialize($raw) : array();
		}

		if (!is_array($now)) {
			$now = $this->get_license_data();
		}

		$changed = (string) ($before['key'] ?? '') !== (string) ($now['key'] ?? '')
			|| (string) ($before['instance_id'] ?? '') !== (string) ($now['instance_id'] ?? '');

		$this->forget_cached_record();

		return $changed;
	}

	/**
	 * Drop this request's cached copy of the record, and of the autoloaded
	 * options it is read with, so the next read is the table's (fix round 2).
	 *
	 * @return void
	 */
	private function forget_cached_record()
	{
		if (function_exists('wp_cache_delete')) {
			wp_cache_delete(self::LICENSE_OPTION, 'options');
			wp_cache_delete('alloptions', 'options');
		}
	}

	/**
	 * Check if user has a valid Pro license.
	 *
	 * @return bool True if Pro is active.
	 */
	public function is_pro()
	{
		$data = $this->get_license_data();

		if (empty($data['key']) || empty($data['status'])) {
			return false;
		}

		// Check if license is active.
		if ('active' !== $data['status']) {
			return false;
		}

		// A renewal date passed is not, on its own, a lapsed licence (3.36.0).
		// It makes a check due, and Pro holds until Lemon Squeezy answers -
		// for GRACE_SECONDS at most with no definitive answer. Before, Pro
		// ended the moment the stored date passed: up to a day before the
		// daily check could read a renewed one, and for as long as Lemon
		// Squeezy called a key valid with its date still past (a subscription
		// in its payment retries stays valid).
		return !$this->lapsed($data);
	}

	/**
	 * The stored renewal date, in Unix seconds; 0 for none - a lifetime plan,
	 * a key with no date, or one that cannot be read, which used to read as
	 * long past.
	 *
	 * @param array $data License data.
	 * @return int
	 */
	private function expires_ts($data)
	{
		if ('lifetime' === ($data['variant'] ?? '') || empty($data['expires_at'])) {
			return 0;
		}

		$ts = strtotime((string) $data['expires_at']);

		return false === $ts ? 0 : (int) $ts;
	}

	/**
	 * When the key was activated here, in Unix seconds; 0 if not known.
	 * activated_at is the site's local time (current_time('mysql')).
	 *
	 * @param array $data License data.
	 * @return int
	 */
	private static function activated_ts($data)
	{
		$at = (string) ($data['activated_at'] ?? '');

		if ('' === $at) {
			return 0;
		}

		$ts = function_exists('get_gmt_from_date') ? (int) get_gmt_from_date($at, 'U') : (int) strtotime($at);

		return $ts > 0 ? $ts : 0;
	}

	/**
	 * When Pro ends for a key whose renewal date has passed: GRACE_SECONDS
	 * after the later of that date and Lemon Squeezy's last word that the key
	 * is valid. 0 while the date is ahead, or when there is none: a key with
	 * no renewal date never lapses on answers that say nothing for sure.
	 *
	 * @param array|null $data License data, or the stored.
	 * @return int Unix seconds, or 0.
	 */
	public function grace_until($data = null)
	{
		$data    = is_array($data) ? $data : $this->get_license_data();
		$expires = $this->expires_ts($data);

		if ($expires <= 0 || $expires > time()) {
			return 0;
		}

		return max($expires, (int) ($data['valid_at'] ?? 0)) + self::GRACE_SECONDS;
	}

	/**
	 * Whether a key's grace has run out: its renewal date passed, and
	 * GRACE_SECONDS since then, and since Lemon Squeezy last said it was
	 * valid, with no definitive answer.
	 *
	 * @param array $data License data.
	 * @return bool
	 */
	private function lapsed($data)
	{
		$until = $this->grace_until($data);

		return $until > 0 && time() > $until;
	}

	/**
	 * The free-tier cap, through the name every edition shares.
	 *
	 * @return int
	 */
	public function get_free_limit()
	{
		return self::FREE_LIMIT;
	}

	/**
	 * Get the current import limit.
	 *
	 * @return int Maximum allowed synced products.
	 */
	public function get_limit()
	{
		return $this->is_pro() ? PHP_INT_MAX : self::FREE_LIMIT;
	}

	/**
	 * Get remaining import slots.
	 *
	 * @return int Number of products that can still be imported. Returns PHP_INT_MAX for Pro.
	 */
	public function get_remaining_slots()
	{
		if ($this->is_pro()) {
			return PHP_INT_MAX;
		}

		$remaining = self::FREE_LIMIT - $this->get_active_product_count();
		return max(0, $remaining);
	}

	/**
	 * Check if the user can import more products.
	 *
	 * @return bool True if import is allowed.
	 */
	public function can_import()
	{
		if ($this->is_pro()) {
			return true;
		}

		return $this->get_active_product_count() < self::FREE_LIMIT;
	}

	/**
	 * Get upgrade URL.
	 *
	 * @return string URL to the upgrade page.
	 */
	public function get_upgrade_url()
	{
		return self::UPGRADE_URL;
	}

	/**
	 * Activate a license key via LemonSqueezy API.
	 *
	 * @param string $key License key to activate.
	 * @return array|WP_Error Result array with 'success' and 'message', or WP_Error.
	 */
	public function activate_license($key)
	{
		$key = sanitize_text_field(trim($key));

		if (empty($key)) {
			return new WP_Error('empty_key', __('Please enter a license key.', 'tcgiant-sync'));
		}

		// Call LemonSqueezy license activation endpoint.
		$response = wp_remote_post(self::LS_API_BASE . '/licenses/activate', array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			),
			'body' => wp_json_encode(array(
				'license_key' => $key,
				'instance_name' => $this->get_instance_name(),
			)),
		));

		if (is_wp_error($response)) {
			TCGiant_Sync_Logger::error('License activation failed: ' . $response->get_error_message());
			return new WP_Error('api_error', __('Could not reach the license server. Please try again.', 'tcgiant-sync'));
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		// Handle API errors.
		if ($code !== 200 || empty($body['activated'])) {
			$error_msg = $body['error'] ?? __('Invalid license key.', 'tcgiant-sync');
			TCGiant_Sync_Logger::error('License activation rejected: ' . $error_msg);
			return new WP_Error('invalid_key', $error_msg);
		}

		// Extract data from LemonSqueezy response.
		$meta = $body['meta'] ?? array();
		$instance = $body['instance'] ?? array();

		$variant = $this->detect_variant($meta, $body);

		// What it writes merges onto the record as the table holds it now,
		// not this request's older copy (fix round 2).
		$this->forget_cached_record();

		// Whether this activation is a move to Pro, for the listings the free
		// limit held back (3.36.0).
		$was_pro = $this->is_pro();

		// Save license data.
		$this->update_license_data(array(
			'key' => $key,
			'status' => 'active',
			'instance_id' => $instance['id'] ?? '',
			'customer_name' => $meta['customer_name'] ?? '',
			'customer_email' => $meta['customer_email'] ?? '',
			'variant' => $variant,
			'expires_at' => $meta['expires_at'] ?? '',
			'activated_at' => current_time('mysql'),
		));

		// What the checks keep (3.36.0): a definitive answer now, the next
		// check a day on.
		$now = time();
		$this->update_license_data(array_merge(self::answer_fields($body, $now), array(
			'valid_at' => $now,
			'next_check' => $now + self::RECHECK_VALID,
			'lapse_noted' => 0,
		)));

		// Cache validation.
		set_transient(self::VALIDATION_TRANSIENT, 'valid', DAY_IN_SECONDS);

		$this->check_ids($meta);
		$this->schedule_check();

		if ('' === $variant) {
			TCGiant_Sync_Logger::warning(sprintf(
				'Could not tell which plan this licence is on from what LemonSqueezy returned (variant name: %s). It will be reported as unknown until the next check.',
				$meta['variant_name'] ?? '(none given)'
			));
		}

		TCGiant_Sync_Logger::log('License activated successfully! Plan: ' . ucfirst($variant ?: 'unknown'), 'success');

		if (!$was_pro && $this->is_pro()) {
			TCGiant_Sync_Limit_Held::start('a licence key activated');
		}

		return array(
			'success' => true,
			'message' => __('License activated! You now have unlimited imports.', 'tcgiant-sync'),
			'variant' => $variant,
		);
	}

	/**
	 * Work out which plan a licence is on.
	 *
	 * This used to be two lines with two outcomes: lifetime when the variant
	 * name said so, and annual for everything else. Pro Monthly is a real plan
	 * and fell into 'everything else', so every monthly subscriber was recorded
	 * and reported as annual - which is why the dashboard showed none of them,
	 * and counted each at a year's revenue instead of a month's.
	 *
	 * The name is the primary signal and the renewal date is the fallback, so
	 * this keeps working if the plans are ever renamed in LemonSqueezy. When
	 * neither settles it, the answer is an empty string rather than a guess:
	 * quietly picking the middle tier is the mistake being fixed here, and the
	 * callers treat 'do not know' as 'leave what is already stored'.
	 *
	 * @param array $meta LemonSqueezy meta block.
	 * @param array $body Full response, for fields that live outside meta.
	 * @return string 'monthly', 'annual', 'lifetime', or '' when undecidable.
	 */
	private function detect_variant($meta, $body = array())
	{
		$meta = is_array($meta) ? $meta : array();
		$body = is_array($body) ? $body : array();

		$name = strtolower((string) ($meta['variant_name'] ?? ''));

		if ('' !== $name) {
			// Founder is checked first and separately: a founder plan is a
			// lifetime one whatever else its name happens to contain.
			if (false !== strpos($name, 'lifetime') || false !== strpos($name, 'founder')) {
				return 'lifetime';
			}

			if (false !== strpos($name, 'month')) {
				return 'monthly';
			}

			if (false !== strpos($name, 'annual') || false !== strpos($name, 'year')) {
				return 'annual';
			}
		}

		// Nothing useful in the name. The renewal date still says which plan
		// this is: a monthly subscription renews about a month out, a yearly
		// one about a year, and a lifetime licence never expires at all.
		$expires = '';

		foreach (array($meta['expires_at'] ?? '', $body['license_key']['expires_at'] ?? '', $body['expires_at'] ?? '') as $candidate) {
			if (!empty($candidate)) {
				$expires = (string) $candidate;
				break;
			}
		}

		if ('' === $expires) {
			// No expiry at all. Only said of a lifetime licence when the name
			// agreed; on its own it is just as likely to be a field we were not
			// given, so this declines to answer.
			return '';
		}

		$days = (strtotime($expires) - time()) / DAY_IN_SECONDS;

		if ($days <= 0) {
			return '';
		}

		// Generous either side of a month, because a renewal can be prorated,
		// paused or extended by a trial.
		return ($days <= 62) ? 'monthly' : 'annual';
	}

	/**
	 * Deactivate the current license.
	 *
	 * @return array|WP_Error Result.
	 */
	public function deactivate_license()
	{
		$data = $this->get_license_data();

		if (empty($data['key'])) {
			return new WP_Error('no_license', __('No license key to deactivate.', 'tcgiant-sync'));
		}

		// Call LemonSqueezy deactivation endpoint.
		$response = wp_remote_post(self::LS_API_BASE . '/licenses/deactivate', array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			),
			'body' => wp_json_encode(array(
				'license_key' => $data['key'],
				'instance_id' => $data['instance_id'],
			)),
		));

		// Another key activated here while the request was out keeps its
		// record (3.36.0 fix round 1, changed_meanwhile()).
		if ($this->changed_meanwhile($data)) {
			TCGiant_Sync_Logger::log('Licence: the key was released at Lemon Squeezy, but another key was activated on this site while that was asked, so the new key and its record are kept.', 'warning');
			return array(
				'success' => true,
				'message' => __('That key was released. Another key activated on this site meanwhile is kept.', 'tcgiant-sync'),
			);
		}

		// Clear license regardless of API response (graceful local cleanup).
		$this->update_license_data(array(
			'key' => '',
			'status' => '',
			'instance_id' => '',
			'customer_name' => '',
			'customer_email' => '',
			'variant' => '',
			'expires_at' => '',
			'activated_at' => '',
		));
		// And what the checks kept of it (3.36.0), with the next one.
		$this->update_license_data(array_merge(self::answer_fields(array(), 0), array(
			'valid_at' => 0,
			'next_check' => 0,
			'store_id' => '',
			'product_id' => '',
			'variant_id' => '',
			'ids_noted' => '',
			'lapse_noted' => 0,
		)));
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions(self::CHECK_HOOK);
		}
		delete_transient(self::QUEUED_TRANSIENT);

		delete_transient(self::VALIDATION_TRANSIENT);

		TCGiant_Sync_Logger::log('License deactivated.', 'warning');

		return array(
			'success' => true,
			'message' => __('License deactivated. You are now on the free tier (50 products).', 'tcgiant-sync'),
		);
	}

	/**
	 * Queue a check of the key when one is due (3.36.0).
	 *
	 * From admin_init and from the hourly ended-listing check. It never asks
	 * Lemon Squeezy itself. Before 3.36.0 it did, from admin_init, in the page
	 * being loaded - for up to 15 seconds when Lemon Squeezy was slow - and,
	 * once a licence read "expired", on every admin request, the dashboard's
	 * polls included, since nothing was cached for an expired one. And only
	 * from admin pages, so a site nobody opened the admin of was never
	 * checked.
	 *
	 * @return void
	 */
	public function maybe_revalidate()
	{
		$data = $this->get_license_data();

		if (empty($data['key']) || !$this->check_due($data)) {
			return;
		}

		$this->queue_check();
	}

	/**
	 * Whether a check is due: its time has come (next_check), or the key's
	 * renewal date has passed and it has not been asked since. A licence
	 * 3.36.0 has not checked yet goes by the day 3.35.0 cached its last check
	 * for.
	 *
	 * @param array $data License data.
	 * @return bool
	 */
	private function check_due($data)
	{
		$now     = time();
		$expires = $this->expires_ts($data);

		if ($expires > 0 && $expires <= $now && (int) ($data['asked_at'] ?? 0) < $expires) {
			return true;
		}

		$next = (int) ($data['next_check'] ?? 0);

		if ($next <= 0) {
			return false === get_transient(self::VALIDATION_TRANSIENT);
		}

		return $now >= $next;
	}

	/**
	 * Queue a check in Action Scheduler, unless one was queued in the last
	 * quarter of an hour, is running, or is waiting to run now.
	 *
	 * @return void
	 */
	private function queue_check()
	{
		if (false !== get_transient(self::QUEUED_TRANSIENT) || !function_exists('as_enqueue_async_action')) {
			return;
		}

		set_transient(self::QUEUED_TRANSIENT, time(), 15 * MINUTE_IN_SECONDS);

		$next = function_exists('as_next_scheduled_action') ? as_next_scheduled_action(self::CHECK_HOOK, null, TCGiant_Sync_Limit_Held::GROUP) : false;

		if (true === $next || (is_int($next) && $next <= time())) {
			return;
		}

		as_enqueue_async_action(self::CHECK_HOOK, array(), TCGiant_Sync_Limit_Held::GROUP);
	}

	/**
	 * A check, from Action Scheduler: Lemon Squeezy asked when one is due,
	 * and the next one queued either way (schedule_check()). The daily check
	 * of a valid key is this, on a site nobody opens the admin of as on any
	 * other.
	 *
	 * @return void
	 */
	public function run_check()
	{
		delete_transient(self::QUEUED_TRANSIENT);
		// The record as the table holds it, not this request's copy, which
		// an Action Scheduler batch can have read before another request
		// wrote it: when not due, the lapse's note below merges onto it
		// (fix round 3).
		$this->forget_cached_record();
		$data = $this->get_license_data();

		if (empty($data['key'])) {
			return;
		}

		if ($this->check_due($data)) {
			// A record another request changed meanwhile is not written to
			// here at all, not even the lapse's note (fix round 1).
			if ('dropped' === $this->check()) {
				return;
			}
		} else {
			$this->schedule_check();
		}

		$this->note_lapse();
	}

	/**
	 * Validate the current license against LemonSqueezy, now.
	 *
	 * @return bool True if valid; for an answer that said nothing for sure,
	 *              or one dropped (check()), whether the site is on Pro.
	 */
	public function validate_license()
	{
		$data = $this->get_license_data();

		if (empty($data['key'])) {
			return false;
		}

		$verdict = $this->check();

		return 'valid' === $verdict || (in_array($verdict, array('unclear', 'dropped'), true) && $this->is_pro());
	}

	/**
	 * Ask Lemon Squeezy whether the key is valid, and act only on a
	 * definitive answer (read_answer(), 3.36.0).
	 *
	 * - Valid: Pro, the plan and the renewal date re-read, the next check a
	 *   day on.
	 * - Not valid: the free tier, and the next check 6 hours on.
	 * - Anything else - no answer, a timeout, a 429, a 5xx, an HTML page, an
	 *   empty body, JSON without "valid": nothing stored changes, Pro or not,
	 *   and the next check is an hour on, then two, four, and six from then
	 *   on (unclear_delay()). Logged at the first of a run of them, and when
	 *   Lemon Squeezy answers again.
	 *
	 * Until 3.36.0 every reply but "valid": true - a 429, a 5xx, an HTML page,
	 * an empty body - marked the licence expired, so one bad minute at Lemon
	 * Squeezy put a paying shop on the free tier.
	 *
	 * A move to Pro imports the listings the free limit held back
	 * (TCGiant_Sync_Limit_Held::start()).
	 *
	 * An answer about a key that is no longer the one on this site - another
	 * activated, or this one deactivated, while the request was out - is
	 * dropped, and nothing is written (fix round 1, changed_meanwhile()).
	 * Before, a background check of a lapsed key that finished just after a
	 * new key was activated wrote its "not valid" onto the new key.
	 *
	 * @return string 'valid', 'invalid', 'unclear' or 'dropped'.
	 */
	private function check()
	{
		$data = $this->get_license_data();
		$now  = time();

		$response = wp_remote_post(self::LS_API_BASE . '/licenses/validate', array(
			'timeout' => self::CHECK_TIMEOUT,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			),
			'body' => wp_json_encode(array(
				'license_key' => $data['key'],
				'instance_id' => $data['instance_id'],
			)),
		));

		if ($this->changed_meanwhile($data)) {
			TCGiant_Sync_Logger::log('Licence: the key on this site changed while Lemon Squeezy was being asked about the one before, so that answer is dropped and nothing is written.', 'warning');
			return 'dropped';
		}

		// The record as the table holds it now (changed_meanwhile() dropped
		// this request's copy): the unclear count, the status and whether the
		// site was on Pro are read from it, and every write below merges onto
		// it (fix round 2).
		$data    = $this->get_license_data();
		$was_pro = $this->is_pro();

		$answer = self::read_answer($response);

		if ('unclear' === $answer['verdict']) {
			$this->unclear_answer($data, $answer['why'], $now);
			return 'unclear';
		}

		$body  = $answer['body'];
		$fresh = self::answer_fields($body, $now);

		if ((int) ($data['unclear_count'] ?? 0) > 0) {
			TCGiant_Sync_Logger::log(sprintf(
				'Licence: Lemon Squeezy answered again, after %1$d checks since %2$s UTC whose answers said nothing for sure.',
				(int) $data['unclear_count'],
				gmdate('Y-m-d H:i', (int) ($data['unclear_since'] ?? $now))
			));
		}

		if ('valid' === $answer['verdict']) {
			$fresh['status'] = 'active';
			$fresh['valid_at'] = $now;
			$fresh['next_check'] = $now + self::RECHECK_VALID;

			// The plan, re-read every time.
			//
			// This used to keep nothing but the word valid, so a licence recorded
			// under the wrong plan stayed wrong for as long as it existed - and
			// every monthly subscriber was recorded under the wrong plan, because
			// until now there was no such thing as monthly. Re-reading it here is
			// what lets those correct themselves, with nothing asked of anybody.
			// It also follows an upgrade from monthly to annual without needing
			// the key entered again.
			$variant = $this->detect_variant($body['meta'] ?? array(), $body);

			if ('' !== $variant) {
				$fresh['variant'] = $variant;
			}

			// Only when offered. An absent field must not erase a good value.
			$expires = $body['license_key']['expires_at'] ?? ($body['meta']['expires_at'] ?? '');

			if (!empty($expires)) {
				$fresh['expires_at'] = (string) $expires;
			}

			$this->update_license_data($fresh);
			set_transient(self::VALIDATION_TRANSIENT, 'valid', DAY_IN_SECONDS);
			$this->check_ids($body['meta'] ?? array());
			$this->schedule_check();

			if (!$was_pro && $this->is_pro()) {
				TCGiant_Sync_Logger::log('Licence: Lemon Squeezy says this licence is valid, so the site is on Pro again.', 'success');
				TCGiant_Sync_Limit_Held::start('a licence check found it valid');
			}

			return 'valid';
		}

		// License is no longer valid (expired, refunded, etc.), as Lemon
		// Squeezy says for sure.
		$fresh['status'] = 'expired';
		$fresh['next_check'] = $now + self::RECHECK_EXPIRED;
		$this->update_license_data($fresh);
		delete_transient(self::VALIDATION_TRANSIENT);
		$this->schedule_check();

		if ('active' === ($data['status'] ?? '')) {
			TCGiant_Sync_Logger::log(sprintf(
				// The key itself fine, this site's activation of it not (fix
				// round 1): renewing would not bring it back, entering the key
				// again would.
				self::activation_released($fresh)
					? 'Licence: Lemon Squeezy says this site\'s activation of the key is no longer valid (%s), though the key itself is not expired or disabled, so the site is on the free tier: new eBay listings are held back from import, and products already imported keep syncing. Enter the key again on the Settings screen to activate it here.'
					: 'Licence: Lemon Squeezy says this licence is not valid (%s), so the site is on the free tier: new eBay listings are held back from import, and products already imported keep syncing. It is checked again every 6 hours, so a renewal brings Pro back.',
				$answer['why']
			), 'warning');
		}

		return 'invalid';
	}

	/**
	 * Whether a definitive "not valid" was about this site's activation of
	 * the key rather than the key (3.36.0 fix round 1): the key's own status
	 * at Lemon Squeezy is active or inactive, or its error names the
	 * instance - an activation released, say from the customer's own order
	 * page. A key Lemon Squeezy calls expired or disabled, or whose error
	 * says so, is the key's. Lemon Squeezy's docs show no error body, so both
	 * are read.
	 *
	 * @param array $data The record, or what an answer leaves in it.
	 * @return bool
	 */
	private static function activation_released($data)
	{
		$status = strtolower((string) ($data['ls_status'] ?? ''));

		if (in_array($status, array('expired', 'disabled'), true)) {
			return false;
		}

		// Nor when its error says the key expired or is disabled, whatever
		// status came with it (fix round 2): Lemon Squeezy may call a key past
		// its date not valid before its status reads expired.
		$error = strtolower((string) ($data['ls_error'] ?? ''));

		if (false !== strpos($error, 'expire') || false !== strpos($error, 'disab')) {
			return false;
		}

		return in_array($status, array('active', 'inactive'), true)
			|| false !== stripos((string) ($data['ls_error'] ?? ''), 'instance');
	}

	/**
	 * What a reply to validate says for sure, if anything (3.36.0).
	 *
	 * Definitive only as a JSON object with an HTTP code in ANSWER_CODES:
	 * - license_key.status expired or disabled: not valid;
	 * - "valid": true, on a 200: valid;
	 * - "valid": false, on a 200, 400 or 404: not valid.
	 *
	 * Everything else says nothing for sure, and changes nothing stored: no
	 * answer (a WP_Error, a timeout), a 429, any 5xx, an HTML page, an empty
	 * or non-JSON body, JSON without "valid" or with neither true nor false
	 * in it, "valid": true on anything but a 200 - and a 422 that says no
	 * more than "valid": false. Lemon Squeezy documents 422 as "A required
	 * field was invalid or missing": our request, not the key. And this
	 * plugin sends its fields as JSON, where the License API documents a
	 * form: should Lemon Squeezy ever stop reading JSON, every check would
	 * come back 422, and taken as the key's answer that would put every
	 * paying shop on the free tier at once.
	 *
	 * @param array|WP_Error $response wp_remote_post()'s.
	 * @return array{verdict: string, why: string, body: array}
	 */
	public static function read_answer($response)
	{
		if (is_wp_error($response)) {
			return array('verdict' => 'unclear', 'why' => 'no answer: ' . $response->get_error_message(), 'body' => array());
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$raw  = trim((string) wp_remote_retrieve_body($response));
		$body = '' === $raw ? null : json_decode($raw, true);

		if (!is_array($body)) {
			return array('verdict' => 'unclear', 'why' => sprintf('HTTP %d, %s', $code, '' === $raw ? 'an empty reply' : 'not a JSON object'), 'body' => array());
		}

		$error = is_string($body['error'] ?? null) ? substr($body['error'], 0, 200) : '';
		$why   = sprintf('HTTP %d%s', $code, '' !== $error ? ': ' . $error : '');

		if (!in_array($code, self::ANSWER_CODES, true)) {
			return array('verdict' => 'unclear', 'why' => $why, 'body' => $body);
		}

		$status = is_array($body['license_key'] ?? null) && is_scalar($body['license_key']['status'] ?? null)
			? strtolower((string) $body['license_key']['status'])
			: '';

		if (in_array($status, array('expired', 'disabled'), true)) {
			return array('verdict' => 'invalid', 'why' => $why . ', the key ' . $status, 'body' => $body);
		}

		if (!array_key_exists('valid', $body)) {
			return array('verdict' => 'unclear', 'why' => $why . ', with no "valid" in it', 'body' => $body);
		}

		if (true === $body['valid'] && 200 === $code) {
			return array('verdict' => 'valid', 'why' => $why, 'body' => $body);
		}

		if (false === $body['valid'] && 422 !== $code) {
			return array('verdict' => 'invalid', 'why' => $why, 'body' => $body);
		}

		return array('verdict' => 'unclear', 'why' => $why . (422 === $code ? ', about the request rather than the key' : ', "valid" not clear'), 'body' => $body);
	}

	/**
	 * What a definitive answer leaves in the record: when, the key's own
	 * status and error at Lemon Squeezy, and - only when offered - the store,
	 * product and variant it belongs to, kept as given for support. A run of
	 * answers that said nothing for sure is over.
	 *
	 * @param array $body The answer.
	 * @param int   $now  When.
	 * @return array
	 */
	private static function answer_fields($body, $now)
	{
		$body = is_array($body) ? $body : array();
		$key  = is_array($body['license_key'] ?? null) ? $body['license_key'] : array();
		$meta = is_array($body['meta'] ?? null) ? $body['meta'] : array();

		$fields = array(
			'checked_at' => (int) $now,
			'asked_at' => (int) $now,
			'unclear_count' => 0,
			'unclear_since' => 0,
			'unclear_reason' => '',
			'ls_status' => is_scalar($key['status'] ?? null) ? (string) $key['status'] : '',
			'ls_error' => is_string($body['error'] ?? null) ? substr($body['error'], 0, 200) : '',
		);

		foreach (array('store_id', 'product_id', 'variant_id') as $id) {
			if (is_scalar($meta[$id] ?? null) && '' !== (string) $meta[$id]) {
				$fields[$id] = (string) $meta[$id];
			}
		}

		return $fields;
	}

	/**
	 * An answer that said nothing for sure: nothing stored changes but when
	 * to ask again - an hour, then two, four and six (unclear_delay()); every
	 * 6 hours for a key already not valid. Logged once, at the first of a run.
	 *
	 * @param array  $data License data.
	 * @param string $why  What the answer was.
	 * @param int    $now  When.
	 * @return void
	 */
	private function unclear_answer($data, $why, $now)
	{
		$count = (int) ($data['unclear_count'] ?? 0) + 1;
		$since = $count > 1 && (int) ($data['unclear_since'] ?? 0) > 0 ? (int) $data['unclear_since'] : $now;
		$delay = 'active' === ($data['status'] ?? '') ? self::unclear_delay($count) : self::RECHECK_EXPIRED;

		$this->update_license_data(array(
			'asked_at' => $now,
			'unclear_count' => $count,
			'unclear_since' => $since,
			'unclear_reason' => (string) $why,
			'next_check' => $now + $delay,
		));
		$this->schedule_check();

		if (1 === $count) {
			TCGiant_Sync_Logger::log(sprintf(
				'Licence: Lemon Squeezy\'s answer said nothing for sure (%1$s), so nothing changes: the site stays on %2$s. It asks again in %3$d minutes, then less often, up to every 6 hours, and says no more here until Lemon Squeezy answers.',
				$why,
				$this->is_pro() ? 'Pro' : 'the free tier',
				(int) round($delay / MINUTE_IN_SECONDS)
			), 'warning');
		}
	}

	/**
	 * Seconds to the next check after the given number of answers in a row
	 * that said nothing for sure: UNCLEAR_DELAY, doubling, at most
	 * UNCLEAR_MAX_DELAY.
	 *
	 * @param int $count Answers in a row, 1 for the first.
	 * @return int
	 */
	public static function unclear_delay($count)
	{
		$count = max(1, (int) $count);

		return (int) min(self::UNCLEAR_MAX_DELAY, self::UNCLEAR_DELAY * pow(2, min(10, $count - 1)));
	}

	/**
	 * Queue the next check for when it is due - next_check, or just after a
	 * renewal date sooner than that - in place of any queued before.
	 *
	 * @return void
	 */
	private function schedule_check()
	{
		if (!function_exists('as_schedule_single_action')) {
			return;
		}

		$data = $this->get_license_data();

		if (empty($data['key'])) {
			return;
		}

		$now     = time();
		$at      = max($now, (int) ($data['next_check'] ?? 0));
		$expires = $this->expires_ts($data);

		if ($expires > $now) {
			$at = min($at, $expires + 5 * MINUTE_IN_SECONDS);
		}

		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions(self::CHECK_HOOK);
		}

		as_schedule_single_action($at, self::CHECK_HOOK, array(), TCGiant_Sync_Limit_Held::GROUP);
	}

	/**
	 * The owner's Lemon Squeezy store and products, which a key is compared
	 * with: LS_STORE_ID and LS_PRODUCT_IDS, through the filter
	 * tcgiant_sync_license_ids.
	 *
	 * @return array{store_id: int, product_ids: int[]}
	 */
	public static function wanted_ids()
	{
		$want = apply_filters('tcgiant_sync_license_ids', array(
			'store_id' => self::LS_STORE_ID,
			'product_ids' => self::LS_PRODUCT_IDS,
		));
		$want = is_array($want) ? $want : array();

		return array(
			'store_id' => max(0, (int) ($want['store_id'] ?? 0)),
			'product_ids' => array_values(array_filter(array_map('intval', (array) ($want['product_ids'] ?? array())))),
		);
	}

	/**
	 * Compare the store and product a key belongs to with the owner's
	 * (wanted_ids()), on activation and on each answer that it is valid.
	 * A difference is logged once, and Pro is kept: 3.36.0 only records it.
	 * Each of the two is compared only when it is set and the answer names
	 * it.
	 *
	 * Without it, a key from any Lemon Squeezy store or product unlocks Pro,
	 * which is what Lemon Squeezy's own guide warns of.
	 *
	 * @param array $meta The answer's meta.
	 * @return string 'ok', 'mismatch', or 'skipped' when neither is set.
	 */
	private function check_ids($meta)
	{
		$meta = is_array($meta) ? $meta : array();
		$want = self::wanted_ids();

		if ($want['store_id'] <= 0 && empty($want['product_ids'])) {
			return 'skipped';
		}

		$store   = is_scalar($meta['store_id'] ?? null) ? (string) $meta['store_id'] : '';
		$product = is_scalar($meta['product_id'] ?? null) ? (string) $meta['product_id'] : '';
		$wrong   = array();

		if ($want['store_id'] > 0 && '' !== $store && (int) $store !== $want['store_id']) {
			$wrong[] = sprintf('store %s, not %d', $store, $want['store_id']);
		}

		if (!empty($want['product_ids']) && '' !== $product && !in_array((int) $product, $want['product_ids'], true)) {
			$wrong[] = sprintf('product %s, not %s', $product, implode(' or ', $want['product_ids']));
		}

		$noted = implode('; ', $wrong);
		$data  = $this->get_license_data();

		if ($noted !== (string) ($data['ids_noted'] ?? '')) {
			$this->update_license_data(array('ids_noted' => $noted));

			if ('' !== $noted) {
				TCGiant_Sync_Logger::log(sprintf(
					'Licence: this key belongs to Lemon Squeezy %s. Pro is kept on: this version only records it.',
					$noted
				), 'warning');
			}
		}

		return '' === $noted ? 'ok' : 'mismatch';
	}

	/**
	 * Say once that a key's grace has run out (lapsed()), and what to do.
	 *
	 * @return void
	 */
	private function note_lapse()
	{
		$data  = $this->get_license_data();
		$until = $this->grace_until($data);

		if ('active' !== ($data['status'] ?? '') || !$this->lapsed($data) || (int) ($data['lapse_noted'] ?? 0) === $until) {
			return;
		}

		$this->update_license_data(array('lapse_noted' => $until));

		TCGiant_Sync_Logger::log(sprintf(
			'Licence: Lemon Squeezy has not said this licence is valid in the %1$d days since its renewal date (%2$s UTC), and could not be asked, so the site is on the free tier until it says so: new eBay listings are held back from import, and products already imported keep syncing. Press Re-check on the Settings screen, or wait for the next check.',
			(int) (self::GRACE_SECONDS / DAY_IN_SECONDS),
			gmdate('Y-m-d H:i', $this->expires_ts($data))
		), 'warning');
	}

	/**
	 * Re-check, from the licence card and the notice: one check now, then
	 * back to Settings, which says what Lemon Squeezy answered.
	 *
	 * @return void
	 */
	public function handle_recheck()
	{
		check_admin_referer('tcgiant_recheck_license');

		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized.', 'tcgiant-sync'));
		}

		$verdict = 'none';
		$data    = $this->get_license_data();

		if (!empty($data['key'])) {
			delete_transient(self::QUEUED_TRANSIENT);
			$verdict = $this->check();
			if ('dropped' !== $verdict) {
				$this->note_lapse();
			}
		}

		wp_safe_redirect(add_query_arg('license_rechecked', $verdict, admin_url('admin.php?page=tcgiant-settings')) . '#tc-license-section');
		exit;
	}

	/**
	 * The notice on this plugin's screens when a key's grace has run out:
	 * the site is on the free tier because Lemon Squeezy could not be asked,
	 * not because it said no. With a Re-check button.
	 *
	 * @return void
	 */
	public function lapse_notice()
	{
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		if (!$screen || false === strpos((string) $screen->id, 'tcgiant') || !current_user_can('manage_options')) {
			return;
		}

		$data = $this->get_license_data();

		if (empty($data['key']) || 'active' !== ($data['status'] ?? '') || !$this->lapsed($data)) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e('TCGiant Sync: your Pro licence could not be checked', 'tcgiant-sync'); ?></strong><br>
				<?php
				printf(
					/* translators: 1: a date, 2: a number of days */
					esc_html__('Lemon Squeezy, which checks TCGiant Sync Pro licences, has not confirmed this licence since its renewal date, %1$s, and could not be asked for %2$d days, so this site is on the free tier until it can be: new eBay listings are held back from import, and products already imported keep syncing. Once the licence is confirmed, Pro comes back and the listings held back are imported.', 'tcgiant-sync'),
					esc_html(gmdate('Y-m-d', $this->expires_ts($data))),
					(int) (self::GRACE_SECONDS / DAY_IN_SECONDS)
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0 0 8px;">
				<input type="hidden" name="action" value="tcgiant_recheck_license">
				<?php wp_nonce_field('tcgiant_recheck_license'); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e('Re-check now', 'tcgiant-sync'); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Where the checks stand, for the licence card (3.36.0).
	 *
	 * state:
	 * - none: no key;
	 * - expired: Lemon Squeezy said the key is not valid - the free tier;
	 * - released: it said this site's activation of the key is not valid,
	 *   the key itself not expired or disabled (activation_released(), fix
	 *   round 1) - the free tier until the key is entered again;
	 * - lapsed: its renewal date passed, and GRACE_SECONDS with no definitive
	 *   answer - the free tier until there is one;
	 * - unclear: the last check's answer said nothing for sure - still Pro;
	 * - renewing: its renewal date passed and it has not been asked since -
	 *   still Pro;
	 * - ok.
	 * ids: the store and product check - skipped (no IDs set), unknown (no
	 * answer has named them yet), mismatch or ok.
	 * stale_since: when the last check finished, if that is more than
	 * STALE_SECONDS ago - or, for a licence no check has finished for yet,
	 * since when the check queued for it has waited that long, or with none
	 * queued since the key was activated (fix round 2) - else 0: checks are
	 * not running (fix round 1). Read only; nothing is asked.
	 * reason_known: false for a record "expired" with nothing of Lemon
	 * Squeezy's answer stored - one 3.34.0 marked on an answer that said
	 * nothing for sure, and 3.36.0 has not checked yet (fix round 2).
	 *
	 * @param array $data License data.
	 * @return array
	 */
	private function check_for_ui($data)
	{
		$expires = $this->expires_ts($data);
		$state   = 'ok';

		if (empty($data['key'])) {
			$state = 'none';
		} elseif ('active' !== ($data['status'] ?? '')) {
			$state = self::activation_released($data) ? 'released' : 'expired';
		} elseif ($this->lapsed($data)) {
			$state = 'lapsed';
		} elseif ((int) ($data['unclear_count'] ?? 0) > 0) {
			$state = 'unclear';
		} elseif ($this->grace_until($data) > 0 && (int) ($data['valid_at'] ?? 0) < $expires) {
			$state = 'renewing';
		}

		$want = self::wanted_ids();
		$ids  = 'ok';

		if ($want['store_id'] <= 0 && empty($want['product_ids'])) {
			$ids = 'skipped';
		} elseif ('' !== (string) ($data['ids_noted'] ?? '')) {
			$ids = 'mismatch';
		} elseif ('' === (string) ($data['store_id'] ?? '') && '' === (string) ($data['product_id'] ?? '')) {
			$ids = 'unknown';
		}

		$asked = (int) ($data['asked_at'] ?? 0);
		$stale = 0;

		if (!empty($data['key'])) {
			if ($asked > 0) {
				$stale = time() - $asked > self::STALE_SECONDS ? $asked : 0;
			} else {
				// Never checked by 3.36.0: since its check was queued, or -
				// none queued, and none made by an earlier version within its
				// day - since the key was activated (fix round 2).
				$next  = function_exists('as_next_scheduled_action') ? as_next_scheduled_action(self::CHECK_HOOK, null, TCGiant_Sync_Limit_Held::GROUP) : false;
				$since = is_int($next) ? $next : 0;

				if (false === $next && false === get_transient(self::VALIDATION_TRANSIENT)) {
					$since = self::activated_ts($data);
				}

				$stale = $since > 0 && time() - $since > self::STALE_SECONDS ? $since : 0;
			}
		}

		return array(
			'state' => $state,
			'checked_at' => (int) ($data['checked_at'] ?? 0),
			'asked_at' => $asked,
			'stale_since' => $stale,
			'next_check' => (int) ($data['next_check'] ?? 0),
			'unclear_count' => (int) ($data['unclear_count'] ?? 0),
			'unclear_since' => (int) ($data['unclear_since'] ?? 0),
			'unclear_reason' => (string) ($data['unclear_reason'] ?? ''),
			'ls_status' => (string) ($data['ls_status'] ?? ''),
			'ls_error' => (string) ($data['ls_error'] ?? ''),
			'reason_known' => (int) ($data['checked_at'] ?? 0) > 0 || '' !== (string) ($data['ls_status'] ?? '') || '' !== (string) ($data['ls_error'] ?? ''),
			'expires' => $expires,
			'grace_until' => $this->grace_until($data),
			'store_id' => (string) ($data['store_id'] ?? ''),
			'product_id' => (string) ($data['product_id'] ?? ''),
			'variant_id' => (string) ($data['variant_id'] ?? ''),
			'ids' => $ids,
			'ids_noted' => (string) ($data['ids_noted'] ?? ''),
		);
	}

	/**
	 * Get a unique instance name for this WordPress installation.
	 *
	 * @return string Instance identifier.
	 */
	private function get_instance_name()
	{
		$site_url = get_site_url();
		// Use domain + path as instance name.
		$parsed = wp_parse_url($site_url);
		return sanitize_title(($parsed['host'] ?? 'unknown') . ($parsed['path'] ?? ''));
	}

	/**
	 * Get license status summary for the admin UI.
	 *
	 * @return array Status data for rendering in the dashboard.
	 */
	public function get_status_for_ui()
	{
		$data = $this->get_license_data();
		$active_count = $this->get_active_product_count();
		$is_pro = $this->is_pro();
		$limit = $is_pro ? 'unlimited' : self::FREE_LIMIT;
		$can_import = $this->can_import();

		$usage_pct = 0;
		if (!$is_pro && self::FREE_LIMIT > 0) {
			$usage_pct = min(100, round(($active_count / self::FREE_LIMIT) * 100));
		}

		return array(
			'is_pro' => $is_pro,
			'plan' => $is_pro ? 'pro' : 'free',
			'variant' => $data['variant'] ?? '',
			'active_count' => $active_count,
			'limit' => $limit,
			'remaining' => $is_pro ? 'unlimited' : max(0, self::FREE_LIMIT - $active_count),
			'can_import' => $can_import,
			'usage_pct' => $usage_pct,
			'key_masked' => $this->mask_key($data['key'] ?? ''),
			'customer_name' => $data['customer_name'] ?? '',
			'expires_at' => $data['expires_at'] ?? '',
			'has_key' => !empty($data['key']),
			'status' => $data['status'] ?? '',
			'upgrade_url' => self::UPGRADE_URL,
			'free_limit' => self::FREE_LIMIT,
			// 3.36.0: where the checks with Lemon Squeezy stand, and the new
			// eBay listings the free limit held back for a move to Pro.
			'check' => $this->check_for_ui($data),
			'held_back' => TCGiant_Sync_Limit_Held::count(),
		);
	}

	/**
	 * Mask a license key for display (show first 4 + last 4).
	 *
	 * @param string $key Full license key.
	 * @return string Masked key.
	 */
	private function mask_key($key)
	{
		if (strlen($key) <= 8) {
			return $key;
		}
		return substr($key, 0, 4) . str_repeat('-', strlen($key) - 8) . substr($key, -4);
	}
}
