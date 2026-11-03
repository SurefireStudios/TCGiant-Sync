<?php
/**
 * Harness: the 3.28.8 upgrade's pass over who made each listing
 * (TCGiant_Sync_Ebay_Live::sweep_origins()).
 *
 * Every product linked before 3.28.8 is judged once, as origin() judges it,
 * and the answer kept with who wrote it ('sweep') - from a few queries a
 * batch (the products' IDs, dates and origins, the meta the rules read, the
 * Listings table's starts for those with none, the sync logs of the few left
 * open), never by loading products, posts or their whole meta, and the sync
 * logs only those that can say 'import', 50 at a time. Where nothing tells,
 * nothing is kept: the product's first Update reads its listing. It runs
 * from the hourly check, and from admin pages at most once a minute, never
 * from AJAX ("Process Queue", the heartbeat). It judges again what the
 * 3.28.8 preview's pass kept (no writer said) and its own, never what a
 * push, a link, the import or a push's judgement wrote, even meanwhile;
 * reads the first of duplicate meta rows as origin() does; and a request
 * stopped part way leaves what it kept. On a 15,000-product shop whose
 * products carry real-sized sync logs each batch stays small, and keeps
 * nothing.
 *
 * The database is played here: the queries the pass makes are answered from
 * the products below - as copies, as a real database hands them back, and
 * kept until flush() - and counted.
 *
 * @package TCGiant_Sync
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['OPTIONS']    = array();
$GLOBALS['TRANSIENTS'] = array();
$GLOBALS['META']       = array();
$GLOBALS['DATES']      = array();
$GLOBALS['LOG']        = array();
$GLOBALS['AJAX']       = false;
$GLOBALS['RACE']       = array();
$GLOBALS['KILL_AT']    = 0;
$GLOBALS['ADDS']       = array();
$GLOBALS['DUPES']      = array();
$GLOBALS['TABLE']      = array();
$GLOBALS['RACE_BY']    = array();

function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; return true; }
function get_transient( $name ) { return $GLOBALS['TRANSIENTS'][ $name ] ?? false; }
function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $name ] = $value; return true; }
function wp_doing_ajax() { return $GLOBALS['AJAX']; }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['META'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['META'][ (int) $id ][ $key ] = $value; return true; }
/** WordPress's, with $unique: refused when the key is there - and a push may have written it a moment ago. */
function add_post_meta( $id, $key, $value, $unique = false ) {
	$id = (int) $id;
	if ( '_ebay_origin' === $key ) {
		if ( isset( $GLOBALS['RACE'][ $id ] ) ) {
			$GLOBALS['META'][ $id ][ $key ] = $GLOBALS['RACE'][ $id ];
		}
		if ( $GLOBALS['KILL_AT'] && count( $GLOBALS['ADDS'] ) + 1 >= $GLOBALS['KILL_AT'] ) {
			throw new RuntimeException( 'killed' );
		}
		$GLOBALS['ADDS'][] = $id;
	}
	if ( $unique && array_key_exists( $key, $GLOBALS['META'][ $id ] ?? array() ) ) {
		return false;
	}
	$GLOBALS['META'][ $id ][ $key ] = $value;
	return true;
}
/** WordPress's: with a value, only a row holding it. */
function delete_post_meta( $id, $key, $value = '' ) {
	$id = (int) $id;
	if ( array_key_exists( $key, $GLOBALS['META'][ $id ] ?? array() ) && ( '' === $value || $GLOBALS['META'][ $id ][ $key ] === $value ) ) {
		unset( $GLOBALS['META'][ $id ][ $key ] );
		return true;
	}
	return false;
}
/** A product's date, as get_post_field() gives it: what origin() reads one product at a time. */
function get_post_field( $field, $id ) { return 'post_date_gmt' === $field ? ( $GLOBALS['DATES'][ (int) $id ] ?? '' ) : ''; }
/** WordPress's: the site's time (current_time( 'mysql' )) in UTC, by the site's zone - here $GLOBALS['TZ']. */
function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) {
	$time = date_create( $date, new DateTimeZone( $GLOBALS['TZ'] ?? 'UTC' ) );
	if ( false === $time ) {
		return false;
	}
	$time->setTimezone( new DateTimeZone( 'UTC' ) );
	return $time->format( $format );
}
function maybe_unserialize( $value ) { return is_string( $value ) && preg_match( '/^[aOs]:\d+:/', $value ) ? unserialize( $value ) : $value; }

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = $message; }
}
class TCGiant_Sync_API {
	public static function scalar_value( $value ) { return is_scalar( $value ) ? (string) $value : ''; }
	/** The product a listing's ApplicationData names as this site's push (3.28.7): played by $GLOBALS['MARKS']. */
	public static function marked_product( $raw ) { return (int) ( $GLOBALS['MARKS'][ is_scalar( $raw ) ? (string) $raw : '' ] ?? 0 ); }
}
/** The Listings table, when the shop has it: a row's listing and start, by product. */
class TCGiant_Sync_DB {
	public static function table_exists() { return ! empty( $GLOBALS['TABLE'] ); }
	public static function table_name() { return 'wp_tcgiant_listings'; }
	public static function get_by_product( $id ) { return $GLOBALS['TABLE'][ (int) $id ] ?? null; }
}

