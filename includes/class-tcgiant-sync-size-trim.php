<?php
/**
 * Old large copies of downloaded photos
 *
 * Until 3.27.4 WordPress made its 1536x1536 copy of every photo the plugin
 * downloaded at eBay's Original size (1,600 px): the same photo 4% smaller,
 * about 264 KB of the 980 KB each such photo takes with all its sizes. Without
 * it shoppers see the same photo: a browser that would have picked it - a
 * high-DPI phone on a product page - picks the next size up, the photo itself.
 * 3.27.4 stopped making it (TCGiant_Sync_Image_Localizer::SKIPPED_IMPORT_SIZES).
 * This takes it off the photos downloaded before, behind a check and a button
 * on Image Cleanup, for a shop running out of disk.
 *
 * Which photos: see plan_for(). In short, a photo the plugin downloaded
 * (_tcgiant_source_url, no _tcgiant_external_url), with a 1536x1536 or
 * 2048x2048 entry in its record of sizes, no larger than 2,048 px, not a GIF -
 * the rule a download goes by (TCGiant_Sync_Image_Localizer::skips_large_sizes())
 * - and with no mark of the image batch or of the photo cleanup on it.
 *
 * What is done to each, in this order, each step confirmed before the next:
 *
 * 1. A note of exactly which sizes and files are going is written to the photo
 *    (PENDING_KEY), and read back.
 * 2. Those sizes come out of the photo's record (_wp_attachment_metadata), so
 *    that srcset stops offering them at once. Written only over the record as
 *    it was read, and read back.
 * 3. Later, the files are deleted, with wp_delete_file_from_directory()
 *    against the photo's own folder. A page a page cache or CDN saved before
 *    step 2 still lists the copy in srcset, and a browser that picks it shows
 *    a broken photo - it never falls back to the src. So the page caches that
 *    can be asked are purged once every record is done (records_done()), the
 *    files wait at least a day, and as long as a page cache says it keeps
 *    pages, up to eight days (files_delay()), and the screen and the log tell
 *    the shop to purge any other cache or CDN before then.
 *    A file is kept, whoever's it is, while anything still names it: another
 *    size of the photo, the photo itself, the original it was scaled from, a
 *    copy kept from editing it, or another Media Library entry - see
 *    shared_elsewhere().
 * 4. The note comes off.
 *
 * A run killed anywhere leaves the note, and the next run finishes what the
 * note says before anything new (see work()). A photo that kills a run three
 * times is left as it is, its copy on disk, and named in the log.
 *
 * It fails toward keeping files. A read the database fails stops the run or the
 * step of the check with nothing changed on its strength (read_ok()); a mark the
 * database did not keep stops the run (confirm_note()); a photo whose files are
 * not on this server is left as it is, record and all - an offload plugin
 * keeps its own copy of each size, which a changed record would strand; and
 * nothing at all happens while an offload plugin is active (offloaders()).
 *
 * The safety of this follows TCGiant_Sync_Photo_Cleanup's: its unfiltered
 * reading of a photo's files (file_records()), its read and write errors, the
 * hold that keeps two requests off the same work, a pause after a run dies,
 * and a time budget for every run.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.27.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The photo cleanup's reading of a photo's files, and its read and write
// errors, which this throws and catches as it does.
if ( ! class_exists( 'TCGiant_Sync_Photo_Read_Error', false ) ) {
	require_once __DIR__ . '/class-tcgiant-sync-photo-cleanup.php';
}

/**
 * TCGiant_Sync_Size_Trim class.
 */
class TCGiant_Sync_Size_Trim {

	/**
	 * On a photo being worked on: which of its sizes and files are going, how
	 * far that has got, and when its files may be deleted.
	 *
	 * An array: stage (meta - its record is to be changed; files - its record
	 * no longer names them, and they wait for their day; given_up - left as it
	 * is), sizes, files (names, in the photo's own folder), dir (that folder,
	 * as the photo's record names it), at, tries and, from the files stage,
	 * due. Written before anything is touched and read back; taken off once
	 * the files are gone.
	 */
	const PENDING_KEY = '_tcgiant_trim_pending';

	/**
	 * WP-Cron hook for the background removal.
	 */
	const TRIM_HOOK = 'tcgiant_sync_size_trim';

	/**
	 * The check, and what it found.
	 */
	const CHECK_OPTION = 'tcgiant_sync_size_trim_check';

	/**
	 * The removal the shop asked for: where it has got to, and its totals.
	 */
	const RUN_OPTION = 'tcgiant_sync_size_trim_run';

	/**
	 * When the removal last paused after a run died, and until when.
	 */
	const PAUSE_OPTION = 'tcgiant_sync_size_trim_pause';

	/**
	 * Prefix of the options that stop two requests doing the same work at once.
	 */
	const LOCK_PREFIX = 'tcgiant_sync_size_trim_lock_';

	/**
	 * Name in the log, and for the record of a run PHP kills.
	 */
	const JOB = 'Large photo copies';

	/**
	 * The same, for a step of the check.
	 */
	const CHECK_JOB = 'Large photo copies check';

	/**
	 * How long the files of a photo whose record no longer names them stay on
	 * disk, at the least, for the pages a cache saved before. Longer where a
	 * page cache says it keeps pages longer: see files_delay().
	 */
	const FILES_AFTER_SECONDS = 86400;

	/**
	 * The longest the files wait, however long a page cache keeps pages.
	 */
	const FILES_AFTER_MAX_SECONDS = 691200;

	/**
	 * LiteSpeed Cache's own default for how long it keeps a public page,
	 * when its setting cannot be read.
	 */
	const LITESPEED_DEFAULT_TTL = 604800;

	/**
	 * The page caches asked to forget every page once the records are done,
	 * each through its own public way of being asked: an action it listens
	 * to, or a function of its own. See purge_page_caches().
	 */
	const PAGE_CACHE_PURGES = array(
		'LiteSpeed Cache'  => array( 'action', 'litespeed_purge_all' ),
		'WP Rocket'        => array( 'function', 'rocket_clean_domain' ),
		'W3 Total Cache'   => array( 'function', 'w3tc_flush_all' ),
		'WP Super Cache'   => array( 'function', 'wp_cache_clear_cache' ),
		'Breeze'           => array( 'action', 'breeze_clear_all_cache' ),
		'WP Fastest Cache' => array( 'action', 'wpfc_clear_all_cache' ),
	);

	/**
	 * Gap between runs while there is work.
	 */
	const NEXT_RUN_SECONDS = 30;

	/**
	 * When the next run happens if this one is killed.
	 */
	const FALLBACK_SECONDS = 300;

	/**
	 * When the removal looks again after finding an offload plugin active.
	 */
	const BLOCKED_SECONDS = 3600;

	/**
	 * How long one run holds the removal. Shorter than FALLBACK_SECONDS.
	 */
	const LOCK_SECONDS = 240;

	/**
	 * How long the removal waits after a run dies, twice as long each time it
	 * happens again straight after, up to PAUSE_MAX_SECONDS. A run that dies
	 * in a WP-Cron request takes the eBay sync due beside it with it.
	 */
	const PAUSE_SECONDS = 1800;

	/**
	 * The longest the removal pauses for.
	 */
	const PAUSE_MAX_SECONDS = 43200;

	/**
	 * Runs a photo may die in before it is left as it is. A photo that killed
	 * a run before its note was written is stepped past after two.
	 */
	const MAX_TRIES = 3;

	/**
	 * Post IDs one query of the walk through the photos looks across at most,
	 * so that each is short however few photos carry a large copy.
	 */
	const WINDOW = 2000;

	/**
	 * Photos judged at once.
	 */
	const PAGE = 100;

	/**
	 * Notes read at once when their files' day has come.
	 */
	const NOTE_PAGE = 200;

	/**
	 * Most of a request a step of the check uses, so that the progress bar moves.
	 */
	const AJAX_SECONDS = 20;

	/**
	 * How long a check stands before the removal asks for another.
	 */
	const CHECK_VALID_SECONDS = 604800;

	/**
	 * The shortest and longest the check's hold lasts, in seconds. See
	 * TCGiant_Sync_Photo_Cleanup::SCAN_HOLD_FLOOR.
	 */
	const CHECK_HOLD_FLOOR   = 180;
	const CHECK_HOLD_CEILING = 600;

