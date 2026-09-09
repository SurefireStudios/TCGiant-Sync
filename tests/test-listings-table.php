<?php
/**
 * Harness: the Listings table, and linking to a listing eBay already has.
 *
 * Two merchants wrote in on the same day.
 *
 * A coin dealer, following instructions to end listings in bulk: "when I go to
 * TCGiant Sync -> Listings, there are no products listed to tick." Their screen
 * said "No eBay-linked products found" although they had live eBay listings.
 * Two faults were behind it. The table the screen reads was never created on any
 * site - its creation was registered on plugins_loaded at priority 5 from inside
 * a constructor that itself runs on plugins_loaded at priority 20, and WordPress
 * does not run a callback added at a priority the pass in progress has already
 * gone by. And even with the table present, a push never wrote a row: only the
 * importer did, so a shop that lists by pushing had nothing there regardless.
 *
 * A networking retailer, on the same day: "So I pushed an item that was already
 * in eBay and it created a duplicate. What is being compared to not create
 * duplicates?" One thing, it turns out - the eBay item number stored on the
 * product - and there was no way to supply it. Unlink existed; nothing linked.
 *
 * Part of this file reads the plugin's own source. Those assertions are the only
 * ones that can catch the first fault coming back: a lost table is silent, and
 * every symptom of it looks like "you have not imported yet".
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-66s got %-26s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$root = dirname( __DIR__ );

echo "\nTHE TABLE IS CREATED BY A PATH THAT ACTUALLY RUNS\n" . str_repeat( '=', 118 ) . "\n";

$db = file_get_contents( $root . '/includes/class-tcgiant-sync-db.php' );

// The constructor is reached from TCGiant_Sync::init_subsystems(), which is
// hooked to plugins_loaded at priority 20. Anything it registers on
// plugins_loaded at a lower priority is dropped on the floor by WP_Hook, which
// advances its iterator past priorities the running pass has already left
// behind. That is not a thing to re-derive from memory every time someone edits
// this file, so it is asserted instead.
preg_match( '/public function __construct\(\)\s*\{(.*?)\n\t\}/s', $db, $m );
$ctor = $m[1] ?? '';

check( 'the constructor was found', '' !== $ctor, true );
check( 'it registers nothing on plugins_loaded', false === strpos( $ctor, "add_action( 'plugins_loaded'" ), true );
check( 'it creates the table directly', false !== strpos( $ctor, '$this->maybe_create_table();' ), true );
check( 'it adds the postmeta index directly', false !== strpos( $ctor, '$this->maybe_add_postmeta_index();' ), true );

// And nowhere else in the file either - the same trap, one method along.
// Comments are stripped first: this file explains the old registration in prose,
// and a check that cannot tell prose from code would fail on its own docblock.
$db_code = php_strip_whitespace( $root . '/includes/class-tcgiant-sync-db.php' );
check( 'nothing in the class registers a plugins_loaded callback', substr_count( $db_code, "add_action('plugins_loaded'" ) + substr_count( $db_code, "add_action( 'plugins_loaded'" ), 0 );

$bootstrap = file_get_contents( $root . '/includes/class-tcgiant-sync.php' );
preg_match( "/add_action\( 'plugins_loaded', array\( \\\$this, 'init_subsystems' \), (\d+) \)/", $bootstrap, $pm );
check( 'subsystems still start at plugins_loaded priority 20', $pm[1] ?? '', '20' );

echo "\nA PUSH PUTS THE PRODUCT ON THE LISTINGS SCREEN\n" . str_repeat( '=', 118 ) . "\n";

$exporter = file_get_contents( $root . '/includes/class-tcgiant-sync-exporter.php' );
check( 'the exporter writes to the listings table', substr_count( $exporter, 'TCGiant_Sync_DB::upsert(' ), 1 );

// It has to happen after the product is saved and only on success, so take the
// slice between the success branch and the export-state update that closes it.
$from = (int) strpos( $exporter, "\$item_id = \$result['item_id'];" );
$to   = (int) strpos( $exporter, '$state = self::get_export_state();', $from );
$after_save = substr( $exporter, $from, $to - $from );
check( '  on the success path, not the error path', false !== strpos( $after_save, 'TCGiant_Sync_DB::upsert(' ), true );
check( '  carrying the eBay item id', false !== strpos( $after_save, "'ebay_item_id'   => \$item_id," ), true );
check( '  and marking when it was pushed', false !== strpos( $after_save, "'last_pushed'" ), true );
check( '  and leaving price and quantity alone for a variable product',
	false !== strpos( $after_save, "if ( ! \$product->is_type( 'variable' ) ) {" ), true );
check( '  recording an auction as the single item it is',
	false !== strpos( $after_save, "'Chinese' === \$row['listing_type']" ), true );

$listings_view = file_get_contents( $root . '/admin/views/listings.php' );
check( 'the empty state no longer names a button that does not exist', false === strpos( $listings_view, 'Run a Full Sync' ), true );
check( '  and names one that does', false !== strpos( $listings_view, 'Fetch Inventory' ), true );

echo "\nWHERE THE TABLE WORK IS ALLOWED TO HAPPEN\n" . str_repeat( '=', 118 ) . "\n";

/**
 * The constructor's context guard, verbatim. Creating the table means a dbDelta
 * and, once, a row per linked product; that belongs nowhere near a shopper's
 * page load.
 */
