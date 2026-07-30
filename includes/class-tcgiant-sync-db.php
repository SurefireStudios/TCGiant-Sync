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
	 * Constructor — check for table creation/migration.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'maybe_create_table' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'maybe_add_postmeta_index' ), 6 );
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

		// Migrate existing data from post meta on first install.
		if ( empty( $installed_version ) ) {
			$this->migrate_from_postmeta();
		}

		update_option( 'tcgiant_listings_table_version', self::TABLE_VERSION );
	}

	/**
	 * One-time migration: populate table from existing _ebay_item_id post meta.
	 */
	private function migrate_from_postmeta() {
		global $wpdb;
		$table = self::table_name();

		// Find all products with eBay Item IDs.
		$results = $wpdb->get_results(
			"SELECT p.ID AS product_id,
				MAX(CASE WHEN pm.meta_key = '_ebay_item_id' THEN pm.meta_value END) AS ebay_item_id,
				MAX(CASE WHEN pm.meta_key = '_ebay_listing_type' THEN pm.meta_value END) AS listing_type,
				MAX(CASE WHEN pm.meta_key = '_ebay_listing_status' THEN pm.meta_value END) AS listing_status,
				MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) AS price,
				MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) AS stock
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'product'
			  AND pm.meta_key IN ('_ebay_item_id', '_ebay_listing_type', '_ebay_listing_status', '_price', '_stock')
			GROUP BY p.ID
			HAVING ebay_item_id IS NOT NULL AND ebay_item_id != ''",
			ARRAY_A
		);

		if ( empty( $results ) ) {
			return;
		}

		$migrated = 0;
		foreach ( $results as $row ) {
			$wpdb->replace( $table, array(
				'product_id'     => (int) $row['product_id'],
				'ebay_item_id'   => $row['ebay_item_id'],
				'listing_type'   => ! empty( $row['listing_type'] ) ? $row['listing_type'] : 'FixedPriceItem',
				'listing_status' => ! empty( $row['listing_status'] ) ? $row['listing_status'] : 'Active',
				'ebay_price'     => (float) ( $row['price'] ?? 0 ),
				'ebay_quantity'  => (int) ( $row['stock'] ?? 0 ),
				'ebay_title'     => get_the_title( $row['product_id'] ),
				'last_synced'    => current_time( 'mysql' ),
				'created_at'     => current_time( 'mysql' ),
			) );
			$migrated++;
		}

		TCGiant_Sync_Logger::log( sprintf(
			'Database migration: Migrated %d products from post meta to custom listings table.',
			$migrated
		), 'success' );
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

		$defaults = array(
			'product_id'     => $product_id,
			'ebay_item_id'   => '',
			'listing_type'   => 'FixedPriceItem',
			'listing_status' => 'Active',
			'ebay_price'     => 0.00,
			'ebay_quantity'  => 0,
			'ebay_url'       => '',
			'ebay_title'     => '',
			'last_synced'    => current_time( 'mysql' ),
		);

		$row = wp_parse_args( $data, $defaults );

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE product_id = %d",
			$product_id
		) );

		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'product_id' => $product_id ) );
		} else {
			$row['created_at'] = current_time( 'mysql' );
			$wpdb->insert( $table, $row );
		}

		// Dual-write to post meta for backward compatibility.
		if ( ! empty( $row['ebay_item_id'] ) ) {
			update_post_meta( $product_id, '_ebay_item_id', $row['ebay_item_id'] );
		}
		if ( ! empty( $row['listing_type'] ) ) {
			update_post_meta( $product_id, '_ebay_listing_type', $row['listing_type'] );
		}
		if ( ! empty( $row['listing_status'] ) ) {
			update_post_meta( $product_id, '_ebay_listing_status', $row['listing_status'] );
		}

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
