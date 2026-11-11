<?php
/**
 * A played WordPress for Re-send to eBay (3.34.0): the template world, with
 * Action Scheduler, the options table's rows the re-send's holds and the
 * call counter read, the queries it makes of posts and post meta, the
 * Listings table's last_synced, and eBay's budget figures.
 *
 * Loaded for real besides the template world's: the re-send class, the
 * listing link's lookup, the templates' admin handlers where a harness asks.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/template-world.php';

$RS_ROOT = dirname( __DIR__, 2 );

// --- Action Scheduler, played --------------------------------------------------

$GLOBALS['AS'] = array( 'actions' => array(), 'next' => 1, 'ran' => array() );

function as_schedule_single_action( $time, $hook, $args = array(), $group = '' ) {
	$id = $GLOBALS['AS']['next']++;
	$GLOBALS['AS']['actions'][ $id ] = array( 'time' => (int) $time, 'hook' => $hook, 'args' => (array) $args, 'group' => (string) $group );
	return $id;
}
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	return as_schedule_single_action( time(), $hook, $args, $group );
}
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['AS']['actions'] as $a ) {
		if ( $a['hook'] === $hook && ( null === $args || $a['args'] == $args ) && ( '' === $group || $a['group'] === $group ) ) {
			return $a['time'];
		}
	}
	return false;
}
function as_unschedule_action( $hook, $args = array(), $group = '' ) {
	foreach ( $GLOBALS['AS']['actions'] as $id => $a ) {
		if ( $a['hook'] === $hook && $a['args'] == (array) $args && ( '' === $group || $a['group'] === $group ) ) {
			unset( $GLOBALS['AS']['actions'][ $id ] );
			return $id;
		}
	}
	return null;
}
function as_unschedule_all_actions( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['AS']['actions'] as $id => $a ) {
		if ( $a['hook'] === $hook && ( null === $args || $a['args'] == $args ) && ( '' === $group || $a['group'] === $group ) ) {
			unset( $GLOBALS['AS']['actions'][ $id ] );
		}
	}
}
function as_get_scheduled_actions( $args = array(), $return = 'OBJECT' ) {
	return array();
}

/**
 * Run the waiting actions of a hook, earliest first, as Action Scheduler's
 * runner would: due ones only, unless $all; at most $max.
 */
function as_run( $hook, $all = false, $max = 1000 ) {
	$ran = 0;
	while ( $ran < $max ) {
		$pick = null;
		foreach ( $GLOBALS['AS']['actions'] as $id => $a ) {
			if ( $a['hook'] === $hook && ( $all || $a['time'] <= time() ) && ( null === $pick || $a['time'] < $GLOBALS['AS']['actions'][ $pick ]['time'] ) ) {
				$pick = $id;
			}
		}
		if ( null === $pick ) {
			break;
		}
		$a = $GLOBALS['AS']['actions'][ $pick ];
		unset( $GLOBALS['AS']['actions'][ $pick ] );
		$GLOBALS['AS']['ran'][] = $hook;
		do_action_ref_array( $hook, $a['args'] );
		$ran++;
	}
	return $ran;
}

/** The actions of a hook waiting now. */
function as_waiting( $hook ) {
	$out = array();
	foreach ( $GLOBALS['AS']['actions'] as $a ) {
		if ( $a['hook'] === $hook ) {
			$out[] = $a;
		}
	}
	return $out;
}

// --- the database's own rows ----------------------------------------------------

/**
 * The template world's database, with the rows the re-send reads directly:
 * the options table's rows of its holds (inserted, read earliest first,
 * deleted by value), the call counter, the products in ID order, and the
 * products holding an eBay item number.
 */