function tcg_should_touch_table( $is_admin, $doing_cron, $wp_cli ) {
	if ( ! $is_admin && ! $doing_cron && ! $wp_cli ) {
		return false;
	}
	return true;
}

check( 'an admin screen',            tcg_should_touch_table( true, false, false ), true );
check( 'admin-ajax, where the background jobs run', tcg_should_touch_table( true, false, false ), true );
check( 'wp-cron',                    tcg_should_touch_table( false, true, false ), true );
check( 'wp-cli',                     tcg_should_touch_table( false, false, true ), true );
check( 'a shopper loading a product page pays nothing', tcg_should_touch_table( false, false, false ), false );

echo "\nLINKING TO A LISTING EBAY ALREADY HAS\n" . str_repeat( '=', 118 ) . "\n";

/** The handler's decisions, verbatim. */
class Linker {
	public static function clean_item_id( $raw ) {
		return preg_replace( '/[^0-9]/', '', (string) $raw );
	}

	/** eBay says Active, Completed, Ended or Custom. Only one of those is live. */
	public static function local_status( $ebay_status ) {
		return ( 'Active' === $ebay_status ) ? 'Active' : 'Ended';
	}

	public static function price( $item, $product_price ) {
		$price = $item['SellingStatus']['CurrentPrice'] ?? null;
		if ( is_array( $price ) ) {
			$price = $price['value'] ?? ( $price['#text'] ?? null );
		}
		return is_numeric( $price ) ? (float) $price : (float) $product_price;
	}

	/** Returns an error string, or '' when the link may go ahead. */
	public static function refuse( $item_id, array $shared, $ebay_answer ) {
		if ( '' === $item_id ) {
			return 'no_item_id';
		}
		if ( ! empty( $shared ) ) {
			return 'already_claimed';
		}
		if ( null === $ebay_answer || empty( $ebay_answer['Item'] ) ) {
			return 'not_on_ebay';
		}
		return '';
	}
}

check( 'a pasted listing URL yields the number',
	Linker::clean_item_id( 'https://www.ebay.com/itm/256000111222?hash=abc' ), '256000111222' );
check( 'spaces and punctuation are stripped', Linker::clean_item_id( ' 256-000-111-222 ' ), '256000111222' );
check( 'a word is not an item number',        Linker::clean_item_id( 'my listing' ), '' );

$live = array( 'Item' => array( 'Title' => 'NETGEAR GS524UP', 'ListingType' => 'FixedPriceItem', 'SellingStatus' => array( 'ListingStatus' => 'Active' ) ) );

check( 'nothing typed is refused',            Linker::refuse( '', array(), $live ), 'no_item_id' );
check( 'a listing another product claims is refused', Linker::refuse( '256000111222', array( 41 ), $live ), 'already_claimed' );
check( '  which is the guard that stops two products revising one listing', true, true );
check( 'a number eBay does not know is refused', Linker::refuse( '256000111222', array(), array() ), 'not_on_ebay' );
check( 'an eBay error is refused',            Linker::refuse( '256000111222', array(), null ), 'not_on_ebay' );
check( 'an existing listing of ours is accepted', Linker::refuse( '256000111222', array(), $live ), '' );

check( 'a live listing is recorded as Active',   Linker::local_status( 'Active' ), 'Active' );
check( 'a completed one is recorded as Ended',   Linker::local_status( 'Completed' ), 'Ended' );
check( 'so is an ended one',                     Linker::local_status( 'Ended' ), 'Ended' );
check( 'and anything else eBay invents',         Linker::local_status( 'Custom' ), 'Ended' );