	/**
	 * The kinds of photo WordPress may save a file as, whatever its name said.
	 * As TCGiant_Sync_Image_Localizer::unclaimed_file_name() checks a name.
	 */
	const IMAGE_KINDS = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'heic' );

	/**
	 * Offload plugins, by the folder they install into. While one is active
	 * nothing here is checked or removed. See offloaders().
	 */
	const OFFLOADER_PLUGINS = array(
		'amazon-s3-and-cloudfront'        => 'WP Offload Media',
		'amazon-s3-and-cloudfront-pro'    => 'WP Offload Media',
		'ilab-media-tools'                => 'Media Cloud',
		'ilab-media-tools-premium'        => 'Media Cloud',
		'advanced-media-offloader'        => 'Advanced Media Offloader',
		'wp-stateless'                    => 'WP-Stateless',
		'infinite-uploads'                => 'Infinite Uploads',
		's3-uploads'                      => 'S3 Uploads',
		'leopard-wordpress-offload-media' => 'Leopard Offload Media',
		'next3-offload'                   => 'Next3 Offload',
		'cloudinary-image-management-and-manipulation-in-the-cloud-cdn' => 'Cloudinary',
	);

	/**
	 * Offload plugins by their own code, for one loaded some other way - as a
	 * must-use plugin, or by Composer.
	 */
	const OFFLOADER_CODE = array(
		'Amazon_S3_And_CloudFront'        => 'WP Offload Media',
		'S3_Uploads\\Plugin'              => 'S3 Uploads',
		'wpCloud\\StatelessMedia\\Bootstrap' => 'WP-Stateless',
		'Infinite_Uploads'                => 'Infinite Uploads',
		'S3_UPLOADS_BUCKET'               => 'S3 Uploads',
	);

	/**
	 * What a plugin with "offload" in its folder's or callback's name has in
	 * its name, letters and digits only, when it moves no media: WP Offload
	 * SES, which sends a site's e-mail through Amazon SES.
	 */
	const NOT_OFFLOADERS = array( 'offloadses' );

	/**
	 * The hooks an offload plugin deletes, uploads or re-processes a photo's
	 * files from. See offloaders().
	 */
	const OFFLOAD_HOOKS = array( 'delete_attachment', 'wp_delete_file', 'updated_postmeta', 'wp_update_attachment_metadata' );

	/**
	 * Page cache plugins named on the screen, by a constant each defines.
	 */
	const PAGE_CACHES = array(
		'LSCWP_V'                 => 'LiteSpeed Cache',
		'WP_ROCKET_VERSION'       => 'WP Rocket',
		'W3TC'                    => 'W3 Total Cache',
		'WPCACHEHOME'             => 'WP Super Cache',
		'WPFC_WP_CONTENT_BASENAME' => 'WP Fastest Cache',
		'BREEZE_VERSION'          => 'Breeze',
		'CLOUDFLARE_PLUGIN_DIR'   => 'Cloudflare',
	);

	/**
	 * Instance.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * Holds this request has taken, by what they hold, with the value each
	 * was written with.
	 *
	 * @var string[]
	 */
	private static $held = array();

	/**
	 * Copies deleted with their photo in this request, for one line at its end.
	 *
	 * @var int[]
	 */
	private static $with_photo = array( 'photos' => 0, 'files' => 0, 'bytes' => 0 );

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
	 * Registered on every request: the removal runs from WP-Cron, and a photo
	 * can be deleted - with its copy still waiting for its day - by WP-Cron
	 * emptying the Trash and by the photo cleanup's own runs.
	 */
	public function __construct() {
		add_action( self::TRIM_HOOK, array( $this, 'process_run' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_rearm_trim' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );

		add_action( 'wp_ajax_tcgiant_size_trim_check', array( __CLASS__, 'ajax_check' ) );
		add_action( 'wp_ajax_tcgiant_size_trim_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_tcgiant_size_trim_status', array( __CLASS__, 'ajax_status' ) );
	}

	// ───────────────────────────────────────────────────────────────────────────
	// Which photos, and what of them
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * The next photos that may carry a large copy, in ID order.
	 *
	 * Those the plugin downloaded whose record of sizes mentions either size.
	 * The mention is only a hint - a photo's caption could hold the words - and
	 * plan_for() judges each from its record. Each query looks across at most
	 * WINDOW post IDs, so a shop where few photos carry the copy is walked in
	 * short steps rather than one long one.
	 *
	 * @param int $after  The last ID already walked past.
	 * @param int $max_id The last ID to walk to.
	 * @return array{0:int[],1:int} The IDs, and the ID walked past with them.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function candidates( $after, $max_id ) {
		global $wpdb;

		$after = (int) $after;
		$upto  = min( (int) $max_id, $after + self::WINDOW );

		if ( $upto <= $after ) {
			return array( array(), $after );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT a.ID FROM {$wpdb->posts} a
			 INNER JOIN {$wpdb->postmeta} md ON md.post_id = a.ID AND md.meta_key = '_wp_attachment_metadata'
			 WHERE a.ID > %d AND a.ID <= %d
			   AND a.post_type = 'attachment'
			   AND ( md.meta_value LIKE %s OR md.meta_value LIKE %s )
			   AND EXISTS (
				   SELECT 1 FROM {$wpdb->postmeta} src
				   WHERE src.post_id = a.ID AND src.meta_key = '_tcgiant_source_url'
			   )
			 ORDER BY a.ID ASC
			 LIMIT %d",
			$after,
			$upto,
			'%' . $wpdb->esc_like( '"' . TCGiant_Sync_Image_Localizer::SKIPPED_IMPORT_SIZES[0] . '"' ) . '%',
			'%' . $wpdb->esc_like( '"' . TCGiant_Sync_Image_Localizer::SKIPPED_IMPORT_SIZES[1] . '"' ) . '%',
			self::PAGE
		) ) );
		self::read_ok();

		// A full page may stop short of the window; carry on from its last.
		$next = count( $ids ) >= self::PAGE ? (int) end( $ids ) : $upto;

		return array( $ids, $next );
	}

	/**
	 * What would be done to each of these photos, and why not for the rest.
	 *
	 * Asked of the database as it is now - every mark, the photo's file and
	 * its record of sizes, unfiltered - and of the uploads folder. The same
	 * judgement for the check and for the removal, so that the check counts
	 * what the removal does.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array[] ID => plan: why ('' when its copies may go, otherwise
	 *                 gone, not_ours, marked, pending, odd, none, gif, large or
	 *                 not_here), sizes, files (name => bytes), kept (name =>
	 *                 internal or shared), dir, rel_dir, path, stem, note
	 *                 (its PENDING_KEY note, when it has one).
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	public static function judge( array $ids ) {
		$records = self::records( $ids );
		$plans   = array();

		foreach ( $records as $id => $record ) {
			$plans[ $id ] = self::plan_for( $record );
		}

		self::shared_elsewhere( $plans );

		// What is on this server, last, and only for what would otherwise go.
		foreach ( $plans as $id => $plan ) {
			if ( '' !== $plan['why'] ) {
				continue;
			}
			clearstatcache();
			$here = is_file( $plan['path'] );
			foreach ( $plan['files'] as $name => $bytes ) {
				$file = $plan['dir'] . '/' . $name;
				if ( ! is_file( $file ) ) {
					$here = false;
					break;
				}
				// The size's own record of its bytes, else the file's.
				$plans[ $id ]['files'][ $name ] = $bytes > 0 ? $bytes : (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			if ( ! $here ) {
				$plans[ $id ]['why'] = 'not_here';
			}
		}

		return $plans;
	}

	/**
	 * Everything about each photo a judgement needs, read straight from the
	 * tables: get_post_meta() keeps a failed read as "no marks at all" for the
	 * rest of the request, and an offload plugin may filter what
	 * wp_get_attachment_metadata() returns.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array[] ID => found, status, mime, marks (key => first value),
	 *                 file, meta, meta_rows, backup, note, note_rows.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function records( array $ids ) {
		global $wpdb;

		$ids     = self::ids( $ids );
		$records = array();
		foreach ( $ids as $id ) {
			$records[ $id ] = array(
				'id'        => $id,
				'found'     => false,
				'status'    => '',
				'mime'      => '',
				'marks'     => array(),
				'file'      => '',
				'meta'      => null,
				'meta_rows' => 0,
				'backup'    => array(),
				'note'      => null,
				'note_rows' => 0,
			);
		}

		$keys = "'" . implode( "', '", array_map( 'esc_sql', self::read_keys() ) ) . "'";

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$posts = (array) $wpdb->get_results( "SELECT ID, post_status, post_mime_type FROM {$wpdb->posts} WHERE ID IN ( {$in} ) AND post_type = 'attachment'" );
			self::read_ok();

			foreach ( $posts as $post ) {
				$records[ (int) $post->ID ]['found']  = true;
				$records[ (int) $post->ID ]['status'] = (string) $post->post_status;
				$records[ (int) $post->ID ]['mime']   = (string) $post->post_mime_type;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				 WHERE post_id IN ( {$in} ) AND meta_key IN ( {$keys} )
				 ORDER BY meta_id ASC"
			);
			self::read_ok();

			foreach ( $rows as $row ) {
				$id  = (int) $row->post_id;
				$key = (string) $row->meta_key;

				if ( '_wp_attachment_metadata' === $key ) {
					$records[ $id ]['meta_rows']++;
					$meta                   = maybe_unserialize( $row->meta_value );
					$records[ $id ]['meta'] = is_array( $meta ) ? $meta : null;
				} elseif ( '_wp_attachment_backup_sizes' === $key ) {
					$backup                   = maybe_unserialize( $row->meta_value );
					$records[ $id ]['backup'] = is_array( $backup ) ? $backup : array();
				} elseif ( '_wp_attached_file' === $key ) {
					if ( '' === $records[ $id ]['file'] ) {
						$records[ $id ]['file'] = (string) $row->meta_value;
					}
				} elseif ( self::PENDING_KEY === $key ) {
					$records[ $id ]['note_rows']++;
					$note                   = maybe_unserialize( $row->meta_value );
					$records[ $id ]['note'] = is_array( $note ) ? $note : array( 'stage' => '' );
				} elseif ( ! isset( $records[ $id ]['marks'][ $key ] ) ) {
					// The first of each, as get_post_meta() reads one.
					$records[ $id ]['marks'][ $key ] = (string) $row->meta_value;
				}
			}
		}

		return $records;
	}

	/**
	 * The meta keys records() reads.
	 *
	 * @return string[]
	 */
	private static function read_keys() {
		return array_merge(
			array( '_tcgiant_source_url', '_tcgiant_external_url', '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', self::PENDING_KEY ),
			self::busy_marks()
		);
	}

	/**
	 * Marks that say another part of the plugin has the photo in hand.
	 *
	 * The image batch's mark for a photo a stopped request left half saved,
	 * which it will finish - making its sizes - when it is next needed; and the
	 * photo cleanup's, for a photo waiting to be deleted, being deleted, or
	 * given up on part way. A photo carrying any is left to them.
	 *
	 * @return string[]
	 */
	private static function busy_marks() {
		return array(
			TCGiant_Sync_Image_Localizer::UNFINISHED_META,
			TCGiant_Sync_Photo_Cleanup::MARK_KEY,
			TCGiant_Sync_Photo_Cleanup::ATTEMPTS_KEY,
			TCGiant_Sync_Photo_Cleanup::STARTED_KEY,
			TCGiant_Sync_Photo_Cleanup::APPROVED_KEY,
			TCGiant_Sync_Photo_Cleanup::FAILED_KEY,
		);
	}

	/**
	 * What would be done to one photo, before what other photos and the
	 * uploads folder say. See judge().
	 *
	 * @param array $record From records().
	 * @return array The plan.
	 */
	private static function plan_for( array $record ) {
		$plan = array(
			'why'     => '',
			'sizes'   => array(),
			'files'   => array(),
			'kept'    => array(),
			'dir'     => '',
			'rel_dir' => '',
			'path'    => '',
			'stem'    => '',
			'note'    => $record['note'],
		);

		$marks = $record['marks'];

		if ( ! $record['found'] ) {
			$plan['why'] = 'gone';
			return $plan;
		}

		// Only what the plugin downloaded, and not an eBay stand-in, which
		// has no file. A photo moved to the Media Library's own Trash is the
		// shop's business.
		if ( '' === ( $marks['_tcgiant_source_url'] ?? '' ) || isset( $marks['_tcgiant_external_url'] ) || 'inherit' !== $record['status'] ) {
			$plan['why'] = 'not_ours';
			return $plan;
		}

		foreach ( self::busy_marks() as $key ) {
			if ( isset( $marks[ $key ] ) ) {
				$plan['why'] = 'marked';
				return $plan;
			}
		}

		if ( $record['note_rows'] > 0 ) {
			$plan['why'] = 'pending';
			return $plan;
		}

		// One record of sizes, and a file, or it is not clear what the photo is.
		$meta = $record['meta'];
		if ( 1 !== $record['meta_rows'] || ! is_array( $meta ) || '' === $record['file'] ) {
			$plan['why'] = 'odd';
			return $plan;
		}

		$listed = is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array();
		$sizes  = array_values( array_intersect( TCGiant_Sync_Image_Localizer::SKIPPED_IMPORT_SIZES, array_keys( $listed ) ) );
		if ( empty( $sizes ) ) {
			$plan['why'] = 'none';
			return $plan;
		}

		if ( (int) ( $meta['width'] ?? 0 ) < 1 || (int) ( $meta['height'] ?? 0 ) < 1 ) {
			$plan['why'] = 'odd';
			return $plan;
		}

		// The rule a download goes by: the photo itself must be able to take
		// the copies' place in srcset.
		if ( ! TCGiant_Sync_Image_Localizer::skips_large_sizes( $meta, $record['mime'] ) ) {
			$is_gif      = 'gif' === strtolower( pathinfo( (string) ( $meta['file'] ?? $record['file'] ), PATHINFO_EXTENSION ) ) || 'image/gif' === strtolower( $record['mime'] );
			$plan['why'] = $is_gif ? 'gif' : 'large';
			return $plan;
		}

		$plan['sizes']   = $sizes;
		$plan['path']    = self::main_path( $record['file'] );
		$plan['dir']     = dirname( $plan['path'] );
		$plan['rel_dir'] = self::rel_dir( $record['file'] );
		$plan['stem']    = self::stem( $record['file'] );

		// A file the photo still names some other way stays: another size of
		// the same dimensions (WordPress makes one file for both), the photo
		// itself, the original it was scaled from, a copy kept from editing.
		$named = self::named_files( $meta, $record['backup'], $record['file'], $sizes );

		foreach ( $sizes as $name ) {
			$file = wp_basename( (string) ( $listed[ $name ]['file'] ?? '' ) );
			if ( '' === $file || isset( $plan['files'][ $file ] ) || isset( $plan['kept'][ $file ] ) ) {
				continue;
			}
			if ( isset( $named[ strtolower( $file ) ] ) ) {
				$plan['kept'][ $file ] = 'internal';
				continue;
			}
			$plan['files'][ $file ] = max( 0, (int) ( $listed[ $name ]['filesize'] ?? 0 ) );
		}

		return $plan;
	}

	/**
	 * Every file a photo's records name, in its own folder, lower-cased.
	 *
	 * @param array  $meta   Its record of sizes.
	 * @param array  $backup Copies kept from editing it (_wp_attachment_backup_sizes).
	 * @param string $file   Its file (_wp_attached_file).
	 * @param array  $except Sizes left out: the ones going.
	 * @return bool[] File name => true.
	 */
	private static function named_files( array $meta, array $backup, $file, array $except = array() ) {
		$named = array();

		foreach ( array( $file, (string) ( $meta['file'] ?? '' ), (string) ( $meta['original_image'] ?? '' ) ) as $one ) {
			if ( '' !== $one ) {
				$named[ strtolower( wp_basename( $one ) ) ] = true;
			}
		}

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $name => $size ) {
			if ( ! in_array( (string) $name, $except, true ) && is_array( $size ) && ! empty( $size['file'] ) ) {
				$named[ strtolower( wp_basename( (string) $size['file'] ) ) ] = true;
			}
		}

		foreach ( $backup as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$named[ strtolower( wp_basename( (string) $size['file'] ) ) ] = true;
			}
		}

		return $named;
	}

	/**
	 * Keep each file another Media Library entry names.
	 *
	 * WordPress names a photo's sizes after the photo - "part-1536x1152.jpg" for
	 * "part.jpg" - in the photo's own folder. So another entry can name one of
	 * these files only if its own file is in the same folder and is the file
	 * itself, or has the same name before its extension (another record of
	 * the same photo, as the image batch made before 3.1.4 and translation
	 * plugins make; or a photo saved over one an offload plugin had emptied
	 * from the folder; or one of another type whose sizes WordPress saved in
	 * the same format), or was edited from such a one (-e and thirteen digits).
	 * Those entries are found by their file's name, exactly or by its start,
	 * which the plugin's index on post meta answers, and their own records are
	 * then read whole: a file any of them names is kept.
	 *
	 * @param array[] $plans ID => plan, from plan_for(). Changed in place.
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function shared_elsewhere( array &$plans ) {
		global $wpdb;

		$exact = array(); // File as recorded => photos it could share with.
		$start = array(); // Photo => the start of an edited copy's name.

		foreach ( $plans as $id => $plan ) {
			if ( '' !== $plan['why'] || empty( $plan['files'] ) ) {
				continue;
			}
			$prefix = '' !== $plan['rel_dir'] ? $plan['rel_dir'] . '/' : '';
			foreach ( self::IMAGE_KINDS as $kind ) {
				foreach ( array( '', '-scaled', '-rotated' ) as $suffix ) {
					$exact[ $prefix . $plan['stem'] . $suffix . '.' . $kind ][] = $id;
				}
			}
			foreach ( array_keys( $plan['files'] ) as $name ) {
				$exact[ $prefix . $name ][] = $id;
			}
			$start[ $id ] = $prefix . $plan['stem'] . '-e';
		}

		if ( empty( $exact ) ) {
			return;
		}

		$others = array(); // Photo => other entries that may name its files.

		// The database may compare names without regard to case, as MySQL's
		// usual collations do; so does this.
		$lower = array();
		foreach ( $exact as $name => $ids ) {
			$lower[ strtolower( (string) $name ) ] = array_merge( $lower[ strtolower( (string) $name ) ] ?? array(), $ids );
		}

		foreach ( array_chunk( array_keys( $exact ), 400 ) as $chunk ) {
			$marks = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ( {$marks} )",
				$chunk
			) );
			self::read_ok();

			foreach ( $rows as $row ) {
				foreach ( $lower[ strtolower( (string) $row->meta_value ) ] ?? array() as $id ) {
					if ( (int) $row->post_id !== $id ) {
						$others[ $id ][] = (int) $row->post_id;
					}
				}
			}
		}

		foreach ( array_chunk( $start, 50, true ) as $chunk ) {
			$likes = array();
			$args  = array();
			foreach ( $chunk as $prefix ) {
				$likes[] = 'meta_value LIKE %s';
				$args[]  = $wpdb->esc_like( $prefix ) . '%';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND ( " . implode( ' OR ', $likes ) . ' )',
				$args
			) );
			self::read_ok();

			foreach ( $rows as $row ) {
				foreach ( $chunk as $id => $prefix ) {
					if ( (int) $row->post_id !== $id && 0 === strpos( strtolower( (string) $row->meta_value ), strtolower( $prefix ) ) ) {
						$others[ $id ][] = (int) $row->post_id;
					}
				}
			}
		}

		if ( empty( $others ) ) {
			return;
		}

		$all     = self::ids( array_merge( ...array_values( $others ) ) );
		$records = TCGiant_Sync_Photo_Cleanup::file_records( $all );

		foreach ( $others as $id => $their_ids ) {
			foreach ( self::ids( $their_ids ) as $other ) {
				$record = $records[ $other ] ?? null;
				if ( ! is_array( $record ) || '' === $record['file'] || strtolower( self::rel_dir( $record['file'] ) ) !== strtolower( $plans[ $id ]['rel_dir'] ) ) {
					continue;
				}
				$named = self::named_files( (array) $record['meta'], (array) $record['backup'], $record['file'] );
				foreach ( array_keys( $plans[ $id ]['files'] ) as $name ) {
					if ( isset( $named[ strtolower( (string) $name ) ] ) ) {
						unset( $plans[ $id ]['files'][ $name ] );
						$plans[ $id ]['kept'][ $name ] = 'shared';
					}
				}
			}
		}
	}

	/**
	 * A photo's file on this server, as get_attached_file() reads it.
	 *
	 * @param string $relative Its _wp_attached_file.
	 * @return string
	 */
	private static function main_path( $relative ) {
		$relative = (string) $relative;

		// A path stored whole is used whole.
		if ( 0 === strpos( $relative, '/' ) || preg_match( '|^.:\\\\|', $relative ) ) {
			return $relative;
		}

		$uploads = wp_get_upload_dir();
		return rtrim( (string) ( $uploads['basedir'] ?? '' ), '/\\' ) . '/' . $relative;
	}

	/**
	 * The folder a photo's file is in, as its record names it: "2024/05", or
	 * '' for the top of the uploads folder.
	 *
	 * @param string $relative Its _wp_attached_file.
	 * @return string
	 */
	private static function rel_dir( $relative ) {
		$dir = str_replace( '\\', '/', dirname( str_replace( '\\', '/', (string) $relative ) ) );
		return ( '.' === $dir || '/' === $dir ) ? '' : $dir;
	}

	/**
	 * A photo's file name before its extension, and before what WordPress adds
	 * when it scales or rotates one: what its sizes' names begin with.
	 *
	 * @param string $relative Its _wp_attached_file.
	 * @return string
	 */
	private static function stem( $relative ) {
		$name = pathinfo( wp_basename( (string) $relative ), PATHINFO_FILENAME );
		return (string) preg_replace( '/-(?:scaled|rotated)$/', '', $name );
	}

	// ───────────────────────────────────────────────────────────────────────────
	// Offload plugins and page caches
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * The offload plugins at work on this site, if any.
	 *
	 * An offload plugin keeps its own copy of every size of a photo and often
	 * deletes the files here. Taking a size out of a photo's record would
	 * strand that copy in storage the shop pays for, and some of them upload
	 * or re-process a photo when its record changes, or delete their copy when
	 * WordPress deletes a file. So nothing here is checked or removed while one
	 * is at work. Found by:
	 *
	 * - the folder an active plugin is in: those in OFFLOADER_PLUGINS, and any
	 *   whose folder's name has "offload" in it;
	 * - code of one loaded another way (OFFLOADER_CODE);
	 * - a callback on a hook an offload plugin works from (OFFLOAD_HOOKS) that
	 *   is bunny.net's Offloader - which it hooks only while it is switched on,
	 *   unlike its CDN, which is safe - or whose name has "offload" in it;
	 * - an uploads folder that is not a folder on this server (s3://, gs://).
	 *
	 * Each photo is also left as it is when its files are not here (see
	 * judge()), whatever plugin moved them.
	 *
	 * @return string[] What was found, in words, keyed so that each is said once.
	 */
	public static function offloaders() {
		global $wp_filter;

		$found = array();

		$active = (array) get_option( 'active_plugins', array() );
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( $active as $plugin ) {
			$folder = strtolower( (string) strtok( str_replace( '\\', '/', (string) $plugin ), '/' ) );
			if ( isset( self::OFFLOADER_PLUGINS[ $folder ] ) ) {
				$found[ $folder ] = self::OFFLOADER_PLUGINS[ $folder ];
			} elseif ( false !== strpos( $folder, 'offload' ) && ! self::moves_no_media( $folder ) ) {
				$found[ $folder ] = $folder;
			}
		}

		// Loaded already, without asking another plugin's autoloader to load it.
		$declared = array_flip( array_map( 'strtolower', get_declared_classes() ) );
		foreach ( self::OFFLOADER_CODE as $code => $name ) {
			if ( isset( $declared[ strtolower( $code ) ] ) || defined( $code ) ) {
				$found[ strtolower( $name ) ] = $name;
			}
		}

		foreach ( self::OFFLOAD_HOOKS as $hook ) {
			$registered = $wp_filter[ $hook ] ?? null;
			$by_priority = is_object( $registered ) && isset( $registered->callbacks ) ? (array) $registered->callbacks : ( is_array( $registered ) ? $registered : array() );
			foreach ( $by_priority as $callbacks ) {
				foreach ( (array) $callbacks as $callback ) {
					$name = self::callback_name( is_array( $callback ) ? ( $callback['function'] ?? null ) : null );
					if ( 0 === stripos( $name, 'Bunny\\Wordpress\\Offloader' ) ) {
						$found['bunny'] = 'bunny.net\'s Offloader';
					} elseif ( '' !== $name && false !== stripos( $name, 'offload' ) && ! self::moves_no_media( $name ) ) {
						$found[ strtolower( $name ) ] = sprintf( '%s (on %s)', $name, $hook );
					}
				}
			}
		}

		$uploads = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
		$basedir = (string) ( $uploads['basedir'] ?? '' );
		if ( preg_match( '#^([a-z][a-z0-9+.-]*)://#i', $basedir, $scheme ) && 'file' !== strtolower( $scheme[1] ) ) {
			$found['stream'] = sprintf( 'an uploads folder kept at %s://', strtolower( $scheme[1] ) );
		}

		/**
		 * Offload plugins that stop the removal of old large photo copies.
		 *
		 * @since 3.27.7
		 *
		 * @param string[] $found What was found, in words.
		 */
		$found = (array) apply_filters( 'tcgiant_sync_size_trim_offloaders', $found );

		return array_values( array_unique( array_filter( array_map( 'strval', $found ) ) ) );
	}

	/**
	 * A callback's name: Class::method, or the function's.
	 *
	 * @param mixed $callback As registered.
	 * @return string '' for a closure, which says nothing about its owner.
	 */
	private static function callback_name( $callback ) {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) && 2 === count( $callback ) && is_string( $callback[1] ?? null ) ) {
			$owner = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return $owner . '::' . $callback[1];
		}
		if ( is_object( $callback ) && ! ( $callback instanceof \Closure ) ) {
			return get_class( $callback );
		}
		return '';
	}

	/**
	 * Whether a plugin named for offloading moves something other than media.
	 *
	 * @param string $name Its folder, or a callback's name.
	 * @return bool
	 */
	private static function moves_no_media( $name ) {
		$squashed = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $name ) );
		foreach ( self::NOT_OFFLOADERS as $not ) {
			if ( false !== strpos( $squashed, $not ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Page cache plugins that are active, for the screen to name.
	 *
	 * @return string[]
	 */
	public static function page_caches() {
		$found = array();
		foreach ( self::PAGE_CACHES as $constant => $name ) {
			if ( defined( $constant ) ) {
				$found[] = $name;
			}
		}
		return $found;
	}

	/**
	 * How long the files of a photo whose record has just changed wait.
	 *
	 * A page a cache saved before the change still offers the copy in srcset,
	 * and a high-DPI phone on a product page picks it; once its file is gone
	 * the browser shows a broken photo, and does not fall back to the src. The
	 * caches that can be asked are purged when the records are done (see
	 * purge_page_caches()), and on top of that the files wait as long as a
	 * page cache that says how long it keeps pages keeps them, and an hour
	 * more: never less than a day, never more than FILES_AFTER_MAX_SECONDS.
	 *
	 * @return int Seconds.
	 */
	public static function files_delay() {
		$ttl = self::cache_ttl();

		return (int) min( self::FILES_AFTER_MAX_SECONDS, max( self::FILES_AFTER_SECONDS, $ttl > 0 ? $ttl + HOUR_IN_SECONDS : 0 ) );
	}

	/**
	 * The longest an active page cache that says so keeps a page, or 0.
	 *
	 * LiteSpeed Cache - which Hostinger and other LiteSpeed hosts install as
	 * standard - keeps public pages a week unless the shop changed it. WP
	 * Rocket clears its cache every ten hours by default, and never when its
	 * interval is set to none, taken here as the longest wait.
	 *
	 * @return int Seconds.
	 */
	private static function cache_ttl() {
		$ttl = 0;

		if ( defined( 'LSCWP_V' ) ) {
			$lite = get_option( 'litespeed.conf.cache-ttl_pub', self::LITESPEED_DEFAULT_TTL );
			$ttl  = max( $ttl, is_numeric( $lite ) ? (int) $lite : self::LITESPEED_DEFAULT_TTL );
		}

		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			$rocket = get_option( 'wp_rocket_settings', array() );
			$rocket = is_array( $rocket ) ? $rocket : array();
			$every  = (int) ( $rocket['purge_cron_interval'] ?? 10 );
			$units  = array( 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400 );
			$unit   = $units[ (string) ( $rocket['purge_cron_unit'] ?? 'HOUR_IN_SECONDS' ) ] ?? 3600;
			$ttl    = max( $ttl, $every > 0 ? $every * $unit : self::FILES_AFTER_MAX_SECONDS );
		}

		return $ttl;
	}

	/**
	 * Ask the page caches that can be asked to forget every page, now that no
	 * photo's record offers the copy: what they draw again offers the photo
	 * itself in its place.
	 *
	 * Through each one's own public way (PAGE_CACHE_PURGES), only where it is
	 * there to be asked. One that throws is named in the log, and the rest are
	 * asked all the same.
	 *
	 * @return array{0:string[],1:string[]} The caches asked, and those that threw.
	 */
	private static function purge_page_caches() {
		$asked  = array();
		$failed = array();

		foreach ( self::PAGE_CACHE_PURGES as $name => $way ) {
			try {
				if ( 'action' === $way[0] && has_action( $way[1] ) ) {
					do_action( $way[1] );
					$asked[] = $name;
				} elseif ( 'function' === $way[0] && function_exists( $way[1] ) ) {
					call_user_func( $way[1] );
					$asked[] = $name;
				}
			} catch ( \Throwable $e ) {
				$failed[] = $name;
				TCGiant_Sync_Logger::warning( sprintf(
					'Large photo copies: asking %1$s to purge its page cache ran into an error (%2$s). Purge it by hand before the copies\' files go.',
					$name,
					$e->getMessage()
				) );
			}
		}

		return array( $asked, $failed );
	}

	/**
	 * Purge what can be purged as the records are done, and say so: which
	 * caches were purged, when the files go, and what the shop must purge by
	 * hand before then.
	 *
	 * @param array $run State. Changed in place.
	 * @return void
	 */
	private static function records_done( array &$run ) {
		list( $purged, $failed ) = self::purge_page_caches();

		// Those found that cannot be asked - Cloudflare's plugin, say - and
		// those that threw when asked.
		$unpurged = array_values( array_unique( array_merge( $failed, array_diff( self::page_caches(), $purged ) ) ) );
		$when     = wp_date( 'j M Y, H:i', (int) $run['files_due'] );

		$run['purged']   = $purged;
		$run['unpurged'] = $unpurged;

		TCGiant_Sync_Logger::log( sprintf(
			'Large photo copies: every photo\'s record is done, so pages drawn from now on no longer offer the old 1,536 px copy. %1$s The copies\' files are deleted from %2$s, once pages saved before have had time to expire. If your site is behind a CDN or another cache that keeps whole pages, purge it before then: until it is, a page it saved still offers the copy, and once the file is gone a visitor\'s browser can show a broken photo.',
			$purged ? sprintf( 'Purged the page cache of %s.', implode( ', ', $purged ) ) : 'No page cache plugin TCGiant can purge was found.',
			$when
		) );

		if ( $unpurged ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Large photo copies: %1$s cannot be purged by TCGiant. Purge it by hand now, and in any case before %2$s, when the old copies\' files are deleted.',
				implode( ', ', $unpurged ),
				$when
			) );
		}
	}

	// ───────────────────────────────────────────────────────────────────────────
	// The check
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * The check as kept.
	 *
	 * @return array
	 */
	public static function check() {
		$check = get_option( self::CHECK_OPTION, array() );
		return is_array( $check ) ? $check : array();
	}

	/**
	 * The check as the table holds it now, past any copy this request keeps.
	 *
	 * @return array
	 */
	private static function stored_check() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::CHECK_OPTION ) );
		$check = null === $raw ? array() : maybe_unserialize( $raw );

		return is_array( $check ) ? $check : array();
	}

	/**
	 * Begin a new check, forgetting the last one.
	 *
	 * @return array|WP_Error The new state.
	 */
	public static function start_check() {
		global $wpdb;

		if ( self::under_way() ) {
			return new WP_Error( 'busy', __( 'The removal of the large copies is still under way. Check again once it has finished.', 'tcgiant-sync' ) );
		}

		$offloaders = self::offloaders();
		if ( $offloaders ) {
			return new WP_Error( 'offloaded', self::offloaded_message( $offloaders ) );
		}

		$check = array(
			'id'             => wp_generate_uuid4(),
			'status'         => 'scanning',
			'cursor'         => 0,
			// Photos downloaded after this are 3.27.4's, without the copies.
			'max_id'         => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'examined'       => 0,
			'photos'         => 0,
			'files'          => 0,
			'bytes'          => 0,
			'not_here'       => 0,
			'not_here_files' => 0,
			'shared'         => 0,
			'gif'            => 0,
			'large'          => 0,
			'busy'           => 0,
			'started_at'     => time(),
			'finished_at'    => 0,
		);

		update_option( self::CHECK_OPTION, $check, false );

		return $check;
	}

	/**
	 * Carry the check on until the deadline.
	 *
	 * The photos are walked in ID order and each page judged by judge(), as
	 * the removal will judge it. Nothing is changed. A read the database fails
	 * ends the step with nothing of its page counted, and the check carries on
	 * from the same place next time.
	 *
	 * @param float $deadline Seconds into the request at which to stop.
	 * @return array The state.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	public static function check_step( $deadline ) {
		$check = self::check();
		if ( 'scanning' !== ( $check['status'] ?? '' ) ) {
			return $check;
		}

		$steps = 0;
		do {
			$from = (int) $check['cursor'];

			if ( $from >= (int) $check['max_id'] ) {
				$check['status']      = 'found';
				$check['finished_at'] = time();
				update_option( self::CHECK_OPTION, $check, false );
				self::log_found( $check );
				break;
			}

			list( $ids, $next ) = self::candidates( $from, (int) $check['max_id'] );

			$plans = $ids ? self::judge( $ids ) : array();
			foreach ( $plans as $plan ) {
				self::count_plan( $check, $plan );
			}

			// Only if no other request has moved this check on meanwhile: a
			// step the browser gave up waiting for, still running, and the one
			// it asked for instead would count the same page twice.
			$stored = self::stored_check();
			if ( ( $stored['id'] ?? '' ) !== $check['id'] || 'scanning' !== ( $stored['status'] ?? '' ) || (int) ( $stored['cursor'] ?? -1 ) !== $from ) {
				if ( function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( self::CHECK_OPTION, 'options' );
				}
				return $stored;
			}

			$check['cursor'] = max( $from + 1, (int) $next );
			update_option( self::CHECK_OPTION, $check, false );
			$steps++;

			TCGiant_Sync_Runtime::release_memory();
		} while ( TCGiant_Sync_Runtime::elapsed() < $deadline );

		return $check;
	}

	/**
	 * Add one photo's plan to the check's totals.
	 *
	 * @param array $check State.
	 * @param array $plan  From judge().
	 * @return void
	 */
	private static function count_plan( array &$check, array $plan ) {
		$check['examined']++;

		switch ( $plan['why'] ) {
			case '':
				$check['photos']++;
				$check['files']  += count( $plan['files'] );
				$check['bytes']  += (int) array_sum( $plan['files'] );
				$check['shared'] += count( array_filter( $plan['kept'], function ( $why ) {
					return 'shared' === $why;
				} ) );
				break;
			case 'not_here':
				$check['not_here']++;
				$check['not_here_files'] += max( 1, count( $plan['files'] ) );
				break;
			case 'gif':
				$check['gif']++;
				break;
			case 'large':
				$check['large']++;
				break;
			case 'marked':
			case 'pending':
				$check['busy']++;
				break;
		}
	}

	/**
	 * The line a finished check writes.
	 *
	 * @param array $check State.
	 * @return void
	 */
	private static function log_found( array $check ) {
		TCGiant_Sync_Logger::log( sprintf(
			'Large photo copies: checked the photos TCGiant downloaded. %d still have WordPress\'s old 1,536 px copy: %d file(s), about %s on this server.%s%s%s',
			(int) $check['photos'],
			(int) $check['files'],
			size_format( (int) $check['bytes'], 1 ),
			$check['not_here'] > 0 ? sprintf( ' %d more are left as they are, because their files are not on this server (an offload plugin may have moved them).', (int) $check['not_here'] ) : '',
			$check['shared'] > 0 ? sprintf( ' %d more file(s) are shared with another Media Library entry, and not counted: each is deleted only once nothing else uses it.', (int) $check['shared'] ) : '',
			( $check['gif'] + $check['large'] ) > 0 ? sprintf( ' %d keep their copies because they are GIFs or larger than 2,048 px.', (int) ( $check['gif'] + $check['large'] ) ) : ''
		) );
	}

	/**
	 * The shop has seen the check and asked for the copies to go.
	 *
	 * @param string $check_id The check the screen showed, so that a page left
	 *                         open from an older check cannot start a removal
	 *                         on a newer one the shop never saw.
	 * @return array|WP_Error The removal's state.
	 */
	public static function approve( $check_id ) {
		$check = self::check();

		if ( self::under_way() ) {
			return new WP_Error( 'busy', __( 'The removal of the large copies is already under way.', 'tcgiant-sync' ) );
		}

		if ( 'found' !== ( $check['status'] ?? '' ) || (string) $check_id !== (string) ( $check['id'] ?? '' ) ) {
			return new WP_Error( 'stale', __( 'This check is no longer the latest. Reload the page and check again.', 'tcgiant-sync' ) );
		}

		if ( time() - (int) $check['finished_at'] > self::CHECK_VALID_SECONDS ) {
			return new WP_Error( 'old', __( 'This check is more than a week old. Check again before removing anything.', 'tcgiant-sync' ) );
		}

		if ( (int) $check['photos'] < 1 ) {
			return new WP_Error( 'nothing', __( 'The check found nothing to remove.', 'tcgiant-sync' ) );
		}

		$offloaders = self::offloaders();
		if ( $offloaders ) {
			return new WP_Error( 'offloaded', self::offloaded_message( $offloaders ) );
		}

		$run = self::fresh_run( $check );
		update_option( self::RUN_OPTION, $run, false );
		delete_option( self::PAUSE_OPTION );

		$user = wp_get_current_user();
		TCGiant_Sync_Logger::log( sprintf(
			'Large photo copies: %1$s asked for the old 1,536 px copies found on %2$s to be removed: %3$d photo(s), %4$d file(s), about %5$s. Each photo\'s record of its sizes is changed first, in the background, so pages stop offering the copy; the files are deleted a day after that, so pages a cache saved before still show.',
			( $user && ! empty( $user->user_login ) ) ? $user->user_login : 'the shop',
			wp_date( 'j M Y, H:i', (int) $check['finished_at'] ),
			(int) $check['photos'],
			(int) $check['files'],
			size_format( (int) $check['bytes'], 1 )
		) );

		return $run;
	}

	/**
	 * A removal's state, before it begins.
	 *
	 * @param array $check The check it is for.
	 * @return array
	 */
	private static function fresh_run( array $check ) {
		return array(
			'check'          => (string) ( $check['id'] ?? '' ),
			'status'         => 'running', // running, waiting (for the files' day), done.
			'cursor'         => 0,
			'max_id'         => (int) ( $check['max_id'] ?? 0 ),
			'note_cursor'    => 0,
			'at'             => 0,         // The photo the last run was working on.
			'at_time'        => 0,
			'deaths'         => array(),   // Photo => runs it died in before its note was written.
			'retry'          => array(),   // Photos whose record is still to be changed.
			'photos'         => 0,         // Records changed.
			'files_waiting'  => 0,
			'bytes_waiting'  => 0,
			'deleted'        => 0,
			'bytes'          => 0,
			'gone'           => 0,         // Files already gone when their day came.
			'left_files'     => 0,         // Files that could not be deleted.
			'left_bytes'     => 0,
			'shared'         => 0,
			'not_here'       => 0,
			'given_up'       => 0,
			'with_photo'     => 0,
			'purged'         => array(),   // Page caches purged as the records were done.
			'unpurged'       => array(),   // Page caches found that could not be.
			'started_at'     => time(),
			'approved_by'    => get_current_user_id(),
			'records_done_at' => 0,
			'files_due'      => 0,
			'finished_at'    => 0,
			'last_run'       => 0,
		);
	}

	// ───────────────────────────────────────────────────────────────────────────
	// The removal
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * The removal's state.
	 *
	 * @return array
	 */
	public static function run_state() {
		$run = get_option( self::RUN_OPTION, array() );
		return is_array( $run ) ? $run : array();
	}

	/**
	 * Keep the removal's state.
	 *
	 * @param array $run State.
	 * @return void
	 */
	private static function save_run( array $run ) {
		update_option( self::RUN_OPTION, $run, false );
	}

	/**
	 * Whether a removal has been asked for and is not finished.
	 *
	 * @return bool
	 */
	public static function under_way() {
		return in_array( self::run_state()['status'] ?? '', array( 'running', 'waiting' ), true );
	}

	/**
	 * Work through the removal, a batch at a time. Called by WP-Cron.
	 *
	 * Nothing it meets may end the request: WP-Cron runs every event that is
	 * due in one request, and an error escaping from here would stop the eBay
	 * sync behind it too.
	 *
	 * @return void
	 */
	public function process_run() {
		// A run PHP kills leaves a line saying how, and on which photo, and lets
		// go of the removal so the run after it need not wait for the hold.
		TCGiant_Sync_Runtime::watch( self::JOB, array( __CLASS__, 'release_all_locks' ) );

		try {
			self::run();
		} catch ( TCGiant_Sync_Photo_Write_Error $e ) {
			TCGiant_Sync_Logger::error( sprintf(
				'Large photo copies stopped: the database did not keep a change the removal relies on (%1$s). This is often a full disk on the database server. Nothing more was changed in this run; the photo it was working on carries on from where it stopped in about %2$d minutes.',
				$e->getMessage(),
				(int) ceil( self::FALLBACK_SECONDS / 60 )
			) );
			self::fall_back();
		} catch ( TCGiant_Sync_Photo_Read_Error $e ) {
			TCGiant_Sync_Logger::error( sprintf(
				'Large photo copies stopped: the database did not answer (%1$s). Nothing more was changed in this run - nothing is changed on the strength of a read the database failed. It carries on in about %2$d minutes.',
				$e->getMessage(),
				(int) ceil( self::FALLBACK_SECONDS / 60 )
			) );
			self::fall_back();
		} catch ( \Throwable $e ) {
			TCGiant_Sync_Logger::error( sprintf(
				'Large photo copies stopped on an error (%1$s, in %2$s line %3$d). Nothing more was changed in this run; it carries on in about %4$d minutes.',
				$e->getMessage(),
				basename( $e->getFile() ),
				$e->getLine(),
				(int) ceil( self::FALLBACK_SECONDS / 60 )
			) );
			self::fall_back();
		} finally {
			TCGiant_Sync_Runtime::unwatch();
		}
	}

	/**
	 * After a run that stopped on an error: the next one, if none is set.
	 *
	 * @return void
	 */
	private static function fall_back() {
		if ( ! wp_next_scheduled( self::TRIM_HOOK ) ) {
			wp_schedule_single_event( time() + self::FALLBACK_SECONDS, self::TRIM_HOOK );
		}
	}

	/**
	 * The body of process_run(), watched by it.
	 *
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer,
	 *                                       or did not keep a change.
	 */
	private static function run() {
		if ( ! self::take_lock( 'run', self::LOCK_SECONDS ) ) {
			// Another run is working. Come back once its hold would have
			// lapsed, in case it was killed before it could plan the next.
			wp_schedule_single_event( time() + self::LOCK_SECONDS + 10, self::TRIM_HOOK );
			return;
		}

		try {
			$run = self::run_state();
			if ( ! in_array( $run['status'] ?? '', array( 'running', 'waiting' ), true ) ) {
				return; // Nothing asked for, or all done.
			}

			TCGiant_Sync_Runtime::raise_time_limit( 300 );

			// A run for after this one, in case this one is killed. Replaced
			// at the end when it is not.
			wp_clear_scheduled_hook( self::TRIM_HOOK );
			wp_schedule_single_event( time() + self::FALLBACK_SECONDS, self::TRIM_HOOK );

			// Nothing at all while an offload plugin is at work. It carries on
			// by itself once it is not.
			$offloaders = self::offloaders();
			if ( $offloaders ) {
				self::say_offloaded( $offloaders );
				wp_clear_scheduled_hook( self::TRIM_HOOK );
				wp_schedule_single_event( time() + self::BLOCKED_SECONDS, self::TRIM_HOOK );
				return;
			}

			$paused = self::paused_until( $run );
			if ( $paused > time() ) {
				self::save_run( $run );
				wp_clear_scheduled_hook( self::TRIM_HOOK );
				wp_schedule_single_event( $paused + 5, self::TRIM_HOOK );
				return;
			}

			// The files' day has not come: nothing to do yet. (A pass under
			// way carries on: what was due when it began still is.)
			if ( 'waiting' === $run['status'] && (int) $run['note_cursor'] < 1 && (int) $run['files_due'] > time() ) {
				self::save_run( $run );
				wp_clear_scheduled_hook( self::TRIM_HOOK );
				wp_schedule_single_event( (int) $run['files_due'] + 5, self::TRIM_HOOK );
				return;
			}

			// Begun with this request's time already spent, in a WP-Cron
			// request another job has used: come back soon, in another.
			if ( '' !== TCGiant_Sync_Runtime::over_time_budget() ) {
				self::save_run( $run );
				wp_clear_scheduled_hook( self::TRIM_HOOK );
				wp_schedule_single_event( time() + self::NEXT_RUN_SECONDS, self::TRIM_HOOK );
				return;
			}

			$done = self::empty_tally();
			try {
				if ( 'running' === $run['status'] ) {
					self::change_records( $run, $done );
				} else {
					self::delete_waiting( $run, $done );
				}
			} finally {
				// However the run ended short of being killed: it is not on a
				// photo any more, and it counts as a run that finished. See
				// paused_until().
				$run['at']       = 0;
				$run['at_time']  = 0;
				$run['last_run'] = time();
				self::add_tally( $run, $done );
				self::save_run( $run );
				self::log_run( $run, $done );
			}

			wp_clear_scheduled_hook( self::TRIM_HOOK );
			self::schedule_next( $run );
		} finally {
			self::release_lock( 'run' );
		}
	}

	/**
	 * A run's counts, before it begins.
	 *
	 * @return array
	 */
	private static function empty_tally() {
		return array(
			'photos'        => 0,
			'files_waiting' => 0,
			'bytes_waiting' => 0,
			'deleted'       => 0,
			'bytes'         => 0,
			'gone'          => 0,
			'left_files'    => 0,
			'left_bytes'    => 0,
			'shared'        => 0,
			'not_here'      => 0,
			'given_up'      => 0,
			'stopped'       => '',
		);
	}

	/**
	 * Add a run's counts to the removal's totals.
	 *
	 * @param array $run  State.
	 * @param array $done The run's counts.
	 * @return void
	 */
	private static function add_tally( array &$run, array $done ) {
		foreach ( $done as $key => $value ) {
			if ( is_int( $value ) ) {
				$run[ $key ] = (int) ( $run[ $key ] ?? 0 ) + $value;
			}
		}
	}

	/**
	 * The first part: each photo's record of its sizes, photo by photo in ID
	 * order, until the run's time is up.
	 *
	 * A photo a killed run left part way is finished first - its record
	 * changed, if its note says it was not yet - before anything new.
	 *
	 * @param array $run  State. Changed in place.
	 * @param array $done The run's counts. Changed in place.
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer,
	 *                                       or did not keep a change.
	 */
	private static function change_records( array &$run, array &$done ) {
		$started = 0;

		// First, what a run before left part way. retry is kept small: a
		// photo goes on it only when its record could not be changed, and
		// comes off as it is taken up again - finish_record() puts it back
		// if it still cannot be.
		foreach ( self::ids( (array) $run['retry'] ) as $id ) {
			if ( $started++ > 0 ) {
				$done['stopped'] = TCGiant_Sync_Runtime::over_budget();
				if ( '' !== $done['stopped'] ) {
					return;
				}
			}

			$run['retry']   = array_values( array_diff( self::ids( (array) $run['retry'] ), array( $id ) ) );
			$run['at']      = $id;
			$run['at_time'] = time();
			self::save_run( $run );
			self::working_on( $id );

			self::finish_first( $run, $done, $id, function () use ( $id ) {
				return self::judge( array( $id ) )[ $id ];
			} );
		}

		while ( (int) $run['cursor'] < (int) $run['max_id'] ) {
			list( $ids, $next ) = self::candidates( (int) $run['cursor'], (int) $run['max_id'] );

			// A run killed while it reads a page is known by its first photo.
			if ( $ids ) {
				$run['at']      = $ids[0];
				$run['at_time'] = time();
				self::save_run( $run );
			}

			$plans = $ids ? self::judge( $ids ) : array();

			foreach ( $ids as $id ) {
				// Always at least one, so a run that began late still moves.
				if ( $started++ > 0 ) {
					$done['stopped'] = TCGiant_Sync_Runtime::over_budget();
					if ( '' !== $done['stopped'] ) {
						return;
					}
				}

				// The photo this run is on, written before anything of it is
				// touched, so that a run killed on it is known by the next.
				$run['at']      = $id;
				$run['at_time'] = time();
				self::save_run( $run );

				self::working_on( $id );
				$plan = $plans[ $id ];
				self::finish_first( $run, $done, $id, function () use ( $plan ) {
					return $plan;
				} );

				unset( $run['deaths'][ $id ] );
				$run['cursor'] = $id;
			}

			$run['cursor'] = max( (int) $run['cursor'], (int) $next );
			self::save_run( $run );
			TCGiant_Sync_Runtime::release_memory();
		}

		if ( empty( $run['retry'] ) ) {
			// Every record changed: the files go once the last of them has
			// waited its time, and the page caches are purged now.
			$run['status']          = 'waiting';
			$run['records_done_at'] = time();
			$run['files_due']       = max( (int) $run['files_due'], time() + self::files_delay() );
			$run['note_cursor']     = 0;
			self::records_done( $run );
		}
	}

	/**
	 * finish_note(), and if the database fails it part way, the photo is put
	 * first for the next run: once its record has been changed the walk no
	 * longer finds it, and its note would otherwise wait for the second part.
	 *
	 * @param array    $run  State. Changed in place.
	 * @param array    $done The run's counts. Changed in place.
	 * @param int      $id   Attachment ID.
	 * @param callable $plan Gives its plan, from judge().
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer,
	 *                                       or did not keep a change.
	 */
	private static function finish_first( array &$run, array &$done, $id, $plan ) {
		try {
			self::finish_note( $run, $done, $id, call_user_func( $plan ) );
		} catch ( TCGiant_Sync_Photo_Read_Error $e ) {
			$run['retry'] = self::ids( array_merge( (array) $run['retry'], array( $id ) ) );
			throw $e;
		}
	}

	/**
	 * Do what is due for one photo in the first part.
	 *
	 * @param array $run  State. Changed in place.
	 * @param array $done The run's counts. Changed in place.
	 * @param int   $id   Attachment ID.
	 * @param array $plan From judge().
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer,
	 *                                       or did not keep a change.
	 */
	private static function finish_note( array &$run, array &$done, $id, array $plan ) {
		$why  = (string) $plan['why'];
		$note = is_array( $plan['note'] ?? null ) ? $plan['note'] : null;

		if ( 'pending' === $why ) {
			// Left part way by a run before: its record still to change.
			if ( 'meta' === ( $note['stage'] ?? '' ) ) {
				self::tally( $done, self::resume_note( $run, $id, $note ) );
			}
			return;
		}

		if ( '' !== $why ) {
			if ( 'not_here' === $why ) {
				$done['not_here']++;
			}
			return;
		}

		self::tally( $done, self::trim_one( $run, $id, $plan ) );
	}

	/**
	 * Add what was done to one photo to the run's counts.
	 *
	 * @param array $done   The run's counts. Changed in place.
	 * @param array $result What was done.
	 * @return void
	 */
	private static function tally( array &$done, array $result ) {
		foreach ( $result as $key => $value ) {
			if ( is_int( $value ) && isset( $done[ $key ] ) ) {
				$done[ $key ] += $value;
			}
		}
	}

	/**
	 * Take one photo's large copies out of its record, leaving their files
	 * for their day. Steps 1 and 2 of the removal; see the class.
	 *
	 * @param array $run  State. Changed in place: a photo whose record could
	 *                    not be changed for another request's writing to it
	 *                    goes on its retry list.
	 * @param int   $id   Attachment ID.
	 * @param array $plan From judge(), with nothing against it.
	 * @return int[] Counts: photos, files_waiting, bytes_waiting, shared, given_up.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer,
	 *                                       or did not keep the note or the
	 *                                       changed record.
	 */
	private static function trim_one( array &$run, $id, array $plan ) {
		global $wpdb;

		// Every file of the sizes going, even one something else names now:
		// whether each may be deleted is asked again on its day, and another
		// record of the same photo that names a file today may have had its
		// own copy taken off by then.
		$note = array(
			'stage' => 'meta',
			'sizes' => array_values( $plan['sizes'] ),
			'files' => array_values( array_unique( array_merge( array_map( 'strval', array_keys( $plan['files'] ) ), array_map( 'strval', array_keys( $plan['kept'] ) ) ) ) ),
			'dir'   => (string) $plan['rel_dir'],
			'at'    => time(),
			'tries' => 1,
		);

		// 1. What is going, written and read back before anything is touched.
		TCGiant_Sync_Runtime::stage( 'writing the note of what is going' );
		add_post_meta( $id, self::PENDING_KEY, wp_slash( $note ), true );
		self::confirm_note( $id, $note, (string) $wpdb->last_error );

		return self::finish_record( $run, $id, $note, array(
			'shared'        => count( array_filter( $plan['kept'], function ( $why ) {
				return 'shared' === $why;
			} ) ),
			'files_waiting' => count( $plan['files'] ),
			'bytes_waiting' => (int) array_sum( $plan['files'] ),
		) );
	}

	/**
	 * Carry on with a photo a run before left with its record still to change.
	 *
	 * @param array $run  State. Changed in place.
	 * @param int   $id   Attachment ID.
	 * @param array $note Its note.
	 * @return int[] Counts.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer,
	 *                                       or did not keep a change.
	 */
	private static function resume_note( array &$run, $id, array $note ) {
		global $wpdb;

		$tries = (int) ( $note['tries'] ?? 0 );
		if ( $tries >= self::MAX_TRIES ) {
			self::give_up( $id, $note, 'stopped' );
			return array( 'given_up' => 1 );
		}

		// Counted before the work, so a photo that kills every run is left
		// after MAX_TRIES rather than heading every run for ever.
		$note['tries'] = $tries + 1;
		update_post_meta( $id, self::PENDING_KEY, wp_slash( $note ) );
		self::confirm_note( $id, $note, (string) $wpdb->last_error );

		return self::finish_record( $run, $id, $note, array( 'bytes_waiting' => 0 ) );
	}

	/**
	 * Step 2, and the note moved on to the files' day.
	 *
	 * @param array $run   State. Changed in place.
	 * @param int   $id    Attachment ID.
	 * @param array $note  Its note, at the meta stage.
	 * @param array $extra Counts to add when it is done.
	 * @return int[] Counts.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer,
	 *                                       or did not keep a change.
	 */
	private static function finish_record( array &$run, $id, array $note, array $extra ) {
		global $wpdb;

		// 2. The sizes out of the photo's record: srcset stops offering them.
		TCGiant_Sync_Runtime::stage( 'taking the large copies out of its record' );
		$changed = self::drop_sizes( $id, (array) $note['sizes'] );

		if ( 'odd' === $changed ) {
			self::give_up( $id, $note, 'odd' );
			return array( 'given_up' => 1 );
		}

		if ( 'busy' === $changed ) {
			// Something else kept writing to it. Its note stays at the meta
			// stage, and it is tried again first in the next run.
			$run['retry'] = self::ids( array_merge( (array) $run['retry'], array( $id ) ) );
			return array();
		}

		// The files wait for their day.
		$note['stage'] = 'files';
		$note['due']   = time() + self::files_delay();
		update_post_meta( $id, self::PENDING_KEY, wp_slash( $note ) );
		self::confirm_note( $id, $note, (string) $wpdb->last_error );

		$run['files_due'] = max( (int) $run['files_due'], (int) $note['due'] );

		return array_merge( array(
			'photos'        => 1,
			'files_waiting' => count( (array) $note['files'] ),
		), $extra );
	}

	/**
	 * Take sizes out of a photo's record of them.
	 *
	 * Written straight to its row, over the record exactly as it was read:
	 * another request writing the record meanwhile - WooCommerce making one of
	 * its own sizes on the fly, say - is not written over, and the change is
	 * read again and made again. Not through wp_update_attachment_metadata(),
	 * whose filter image optimisers and offload plugins re-process a photo
	 * from. Read back before anything depends on it.
	 *
	 * @param int      $id    Attachment ID.
	 * @param string[] $sizes Sizes to take out.
	 * @return string done (none of them is in it now), odd (it has no single
	 *                readable record) or busy (another request kept changing it).
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer,
	 *                                       or did not keep the change.
	 */
	private static function drop_sizes( $id, array $sizes ) {
		global $wpdb;

		$wrote = false;

		for ( $try = 0; $try < 3; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attachment_metadata' ORDER BY meta_id ASC",
				$id
			) );
			self::read_ok();

			$meta = 1 === count( $rows ) ? maybe_unserialize( $rows[0]->meta_value ) : null;
			if ( ! is_array( $meta ) ) {
				return 'odd';
			}

			$listed  = is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array();
			$present = array_values( array_intersect( $sizes, array_map( 'strval', array_keys( $listed ) ) ) );
			if ( empty( $present ) ) {
				return 'done';
			}

			if ( $wrote ) {
				// The database said it took the change, and the sizes are
				// still there.
				throw new TCGiant_Sync_Photo_Write_Error( sprintf( 'the change to the record of photo #%d\'s sizes was not kept: the database gave no reason', $id ) );
			}

			foreach ( $present as $name ) {
				unset( $meta['sizes'][ $name ] );
			}

			// Byte for byte on MySQL and MariaDB, whose usual collations
			// compare text without regard to case: a change by case alone -
			// another plugin lower-casing a file name - would otherwise match
			// and be written over.
			$exact = ! empty( $wpdb->is_mysql ) ? 'BINARY ' : '';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$changed = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_id = %d AND {$exact}meta_value = %s",
				maybe_serialize( $meta ),
				(int) $rows[0]->meta_id,
				(string) $rows[0]->meta_value
			) );
			$refused = (string) $wpdb->last_error;

			// What WordPress's own update_metadata() does after a write.
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $id, 'post_meta' );
			}
			if ( function_exists( 'wp_cache_set_posts_last_changed' ) ) {
				wp_cache_set_posts_last_changed();
			}

			if ( '' !== $refused ) {
				throw new TCGiant_Sync_Photo_Write_Error( sprintf( 'the change to the record of photo #%d\'s sizes was not kept: %s', $id, $refused ) );
			}

			// Nothing changed: another request wrote the record after it was
			// read. Read it again.
			$wrote = (int) $changed > 0;
		}

		return 'busy';
	}

	/**
	 * The second part: the files of each photo whose record was changed, once
	 * their day has come.
	 *
	 * Every note is read, in order. A note still at the meta stage - a photo
	 * a run left part way - is finished first, and its files wait for their
	 * own day, so another pass follows then.
	 *
	 * @param array $run  State. Changed in place.
	 * @param array $done The run's counts. Changed in place.
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer,
	 *                                       or did not keep a change.
	 */
	private static function delete_waiting( array &$run, array &$done ) {
		global $wpdb;

		$started = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
				self::PENDING_KEY,
				(int) $run['note_cursor'],
				self::NOTE_PAGE
			) );
			self::read_ok();

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( $started++ > 0 ) {
					$done['stopped'] = TCGiant_Sync_Runtime::over_budget();
					if ( '' !== $done['stopped'] ) {
						return;
					}
				}

				$id    = (int) $row->post_id;
				$note  = maybe_unserialize( $row->meta_value );
				$note  = is_array( $note ) ? $note : array( 'stage' => '' );
				$stage = (string) ( $note['stage'] ?? '' );
				$act   = 'meta' === $stage || ( 'files' === $stage && (int) ( $note['due'] ?? 0 ) <= time() );

				if ( $act ) {
					// The photo this run is on. See change_records().
					$run['at']      = $id;
					$run['at_time'] = time();
					self::save_run( $run );
					self::working_on( $id );

					if ( 'meta' === $stage ) {
						self::tally( $done, self::resume_note( $run, $id, $note ) );
					} else {
						self::tally( $done, self::delete_files( $id, $note ) );
					}
				}

				$run['note_cursor'] = (int) $row->meta_id;
			}

			self::save_run( $run );
			TCGiant_Sync_Runtime::release_memory();
		}

		// One pass over every note. A photo whose files' day came after the
		// pass began - finished late, or left for another request - goes on
		// the next pass, on that day. The notes are what say so: the retry
		// list is the first part's.
		$run['note_cursor'] = 0;
		$run['retry']       = array();
		$later              = self::next_due();

		if ( $later > 0 ) {
			$run['files_due'] = max( $later, time() + self::NEXT_RUN_SECONDS );
			return;
		}

		$run['status']      = 'done';
		$run['finished_at'] = time();
	}

	/**
	 * When the next photo's files are due, from the notes still waiting, or 0.
	 *
	 * @return int
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function next_due() {
		global $wpdb;

		$next  = 0;
		$after = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
				self::PENDING_KEY,
				$after,
				self::NOTE_PAGE
			) );
			self::read_ok();

			foreach ( $rows as $row ) {
				$after = (int) $row->meta_id;
				$note  = maybe_unserialize( $row->meta_value );
				$stage = is_array( $note ) ? (string) ( $note['stage'] ?? '' ) : '';
				if ( 'files' === $stage || 'meta' === $stage ) {
					$due  = 'files' === $stage ? (int) ( $note['due'] ?? 0 ) : time();
					$next = 0 === $next ? $due : min( $next, $due );
				}
			}
		} while ( count( $rows ) === self::NOTE_PAGE );

		return $next;
	}

	/**
	 * Steps 3 and 4 for one photo: its files, then its note.
	 *
	 * Everything is asked again first. A file the photo's record names again
	 * - a tool that made the size again - or another Media Library entry
	 * names now is kept. So is every file of a photo whose file has moved to
	 * another folder since. A file that is already gone counts for nothing.
	 *
	 * @param int   $id   Attachment ID.
	 * @param array $note Its note, at the files stage.
	 * @return int[] Counts: deleted, bytes, gone, left_files, left_bytes,
	 *               shared, given_up.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer,
	 *                                       or did not keep the count of tries.
	 */
	private static function delete_files( $id, array $note ) {
		global $wpdb;

		$tries = (int) ( $note['tries'] ?? 0 );
		if ( $tries >= self::MAX_TRIES ) {
			self::give_up( $id, $note, 'stopped' );
			return array( 'given_up' => 1 );
		}

		$note['tries'] = $tries + 1;
		update_post_meta( $id, self::PENDING_KEY, wp_slash( $note ) );
		self::confirm_note( $id, $note, (string) $wpdb->last_error );

		// Insurance against a persistent object cache still holding the
		// record as it was before step 2: WordPress reads it again from the
		// table, without the copy, before the copy's file goes.
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $id, 'post_meta' );
		}

		TCGiant_Sync_Runtime::stage( 'deleting its large copies' );
		$result = self::delete_noted( $id, $note );

		if ( isset( $result['moved'] ) || isset( $result['odd'] ) ) {
			self::give_up( $id, $note, isset( $result['moved'] ) ? 'moved' : 'odd' );
			return array( 'given_up' => 1 );
		}

		// 4. Done with.
		delete_post_meta( $id, self::PENDING_KEY );

		return $result;
	}

	/**
	 * Delete the files a note names that nothing names now.
	 *
	 * @param int   $id   Attachment ID.
	 * @param array $note Its note.
	 * @return int[] Counts: deleted, bytes, gone, left_files, left_bytes,
	 *               shared; and moved when its file is in another folder now.
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function delete_noted( $id, array $note ) {
		$out = array( 'deleted' => 0, 'bytes' => 0, 'gone' => 0, 'left_files' => 0, 'left_bytes' => 0, 'shared' => 0 );

		$record = self::records( array( $id ) )[ $id ];
		if ( ! $record['found'] || '' === $record['file'] ) {
			return $out + array( 'moved' => 1 );
		}
		if ( ! is_array( $record['meta'] ) || 1 !== $record['meta_rows'] ) {
			return $out + array( 'odd' => 1 );
		}

		if ( strtolower( self::rel_dir( $record['file'] ) ) !== strtolower( (string) ( $note['dir'] ?? '' ) ) ) {
			return $out + array( 'moved' => 1 );
		}

		// Its own records as they are now: a size made again is named again.
		$named = self::named_files( $record['meta'], $record['backup'], $record['file'] );
		$files = array();
		foreach ( (array) ( $note['files'] ?? array() ) as $name ) {
			$name = wp_basename( (string) $name );
			if ( '' !== $name && ! isset( $named[ strtolower( $name ) ] ) ) {
				$files[ $name ] = 0;
			}
		}

		$path  = self::main_path( $record['file'] );
		$plans = array(
			$id => array(
				'why'     => '',
				'files'   => $files,
				'kept'    => array(),
				'rel_dir' => self::rel_dir( $record['file'] ),
				'stem'    => self::stem( $record['file'] ),
			),
		);
		self::shared_elsewhere( $plans );
		$out['shared'] = count( $plans[ $id ]['kept'] );

		$dir  = dirname( $path );
		$left = array();

		foreach ( array_keys( $plans[ $id ]['files'] ) as $name ) {
			$file = $dir . '/' . $name;
			clearstatcache();
			if ( ! is_file( $file ) ) {
				$out['gone']++;
				continue;
			}

			$bytes = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( function_exists( 'wp_delete_file_from_directory' ) ) {
				wp_delete_file_from_directory( $file, $dir );
			}
			clearstatcache();

			// What really went. WordPress says nothing when it cannot delete a
			// file - the wrong owner, say - and a shop running out of disk must
			// not be told it has space it has not.
			if ( file_exists( $file ) ) {
				$left[]             = $name;
				$out['left_files']++;
				$out['left_bytes'] += $bytes;
			} else {
				$out['deleted']++;
				$out['bytes'] += $bytes;
			}
		}

		if ( $left ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Large photo copies: %1$d file(s) of photo #%2$d could not be deleted from the uploads folder (%3$s), about %4$s. Check that the web server is allowed to delete files there; nothing else will remove these.',
				count( $left ),
				$id,
				implode( ', ', array_slice( $left, 0, 3 ) ),
				size_format( $out['left_bytes'], 1 )
			) );
		}

		return $out;
	}

	/**
	 * Leave a photo as it is from here on, its note saying so.
	 *
	 * Its files are not deleted. If its record had already been changed they
	 * stay on disk, named by nothing but the note - the safe way round.
	 *
	 * @param int    $id   Attachment ID.
	 * @param array  $note Its note.
	 * @param string $why  stopped (killed or stopped MAX_TRIES times), odd (its
	 *                     record could not be read as one) or moved (its file
	 *                     is in another folder now, or gone).
	 * @return void
	 * @throws TCGiant_Sync_Photo_Read_Error When the database did not keep the note.
	 */
	private static function give_up( $id, array $note, $why ) {
		global $wpdb;

		$note['stage'] = 'given_up';
		$note['why']   = (string) $why;
		update_post_meta( $id, self::PENDING_KEY, wp_slash( $note ) );
		self::confirm_note( $id, $note, (string) $wpdb->last_error );

		$reasons = array(
			'stopped' => sprintf( 'the removal was stopped while working on it %d times', self::MAX_TRIES ),
			'odd'     => 'its record of sizes could not be read as one record',
			'moved'   => 'its file is no longer where it was',
		);

		TCGiant_Sync_Logger::warning( sprintf(
			'Large photo copies: photo #%1$d left as it is - %2$s. Its large copy (%3$s) stays on this server and will not be tried again.',
			$id,
			$reasons[ $why ] ?? $why,
			implode( ', ', array_slice( array_map( 'strval', (array) ( $note['files'] ?? array() ) ), 0, 2 ) )
		) );
	}

	/**
	 * Stop the run unless a photo's note is exactly as just written, read back
	 * from the table.
	 *
	 * wpdb does not throw when a write fails: a full disk refuses new rows
	 * while it still deletes old ones, and a deadlock leaves the write undone,
	 * each with only last_error to say so. A record changed with no note would
	 * leave its files with nothing to say they are to go.
	 *
	 * @param int    $id      Attachment ID.
	 * @param array  $note    What it must be.
	 * @param string $refused What the database said of the write, if anything.
	 * @return void
	 * @throws TCGiant_Sync_Photo_Write_Error When it is not.
	 * @throws TCGiant_Sync_Photo_Read_Error  When the database could not say.
	 */
	private static function confirm_note( $id, array $note, $refused ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
			$id,
			self::PENDING_KEY
		) );
		self::read_ok();

		if ( 1 === count( $rows ) && maybe_unserialize( $rows[0] ) === $note ) {
			return;
		}

		throw new TCGiant_Sync_Photo_Write_Error( sprintf(
			'the note of which large copies of photo #%1$d are going was not kept: %2$s',
			$id,
			'' !== $refused ? $refused : 'the database gave no reason'
		) );
	}

	/**
	 * Whether a photo has a note, asked of the table.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 * @throws TCGiant_Sync_Photo_Read_Error When the database could not answer.
	 */
	private static function has_note( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
			$id,
			self::PENDING_KEY
		) );
		self::read_ok();

		return null !== $found;
	}

	/**
	 * Say which photo the run is on, for the record PHP leaves if it kills it.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	private static function working_on( $id ) {
		TCGiant_Sync_Runtime::context( sprintf( 'photo #%d', (int) $id ) );
	}

	/**
	 * Whether the removal is paused, starting a pause if the run before this
	 * one died. See PAUSE_SECONDS.
	 *
	 * A death is known two ways, either of which is enough. The photo the run
	 * said it was on is still there when the next one starts: every run that
	 * ends any other way takes it away, so it is a death for certain, and it
	 * is taken away here once counted - a host ending a request from outside
	 * runs no shutdown function and leaves only that. And the record PHP
	 * leaves of a job it ends, counted once, and only if it came no earlier
	 * than the last run that finished.
	 *
	 * A photo killed on twice before its note was written is stepped past -
	 * left as it is - so that one photo cannot hold up the rest for ever. One
	 * with a note goes by the note's count of tries.
	 *
	 * @param array $run State. Changed in place.
	 * @return int When the pause ends, or 0.
	 */
	private static function paused_until( array &$run ) {
		$pause  = self::pause_state();
		$deaths = array();
		$newest = 0;

		if ( (int) $run['at'] > 0 ) {
			$newest   = max( 1, (int) $run['at_time'] );
			$deaths[] = $newest;
		}

		$crash = TCGiant_Sync_Runtime::last_crash( self::JOB );
		if ( null !== $crash ) {
			$deaths[] = (int) $crash['at'];
			if ( (int) $crash['at'] > (int) $pause['crash_at'] && (int) $crash['at'] >= (int) $run['last_run'] ) {
				$newest = max( $newest, (int) $crash['at'] );
			}
		}

		$on = (int) $run['at'];
		if ( $on > 0 ) {
			if ( self::has_note( $on ) ) {
				// Part way through it: finished first, by its note, whose
				// count of tries lets it go after MAX_TRIES.
				if ( 'running' === $run['status'] ) {
					$run['retry'] = self::ids( array_merge( (array) $run['retry'], array( $on ) ) );
				}
			} else {
				$run['deaths'][ $on ] = (int) ( $run['deaths'][ $on ] ?? 0 ) + 1;
				if ( $run['deaths'][ $on ] >= 2 && 'running' === $run['status'] && (int) $run['cursor'] < $on ) {
					// Stepped past, nothing of it changed.
					$run['cursor'] = $on;
					unset( $run['deaths'][ $on ] );
					TCGiant_Sync_Logger::warning( sprintf(
						'Large photo copies: photo #%d stopped the removal twice before anything of it was changed, and has been left as it is, its large copy with it.',
						$on
					) );
				}
			}
			$run['at']      = 0;
			$run['at_time'] = 0;
		}

		if ( 0 === $newest ) {
			return (int) $pause['until'];
		}

		$pause = self::start_pause( $newest, max( $deaths ) );

		TCGiant_Sync_Logger::warning( sprintf(
			'Large photo copies: paused for %1$s. The last run was stopped%2$s, and a run that is stopped takes the rest of its background request with it, the eBay sync included. The removal carries on by itself after the pause, from where it stopped, waiting longer each time this happens again.',
			self::duration( (int) $pause['seconds'] ),
			$on > 0 ? sprintf( ' while it was working on photo #%d', $on ) : ''
		) );

		return (int) $pause['until'];
	}

	/**
	 * The pause as kept: until, seconds, crash_at (the newest death counted).
	 *
	 * @return array
	 */
	private static function pause_state() {
		$pause = get_option( self::PAUSE_OPTION, array() );

		return array_merge(
			array( 'until' => 0, 'seconds' => 0, 'crash_at' => 0 ),
			is_array( $pause ) ? $pause : array()
		);
	}

	/**
	 * Pause the removal, for twice as long as last time if this comes straight
	 * after that pause.
	 *
	 * @param int $at       When the death was.
	 * @param int $crash_at The newest death counted, with this one.
	 * @return array The pause.
	 */
	private static function start_pause( $at, $crash_at ) {
		$pause = self::pause_state();

		$seconds = ( (int) $pause['until'] > 0 && (int) $at - (int) $pause['until'] < self::PAUSE_SECONDS )
			? min( self::PAUSE_MAX_SECONDS, max( self::PAUSE_SECONDS, 2 * (int) $pause['seconds'] ) )
			: self::PAUSE_SECONDS;

		$pause = array(
			'until'    => time() + $seconds,
			'seconds'  => $seconds,
			'crash_at' => max( (int) $pause['crash_at'], (int) $crash_at ),
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
	 * Plan the next run.
	 *
	 * @param array $run State.
	 * @return void
	 */
	private static function schedule_next( array $run ) {
		if ( 'running' === $run['status'] ) {
			wp_schedule_single_event( time() + self::NEXT_RUN_SECONDS, self::TRIM_HOOK );
		} elseif ( 'waiting' === $run['status'] ) {
			$when = (int) $run['note_cursor'] > 0 ? time() + self::NEXT_RUN_SECONDS : max( time() + self::NEXT_RUN_SECONDS, (int) $run['files_due'] + 5 );
			wp_schedule_single_event( $when, self::TRIM_HOOK );
		}
	}

	/**
	 * Say what a run did, and when the removal has finished.
	 *
	 * @param array $run  State, with the run's counts added.
	 * @param array $done The run's counts.
	 * @return void
	 */
	private static function log_run( array $run, array $done ) {
		if ( $done['photos'] > 0 ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Large photo copies: took the old 1,536 px copy out of the records of %1$d photo(s), so pages drawn from now on no longer offer it. Their %2$d file(s), about %3$s, are deleted from %4$s, once pages a cache saved before have had time to expire.%5$s',
				$done['photos'],
				$done['files_waiting'],
				size_format( $done['bytes_waiting'], 1 ),
				wp_date( 'j M Y, H:i', (int) $run['files_due'] ),
				$done['shared'] > 0 ? sprintf( ' %d file(s) are kept: another Media Library entry uses the same file.', $done['shared'] ) : ''
			) );
		}

		if ( $done['deleted'] + $done['gone'] + $done['left_files'] > 0 ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Large photo copies: deleted %1$d old 1,536 px copy file(s), about %2$s freed on this server.%3$s%4$s',
				$done['deleted'],
				size_format( $done['bytes'], 1 ),
				$done['gone'] > 0 ? sprintf( ' %d had already gone.', $done['gone'] ) : '',
				$done['left_files'] > 0 ? sprintf( ' %d could not be deleted - see above.', $done['left_files'] ) : ''
			) );
		}

		if ( '' !== $done['stopped'] && 'done' !== $run['status'] ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Large photo copies: stopped to stay within this host\'s limits (%1$s). The next batch carries on in %2$d seconds.',
				$done['stopped'],
				self::NEXT_RUN_SECONDS
			) );
		}

		if ( 'done' === $run['status'] && (int) $run['finished_at'] >= time() - 5 ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Large photo copies: finished. %1$d file(s) deleted from %2$d photo(s), about %3$s freed on this server.%4$s%5$s%6$s%7$s',
				(int) $run['deleted'],
				(int) $run['photos'],
				size_format( (int) $run['bytes'], 1 ),
				(int) $run['shared'] > 0 ? sprintf( ' %d file(s) kept because another Media Library entry uses them.', (int) $run['shared'] ) : '',
				(int) $run['not_here'] > 0 ? sprintf( ' %d photo(s) left as they were because their files are not on this server.', (int) $run['not_here'] ) : '',
				(int) $run['left_files'] > 0 ? sprintf( ' %1$d file(s), about %2$s, could not be deleted - see above.', (int) $run['left_files'], size_format( (int) $run['left_bytes'], 1 ) ) : '',
				(int) $run['given_up'] > 0 ? sprintf( ' %d photo(s) were left as they are - see above.', (int) $run['given_up'] ) : ''
			), 'success' );
		}
	}

	/**
	 * Put the removal back on the schedule when it has work and none is planned.
	 *
	 * Checked on admin pages, at most every five minutes.
	 *
	 * @return void
	 */
	public static function maybe_rearm_trim() {
		if ( wp_next_scheduled( self::TRIM_HOOK ) || ! self::under_way() ) {
			return;
		}

		if ( get_transient( 'tcgiant_size_trim_rearm_check' ) ) {
			return;
		}
		set_transient( 'tcgiant_size_trim_rearm_check', 1, 5 * MINUTE_IN_SECONDS );

		wp_schedule_single_event( time() + self::NEXT_RUN_SECONDS, self::TRIM_HOOK );
		if ( class_exists( 'TCGiant_Sync_Cron' ) ) {
			TCGiant_Sync_Cron::request_dispatch();
		}

		TCGiant_Sync_Logger::log( 'Large photo copies: the removal was under way and nothing was scheduled; it has been scheduled again.' );
	}

	/**
	 * Say, at most once a day, that the removal is waiting for an offload
	 * plugin to be switched off.
	 *
	 * @param string[] $offloaders From offloaders().
	 * @return void
	 */
	private static function say_offloaded( array $offloaders ) {
		if ( get_transient( 'tcgiant_size_trim_offloaded' ) ) {
			return;
		}
		set_transient( 'tcgiant_size_trim_offloaded', 1, DAY_IN_SECONDS );

		TCGiant_Sync_Logger::warning( 'Large photo copies: waiting. ' . self::offloaded_message( $offloaders ) . ' The removal carries on by itself, from where it stopped, within an hour of it being switched off.' );
	}

	/**
	 * Why nothing is checked or removed while an offload plugin is at work.
	 *
	 * @param string[] $offloaders From offloaders().
	 * @return string
	 */
	public static function offloaded_message( array $offloaders ) {
		return sprintf(
			/* translators: %s: names of offload plugins, e.g. "WP Offload Media" */
			__( 'An offload plugin is at work on this site (%s). It keeps its own copy of every size of a photo, which taking a size off the photo would leave behind in storage you pay for, and some re-upload or re-process a photo when its record changes. So nothing is checked or removed while it is active.', 'tcgiant-sync' ),
			implode( ', ', $offloaders )
		);
	}

	// ───────────────────────────────────────────────────────────────────────────
	// A photo deleted while its copy waits for its day
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Delete a photo's waiting copies with it.
	 *
	 * WordPress deletes the files a photo's record names. Once the record no
	 * longer names the large copy, deleting the photo - a product deleted for
	 * good, its photos with it - would leave the copy on disk with nothing at
	 * all to say what it is: the note goes with the rest of the photo's meta.
	 * So they go here, from the delete_attachment action, where the photo and
	 * its records are still whole, on the same checks as on their day. Nothing
	 * here may stop the photo being deleted.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	public static function on_delete_attachment( $id ) {
		$id = (int) $id;

		try {
			$note = get_post_meta( $id, self::PENDING_KEY, true );
			if ( ! is_array( $note ) || ! in_array( $note['stage'] ?? '', array( 'meta', 'files' ), true ) ) {
				return;
			}
			if ( self::offloaders() ) {
				return;
			}

			$result = self::delete_noted( $id, $note );
			if ( isset( $result['moved'] ) || isset( $result['odd'] ) || $result['deleted'] < 1 ) {
				return;
			}

			if ( 0 === self::$with_photo['photos'] ) {
				add_action( 'shutdown', array( __CLASS__, 'log_with_photo' ) );
			}
			self::$with_photo['photos']++;
			self::$with_photo['files'] += $result['deleted'];
			self::$with_photo['bytes'] += $result['bytes'];
		} catch ( \Throwable $e ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Large photo copies: photo #%1$d was deleted while its old 1,536 px copy waited to be removed, and the copy could not be deleted with it (%2$s). It stays on this server.',
				$id,
				$e->getMessage()
			) );
		}
	}

	/**
	 * The line for a request that deleted photos whose copies were waiting.
	 *
	 * @return void
	 */
	public static function log_with_photo() {
		if ( self::$with_photo['photos'] > 0 ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Large photo copies: %1$d photo(s) were deleted while their old 1,536 px copies waited to be removed; %2$d copy file(s), about %3$s, went with them.',
				self::$with_photo['photos'],
				self::$with_photo['files'],
				size_format( self::$with_photo['bytes'], 1 )
			) );
		}
		self::$with_photo = array( 'photos' => 0, 'files' => 0, 'bytes' => 0 );
	}

	// ───────────────────────────────────────────────────────────────────────────
	// The Image Cleanup screen
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * How long the check's hold lasts: longer than any request can. See
	 * TCGiant_Sync_Photo_Cleanup::scan_hold_seconds().
	 *
	 * @return int Seconds.
	 */
	private static function check_hold_seconds() {
		return min( self::CHECK_HOLD_CEILING, max( TCGiant_Sync_Runtime::effective_time_limit(), self::CHECK_HOLD_FLOOR ) + 30 );
	}

	/**
	 * AJAX: start or carry on the check.
	 *
	 * @return void
	 */
	public static function ajax_check() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		TCGiant_Sync_Runtime::raise_time_limit( 120 );

		// Nothing inside the hold sends the answer: that ends the request, and
		// a hold still taken would keep the next step waiting for it to lapse.
		if ( ! self::take_lock( 'check', self::check_hold_seconds() ) ) {
			wp_send_json_success( self::status() + array( 'busy' => true ) );
		}

		$refused = null;

		TCGiant_Sync_Runtime::watch( self::CHECK_JOB, array( __CLASS__, 'release_all_locks' ) );

		try {
			$offloaders = self::offloaders();
			if ( $offloaders ) {
				$refused = new WP_Error( 'offloaded', self::offloaded_message( $offloaders ) );
			} else {
				$started = empty( $_POST['restart'] ) ? null : self::start_check(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( is_wp_error( $started ) ) {
					$refused = $started;
				} else {
					self::check_step( min( TCGiant_Sync_Runtime::time_budget(), TCGiant_Sync_Runtime::elapsed() + self::AJAX_SECONDS ) );
				}
			}
		} catch ( TCGiant_Sync_Photo_Read_Error $e ) {
			$refused = new WP_Error( 'read', sprintf(
				/* translators: %s: what the database said */
				__( 'The database did not answer while checking (%s), so nothing from this step was counted. Press the button again to carry on from where the check stopped.', 'tcgiant-sync' ),
				$e->getMessage()
			) );
		} finally {
			self::release_lock( 'check' );
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
	 * Starts the removal, which goes on in the background whether or not
	 * anyone keeps the page open.
	 *
	 * @return void
	 */
	public static function ajax_start() {
		check_ajax_referer( 'tcgiant_sync_ajax' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		// Under the check's hold, so a check started again in another tab
		// cannot replace the one approved here.
		if ( ! self::take_lock( 'check', self::check_hold_seconds() ) ) {
			wp_send_json_error( array( 'message' => __( 'A check is running. Wait for it to finish, then look at what it found before removing anything.', 'tcgiant-sync' ) ) );
		}

		try {
			$approved = self::approve( sanitize_text_field( wp_unslash( $_POST['check_id'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} finally {
			self::release_lock( 'check' );
		}

		if ( is_wp_error( $approved ) ) {
			wp_send_json_error( array( 'message' => $approved->get_error_message() ) );
		}

		wp_clear_scheduled_hook( self::TRIM_HOOK );
		wp_schedule_single_event( time() + 5, self::TRIM_HOOK );
		if ( class_exists( 'TCGiant_Sync_Cron' ) ) {
			TCGiant_Sync_Cron::request_dispatch();
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
		$check = self::check();
		$run   = self::run_state();
		$pause = self::pause_state();
		$max   = max( 1, (int) ( $check['max_id'] ?? 0 ) );

		$records_of = max( 1, (int) ( $run['max_id'] ?? 0 ) );

		return array(
			'offloaders'   => self::offloaders(),
			'caches'       => self::page_caches(),
			'check'        => array(
				'id'             => (string) ( $check['id'] ?? '' ),
				'status'         => (string) ( $check['status'] ?? '' ),
				'percent'        => (int) min( 100, floor( 100 * (int) ( $check['cursor'] ?? 0 ) / $max ) ),
				'examined'       => (int) ( $check['examined'] ?? 0 ),
				'photos'         => (int) ( $check['photos'] ?? 0 ),
				'files'          => (int) ( $check['files'] ?? 0 ),
				'bytes'          => (int) ( $check['bytes'] ?? 0 ),
				'size'           => size_format( (int) ( $check['bytes'] ?? 0 ), 1 ),
				'not_here'       => (int) ( $check['not_here'] ?? 0 ),
				'not_here_files' => (int) ( $check['not_here_files'] ?? 0 ),
				'shared'         => (int) ( $check['shared'] ?? 0 ),
				'kept_whole'     => (int) ( $check['gif'] ?? 0 ) + (int) ( $check['large'] ?? 0 ),
				'finished_at'    => (int) ( $check['finished_at'] ?? 0 ),
				'stale'          => ! empty( $check['finished_at'] ) && time() - (int) $check['finished_at'] > self::CHECK_VALID_SECONDS,
			),
			'run'          => array(
				'status'        => (string) ( $run['status'] ?? '' ),
				'check'         => (string) ( $run['check'] ?? '' ),
				'percent'       => (int) min( 100, floor( 100 * (int) ( $run['cursor'] ?? 0 ) / $records_of ) ),
				'photos'        => (int) ( $run['photos'] ?? 0 ),
				'files_waiting' => (int) ( $run['files_waiting'] ?? 0 ),
				'size_waiting'  => size_format( (int) ( $run['bytes_waiting'] ?? 0 ), 1 ),
				'deleted'       => (int) ( $run['deleted'] ?? 0 ),
				'freed'         => size_format( (int) ( $run['bytes'] ?? 0 ), 1 ),
				'bytes'         => (int) ( $run['bytes'] ?? 0 ),
				'shared'        => (int) ( $run['shared'] ?? 0 ),
				'not_here'      => (int) ( $run['not_here'] ?? 0 ),
				'left_files'    => (int) ( $run['left_files'] ?? 0 ),
				'given_up'      => (int) ( $run['given_up'] ?? 0 ),
				'files_due'     => (int) ( $run['files_due'] ?? 0 ),
				'finished_at'   => (int) ( $run['finished_at'] ?? 0 ),
				'purged'        => array_values( array_map( 'strval', (array) ( $run['purged'] ?? array() ) ) ),
				'unpurged'      => array_values( array_map( 'strval', (array) ( $run['unpurged'] ?? array() ) ) ),
			),
			'paused_until' => (int) $pause['until'] > time() ? (int) $pause['until'] : 0,
			'next_run'     => (int) wp_next_scheduled( self::TRIM_HOOK ),
		);
	}

	// ───────────────────────────────────────────────────────────────────────────
	// Housekeeping
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Stop if the read just made failed. See TCGiant_Sync_Photo_Cleanup's.
	 *
	 * wpdb does not throw when a query fails: it returns an empty result and
	 * keeps what the database said in last_error. Read as "nothing names this
	 * file" or "no mark on this photo", that would delete a file in use.
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

	/**
	 * Hold a piece of this work for one request.
	 *
	 * The photo cleanup's hold (TCGiant_Sync_Photo_Cleanup::take_lock()), under
	 * names of its own: a plain INSERT, which the options table's unique name
	 * lets in for one request only; one past its time is a request that was
	 * killed, taken over by deleting exactly the value read; and the row is
	 * read back, earliest first, before the hold counts.
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
				// Nothing holds it: the database would not take the write.
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
	 * @param string $error What the database said.
	 * @return void
	 */
	private static function lock_failed( $error ) {
		if ( get_transient( 'tcgiant_size_trim_lock_error' ) ) {
			return;
		}
		set_transient( 'tcgiant_size_trim_lock_error', 1, HOUR_IN_SECONDS );

		TCGiant_Sync_Logger::error( sprintf(
			'Large photo copies: could not start - the database would not record that it was working (%s). Nothing was changed. This is often a full disk on the database server; it tries again in a few minutes.',
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
	 * Clear the schedule on deactivation. What is under way stays under way,
	 * and is picked up again when the plugin is.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::TRIM_HOOK );
	}
}
