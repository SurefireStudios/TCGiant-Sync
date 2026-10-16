<?php
/**
 * Photos of deleted products
 *
 * Removes the photographs the plugin downloaded for a product once that
 * product has been deleted for good, and offers the same, behind a check and
 * a button, for the ones left behind before this existed.
 *
 * Nothing removed them before. A product that sells is moved to the Trash,
 * WordPress empties the Trash after thirty days, and neither WordPress nor
 * WooCommerce deletes a product's photographs with it - they stay in the Media
 * Library as "Unattached", each with its resized copies. A shop that sells one
 * of each of fifteen thousand parts only ever adds to that, and one ran out of
 * disk.
 *
 * What may be deleted is decided in one place, verdicts(), and everything
 * here goes through it: the removal of a deleted product's photos, the check
 * that counts the old ones, and the removal the shop asks for after seeing
 * that count. So the check counts exactly what the removal would remove.
 *
 * A photo may be deleted only when every one of these holds:
 *
 * - the plugin downloaded it. Only files carrying _tcgiant_source_url and not
 *   _tcgiant_external_url (an eBay stand-in, which has no file). A photo the
 *   shop uploaded has neither, and nothing here ever deletes one;
 * - it is no longer attached to anything that exists: its parent is 0, which
 *   is where WordPress moves a deleted product's attachments, or names a post
 *   that is gone. A photo attached to a product that still exists - live,
 *   draft, private or in the Trash - is never deleted, whatever uses it;
 * - nothing uses it, as far as these places say - see in_use():
 *   - the featured image of a post of any type or status (variations keep
 *     theirs in _thumbnail_id);
 *   - a gallery list of any post, of any status: WooCommerce's own, and those
 *     of two variation-gallery plugins (see gallery_keys());
 *   - the picture of a category, or of any term kept as thumbnail_id;
 *   - named by its ID in the content or the excerpt (a product's short
 *     description) of a post of any status, of every kind the site shows,
 *     gives a screen of its own in the admin or opens in the block editor -
 *     posts, pages, products, reusable blocks, templates, navigation menus,
 *     the global styles, a theme's or page builder's own layouts (see
 *     content_types()) - in the Customizer's Additional CSS, in a
 *     variation's description, or in the description of a category, tag,
 *     brand or any other term: the editor's wp-image-N, "id":N, "mediaId":N
 *     and lists of IDs, the image="N", images="...", include="..." and
 *     image_id="N" a page builder's or gallery's shortcode carries, and the
 *     id="N", bg="N" and img="N" of Flatsome's UX Builder's own shortcodes;
 *   - its file pointed at by its address under /uploads/, in any size, in
 *     that content, in another plugin's post meta (a page builder's data) or
 *     term meta (a theme's content for a category's page, an SEO plugin's
 *     picture for sharing it), or in a site setting; and by its ID in
 *     Flatsome's shortcodes in term meta (its content for a category's page);
 *   - shown by a widget (Image, Gallery, Block, Text, Custom HTML);
 *   - the site's logo or icon, the theme's logo, header or background, or
 *     WooCommerce's picture for a product without one.
 *   Photos are shared - the image batch reuses one download for every product
 *   showing the same eBay photo, and a relist adopts its trashed
 *   predecessor's - so the product a photo was downloaded for says nothing
 *   about who uses it now;
 * - no other Media Library entry points at the same file. Before 3.1.4 the
 *   image batch copied the attachment for each product reusing a photo, and
 *   translation plugins do the same, so two records can share one file, and
 *   deleting either would take the picture from the other;
 * - a deletion of it has not been started and given up on before. See
 *   FAILED_KEY.
 *
 * Not seen, and so not protected unless the tcgiant_sync_photos_in_use
 * filter names them: a photo another plugin keeps by its ID alone in a place
 * of its own - its own tables, user meta (a profile picture), term meta with
 * no address in it other than a category's picture and Flatsome's
 * shortcodes, another kind of widget, a slider's or swatch plugin's
 * settings, a page builder's data with no address in it - and one shown from
 * an address that is not under /uploads/. Such a photo goes with its product
 * as any other does, with nobody looking, unless that filter, or
 * tcgiant_sync_remove_photos_of_deleted_products, says otherwise. Old
 * revisions, orders, photos' own descriptions, and the posts of kinds with
 * neither a screen in the admin nor the block editor (another plugin's email
 * logs or form entries) are not read on purpose.
 *
 * What can only be read for the whole site at once - galleries, content,
 * meta, terms, settings - is read once for a run or a step of the check, and
 * each photo has a last look of its own just before it is deleted: its own
 * marks, its featured images and category pictures, and the galleries again
 * when that reading is more than a few seconds old. A read the database
 * fails is never taken for "nothing uses it": the run or the step of the
 * check stops there, deleting nothing more. See read_ok(). Nor is half a
 * reading: one that takes as long as a run may by itself, or would need more
 * memory than the request has left, is given up, judging nothing, and the
 * removal pauses after it, as it does after a run that dies in that reading.
 * See site_uses().
 *
 * The marks that keep a photo whose deletion began from ever being reused
 * are read back before anything depends on them. A write the database
 * refused - a full disk refuses new rows while it still deletes old ones -
 * stops the run, and the photo keeps its place in the queue. See fail().
 *
 * Nothing is deleted the moment it is found. It is marked, and deleted at
 * least QUARANTINE_SECONDS later after every check is made again. See there.
 *
 * And nothing that was already in the Trash when this version arrived is
 * removed without the shop seeing it first. See SINCE_OPTION.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.27.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Photo_Cleanup class.
 */
class TCGiant_Sync_Photo_Cleanup {

	/**
	 * On a photo waiting to be deleted: the time after which it may be.
	 *
	 * The queue itself. A row per photo rather than a list in an option, so
	 * that two requests deleting products at once cannot lose each other's
	 * additions, and so that the queue survives anything that kills a request.
	 * It goes with the photo when the photo is deleted. The image batch never
	 * reuses a photo carrying it.
	 */
	const MARK_KEY = '_tcgiant_delete_after';

	/**
	 * On a photo waiting to be deleted: how many runs have taken it on.
	 *
	 * Counted by a run that throws before WordPress is asked to delete the
	 * photo too, so that one it happens to every time is let go of after
	 * MAX_ATTEMPTS. Whether a deletion really began is STARTED_KEY's to say.
	 */
	const ATTEMPTS_KEY = '_tcgiant_delete_attempts';

	/**
	 * On a photo waiting to be deleted: when WordPress was last asked to
	 * delete it.
	 *
	 * Written just before wp_delete_attachment(), and nowhere else. From then
	 * on another plugin's part of the deletion may have run - an offload
	 * plugin removes its remote copies first - so a photo carrying this that
	 * is kept after all is given up on (FAILED_KEY), never handed back to the
	 * image batch. One that only ever threw before this point is whole, and
	 * is put back like any other photo that is kept. Read back before the
	 * deletion begins, which it does only once this is there.
	 */
	const STARTED_KEY = '_tcgiant_delete_started';

	/**
	 * On a photo the shop's button put in the queue: the check that found it.
	 *
	 * So that the screen counts what became of the check's own photos, apart
	 * from those of products deleted since.
	 */
	const APPROVED_KEY = '_tcgiant_delete_approved';

	/**
	 * On a photo whose deletion was started and then given up on: when.
	 *
	 * Permanent, and the image batch never reuses a photo carrying it. Once
	 * WordPress has begun deleting a photo, part of it may already be gone
	 * even though its record is still there: an offload plugin removes its
	 * remote copies inside the delete_attachment action, before the record
	 * goes, and a request killed there - or a deletion another plugin refused
	 * half way - leaves a photo that looks whole and is not. Handing that to
	 * a new product would give it a broken picture, so such a photo is left
	 * where it is, counted on Image Cleanup, and named in the log for the shop
	 * to look at, and is never taken off this list by the plugin.
	 */
	const FAILED_KEY = '_tcgiant_delete_failed';

	/**
	 * WP-Cron hook for the background removal.
	 *
	 * Not CRON_HOOK, which the image batch already uses: tools/check-hooks.php
	 * resolves constants by name alone, and two of that name made it record
	 * the image batch's registration as this one's.
	 */
	const CLEANUP_HOOK = 'tcgiant_sync_photo_cleanup';

	/**
	 * How long a photo stays marked before it is checked again and deleted.
	 *
	 * The image batch reuses an existing download for any product showing the
	 * same eBay photo, and it finds that download a minute or two before it
	 * writes it into the product's gallery - the gallery is written when the
	 * product's photos are done. A photo checked and deleted inside that gap
	 * would leave the new product pointing at nothing. So a marked photo is
	 * not reused (the image batch skips it), and it is not deleted until any
	 * batch that found it before it was marked has long finished and written
	 * what it was going to: the image batch's own lease, which already
	 * assumes no request lives longer.
	 */
	const QUARANTINE_SECONDS = 900;

	/**
	 * How long a photo is left alone after a run starts deleting it.
	 *
	 * As with the image batch: another plugin's work on a deletion - an
	 * offload plugin deleting the remote copies, say - can hang until the host
	 * ends the request. Without a lease that photo would head every run after
	 * it and nothing behind it would ever go.
	 */
	const LEASE_SECONDS = 900;

	/**
	 * Runs a photo may die in before it is left alone for good.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * When the next run happens if this one is killed.
	 */
	const FALLBACK_SECONDS = 300;

	/**
	 * Gap between runs while photos are due.
	 */
	const NEXT_RUN_SECONDS = 30;

	/**
	 * How long one run holds the queue. Shorter than FALLBACK_SECONDS, so the
	 * run the fallback starts always finds a killed run's hold expired.
	 */
	const LOCK_SECONDS = 240;

	/**
	 * How long the whole removal waits after a run dies while deleting a photo.
	 *
	 * The lease stops one photo heading every run, but not the damage a run
	 * does when it dies: WP-Cron runs every due event in one request, so the
	 * eBay sync, the order import and the image batch due alongside it die
	 * with it. If another plugin's deletion work is what hangs - an offload
	 * plugin whose service is down - every run would start on a fresh photo
	 * and die again, every FALLBACK_SECONDS, taking the sync with it each
	 * time. So after such a death the removal stops for this long, twice as
	 * long each time it happens again straight after, up to
	 * PAUSE_MAX_SECONDS. Nothing waits on it.
	 */
	const PAUSE_SECONDS = 1800;

	/**
	 * The longest the removal pauses for, however often it has died.
	 */
	const PAUSE_MAX_SECONDS = 43200;

	/**
	 * Photos checked per round of a run. A run goes round until its time is up.
	 */
	const BATCH = 50;

	/**
	 * Photos waiting whose galleries are read at once, ahead of the rounds
	 * that will need them. Galleries are lists, which no index can search, so
	 * a reading of them is a pass over every one on the site; one per round
	 * of fifty was most of what a removal read on a large shop.
	 */
	const RUN_SET = 1000;

	/**
	 * How old that reading of the galleries may be when a photo's turn comes.
	 * Older, and they are read again first. See in_gallery_now().
	 */
	const GALLERY_RECHECK_SECONDS = 10;

	/**
	 * The most photos one run takes on when wp_postmeta has no index on
	 * (meta_key, meta_value). See value_index_missing().
	 */
	const NO_INDEX_PHOTOS = 100;

	/**
	 * What Flatsome's UX Builder writes where it places a photo, as a row
	 * must mention it to be read: its own shortcodes, which name the photo by
	 * number alone. See ids_in_content().
	 */
	const UX_NEEDLES = array( '[ux_', ' bg=', ' img=' );

	/**
	 * Rows whose content is fetched at once, at most, while reading where
	 * photos are used: a product's description imported from eBay can run to
	 * hundreds of kilobytes, and a shop can have tens of thousands. Fewer
	 * when they come to more than READ_BYTES. See byte_groups().
	 */
	const READ_PAGE = 50;

	/**
	 * Bytes of content fetched at once, at most, while reading where photos
	 * are used. A page builder keeps a page's data in one row of post meta,
	 * a megabyte or more of it, and fifty rows fetched at once took fifty
	 * megabytes. A single value bigger than this is fetched on its own.
	 */
	const READ_BYTES = 4194304;

	/**
	 * How many times its own size fetching a group of values may take of
	 * memory while it is looked through: the database driver's copy, wpdb's,
	 * and the copies made of each value as it is read. See room_for().
	 */
	const READ_MEMORY_TIMES = 3;

	/**
	 * Memory kept in hand beyond that, for the rest of the run to say what it
	 * did and why it stopped.
	 */
	const READ_MEMORY_SPARE = 4194304;

	/**
	 * Rows that reading walks at once by its key alone, before any content
	 * is fetched. See uses_in_content().
	 */
	const KEY_PAGE = 500;

	/**
	 * Rows of post meta, or of settings, that one query of that reading looks
	 * through at most. See key_window().
	 */
	const WINDOW = 5000;

	/**
	 * Downloaded photos looked at per round of the check.
	 */
	const SCAN_PAGE = 500;

	/**
	 * How many photos of a round of the check go by between looks at the
	 * clock. Reading the files of five hundred photos from slow storage can
	 * take longer than the screen's request is meant to last.
	 */
	const SCAN_TIME_EVERY = 100;

	/**
	 * Photos marked per round when the shop asks for the old ones to go.
	 */
	const QUEUE_SLICE = 200;

	/**
	 * Photo IDs kept per option while the check's findings wait for the shop.
	 *
	 * Split, because one option holding sixty thousand IDs is a single row of
	 * half a megabyte or more, and some hosts refuse a write that size.
	 */
	const CHUNK = 5000;

	/**
	 * Most of a request the screen's check, or the start of a removal, uses,
	 * so that the progress bar moves.
	 */
	const AJAX_SECONDS = 20;

	/**
	 * The shortest the check's hold lasts, in seconds, whatever this server's
	 * time limit says. Hosts end requests by the clock on the wall - three
	 * minutes is common - while PHP's own limit, on Linux, counts only the
	 * time PHP itself spends working, so a request can outlive it.
	 */
	const SCAN_HOLD_FLOOR = 180;

	/**
	 * The longest the check's hold lasts, in seconds, whatever this server's
	 * time limit says: a hold lasting an hour would keep a shop whose step was
	 * killed outright waiting that long to check again.
	 */
	const SCAN_HOLD_CEILING = 600;

	/**
	 * How long a check stands. Older than this, the screen asks for another
	 * before anything is removed on its strength.
	 */
	const SCAN_VALID_SECONDS = 604800;

	/**
	 * The check, its findings, and the removal the shop asked for.
	 */
	const SCAN_OPTION = 'tcgiant_sync_photo_scan';

	/**
	 * Prefix of the options holding the check's findings, CHUNK at a time.
	 */
	const SCAN_IDS_PREFIX = 'tcgiant_sync_photo_scan_ids_';

	/**
	 * Running totals of what the removal has done, for the screen.
	 */
	const STATS_OPTION = 'tcgiant_sync_photo_cleanup_stats';

	/**
	 * When the removal last paused, why, and until when. See PAUSE_SECONDS.
	 */
	const PAUSE_OPTION = 'tcgiant_sync_photo_cleanup_pause';

	/**
	 * The photo a run has asked WordPress to delete, and when, for as long as
	 * WordPress and other plugins are at it.
	 *
	 * Taken away the moment wp_delete_attachment() returns or throws. Still
	 * there when the next run starts, it says that the run before died in
	 * that deletion, however it died. The record a run leaves when PHP ends it
	 * is written by a shutdown handler, and a host ending a request from
	 * outside - PHP-FPM's request_terminate_timeout, LiteSpeed, the
	 * out-of-memory killer, a container's limit - runs none. See
	 * paused_until().
	 */
	const DELETING_OPTION = 'tcgiant_sync_photo_cleanup_deleting';

	/**
	 * When a run began reading where photos are used on the site, for as long
	 * as it is at it.
	 *
	 * That reading - the content of every post, page and product, page
	 * builders' data, the site's settings - is the longest thing a run does
	 * before it deletes anything, and on a big shop the likeliest place for
	 * the host to end the request. Taken away the moment the reading ends or
	 * throws; still there when the next run starts, it says that the run
	 * before died in it, however it died, as DELETING_OPTION does for a
	 * deletion. See paused_until().
	 */
	const READING_OPTION = 'tcgiant_sync_photo_cleanup_reading';

	/**
	 * The same, for a step of the check, with the check it was a step of.
	 * See ajax_scan().
	 */
	const CHECK_READING_OPTION = 'tcgiant_sync_photo_check_reading';

	/**
	 * What a run, or a step of the check, says it is working on while it
	 * reads where photos are used, so that the record of a request PHP ends
	 * there says so. See paused_until().
	 */
	const READING_CONTEXT = 'the reading of where photos are used on the site';

	/**
	 * What became of the photos the shop's button put in the queue, for the
	 * screen: the check they came from, and how many were removed, how much
	 * that freed, how many were kept and how many could not be removed.
	 */
	const CHECK_STATS_OPTION = 'tcgiant_sync_photo_cleanup_check';

	/**
	 * When this version first ran on the site.
	 *
	 * A product already in the Trash by then had its photos kept on the
	 * understanding that nothing removed them, and the shop has not been
	 * told otherwise: WordPress empties a Trash on its own, thirty days on,
	 * and a shop emptying it may not know that photos now go with it. So
	 * the removal that follows a deletion is only for products trashed after
	 * this, and the photos of one trashed before become leftovers like any
	 * other - found by the check, shown to the shop with how much space they
	 * take and the warning to back up, and removed only when the shop says.
	 */
	const SINCE_OPTION = 'tcgiant_sync_photo_cleanup_since';

	/**
	 * Prefix of the options that stop two requests doing the same work at once.
	 */
	const LOCK_PREFIX = 'tcgiant_sync_photo_cleanup_lock_';

	/**
	 * Name in the log, and for the record of a run PHP kills.
	 */
	const JOB = 'Photo cleanup';

	/**
	 * The same, for the check and the button on Image Cleanup - kept apart, so
	 * a check the host stops does not read as a removal that died deleting a
	 * photo, which pauses the removal.
	 */
	const CHECK_JOB = 'Photo check';

	/**
	 * Instance.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * Photos marked in this request, how many deleted products they were the
	 * photos of, and how many deleted products were held back, for the lines
	 * a request writes at its end.
	 *
	 * @var int[]
	 */
	private static $marked_now = array( 'photos' => 0, 'products' => 0, 'held_back' => 0 );

	/**
	 * Products deleted in this request whose photos were held back, so that
	 * their variations - which WooCommerce deletes from inside the product's
	 * own deletion, by when its record of being trashed has gone - follow it.
	 *
	 * @var bool[]
	 */
	private static $held_back = array();