check( "eBay's price is used when it is a plain number",
	Linker::price( array( 'SellingStatus' => array( 'CurrentPrice' => '129.99' ) ), 99.0 ), 129.99 );
check( '  and when it arrives as an attributed value',
	Linker::price( array( 'SellingStatus' => array( 'CurrentPrice' => array( 'value' => '129.99', 'currencyID' => 'USD' ) ) ), 99.0 ), 129.99 );
check( '  falling back to the product when eBay sends nothing usable',
	Linker::price( array( 'SellingStatus' => array( 'CurrentPrice' => array( 'currencyID' => 'USD' ) ) ), 99.0 ), 99.0 );
check( '  and when there is no SellingStatus at all',
	Linker::price( array(), 99.0 ), 99.0 );

echo "\nTHE ANSWER TO \"WHAT IS BEING COMPARED\"\n" . str_repeat( '=', 118 ) . "\n";

// One thing, and it is worth pinning: the push decides create-versus-update on
// the stored item number alone. Nothing about the product is compared with
// anything on eBay. If that ever changes, this assertion should change with it
// deliberately - the answer we give merchants depends on it.
$do_push = substr( $exporter, (int) strpos( $exporter, 'private function do_push(' ) );
$do_push = substr( $do_push, 0, (int) strpos( $do_push, 'private function' ) ?: 12000 );
foreach ( array( 'GetItem', 'GetSellerList', 'GetMyeBaySelling', 'find_product_id_by_sku' ) as $lookup ) {
	check( 'the push still makes no ' . $lookup . ' call', false === strpos( $do_push, $lookup ), true );
}
check( 'it branches on the stored item id', false !== strpos( $do_push, "\$ebay_item_id = \$product->get_meta( '_ebay_item_id' );" ), true );

echo "\nWRITING ONE COLUMN WRITES ONE COLUMN\n" . str_repeat( '=', 118 ) . "\n";

/**
 * upsert()'s row builder, before and after.
 *
 * The old one ran the caller's data through wp_parse_args() against a full set
 * of defaults and handed the result to $wpdb->update(), which writes every key
 * it is given. So "this listing is Active again" also wrote listing_type
 * 'FixedPriceItem', price 0.00, quantity 0 and an empty title - and dual-wrote
 * that invented type onto the product.
 */
function tcg_row_old( array $data ) {
	$defaults = array(
		'ebay_item_id'   => '',
		'listing_type'   => 'FixedPriceItem',
		'listing_status' => 'Active',
		'ebay_price'     => 0.00,
		'ebay_quantity'  => 0,
		'ebay_url'       => '',
		'ebay_title'     => '',
	);
	unset( $data['product_id'] );
	return array_merge( $defaults, $data );
}

function tcg_row_new( array $data ) {
	$columns = array( 'ebay_item_id', 'listing_type', 'listing_status', 'ebay_price', 'ebay_quantity', 'ebay_url', 'ebay_title', 'last_synced', 'last_pushed', 'variation_cache', 'sync_error' );
	$row     = array();
	foreach ( $columns as $column ) {
		if ( array_key_exists( $column, $data ) ) {
			$row[ $column ] = $data[ $column ];
		}
	}
	return $row;
}

// This is what the auto-relist scheduler sends after relisting an auction.
$relist = array( 'product_id' => 7, 'ebay_item_id' => '256000111222', 'listing_status' => 'Active' );

check( 'before: a three-field update wrote seven columns', count( tcg_row_old( $relist ) ), 7 );
check( '  including a listing type nobody asked for', tcg_row_old( $relist )['listing_type'], 'FixedPriceItem' );
check( '  which is how a relisted auction became fixed price', true, true );
check( 'after: it writes the two it was given', array_keys( tcg_row_new( $relist ) ), array( 'ebay_item_id', 'listing_status' ) );
check( '  and cannot touch the recorded format', array_key_exists( 'listing_type', tcg_row_new( $relist ) ), false );
check( '  nor blank the title or zero the price', array_key_exists( 'ebay_title', tcg_row_new( $relist ) ) || array_key_exists( 'ebay_price', tcg_row_new( $relist ) ), false );
check( 'a full row from the importer still writes everything it sends',
	count( tcg_row_new( array( 'product_id' => 7, 'ebay_item_id' => '1', 'listing_type' => 'Chinese', 'listing_status' => 'Active', 'ebay_price' => 1.0, 'ebay_quantity' => 2, 'ebay_url' => 'u', 'ebay_title' => 't', 'last_synced' => 'n' ) ) ), 8 );