/** The database: the queries the pass makes, answered from the products as copies, and counted. */
class Sweep_World_DB {
	public $posts       = 'wp_posts';
	public $postmeta    = 'wp_postmeta';
	public $queries     = 0;
	public $by          = array( 'dates' => 0, 'meta' => 0, 'logs' => 0 );
	public $rows        = 0;
	public $log_rows    = array();
	public $flushed     = 0;
	public $last_result = array();
	public function prepare( $sql, ...$args ) {
		return array( $sql, 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args );
	}
	public function esc_like( $text ) { return addcslashes( (string) $text, '_%\\' ); }
	public function get_results( $query, $output = null ) {
		$this->queries++;
		list( $sql, $args ) = is_array( $query ) ? $query : array( (string) $query, array() );
		$out = array();
		if ( false !== strpos( $sql, 'SELECT DISTINCT p.ID, p.post_date_gmt, o.meta_value AS origin' ) ) {
			// Linked, after the cursor, and with no origin, or one no writer
			// said (the preview's) or a pass wrote.
			$this->by['dates']++;
			list( $key, $by_key, $after, $sweep, $limit ) = $args;
			$unsaid = false !== strpos( $sql, 'OR b.meta_id IS NULL' );
			$swept  = false !== strpos( $sql, 'OR b.meta_value = %s )' );
			foreach ( $GLOBALS['DATES'] as $id => $date ) {
				$meta = $GLOBALS['META'][ $id ] ?? array();
				if ( $id > $after && '' !== (string) ( $meta['_ebay_item_id'] ?? '' ) && ( ! array_key_exists( $key, $meta ) || ( $unsaid && ! array_key_exists( $by_key, $meta ) ) || ( $swept && $sweep === ( $meta[ $by_key ] ?? null ) ) ) ) {
					$out[] = array( 'ID' => (string) $id, 'post_date_gmt' => $date, 'origin' => array_key_exists( $key, $meta ) ? (string) $meta[ $key ] : null );
					if ( count( $out ) >= $limit ) {
						break;
					}
				}
			}
		} elseif ( false !== strpos( $sql, 'FROM wp_tcgiant_listings WHERE product_id IN' ) && preg_match( '/product_id IN \(([\d,]+)\)/', $sql, $m ) ) {
			$this->by['table'] = ( $this->by['table'] ?? 0 ) + 1;
			foreach ( explode( ',', $m[1] ) as $id ) {
				if ( isset( $GLOBALS['TABLE'][ (int) $id ] ) ) {
					$out[] = array( 'product_id' => $id ) + $GLOBALS['TABLE'][ (int) $id ];
				}
			}
		} elseif ( false !== strpos( $sql, 'SELECT meta_key, meta_id' ) ) {
			// origin(), one product at a time: its _ebay_sku and _ebay_item_id rows.
			foreach ( mids_of( (int) $args[0] ) as $key => $mid ) {
				if ( in_array( $key, array( '_ebay_sku', '_ebay_item_id' ), true ) ) {
					$out[] = array( 'meta_key' => $key, 'meta_id' => (string) $mid );
				}
			}
			usort( $out, function ( $a, $b ) { return (int) $a['meta_id'] - (int) $b['meta_id']; } );
		} elseif ( false !== strpos( $sql, 'SELECT post_id, meta_key, meta_value' ) && preg_match( '/post_id IN \(([\d,]+)\)/', $sql, $m ) ) {
			// Only logs that can say 'import': not full ("a:20:"), with the
			// import's first entry in them, as the SQL asks.
			$logs_only = false !== strpos( $sql, "NOT LIKE 'a:20:%%'" ) && false !== strpos( $sql, 'meta_value LIKE %s' );
			$this->by[ $logs_only ? 'logs' : 'meta' ]++;
			$keys = array_filter( $args, function ( $arg ) { return 0 === strpos( (string) $arg, '_' ); } );
			foreach ( explode( ',', $m[1] ) as $id ) {
				foreach ( $keys as $key ) {
					// Every row, in meta_id order: a product may have two.
					$values = $GLOBALS['DUPES'][ (int) $id ][ $key ] ?? ( isset( $GLOBALS['META'][ (int) $id ][ $key ] ) ? array( $GLOBALS['META'][ (int) $id ][ $key ] ) : array() );
					foreach ( $values as $n => $value ) {
						$value = is_array( $value ) ? serialize( $value ) : (string) $value;
						if ( $logs_only && ( 0 === strpos( $value, 'a:20:' ) || false === strpos( $value, IMPORTED ) ) ) {
							continue;
						}
						if ( $logs_only ) {
							$this->log_rows[] = (int) $id;
						}
						// A copy, as a real database hands back each value.
						$out[] = array( 'post_id' => $id, 'meta_key' => $key, 'meta_value' => substr( $value . ' ', 0, -1 ), 'meta_id' => (string) ( ( mids_of( (int) $id )[ $key ] ?? 0 ) + $n ) );
					}
				}
			}
		}
		$this->rows       += count( $out );
		$this->last_result = $out;
		return $out;
	}
	/** Who wrote a product's origin, as the database has it now - which a push may have just changed. */
	public function get_var( $query ) {
		$this->queries++;
		list( $sql, $args ) = $query;
		if ( false !== strpos( $sql, 'SELECT meta_value FROM wp_postmeta WHERE post_id = %d AND meta_key = %s' ) ) {
			$id = (int) $args[0];
			if ( isset( $GLOBALS['RACE_BY'][ $id ] ) ) {
				$GLOBALS['META'][ $id ]['_ebay_origin']    = $GLOBALS['RACE_BY'][ $id ][0];
				$GLOBALS['META'][ $id ]['_ebay_origin_by'] = $GLOBALS['RACE_BY'][ $id ][1];
			}
			return $GLOBALS['META'][ $id ][ $args[1] ] ?? null;
		}
		return null;
	}
	public function flush() { $this->flushed++; $this->last_result = array(); }
}
$GLOBALS['wpdb'] = new Sweep_World_DB();

/** A product's meta IDs: each key's place in the order it was first written, as a real table numbers its rows. */
function mids_of( $id ) {
	$out = array();
	$n   = 0;
	foreach ( array_keys( $GLOBALS['META'][ $id ] ?? array() ) as $key ) {
		$n++;
		$out[ $key ] = $id * 1000 + $n * 10;
	}
	return $out;
}

require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-ebay-live.php';

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-92s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 160 ) );
	if ( ! $ok ) {
		printf( "        want %s\n", substr( str_replace( "\n", ' ', var_export( $want, true ) ), 0, 400 ) );
	}
}

const IMPORTED = 'New product imported. All fields synced from eBay.';

/**
 * Sync logs of the size a real shop's are: entries of seven decisions. A
 * full one (20 entries) whose oldest is the import's; a young one (4) whose
 * oldest is; a pushed product's (12) with no import line.
 */
function logs() {
	static $logs = null;
	if ( null === $logs ) {
		$seven = array( 'Price updated (eBay won)', 'Stock updated from eBay: 3 -> 2', 'Title kept (WooCommerce won)', 'Description kept (WooCommerce won)', 'Categories & Tags kept (WooCommerce won)', 'Images kept (Overwrite Images is off)', 'Weight and dimensions kept (WooCommerce won)' );
		$entry = function ( array $decisions, $i ) {
			return array( 'time' => sprintf( '2026-%02d-%02d 10:%02d:00', 1 + $i % 12, 1 + $i % 28, $i ), 'source' => 'delta', 'item' => '256100000001', 'decisions' => $decisions );
		};
		$make = function ( $n, $imported ) use ( $seven, $entry ) {
			$log = array();
			for ( $i = 0; $i < $n; $i++ ) {
				$log[] = $entry( $seven, $i );
			}
			if ( $imported ) {
				$log[ $n - 1 ] = $entry( array( IMPORTED ), $n );
			}
			return serialize( $log );
		};
		$logs = array( 'full' => $make( 20, true ), 'young' => $make( 4, true ), 'pushed' => $make( 12, false ) );
	}
	return $logs;
}

/**
 * A shop of $n linked products from before 3.28.8, a mix of every rule:
 * never pushed; pushed and made after the listing started; pushed, made
 * before, its log still holding the import's first entry; the same with a
 * full log; a draft's date and no log; pushed with a log of its own and no
 * import line, Updated months after its listing started; the same listed
 * by its last push (kind 8, pushed two minutes after the start); and every
 * tenth product with no listing.
 */