class Resend_World_DB extends Template_World_DB {
	public $rows       = array();
	public $next_row   = 1;
	public $last_error = '';
	public $unique     = true;
	public $selects    = array();
	public function suppress_errors( $quiet = true ) { return false; }
	public function get_col( $query ) {
		list( $sql, $args ) = is_array( $query ) ? $query : array( (string) $query, array() );
		$this->selects[] = $sql;
		if ( false !== strpos( $sql, "post_type = 'product' AND post_status IN" ) ) {
			$ids = array();
			foreach ( $GLOBALS['W']['products'] as $id => $product ) {
				if ( $id > (int) $args[0] && ! $product instanceof WC_Product_Variation && in_array( $product->get_status(), array( 'publish', 'private', 'draft', 'pending', 'future' ), true ) ) {
					$ids[] = (int) $id;
				}
			}
			sort( $ids );
			return array_map( 'strval', array_slice( $ids, 0, (int) $args[1] ) );
		}
		if ( false !== strpos( $sql, "pm.meta_key = '_ebay_item_id'" ) ) {
			// A harness with thousands of listings gives the lookup an index.
			if ( isset( $GLOBALS['W']['item_index'] ) ) {
				return array_values( array_diff( $GLOBALS['W']['item_index'][ (string) $args[0] ] ?? array(), array( (string) $args[1] ) ) );
			}
			$out = array();
			foreach ( $GLOBALS['W']['meta'] as $id => $meta ) {
				if ( (string) ( $meta['_ebay_item_id'] ?? '' ) === (string) $args[0] && (int) $id !== (int) $args[1] && isset( $GLOBALS['W']['products'][ $id ] ) ) {
					$out[] = (string) $id;
				}
			}
			return $out;
		}
		return array();
	}
	public function query( $query ) {
		list( $sql, $args ) = is_array( $query ) ? $query : array( (string) $query, array() );
		if ( 0 === strpos( $sql, 'INSERT INTO wp_options' ) ) {
			if ( $this->unique && ! empty( $this->rows[ $args[0] ] ) ) {
				$this->last_error = 'Duplicate entry';
				return false;
			}
			$this->rows[ $args[0] ][] = array( $this->next_row++, (string) $args[1] );
			return 1;
		}
		if ( 0 === strpos( $sql, 'DELETE FROM wp_options WHERE option_name = %s AND option_value = %s' ) ) {
			$n = 0;
			foreach ( $this->rows[ $args[0] ] ?? array() as $i => $row ) {
				if ( $row[1] === (string) $args[1] ) {
					unset( $this->rows[ $args[0] ][ $i ] );
					$n++;
				}
			}
			if ( empty( $this->rows[ $args[0] ] ) ) {
				unset( $this->rows[ $args[0] ] );
			}
			return $n;
		}
		return 0;
	}
	public function get_var( $query ) {
		list( $sql, $args ) = is_array( $query ) ? $query : array( (string) $query, array() );
		if ( false !== strpos( $sql, 'SELECT option_value FROM wp_options WHERE option_name = %s ORDER BY option_id' ) ) {
			$rows = array_values( $this->rows[ $args[0] ] ?? array() );
			return $rows ? $rows[0][1] : null;
		}
		if ( false !== strpos( $sql, 'SELECT option_value FROM wp_options WHERE option_name = %s LIMIT 1' ) ) {
			return isset( $GLOBALS['W']['options'][ $args[0] ] ) ? (string) $GLOBALS['W']['options'][ $args[0] ] : null;
		}
		// Whether any product was sent a template (3.34.0's count, for a shop with none).
		if ( false !== strpos( $sql, "meta_key = '_tcgiant_tpl_id' AND meta_value NOT IN ( '', '0' )" ) ) {
			foreach ( $GLOBALS['W']['meta'] as $id => $meta ) {
				if ( ! in_array( (string) ( $meta['_tcgiant_tpl_id'] ?? '' ), array( '', '0' ), true ) ) {
					return (string) $id;
				}
			}
			return null;
		}
		return parent::get_var( $query );
	}
}
$GLOBALS['wpdb'] = new Resend_World_DB();

// --- the rest of the plugin the re-send reaches ---------------------------------

/**
 * The host's time for a request, played: $late, or what it returns when it
 * is a callable, says why it is used up ('' while there is time).
 */
class TCGiant_Sync_Runtime {
	public static $late  = '';
	public static $asked = 0;
	public static function over_time_budget() {
		self::$asked++;
		return is_callable( self::$late ) ? call_user_func( self::$late ) : self::$late;
	}
	public static function __callStatic( $name, $args ) { return null; }
}

/** The Listings table, as far as last_synced and ebay_title go. */
class TCGiant_Sync_DB {
	public static $rows = array();
	public static function get_by_product( $product_id ) { return self::$rows[ (int) $product_id ] ?? null; }
	public static function upsert( $data ) { self::$rows[ (int) $data['product_id'] ] = array_merge( self::$rows[ (int) $data['product_id'] ] ?? array(), $data ); return true; }
}

require_once $RS_ROOT . '/includes/class-tcgiant-sync-listing-link.php';
require_once $RS_ROOT . '/includes/class-tcgiant-sync-template-resend.php';
TCGiant_Sync_Template_Resend::register();

