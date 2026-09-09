<?php
/**
 * Database Manager
 *
 * Manages the custom tcgiant_listings table for tracking eBay-linked products.
 * Provides high-performance queries for the Listings admin page.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_DB class
 */
class TCGiant_Sync_DB {

	/**
	 * Table version for schema migrations.
	 */
	const TABLE_VERSION = '1.0.0';

	/**
	 * Version marker for the postmeta index migration.
	 */
	const POSTMETA_INDEX_VERSION = '1';

	/**
	 * Option key recording which postmeta index migration has run.
	 */
	const POSTMETA_INDEX_OPTION = 'tcgiant_postmeta_index_version';

	/**
	 * Name of the index added to wp_postmeta.
	 */
	const POSTMETA_INDEX_NAME = 'tcgiant_key_value';

	/**
	 * Instance.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

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
	 * Get the full table name.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'tcgiant_listings';
	}

	/**
	 * Cached result of the table-existence check.
	 *
	 * @var bool|null
	 */
	private static $table_exists = null;

	/**
	 * Backfill state. The table version says the table exists; these say how far
	 * the one-time copy from post meta has got. They are separate on purpose - a
	 * backfill that runs out of time must not make the table look uncreated.
	 */
	const BACKFILL_OPTION  = 'tcgiant_listings_backfill';
	const BACKFILL_VERSION = '1';
	const BACKFILL_CURSOR  = 'tcgiant_listings_backfill_cursor';
	const BACKFILL_BATCH   = 500;
	const BACKFILL_SECONDS = 1.5;

	/** Set while we are writing post meta ourselves, so the mirror does not echo. */
	private static $mirroring = false;

	/**
	 * Whether the custom listings table exists.
	 *
	 * Cached per request: this is consulted once per imported product, so an
	 * uncached SHOW TABLES would add one round-trip per item.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		if ( null !== self::$table_exists ) {
			return self::$table_exists;
		}

		global $wpdb;
		$table = self::table_name();
		self::$table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

		return self::$table_exists;
	}

	/**
	 * Constructor — create or upgrade the table, and add the index.
	 *
	 * Called directly. These were two add_action( 'plugins_loaded', ..., 5 )
	 * and ( ..., 6 ) registrations - added from inside a constructor that is
	 * itself reached from plugins_loaded at priority 20. WordPress will not
	 * run a callback registered at a priority the pass in progress has
	 * already gone by, so neither ever ran, on any site, since the table was
	 * introduced.
	 *
	 * Nothing said so. The table simply did not exist, table_exists() was
	 * false everywhere, every upsert() returned false without writing, and
	 * the Listings screen showed "No eBay-linked products found" to everyone
	 * for ever. A coin dealer told us: "there are no products listed to tick".
	 *
	 * Only where the table is used, and where a one-off migration of every
	 * linked product may take its time: admin screens, admin-ajax, cron and
	 * WP-CLI. A shopper's page load pays nothing for this.
	 */
	public function __construct() {
		add_action( 'added_post_meta', array( $this, 'mirror_meta_to_row' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'mirror_meta_to_row' ), 10, 4 );

		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$this->maybe_create_table();
		$this->maybe_backfill_listings();
		$this->maybe_add_postmeta_index();
	}