function shop( $n ) {
	$GLOBALS['META']    = array();
	$GLOBALS['DATES']   = array();
	$GLOBALS['OPTIONS'] = array();
	$GLOBALS['LOG']     = array();
	$GLOBALS['DUPES']   = array();
	$logs               = logs();
	for ( $id = 1001; $id < 1001 + $n; $id++ ) {
		$kind                    = $id % 10;
		$GLOBALS['DATES'][ $id ] = 4 === $kind ? '0000-00-00 00:00:00' : ( 1 === $kind ? '2026-02-01 09:00:00' : '2025-12-01 09:00:00' );
		$meta                    = array( '_ebay_item_id' => 9 === $kind ? '' : '2561' . $id, '_ebay_start_time' => '2026-01-05T10:00:00.000Z', '_sku' => 'S' . $id );
		if ( 0 !== $kind ) {
			$meta['_ebay_export_last_pushed'] = 8 === $kind ? '2026-01-05 10:02:00' : '2026-06-01 10:00:00';
			$meta['_ebay_export_status']      = 'pushed';
		}
		if ( 2 === $kind ) {
			$meta['_tcgiant_sync_log'] = $logs['young'];
		} elseif ( 3 === $kind ) {
			$meta['_tcgiant_sync_log'] = $logs['full'];
		} elseif ( $kind >= 5 || 1 === $kind ) {
			$meta['_tcgiant_sync_log'] = $logs['pushed'];
		}
		$GLOBALS['META'][ $id ] = $meta;
	}
}
/** The products a batch leaves to the logs: pushed, and made before the listing started. */
function open_of( array $ids ) {
	return array_values( array_filter( $ids, function ( $id ) { return ! in_array( $id % 10, array( 0, 1, 9 ), true ); } ) );
}
/** The linked products up to a cursor, in order: what the pass has judged. */
function linked_through( $after ) {
	return array_values( array_filter( array_keys( $GLOBALS['DATES'] ), function ( $id ) use ( $after ) {
		return $id <= $after && '' !== (string) ( $GLOBALS['META'][ $id ]['_ebay_item_id'] ?? '' );
	} ) );
}
/** Of these, those nothing tells: a draft's date, no log - kept nothing. */
function untold_of( array $ids ) {
	return array_values( array_filter( $ids, function ( $id ) { return 4 === $id % 10; } ) );
}
/** What origin() judges, one product at a time, without keeping it. */
function judged_one_by_one() {
	$out = array();
	foreach ( $GLOBALS['META'] as $id => $meta ) {
		if ( '' !== (string) ( $meta['_ebay_item_id'] ?? '' ) ) {
			$saved = $GLOBALS['META'][ $id ];
			if ( isset( $saved['_tcgiant_sync_log'] ) ) {
				$GLOBALS['META'][ $id ]['_tcgiant_sync_log'] = unserialize( $saved['_tcgiant_sync_log'] );
			}
			$out[ $id ] = TCGiant_Sync_Ebay_Live::origin( $id );
			$GLOBALS['META'][ $id ] = $saved;
		}
	}
	return $out;
}
function kept() {
	$out = array();
	foreach ( $GLOBALS['META'] as $id => $meta ) {
		if ( array_key_exists( '_ebay_origin', $meta ) ) {
			$out[ $id ] = $meta['_ebay_origin'];
		}
	}
	return $out;
}
/** Who wrote each origin kept. */
function writers() {
	$out = array();
	foreach ( $GLOBALS['META'] as $id => $meta ) {
		if ( array_key_exists( '_ebay_origin', $meta ) ) {
			$out[ $id ] = $meta['_ebay_origin_by'] ?? '';
		}
	}
	return $out;
}
/** The pass's verdicts so far: import, push, and those nothing told. */
function judged_count() {
	$state = get_option( TCGiant_Sync_Ebay_Live::SWEEP_OPTION, array() );
	return (int) ( $state['import'] ?? 0 ) + (int) ( $state['push'] ?? 0 ) + (int) ( $state['unknown'] ?? 0 );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PASS JUDGES EVERY LINKED PRODUCT AS ORIGIN() DOES, FROM WHAT ITS QUERIES SELECT\n" . str_repeat( '=', 124 ) . "\n";

shop( 1230 );
$GLOBALS['META'][1015]['_ebay_export_last_pushed']  = '2026-01-05 10:02:00';
$GLOBALS['DUPES'][1015]['_ebay_export_last_pushed'] = array( '2026-01-05 10:02:00', '2026-06-01 10:00:00' );
$want = judged_one_by_one();
$GLOBALS['wpdb']->by       = array( 'dates' => 0, 'meta' => 0, 'logs' => 0 );
$GLOBALS['wpdb']->log_rows = array();
TCGiant_Sync_Ebay_Live::sweep_origins();
$state = get_option( TCGiant_Sync_Ebay_Live::SWEEP_OPTION );
$first = linked_through( $state['after'] ?? 0 );
check( 'one batch: SWEEP_BATCH (500) products judged, those that tell kept, not done yet, its cursor at the 500th', array( count( $first ), judged_count(), count( kept() ), empty( $state['done'] ), $state['after'] ?? null ), array( 500, 500, 500 - count( untold_of( $first ) ), true, max( $first ) ) );
$open = open_of( $first );
check( '  its queries: the dates, the rules\' meta, and the open products\' logs 50 at a time', $GLOBALS['wpdb']->by, array( 'dates' => 1, 'meta' => 1, 'logs' => (int) ceil( count( $open ) / 50 ) ) );
check( '  the logs read only those that can say \'import\': not full, with the import\'s first entry', $GLOBALS['wpdb']->log_rows, array_values( array_filter( $open, function ( $id ) { return 2 === $id % 10; } ) ) );
TCGiant_Sync_Ebay_Live::sweep_origins();
TCGiant_Sync_Ebay_Live::sweep_origins();
$state = get_option( TCGiant_Sync_Ebay_Live::SWEEP_OPTION );
check( 'three batches: every linked product judged; the 123 nothing tells (a draft\'s date, no log) keep nothing, nor the 123 with no listing; done', array( count( kept() ), (int) ( $state['unknown'] ?? 0 ), ! empty( $state['done'] ), count( array_diff_key( $GLOBALS['META'], kept() ) ) ), array( 984, 123, true, 246 ) );
$want_kept = array_filter( $want, function ( $origin ) { return TCGiant_Sync_Ebay_Live::UNKNOWN !== $origin; } );
check( '  each exactly as origin() judges it one at a time - a product with two last-pushed rows by the first, as origin() reads it; what origin() cannot tell, not kept', array( kept() === $want_kept, kept()[1015] ?? null, $want[1015] ?? null, array_keys( array_diff_key( $want, $want_kept ) ) === untold_of( array_keys( $want ) ) ), array( true, 'push', 'push', true ) );
check( '  every one kept says the pass wrote it', array_unique( writers() ), array( 1001 => 'sweep' ) );
$by = array_count_values( kept() );
check( '  every rule met: never pushed, made after its start, the import\'s first log entry, or Updated since its listing started - imported; listed by its last push (kind 8, and 1015 by its first last-pushed row) - pushed', array( $by['import'] ?? 0, $by['push'] ?? 0 ), array( 860, 124 ) );
check( '  said once, with the counts', array( count( $GLOBALS['LOG'] ), $GLOBALS['LOG'][0] ?? '' ), array( 1, 'Who made each eBay listing, for the products linked before 3.28.8: 860 judged imported from eBay, whose Updates now leave the listing\'s own category, condition, business policies and location as they are on eBay; 124 judged pushed from WooCommerce, which go on being sent the shop\'s defaults; 123 that what the shop holds cannot tell, each read from eBay once, at its next Update, before anything of the shop\'s defaults is sent. A product\'s eBay tab can say otherwise for it.' ) );
$GLOBALS['wpdb']->queries = 0;
TCGiant_Sync_Ebay_Live::sweep_origins();
check( '  once done, it asks the database nothing more', $GLOBALS['wpdb']->queries, 0 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nIT NEVER WRITES OVER AN ORIGIN, AND A REQUEST STOPPED PART WAY LOSES NOTHING\n" . str_repeat( '=', 124 ) . "\n";

shop( 700 );
$GLOBALS['META'][1005]['_ebay_origin']    = 'push';
$GLOBALS['META'][1005]['_ebay_origin_by'] = 'push';
$GLOBALS['RACE']                          = array( 1011 => 'import', 1012 => 'push' );
$GLOBALS['ADDS']                          = array();
TCGiant_Sync_Ebay_Live::sweep_origins();
check( 'an origin a push wrote is not asked about, and one a push or the import writes meanwhile stays', array( $GLOBALS['META'][1005]['_ebay_origin'], in_array( 1005, $GLOBALS['ADDS'], true ), $GLOBALS['META'][1011]['_ebay_origin'], $GLOBALS['META'][1012]['_ebay_origin'] ), array( 'push', false, 'import', 'push' ) );
check( '  and is not counted', judged_count(), 498 );
$GLOBALS['RACE'] = array();

shop( 700 );
$GLOBALS['ADDS']    = array();
$GLOBALS['KILL_AT'] = 120;
try {
	TCGiant_Sync_Ebay_Live::sweep_origins();
} catch ( RuntimeException $e ) {
	$killed = $e->getMessage();
}
$GLOBALS['KILL_AT'] = 0;
check( 'a request killed after 119 products: those kept, the cursor not moved', array( $killed ?? '', count( kept() ), get_option( TCGiant_Sync_Ebay_Live::SWEEP_OPTION, array() ) ), array( 'killed', 119, array() ) );
TCGiant_Sync_Ebay_Live::sweep_origins();
TCGiant_Sync_Ebay_Live::sweep_origins();
check( '  the next requests take the rest, none of the 119 asked about twice', array( count( kept() ), count( $GLOBALS['ADDS'] ), count( array_unique( $GLOBALS['ADDS'] ) ), ! empty( get_option( TCGiant_Sync_Ebay_Live::SWEEP_OPTION )['done'] ) ), array( 560, 560, 560, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFROM THE HOURLY CHECK, AND FROM ADMIN PAGES AT MOST ONCE A MINUTE, NEVER FROM AJAX\n" . str_repeat( '=', 124 ) . "\n";

shop( 1230 );
$GLOBALS['TRANSIENTS']    = array();
$GLOBALS['wpdb']->queries = 0;
$GLOBALS['AJAX']          = true;
TCGiant_Sync_Ebay_Live::sweep_origins_from_admin();
check( 'an AJAX request - the heartbeat, every few seconds - runs nothing, and sets no tick', array( $GLOBALS['wpdb']->queries, count( kept() ), get_transient( TCGiant_Sync_Ebay_Live::SWEEP_TICK ) ), array( 0, 0, false ) );
TCGiant_Sync_Ebay_Live::sweep_origins();
check( '  nor the hourly check\'s hook run inside one ("Process Queue")', array( $GLOBALS['wpdb']->queries, count( kept() ) ), array( 0, 0 ) );
$GLOBALS['AJAX'] = false;
TCGiant_Sync_Ebay_Live::sweep_origins_from_admin();
check( 'an admin page: one batch, and the minute\'s tick set', array( judged_count(), get_transient( TCGiant_Sync_Ebay_Live::SWEEP_TICK ) ), array( 500, 1 ) );
TCGiant_Sync_Ebay_Live::sweep_origins_from_admin();
check( '  the next page within the minute: nothing', judged_count(), 500 );
$GLOBALS['TRANSIENTS'] = array();
TCGiant_Sync_Ebay_Live::sweep_origins_from_admin();
TCGiant_Sync_Ebay_Live::sweep_origins();
$GLOBALS['wpdb']->queries = 0;
$GLOBALS['TRANSIENTS']    = array();
TCGiant_Sync_Ebay_Live::sweep_origins_from_admin();
check( '  done: an admin page asks nothing, and sets no tick', array( $GLOBALS['wpdb']->queries, get_transient( TCGiant_Sync_Ebay_Live::SWEEP_TICK ) ), array( 0, false ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE LIVE CHECK'S PRODUCT: IMPORTED LONG AGO, UPDATED SINCE, NO START TIME\n" . str_repeat( '=', 124 ) . "\n";

/** A pushed sync log of 20 entries, the oldest from 18 June 2026: the import's first entry long gone. */
function rolled_log() {
	$log = array();
	for ( $i = 0; $i < 20; $i++ ) {
		$log[] = array( 'time' => sprintf( '2026-%02d-%02d 10:00:00', 10 - intdiv( $i, 6 ), 28 - $i ), 'source' => 'delta', 'item' => '336419685448', 'decisions' => array( 'Price updated (eBay won)', 'Stock updated from eBay: 1 -> 1' ) );
	}
	$log[19]['time'] = '2026-06-18 10:00:00';
	return serialize( $log );
}
/**
 * The product the live check found judged 'push' (WC #11755): made on 9 April
 * 2026 by an older import, its listing on eBay since 3 February; SKU
 * "EBAY-336419685448", the import's form for a listing with none, and
 * _ebay_sku, both written before the item number by the import's own save;
 * Updated once by 3.27.8 (pushed, 28 October); its own Item Type, grading and
 * category; no start or end time, no record of eBay's values; a full sync
 * log; and the 3.28.8 preview's 'push', which no writer said.
 */
function lilo( $id, $item = '336419685448' ) {
	$GLOBALS['DATES'][ $id ] = '2026-04-09 08:00:00';
	$GLOBALS['META'][ $id ]  = array(
		'_sku'                        => 'EBAY-' . $item,
		'_ebay_sku'                   => 'EBAY-' . $item,
		'_ebay_item_id'               => $item,
		'_ebay_listing_type'          => 'FixedPriceItem',
		'_ebay_listing_status'        => 'Active',
		'_tcgiant_sync_log'           => rolled_log(),
		'_ebay_export_item_type'      => 'tcg',
		'_ebay_export_condition_type' => 'graded',
		'_ebay_export_category_id'    => '183454',
		'_ebay_export_status'         => 'pushed',
		'_ebay_export_last_pushed'    => '2026-10-28 15:00:00',
		'_ebay_export_pushed_version' => '3.27.8',
		'_ebay_origin'                => 'push',
	);
}
/** origin() of a product, one at a time, as if nothing were kept: the log unserialized, as get_post_meta() gives it. */
function judged_afresh( $id ) {
	$saved = $GLOBALS['META'][ $id ];
	unset( $GLOBALS['META'][ $id ]['_ebay_origin'], $GLOBALS['META'][ $id ]['_ebay_origin_by'] );
	if ( isset( $saved['_tcgiant_sync_log'] ) ) {
		$GLOBALS['META'][ $id ]['_tcgiant_sync_log'] = unserialize( $saved['_tcgiant_sync_log'] );
	}
	$out                    = TCGiant_Sync_Ebay_Live::origin( $id );
	$GLOBALS['META'][ $id ] = $saved;
	return $out;
}

$GLOBALS['META']    = array();
$GLOBALS['DATES']   = array();
$GLOBALS['OPTIONS'] = array( TCGiant_Sync_Ebay_Live::SWEEP_OPTION_PREVIEW => array( 'done' => 1, 'import' => 88, 'push' => 1 ) );
$GLOBALS['LOG']     = array();
$GLOBALS['TABLE']   = array();
lilo( 11755 );
check( 'the preview\'s pass done, and Lilo stored \'push\' with no writer: origin() judges it afresh \'import\', by its SKU', judged_afresh( 11755 ), 'import' );
TCGiant_Sync_Ebay_Live::sweep_origins();
check( '  the pass judges it again, though the preview\'s is done: \'import\', and says it wrote it', array( $GLOBALS['META'][11755]['_ebay_origin'], $GLOBALS['META'][11755]['_ebay_origin_by'] ?? '', ! empty( get_option( TCGiant_Sync_Ebay_Live::SWEEP_OPTION )['done'] ) ), array( 'import', 'sweep', true ) );
check( '  the import\'s form in _ebay_sku alone, the shop having changed the SKU since: imported too', ( function () {
	lilo( 11757 );
	$GLOBALS['META'][11757]['_sku'] = 'SHOP-LILO';
	return judged_afresh( 11757 );
} )(), 'import' );
check( '  the plain-number form (sku_prefix "none") too', ( function () {
	lilo( 11756 );
	$GLOBALS['META'][11756]['_sku']      = '336419685448';
	$GLOBALS['META'][11756]['_ebay_sku'] = '336419685448';
	return judged_afresh( 11756 );
} )(), 'import' );

// A product the shop pushed with no SKU of its own: the import gave it the
// listing's number at the first sync after the push, so its _ebay_sku row
// comes after the _ebay_item_id row the push wrote.
function pushed_no_sku( $id, $item ) {
	$GLOBALS['DATES'][ $id ] = '2026-03-01 08:00:00';
	$GLOBALS['META'][ $id ]  = array(
		'_sku'                     => '',
		'_ebay_export_status'      => 'pushed',
		'_ebay_export_last_pushed' => '2026-10-20 10:00:00',
		'_ebay_item_id'            => $item,
		'_ebay_listing_status'     => 'Active',
		'_tcgiant_sync_log'        => rolled_log(),
		'_ebay_sku'                => 'EBAY-' . $item,
	);
	$GLOBALS['META'][ $id ]['_sku'] = 'EBAY-' . $item;
}
pushed_no_sku( 12001, '336400000001' );
check( 'a product pushed with no SKU, given the number\'s form by a later sync: the SKU tells nothing - not known', judged_afresh( 12001 ), TCGiant_Sync_Ebay_Live::UNKNOWN );
pushed_no_sku( 12002, '336400000002' );
$GLOBALS['META'][12002]['_ebay_start_time']         = '2026-03-02T10:00:00.000Z';
$GLOBALS['META'][12002]['_ebay_export_last_pushed'] = '2026-03-02 10:05:00';
check( '  with its start recorded, after it was made, and its last push the one that listed it: the shop\'s', judged_afresh( 12002 ), 'push' );
lilo( 12003, '336400000003' );
$GLOBALS['META'][12003]['_sku']      = 'ABC-77';
$GLOBALS['META'][12003]['_ebay_sku'] = 'ABC-77';
check( 'a seller\'s own SKU, recorded first by the import\'s save: imported all the same - whatever the SKU\'s form (round 7)', judged_afresh( 12003 ), 'import' );

// The Listings table's start, where the product holds none.
pushed_no_sku( 12004, '336400000004' );
$GLOBALS['TABLE'][12004] = array( 'ebay_item_id' => '336400000004', 'ebay_start_time' => '2026-02-03T20:54:31.000Z' );
check( 'no start on the product, the table\'s for the same listing before it was made: imported', judged_afresh( 12004 ), 'import' );
pushed_no_sku( 12005, '336400000005' );
$GLOBALS['TABLE'][12005] = array( 'ebay_item_id' => '336400000005', 'ebay_start_time' => '2026-10-20T10:00:00.000Z' );
check( '  the table\'s after it was made - which every push, an Update too, writes as its own time: never the shop\'s for that, not known', judged_afresh( 12005 ), TCGiant_Sync_Ebay_Live::UNKNOWN );
pushed_no_sku( 12006, '336400000006' );
$GLOBALS['TABLE'][12006] = array( 'ebay_item_id' => '336499999998', 'ebay_start_time' => '2026-02-03T20:54:31.000Z' );
check( '  the table\'s row of another listing: not read', judged_afresh( 12006 ), TCGiant_Sync_Ebay_Live::UNKNOWN );

// Judged again: the preview's (no writer said) and a pass's own; never a
// push's, a link's, the import's or a push's judgement.
pushed_no_sku( 12010, '336400000010' );
$GLOBALS['META'][12010]['_ebay_origin'] = 'push';
pushed_no_sku( 12011, '336400000011' );
$GLOBALS['META'][12011]['_ebay_origin']    = 'import';
$GLOBALS['META'][12011]['_ebay_origin_by'] = 'sweep';
foreach ( array( 12012 => 'push', 12013 => 'link', 12014 => 'import', 12015 => 'judged' ) as $id => $by ) {
	pushed_no_sku( $id, '3364000000' . $id );
	$GLOBALS['META'][ $id ]['_ebay_origin']    = 'link' === $by || 'import' === $by ? 'import' : 'push';
	$GLOBALS['META'][ $id ]['_ebay_origin_by'] = $by;
}
pushed_no_sku( 12016, '336400000016' );
$GLOBALS['META'][12016]['_ebay_origin'] = 'push';
$GLOBALS['RACE_BY'][12016]              = array( 'push', 'push' );
lilo( 12017, '336400000017' );
$GLOBALS['META'][12017]['_ebay_origin'] = 'import';
$before = array_map( function ( $id ) { return $GLOBALS['META'][ $id ]['_ebay_origin'] . '/' . ( $GLOBALS['META'][ $id ]['_ebay_origin_by'] ?? '' ); }, array( 12012 => 12012, 12013 => 12013, 12014 => 12014, 12015 => 12015 ) );
$GLOBALS['OPTIONS'] = array();
$GLOBALS['LOG']     = array();
TCGiant_Sync_Ebay_Live::sweep_origins();
check( 'the preview\'s \'push\' where nothing tells: taken off, so the first Update reads the listing', array( array_key_exists( '_ebay_origin', $GLOBALS['META'][12010] ), TCGiant_Sync_Ebay_Live::origin( 12010 ) ), array( false, TCGiant_Sync_Ebay_Live::UNKNOWN ) );
check( '  a pass\'s own \'import\' where nothing tells now: kept - an import is never taken off (round 7)', array( $GLOBALS['META'][12011]['_ebay_origin'] ?? null, $GLOBALS['META'][12011]['_ebay_origin_by'] ?? null ), array( 'import', 'sweep' ) );
check( '  a push\'s, a link\'s, the import\'s and a push\'s judgement: never asked about, never touched', array_map( function ( $id ) { return $GLOBALS['META'][ $id ]['_ebay_origin'] . '/' . ( $GLOBALS['META'][ $id ]['_ebay_origin_by'] ?? '' ); }, array( 12012 => 12012, 12013 => 12013, 12014 => 12014, 12015 => 12015 ) ), $before );
check( '  one a push wrote, with its writer, while the pass judged it: left as the push wrote it', array( $GLOBALS['META'][12016]['_ebay_origin'], $GLOBALS['META'][12016]['_ebay_origin_by'] ?? '' ), array( 'push', 'push' ) );
check( '  the preview\'s \'import\' the pass agrees with: kept, and now said to be the pass\'s', array( $GLOBALS['META'][12017]['_ebay_origin'], $GLOBALS['META'][12017]['_ebay_origin_by'] ?? '' ), array( 'import', 'sweep' ) );
check( '  the table\'s start, as origin() reads it: before it was made, imported; after, or another listing\'s, nothing kept', array( kept()[12004] ?? null, kept()[12005] ?? null, kept()[12006] ?? null, $GLOBALS['wpdb']->by['table'] ?? 0 ), array( 'import', null, null, 1 ) );
$GLOBALS['RACE_BY'] = array();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nROUND 7: NEVER THE SHOP'S WITHOUT EVIDENCE - RELISTS, SKU LINKS, THE PASS\n" . str_repeat( '=', 124 ) . "\n";

$GLOBALS['META']    = array();
$GLOBALS['DATES']   = array();
$GLOBALS['OPTIONS'] = array();
$GLOBALS['LOG']     = array();
$GLOBALS['TABLE']   = array();
$GLOBALS['MARKS']   = array();
/** A pushed product's meta, the item number written before any _ebay_sku, as a push links one. */
function linked_by_push( $id, $made, $item, array $more = array() ) {
	$GLOBALS['DATES'][ $id ] = $made;
	$GLOBALS['META'][ $id ]  = array_merge( array( '_sku' => 'SKU-' . $id, '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-08-01 10:00:00', '_ebay_item_id' => $item, '_ebay_listing_status' => 'Active', '_ebay_sku' => 'SKU-' . $id, '_tcgiant_sync_log' => rolled_log() ), $more );
}
/** A shop product the import linked by its SKU (the save writes _ebay_sku, then the item number), Updated since. */
function linked_by_sku( $id, $made, $item, $sku, array $more = array() ) {
	$GLOBALS['DATES'][ $id ] = $made;
	$GLOBALS['META'][ $id ]  = array_merge( array( '_sku' => $sku, '_ebay_sku' => $sku, '_ebay_item_id' => $item, '_ebay_listing_status' => 'Active', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-07-01 10:00:00', '_tcgiant_sync_log' => rolled_log() ), $more );
}

// P1: the preview's 'import' with no writer (its Link or SKU link), a shop
// product older than the listing, Updated since: the rules would say the
// shop's; the pass never does.
linked_by_push( 13001, '2025-06-01 10:00:00', '336400000101', array( '_ebay_origin' => 'import', '_ebay_start_time' => '2026-10-20T10:00:00.000Z' ) );
check( 'P1. an \'import\' no writer said, the rules saying the shop\'s: the pass keeps \'import\', and confirms it', ( function () {
	TCGiant_Sync_Ebay_Live::sweep_origins();
	return array( $GLOBALS['META'][13001]['_ebay_origin'] ?? null, $GLOBALS['META'][13001]['_ebay_origin_by'] ?? null );
} )(), array( 'import', 'sweep' ) );

// P2: an import relisted on eBay - its start moved past its date, the listing
// it replaced recorded - with a seller SKU and a full log, Updated once.
$GLOBALS['OPTIONS'] = array();
linked_by_sku( 13002, '2026-03-01 10:00:00', '336500000002', 'LOT-12', array( '_ebay_previous_item_id' => '336300000009', '_ebay_start_time' => '2026-09-01T10:00:00.000Z' ) );
linked_by_push( 13012, '2026-03-01 10:00:00', '336500000012', array( '_ebay_previous_item_id' => '336300000019', '_ebay_start_time' => '2026-09-01T10:00:00.000Z' ) );
check( 'P2. a relisted import: imported, by its _ebay_sku recorded first; with that order lost, imported by the dates (round 8) - never the shop\'s', array( judged_afresh( 13002 ), judged_afresh( 13012 ) ), array( 'import', 'import' ) );
TCGiant_Sync_Ebay_Live::sweep_origins();
check( '  the pass keeps both \'import\'', array( $GLOBALS['META'][13002]['_ebay_origin'] ?? null, $GLOBALS['META'][13012]['_ebay_origin'] ?? null ), array( 'import', 'import' ) );
foreach ( array( '_ebay_relist_parent_id' => '336300000029', '_ebay_relist_tried' => '1730000000', '_ebay_relist_held' => '1>2', '_ebay_relist_unsendable' => 'x', '_ebay_relist_stock_check' => array( 'item' => '1' ) ) as $mark => $value ) {
	linked_by_push( 13020, '2025-06-01 10:00:00', '336500000020', array( '_ebay_start_time' => '2026-09-01T10:00:00.000Z', '_ebay_export_last_pushed' => '2026-09-01 10:03:00', $mark => $value ) );
	$by_mark[ $mark ] = judged_afresh( 13020 );
}
linked_by_push( 13020, '2025-06-01 10:00:00', '336500000020', array( '_ebay_start_time' => '2026-09-01T10:00:00.000Z', '_ebay_export_last_pushed' => '2026-09-01 10:03:00' ) );
$by_mark['none'] = judged_afresh( 13020 );
check( '  every relist mark: a relist, so the push three minutes after its start did not make the first listing - imported; with none, the shop\'s', $by_mark, array( '_ebay_relist_parent_id' => 'import', '_ebay_relist_tried' => 'import', '_ebay_relist_held' => 'import', '_ebay_relist_unsendable' => 'import', '_ebay_relist_stock_check' => 'import', 'none' => 'push' ) );

// P3: not known (no start, the order lost), relisted on eBay: the push's
// read gives the relist's start and its RelistParentID.
linked_by_push( 13003, '2026-03-01 10:00:00', '336500000003' );
$told = null;
check( 'P3. not known, then read - a relist (RelistParentID), its start after the product: imported by the dates, judged', array( TCGiant_Sync_Ebay_Live::origin( 13003 ), TCGiant_Sync_Ebay_Live::learn_origin( 13003, array( 'ItemID' => '336500000003', 'RelistParentID' => '336300000010', 'ListingDetails' => array( 'StartTime' => '2026-09-01T10:00:00.000Z' ) ), $told ), $told, $GLOBALS['META'][13003]['_ebay_origin_by'] ?? null ), array( TCGiant_Sync_Ebay_Live::UNKNOWN, 'import', true, 'judged' ) );
linked_by_push( 13013, '2026-03-01 10:00:00', '336500000013', array( '_ebay_export_last_pushed' => '2026-09-01 10:04:00' ) );
check( '  the same read with no RelistParentID, its last push four minutes after the start: the push listed it - the shop\'s', array( TCGiant_Sync_Ebay_Live::learn_origin( 13013, array( 'ItemID' => '336500000013', 'ListingDetails' => array( 'StartTime' => '2026-09-01T10:00:00.000Z' ) ), $told ), $told ), array( 'push', true ) );
linked_by_push( 13014, '2026-03-01 10:00:00', '336500000014', array( '_ebay_export_last_pushed' => '2026-09-02 10:04:00' ) );
check( '  the same, Updated a day after the start: imported (P8 of round 7\'s check: nothing tells a push that listed it from a Link)', TCGiant_Sync_Ebay_Live::learn_origin( 13014, array( 'ItemID' => '336500000014', 'ListingDetails' => array( 'StartTime' => '2026-09-01T10:00:00.000Z' ) ) ), 'import' );
$GLOBALS['MARKS']['TCG1.abcdef01.13023'] = 13023;
linked_by_push( 13023, '2026-03-01 10:00:00', '336500000023' );
check( '  a relist carrying this product\'s own push mark (3.28.7): the shop\'s - the mark is evidence, the date is not needed', array( TCGiant_Sync_Ebay_Live::learn_origin( 13023, array( 'ItemID' => '336500000023', 'RelistParentID' => '336300000030', 'ApplicationData' => 'TCG1.abcdef01.13023', 'ListingDetails' => array( 'StartTime' => '2026-09-01T10:00:00.000Z' ) ), $told ), $told ), array( 'push', true ) );
$GLOBALS['MARKS']['TCG1.abcdef01.99999'] = 99999;
linked_by_push( 13033, '2026-03-01 10:00:00', '336500000033' );
check( '  another product\'s mark: not this one\'s, and a relist - imported', TCGiant_Sync_Ebay_Live::learn_origin( 13033, array( 'ItemID' => '336500000033', 'RelistParentID' => '1', 'ApplicationData' => 'TCG1.abcdef01.99999', 'ListingDetails' => array( 'StartTime' => '2026-09-01T10:00:00.000Z' ) ) ), 'import' );
linked_by_sku( 13043, '2024-05-01 10:00:00', '336500000043', 'ABC-43' );
$GLOBALS['MARKS']['TCG1.abcdef01.13043'] = 13043;
check( '  import evidence first: a SKU link whose listing carries the mark (its own push in place of an ended one) stays imported', TCGiant_Sync_Ebay_Live::learn_origin( 13043, array( 'ItemID' => '336500000043', 'ApplicationData' => 'TCG1.abcdef01.13043', 'ListingDetails' => array( 'StartTime' => '2025-01-10T10:00:00.000Z' ) ) ), 'import' );

// P4: a draft's zero date: the read gives a start, and still nothing tells.
$GLOBALS['DATES'][13004] = '0000-00-00 00:00:00';
$GLOBALS['META'][13004]  = array( '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-08-01 10:00:00', '_ebay_item_id' => '336500000004' );
check( 'P4. a draft\'s zero date, read: kept \'import\', judged, so it is not read again', array( TCGiant_Sync_Ebay_Live::learn_origin( 13004, array( 'ItemID' => '336500000004', 'ListingDetails' => array( 'StartTime' => '2026-08-01T10:00:00.000Z' ) ), $told ), $told, TCGiant_Sync_Ebay_Live::origin( 13004 ), $GLOBALS['META'][13004]['_ebay_origin_by'] ?? null ), array( 'import', false, 'import', 'judged' ) );
check( '  an answer about another listing keeps nothing', array( TCGiant_Sync_Ebay_Live::learn_origin( 13013 + 1000, array( 'ItemID' => '1' ) ) ), array( '' ) );

// P5: a shop product linked by its SKU before 3.28.8 to a listing made
// outside the plugin - by hand on eBay, or a WP-Lister shop moving over - and
// Updated once: made long before its listing started.
linked_by_sku( 13005, '2024-05-01 10:00:00', '336500000005', 'ABC-77', array( '_ebay_start_time' => '2025-01-10T10:00:00.000Z' ) );
check( 'P5. a pre-3.28.8 SKU link, Updated once: imported, by the import\'s save writing _ebay_sku first', judged_afresh( 13005 ), 'import' );
linked_by_sku( 13015, '2024-02-01 10:00:00', '336500000015', 'WPL-15', array( '_ebay_start_time' => '2025-03-01T10:00:00.000Z', '_ebay_export_pushed_version' => '3.25.0' ) );
check( '  a WP-Lister shop\'s product (the converter writes no product meta: its products arrive by the import\'s SKU match), Updated once: imported', judged_afresh( 13015 ), 'import' );
linked_by_push( 13025, '2024-05-01 10:00:00', '336500000025', array( '_ebay_start_time' => '2025-01-10T10:00:00.000Z' ) );
check( '  the item number written before any _ebay_sku, made before its listing started, never relisted, but its last push long after that start: imported (round 8 - a push, a Link and a re-link all look so)', judged_afresh( 13025 ), 'import' );
$GLOBALS['OPTIONS'] = array();
TCGiant_Sync_Ebay_Live::sweep_origins();
check( '  the pass keeps the same', array( $GLOBALS['META'][13005]['_ebay_origin'] ?? null, $GLOBALS['META'][13015]['_ebay_origin'] ?? null, $GLOBALS['META'][13025]['_ebay_origin'] ?? null ), array( 'import', 'import', 'import' ) );

// P6, and the pass's own 'push' turned into 'import'.
$GLOBALS['OPTIONS'] = array();
$GLOBALS['DATES'][13006] = '2026-10-28 10:00:00';
$GLOBALS['META'][13006]  = array( '_ebay_item_id' => '336500000006', '_ebay_origin' => 'push', '_ebay_export_last_pushed' => '2026-10-29 10:00:00', '_ebay_export_status' => 'pushed' );
$GLOBALS['TABLE'][13006] = array( 'ebay_item_id' => '336500000006', 'ebay_start_time' => '2026-10-29T10:00:00.000Z' );
linked_by_sku( 13016, '2026-03-01 10:00:00', '336500000016', 'LOT-16', array( '_ebay_origin' => 'push', '_ebay_origin_by' => 'sweep' ) );
TCGiant_Sync_Ebay_Live::sweep_origins();
check( 'P6. the preview\'s \'push\' with nothing to say (the table\'s start a push\'s own): taken off; a pass\'s own \'push\' the rules now call imported: turned \'import\'', array( $GLOBALS['META'][13006]['_ebay_origin'] ?? null, $GLOBALS['META'][13016]['_ebay_origin'] ?? null, $GLOBALS['META'][13016]['_ebay_origin_by'] ?? null ), array( null, 'import', 'sweep' ) );
$GLOBALS['TABLE'] = array();
$GLOBALS['MARKS'] = array();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nROUND 8: THE SHOP'S BY DATE ONLY WHERE ITS LAST PUSH LISTED IT\n" . str_repeat( '=', 124 ) . "\n";

$GLOBALS['META']    = array();
$GLOBALS['DATES']   = array();
$GLOBALS['OPTIONS'] = array();
/** A product the plugin pushed (the push wrote the item number; a sync the _ebay_sku later), listed at 10:00 UTC on 5 January 2026, last pushed at $last (the site's time). */
function pushed_once( $id, $last, array $more = array() ) {
	linked_by_push( $id, '2025-12-01 09:00:00', '3366000' . $id, array_merge( array( '_ebay_start_time' => '2026-01-05T10:00:00.000Z', '_ebay_export_last_pushed' => $last ), $more ) );
}
pushed_once( 14001, '2026-01-05 10:01:30' );
pushed_once( 14002, '2026-01-06 10:00:00' );
pushed_once( 14003, '2026-01-05 10:16:00' );
pushed_once( 14004, '2026-01-05 09:59:30' );
pushed_once( 14005, '2026-01-05 09:58:00' );
check( 'R8. pushed once and never Updated (its last push 90 s after the start): the shop\'s; Updated a day later: imported; 16 minutes after: imported; 30 s before (a clock behind eBay\'s): the shop\'s; 2 minutes before: imported', array( judged_afresh( 14001 ), judged_afresh( 14002 ), judged_afresh( 14003 ), judged_afresh( 14004 ), judged_afresh( 14005 ) ), array( 'push', 'import', 'import', 'push', 'import' ) );
$GLOBALS['TZ'] = '-08:00';
pushed_once( 14006, '2026-01-05 02:04:00' );
pushed_once( 14007, '2026-01-05 10:04:00' );
check( '  a site at UTC-8: its last push at 02:04 its time is 10:04 UTC - the shop\'s; 10:04 its time is 18:04 UTC - imported', array( judged_afresh( 14006 ), judged_afresh( 14007 ) ), array( 'push', 'import' ) );
$GLOBALS['TZ'] = 'UTC';
check( '  the same 02:04 read as UTC would be eight hours before the start: imported - so the zone is what tells', judged_afresh( 14006 ), 'import' );
$GLOBALS['TZ'] = '-08:00';
TCGiant_Sync_Ebay_Live::sweep_origins();
check( '  the pass judges the same, at UTC-8', array( $GLOBALS['META'][14006]['_ebay_origin'] ?? null, $GLOBALS['META'][14007]['_ebay_origin'] ?? null ), array( 'push', 'import' ) );
$GLOBALS['TZ'] = 'UTC';

// Where the window alone would say the shop's, each earlier rule still
// decides: the import's first log entry, a relist mark the pass reads, a
// relist the read shows, an 'import' kept already.
$GLOBALS['OPTIONS'] = array();
pushed_once( 14011, '2026-01-05 10:02:00', array( '_tcgiant_sync_log' => logs()['young'] ) );
pushed_once( 14012, '2026-01-05 10:02:00', array( '_ebay_previous_item_id' => '3366000014000' ) );
pushed_once( 14013, '2026-01-05 10:02:00', array( '_ebay_origin' => 'import' ) );
TCGiant_Sync_Ebay_Live::sweep_origins();
check( '  listed by its last push, but its log begins with the import, or its listing is a relist: the pass keeps \'import\'; an \'import\' no writer said is kept and confirmed though the window says the shop\'s', array( $GLOBALS['META'][14011]['_ebay_origin'] ?? null, $GLOBALS['META'][14012]['_ebay_origin'] ?? null, $GLOBALS['META'][14013]['_ebay_origin'] ?? null, $GLOBALS['META'][14013]['_ebay_origin_by'] ?? null ), array( 'import', 'import', 'import', 'sweep' ) );
pushed_once( 14014, '2026-01-05 10:02:00' );
unset( $GLOBALS['META'][14014]['_ebay_start_time'] );
check( '  a read that shows a relist, the last push two minutes after the relist\'s start: imported; the same read showing none: the shop\'s', array( TCGiant_Sync_Ebay_Live::learn_origin( 14014, array( 'ItemID' => '336600014014', 'RelistParentID' => '1', 'ListingDetails' => array( 'StartTime' => '2026-01-05T10:00:00.000Z' ) ) ), ( function () {
	pushed_once( 14015, '2026-01-05 10:02:00' );
	unset( $GLOBALS['META'][14015]['_ebay_start_time'] );
	return TCGiant_Sync_Ebay_Live::learn_origin( 14015, array( 'ItemID' => '336600014015', 'ListingDetails' => array( 'StartTime' => '2026-01-05T10:00:00.000Z' ) ) );
} )() ), array( 'import', 'push' ) );

// P8: a shop product linked by hand before 3.28.8 (ajax_link_ebay(), 3.17.0
// to 3.28.7: the item number and, since 3.22.0, the start; no _ebay_sku -
// the next sync's save adds it), to a listing made by hand on eBay or by
// WP-Lister, Updated once since.
$GLOBALS['DATES'][14008] = '2025-06-01 10:00:00';
$GLOBALS['META'][14008]  = array( '_sku' => 'HAND-8', '_ebay_item_id' => '336600014008', '_ebay_start_time' => '2026-01-05T10:00:00.000Z', '_ebay_listing_status' => 'Active', '_ebay_sku' => 'HAND-8', '_tcgiant_sync_log' => rolled_log(), '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-03-01 10:00:00' );
check( 'P8. linked by hand before 3.28.8, Updated since: imported', judged_afresh( 14008 ), 'import' );
$GLOBALS['DATES'][14018] = '2025-06-01 10:00:00';
$GLOBALS['META'][14018]  = array( '_sku' => 'HAND-18', '_ebay_item_id' => '336600014018', '_ebay_listing_status' => 'Active', '_ebay_sku' => 'HAND-18', '_tcgiant_sync_log' => rolled_log(), '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-03-01 10:00:00' );
check( '  P8b. the same before 3.22.0 (no start kept): not known - then its read (no RelistParentID): imported, judged', array( judged_afresh( 14018 ), TCGiant_Sync_Ebay_Live::learn_origin( 14018, array( 'ItemID' => '336600014018', 'ListingDetails' => array( 'StartTime' => '2026-01-05T10:00:00.000Z' ) ) ), $GLOBALS['META'][14018]['_ebay_origin_by'] ?? null ), array( TCGiant_Sync_Ebay_Live::UNKNOWN, 'import', 'judged' ) );
// P9: an imported product Unlinked (the item number, _ebay_sku, the log and
// the last push dropped), then linked by hand to a newer listing made on eBay.
$GLOBALS['DATES'][14009] = '2025-03-01 10:00:00';
$GLOBALS['META'][14009]  = array( '_sku' => 'IMP-9', '_ebay_item_id' => '336600014009', '_ebay_start_time' => '2026-01-05T10:00:00.000Z', '_ebay_listing_status' => 'Active', '_ebay_sku' => 'IMP-9', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-02-10 10:00:00' );
check( 'P9. imported, Unlinked, then linked by hand to a newer listing and Updated: imported', judged_afresh( 14009 ), 'import' );
// P10: the import's re-link of a pushed product over its ended listing
// (mapper :1744, the item number in place, no relist mark) to the copy the
// seller put up with Sell Similar and the same SKU.
pushed_once( 14010, '2025-12-20 10:00:00', array( '_ebay_start_time' => '2026-02-01T10:00:00.000Z' ) );
$GLOBALS['OPTIONS'] = array();
check( 'P10. a pushed product re-linked by the import over its ended listing to a Sell Similar copy: its last push before that listing began - imported', judged_afresh( 14010 ), 'import' );
TCGiant_Sync_Ebay_Live::sweep_origins();
check( '  the pass keeps all three \'import\'', array( $GLOBALS['META'][14008]['_ebay_origin'] ?? null, $GLOBALS['META'][14009]['_ebay_origin'] ?? null, $GLOBALS['META'][14010]['_ebay_origin'] ?? null ), array( 'import', 'import', 'import' ) );

// The live store's shape: 89 linked products, every one imported - made by
// the import (_ebay_sku first), some never pushed, some Updated since, some
// relisted on eBay, some with their start recorded - none changes.
$GLOBALS['META']    = array();
$GLOBALS['DATES']   = array();
$GLOBALS['OPTIONS'] = array();
for ( $i = 0; $i < 89; $i++ ) {
	$id   = 15000 + $i;
	$meta = array( '_sku' => 'EBAY-33660' . $id, '_ebay_sku' => 'EBAY-33660' . $id, '_ebay_item_id' => '33660' . $id, '_ebay_listing_status' => 'Active', '_tcgiant_sync_log' => rolled_log() );
	if ( 0 !== $i % 3 ) {
		$meta['_ebay_export_status']      = 'pushed';
		$meta['_ebay_export_last_pushed'] = 1 === $i % 3 ? '2026-10-28 15:00:00' : '2026-01-05 10:03:00';
	}
	if ( 0 === $i % 4 ) {
		$meta['_ebay_start_time'] = '2026-01-05T10:00:00.000Z';
	}
	if ( 0 === $i % 5 ) {
		$meta['_ebay_previous_item_id'] = '33650' . $id;
	}
	$GLOBALS['DATES'][ $id ] = '2025-11-01 10:00:00';
	$GLOBALS['META'][ $id ]  = $meta;
}
TCGiant_Sync_Ebay_Live::sweep_origins();
check( 'LIVE STORE. 89 imports - some never pushed, some Updated, some pushed minutes after a start, some relisted, some with no start: all \'import\', none \'push\', none left unknown', array_count_values( kept() ), array( 'import' => 89 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nMEMORY: A 15,000-PRODUCT SHOP\n" . str_repeat( '=', 124 ) . "\n";

shop( 15000 );
$GLOBALS['ADDS']          = array();
$GLOBALS['wpdb']->queries = 0;
$GLOBALS['wpdb']->rows    = 0;
$kept_growth              = array();
$peak_growth              = array();
$per_batch                = array();
$batches                  = 0;
while ( empty( get_option( TCGiant_Sync_Ebay_Live::SWEEP_OPTION, array() )['done'] ) && $batches < 100 ) {
	$GLOBALS['ADDS'] = array();
	gc_collect_cycles();
	$asked  = $GLOBALS['wpdb']->queries;
	$before = memory_get_usage();
	if ( function_exists( 'memory_reset_peak_usage' ) ) {
		memory_reset_peak_usage();
	}
	TCGiant_Sync_Ebay_Live::sweep_origins();
	$peak_growth[] = memory_get_peak_usage() - $before;
	// What the batch left behind: the origins it wrote, which the shop's
	// meta - played here in memory - now holds, and nothing of what it read.
	$kept_growth[] = memory_get_usage() - $before;
	$per_batch[]   = $GLOBALS['wpdb']->queries - $asked;
	$batches++;
}
check( 'all 13,500 linked products judged in 27 batches and a 28th that finds none; the 1,500 nothing tells keep nothing', array( judged_count(), count( kept() ), $batches ), array( 13500, 12000, 28 ) );
check( '  a batch asks the dates, the meta, and at most 10 queries of 50 logs; each is flushed', array( max( $per_batch ) <= 12, $GLOBALS['wpdb']->flushed >= $GLOBALS['wpdb']->by['logs'] ), array( true, true ) );
check( '  never more than 500 rows of dates, about 2,500 of meta (five keys a product) and the few young logs a batch', $GLOBALS['wpdb']->rows <= 27 * ( 500 + 2500 + 60 ), true );
check( '  nothing a batch read is kept: it leaves only the origins it wrote, under 64 KB, whatever the shop\'s size', max( $kept_growth ) < 65536, true );
printf( "        (left behind by a batch: at most %d bytes)\n", max( $kept_growth ) );
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	check( '  and at its height a batch, with real-sized logs handed back as copies, holds under 2 MB', max( $peak_growth ) < 2 * 1024 * 1024, true );
	printf( "        (largest batch: %d KB at its height)\n", (int) ( max( $peak_growth ) / 1024 ) );
}

$GLOBALS['harness_done'] = true;
printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail ? 1 : 0 );