	/**
	 * The photo this run has started a deletion of, so that one which throws
	 * is charged an attempt however far it got.
	 *
	 * @var int
	 */
	private static $charged = 0;

	/**
	 * Holds this request has taken, by what they hold, with the value each
	 * was written with.
	 *
	 * @var string[]
	 */
	private static $held = array();

	/**
	 * What the run, or the step of the check, under way has read of where
	 * photos are used - see open_uses() - or null outside one.
	 *
	 * @var array|null
	 */
	private static $uses = null;

	/**
	 * What this request's watched job last said it is working on, so that the
	 * reading of the site can say it is reading, and then hand back.
	 *
	 * @var string
	 */
	private static $context = '';

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
	 * Constructor.
	 *
	 * Registered on every request, not only in the admin: products are deleted
	 * by WP-Cron emptying the Trash, by the REST API and by WP-CLI as well.
	 */
	public function __construct() {
		add_action( 'before_delete_post', array( __CLASS__, 'on_before_delete_post' ), 10, 2 );
		add_action( self::CLEANUP_HOOK, array( $this, 'process_queue' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_rearm_cleanup' ) );

		add_action( 'wp_ajax_tcgiant_photo_scan', array( __CLASS__, 'ajax_scan' ) );
		add_action( 'wp_ajax_tcgiant_photo_remove', array( __CLASS__, 'ajax_remove' ) );
		add_action( 'wp_ajax_tcgiant_photo_status', array( __CLASS__, 'ajax_status' ) );

		self::note_switched_on();
	}

	/**
	 * Remember when this version first ran. See SINCE_OPTION.
	 *
	 * On the first request of any kind - a visitor's, WP-Cron's - so that it
	 * is in place before WordPress empties a Trash. Autoloaded, so reading it
	 * here on every later request costs nothing.
	 *
	 * @return void
	 */
	private static function note_switched_on() {
		if ( false === get_option( self::SINCE_OPTION, false ) ) {
			add_option( self::SINCE_OPTION, time(), '', 'yes' );
		}
	}

	// ───────────────────────────────────────────────────────────────────────────
	// A product deleted for good
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Mark the photos of a product that is about to be deleted for good.
	 *
	 * before_delete_post fires only when a post is really being deleted - the
	 * Trash, WordPress emptying it after thirty days, "Delete Permanently",
	 * the REST API with force - and while the product still has its meta and
	 * its attachments.
	 *
	 * A variation's own photo comes through here when the variation is
	 * deleted too. WooCommerce trashes a product's variations with it, and
	 * deletes them from inside the product's deletion - but only for someone
	 * allowed to delete posts, in most versions. WordPress emptying the Trash
	 * on its own, from WP-Cron, is nobody; it reaches each variation anyway,
	 * on the variation's own record of being trashed. A product deleted
	 * straight from the shop by something with nobody behind it - WP-CLI
	 * without --user, a script - leaves its variations behind, each still
	 * showing its photo, and those photos stay, as anything still shown does.
	 *
	 * Marking is all that happens here. Whether a photo is really deleted is
	 * decided later, by the same checks as everything else, so a photo another
	 * product shares is kept, and so is every photo of a deletion that does not
	 * go through after all.
	 *
	 * @param int          $post_id Post being deleted.
	 * @param WP_Post|null $post    The post (WordPress 5.5 and later).
	 * @return void
	 */
	public static function on_before_delete_post( $post_id, $post = null ) {
		$post_id = (int) $post_id;
		if ( ! is_object( $post ) ) {
			$post = get_post( $post_id );
		}
		$type = ( is_object( $post ) && isset( $post->post_type ) ) ? (string) $post->post_type : '';

		if ( ! in_array( $type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}

		/**
		 * Whether to remove the downloaded photos of a product deleted for good.
		 *
		 * @param bool $remove  Default true.
		 * @param int  $post_id Product or variation being deleted.
		 */
		if ( ! apply_filters( 'tcgiant_sync_remove_photos_of_deleted_products', true, $post_id ) ) {
			return;
		}

		// Nothing here may stop a product being deleted.
		try {
			if ( self::trashed_before_switch_on( $post_id, $post ) ) {
				self::$held_back[ $post_id ] = true;
				// Counted as products, which is what the shop deleted.
				self::note_for_log( 'held_back', 'product' === $type ? 1 : 0 );
				return;
			}

			$marked = self::mark_photos_of( $post_id );
		} catch ( \Throwable $e ) {
			TCGiant_Sync_Logger::error( sprintf(
				'Photo cleanup: could not note the photos of WC #%d as it was deleted (%s). They stay where they are; Image Cleanup can find them later.',
				$post_id,
				$e->getMessage()
			) );
			return;
		}

		if ( $marked < 1 ) {
			return;
		}

		self::note_for_log( 'photos', $marked );
		self::note_for_log( 'products', 1 );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_single_event( time() + self::QUARANTINE_SECONDS + 60, self::CLEANUP_HOOK );
		}
	}

	/**
	 * Whether a product being deleted was already in the Trash when this
	 * version arrived, so its photos are left for the shop to see first.
	 *
	 * WordPress keeps when a post was trashed in _wp_trash_meta_time, and it is
	 * still there at before_delete_post. A product deleted without passing
	 * through the Trash is being deleted now, by someone or something acting
	 * now. A product in the Trash with no record of when it got there could
	 * have been there for years, and is held back with the rest.
	 *
	 * @param int     $post_id Product or variation being deleted.
	 * @param WP_Post $post    It.
	 * @return bool
	 */
	private static function trashed_before_switch_on( $post_id, $post ) {
		if ( 'product_variation' === $post->post_type && isset( self::$held_back[ (int) $post->post_parent ] ) ) {
			return true;
		}

		if ( 'trash' !== $post->post_status ) {
			return false;
		}

		$since   = (int) get_option( self::SINCE_OPTION, 0 );
		$trashed = (int) get_post_meta( $post_id, '_wp_trash_meta_time', true );

		return $since < 1 || $trashed < 1 || $trashed < $since;
	}

	/**
	 * Count something for the lines this request writes at its end.
	 *
	 * One line for the request, however many products it deletes. Emptying a
	 * Trash of a thousand products would otherwise write a thousand.
	 *
	 * @param string $what   photos, products or held_back.
	 * @param int    $amount How many.
	 * @return void
	 */
	private static function note_for_log( $what, $amount ) {
		if ( 0 === array_sum( self::$marked_now ) ) {
			add_action( 'shutdown', array( __CLASS__, 'log_marked' ) );
		}
		self::$marked_now[ $what ] += (int) $amount;
	}

	/**
	 * The lines for a request that deleted products.
	 *
	 * @return void
	 */
	public static function log_marked() {
		if ( self::$marked_now['photos'] > 0 ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Photo cleanup: %d photo(s) of %d product(s) deleted for good will be removed in about %d minutes, unless something else still uses them.',
				self::$marked_now['photos'],
				self::$marked_now['products'],
				(int) ceil( self::QUARANTINE_SECONDS / 60 )
			) );
		}

		if ( self::$marked_now['held_back'] > 0 ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Photo cleanup: %d product(s) deleted for good had been in the Trash since before this version of the plugin, so their photos were left where they are. Image Cleanup can find them, and shows what it found and how much space it takes before anything is removed.',
				self::$marked_now['held_back']
			) );
		}

		self::$marked_now = array( 'photos' => 0, 'products' => 0, 'held_back' => 0 );
	}

	/**
	 * Mark every downloaded photo a product shows or holds.
	 *
	 * Both the photos it shows (its featured image and gallery - which may be
	 * attached to some other product, when the image batch reused a download)
	 * and the photos attached to it (which may no longer be shown anywhere,
	 * after "Overwrite Images" replaced them).
	 *
	 * @param int $post_id Product or variation.
	 * @return int Photos newly marked.
	 */
	private static function mark_photos_of( $post_id ) {
		global $wpdb;

		$ids = array();

		$thumb = (int) get_post_meta( $post_id, '_thumbnail_id', true );
		if ( $thumb > 0 ) {
			$ids[] = $thumb;
		}

		foreach ( self::gallery_keys() as $key ) {
			$ids = array_merge( $ids, self::listed_ids( get_post_meta( $post_id, $key, true ) ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$children = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'attachment'",
			$post_id
		) );

		$ids = self::ids( array_merge( $ids, (array) $children ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$when   = (string) ( time() + self::QUARANTINE_SECONDS );
		$marked = 0;

		foreach ( self::attachment_rows( $ids ) as $id => $row ) {
			if ( ! $row['ours'] || $row['failed'] ) {
				continue; // The shop's own, an eBay stand-in, or one given up on before.
			}
			// Unique, so a photo already waiting keeps its place.
			if ( add_post_meta( $id, self::MARK_KEY, $when, true ) ) {
				$marked++;
			}
		}

		return $marked;
	}

	// ───────────────────────────────────────────────────────────────────────────
	// What may be deleted
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Which of these photos may be deleted now, and why not for the rest.
	 *
	 * Asked of the database as it is now, and made in sets so that a run over
	 * thousands of photos costs a handful of queries per round rather than
	 * several per photo. What can only be read for the whole site at once -
	 * galleries, content, settings - is read once for a run or a step of the
	 * check, while one is under way (see open_uses()), and re-read for each
	 * photo's last look where it can change (see delete_one()).
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return string[] ID => '' when it may be deleted, otherwise why not:
	 *                  gone, not_ours, attached, in_use, shared_file, no_file,
	 *                  failed.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer
	 *                                       one of the reads. Nothing is judged.
	 */
	public static function verdicts( array $ids ) {
		$ids = self::ids( $ids );
		if ( empty( $ids ) ) {
			return array();
		}

		$out    = array_fill_keys( $ids, 'gone' );
		$open   = array();
		$failed = array();

		foreach ( self::attachment_rows( $ids ) as $id => $row ) {
			if ( ! $row['ours'] || 'inherit' !== $row['status'] ) {
				$out[ $id ] = 'not_ours';
			} elseif ( $row['parent'] > 0 && $row['parent_found'] ) {
				$out[ $id ] = 'attached';
			} else {
				$out[ $id ] = '';
				$open[]     = $id;
				if ( $row['failed'] ) {
					$failed[ $id ] = true;
				}
			}
		}

		if ( empty( $open ) ) {
			return $out;
		}

		foreach ( self::in_use( $open ) as $id ) {
			$out[ $id ] = 'in_use';
		}

		$open = self::still_open( $out );
		if ( empty( $open ) ) {
			return $out;
		}

		$records = self::file_records( $open );
		foreach ( $open as $id ) {
			if ( '' === ( $records[ $id ]['file'] ?? '' ) ) {
				$out[ $id ] = 'no_file'; // Nothing on record to free; left as it is.
			}
		}

		foreach ( self::shared_files( $records ) as $id ) {
			$out[ $id ] = 'shared_file';
		}

		// Last, so that what the check counts as given up on is only what
		// would otherwise have gone - the photos the shop has to look at.
		foreach ( array_keys( $failed ) as $id ) {
			if ( '' === $out[ $id ] ) {
				$out[ $id ] = 'failed';
			}
		}

		$open = self::still_open( $out );

		/**
		 * Photos another plugin still uses, which must not be deleted.
		 *
		 * For uses this cannot see - a swatch plugin keeping attachment IDs in
		 * term meta of its own, a page builder keeping them in post meta, say.
		 *
		 * @param int[] $keep IDs to keep. Default none.
		 * @param int[] $ids  Photos about to be deleted.
		 */
		$keep = (array) apply_filters( 'tcgiant_sync_photos_in_use', array(), $open );
		foreach ( self::ids( $keep ) as $id ) {
			if ( isset( $out[ $id ] ) && '' === $out[ $id ] ) {
				$out[ $id ] = 'in_use';
			}
		}

		return $out;
	}

	/**
	 * The IDs a set of verdicts still lets go.
	 *
	 * @param string[] $verdicts From verdicts().
	 * @return int[]
	 */
	private static function still_open( array $verdicts ) {
		return array_keys( array_filter( $verdicts, function ( $why ) {
			return '' === $why;
		} ) );
	}

	/**
	 * Whether the plugin downloaded each attachment, and what it is attached to.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array[] ID => ours, status, parent, parent_found, failed. Absent
	 *                 when not an attachment.
	 */
	private static function attachment_rows( array $ids ) {
		global $wpdb;

		$rows   = array();
		$failed = self::FAILED_KEY;

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$found = $wpdb->get_results(
				"SELECT a.ID, a.post_parent, a.post_status, par.ID AS parent_found,
				        MAX( CASE WHEN m.meta_key = '_tcgiant_source_url' AND m.meta_value <> '' THEN 1 ELSE 0 END ) AS has_src,
				        MAX( CASE WHEN m.meta_key = '_tcgiant_external_url' THEN 1 ELSE 0 END ) AS has_ext,
				        MAX( CASE WHEN m.meta_key = '{$failed}' THEN 1 ELSE 0 END ) AS has_failed
				 FROM {$wpdb->posts} a
				 LEFT JOIN {$wpdb->posts} par ON par.ID = a.post_parent
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = a.ID AND m.meta_key IN ( '_tcgiant_source_url', '_tcgiant_external_url', '{$failed}' )
				 WHERE a.ID IN ( {$in} )
				   AND a.post_type = 'attachment'
				 GROUP BY a.ID, a.post_parent, a.post_status, par.ID"
			);
			self::read_ok();

			foreach ( (array) $found as $row ) {
				$rows[ (int) $row->ID ] = array(
					'ours'         => 1 === (int) $row->has_src && 0 === (int) $row->has_ext,
					'status'       => (string) $row->post_status,
					'parent'       => (int) $row->post_parent,
					'parent_found' => ! empty( $row->parent_found ),
					'failed'       => 1 === (int) $row->has_failed,
				);
			}
		}

		return $rows;
	}

	/**
	 * Which of these photos something still shows.
	 *
	 * The featured image of any post of any type or status - products,
	 * variations, posts, pages, anything in the Trash. Every gallery list (see
	 * gallery_keys()), of any status. Every category picture. And everything
	 * site_uses() reads: a photo named in content, an excerpt, a variation's
	 * description or a category's, a file pointed at by its address, a
	 * widget, the site's own pictures.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return int[]
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	public static function in_use( array $ids ) {
		global $wpdb;

		$ids  = self::ids( $ids );
		$used = array();

		if ( empty( $ids ) ) {
			return $used;
		}

		// As strings, the way they are stored, so that the plugin's
		// (meta_key, meta_value) index answers this directly. Compared as
		// numbers, every row would have to be read and converted.
		foreach ( array_chunk( array_map( 'strval', $ids ), 500 ) as $chunk ) {
			$marks = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$used = array_merge( $used, (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value IN ( {$marks} )",
				$chunk
			) ) );
			self::read_ok();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$used = array_merge( $used, (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->termmeta} WHERE meta_key = 'thumbnail_id' AND meta_value IN ( {$marks} )",
				$chunk
			) ) );
			self::read_ok();
		}

		$want = array_flip( $ids );
		$site = self::site_uses();

		$used = array_merge(
			$used,
			array_keys( array_intersect_key( self::in_galleries( $ids ), $want ) ),
			array_keys( array_intersect_key( $site['ids'], $want ) )
		);

		// A file pointed at by its address, in whichever size: the photo is
		// matched on the file it has on record, as file_key() reads both.
		if ( ! empty( $site['files'] ) ) {
			foreach ( self::attached_files( $ids ) as $id => $file ) {
				if ( isset( $site['files'][ self::file_key( $file ) ] ) ) {
					$used[] = $id;
				}
			}
		}

		return array_values( array_filter( self::ids( $used ), function ( $id ) use ( $want ) {
			return isset( $want[ $id ] );
		} ) );
	}

	/**
	 * Which of these photos a gallery lists.
	 *
	 * From the reading the run already has, when it covers them; otherwise
	 * read now, for these and for whatever the run said comes next (see
	 * look_ahead()), so that the rounds after this one need no reading of
	 * their own.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return bool[] ID => true, for those listed. May hold others too.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function in_galleries( array $ids ) {
		if ( null === self::$uses ) {
			return self::read_galleries( $ids );
		}

		if ( ! self::covered( $ids ) ) {
			$for                       = self::ids( array_merge( $ids, self::$uses['ahead'] ) );
			self::$uses['galleries']   = self::read_galleries( $for );
			self::$uses['gallery_for'] = array_flip( $for );
			self::$uses['gallery_at']  = TCGiant_Sync_Runtime::elapsed();
		}

		return self::$uses['galleries'];
	}

	/**
	 * Whether the run's reading of the galleries covers every one of these.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return bool
	 */
	private static function covered( array $ids ) {
		return null !== self::$uses && empty( array_diff_key( array_flip( self::ids( $ids ) ), self::$uses['gallery_for'] ) );
	}

	/**
	 * Tell the reading of the galleries which photos come next.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return void
	 */
	private static function look_ahead( array $ids ) {
		if ( null !== self::$uses ) {
			self::$uses['ahead'] = self::ids( $ids );
		}
	}

	/**
	 * Which of these photos any gallery list on the site holds, read now.
	 *
	 * Galleries are lists, which no index can search, so they are read through
	 * in pages - one pass for the whole set, never one per photo.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return bool[] ID => true, for those listed.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function read_galleries( array $ids ) {
		global $wpdb;

		$want  = array_flip( self::ids( $ids ) );
		$found = array();
		$page  = 2000;

		if ( empty( $want ) ) {
			return $found;
		}

		foreach ( self::gallery_keys() as $key ) {
			$after = 0;
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = (array) $wpdb->get_results( $wpdb->prepare(
					"SELECT meta_id, meta_value FROM {$wpdb->postmeta}
					 WHERE meta_key = %s AND meta_id > %d
					 ORDER BY meta_id ASC
					 LIMIT %d",
					$key,
					$after,
					$page
				) );
				self::read_ok();

				foreach ( $rows as $row ) {
					$after = (int) $row->meta_id;
					foreach ( self::listed_ids( $row->meta_value ) as $gid ) {
						if ( isset( $want[ $gid ] ) ) {
							$found[ $gid ] = true;
						}
					}
				}
			} while ( count( $rows ) === $page );
		}

		return $found;
	}

	/**
	 * The IDs a gallery list holds: comma-separated, as WooCommerce keeps
	 * them, or a serialized array, as some gallery plugins do.
	 *
	 * @param mixed $value The meta value, as stored or as read.
	 * @return int[]
	 */
	private static function listed_ids( $value ) {
		$value = maybe_unserialize( $value );

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$flat = array();
			array_walk_recursive( $value, function ( $item ) use ( &$flat ) {
				if ( is_scalar( $item ) ) {
					$flat[] = (string) $item;
				}
			} );
			$value = implode( ',', $flat );
		}

		if ( ! is_scalar( $value ) ) {
			return array();
		}

		return self::ids( preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY ) );
	}

	/**
	 * Every photo the site names, and every file it points at, outside
	 * featured images, galleries and category pictures.
	 *
	 * The content and excerpts of the kinds of post content_types() lists;
	 * variations' descriptions, which WooCommerce keeps in post meta; any
	 * other plugin's post meta - a page builder's data, say - that holds an
	 * address under /uploads/; the site's settings that do; the descriptions
	 * of categories, tags and every other term, and their meta that holds an
	 * address (see uses_in_terms()); and widgets and the site's own pictures
	 * (see site_pictures()).
	 *
	 * Read for the whole site at once, not for the photos being asked about,
	 * and only once for a run or a step of the check: these are the slow
	 * reads, and there is little in them. Outside a run, read each time.
	 *
	 * Every query of it is short, whatever plan the database picks for it -
	 * see uses_in_content() and key_window() - and fetches a few megabytes
	 * at most, see read_values(). A reading that has taken as long as a run
	 * may by itself, or would need more memory than the request has left,
	 * is given up, judging nothing. In a run or a step of the check it is
	 * noted while it lasts, so that one the host ends here is seen by the
	 * next. See READING_OPTION.
	 *
	 * @return array[] ids: photo ID => true; files: file_key() => true.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 * @throws TCGiant_Sync_Photo_Read_Late  When the reading took too long.
	 * @throws TCGiant_Sync_Photo_Read_Full  When it would run out of memory.
	 */
	private static function site_uses() {
		if ( null !== self::$uses && null !== self::$uses['site'] ) {
			return self::$uses['site'];
		}

		$site  = array( 'ids' => array(), 'files' => array() );
		$began = TCGiant_Sync_Runtime::elapsed();
		$was   = self::$context;
		$note  = self::note_reading();

		try {
			self::working_on( self::READING_CONTEXT );

			self::uses_in_content( $site, $began );
			self::uses_in_meta( $site, $began );
			self::uses_in_options( $site, $began );
			self::uses_in_terms( $site, $began );
			self::site_pictures( $site );
		} finally {
			if ( '' !== $note ) {
				delete_option( $note );
			}
			self::working_on( $was );
		}

		if ( null !== self::$uses ) {
			self::$uses['site'] = $site;
		}

		return $site;
	}

	/**
	 * Note that this run, or this step of the check, is reading where photos
	 * are used. See READING_OPTION.
	 *
	 * @return string The option that notes it, for the caller to take away
	 *                again, or '' outside a run or a step of the check.
	 */
	private static function note_reading() {
		if ( null === self::$uses ) {
			return '';
		}

		$check = self::CHECK_JOB === self::$uses['job'];
		$name  = $check ? self::CHECK_READING_OPTION : self::READING_OPTION;

		update_option( $name, array( 'at' => time(), 'scan' => $check ? self::$uses['scan'] : '' ), false );

		return $name;
	}

	/**
	 * Give up the reading of where photos are used once it has taken as long
	 * as a run may by itself.
	 *
	 * A run's deletions stop at its budget, but this reading comes before
	 * them, and nothing judged on half of it can stand. On a site that is too
	 * big to read inside a host's limit, carrying on would only have the host
	 * end the request - and with it whatever else WP-Cron was running in it.
	 *
	 * @param float $began Seconds into the request at which the reading began.
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Late When it has.
	 */
	private static function read_in_time( $began ) {
		$took   = TCGiant_Sync_Runtime::elapsed() - (float) $began;
		$budget = TCGiant_Sync_Runtime::time_budget();

		if ( $took >= $budget ) {
			throw new TCGiant_Sync_Photo_Read_Late( sprintf( '%d s, of a %d s budget', (int) $took, (int) $budget ) );
		}
	}

	/**
	 * What the content of the kinds of post content_types() lists names: a
	 * post's or product's content and its excerpt - a product's short
	 * description.
	 *
	 * Walked by the posts table's own key alone, KEY_PAGE rows at a time.
	 * Only then are those rows asked which of them mention a photo in one of
	 * the ways ids_in_content() and files_in() read, and how big each is, and
	 * the content of those fetched - see read_values(). Asked in one query,
	 * the database may answer from its index on the kind instead of the key,
	 * and then look through every remaining row again for each page: a shop
	 * of fifteen thousand products whose eBay template mentions /uploads/
	 * took ten minutes to read.
	 *
	 * @param array $site  Collected so far, added to.
	 * @param float $began When the reading began. See read_in_time().
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 * @throws TCGiant_Sync_Photo_Read_Late  When the reading took too long.
	 * @throws TCGiant_Sync_Photo_Read_Full  When it would run out of memory.
	 */
	private static function uses_in_content( array &$site, $began ) {
		global $wpdb;

		$types = self::content_types();
		if ( empty( $types ) ) {
			return;
		}

		$after   = 0;
		$kinds   = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$needles = self::needles();
		$mention = implode( ' OR ', array_merge(
			array_fill( 0, count( $needles ), 'post_content LIKE %s' ),
			array_fill( 0, count( $needles ), 'post_excerpt LIKE %s' )
		) );

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$keys = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type IN ( {$kinds} )
				   AND ID > %d
				 ORDER BY ID ASC
				 LIMIT %d",
				array_merge( $types, array( $after, self::KEY_PAGE ) )
			) ) );
			self::read_ok();

			if ( $keys ) {
				$in = implode( ',', $keys );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = (array) $wpdb->get_results( $wpdb->prepare(
					"SELECT ID AS id, LENGTH( post_content ) + LENGTH( post_excerpt ) AS bytes FROM {$wpdb->posts}
					 WHERE ID IN ( {$in} ) AND ( {$mention} )
					 ORDER BY ID ASC",
					array_merge( $needles, $needles )
				) );
				self::read_ok();
				$sizes = self::sizes( $rows );
				unset( $rows );

				self::read_values( $site, $sizes, $wpdb->posts, 'ID', array( 'post_content', 'post_excerpt' ), true, $began );

				$after = (int) end( $keys );
			}
			self::read_in_time( $began );
		} while ( count( $keys ) === self::KEY_PAGE );
	}

	/**
	 * What post meta names: variations' descriptions, and the data of any
	 * plugin - a page builder's, say - that holds an address under /uploads/.
	 *
	 * Photos' own records are left out, and so are old revisions, as their
	 * content is. A builder's data names photos by ID as well as by address,
	 * and both are taken. Descriptions are walked by key first, as content
	 * is; the rest a WINDOW of rows at a time. See key_window(). Either way,
	 * what is found is fetched as read_values() fetches it.
	 *
	 * @param array $site  Collected so far, added to.
	 * @param float $began When the reading began. See read_in_time().
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 * @throws TCGiant_Sync_Photo_Read_Late  When the reading took too long.
	 * @throws TCGiant_Sync_Photo_Read_Full  When it would run out of memory.
	 */
	private static function uses_in_meta( array &$site, $began ) {
		global $wpdb;

		$needles = self::needles();
		$mention = implode( ' OR ', array_fill( 0, count( $needles ), 'meta_value LIKE %s' ) );

		$after = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$keys = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_variation_description'
				   AND meta_id > %d
				 ORDER BY meta_id ASC
				 LIMIT %d",
				$after,
				self::KEY_PAGE
			) ) );
			self::read_ok();

			if ( $keys ) {
				$in = implode( ',', $keys );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = (array) $wpdb->get_results( $wpdb->prepare(
					"SELECT meta_id AS id, LENGTH( meta_value ) AS bytes FROM {$wpdb->postmeta}
					 WHERE meta_id IN ( {$in} ) AND ( {$mention} )
					 ORDER BY meta_id ASC",
					$needles
				) );
				self::read_ok();
				$sizes = self::sizes( $rows );
				unset( $rows );

				self::read_values( $site, $sizes, $wpdb->postmeta, 'meta_id', array( 'meta_value' ), true, $began );

				$after = (int) end( $keys );
			}
			self::read_in_time( $began );
		} while ( count( $keys ) === self::KEY_PAGE );

		$after = 0;
		do {
			$end = self::key_window( $wpdb->postmeta, 'meta_id', $after );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT m.meta_id AS id, LENGTH( m.meta_value ) AS bytes FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_id > %d AND m.meta_id <= %d
				   AND m.meta_value LIKE %s
				   AND m.meta_key <> '_variation_description'
				   AND p.post_type NOT IN ( 'attachment', 'revision' )
				 ORDER BY m.meta_id ASC",
				$after,
				$end,
				'%' . $wpdb->esc_like( 'uploads' ) . '%'
			) );
			self::read_ok();
			$sizes = self::sizes( $rows );
			unset( $rows );

			self::read_values( $site, $sizes, $wpdb->postmeta, 'meta_id', array( 'meta_value' ), true, $began );
			self::read_in_time( $began );

			$after = $end;
		} while ( $end < PHP_INT_MAX );
	}

	/**
	 * The files the site's settings point at by address under /uploads/ -
	 * a theme's options, a slider's, the Customizer's. Not transients, which
	 * are copies of something kept elsewhere.
	 *
	 * Addresses only: a setting holding a number is no sign of a photo. A
	 * WINDOW of rows at a time, as a site can keep hundreds of thousands of
	 * them (see key_window()), and what is found is fetched as read_values()
	 * fetches it.
	 *
	 * @param array $site  Collected so far, added to.
	 * @param float $began When the reading began. See read_in_time().
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 * @throws TCGiant_Sync_Photo_Read_Late  When the reading took too long.
	 * @throws TCGiant_Sync_Photo_Read_Full  When it would run out of memory.
	 */
	private static function uses_in_options( array &$site, $began ) {
		global $wpdb;

		$after = 0;
		do {
			$end = self::key_window( $wpdb->options, 'option_id', $after );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT option_id AS id, LENGTH( option_value ) AS bytes FROM {$wpdb->options}
				 WHERE option_id > %d AND option_id <= %d
				   AND option_value LIKE %s
				   AND option_name NOT LIKE %s
				   AND option_name NOT LIKE %s
				 ORDER BY option_id ASC",
				$after,
				$end,
				'%' . $wpdb->esc_like( 'uploads' ) . '%',
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%'
			) );
			self::read_ok();
			$sizes = self::sizes( $rows );
			unset( $rows );

			self::read_values( $site, $sizes, $wpdb->options, 'option_id', array( 'option_value' ), false, $began );
			self::read_in_time( $began );

			$after = $end;
		} while ( $end < PHP_INT_MAX );
	}

	/**
	 * What the descriptions of categories, tags, brands and every other term
	 * name, and the files term meta points at by an address under /uploads/ -
	 * a theme's content for the top or foot of a category's page, an SEO
	 * plugin's picture for sharing it.
	 *
	 * WooCommerce shows a category's description at the top of its page, and
	 * the editor some themes and plugins give descriptions places a photo
	 * there as the post editor does, so descriptions are read as content is,
	 * whatever the taxonomy: walked by the table's own key alone, KEY_PAGE
	 * rows at a time, and only then asked which of those rows mention a
	 * photo, and how big each is. Term meta is read as other plugins' post
	 * meta is, a WINDOW of rows at a time (see key_window()), for an address,
	 * and the IDs that come with it are taken too - and for Flatsome's
	 * shortcodes, which name a photo by number alone, as its content for the
	 * top and foot of a category's page does. Either way, what is found is
	 * fetched as read_values() fetches it. A term's picture by its ID is
	 * in_use()'s to see.
	 *
	 * @param array $site  Collected so far, added to.
	 * @param float $began When the reading began. See read_in_time().
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 * @throws TCGiant_Sync_Photo_Read_Late  When the reading took too long.
	 * @throws TCGiant_Sync_Photo_Read_Full  When it would run out of memory.
	 */
	private static function uses_in_terms( array &$site, $began ) {
		global $wpdb;

		$needles = self::needles();
		$mention = implode( ' OR ', array_fill( 0, count( $needles ), 'description LIKE %s' ) );

		$after = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$keys = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
				 WHERE term_taxonomy_id > %d
				 ORDER BY term_taxonomy_id ASC
				 LIMIT %d",
				$after,
				self::KEY_PAGE
			) ) );
			self::read_ok();

			if ( $keys ) {
				$in = implode( ',', $keys );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = (array) $wpdb->get_results( $wpdb->prepare(
					"SELECT term_taxonomy_id AS id, LENGTH( description ) AS bytes FROM {$wpdb->term_taxonomy}
					 WHERE term_taxonomy_id IN ( {$in} ) AND ( {$mention} )
					 ORDER BY term_taxonomy_id ASC",
					$needles
				) );
				self::read_ok();
				$sizes = self::sizes( $rows );
				unset( $rows );

				self::read_values( $site, $sizes, $wpdb->term_taxonomy, 'term_taxonomy_id', array( 'description' ), true, $began );

				$after = (int) end( $keys );
			}
			self::read_in_time( $began );
		} while ( count( $keys ) === self::KEY_PAGE );

		$looks = self::like_patterns( array_merge( array( 'uploads' ), self::UX_NEEDLES ) );
		$holds = implode( ' OR ', array_fill( 0, count( $looks ), 'meta_value LIKE %s' ) );

		$after = 0;
		do {
			$end = self::key_window( $wpdb->termmeta, 'meta_id', $after );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_id AS id, LENGTH( meta_value ) AS bytes FROM {$wpdb->termmeta}
				 WHERE meta_id > %d AND meta_id <= %d
				   AND ( {$holds} )
				 ORDER BY meta_id ASC",
				array_merge( array( $after, $end ), $looks )
			) );
			self::read_ok();
			$sizes = self::sizes( $rows );
			unset( $rows );

			self::read_values( $site, $sizes, $wpdb->termmeta, 'meta_id', array( 'meta_value' ), true, $began );
			self::read_in_time( $began );

			$after = $end;
		} while ( $end < PHP_INT_MAX );
	}

	/**
	 * The rows a query of the reading found, as the bytes of each one's
	 * value, by its key.
	 *
	 * @param array $rows From get_results(): id and bytes.
	 * @return int[] Key => bytes, in the order found.
	 */
	private static function sizes( array $rows ) {
		$sizes = array();
		foreach ( $rows as $row ) {
			$sizes[ (int) $row->id ] = (int) $row->bytes;
		}
		return $sizes;
	}

	/**
	 * Fetch the values of rows the reading found, and add what they name to
	 * what has been collected: a group at a time (see byte_groups()), asking
	 * first whether this request has the memory for it (see room_for()), and
	 * letting go of each group before the next is fetched.
	 *
	 * @param array    $site     Collected so far, added to.
	 * @param int[]    $sizes    Key => bytes of the row's values, from sizes().
	 * @param string   $table    The table.
	 * @param string   $key      Its primary key.
	 * @param string[] $columns  The values to read.
	 * @param bool     $with_ids Whether numbers in them are photo IDs. See
	 *                           note_uses().
	 * @param float    $began    When the reading began. See read_in_time().
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 * @throws TCGiant_Sync_Photo_Read_Late  When the reading took too long.
	 * @throws TCGiant_Sync_Photo_Read_Full  When it would run out of memory.
	 */
	private static function read_values( array &$site, array $sizes, $table, $key, array $columns, $with_ids, $began ) {
		global $wpdb;

		$select = implode( ', ', $columns );

		foreach ( self::byte_groups( $sizes ) as $group ) {
			self::room_for( $group['bytes'] );

			$in = implode( ',', array_map( 'intval', $group['ids'] ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( "SELECT {$select} FROM {$table} WHERE {$key} IN ( {$in} )" );
			self::read_ok();

			foreach ( $rows as $row ) {
				foreach ( $columns as $column ) {
					self::note_uses( $site, (string) ( $row->$column ?? '' ), $with_ids );
				}
			}

			// wpdb keeps its last answer, and the database driver its own copy
			// of it, until the next query. Both go now, so that the next group
			// is measured against what is really left.
			unset( $rows );
			if ( method_exists( $wpdb, 'flush' ) ) {
				$wpdb->flush();
			}

			self::read_in_time( $began );
		}
	}

	/**
	 * Rows in the groups their values are fetched in: READ_PAGE rows at
	 * most, coming to READ_BYTES at most between them - or one row on its
	 * own, when its value alone is bigger.
	 *
	 * @param int[] $sizes Key => bytes, in the order to fetch them.
	 * @return array[] Each: ids, the rows' keys; bytes, what they come to.
	 */
	private static function byte_groups( array $sizes ) {
		$groups = array();
		$ids    = array();
		$bytes  = 0;

		foreach ( $sizes as $id => $size ) {
			$size = max( 0, (int) $size );
			if ( $ids && ( count( $ids ) >= self::READ_PAGE || $bytes + $size > self::READ_BYTES ) ) {
				$groups[] = array( 'ids' => $ids, 'bytes' => $bytes );
				$ids      = array();
				$bytes    = 0;
			}
			$ids[]  = (int) $id;
			$bytes += $size;
		}

		if ( $ids ) {
			$groups[] = array( 'ids' => $ids, 'bytes' => $bytes );
		}

		return $groups;
	}

	/**
	 * Give up the reading of where photos are used when this request has not
	 * the memory left to fetch the next group of it.
	 *
	 * Running out part way would end the request where it stands - the rest
	 * of WP-Cron's work in it with it - and nothing judged on half a reading
	 * could stand anyway. What this request's own copies hold is let go of
	 * first, in case that is enough. See TCGiant_Sync_Runtime::release_memory().
	 *
	 * @param int $bytes What the next group's values come to.
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Full When the memory is not there.
	 */
	private static function room_for( $bytes ) {
		$limit = TCGiant_Sync_Runtime::memory_limit();
		if ( $limit < 1 ) {
			return; // No limit.
		}

		$need = self::READ_MEMORY_TIMES * max( 0, (int) $bytes ) + self::READ_MEMORY_SPARE;
		if ( $limit - TCGiant_Sync_Runtime::memory_used() >= $need ) {
			return;
		}

		TCGiant_Sync_Runtime::release_memory();
		$left = $limit - TCGiant_Sync_Runtime::memory_used();
		if ( $left >= $need ) {
			return;
		}

		throw new TCGiant_Sync_Photo_Read_Full( sprintf(
			'%1$d MB of a %2$d MB memory limit left, and the next part of it needs about %3$d MB',
			(int) floor( max( 0, $left ) / 1048576 ),
			(int) round( $limit / 1048576 ),
			(int) ceil( $need / 1048576 )
		) );
	}

	/**
	 * The key a window of a table's rows ends at: WINDOW rows on from $after,
	 * in the table's own key, or PHP_INT_MAX when fewer are left.
	 *
	 * A LIKE on a value no index can answer looks through every row; within
	 * a window of the key it looks through at most WINDOW, so no one query
	 * of the reading runs for long on however big a table, and the reading
	 * can stop in time between them.
	 *
	 * @param string $table Table.
	 * @param string $key   Its primary key.
	 * @param int    $after Where the window starts, not included.
	 * @return int
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function key_window( $table, $key, $after ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$end = $wpdb->get_var( $wpdb->prepare(
			"SELECT {$key} FROM {$table} WHERE {$key} > %d ORDER BY {$key} ASC LIMIT 1 OFFSET %d",
			(int) $after,
			self::WINDOW - 1
		) );
		self::read_ok();

		return null === $end ? PHP_INT_MAX : (int) $end;
	}

	/**
	 * The site's own pictures - its logo and icon, the theme's logo, header
	 * and background, the one WooCommerce shows for a product without a
	 * photo - and what its widgets show.
	 *
	 * Read from the table, so that a read the database fails stops here
	 * rather than coming back as "not set", the way get_option() would.
	 *
	 * @param array $site Collected so far, added to.
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function site_pictures( array &$site ) {
		global $wpdb;

		$mods  = 'theme_mods_' . ( function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : (string) get_option( 'stylesheet' ) );
		$names = array( 'site_logo', 'site_icon', 'woocommerce_placeholder_image', 'widget_media_image', 'widget_media_gallery', 'widget_block', 'widget_text', 'widget_custom_html', $mods );
		$marks = implode( ', ', array_fill( 0, count( $names ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ( {$marks} )",
			$names
		) );
		self::read_ok();

		$opt = array();
		foreach ( $rows as $row ) {
			$opt[ (string) $row->option_name ] = maybe_unserialize( $row->option_value );
		}

		$ids   = array( $opt['site_logo'] ?? 0, $opt['site_icon'] ?? 0, $opt['woocommerce_placeholder_image'] ?? 0 );
		$theme = isset( $opt[ $mods ] ) && is_array( $opt[ $mods ] ) ? $opt[ $mods ] : array();

		// The header by its ID. The header's and the background's addresses
		// are in this same setting, and uses_in_options() reads them there,
		// with every other setting that holds an address.
		$ids[]  = $theme['custom_logo'] ?? 0;
		$header = $theme['header_image_data'] ?? array();
		$header = is_object( $header ) ? get_object_vars( $header ) : (array) $header;
		$ids[]  = $header['attachment_id'] ?? 0;

		// Each widget is one entry of its option, by number.
		foreach ( (array) ( $opt['widget_media_image'] ?? array() ) as $widget ) {
			$ids[] = is_array( $widget ) ? ( $widget['attachment_id'] ?? 0 ) : 0;
		}
		foreach ( (array) ( $opt['widget_media_gallery'] ?? array() ) as $widget ) {
			if ( is_array( $widget ) && isset( $widget['ids'] ) ) {
				$ids = array_merge( $ids, self::listed_ids( $widget['ids'] ) );
			}
		}
		foreach ( array( 'widget_block' => 'content', 'widget_text' => 'text', 'widget_custom_html' => 'content' ) as $name => $field ) {
			foreach ( (array) ( $opt[ $name ] ?? array() ) as $widget ) {
				if ( is_array( $widget ) && isset( $widget[ $field ] ) && is_string( $widget[ $field ] ) ) {
					self::note_uses( $site, $widget[ $field ] );
				}
			}
		}

		// An address rather than an ID, as some older settings kept, is
		// matched by its file above or not at all.
		foreach ( $ids as $value ) {
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				$site['ids'][ (int) $value ] = true;
			}
		}
	}

	/**
	 * Add what a piece of content names to what has been collected.
	 *
	 * @param array  $site     Collected so far, added to.
	 * @param string $text     Content, a description, a meta or option value.
	 * @param bool   $with_ids Whether numbers it carries in the ways
	 *                         ids_in_content() reads are photo IDs.
	 * @return void
	 */
	private static function note_uses( array &$site, $text, $with_ids = true ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return;
		}

		if ( $with_ids ) {
			foreach ( self::ids_in_content( $text ) as $id ) {
				$site['ids'][ $id ] = true;
			}
		}

		foreach ( self::files_in( $text ) as $key ) {
			$site['files'][ $key ] = true;
		}
	}

	/**
	 * What a row must mention to be read at all: the ways ids_in_content()
	 * and files_in() find a photo, as LIKE patterns.
	 *
	 * @return string[]
	 */
	private static function needles() {
		return self::like_patterns( array_merge(
			array( 'wp-image-', '"id":', '"mediaId":', '"ids":', 'ids=', 'image=', 'images=', 'image_id=', 'include=', 'uploads' ),
			self::UX_NEEDLES
		) );
	}

	/**
	 * Words to look for, as LIKE patterns that find them anywhere.
	 *
	 * @param string[] $words Words.
	 * @return string[]
	 */
	private static function like_patterns( array $words ) {
		global $wpdb;

		return array_map( function ( $word ) use ( $wpdb ) {
			return '%' . $wpdb->esc_like( $word ) . '%';
		}, $words );
	}

	/**
	 * The kinds of post whose content is read for photos placed in it.
	 *
	 * Every kind the site shows (public: posts, pages, products and whatever
	 * else a theme or plugin publishes), every kind with a screen of its own
	 * in the admin, and every kind the block editor can open. That takes in
	 * what is not public but is shown on every page all the same: the
	 * editor's reusable blocks, templates, navigation menus and global
	 * styles, and a theme's or page builder's own layouts - GeneratePress's
	 * and Kadence's Elements, the Divi and Elementor libraries. Named as well:
	 * those of the editor's, in case a version registers one with neither,
	 * the Customizer's Additional CSS (custom_css), and the layouts of Divi's
	 * Theme Builder, which it edits in an app of its own.
	 *
	 * Not read: orders, which show nothing on the site and can number
	 * hundreds of thousands; old revisions; photos' own descriptions; and
	 * kinds with neither a screen nor the editor - the posts other plugins
	 * keep their email logs or form entries as, thousands of rows that show
	 * nothing, and whose emails carry the shop's pictures by address.
	 *
	 * @return string[]
	 */
	private static function content_types() {
		$types = array( 'post', 'page', 'product' );
		if ( function_exists( 'get_post_types' ) ) {
			$types = array_merge(
				array_values( (array) get_post_types( array( 'public' => true ) ) ),
				array_values( (array) get_post_types( array( 'show_ui' => true ) ) ),
				array_values( (array) get_post_types( array( 'show_in_rest' => true ) ) )
			);
		}

		$types = array_merge(
			array_map( 'strval', $types ),
			array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'custom_css' ),
			array( 'et_template', 'et_header_layout', 'et_body_layout', 'et_footer_layout' )
		);

		$never = array( 'attachment', 'revision', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription' );
		if ( function_exists( 'wc_get_order_types' ) ) {
			$never = array_merge( $never, array_map( 'strval', (array) wc_get_order_types() ) );
		}
		$types = array_diff( $types, $never );

		/**
		 * Kinds of post whose content may show a photo of the Media Library,
		 * placed in it with the editor. A photo placed in one is never deleted.
		 *
		 * @param string[] $types Post types.
		 */
		$types = (array) apply_filters( 'tcgiant_sync_photo_content_types', array_values( array_unique( $types ) ) );

		return array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );
	}

	/**
	 * The attachment IDs a post's content names.
	 *
	 * The editor marks an inserted photo with its ID in three ways: the class
	 * wp-image-123 on the picture, "id":123 (or "mediaId") in the block's
	 * settings, and a gallery's list - ids="1,2,3" in the old shortcode,
	 * "ids":[1,2,3] in the first gallery block. Page builders' and galleries'
	 * shortcodes name theirs as image="123" (and bg_image="123" and the like),
	 * image_id="123", images="1,2,3" and include="1,2,3".
	 *
	 * Flatsome's UX Builder writes a page as shortcodes of its own and names
	 * a photo in them by its number alone: the Image element as
	 * [ux_image id="123"], a section's or a banner's background as bg="123",
	 * an image box's, a logo's or a team member's picture as img="123". Read
	 * only inside those, so that an id="123" in another plugin's shortcode,
	 * or in HTML, is not taken for a photo.
	 *
	 * @param string $content Post content.
	 * @return int[]
	 */
	private static function ids_in_content( $content ) {
		$ids = array();

		if ( preg_match_all( '/\[(?:ux_[a-z0-9_]+|section|row(?:_inner(?:_\d)?)?|col(?:_inner(?:_\d)?)?|featured_box|logo|team_member)\b([^\]]*)\]/i', $content, $tags ) ) {
			foreach ( $tags[1] as $attributes ) {
				if ( preg_match_all( '/(?:^|\s)(?:id|bg|img)\s*=\s*["\']?(\d+)/i', $attributes, $m ) ) {
					$ids = array_merge( $ids, $m[1] );
				}
			}
		}

		if ( preg_match_all( '/wp-image-(\d+)/', $content, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}
		if ( preg_match_all( '/"(?:id|mediaId)"\s*:\s*"?(\d+)/', $content, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}
		if ( preg_match_all( '/image(?:_id)?\s*=\s*["\']?(\d+)/', $content, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}
		if ( preg_match_all( '/"ids"\s*:\s*\[([^\]]*)\]|(?:\bids|images|include)\s*=\s*["\']?([\d,\s]+)/', $content, $m ) ) {
			foreach ( array_merge( $m[1], $m[2] ) as $list ) {
				$ids = array_merge( $ids, preg_split( '/\D+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY ) );
			}
		}

		return self::ids( $ids );
	}

	/**
	 * The files a piece of content points at by an address under /uploads/,
	 * as file_key() reads them.
	 *
	 * Wherever the address is - an <img>, a srcset, CSS, JSON with its slashes
	 * escaped, as page builders store it.
	 *
	 * @param string $text Content.
	 * @return string[]
	 */
	private static function files_in( $text ) {
		if ( false === stripos( $text, 'uploads' ) ) {
			return array();
		}

		$text = str_replace( '\\/', '/', $text );
		if ( ! preg_match_all( '#/uploads/([^\s"\'<>()\\\\?\#,;&]+)#i', $text, $m ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( array( __CLASS__, 'file_key' ), $m[1] ) ) ) );
	}

	/**
	 * A file as a page and as the photo's record both come to name it.
	 *
	 * The part under the uploads folder (and under a network site's own
	 * folder), without its extension - nor one added for a copy in another
	 * format - and without what WordPress adds to the name of a copy: a size
	 * (-300x300), a large upload's scaled-down or rotated copy, an edit
	 * (-e and the time). Letters in either case. So a page showing any size
	 * of a photo, or its original, and the photo's own record of its file,
	 * come to the same. Two photos whose names differ only there come to the
	 * same too, and both are kept.
	 *
	 * @param string $path An address, a path, or a file name as the photo's
	 *                     record keeps it.
	 * @return string
	 */
	private static function file_key( $path ) {
		$path = strtolower( rawurldecode( str_replace( '\\', '/', (string) $path ) ) );
		$path = (string) preg_replace( '/[?#].*$/', '', $path );

		$at = strrpos( $path, '/uploads/' );
		if ( false !== $at ) {
			$path = substr( $path, $at + 9 );
		}
		$path = (string) preg_replace( '#^sites/\d+/#', '', ltrim( $path, '/' ) );

		while ( preg_match( '/\.[a-z0-9]{2,5}$/', $path ) ) {
			$path = (string) preg_replace( '/\.[a-z0-9]{2,5}$/', '', $path );
		}

		return (string) preg_replace( '/(?:-\d+x\d+|-scaled|-rotated|-e\d{13})+$/', '', $path );
	}

	/**
	 * The file each of these photos has on record.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return string[] ID => the file as kept, for those that have one.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function attached_files( array $ids ) {
		global $wpdb;

		$files = array();

		foreach ( array_chunk( self::ids( $ids ), 500 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta}
				 WHERE post_id IN ( {$in} ) AND meta_key = '_wp_attached_file'"
			);
			self::read_ok();

			foreach ( $rows as $row ) {
				if ( '' !== (string) $row->meta_value ) {
					$files[ (int) $row->post_id ] = (string) $row->meta_value;
				}
			}
		}

		return $files;
	}

	/**
	 * Begin a run, or a step of the check: from here until close_uses(),
	 * what can only be read for the whole site at once is read once.
	 *
	 * @param string $job  JOB for a run, CHECK_JOB for the check and the
	 *                     shop's button. See note_reading().
	 * @param string $scan The check this is a step of, if it is one.
	 * @return bool Whether this began one. False when one was already under
	 *              way, which the caller then leaves to whoever began it.
	 */
	private static function open_uses( $job, $scan = '' ) {
		if ( null !== self::$uses ) {
			return false;
		}

		self::$uses = array(
			'site'        => null,    // From site_uses(), once read.
			'galleries'   => array(), // Photos a gallery lists, among...
			'gallery_for' => array(), // ...these, read...
			'gallery_at'  => 0.0,     // ...this many seconds into the request.
			'ahead'       => array(), // What the run says comes next.
			'job'         => (string) $job,
			'scan'        => (string) $scan,
		);

		return true;
	}

	/**
	 * Say what the watched job is working on, and remember it.
	 *
	 * @param string $what E.g. "photo #12", or '' for nothing in particular.
	 * @return void
	 */
	private static function working_on( $what ) {
		self::$context = (string) $what;
		TCGiant_Sync_Runtime::context( self::$context );
	}

	/**
	 * End what open_uses() began.
	 *
	 * @return void
	 */
	private static function close_uses() {
		self::$uses = null;
	}

	/**
	 * Whether a photo is a featured image or a category's picture right now.
	 *
	 * Asked of each photo just before it is deleted. Its round was judged
	 * minutes earlier, perhaps, when another plugin's deletions are slow, and
	 * WordPress's own deletion takes the photo off every featured image
	 * without a trace - so one set in that time, in the Media Library or by
	 * anything the mark does not cover, would be lost. Two lookups on an
	 * index.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function shown_now( $id ) {
		global $wpdb;

		$key = (string) (int) $id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1",
			$key
		) );
		self::read_ok();
		if ( $post ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$term = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->termmeta} WHERE meta_key = 'thumbnail_id' AND meta_value = %s LIMIT 1",
			$key
		) );
		self::read_ok();

		return (bool) $term;
	}

	/**
	 * Whether a gallery lists a photo right now, or near enough.
	 *
	 * The galleries are not read for each photo - every one on the site would
	 * be read for each - but the run's reading of them is read again first
	 * when it is more than GALLERY_RECHECK_SECONDS old: a photo the shop puts
	 * in a gallery while another plugin is slowly deleting the photo before
	 * it is then seen in time.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function in_gallery_now( $id ) {
		if ( ! self::covered( array( $id ) ) ) {
			$listed = self::in_galleries( array( $id ) );
			return isset( $listed[ $id ] );
		}

		if ( TCGiant_Sync_Runtime::elapsed() - self::$uses['gallery_at'] >= self::GALLERY_RECHECK_SECONDS ) {
			self::$uses['galleries']  = self::read_galleries( array_keys( self::$uses['gallery_for'] ) );
			self::$uses['gallery_at'] = TCGiant_Sync_Runtime::elapsed();
		}

		return isset( self::$uses['galleries'][ $id ] );
	}

	/**
	 * Which of these photos share their file with another Media Library entry.
	 *
	 * @param array[] $records ID => file record, from file_records().
	 * @return int[]
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function shared_files( array $records ) {
		global $wpdb;

		$by_file = array();
		foreach ( $records as $id => $record ) {
			if ( '' !== $record['file'] ) {
				$by_file[ $record['file'] ][] = (int) $id;
			}
		}

		if ( empty( $by_file ) ) {
			return array();
		}

		$shared = array();

		foreach ( array_chunk( array_keys( $by_file ), 500 ) as $chunk ) {
			$marks = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$files = (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attached_file' AND meta_value IN ( {$marks} )
				 GROUP BY meta_value
				 HAVING COUNT(*) > 1",
				$chunk
			) );
			self::read_ok();

			foreach ( $files as $file ) {
				$shared = array_merge( $shared, $by_file[ $file ] ?? array() );
			}
		}

		return self::ids( $shared );
	}

	/**
	 * The file each photo has on record, its sizes, and any edited originals.
	 *
	 * Read straight from the table, unfiltered: an offload plugin may filter
	 * what wp_get_attachment_metadata() returns, and this is about what is on
	 * this server.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array[] ID => file, meta, backup.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	public static function file_records( array $ids ) {
		global $wpdb;

		$ids     = self::ids( $ids );
		$records = array();

		foreach ( $ids as $id ) {
			$records[ $id ] = array( 'file' => '', 'meta' => array(), 'backup' => array() );
		}

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				 WHERE post_id IN ( {$in} )
				   AND meta_key IN ( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' )"
			);
			self::read_ok();

			foreach ( $rows as $row ) {
				$id = (int) $row->post_id;
				if ( '_wp_attached_file' === $row->meta_key ) {
					$records[ $id ]['file'] = (string) $row->meta_value;
				} elseif ( '_wp_attachment_metadata' === $row->meta_key ) {
					$meta                   = maybe_unserialize( $row->meta_value );
					$records[ $id ]['meta'] = is_array( $meta ) ? $meta : array();
				} else {
					$backup                   = maybe_unserialize( $row->meta_value );
					$records[ $id ]['backup'] = is_array( $backup ) ? $backup : array();
				}
			}
		}

		return $records;
	}

	/**
	 * Every file on this server that deleting a photo would remove.
	 *
	 * The same files wp_delete_attachment() removes: the photo, each resized
	 * copy, the original WordPress kept of a very large upload, and any copies
	 * kept from editing it in the Media Library.
	 *
	 * @param array $record From file_records().
	 * @return string[] Absolute paths.
	 */
	public static function local_files( array $record ) {
		$relative = (string) ( $record['file'] ?? '' );
		if ( '' === $relative ) {
			return array();
		}

		// As get_attached_file() reads it: a path stored whole is used whole.
		if ( 0 === strpos( $relative, '/' ) || preg_match( '|^.:\\\\|', $relative ) ) {
			$file = $relative;
		} else {
			$uploads = wp_get_upload_dir();
			$file    = rtrim( (string) ( $uploads['basedir'] ?? '' ), '/\\' ) . '/' . $relative;
		}

		$dir   = dirname( $file );
		$paths = array( $file );
		$meta  = is_array( $record['meta'] ?? null ) ? $record['meta'] : array();

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$paths[] = $dir . '/' . wp_basename( $size['file'] );
			}
		}

		if ( ! empty( $meta['original_image'] ) ) {
			$paths[] = $dir . '/' . wp_basename( $meta['original_image'] );
		}

		foreach ( (array) ( $record['backup'] ?? array() ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$paths[] = $dir . '/' . wp_basename( $size['file'] );
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * The files of a photo that are on this server now, with their sizes.
	 *
	 * @param array $record From file_records().
	 * @return int[] Absolute path => bytes.
	 */
	private static function files_here( array $record ) {
		$here = array();

		foreach ( self::local_files( $record ) as $path ) {
			if ( is_file( $path ) ) {
				$here[ $path ] = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		return $here;
	}

	/**
	 * Bytes a photo takes on this server, and whether any of it is here at all.
	 *
	 * Files that are not here - moved away by an offload plugin, or already
	 * gone - count for nothing: deleting them frees no space on this server.
	 *
	 * @param array $record From file_records().
	 * @return array{0:int,1:bool} Bytes, and whether any file was found.
	 */
	public static function bytes_here( array $record ) {
		$here = self::files_here( $record );
		return array( (int) array_sum( $here ), ! empty( $here ) );
	}

	/**
	 * Meta keys holding lists of attachment IDs.
	 *
	 * @return string[]
	 */
	private static function gallery_keys() {
		/**
		 * Meta keys that hold a list of attachment IDs: comma-separated, or a
		 * serialized array.
		 *
		 * A photo listed under any of them, on any post, is never deleted.
		 * WooCommerce's Additional Variation Images extension keeps its
		 * variation galleries under the second, as a list; Variation Images
		 * Gallery for WooCommerce under the third, as an array.
		 *
		 * @param string[] $keys
		 */
		$keys = (array) apply_filters(
			'tcgiant_sync_photo_list_keys',
			array( '_product_image_gallery', '_wc_additional_variation_images', 'woo_variation_gallery_images' )
		);

		return array_values( array_unique( array_filter( array_map( 'strval', $keys ) ) ) );
	}

	/**
	 * Stop if the read just made failed.
	 *
	 * wpdb does not throw when a query fails: it returns an empty result - no
	 * rows, null - and keeps what the database said in last_error. Read as
	 * "nothing uses it", that would delete a photo in use for good: a lost
	 * connection mid-query, a query over the server's time limit, a sort or a
	 * temporary table on a full disk. So every read a judgement rests on is
	 * followed by this, and a failed one ends the judgement - and with it the
	 * run or the step of the check - with nothing deleted on its strength.
	 *
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When it failed.
	 */
	private static function read_ok() {
		global $wpdb;

		if ( '' !== (string) $wpdb->last_error ) {
			throw new TCGiant_Sync_Photo_Read_Error( (string) $wpdb->last_error );
		}
	}

	/**
	 * A photo's own marks: where it came from, and how far a removal has got
	 * with it.
	 *
	 * Asked of the table, not of get_post_meta(). That reads every mark of
	 * the photo once a request and keeps what it read, and one the database
	 * failed is kept as "no marks at all" for the rest of the request - a
	 * photo whose deletion had begun would look as if it never had, or as
	 * if it were not the plugin's, and be handed back to the image batch
	 * half deleted. Here a failed read stops the run instead. See read_ok().
	 *
	 * Also how a mark just written is known to be there. See kept().
	 *
	 * @param int $id Attachment ID.
	 * @return string[] source, external, attempts, started and failed: each
	 *                  as kept, or '' when it is not.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function own_marks( $id ) {
		global $wpdb;

		$keys  = array(
			'source'   => '_tcgiant_source_url',
			'external' => '_tcgiant_external_url',
			'attempts' => self::ATTEMPTS_KEY,
			'started'  => self::STARTED_KEY,
			'failed'   => self::FAILED_KEY,
		);
		$names = array_flip( $keys );
		$marks = array_fill_keys( array_keys( $keys ), '' );
		$seen  = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE post_id = %d AND meta_key IN ( %s, %s, %s, %s, %s )
			 ORDER BY meta_id ASC",
			array_merge( array( (int) $id ), array_values( $keys ) )
		) );
		self::read_ok();

		foreach ( $rows as $row ) {
			$name = $names[ (string) $row->meta_key ] ?? '';
			// The first of each, as get_post_meta() reads one.
			if ( '' !== $name && ! isset( $seen[ $name ] ) ) {
				$seen[ $name ]  = true;
				$marks[ $name ] = (string) $row->meta_value;
			}
		}

		return $marks;
	}

	/**
	 * Positive integer IDs, each once, in order.
	 *
	 * @param array $ids Anything.
	 * @return int[]
	 */
	private static function ids( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), function ( $id ) {
			return $id > 0;
		} ) ) );
		sort( $ids );
		return $ids;
	}

	// ───────────────────────────────────────────────────────────────────────────
	// The background removal
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Delete what is due, a batch at a time. Called by WP-Cron.
	 *
	 * Nothing it meets may end the request. WP-Cron runs every event that is
	 * due in one request, so an error escaping from here would stop the eBay
	 * sync and the order import behind it too.
	 *
	 * @return void
	 */
	public function process_queue() {
		// A run PHP kills leaves a line saying how, and on which photo, and
		// lets go of the queue so the run after it need not wait for the hold
		// to lapse.
		TCGiant_Sync_Runtime::watch( self::JOB, array( __CLASS__, 'release_all_locks' ) );

		try {
			$this->run_queue();
		} catch ( TCGiant_Sync_Photo_Read_Late $e ) {
			// Every run after it would read for as long again, each time in a
			// WP-Cron request the eBay sync shares, so the removal waits.
			$pause = self::start_pause( 'slow', time(), (int) self::pause_state()['crash_at'] );
			TCGiant_Sync_Logger::warning( sprintf(
				'Photo cleanup: paused for %1$s. Reading where photos are used on the site - the content of its posts, pages and products, page builders\' data and its settings - took longer than a run may (%2$s), so this run stopped there: nothing was removed, and nothing was given up on. A run that took as long every time would hold up the rest of its background request, the eBay sync included. Removal tries again by itself after the pause, waiting longer each time this happens again.',
				self::duration( (int) $pause['seconds'] ),
				$e->getMessage()
			) );
			wp_clear_scheduled_hook( self::CLEANUP_HOOK );
			wp_schedule_single_event( (int) $pause['until'] + 5, self::CLEANUP_HOOK );
		} catch ( TCGiant_Sync_Photo_Read_Full $e ) {
			// Every run after it would read as far and stop there the same way
			// - or, with less to spare, run out of memory in the reading and
			// take the rest of its WP-Cron request with it - so the removal
			// waits, as it does after a run that died there.
			$pause = self::start_pause( 'memory', time(), (int) self::pause_state()['crash_at'] );
			TCGiant_Sync_Logger::warning( sprintf(
				'Photo cleanup: paused for %1$s. Reading where photos are used on the site - the content of its posts, pages and products, page builders\' data and its settings - needed more memory than this run had left (%2$s), so this run stopped there rather than run out: nothing was removed, and nothing was given up on. A run that ran out of memory would end the rest of its background request too, the eBay sync included. Removal tries again by itself after the pause, waiting longer each time this happens again; a higher PHP memory limit lets it through.',
				self::duration( (int) $pause['seconds'] ),
				$e->getMessage()
			) );
			wp_clear_scheduled_hook( self::CLEANUP_HOOK );
			wp_schedule_single_event( (int) $pause['until'] + 5, self::CLEANUP_HOOK );
		} catch ( TCGiant_Sync_Photo_Write_Error $e ) {
			TCGiant_Sync_Logger::error( sprintf(
				'Photo cleanup stopped: the database did not keep a mark the removal relies on (%1$s). This is often a full disk on the database server. Nothing more was removed in this run, and the photo keeps its place in the queue, so it is not given to another product. It carries on in about %2$d minutes.',
				$e->getMessage(),
				(int) ceil( self::FALLBACK_SECONDS / 60 )
			) );
			if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
				wp_schedule_single_event( time() + self::FALLBACK_SECONDS, self::CLEANUP_HOOK );
			}
		} catch ( TCGiant_Sync_Photo_Read_Error $e ) {
			TCGiant_Sync_Logger::error( sprintf(
				'Photo cleanup stopped: the database did not answer while checking whether photos are still in use (%s). Nothing more was removed in this run, and nothing was given up on - a photo is only removed once the database has said that nothing uses it. It carries on in about %d minutes.',
				$e->getMessage(),
				(int) ceil( self::FALLBACK_SECONDS / 60 )
			) );
			if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
				wp_schedule_single_event( time() + self::FALLBACK_SECONDS, self::CLEANUP_HOOK );
			}
		} catch ( \Throwable $e ) {
			TCGiant_Sync_Logger::error( sprintf(
				'Photo cleanup stopped on an error (%s, in %s line %d). Nothing more was removed in this run; it carries on in about %d minutes.',
				$e->getMessage(),
				basename( $e->getFile() ),
				$e->getLine(),
				(int) ceil( self::FALLBACK_SECONDS / 60 )
			) );
			if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
				wp_schedule_single_event( time() + self::FALLBACK_SECONDS, self::CLEANUP_HOOK );
			}
		} finally {
			TCGiant_Sync_Runtime::unwatch();
		}
	}

	/**
	 * The body of process_queue(), watched by it.
	 *
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer
	 *                                       a check. The run has stopped there.
	 */
	private function run_queue() {
		if ( ! self::take_lock( 'queue', self::LOCK_SECONDS ) ) {
			// Another run is working. Come back once its hold would have lapsed,
			// in case it was killed before it could say what comes next.
			wp_schedule_single_event( time() + self::LOCK_SECONDS + 10, self::CLEANUP_HOOK );
			return;
		}

		$opened = self::open_uses( self::JOB );

		try {
			TCGiant_Sync_Runtime::raise_time_limit( 300 );

			// A run for after this one, in case this one is killed. Replaced at
			// the end when it is not.
			wp_clear_scheduled_hook( self::CLEANUP_HOOK );
			wp_schedule_single_event( time() + self::FALLBACK_SECONDS, self::CLEANUP_HOOK );

			// Nothing at all during a pause, not even getting the shop's
			// approved photos ready: that reads where photos are used on the
			// site, which may be what stopped the run before.
			$paused = self::paused_until();
			if ( $paused > time() ) {
				wp_clear_scheduled_hook( self::CLEANUP_HOOK );
				wp_schedule_single_event( $paused + 5, self::CLEANUP_HOOK );
				return;
			}

			// Begun with this request's time already spent - after the eBay
			// sync's queue run, say, in a WP-Cron request the two share - the
			// reading of the site below would carry the request on for as long
			// again: it is held to a run's budget from when it begins, since
			// nothing judged on half of it can stand. Every other job stops
			// starting work once the budget, counted from the start of the
			// request, is spent, and so does this one: it comes back soon, in
			// another request. Not a pause - nothing went wrong - and only when
			// there is work that reads; with nothing due, the run below just
			// plans the next one.
			if ( '' !== TCGiant_Sync_Runtime::over_time_budget()
				&& ( 'approved' === ( self::scan()['status'] ?? '' ) || self::due() > 0 ) ) {
				wp_clear_scheduled_hook( self::CLEANUP_HOOK );
				wp_schedule_single_event( time() + self::NEXT_RUN_SECONDS, self::CLEANUP_HOOK );
				return;
			}

			// Photos the shop has asked to go are marked first, for a third of
			// this run at most, so the removal of what is already due is never
			// held up behind them.
			self::queue_approved( TCGiant_Sync_Runtime::time_budget() / 3 );

			$most = 0;
			if ( self::value_index_missing() ) {
				$most = self::NO_INDEX_PHOTOS;
				self::warn_no_index();
			}

			$done = self::delete_due( $most );

			self::record( $done );

			// The database failed a check: this run stops here. process_queue()
			// says so, and the run already set for after this one carries on.
			if ( null !== $done['read_error'] ) {
				throw $done['read_error'];
			}

			wp_clear_scheduled_hook( self::CLEANUP_HOOK );
			self::schedule_next();
		} finally {
			if ( $opened ) {
				self::close_uses();
			}
			self::release_lock( 'queue' );
		}
	}

	/**
	 * Whether wp_postmeta is missing the index the plugin adds to it on
	 * (meta_key, meta_value) - see TCGiant_Sync_DB::maybe_add_postmeta_index(),
	 * which records its attempt as done even when the database refused it.
	 *
	 * Every check of a photo but the galleries is a lookup of a value under
	 * a key - its featured images, its file - that the index answers directly
	 * and that, without it, reads every row under that key: every featured
	 * image on the site for each photo's last look, every photo's file for
	 * each round.
	 *
	 * @return bool False when it is there, or when the database will not say.
	 */
	private static function value_index_missing() {
		global $wpdb;

		$quiet = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name = %s",
			'tcgiant_key_value' // TCGiant_Sync_DB::POSTMETA_INDEX_NAME, named here so this class stands on its own.
		) );
		$error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $quiet );

		return '' === $error && empty( $rows );
	}

	/**
	 * Say, at most once a day, that the removal is going slowly for want of
	 * the index, and what would put it right.
	 *
	 * @return void
	 */
	private static function warn_no_index() {
		if ( get_transient( 'tcgiant_photo_cleanup_no_index' ) ) {
			return;
		}
		set_transient( 'tcgiant_photo_cleanup_no_index', 1, DAY_IN_SECONDS );

		global $wpdb;

		TCGiant_Sync_Logger::warning( sprintf(
			'Photo cleanup: the database table %1$s is missing the index the plugin adds to it (tcgiant_key_value), so every check made before a photo is removed reads far more of the table than it needs to. Each run removes at most %2$d photos until the index is there. A database administrator can add it: ALTER TABLE %1$s ADD INDEX tcgiant_key_value (meta_key(32), meta_value(191));',
			$wpdb->postmeta,
			self::NO_INDEX_PHOTOS
		) );
	}

	/**
	 * Whether the removal is paused, starting a pause if the run before this
	 * one died while deleting a photo, or while reading where photos are
	 * used on the site. See PAUSE_SECONDS.
	 *
	 * A death counts once, and only if it came after the last run that
	 * finished: the run before this one is what died, then. It is known two
	 * ways, either of which is enough. The record a run leaves when PHP ends
	 * it says what it was doing - the photo it was deleting, or the reading -
	 * because the run says so as it goes; and the note it made as it began
	 * either is still there when the run ended any other way, or is still
	 * hung in another request. See DELETING_OPTION and READING_OPTION.
	 *
	 * @return int When the pause ends, or 0.
	 */
	private static function paused_until() {
		$pause  = self::pause_state();
		$deaths = array();

		$crash = TCGiant_Sync_Runtime::last_crash( self::JOB );
		if ( null !== $crash ) {
			if ( preg_match( '/while working on photo #(\d+)/', $crash['message'], $photo ) ) {
				$deaths[] = array( 'at' => (int) $crash['at'], 'photo' => (int) $photo[1], 'why' => 'deleting' );
			} elseif ( false !== strpos( $crash['message'], 'while working on ' . self::READING_CONTEXT ) ) {
				$deaths[] = array( 'at' => (int) $crash['at'], 'photo' => 0, 'why' => 'reading' );
			}
		}

		$deleting = get_option( self::DELETING_OPTION, array() );
		if ( is_array( $deleting ) && ! empty( $deleting['at'] ) ) {
			$deaths[] = array( 'at' => (int) $deleting['at'], 'photo' => (int) ( $deleting['photo'] ?? 0 ), 'why' => 'deleting' );
		}

		$reading = get_option( self::READING_OPTION, array() );
		if ( is_array( $reading ) && ! empty( $reading['at'] ) ) {
			$deaths[] = array( 'at' => (int) $reading['at'], 'photo' => 0, 'why' => 'reading' );
		}

		$last_run = (int) self::stats()['last_run'];
		$death    = null;
		foreach ( $deaths as $one ) {
			if ( $one['at'] > (int) $pause['crash_at'] && $one['at'] > $last_run && ( null === $death || $one['at'] > $death['at'] ) ) {
				$death = $one;
			}
		}

		if ( null === $death ) {
			return (int) $pause['until'];
		}

		// Both records of one death are counted with it.
		$pause = self::start_pause( $death['why'], $death['at'], max( array_column( $deaths, 'at' ) ) );

		if ( 'reading' === $death['why'] ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Photo cleanup: paused for %s. The last run was stopped while it was reading where photos are used on the site - the content of its posts, pages and products, page builders\' data and its settings - before it had deleted anything. On a big shop that reading is the longest part of a run, and a run stopped there takes the rest of that background request with it, the eBay sync included. Removal carries on by itself after the pause, waiting longer each time this happens again.',
				self::duration( (int) $pause['seconds'] )
			) );
		} else {
			TCGiant_Sync_Logger::warning( sprintf(
				'Photo cleanup: paused for %s. The last run was stopped while WordPress and other plugins were deleting photo #%d - another plugin\'s work on deleting a photo (an offload plugin removing its remote copies, say) may be stalling, and a run that stalls takes the rest of that background request with it, the eBay sync included. Removal carries on by itself after the pause; if this keeps happening, look at that plugin.',
				self::duration( (int) $pause['seconds'] ),
				$death['photo']
			) );
		}

		return (int) $pause['until'];
	}

	/**
	 * The pause as kept: until, seconds, crash_at (the newest death it has
	 * counted) and why (deleting, reading, slow or memory).
	 *
	 * @return array
	 */
	private static function pause_state() {
		$pause = get_option( self::PAUSE_OPTION, array() );

		return array_merge(
			array( 'until' => 0, 'seconds' => 0, 'crash_at' => 0, 'why' => '' ),
			is_array( $pause ) ? $pause : array()
		);
	}

	/**
	 * Pause the removal, for twice as long as the last time if this comes
	 * straight after that pause.
	 *
	 * @param string $why      deleting or reading (a run died there), slow
	 *                         (its reading of the site took too long) or
	 *                         memory (it would have run out of memory there).
	 * @param int    $at       When that happened.
	 * @param int    $crash_at The newest death counted, with this one.
	 * @return array The pause, as pause_state() gives it.
	 */
	private static function start_pause( $why, $at, $crash_at ) {
		$pause = self::pause_state();

		$seconds = ( (int) $pause['until'] > 0 && (int) $at - (int) $pause['until'] < self::PAUSE_SECONDS )
			? min( self::PAUSE_MAX_SECONDS, max( self::PAUSE_SECONDS, 2 * (int) $pause['seconds'] ) )
			: self::PAUSE_SECONDS;

		$pause = array(
			'until'    => time() + $seconds,
			'seconds'  => $seconds,
			'crash_at' => max( (int) $pause['crash_at'], (int) $crash_at ),
			'why'      => (string) $why,
		);
		update_option( self::PAUSE_OPTION, $pause, false );

		return $pause;
	}

	/**
	 * A length of time, in words.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private static function duration( $seconds ) {
		$minutes = (int) round( $seconds / 60 );
		return $minutes < 120 ? sprintf( '%d minutes', $minutes ) : sprintf( '%d hours', (int) round( $minutes / 60 ) );
	}

	/**
	 * Delete the photos whose time has come, until this run's time is up.
	 *
	 * A read the database fails stops the run where it is: the photo whose
	 * check it was is not charged an attempt, and everything still waiting
	 * keeps its place. See read_ok().
	 *
	 * @param int $most The most photos to take on, or 0 for as many as fit.
	 * @return array Counts: removed, bytes, kept, failed, errors, left_files,
	 *               left_bytes; the same for the photos of the check the shop
	 *               approved (check_*, with check_scan naming it); stopped,
	 *               why the run stopped early, or ''; capped, $most when the
	 *               run stopped there; and read_error, the failed read that
	 *               stopped it, or null.
	 */
	private static function delete_due( $most = 0 ) {
		$done    = array(
			'removed'       => 0,
			'bytes'         => 0,
			'kept'          => 0,
			'failed'        => 0,
			'errors'        => 0,
			'left_files'    => 0,
			'left_bytes'    => 0,
			'check_scan'    => (string) self::check_stats()['scan'],
			'check_removed' => 0,
			'check_bytes'   => 0,
			'check_kept'    => 0,
			'check_failed'  => 0,
			'stopped'       => '',
			'capped'        => 0,
			'read_error'    => null,
		);
		$started = 0;
		$rounds  = 0;
		$taken   = 0;

		try {
			while ( true ) {
				// Between rounds too: a round of photos that are all kept deletes
				// nothing, and a long run of those must still stop in time.
				if ( $rounds++ > 0 ) {
					$late = TCGiant_Sync_Runtime::over_budget();
					if ( '' !== $late ) {
						$done['stopped'] = $late;
						break;
					}
				}

				if ( $most > 0 && $taken >= $most ) {
					$done['capped'] = $most;
					break;
				}

				$due = self::due_ids( $most > 0 ? min( self::BATCH, $most - $taken ) : self::BATCH );
				if ( empty( $due ) ) {
					break;
				}
				$taken += count( $due );

				// Galleries read once for what is coming, not once a round.
				if ( ! self::covered( $due ) ) {
					self::look_ahead( self::due_ids( self::RUN_SET ) );
				}

				$verdicts = self::verdicts( $due );
				$approved = self::approved_among( $due, $done['check_scan'] );

				foreach ( $due as $id ) {
					$why  = $verdicts[ $id ] ?? 'gone';
					$mine = isset( $approved[ $id ] );

					if ( '' !== $why ) {
						$outcome = self::keep( $id, $why );
						$done[ $outcome ]++;
						if ( $mine ) {
							$done[ 'check_' . $outcome ]++;
						}
						continue;
					}

					// Stop starting deletions well short of whatever this host
					// allows. Always at least one, so a run that began late in a
					// busy cron request still moves.
					if ( $started > 0 ) {
						$late = TCGiant_Sync_Runtime::over_budget();
						if ( '' !== $late ) {
							$done['stopped'] = $late;
							break 2;
						}
					}
					$started++;

					// Another plugin's work inside WordPress's deletion can throw.
					// One photo it throws on stays where it is, under its lease,
					// and the rest of the queue carries on. A read the database
					// fails is not the photo's doing, and ends the run instead.
					try {
						$result = self::delete_one( $id );
					} catch ( TCGiant_Sync_Photo_Read_Error $e ) {
						throw $e;
					} catch ( \Throwable $e ) {
						$done['errors']++;
						self::after_error( $id, $e );
						continue;
					} finally {
						self::working_on( '' );
					}

					$done[ $result['outcome'] ]++;
					$done['bytes']      += (int) ( $result['bytes'] ?? 0 );
					$done['left_files'] += (int) ( $result['left_files'] ?? 0 );
					$done['left_bytes'] += (int) ( $result['left_bytes'] ?? 0 );
					if ( $mine ) {
						$done[ 'check_' . $result['outcome'] ]++;
						$done['check_bytes'] += (int) ( $result['bytes'] ?? 0 );
					}
				}

				TCGiant_Sync_Runtime::release_memory();
			}
		} catch ( TCGiant_Sync_Photo_Read_Error $e ) {
			$done['read_error'] = $e;
		}

		return $done;
	}

	/**
	 * The photos whose time has come, oldest in the queue first.
	 *
	 * @param int $limit How many at most.
	 * @return int[]
	 */
	private static function due_ids( $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = %s
			   AND CAST( meta_value AS SIGNED ) <= %d
			 ORDER BY meta_id ASC
			 LIMIT %d",
			self::MARK_KEY,
			time(),
			(int) $limit
		) ) );
	}

	/**
	 * Which of these photos the shop's button queued, from the check given.
	 *
	 * @param int[]  $ids     Attachment IDs.
	 * @param string $scan_id The check.
	 * @return bool[] ID => true.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function approved_among( array $ids, $scan_id ) {
		global $wpdb;

		$ids = self::ids( $ids );
		if ( empty( $ids ) || '' === (string) $scan_id ) {
			return array();
		}

		$in = implode( ',', array_map( 'intval', $ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE post_id IN ( {$in} ) AND meta_key = %s AND meta_value = %s",
			self::APPROVED_KEY,
			(string) $scan_id
		) );
		self::read_ok();

		return array_fill_keys( self::ids( $found ), true );
	}

	/**
	 * Delete one photo that has passed every check.
	 *
	 * In WordPress's own order, which matters for what a killed request
	 * leaves: the delete_attachment action, where other plugins do their part
	 * (an offload plugin removes its remote copies here, and it is where a
	 * host's limit usually lands); every featured-image pointer to the photo;
	 * its meta; the delete_post action, which SEO and translation plugins
	 * hook and can be slow; its row; and only then its files. A request killed
	 * before the meta goes leaves the photo whole as far as the plugin can
	 * tell, still marked and under its lease, and it is tried again. One
	 * killed after leaves a row without its meta, or files without a row;
	 * nothing finds those again, which is why every give-up is logged.
	 *
	 * @param int $id Attachment ID.
	 * @return array outcome (removed, kept or failed); when removed, bytes
	 *               freed on this server and any files left behind.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer
	 *                                       one of the last looks, or did not
	 *                                       keep the marks of the deletion
	 *                                       beginning. Nothing of the photo
	 *                                       has been touched. Or when it did
	 *                                       not keep the mark of a photo given
	 *                                       up on; see fail().
	 */
	private static function delete_one( $id ) {
		global $wpdb;

		self::$charged = 0;

		// Its own marks, from the table. See own_marks().
		$marks    = self::own_marks( $id );
		$attempts = (int) $marks['attempts'];

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			if ( '' !== $marks['started'] ) {
				self::fail( $id, 'stopped', (string) $attempts );
			} else {
				self::let_go( $id, $attempts );
			}
			return array( 'outcome' => 'failed' );
		}

		// The last look, just before, at what can change in the seconds a
		// round takes: the record of where it came from, re-read as Image
		// Cleanup's restore does, whether something has just made it a
		// featured image or a category's picture, and the galleries, when the
		// run's reading of them is more than a few seconds old. This cannot
		// be undone.
		if ( '' === $marks['source'] || '' !== $marks['external'] ) {
			return array( 'outcome' => self::keep( $id, 'not_ours', $marks ) );
		}
		if ( self::shown_now( $id ) || self::in_gallery_now( $id ) ) {
			return array( 'outcome' => self::keep( $id, 'in_use', $marks ) );
		}

		// What is on this server, before anything is touched.
		$records = self::file_records( array( $id ) );
		$before  = self::files_here( $records[ $id ] ?? array() );

		// Charged before the work, with a lease, as the image batch does: a
		// deletion killed part way must not head every run after it.
		update_post_meta( $id, self::ATTEMPTS_KEY, $attempts + 1 );
		$refused = (string) $wpdb->last_error;
		update_post_meta( $id, self::MARK_KEY, (string) ( time() + self::LEASE_SECONDS ) );
		self::$charged = $id;

		// Each read back before anything depends on it: a try that went
		// uncounted could head every run after it. See kept().
		self::kept( $id, 'attempts', (string) ( $attempts + 1 ), $refused );

		self::working_on( sprintf( 'photo #%d', $id ) );
		TCGiant_Sync_Runtime::stage( 'WordPress and other plugins deleting it' );

		// From here part of it may go. See STARTED_KEY and DELETING_OPTION.
		// Begun without this mark, and stopped, a deletion would leave a photo
		// that looks untouched - kept as an ordinary one once something shows
		// it again, and reused - so WordPress is asked only once it is there.
		update_post_meta( $id, self::STARTED_KEY, (string) time() );
		self::kept( $id, 'started', '', (string) $wpdb->last_error );

		update_option( self::DELETING_OPTION, array( 'photo' => (int) $id, 'at' => time() ), false );
		try {
			wp_delete_attachment( $id, true );
		} finally {
			delete_option( self::DELETING_OPTION );
		}

		if ( self::exists( $id ) ) {
			// Refused - another plugin can veto a deletion - or stopped part
			// way, when the database gave up on one of its steps. Asking again
			// would get the same answer, and what is left may be incomplete.
			self::fail( $id, 'refused' );
			return array( 'outcome' => 'failed' );
		}

		// What really went. WordPress says nothing when it cannot delete a
		// file - the wrong owner, say - and a shop running out of disk must
		// not be told it has space it has not.
		clearstatcache();
		$freed      = 0;
		$left       = array();
		$left_bytes = 0;

		foreach ( $before as $path => $bytes ) {
			if ( file_exists( $path ) ) {
				$left[]      = wp_basename( $path );
				$left_bytes += $bytes;
			} else {
				$freed += $bytes;
			}
		}

		if ( $left ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Photo cleanup: photo #%d is gone from the Media Library, but %d of its file(s) could not be deleted from the uploads folder (%s%s), about %s. Check that the web server is allowed to delete files there; nothing else will remove these.',
				$id,
				count( $left ),
				implode( ', ', array_slice( $left, 0, 3 ) ),
				count( $left ) > 3 ? sprintf( ' and %d more', count( $left ) - 3 ) : '',
				size_format( $left_bytes, 1 )
			) );
		}

		return array(
			'outcome'    => 'removed',
			'bytes'      => $freed,
			'left_files' => count( $left ),
			'left_bytes' => $left_bytes,
		);
	}

	/**
	 * A deletion threw: say so, and leave the photo under its lease.
	 *
	 * Charged an attempt if it had not been yet, so that a photo it happens to
	 * every time is let go of after MAX_ATTEMPTS rather than tried for ever.
	 *
	 * @param int        $id Attachment ID.
	 * @param \Throwable $e  What was thrown.
	 * @return void
	 */
	private static function after_error( $id, $e ) {
		try {
			if ( self::$charged !== $id ) {
				update_post_meta( $id, self::ATTEMPTS_KEY, (int) self::own_marks( $id )['attempts'] + 1 );
			}
			if ( self::exists( $id ) ) {
				update_post_meta( $id, self::MARK_KEY, (string) ( time() + self::LEASE_SECONDS ) );
			}
		} catch ( \Throwable $again ) {
			unset( $again ); // The log line below still says what happened.
		}

		TCGiant_Sync_Logger::warning( sprintf(
			'Photo cleanup: deleting photo #%d ran into an error in WordPress or another plugin (%s, in %s line %d). It has been left where it is and will be tried again in about %d minutes; the rest carry on.',
			$id,
			$e->getMessage(),
			basename( $e->getFile() ),
			$e->getLine(),
			(int) ceil( self::LEASE_SECONDS / 60 )
		) );
	}

	/**
	 * Take a photo off the queue without deleting it.
	 *
	 * Back to an ordinary photo the image batch may reuse - but only if
	 * WordPress has not yet been asked to delete it. One it has may already
	 * have lost part of itself, and is given up on instead. See STARTED_KEY.
	 *
	 * @param int        $id    Attachment ID.
	 * @param string     $why   From verdicts().
	 * @param array|null $marks Its own marks, when just read. See own_marks().
	 * @return string kept, or failed.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not say
	 *                                       whether a deletion of it began, or
	 *                                       did not keep the mark of one given
	 *                                       up on. It keeps its place.
	 */
	private static function keep( $id, $why, $marks = null ) {
		if ( ! in_array( $why, array( 'gone', 'failed' ), true ) ) {
			$marks = is_array( $marks ) ? $marks : self::own_marks( $id );
			if ( '' !== $marks['started'] ) {
				self::fail( $id, 'after_attempt', $why );
				return 'failed';
			}
		}

		// Kept because something uses it is the ordinary case for a shared
		// photo, and is counted rather than logged one by one. A shared file
		// is worth a line of its own.
		if ( 'shared_file' === $why ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Photo cleanup: kept photo #%d - another Media Library entry points at the same file (%s), so deleting it would take that one\'s picture too.',
				$id,
				wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) )
			) );
		}

		self::unmark( $id );
		return 'kept';
	}

	/**
	 * Stop trying to remove a photo that every try ran into an error with
	 * before WordPress was asked to delete it. Nothing of it was touched, so
	 * it is put back as it was, for the image batch to reuse and for a later
	 * deletion of a product showing it to mark again.
	 *
	 * @param int $id       Attachment ID.
	 * @param int $attempts How many tries.
	 * @return void
	 */
	private static function let_go( $id, $attempts ) {
		self::unmark( $id );

		TCGiant_Sync_Logger::warning( sprintf(
			'Photo cleanup: photo #%d left in place - each of the last %d tries to remove it ran into an error before its deletion began (see above). Nothing of it was deleted, and it stays as it is.',
			$id,
			$attempts
		) );
	}

	/**
	 * Give up on a photo for good: leave it where it is, never to be reused
	 * by the image batch nor tried again here. See FAILED_KEY.
	 *
	 * The mark is read back before the queue's marks come off: until it is
	 * there, they are what keeps the image batch from handing on a photo part
	 * of which may be gone. A write the database refused - a deadlock, or a
	 * full disk, which refuses new rows while it still deletes - stops the
	 * run with them in place, and the next run gives up on it again.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $reason stopped, refused or after_attempt.
	 * @param string $detail How many attempts, or the verdict that kept it.
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the mark was not kept, or
	 *                                       the database could not say.
	 */
	private static function fail( $id, $reason, $detail = '' ) {
		global $wpdb;

		add_post_meta( $id, self::FAILED_KEY, (string) time(), true );
		self::kept( $id, 'failed', '', (string) $wpdb->last_error );

		self::unmark( $id );

		$why = array(
			'in_use'      => 'something shows it again',
			'attached'    => 'it is attached to a product again',
			'not_ours'    => 'the record of where it came from is gone',
			'shared_file' => 'another Media Library entry points at its file',
			'no_file'     => 'it has no file on record',
		);

		if ( 'stopped' === $reason ) {
			$line = sprintf(
				'Photo cleanup: photo #%d left in place - its deletion was begun and did not finish, %d times: the request was stopped, or another plugin ran into an error. Another plugin\'s work on deleting a photo (an offload plugin removing its remote copies, say) may be taking too long. It will not be tried again, and will not be given to another product in case part of it has already gone. Delete it from the Media Library by hand.',
				$id,
				(int) $detail
			);
		} elseif ( 'refused' === $reason ) {
			$line = sprintf(
				'Photo cleanup: WordPress did not delete photo #%d - another plugin may be protecting it, or the database stopped part way through. It has been left in place, will not be tried again, and will not be given to another product in case part of it has already gone.',
				$id
			);
		} else {
			$line = sprintf(
				'Photo cleanup: a deletion of photo #%d was started before and did not finish - the request was stopped, or ran into an error - and now %s. It has been left in place, but will not be given to another product in case part of it - an offload plugin\'s copies, say - has already gone. Check that it still shows properly.',
				$id,
				$why[ $detail ] ?? 'it may not be deleted'
			);
		}

		TCGiant_Sync_Logger::warning( $line );
	}

	/**
	 * Stop the run unless a mark just written to a photo is there, read back
	 * from the table. See own_marks().
	 *
	 * wpdb does not throw when a write fails, nor try one again: a deadlock,
	 * a lock waited on too long, a full disk - which refuses new rows while it
	 * still updates and deletes the rest - each leave the write undone with
	 * only last_error to say so. Taken as done, a photo whose deletion began
	 * could be left with nothing to keep it from the image batch.
	 *
	 * @param int    $id      Attachment ID.
	 * @param string $mark    attempts, started or failed.
	 * @param string $value   What it must be, or '' for any value.
	 * @param string $refused What the database said of the write, if anything.
	 * @return void
	 * @throws TCGiant_Sync_Photo_Write_Error When it is not there.
	 * @throws TCGiant_Sync_Photo_Read_Error  When the database could not say.
	 */
	private static function kept( $id, $mark, $value, $refused ) {
		$now = self::own_marks( $id )[ $mark ];
		if ( '' !== $now && ( '' === $value || $value === $now ) ) {
			return;
		}

		$what = array(
			'attempts' => 'the count of tries to delete photo #%d',
			'started'  => 'the mark that a deletion of photo #%d had begun',
			'failed'   => 'the mark that photo #%d is never to be given to another product',
		);

		throw new TCGiant_Sync_Photo_Write_Error( sprintf(
			'%1$s was not kept: %2$s',
			sprintf( $what[ $mark ], $id ),
			'' !== $refused ? $refused : 'the database gave no reason'
		) );
	}

	/**
	 * Remove a photo's place in the queue.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	private static function unmark( $id ) {
		delete_post_meta( $id, self::MARK_KEY );
		delete_post_meta( $id, self::ATTEMPTS_KEY );
		delete_post_meta( $id, self::STARTED_KEY );
		delete_post_meta( $id, self::APPROVED_KEY );
	}

	/**
	 * Whether an attachment row is still there, asked of the table.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	private static function exists( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $id ) );
	}

	/**
	 * Add a run's work to the totals, and say what it did.
	 *
	 * @param array $done From delete_due().
	 * @return void
	 */
	private static function record( array $done ) {
		$stats = self::stats();
		foreach ( array( 'removed', 'bytes', 'kept', 'failed', 'errors', 'left_files', 'left_bytes' ) as $key ) {
			$stats[ $key ] += (int) $done[ $key ];
		}
		// Every run that finishes, whether or not it did anything: a death
		// after this is the next run's, which is what paused_until() asks.
		$stats['last_run'] = time();
		update_option( self::STATS_OPTION, $stats, false );

		// What became of the check's own photos, for the screen - only while
		// that check is still the one the screen shows.
		if ( $done['check_removed'] + $done['check_kept'] + $done['check_failed'] > 0 ) {
			$check = self::check_stats();
			if ( '' !== $check['scan'] && $check['scan'] === $done['check_scan'] ) {
				foreach ( array( 'removed', 'bytes', 'kept', 'failed' ) as $key ) {
					$check[ $key ] += (int) $done[ 'check_' . $key ];
				}
				update_option( self::CHECK_STATS_OPTION, $check, false );
			}
		}

		if ( $done['removed'] + $done['kept'] + $done['failed'] + $done['errors'] < 1 ) {
			return;
		}

		$left = self::waiting();

		TCGiant_Sync_Logger::log( sprintf(
			'Photo cleanup: removed %d photo(s) of deleted products, about %s freed on this server.%s%s%s%s %s',
			$done['removed'],
			size_format( $done['bytes'], 1 ),
			$done['kept'] > 0 ? sprintf( ' Kept %d that something on the site still uses, or that is no longer the plugin\'s to remove.', $done['kept'] ) : '',
			$done['failed'] > 0 ? sprintf( ' %d could not be removed and were left in place - see above.', $done['failed'] ) : '',
			$done['errors'] > 0 ? sprintf( ' %d ran into an error and will be tried again later - see above.', $done['errors'] ) : '',
			$done['left_files'] > 0 ? sprintf( ' %d file(s) could not be deleted from the uploads folder - see above.', $done['left_files'] ) : '',
			$left > 0 ? sprintf( '%d still waiting.', $left ) : 'Nothing left waiting.'
		), 'success' );

		if ( '' !== $done['stopped'] ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Photo cleanup: stopped to stay within this host\'s limits (%s). The next batch carries on in %d seconds.',
				$done['stopped'],
				self::NEXT_RUN_SECONDS
			) );
		}

		if ( $done['capped'] > 0 ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Photo cleanup: stopped after the %d photos a run takes on while the database is missing its index (see the warning about tcgiant_key_value). The next batch carries on in %d seconds.',
				$done['capped'],
				self::NEXT_RUN_SECONDS
			) );
		}
	}

	/**
	 * What became of the photos of the check the shop last approved.
	 *
	 * @return array scan (the check's ID, or ''), removed, bytes, kept, failed.
	 */
	public static function check_stats() {
		$check = get_option( self::CHECK_STATS_OPTION, array() );

		return array_merge(
			array( 'scan' => '', 'removed' => 0, 'bytes' => 0, 'kept' => 0, 'failed' => 0 ),
			is_array( $check ) ? $check : array()
		);
	}

	/**
	 * Schedule the next run for whenever there is next something to do.
	 *
	 * @return void
	 */
	private static function schedule_next() {
		global $wpdb;

		$now = time();

		$scan = self::scan();
		if ( 'approved' === ( $scan['status'] ?? '' ) || self::due() > 0 ) {
			wp_schedule_single_event( $now + self::NEXT_RUN_SECONDS, self::CLEANUP_HOOK );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$next = $wpdb->get_var( $wpdb->prepare(
			"SELECT MIN( CAST( meta_value AS SIGNED ) ) FROM {$wpdb->postmeta} WHERE meta_key = %s",
			self::MARK_KEY
		) );

		if ( null !== $next && '' !== $next ) {
			wp_schedule_single_event( max( $now + self::NEXT_RUN_SECONDS, (int) $next + 5 ), self::CLEANUP_HOOK );
		}
	}

	/**
	 * Put the removal back on the schedule when it has work and none is planned.
	 *
	 * The schedule can be lost - a cleared cron table, a host that wipes it,
	 * the plugin deactivated and reactivated - and nothing else would bring it
	 * back. Checked on admin pages, at most every five minutes.
	 *
	 * @return void
	 */
	public static function maybe_rearm_cleanup() {
		if ( wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			return;
		}

		if ( get_transient( 'tcgiant_photo_cleanup_rearm_check' ) ) {
			return;
		}
		set_transient( 'tcgiant_photo_cleanup_rearm_check', 1, 5 * MINUTE_IN_SECONDS );

		$scan    = self::scan();
		$waiting = self::waiting();

		if ( $waiting < 1 && 'approved' !== ( $scan['status'] ?? '' ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::NEXT_RUN_SECONDS, self::CLEANUP_HOOK );
		if ( class_exists( 'TCGiant_Sync_Cron' ) ) {
			TCGiant_Sync_Cron::request_dispatch();
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Photo cleanup re-armed: %d photo(s) waiting and nothing was scheduled.',
			$waiting
		) );
	}

	/**
	 * Photos waiting in the queue, due or not.
	 *
	 * @return int
	 */
	public static function waiting() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
			self::MARK_KEY
		) );
	}

	/**
	 * Photos whose time has come.
	 *
	 * @return int
	 */
	public static function due() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST( meta_value AS SIGNED ) <= %d",
			self::MARK_KEY,
			time()
		) );
	}

	/**
	 * The removal's running totals.
	 *
	 * @return array removed, bytes, kept, failed, errors, left_files,
	 *               left_bytes, last_run.
	 */
	public static function stats() {
		$stats = get_option( self::STATS_OPTION, array() );
		$stats = is_array( $stats ) ? $stats : array();

		return array_merge(
			array(
				'removed'    => 0,
				'bytes'      => 0,
				'kept'       => 0,
				'failed'     => 0,
				'errors'     => 0,
				'left_files' => 0,
				'left_bytes' => 0,
				'last_run'   => 0,
			),
			$stats
		);
	}

	// ───────────────────────────────────────────────────────────────────────────
	// The photos left behind before this existed
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * The check and what the shop asked for after it.
	 *
	 * @return array
	 */
	public static function scan() {
		$scan = get_option( self::SCAN_OPTION, array() );
		return is_array( $scan ) ? $scan : array();
	}

	/**
	 * The check as the table holds it now, past any copy this request keeps.
	 *
	 * @return array
	 */
	private static function stored_scan() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw  = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			self::SCAN_OPTION
		) );
		$scan = null === $raw ? array() : maybe_unserialize( $raw );

		return is_array( $scan ) ? $scan : array();
	}

	/**
	 * Keep the check's state.
	 *
	 * @param array $scan State.
	 * @return void
	 */
	private static function save_scan( array $scan ) {
		update_option( self::SCAN_OPTION, $scan, false );
	}

	/**
	 * Begin a new check, forgetting the last one.
	 *
	 * @return array|WP_Error The new state.
	 */
	public static function start_scan() {
		global $wpdb;

		$old = self::scan();
		if ( 'approved' === ( $old['status'] ?? '' ) ) {
			return new WP_Error( 'busy', __( 'The photos from the last check are still being queued for removal. Check again once that has finished.', 'tcgiant-sync' ) );
		}

		self::forget_found( (int) ( $old['chunks'] ?? 0 ) + 1 );

		$scan = array(
			'id'          => wp_generate_uuid4(),
			'status'      => 'scanning',

			// Where the check has reached, and where it stops: photos added
			// while it runs are new, and are the removal's business, not its.
			'cursor'      => 0,
			'max_id'      => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'estimate'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_tcgiant_source_url'" ),
			'examined'    => 0,
			'found'       => 0,
			'bytes'       => 0,
			'not_here'    => 0,

			// Not offered, and not in the count: photos already waiting to be
			// removed - by an earlier press of the button, or with a product
			// deleted since - and photos a removal gave up on, for the shop.
			'waiting'     => 0,
			'failed'      => 0,
			'chunks'      => 0,
			'started_at'  => time(),
			'finished_at' => 0,
		);

		self::save_scan( $scan );

		return $scan;
	}

	/**
	 * Carry the check on until the deadline.
	 *
	 * Downloaded photos are read in ID order, a page at a time, and each page
	 * is judged by verdicts() - the same judgement the removal makes just
	 * before it deletes anything. What it finds is kept, so that the removal
	 * the shop asks for acts on exactly what it was shown.
	 *
	 * A read the database fails ends the step with nothing of its page
	 * counted, and the check carries on from the same place next time.
	 *
	 * @param float $deadline Seconds into the request at which to stop.
	 * @return array The state.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	public static function scan_step( $deadline ) {
		$scan = self::scan();
		if ( 'scanning' !== ( $scan['status'] ?? '' ) ) {
			return $scan;
		}

		self::drop_unsaved_finds( $scan );

		$opened = self::open_uses( self::CHECK_JOB, (string) ( $scan['id'] ?? '' ) );
		try {
			return self::scan_pages( $scan, $deadline );
		} finally {
			if ( $opened ) {
				self::close_uses();
			}
		}
	}

	/**
	 * The pages of one step of the check. See scan_step().
	 *
	 * @param array $scan     State.
	 * @param float $deadline Seconds into the request at which to stop.
	 * @return array The state.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function scan_pages( array $scan, $deadline ) {
		global $wpdb;

		do {
			self::working_on( sprintf( 'the check, after photo #%d', (int) $scan['cursor'] ) );

			$from = (int) $scan['cursor'];

			// Driven by the posts table's own key, so the whole check is one
			// pass along it however many pages it takes.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT a.ID FROM {$wpdb->posts} a
				 WHERE a.ID > %d AND a.ID <= %d
				   AND a.post_type = 'attachment'
				   AND EXISTS (
					   SELECT 1 FROM {$wpdb->postmeta} src
					   WHERE src.post_id = a.ID AND src.meta_key = '_tcgiant_source_url'
				   )
				 ORDER BY a.ID ASC
				 LIMIT %d",
				$from,
				(int) $scan['max_id'],
				self::SCAN_PAGE
			) ) );
			self::read_ok();

			if ( empty( $ids ) ) {
				$scan['status']      = 'found';
				$scan['finished_at'] = time();
				self::save_scan( $scan );
				self::log_found( $scan );
				break;
			}

			$verdicts = self::verdicts( $ids );
			$waiting  = self::marked_among( self::still_open( $verdicts ) );
			$records  = self::file_records( array_diff( self::still_open( $verdicts ), $waiting ) );
			$waiting  = array_flip( $waiting );
			$finds    = array();
			$last     = $from;
			$looked   = 0;

			foreach ( $ids as $n => $id ) {
				// Within the page too, so that slow storage cannot hold one
				// step for minutes. Always the first few, so each step moves.
				if ( $n > 0 && 0 === $n % self::SCAN_TIME_EVERY && TCGiant_Sync_Runtime::elapsed() >= $deadline ) {
					break;
				}
				$last = $id;
				$looked++;

				$why = $verdicts[ $id ] ?? 'gone';
				if ( 'failed' === $why ) {
					$scan['failed']++;
					continue;
				}
				if ( '' !== $why ) {
					continue;
				}
				if ( isset( $waiting[ $id ] ) ) {
					$scan['waiting']++;
					continue;
				}

				list( $bytes, $here ) = self::bytes_here( $records[ $id ] ?? array() );
				$scan['found']++;
				$scan['bytes'] += $bytes;
				if ( ! $here ) {
					$scan['not_here']++;
				}
				$finds[] = $id;
			}

			// Only if no other request has moved this check on meanwhile - a
			// step the browser gave up waiting for, still running, and the one
			// it asked for instead. Both would add the same page to the list.
			$stored = self::stored_scan();
			if ( ( $stored['id'] ?? '' ) !== $scan['id'] || 'scanning' !== ( $stored['status'] ?? '' ) || (int) ( $stored['cursor'] ?? -1 ) !== $from ) {
				if ( function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( self::SCAN_OPTION, 'options' );
				}
				return $stored;
			}

			// The finds first, then the state that says how far they go: a
			// request killed between the two leaves finds beyond the cursor,
			// and drop_unsaved_finds() takes them back off.
			$scan['chunks']    = self::keep_finds( $finds, (int) $scan['chunks'] );
			$scan['cursor']    = $last;
			$scan['examined'] += $looked;
			self::save_scan( $scan );

			TCGiant_Sync_Runtime::release_memory();
		} while ( TCGiant_Sync_Runtime::elapsed() < $deadline );

		return $scan;
	}

	/**
	 * Which of these photos are already waiting to be removed.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return int[]
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function marked_among( array $ids ) {
		global $wpdb;

		$ids = self::ids( $ids );
		if ( empty( $ids ) ) {
			return array();
		}

		$marked = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$marked = array_merge( $marked, (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE post_id IN ( {$in} ) AND meta_key = %s",
				self::MARK_KEY
			) ) );
			self::read_ok();
		}

		return self::ids( $marked );
	}

	/**
	 * The line a finished check writes.
	 *
	 * @param array $scan State.
	 * @return void
	 */
	private static function log_found( array $scan ) {
		TCGiant_Sync_Logger::log( sprintf(
			'Photo cleanup: checked %d downloaded photo(s). %d are left over from deleted products, about %s on this server%s.%s%s',
			$scan['examined'],
			$scan['found'],
			size_format( $scan['bytes'], 1 ),
			$scan['not_here'] > 0 ? sprintf( ' (%d of them have no file here any more)', $scan['not_here'] ) : '',
			$scan['waiting'] > 0 ? sprintf( ' %d more are already waiting to be removed.', $scan['waiting'] ) : '',
			$scan['failed'] > 0 ? sprintf( ' %d could not be removed before and have been left for you to delete by hand - the log has a line for each.', $scan['failed'] ) : ''
		) );
	}

	/**
	 * Add photo IDs to the check's findings.
	 *
	 * @param int[] $ids    In ascending order, all above every ID already kept.
	 * @param int   $chunks How many options hold findings now.
	 * @return int How many hold them after.
	 */
	private static function keep_finds( array $ids, $chunks ) {
		if ( empty( $ids ) ) {
			return $chunks;
		}

		$index = max( 0, $chunks - 1 );
		$list  = $chunks > 0 ? self::finds( $index ) : array();

		foreach ( $ids as $id ) {
			if ( count( $list ) >= self::CHUNK ) {
				update_option( self::SCAN_IDS_PREFIX . $index, implode( ',', $list ), false );
				$index++;
				$list = array();
			}
			$list[] = (int) $id;
		}

		update_option( self::SCAN_IDS_PREFIX . $index, implode( ',', $list ), false );

		return $index + 1;
	}

	/**
	 * One option's worth of the check's findings.
	 *
	 * @param int $index Which.
	 * @return int[]
	 */
	private static function finds( $index ) {
		$csv = get_option( self::SCAN_IDS_PREFIX . (int) $index, '' );
		return is_string( $csv ) && '' !== $csv ? array_map( 'intval', explode( ',', $csv ) ) : array();
	}

	/**
	 * Take back findings a killed request wrote but never counted.
	 *
	 * @param array $scan State.
	 * @return void
	 */
	private static function drop_unsaved_finds( array $scan ) {
		$chunks = (int) $scan['chunks'];
		$cursor = (int) $scan['cursor'];

		// A killed page can have started at most one option past the count.
		delete_option( self::SCAN_IDS_PREFIX . $chunks );
		delete_option( self::SCAN_IDS_PREFIX . ( $chunks + 1 ) );

		if ( $chunks < 1 ) {
			return;
		}

		$last = self::finds( $chunks - 1 );
		$kept = array_values( array_filter( $last, function ( $id ) use ( $cursor ) {
			return $id <= $cursor;
		} ) );

		if ( count( $kept ) !== count( $last ) ) {
			update_option( self::SCAN_IDS_PREFIX . ( $chunks - 1 ), implode( ',', $kept ), false );
		}
	}

	/**
	 * Delete the options holding a check's findings.
	 *
	 * @param int $chunks How many there are.
	 * @return void
	 */
	private static function forget_found( $chunks ) {
		for ( $i = 0; $i <= $chunks; $i++ ) {
			delete_option( self::SCAN_IDS_PREFIX . $i );
		}
	}

	/**
	 * The shop has seen the check and asked for its photos to be removed.
	 *
	 * @param string $scan_id The check the screen showed, so that a page left
	 *                        open from an older check cannot approve a newer
	 *                        one the shop never saw.
	 * @return array|WP_Error The state.
	 */
	public static function approve( $scan_id ) {
		$scan = self::scan();

		if ( 'found' !== ( $scan['status'] ?? '' ) || (string) $scan_id !== (string) ( $scan['id'] ?? '' ) ) {
			return new WP_Error( 'stale', __( 'This check is no longer the latest. Reload the page and check again.', 'tcgiant-sync' ) );
		}

		if ( time() - (int) $scan['finished_at'] > self::SCAN_VALID_SECONDS ) {
			return new WP_Error( 'old', __( 'This check is more than a week old. Check again before removing anything.', 'tcgiant-sync' ) );
		}

		if ( (int) $scan['found'] < 1 ) {
			return new WP_Error( 'nothing', __( 'The check found nothing to remove.', 'tcgiant-sync' ) );
		}

		$scan['status']       = 'approved';
		$scan['approved_at']  = time();
		$scan['approved_by']  = get_current_user_id();
		$scan['queue_chunk']  = 0;
		$scan['queue_offset'] = 0;
		$scan['queued']       = 0;
		$scan['already']      = 0;
		$scan['kept']         = 0;
		$scan['gone']         = 0;

		self::save_scan( $scan );

		// What becomes of this check's photos, counted apart from those of
		// products deleted since. See APPROVED_KEY.
		update_option( self::CHECK_STATS_OPTION, array( 'scan' => (string) $scan['id'], 'removed' => 0, 'bytes' => 0, 'kept' => 0, 'failed' => 0 ), false );

		$user = wp_get_current_user();
		TCGiant_Sync_Logger::log( sprintf(
			'Photo cleanup: %s asked for the %d photo(s) of deleted products found on %s to be removed. They go in the background, a batch at a time, starting in about %d minutes. Each is checked again just before it goes.',
			( $user && ! empty( $user->user_login ) ) ? $user->user_login : 'the shop',
			(int) $scan['found'],
			wp_date( 'j M Y, H:i', (int) $scan['finished_at'] ),
			(int) ceil( self::QUARANTINE_SECONDS / 60 )
		) );

		return $scan;
	}

	/**
	 * Mark the photos the shop approved, until the deadline.
	 *
	 * Each is judged again first: something may have started using it since
	 * the check, and then it is kept.
	 *
	 * @param float $deadline Seconds into the request at which to stop.
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 *                                       What is marked stays marked, and
	 *                                       the rest carry on from there.
	 */
	public static function queue_approved( $deadline ) {
		$scan = self::scan();
		if ( 'approved' !== ( $scan['status'] ?? '' ) ) {
			return;
		}

		$opened = self::open_uses( self::CHECK_JOB );
		try {
			self::queue_slices( $scan, $deadline );
		} finally {
			if ( $opened ) {
				self::close_uses();
			}
		}
	}

	/**
	 * The work of queue_approved().
	 *
	 * @param array $scan     State.
	 * @param float $deadline Seconds into the request at which to stop.
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function queue_slices( array $scan, $deadline ) {
		$chunk  = (int) $scan['queue_chunk'];
		$offset = (int) $scan['queue_offset'];
		$slices = 0;

		while ( $chunk < (int) $scan['chunks'] ) {
			if ( $slices > 0 && TCGiant_Sync_Runtime::elapsed() >= $deadline ) {
				break;
			}

			$list  = self::finds( $chunk );
			$slice = array_slice( $list, $offset, self::QUEUE_SLICE );

			if ( empty( $slice ) ) {
				$chunk++;
				$offset = 0;
				$scan['queue_chunk']  = $chunk;
				$scan['queue_offset'] = 0;
				self::save_scan( $scan );
				continue;
			}

			// The galleries read once for the rest of this option's worth.
			if ( ! self::covered( $slice ) ) {
				self::look_ahead( array_slice( $list, $offset ) );
			}

			$verdicts = self::verdicts( $slice );
			$when     = (string) ( time() + self::QUARANTINE_SECONDS );

			foreach ( $slice as $id ) {
				$why = $verdicts[ $id ] ?? 'gone';
				if ( '' === $why ) {
					// Counted as queued only when this put it in the queue. One
					// already there - marked by a run of this killed before it
					// could save how far it got, or with a product deleted
					// since the check - is waiting all the same, and counted
					// apart so that nothing is counted twice. Both are this
					// check's, for the screen.
					if ( add_post_meta( $id, self::MARK_KEY, $when, true ) ) {
						$scan['queued']++;
					} else {
						$scan['already']++;
					}
					add_post_meta( $id, self::APPROVED_KEY, (string) $scan['id'], true );
				} elseif ( 'gone' === $why ) {
					$scan['gone']++;
				} else {
					$scan['kept']++;
				}
			}

			$offset              += count( $slice );
			$scan['queue_offset'] = $offset;
			self::save_scan( $scan );
			$slices++;
		}

		if ( $chunk < (int) $scan['chunks'] ) {
			return;
		}

		$scan['status']    = 'queued';
		$scan['queued_at'] = time();
		self::save_scan( $scan );
		self::forget_found( (int) $scan['chunks'] );

		TCGiant_Sync_Logger::log( sprintf(
			'Photo cleanup: %d photo(s) from the check are waiting to be removed.%s%s',
			$scan['queued'] + $scan['already'],
			$scan['kept'] > 0 ? sprintf( ' %d kept: something started using them after the check.', $scan['kept'] ) : '',
			$scan['gone'] > 0 ? sprintf( ' %d had already gone.', $scan['gone'] ) : ''
		) );
	}

	// ───────────────────────────────────────────────────────────────────────────
	// The Image Cleanup screen
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * How long the check's hold lasts: longer than any request can.
	 *
	 * The screen asks again when a step does not answer, and a step the
	 * browser stopped waiting for - a proxy that gives up at a hundred
	 * seconds, say - may still be running. With a hold shorter than that
	 * request, the second step would walk the same pages beside it. The hold
	 * is let go the moment a step ends, so its length is only ever waited out
	 * after a request is killed outright.
	 *
	 * @return int Seconds.
	 */
	private static function scan_hold_seconds() {
		return min( self::SCAN_HOLD_CEILING, max( TCGiant_Sync_Runtime::effective_time_limit(), self::SCAN_HOLD_FLOOR ) + 30 );
	}

	/**
	 * Whether the step of the check before this one died while reading where
	 * photos are used: its note of that reading is still there.
	 *
	 * Asked under the check's hold, so no other step can be at it. The screen
	 * asks again for a step that does not answer, and on a site too big to
	 * read inside the host's limit every step would die the same way, each
	 * time running the same heavy read. The note is taken away as this is
	 * answered, so the button tries again when it is pressed.
	 *
	 * @return bool
	 */
	private static function step_died_reading() {
		$note = get_option( self::CHECK_READING_OPTION, array() );
		if ( ! is_array( $note ) || empty( $note['at'] ) ) {
			return false;
		}

		delete_option( self::CHECK_READING_OPTION );

		// Not one left by the button, or by a check since replaced.
		$scan = self::scan();
		return 'scanning' === ( $scan['status'] ?? '' ) && '' !== (string) ( $note['scan'] ?? '' ) && (string) $note['scan'] === (string) ( $scan['id'] ?? '' );
	}

	/**
	 * AJAX: start or carry on the check.
	 *
	 * @return void
	 */
	public static function ajax_scan() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		TCGiant_Sync_Runtime::raise_time_limit( 120 );

		// Two tabs, or two people, would otherwise both walk the same pages,
		// or one would start the check again under the other. Nothing inside
		// the hold sends the answer: that ends the request, and a hold still
		// taken would keep the next step waiting for it to lapse.
		if ( ! self::take_lock( 'scan', self::scan_hold_seconds() ) ) {
			wp_send_json_success( self::status() + array( 'busy' => true ) );
		}

		$refused = null;

		TCGiant_Sync_Runtime::watch( self::CHECK_JOB, array( __CLASS__, 'release_all_locks' ) );

		try {
			if ( self::step_died_reading() ) {
				// Asked for again by the screen, it would only die again the
				// same way. Said once, and the button tries again when pressed.
				$refused = new WP_Error( 'died', __( 'The last step of the check was stopped while it was reading where photos are used on this site - the content of its posts, pages and products, page builders\' data and its settings - before it could count anything. Nothing was counted, and nothing is removed. Press the button to try again; if it is stopped there every time, this host does not allow a request long enough to check a site this big.', 'tcgiant-sync' ) );
			} else {
				$started = empty( $_POST['restart'] ) ? null : self::start_scan(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

				if ( is_wp_error( $started ) ) {
					$refused = $started;
				} else {
					self::scan_step( min( TCGiant_Sync_Runtime::time_budget(), TCGiant_Sync_Runtime::elapsed() + self::AJAX_SECONDS ) );
				}
			}
		} catch ( TCGiant_Sync_Photo_Read_Late $e ) {
			$refused = new WP_Error( 'late', sprintf(
				/* translators: %s: how long it took, e.g. "61 s, of a 60 s budget" */
				__( 'Reading where photos are used on this site - the content of its posts, pages and products, page builders\' data and its settings - took longer than one step of the check may (%s), so nothing from this step was counted. Nothing is removed unless a check finishes.', 'tcgiant-sync' ),
				$e->getMessage()
			) );
		} catch ( TCGiant_Sync_Photo_Read_Full $e ) {
			$refused = new WP_Error( 'memory', sprintf(
				/* translators: %s: memory left and needed, e.g. "12 MB of a 256 MB memory limit left, and the next part of it needs about 16 MB" */
				__( 'Reading where photos are used on this site - the content of its posts, pages and products, page builders\' data and its settings - needed more memory than this step of the check had left (%s), so it stopped there and nothing from this step was counted. Nothing is removed unless a check finishes; a higher PHP memory limit lets it through.', 'tcgiant-sync' ),
				$e->getMessage()
			) );
		} catch ( TCGiant_Sync_Photo_Read_Error $e ) {
			$refused = new WP_Error( 'read', sprintf(
				/* translators: %s: what the database said */
				__( 'The database did not answer while checking (%s), so nothing from this step was counted. Press the button again to carry on from where the check stopped.', 'tcgiant-sync' ),
				$e->getMessage()
			) );
		} finally {
			self::release_lock( 'scan' );
			TCGiant_Sync_Runtime::unwatch();
		}

		if ( $refused ) {
			wp_send_json_error( array( 'message' => $refused->get_error_message() ) );
		}

		wp_send_json_success( self::status() );
	}

	/**
	 * AJAX: the shop pressed the button to remove what the check found.
	 *
	 * Marks as many as fit in this request and leaves the rest, and every
	 * deletion, to the background removal - which goes on whether or not
	 * anyone keeps the page open.
	 *
	 * @return void
	 */
	public static function ajax_remove() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		TCGiant_Sync_Runtime::raise_time_limit( 120 );

		// Under the check's hold, so a check being started again in another
		// tab cannot replace the one approved here half way through.
		if ( ! self::take_lock( 'scan', self::scan_hold_seconds() ) ) {
			wp_send_json_error( array( 'message' => __( 'A check is running. Wait for it to finish, then look at what it found before removing anything.', 'tcgiant-sync' ) ) );
		}

		TCGiant_Sync_Runtime::watch( self::CHECK_JOB, array( __CLASS__, 'release_all_locks' ) );

		$unread = null;

		try {
			try {
				$approved = self::approve( sanitize_text_field( wp_unslash( $_POST['scan_id'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			} finally {
				self::release_lock( 'scan' );
			}

			if ( ! is_wp_error( $approved ) && self::take_lock( 'queue', self::LOCK_SECONDS ) ) {
				try {
					self::queue_approved( min( TCGiant_Sync_Runtime::time_budget(), TCGiant_Sync_Runtime::elapsed() + self::AJAX_SECONDS ) );
				} catch ( TCGiant_Sync_Photo_Read_Error $e ) {
					// Approved all the same: the background removal carries on
					// getting the photos ready, judging each one again.
					$unread = $e;
				} finally {
					self::release_lock( 'queue' );
				}
			}
		} finally {
			TCGiant_Sync_Runtime::unwatch();
		}

		if ( is_wp_error( $approved ) ) {
			wp_send_json_error( array( 'message' => $approved->get_error_message() ) );
		}

		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		wp_schedule_single_event( time() + 5, self::CLEANUP_HOOK );
		if ( class_exists( 'TCGiant_Sync_Cron' ) ) {
			TCGiant_Sync_Cron::request_dispatch();
		}

		if ( $unread instanceof TCGiant_Sync_Photo_Read_Full ) {
			wp_send_json_error( array( 'message' => sprintf(
				/* translators: %s: memory left and needed, e.g. "12 MB of a 256 MB memory limit left, and the next part of it needs about 16 MB" */
				__( 'The removal has been started, but reading where photos are used on this site needed more memory than this request had left (%s) while the photos were being got ready. Nothing has been removed. It carries on in the background, checking each photo again first.', 'tcgiant-sync' ),
				$unread->getMessage()
			) ) );
		}

		if ( $unread instanceof TCGiant_Sync_Photo_Read_Late ) {
			wp_send_json_error( array( 'message' => sprintf(
				/* translators: %s: how long it took, e.g. "61 s, of a 60 s budget" */
				__( 'The removal has been started, but reading where photos are used on this site took longer than this request may (%s) while the photos were being got ready. Nothing has been removed. It carries on in the background, checking each photo again first.', 'tcgiant-sync' ),
				$unread->getMessage()
			) ) );
		}

		if ( null !== $unread ) {
			wp_send_json_error( array( 'message' => sprintf(
				/* translators: %s: what the database said */
				__( 'The removal has been started, but the database did not answer while the photos were being got ready (%s). Nothing has been removed. It carries on in the background, checking each photo again first.', 'tcgiant-sync' ),
				$unread->getMessage()
			) ) );
		}

		wp_send_json_success( self::status() );
	}

	/**
	 * AJAX: where the check and the removal have got to.
	 *
	 * @return void
	 */
	public static function ajax_status() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		wp_send_json_success( self::status() );
	}

	/**
	 * Everything the screen shows.
	 *
	 * @return array
	 */
	public static function status() {
		global $wpdb;

		$scan  = self::scan();
		$stats = self::stats();
		$pause = self::pause_state();
		$until = (int) $pause['until'];
		$since = (int) get_option( self::SINCE_OPTION, 0 );
		$check = self::check_stats();

		$waiting = self::waiting();
		$mine    = 0;
		if ( '' !== $check['scan'] && $check['scan'] === (string) ( $scan['id'] ?? '' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$mine = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				self::APPROVED_KEY,
				$check['scan']
			) );
		} else {
			$check = array( 'scan' => '', 'removed' => 0, 'bytes' => 0, 'kept' => 0, 'failed' => 0 );
		}

		return array(
			'scan'           => array(
				'id'          => (string) ( $scan['id'] ?? '' ),
				'status'      => (string) ( $scan['status'] ?? '' ),
				'examined'    => (int) ( $scan['examined'] ?? 0 ),
				'estimate'    => (int) ( $scan['estimate'] ?? 0 ),
				'found'       => (int) ( $scan['found'] ?? 0 ),
				'bytes'       => (int) ( $scan['bytes'] ?? 0 ),
				'size'        => size_format( (int) ( $scan['bytes'] ?? 0 ), 1 ),
				'not_here'    => (int) ( $scan['not_here'] ?? 0 ),
				'waiting'     => (int) ( $scan['waiting'] ?? 0 ),
				'failed'      => (int) ( $scan['failed'] ?? 0 ),
				'finished_at' => (int) ( $scan['finished_at'] ?? 0 ),
				'stale'       => ! empty( $scan['finished_at'] ) && time() - (int) $scan['finished_at'] > self::SCAN_VALID_SECONDS,
				'queued'      => (int) ( $scan['queued'] ?? 0 ) + (int) ( $scan['already'] ?? 0 ),
				'kept'        => (int) ( $scan['kept'] ?? 0 ),
			),
			// What became of the photos this check put in the queue - not
			// those of products deleted since, which are counted below.
			'check'          => array(
				'removed' => (int) $check['removed'],
				'freed'   => size_format( (int) $check['bytes'], 1 ),
				'kept'    => (int) $check['kept'],
				'failed'  => (int) $check['failed'],
				'waiting' => $mine,
			),
			'failed'         => (int) $stats['failed'],
			'left_files'     => (int) $stats['left_files'],
			'left_size'      => size_format( (int) $stats['left_bytes'], 1 ),
			'waiting'        => $waiting,
			'auto_waiting'   => max( 0, $waiting - $mine ),
			'due'            => self::due(),
			'next_run'       => (int) wp_next_scheduled( self::CLEANUP_HOOK ),
			'paused_until'   => $until > time() ? $until : 0,
			// What the pause is for: deleting, reading, slow or memory. See start_pause().
			'paused_why'     => $until > time() ? (string) $pause['why'] : '',
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'trashed'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'trash'" ),
			// The ones whose photos will be left for the check. See SINCE_OPTION.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'trashed_before' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT( DISTINCT p.ID ) FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_wp_trash_meta_time'
				 WHERE p.post_type = 'product' AND p.post_status = 'trash'
				   AND ( t.meta_value IS NULL OR CAST( t.meta_value AS SIGNED ) < %d )",
				$since > 0 ? $since : time()
			) ),
		);
	}

	// ───────────────────────────────────────────────────────────────────────────
	// Housekeeping
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Hold a piece of this work for one request.
	 *
	 * A plain INSERT is the hold: the options table's unique name means it
	 * goes in for one request and fails for every other. add_option() will
	 * not do - it inserts with ON DUPLICATE KEY UPDATE, so two requests would
	 * both be told they had it. A hold past its time is a request that was
	 * killed, and is taken over by deleting exactly the value read, so that
	 * of two requests doing that at once only one gets it.
	 *
	 * The row is read back before the hold counts, earliest first: on a table
	 * that has lost its unique key in a bad migration both inserts go in, and
	 * only the request whose row came first may go on.
	 *
	 * @param string $what    Which piece of work.
	 * @param int    $seconds How long the hold lasts at most.
	 * @return bool
	 */
	private static function take_lock( $what, $seconds ) {
		global $wpdb;

		$name  = self::LOCK_PREFIX . $what;
		$now   = time();
		$value = ( $now + (int) $seconds ) . '|' . wp_generate_uuid4();
		$first = "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s ORDER BY option_id ASC LIMIT 1";

		for ( $try = 0; $try < 2; $try++ ) {
			$quiet = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$took  = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				$name,
				$value
			) );
			$error = (string) $wpdb->last_error;
			$wpdb->suppress_errors( $quiet );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$held = $wpdb->get_var( $wpdb->prepare( $first, $name ) );

			if ( $took && $value === $held ) {
				self::$held[ $what ] = $value;
				return true;
			}

			if ( $took ) {
				// Another request's row came first. This one goes back out.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$name,
					$value
				) );
			}

			if ( null === $held ) {
				// Not refused because it is held: there is nothing holding it.
				// The database would not take the write.
				self::lock_failed( $error );
				return false;
			}

			$held = (string) $held;
			if ( '' !== $held && (int) $held > $now ) {
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$name,
				$held
			) );
		}

		return false;
	}

	/**
	 * Say, at most once an hour, that the database refused to record a hold.
	 *
	 * Without this the removal would wait for a hold nobody has, quietly and
	 * for ever. A database that will not take a write is often one whose disk
	 * is full - the very thing a shop running this may be short of.
	 *
	 * @param string $error What the database said.
	 * @return void
	 */
	private static function lock_failed( $error ) {
		if ( get_transient( 'tcgiant_photo_cleanup_lock_error' ) ) {
			return;
		}
		set_transient( 'tcgiant_photo_cleanup_lock_error', 1, HOUR_IN_SECONDS );

		TCGiant_Sync_Logger::error( sprintf(
			'Photo cleanup: could not start - the database would not record that it was working (%s). Nothing was removed. This is often a full disk on the database server; it tries again in a few minutes.',
			'' !== $error ? $error : 'it gave no reason'
		) );
	}

	/**
	 * Let go of a hold - this request's own, never one taken over since.
	 *
	 * @param string $what Which piece of work.
	 * @return void
	 */
	private static function release_lock( $what ) {
		global $wpdb;

		if ( empty( self::$held[ $what ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			self::LOCK_PREFIX . $what,
			self::$held[ $what ]
		) );

		unset( self::$held[ $what ] );
	}

	/**
	 * Let go of every hold this request has, for a request that is dying.
	 *
	 * @return void
	 */
	public static function release_all_locks() {
		foreach ( array_keys( self::$held ) as $what ) {
			self::release_lock( $what );
		}
	}

	/**
	 * Clear the schedule on deactivation. What is marked stays marked, and is
	 * picked up again when the plugin is.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}
}

/**
 * A read the photo cleanup judges by, which the database failed.
 *
 * Thrown by TCGiant_Sync_Photo_Cleanup::read_ok(), and never taken for an
 * answer: whatever was being judged is left as it is. Its message is what the
 * database said.
 */
class TCGiant_Sync_Photo_Read_Error extends RuntimeException {}

/**
 * A reading of where photos are used on the site that took as long as a run
 * may by itself, and was given up. Handled as a failed read - nothing judged,
 * nothing given up on - and the removal pauses after it. Its message says
 * how long it took.
 */
class TCGiant_Sync_Photo_Read_Late extends TCGiant_Sync_Photo_Read_Error {}

/**
 * A reading of where photos are used on the site that would have needed more
 * memory than the request had left, given up before it ran out. Handled as
 * one that took too long: nothing judged, nothing given up on, and the
 * removal pauses after it. Its message says what was left, and what the next
 * part of the reading needed.
 */
class TCGiant_Sync_Photo_Read_Full extends TCGiant_Sync_Photo_Read_Error {}

/**
 * A mark the photo cleanup relies on that the database did not keep - a
 * write refused by a full disk, a deadlock, a lock that timed out. Handled as
 * a failed read: the run stops there, and the photo it was working on keeps
 * its place in the queue, which the image batch passes by. Its message says
 * which mark, and what the database said.
 */
class TCGiant_Sync_Photo_Write_Error extends TCGiant_Sync_Photo_Read_Error {}