	/**
	 * Keep the table in step with the product it describes.
	 *
	 * The row is a mirror, and a mirror that only some writers know about goes
	 * stale. The ended-listing check, the stock listener, the End Listing
	 * button and the bulk job all mark a listing ended by writing post meta;
	 * none of them knew about this table, so the Listings screen would have gone
	 * on showing every one of them as Active, and the auto-relist scheduler -
	 * which reads this table when it exists - would have found nothing to
	 * relist. One listener beats seven call sites and the next one somebody
	 * forgets.
	 *
	 * Existing rows only. This never creates one, so it cannot invent rows for
	 * posts that are not linked listings.
	 *
	 * @param int    $meta_id    Unused.
	 * @param int    $post_id    Product the meta belongs to.
	 * @param string $meta_key   Meta key written.
	 * @param mixed  $meta_value Value written.
	 * @return void
	 */
	public function mirror_meta_to_row( $meta_id, $post_id, $meta_key, $meta_value ) {
		$columns = array(
			'_ebay_listing_status' => 'listing_status',
			'_ebay_listing_type'   => 'listing_type',
			'_ebay_item_id'        => 'ebay_item_id',
		);

		if ( self::$mirroring || ! isset( $columns[ $meta_key ] ) || is_array( $meta_value ) ) {
			return;
		}

		if ( ! self::table_exists() ) {
			return;
		}

		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array( $columns[ $meta_key ] => (string) $meta_value ),
			array( 'product_id' => (int) $post_id )
		);
	}

	/**
	 * Add a composite index to wp_postmeta for our meta lookups.
	 *
	 * WordPress indexes postmeta.meta_key but not meta_value, so a query like
	 *
	 *     meta_key = '_tcgiant_source_url' AND meta_value = '<url>'
	 *
	 * scans every row carrying that key. The image localizer runs exactly that
	 * lookup once per image, so a 10,000-product store with eight images each
	 * performs ~80,000 lookups, each scanning the full set of source-URL rows.
	 * The same shape is used for _ebay_item_id and _ebay_sku.
	 *
	 * Runs once, guarded by an option, and is deliberately non-fatal: if the
	 * database user lacks ALTER rights, or the table is too large to alter on
	 * this request, the plugin still works — just more slowly.
	 */
	public function maybe_add_postmeta_index() {
		if ( get_option( self::POSTMETA_INDEX_OPTION ) === self::POSTMETA_INDEX_VERSION ) {
			return;
		}

		global $wpdb;

		// Never attempt this mid-import; an ALTER on a large table plus a
		// running scan is a bad combination on shared hosting.
		$state = get_option( 'tcgiant_sync_state', array() );
		if ( is_array( $state ) && in_array( $state['status'] ?? '', array( 'scanning', 'importing' ), true ) ) {
			return;
		}

		// Already present (possibly added by hand or by another install)?
		$existing = $wpdb->get_results( $wpdb->prepare(
			"SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name = %s",
			self::POSTMETA_INDEX_NAME
		) );

		if ( ! empty( $existing ) ) {
			update_option( self::POSTMETA_INDEX_OPTION, self::POSTMETA_INDEX_VERSION, false );
			return;
		}

		// meta_value is LONGTEXT, so it must be prefix-indexed. 191 characters
		// is the safe maximum under utf8mb4 with a 767-byte index limit.
		$wpdb->hide_errors();
		$result = $wpdb->query(
			"ALTER TABLE {$wpdb->postmeta}
			 ADD INDEX " . self::POSTMETA_INDEX_NAME . " (meta_key(32), meta_value(191))"
		);
		$wpdb->show_errors();

		if ( false === $result ) {
			// Record the attempt anyway so we do not retry on every request.
			update_option( self::POSTMETA_INDEX_OPTION, self::POSTMETA_INDEX_VERSION, false );
			TCGiant_Sync_Logger::warning(
				'Could not add the tcgiant_key_value index to wp_postmeta (' . $wpdb->last_error . '). '
				. 'Image deduplication and eBay ID lookups will be slower on large stores. '
				. 'A database administrator can add it manually.'
			);
			return;
		}

		update_option( self::POSTMETA_INDEX_OPTION, self::POSTMETA_INDEX_VERSION, false );
		TCGiant_Sync_Logger::log( 'Added the tcgiant_key_value index to wp_postmeta.', 'success' );
	}

	/**
	 * Create or upgrade the listings table.
	 */
	public function maybe_create_table() {
		$installed_version = get_option( 'tcgiant_listings_table_version', '' );
		if ( self::TABLE_VERSION === $installed_version ) {
			return;
		}

		global $wpdb;
		$table = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			ebay_item_id VARCHAR(20) NOT NULL DEFAULT '',
			listing_type VARCHAR(30) NOT NULL DEFAULT 'FixedPriceItem',
			listing_status VARCHAR(20) NOT NULL DEFAULT 'Active',
			ebay_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			ebay_quantity INT NOT NULL DEFAULT 0,
			ebay_url VARCHAR(512) NOT NULL DEFAULT '',
			ebay_title VARCHAR(255) NOT NULL DEFAULT '',
			last_synced DATETIME NULL,
			last_pushed DATETIME NULL,
			variation_cache LONGTEXT,
			sync_error TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY product_id (product_id),
			KEY ebay_item_id (ebay_item_id),
			KEY listing_status (listing_status),
			KEY listing_type (listing_type)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// The table may have just been created, so drop any cached "missing".
		self::$table_exists = null;

		// Recorded now, before any copying. This used to be written only after a
		// per-row migration of every linked product had finished, inside the same
		// request - so on a large catalogue the request died first, nothing was
		// recorded, and the next admin page load started the whole thing again.
		update_option( 'tcgiant_listings_table_version', self::TABLE_VERSION );

		// Copying what is already linked is the backfill's job, in batches.
		if ( empty( $installed_version ) ) {
			update_option( self::BACKFILL_OPTION, 'pending', false );
		}
	}

	/**
	 * Copy products that are already linked to eBay into the table.
	 *
	 * Runs once, in batches, and stops when it has had its share of the
	 * request. What it replaced was a single unbounded pass: one query for the
	 * whole catalogue, then per row a $wpdb->replace() AND a get_the_title() -
	 * an uncached post read each, since the ids came from raw SQL and nothing
	 * primed the cache. Around 80,000 queries and every product object held in
	 * memory at once for a forty-thousand product shop, inside the admin request
	 * that happened to arrive first, with the "done" flag written only at the
	 * end - so a request that ran out of time recorded nothing and the next one
	 * started again from the beginning, for ever.
	 *
	 * Now: two queries per five hundred products, the title taken in SQL, a
	 * cursor saved after every batch, and a time budget. A small shop finishes
	 * on the first admin page load; a large one gets there over several, and
	 * neither ever pays more than about a second and a half at a time.
	 *
	 * INSERT IGNORE, so a row a push or an import has already written is never
	 * replaced by older post meta.
	 *
	 * @return void
	 */
	public function maybe_backfill_listings() {
		if ( self::BACKFILL_VERSION === get_option( self::BACKFILL_OPTION, '' ) ) {
			return;
		}

		if ( ! self::table_exists() ) {
			return;
		}

		global $wpdb;

		$table   = self::table_name();
		$cursor  = (int) get_option( self::BACKFILL_CURSOR, 0 );
		$started = microtime( true );
		$done    = 0;

		do {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_ebay_item_id'
					WHERE p.post_type = 'product'
						AND p.ID > %d
						AND pm.meta_value <> ''
					ORDER BY p.ID ASC
					LIMIT %d",
				$cursor,
				self::BACKFILL_BATCH
			) );

			if ( empty( $ids ) ) {
				update_option( self::BACKFILL_OPTION, self::BACKFILL_VERSION, false );
				delete_option( self::BACKFILL_CURSOR );

				if ( $done > 0 ) {
					TCGiant_Sync_Logger::log( sprintf(
						'Listings table: added %d product(s) that were already linked to eBay.',
						$done
					), 'success' );
				}

				return;
			}

			// Safe to interpolate: every id has been through intval().
			$in = implode( ',', array_map( 'intval', $ids ) );

			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$table}
					(product_id, ebay_item_id, listing_type, listing_status, ebay_price, ebay_quantity, ebay_title, last_synced, created_at)
				SELECT p.ID,
					MAX(CASE WHEN pm.meta_key = '_ebay_item_id' THEN pm.meta_value END),
					COALESCE(NULLIF(MAX(CASE WHEN pm.meta_key = '_ebay_listing_type' THEN pm.meta_value END), ''), 'FixedPriceItem'),
					COALESCE(NULLIF(MAX(CASE WHEN pm.meta_key = '_ebay_listing_status' THEN pm.meta_value END), ''), 'Active'),
					COALESCE(MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) + 0, 0),
					COALESCE(MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) + 0, 0),
					LEFT(MAX(p.post_title), 255),
					%s, %s
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.ID IN ({$in})
					AND pm.meta_key IN ('_ebay_item_id', '_ebay_listing_type', '_ebay_listing_status', '_price', '_stock')
				GROUP BY p.ID
				HAVING MAX(CASE WHEN pm.meta_key = '_ebay_item_id' THEN pm.meta_value END) <> ''",
				current_time( 'mysql' ),
				current_time( 'mysql' )
			) );

			$cursor = (int) max( $ids );
			$done  += count( $ids );

			update_option( self::BACKFILL_CURSOR, $cursor, false );
		} while ( ( microtime( true ) - $started ) < self::BACKFILL_SECONDS );
	}

	// ───────────────────────────────────────────────────────────────────────────
	// CRUD Operations (dual-write: custom table + post meta)
	// ───────────────────────────────────────────────────────────────────────────

	/**
	 * Upsert a listing record (insert or update).
	 *
	 * @param array $data Listing data.
	 * @return int|false Row ID on success, false on failure.
	 */
	public static function upsert( $data ) {
		global $wpdb;
		$table = self::table_name();

		// Check if the table exists (cached — this runs once per imported
		// product, and a SHOW TABLES per product is thousands of wasted
		// round-trips on a large sync).
		if ( ! self::table_exists() ) {
			return false; // Table not yet created.
		}

		$product_id = (int) ( $data['product_id'] ?? 0 );
		if ( ! $product_id ) {
			return false;
		}

		// Only the columns the caller actually supplied.
		//
		// This used to fill every column from a list of defaults and hand the lot
		// to \$wpdb->update(), which writes whatever it is given. So a caller
		// saying no more than "this listing is Active again" also wrote
		// listing_type 'FixedPriceItem', price 0.00, quantity 0 and an empty
		// title over whatever was there - and dual-wrote that invented type onto
		// the product. The auto-relist scheduler does exactly that, so relisting
		// an auction stamped it as fixed price and the next push refused to run,
		// telling the seller to re-create an auction that already was one.
		$columns = array(
			'ebay_item_id',
			'listing_type',
			'listing_status',
			'ebay_price',
			'ebay_quantity',
			'ebay_url',
			'ebay_title',
			'last_synced',
			'last_pushed',
			'variation_cache',
			'sync_error',
		);

		$row = array();
		foreach ( $columns as $column ) {
			if ( array_key_exists( $column, $data ) ) {
				$row[ $column ] = $data[ $column ];
			}
		}

		if ( empty( $row ) ) {
			return false;
		}

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE product_id = %d",
			$product_id
		) );

		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'product_id' => $product_id ) );
		} else {
			$row['product_id'] = $product_id;
			$row['created_at'] = current_time( 'mysql' );
			if ( ! isset( $row['last_synced'] ) ) {
				$row['last_synced'] = current_time( 'mysql' );
			}
			$wpdb->insert( $table, $row );
		}

		// Dual-write to post meta for backward compatibility. Guarded so the
		// mirror listener does not turn round and rewrite the row we just wrote.
		self::$mirroring = true;

		if ( ! empty( $row['ebay_item_id'] ) ) {
			update_post_meta( $product_id, '_ebay_item_id', $row['ebay_item_id'] );
		}
		if ( ! empty( $row['listing_type'] ) ) {
			update_post_meta( $product_id, '_ebay_listing_type', $row['listing_type'] );
		}
		if ( ! empty( $row['listing_status'] ) ) {
			update_post_meta( $product_id, '_ebay_listing_status', $row['listing_status'] );
		}

		self::$mirroring = false;

		return $wpdb->insert_id ?: $existing;
	}

	/**
	 * Get a listing by product ID.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return array|null Listing row or null.
	 */
	public static function get_by_product( $product_id ) {
		global $wpdb;
		$table = self::table_name();

		if ( ! TCGiant_Sync_DB::table_exists() ) {
			return null;
		}

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE product_id = %d",
			$product_id
		), ARRAY_A );
	}

	/**
	 * Get a listing by eBay Item ID.
	 *
	 * @param string $ebay_item_id eBay Item ID.
	 * @return array|null Listing row or null.
	 */
	public static function get_by_ebay_id( $ebay_item_id ) {
		global $wpdb;
		$table = self::table_name();

		if ( ! TCGiant_Sync_DB::table_exists() ) {
			return null;
		}

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE ebay_item_id = %s",
			$ebay_item_id
		), ARRAY_A );
	}

	/**
	 * Query listings with filters, pagination, and sorting.
	 *
	 * Used by the Listings admin page (WP_List_Table).
	 *
	 * @param array $args Query args.
	 * @return array { items: array, total: int }
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$table = self::table_name();

		if ( ! TCGiant_Sync_DB::table_exists() ) {
			return array( 'items' => array(), 'total' => 0 );
		}

		$defaults = array(
			'status'   => '',      // Active, Ended, Completed
			'type'     => '',      // FixedPriceItem, Chinese
			'search'   => '',      // Search by title, eBay ID, or product ID
			'orderby'  => 'updated_at',
			'order'    => 'DESC',
			'per_page' => 20,
			'page'     => 1,
		);

		$args = wp_parse_args( $args, $defaults );

		$where = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[] = 'listing_status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['type'] ) ) {
			$where[] = 'listing_type = %s';
			$params[] = $args['type'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(ebay_title LIKE %s OR ebay_item_id LIKE %s OR product_id = %d)';
			$params[] = $like;
			$params[] = $like;
			$params[] = (int) $args['search'];
		}

		$where_clause = implode( ' AND ', $where );

		// Whitelist orderby to prevent SQL injection.
		$allowed_orderby = array( 'product_id', 'ebay_item_id', 'listing_status', 'listing_type', 'ebay_price', 'ebay_quantity', 'last_synced', 'updated_at', 'ebay_title' );
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'updated_at';
		$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$offset = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];
		$limit = (int) $args['per_page'];

		// Count total.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
		}

		// Fetch rows.
		$query_sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT {$limit} OFFSET {$offset}";
		if ( ! empty( $params ) ) {
			$items = $wpdb->get_results( $wpdb->prepare( $query_sql, $params ), ARRAY_A );
		} else {
			$items = $wpdb->get_results( $query_sql, ARRAY_A );
		}

		return array(
			'items' => $items ?: array(),
			'total' => $total,
		);
	}

	/**
	 * Delete a listing record.
	 *
	 * @param int $product_id WooCommerce product ID.
	 */
	public static function delete( $product_id ) {
		global $wpdb;
		$table = self::table_name();

		if ( ! TCGiant_Sync_DB::table_exists() ) {
			return;
		}

		$wpdb->delete( $table, array( 'product_id' => (int) $product_id ) );
	}

	/**
	 * Get aggregate stats for the dashboard.
	 *
	 * @return array Counts by status.
	 */
	public static function get_stats() {
		global $wpdb;
		$table = self::table_name();

		if ( ! TCGiant_Sync_DB::table_exists() ) {
			return array( 'active' => 0, 'ended' => 0, 'total' => 0 );
		}

		$results = $wpdb->get_results(
			"SELECT listing_status, COUNT(*) as cnt FROM {$table} GROUP BY listing_status",
			ARRAY_A
		);

		$stats = array( 'active' => 0, 'ended' => 0, 'total' => 0 );
		foreach ( $results as $row ) {
			$status = strtolower( $row['listing_status'] );
			if ( 'active' === $status ) {
				$stats['active'] = (int) $row['cnt'];
			} else {
				$stats['ended'] += (int) $row['cnt'];
			}
			$stats['total'] += (int) $row['cnt'];
		}

		return $stats;
	}

	/**
	 * Drop the table on plugin uninstall.
	 */
	public static function uninstall() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( 'tcgiant_listings_table_version' );
	}
}