// The exporter the re-send asks is the world's.
$rs_slot = new ReflectionProperty( 'TCGiant_Sync_Exporter', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$rs_slot->setAccessible( true );
}
$rs_slot->setValue( null, exporter() );

function wp_date( $format, $time = null ) { return gmdate( $format, null === $time ? time() : (int) $time ); }
if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field ) { return array_map( function ( $row ) use ( $field ) { return is_array( $row ) ? ( $row[ $field ] ?? null ) : null; }, (array) $list ); }
}

// A shop whose default category takes no card or coin condition descriptors,
// so a listing it pushed is ready to push again as it stands.
TCGiant_Sync_OAuth::$settings['export_category_id'] = '29223';

// --- eBay's budget, as the relay gave it -----------------------------------------

/**
 * The relay's figures at a token refresh: every shop's calls, the ceiling,
 * this site's count then, and its own allowance; $age seconds ago.
 */
function relay_said( $global_calls, $site_cap = 1000, $age = 60, $local = 0, $limit = 50000 ) {
	set_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ), array(
		'global_calls' => (int) $global_calls,
		'daily_limit'  => (int) $limit,
		'remaining'    => (int) $limit - (int) $global_calls,
		'local_calls'  => (int) $local,
		'site_cap'     => (int) $site_cap,
		'updated_at'   => time() - (int) $age,
	) );
}
/** This site's own Trading calls today, as the counter's row holds them. */
function own_calls( $n ) {
	$GLOBALS['W']['options'][ TCGiant_Sync_API::CALL_COUNT_PREFIX . gmdate( 'Y-m-d' ) ] = (int) $n;
}

// --- listings ----------------------------------------------------------------------

/**
 * A product linked to a live fixed-price listing on the shop's own site,
 * pushed an hour ago and not saved since, with its row on the Listings
 * table: what most of the harness starts from. $meta is laid over it.
 */
function listing( $id, array $data = array(), array $meta = array() ) {
	$product = new WC_Product( $id, array_merge( array(
		'name'        => 'Card ' . $id,
		'description' => 'Near mint card number ' . $id . '.',
		'stock'       => 3,
		'modified'    => time() - 3700,
	), $data ) );
	$GLOBALS['W']['posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_type' => 'product', 'post_status' => 'publish' ) );
	foreach ( array_merge( array(
		'_ebay_item_id'            => (string) ( 110000000000 + $id ),
		'_ebay_listing_status'     => 'Active',
		'_ebay_listing_type'       => 'FixedPriceItem',
		'_ebay_site'               => 'US',
		'_ebay_export_last_pushed' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
		'_ebay_origin'             => 'push',
	), $meta ) as $key => $value ) {
		if ( null === $value ) {
			unset( $GLOBALS['W']['meta'][ $id ][ $key ] );
		} else {
			$GLOBALS['W']['meta'][ $id ][ $key ] = $value;
		}
	}
	// Its row on the Listings table: eBay's title, as the push wrote it.
	TCGiant_Sync_DB::$rows[ $id ] = array( 'product_id' => $id, 'ebay_title' => $product->get_name(), 'last_synced' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
	return $product;
}

/**
 * Record on a product what a push of it would have sent, as the push records
 * it, $ago seconds back: the render it would make now.
 */
function sent_now( WC_Product $product, $ago = 172800 ) {
	$render = exporter()->resend_description( $product, exporter()->resend_settings( $product ) );
	TCGiant_Sync_Exporter::record_resend( $product->get_id(), $render, (string) $product->get_meta( '_ebay_item_id' ) );
	$GLOBALS['W']['meta'][ $product->get_id() ]['_tcgiant_desc_sent_at'] = (string) ( time() - $ago );
	return $render;
}

/** Re-send's options, read back. */
function rs_option( $name ) {
	return get_option( $name, array() );
}

/** Trading calls made since the last asked, cleared. */
function rs_calls() {
	$calls                 = $GLOBALS['API']->calls;
	$GLOBALS['API']->calls = array();
	return $calls;
}

/** Count now: run the count's batches until it is done. */
function count_now() {
	TCGiant_Sync_Template_Resend::queue_scan( 'harness', true );
	as_run( TCGiant_Sync_Template_Resend::SCAN_HOOK, true );
	return rs_option( TCGiant_Sync_Template_Resend::COUNT_OPTION )['done'] ?? null;
}

/** The shop on a fresh, roomy budget: every shop at 1,000 of 50,000, this site at 0 of 1,000. */
function roomy() {
	relay_said( 1000, 1000 );
	own_calls( 0 );
}