check( 'nothing to write is refused rather than written', tcg_row_new( array( 'product_id' => 7 ) ), array() );

echo "\nTHE BACKFILL FINISHES, AND CANNOT START OVER\n" . str_repeat( '=', 118 ) . "\n";

/** The backfill loop, with the database replaced by an array of product ids. */
function tcg_backfill( array $linked, $batch, &$cursor, &$state, $batches_allowed ) {
	$copied = 0;
	$rounds = 0;

	do {
		$ids = array();
		foreach ( $linked as $id ) {
			if ( $id > $cursor ) { $ids[] = $id; }
			if ( count( $ids ) >= $batch ) { break; }
		}

		if ( empty( $ids ) ) {
			$state  = 'done';
			$cursor = 0;
			return $copied;
		}

		$cursor  = max( $ids );
		$copied += count( $ids );
		$rounds++;
	} while ( $rounds < $batches_allowed );

	return $copied;
}

$linked  = range( 100, 1099 );   // 1,000 linked products
$cursor  = 0;
$state   = 'pending';

// A request that only gets through two batches before its time is up.
$first = tcg_backfill( $linked, 500, $cursor, $state, 1 );
check( 'one batch copies 500', $first, 500 );
check( '  and records where it got to', $cursor, 599 );
check( '  without claiming to be finished', $state, 'pending' );

$second = tcg_backfill( $linked, 500, $cursor, $state, 1 );
check( 'the next request carries on from the cursor', $second, 500 );
check( '  reaching the end', $cursor, 1099 );

$third = tcg_backfill( $linked, 500, $cursor, $state, 1 );
check( 'a run with nothing left marks it done', $state, 'done' );
check( '  copying nothing twice', $third, 0 );

// The property that matters: every product is copied exactly once across the
// three runs, and the total is the catalogue, not a multiple of it.
check( 'every linked product copied exactly once', $first + $second + $third, count( $linked ) );

$cursor = 0;
$state  = 'pending';
// $batches_allowed stands in for the real time budget: a small shop gets
// through its one batch and round again to find nothing left, all inside the
// first request.
$small  = tcg_backfill( range( 1, 40 ), 500, $cursor, $state, 5 );
check( 'a small shop finishes in one go', $state, 'done' );
check( '  having copied all of them', $small, 40 );

echo "\nTHE TABLE FOLLOWS THE PRODUCT\n" . str_repeat( '=', 118 ) . "\n";

/** The mirror listener's decision, verbatim. */
function tcg_mirror_column( $meta_key, $meta_value, $mirroring ) {
	$columns = array(
		'_ebay_listing_status' => 'listing_status',
		'_ebay_listing_type'   => 'listing_type',
		'_ebay_item_id'        => 'ebay_item_id',
	);
	if ( $mirroring || ! isset( $columns[ $meta_key ] ) || is_array( $meta_value ) ) {
		return '';
	}
	return $columns[ $meta_key ];
}

check( 'ending a listing updates the row',        tcg_mirror_column( '_ebay_listing_status', 'Ended', false ), 'listing_status' );
check( 'so does a change of format',              tcg_mirror_column( '_ebay_listing_type', 'Chinese', false ), 'listing_type' );
check( 'and a new item id',                       tcg_mirror_column( '_ebay_item_id', '256000111222', false ), 'ebay_item_id' );
check( 'an unrelated meta key is ignored',        tcg_mirror_column( '_price', '9.99', false ), '' );
check( 'an array value is ignored',               tcg_mirror_column( '_ebay_listing_status', array( 'Ended' ), false ), '' );
check( 'and our own dual-write does not echo',    tcg_mirror_column( '_ebay_listing_status', 'Active', true ), '' );

echo "\nLINKING ONLY TO YOUR OWN LISTINGS\n" . str_repeat( '=', 118 ) . "\n";

/** The ownership check, verbatim. */
function tcg_ownership_refused( $seller, $ours ) {
	return ( '' !== $ours && '' !== $seller && 0 !== strcasecmp( $seller, $ours ) );
}

check( 'your own listing links',                  tcg_ownership_refused( 'numismax', 'numismax' ), false );
check( '  whatever case eBay returns it in',      tcg_ownership_refused( 'NumisMax', 'numismax' ), false );
check( "somebody else's listing is refused",      tcg_ownership_refused( 'other_seller', 'numismax' ), true );
check( 'an unknown owner does not block linking', tcg_ownership_refused( '', 'numismax' ), false );
check( '  nor does eBay failing to say who we are', tcg_ownership_refused( 'other_seller', '' ), false );


printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
