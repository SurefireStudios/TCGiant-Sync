<?php
/**
 * Exporter Logic
 *
 * Manages pushing WooCommerce products to eBay as new or updated listings.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Exporter class
 */
class TCGiant_Sync_Exporter {

	/**
	 * Instance.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * Export state option key.
	 */
	const EXPORT_STATE_OPTION = 'tcgiant_export_state';

	/**
	 * Action Scheduler hook for processing a single export job.
	 */
	const PUSH_ACTION = 'tcgiant_export_push_product';

	/**
	 * eBay condition IDs relevant to TCG / collectibles (legacy fallback).
	 */
	const CONDITIONS = array(
		'1000' => 'New / Sealed',
		'2750' => 'Like New',
		'3000' => 'Very Good',
		'4000' => 'Good',
		'5000' => 'Acceptable',
	);

	/**
	 * eBay categories that require ConditionDescriptors — Trading Cards.
	 */
	const DESCRIPTOR_CATEGORIES_TCG = array(
		'183050', // Trading Card Games
		'183454', // CCG Individual Cards (Dragon Ball Super, etc.)
		'261328', // Sports Trading Card Singles
		'2536',   // Trading Cards
		'261068', // Non-Sport Trading Card Games
		'180006', // Pokémon Individual Cards
		'176055', // Magic: The Gathering Cards
		'69243',  // Yu-Gi-Oh! Individual Cards
		'183446', // Disney Lorcana
		'185089', // One Piece Card Game
	);

	/**
	 * eBay categories that require ConditionDescriptors — Coins.
	 */
	const DESCRIPTOR_CATEGORIES_COINS = array(
		'253',   // Coins: US
		'256',   // Coins: World
		'3377',  // Coins: Canada
		'4733',  // Coins: Ancient
		'18466', // Coins: Medieval
	);

	/**
	 * Professional Grader name => eBay conditionDescriptorValueId.
	 * Trading Cards — Descriptor Name 27501.
	 * IDs verified against eBay Metadata API for category 183050.
	 */
	const GRADERS_TCG = array(
		'Professional Sports Authenticator (PSA)'   => '275010',
		'Beckett Collectors Club Grading (BCCG)'    => '275011',
		'Beckett Vintage Grading (BVG)'             => '275012',
		'Beckett Grading Services (BGS)'            => '275013',
		'Certified Guaranty Company (CGC)'          => '275015',
		'Sportscard Guaranty Corporation (SGC)'     => '275016',
		'K Sportscard Authentication (KSA)'         => '275017',
		'Gem Mint Authentication (GMA)'             => '275018',
		'Hybrid Grading Approach (HGA)'             => '275019',
		'International Sports Authentication (ISA)' => '2750110',
		'Gold Standard Grading (GSG)'               => '2750112',
		'Platin Grading Service (PGS)'              => '2750113',
		'MNT Grading (MNT)'                         => '2750114',
		'Technical Authentication & Grading (TAG)'  => '2750115',
		'Rare Edition (Rare)'                       => '2750116',
		'Revolution Card Grading (RCG)'             => '2750117',
		'Ace Grading (Ace)'                         => '2750119',
		'Card Grading Australia (CGA)'              => '2750120',
		'Other'                                     => '2750123',
		'Automated Grading Systems (AGS)'           => '2750124',
		'Diamond Service Grading (DSG)'             => '2750125',
		'Majesty Grading Company'                   => '2750126',
		'GRAAD'                                     => '2750127',
		'Arena Club'                                => '2750128',
		'AiGrading'                                 => '2750129',
	);

	/**
	 * Professional Grader name => eBay conditionDescriptorValueId.
	 * Coins — Descriptor Name 1.
	 */
	const GRADERS_COINS = array(
		'PCGS'    => '14',
		'NGC'     => '15',
		'CAC'     => '16',
		'ANACS'   => '34',
		'ICG'     => '35',
		'ICCS'    => '36',
		'CCCS'    => '37',
		'LCGS'    => '38',
		'CGS-UK'  => '39',
		'SEGS'    => '40',
		'NNC'     => '41',
		'NTC'     => '42',
		'PCI'     => '43',
		'ACG'     => '44',
		'ASA'     => '45',
		'Other'   => '76',
	);

	/**
	 * Numeric grade values available for graded TCG items.
	 */
	const GRADES_TCG = array(
		'10', '9.5', '9', '8.5', '8', '7.5', '7', '6.5', '6',
		'5.5', '5', '4.5', '4', '3.5', '3', '2.5', '2', '1.5', '1',
	);

	/**
	 * Grade values available for graded Coins.
	 * Includes standard Sheldon-scale grades plus text-based aliases
	 * (Gem BU, Choice BU, etc.) that some grading holders use.
	 */
	const GRADES_COINS = array(
		// --- Standard Sheldon Scale ---
		'MS/PR 70', 'MS/PR 69', 'MS/PR 68', 'MS/PR 67', 'MS/PR 66', 'MS/PR 65',
		'MS/PR 64', 'MS/PR 63', 'MS/PR 62', 'MS/PR 61', 'MS/PR 60',
		'AU 58', 'AU 55', 'AU 53', 'AU 50',
		'XF 45', 'XF 40',
		'VF 35', 'VF 30', 'VF 25', 'VF 20',
		'F 15', 'F 12',
		'VG 10', 'VG 8',
		'G 6', 'G 4',
		'AG 3',
		'FR 2',
		'P 1',
		// --- Text-Based Grade Aliases ---
		'Superb Gem BU (≈MS 67)',
		'Gem BU (≈MS 65)',
		'Choice BU (≈MS 63)',
		'Superb Gem Proof (≈PR 67)',
		'Gem Proof (≈PR 65)',
		'Choice Proof (≈PR 63)',
		'Choice AU (≈AU 55)',
		'Choice XF (≈XF 45)',
		'Choice VF (≈VF 35)',
	);

	/**
	 * Maps text-based grade aliases to their canonical 'LETTER NUM' Sheldon values.
	 * Used during XML generation to resolve human-readable labels to eBay descriptor IDs.
	 */
	const TEXT_GRADE_ALIASES = array(
		'Superb Gem BU (≈MS 67)'     => 'MS/PR 67',
		'Gem BU (≈MS 65)'            => 'MS/PR 65',
		'Choice BU (≈MS 63)'         => 'MS/PR 63',
		'Superb Gem Proof (≈PR 67)'  => 'MS/PR 67',
		'Gem Proof (≈PR 65)'         => 'MS/PR 65',
		'Choice Proof (≈PR 63)'      => 'MS/PR 63',
		'Choice AU (≈AU 55)'         => 'AU 55',
		'Choice XF (≈XF 45)'         => 'XF 45',
		'Choice VF (≈VF 35)'         => 'VF 35',
	);

	/**
	 * Letter grade label => eBay conditionDescriptorValueId.
	 * Coins — Descriptor Name 3.
	 */
	const LETTER_GRADES_COINS = array(
		'MS/PR' => '18',
		'AU'    => '19',
		'XF'    => '20',
		'VF'    => '46',
		'F'     => '47',
		'VG'    => '48',
		'G'     => '49',
		'AG'    => '50',
		'FR'    => '51',
		'P'     => '52',
	);

	/**
	 * Numeric grade label => eBay conditionDescriptorValueId.
	 * Coins — Descriptor Name 4.
	 */
	const NUMERIC_GRADES_COINS = array(
		'70' => '32',
		'69' => '25',
		'68' => '26',
		'67' => '53',
		'66' => '54',
		'65' => '55',
		'64' => '56',
		'63' => '57',
		'62' => '58',
		'61' => '59',
		'60' => '60',
		'58' => '27',
		'55' => '28',
		'53' => '29',
		'50' => '61',
		'45' => '30',
		'40' => '31',
		'35' => '62',
		'30' => '63',
		'25' => '64',
		'20' => '65',
		'15' => '66',
		'12' => '67',
		'10' => '68',
		'8'  => '69',
		'6'  => '70',
		'4'  => '71',
		'3'  => '72',
		'2'  => '73',
		'1'  => '74',
	);

	/**
	 * Ungraded Trading Card conditions — value ID => label.
	 */
	const UNGRADED_TCG = array(
		'400010' => 'Near Mint or Better',
		'400011' => 'Excellent',
		'400012' => 'Very Good',
		'400013' => 'Poor',
		'400015' => 'Lightly Played (Excellent)',
		'400016' => 'Moderately Played (Very Good)',
		'400017' => 'Heavily Played (Poor)',
	);

	/**
	 * Ungraded Coin conditions — conditionDescriptorValueId => label.
	 * Coins — Descriptor Name 2.
	 */
	const UNGRADED_COINS = array(
		'7'  => 'Uncirculated',
		'8'  => 'Extremely Fine to About Uncirculated',
		'9'  => 'Fine to Very Fine',
		'10' => 'Below Fine',
	);

	/**
	 * TCG-relevant eBay category map (ID => label).
	 */
	const CATEGORIES = array(
		''       => '— Select a default category —',
		'183050' => 'Trading Card Games',
		'2536'   => 'Trading Cards',
		'261068' => 'Non-Sport Trading Card Games',
		'180006' => 'Pokémon Individual Cards',
		'176055' => 'Magic: The Gathering Cards',
		'69243'  => 'Yu-Gi-Oh! Individual Cards',
		'183454' => 'Dragon Ball Super CCG',
		'183446' => 'Disney Lorcana',
		'185089' => 'One Piece Card Game',
		'253'    => 'Coins: US',
		'256'    => 'Coins: World',
		'3377'   => 'Coins: Canada',
		'4733'   => 'Coins: Ancient',
		'18466'  => 'Coins: Medieval',
		'custom' => 'Custom Category ID...',
	);

	/**
	 * Maximum eBay title length (hard limit).
	 */
	const MAX_TITLE_LENGTH = 80;

	/**
	 * Maximum images per eBay listing.
	 */
	const MAX_IMAGES = 12;

	/**
	 * Listing types supported by the Trading API.
	 */
	const LISTING_TYPES = array(
		'FixedPriceItem' => 'Fixed Price',
		'Chinese'        => 'Auction',
	);

	/**
	 * Valid listing durations.
	 * Keys are the eBay API values; values are human labels.
	 */
	const LISTING_DURATIONS = array(
		'GTC'     => 'Good Til Cancelled',
		'Days_1'  => '1 Day',
		'Days_3'  => '3 Days',
		'Days_5'  => '5 Days',
		'Days_7'  => '7 Days',
		'Days_10' => '10 Days',
		'Days_30' => '30 Days',
		'Days_60' => '60 Days',
		'Days_90' => '90 Days',
	);

	/**
	 * Valid durations per listing type (eBay rules).
	 */
	const DURATIONS_BY_TYPE = array(
		'FixedPriceItem' => array( 'GTC', 'Days_30' ),
		'Chinese'        => array( 'Days_1', 'Days_3', 'Days_5', 'Days_7', 'Days_10' ),
	);

	/**
	 * Get categories, including user-defined custom saved categories.
	 *
	 * @return array Map of category ID => label.
	 */
	public static function get_categories() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$categories = self::CATEGORIES;
		
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		if ( ! empty( $settings['custom_saved_categories'] ) ) {
			$lines = explode( "\n", $settings['custom_saved_categories'] );
			$custom_cats = array();
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( empty( $line ) ) {
					continue;
				}
				if ( strpos( $line, ':' ) !== false ) {
					list( $id, $label ) = explode( ':', $line, 2 );
					$id    = trim( $id );
					$label = trim( $label );
					if ( '' !== $id && ctype_digit( $id ) ) {
						$custom_cats[ $id ] = '' !== $label ? $label : 'Custom ' . $id;
					}
				} elseif ( ctype_digit( $line ) ) {
					$custom_cats[ $line ] = 'Custom ' . $line;
				}
			}
			
			if ( ! empty( $custom_cats ) ) {
				$custom_option = $categories['custom'];
				unset( $categories['custom'] );
				foreach ( $custom_cats as $id => $label ) {
					$categories[ $id ] = $label;
				}
				$categories['custom'] = $custom_option;
			}
		}
		
		$cached = $categories;
		return $cached;
	}

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
	 * Constructor — register Action Scheduler callbacks.
	 */
	public function __construct() {
		add_action( self::PUSH_ACTION, array( $this, 'process_single_push' ), 10, 1 );

		// Strip the eBay link when a product is duplicated. WooCommerce copies
		// all post meta, so a duplicate inherited _ebay_item_id and appeared to
		// already be listed — while actually pointing at the ORIGINAL's live
		// listing. Pressing Update on the copy then overwrote the real listing,
		// and End Listing ended it.
		add_filter( 'woocommerce_duplicate_product_exclude_meta', array( __CLASS__, 'duplicate_exclude_meta' ) );
		add_action( 'woocommerce_product_duplicate', array( __CLASS__, 'on_product_duplicated' ), 10, 2 );

		// Yoast Duplicate Post and similar tools bypass WooCommerce's own
		// duplicator, so cover their hooks too.
		add_action( 'dp_duplicate_post', array( __CLASS__, 'on_third_party_duplicate' ), 10, 2 );
		add_action( 'dp_duplicate_page', array( __CLASS__, 'on_third_party_duplicate' ), 10, 2 );
	}

	/**
	 * Meta keys that identify a specific eBay listing and must never be copied.
	 *
	 * Per-product export *settings* (category, condition, grader, policies) are
	 * deliberately NOT in this list — carrying those over is the whole point of
	 * duplicating a product to build a similar one.
	 *
	 * @return string[]
	 */
	public static function listing_identity_meta_keys() {
		return array(
			// Identity of the eBay listing this product is bound to.
			'_ebay_item_id',
			'_ebay_sku',
			'_ebay_listing_status',
			'_ebay_listing_type',
			'_ebay_listing_duration',
			'_ebay_end_time',
			'_sync_last_updated',
			// Push state belonging to the original.
			'_ebay_export_status',
			'_ebay_export_error',
			'_ebay_export_last_pushed',
			// History belonging to the original.
			'_tcgiant_sync_log',
			'_tcgiant_sync_pushed',
		);
	}

	/**
	 * Tell WooCommerce not to copy eBay linkage meta when duplicating.
	 *
	 * @param array $exclude Meta keys WooCommerce will skip.
	 * @return array
	 */
	public static function duplicate_exclude_meta( $exclude ) {
		$exclude = is_array( $exclude ) ? $exclude : array();
		return array_merge( $exclude, self::listing_identity_meta_keys() );
	}

	/**
	 * Belt-and-braces cleanup after WooCommerce duplicates a product.
	 *
	 * The exclude-meta filter handles current WooCommerce, but this also clears
	 * per-order bookkeeping (which uses dynamic key names the filter cannot
	 * express) and covers older versions where the filter is absent.
	 *
	 * @param WC_Product $duplicate The new product.
	 * @param WC_Product $original  The product it was copied from.
	 * @return void
	 */
	public static function on_product_duplicated( $duplicate, $original = null ) {
		if ( ! $duplicate instanceof WC_Product ) {
			return;
		}
		self::unlink_product_from_ebay( $duplicate->get_id(), 'duplicated' );
	}

	/**
	 * Cleanup for third-party duplication plugins.
	 *
	 * @param int          $new_post_id The new post ID.
	 * @param WP_Post|null $post        The original post.
	 * @return void
	 */
	public static function on_third_party_duplicate( $new_post_id, $post = null ) {
		if ( 'product' !== get_post_type( $new_post_id ) ) {
			return;
		}
		self::unlink_product_from_ebay( (int) $new_post_id, 'duplicated' );
	}

	/**
	 * Remove every trace of an eBay listing binding from a product.
	 *
	 * Does not touch eBay itself — this only severs the local link so the
	 * product is treated as "not yet listed".
	 *
	 * @param int    $product_id Product ID.
	 * @param string $reason     Short reason, for the log.
	 * @return void
	 */
	public static function unlink_product_from_ebay( $product_id, $reason = 'unlinked' ) {
		global $wpdb;

		$product_id = (int) $product_id;
		if ( ! $product_id ) {
			return;
		}

		foreach ( self::listing_identity_meta_keys() as $key ) {
			delete_post_meta( $product_id, $key );
		}

		// Per-line-item order bookkeeping uses dynamic key names.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
			$product_id,
			$wpdb->esc_like( '_ebay_order_processed_' ) . '%'
		) );

		// Drop the row in the listings table so the Listings page does not show
		// the copy as a live listing.
		if ( class_exists( 'TCGiant_Sync_DB' ) && TCGiant_Sync_DB::table_exists() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( TCGiant_Sync_DB::table_name(), array( 'product_id' => $product_id ), array( '%d' ) );
		}

		// The "shared listing" admin notice is cached; drop it so the warning
		// disappears as soon as the conflict is resolved.
		delete_transient( 'tcgiant_shared_item_ids' );

		TCGiant_Sync_Logger::log( sprintf(
			'Product #%d %s — eBay listing link removed. It is now treated as not yet listed.',
			$product_id,
			$reason
		) );
	}

	/**
	 * Find other products bound to the same eBay Item ID.
	 *
	 * More than one product sharing an Item ID means a duplicate was made
	 * before the duplication fix landed. Acting on either one would hit the
	 * same live eBay listing.
	 *
	 * @param int    $product_id   The product being acted on.
	 * @param string $ebay_item_id The eBay Item ID.
	 * @return int[] Other product IDs sharing the Item ID.
	 */
	public static function find_products_sharing_item_id( $product_id, $ebay_item_id ) {
		global $wpdb;

		if ( empty( $ebay_item_id ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = '_ebay_item_id'
			   AND pm.meta_value = %s
			   AND pm.post_id != %d
			   AND p.post_type = 'product'
			   AND p.post_status != 'trash'",
			$ebay_item_id,
			(int) $product_id
		) );

		return array_map( 'intval', (array) $ids );
	}

	// -------------------------------------------------------------------------
	// State Management
	// -------------------------------------------------------------------------

	/**
	 * Get the current export state.
	 *
	 * @return array Export state data.
	 */
	public static function get_export_state() {
		return get_option( self::EXPORT_STATE_OPTION, array(
			'status'          => 'idle',   // idle, queued, pushing, complete, error
			'total_queued'    => 0,
			'total_pushed'    => 0,
			'total_errors'    => 0,
			'started_at'      => '',
			'last_activity'   => '',
			'last_completed'  => '',
			'last_item_title' => '',
		) );
	}

	/**
	 * Update export state.
	 *
	 * @param array $updates Key-value pairs to merge into current state.
	 */
	public static function update_export_state( $updates ) {
		$state = self::get_export_state();
		$state = array_merge( $state, $updates );
		$state['last_activity'] = current_time( 'mysql' );
		update_option( self::EXPORT_STATE_OPTION, $state );
	}

	// -------------------------------------------------------------------------
	// Public Entry Points
	// -------------------------------------------------------------------------

	/**
	 * Queue a single WooCommerce product for export to eBay.
	 *
	 * @param int $product_id WooCommerce Product ID.
	 * @return bool True if queued, false if product not found.
	 */
	public function push_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			TCGiant_Sync_Logger::error( sprintf( 'Export: Product ID %d not found.', $product_id ) );
			return false;
		}

		as_enqueue_async_action(
			self::PUSH_ACTION,
			array( 'product_id' => $product_id ),
			'tcgiant_sync_group'
		);

		// Clear any previous error so it doesn't linger during the new attempt.
		$product->update_meta_data( '_ebay_export_status', 'queued' );
		$product->update_meta_data( '_ebay_export_error', '' );
		$product->save();

		TCGiant_Sync_Logger::log( sprintf( 'Export: Queued "%s" (WC #%d) for push to eBay.', $product->get_name(), $product_id ) );
		return true;
	}

	/**
	 * Queue multiple WooCommerce products for export to eBay.
	 *
	 * @param int[] $product_ids Array of WooCommerce Product IDs.
	 * @return int Number of products successfully queued.
	 */
	public function bulk_push_products( $product_ids ) {
		if ( empty( $product_ids ) || ! is_array( $product_ids ) ) {
			return 0;
		}

		// Clear any existing pending export jobs to prevent stacking.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::PUSH_ACTION, null, 'tcgiant_sync_group' );
		}

		$queued = 0;
		foreach ( $product_ids as $index => $product_id ) {
			$product_id = (int) $product_id;
			if ( $product_id <= 0 ) {
				continue;
			}
			// Stagger jobs by 3 seconds each to avoid rate limiting.
			$delay = 3 * $queued;
			as_schedule_single_action(
				time() + $delay,
				self::PUSH_ACTION,
				array( 'product_id' => $product_id ),
				'tcgiant_sync_group'
			);
			$queued++;
		}

		if ( $queued > 0 ) {
			self::update_export_state( array(
				'status'       => 'queued',
				'total_queued' => $queued,
				'total_pushed' => 0,
				'total_errors' => 0,
				'started_at'   => current_time( 'mysql' ),
			) );
			TCGiant_Sync_Logger::log( sprintf( 'Export: Queued %d products for bulk push to eBay.', $queued ) );
		}

		return $queued;
	}

	// -------------------------------------------------------------------------
	// Action Scheduler Callback
	// -------------------------------------------------------------------------

	/**
	 * Process a single product push (called by Action Scheduler).
	 *
	 * @param int $product_id WooCommerce Product ID.
	 */
	public function process_single_push( $product_id ) {
		$product_id = (int) $product_id;
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			self::update_export_state( array(
				'total_errors' => self::get_export_state()['total_errors'] + 1,
			) );
			TCGiant_Sync_Logger::error( sprintf( 'Export: Product ID %d not found during async push.', $product_id ) );
			$this->check_export_completion();
			return;
		}

		$title = $product->get_name();

		try {
			$result = $this->do_push( $product );

			if ( is_wp_error( $result ) ) {
				$error_msg = $result->get_error_message();
				$product->update_meta_data( '_ebay_export_status', 'error' );
				$product->update_meta_data( '_ebay_export_error', $error_msg );
				$product->save();

				self::update_export_state( array(
					'total_errors' => self::get_export_state()['total_errors'] + 1,
				) );
				TCGiant_Sync_Logger::error( sprintf( 'Export failed for "%s" (WC #%d): %s', $title, $product_id, $error_msg ) );
			} else {
				$item_id = $result['item_id'];
				$action  = $result['action']; // 'created' or 'updated'

				$product->update_meta_data( '_ebay_item_id', $item_id );
				$product->update_meta_data( '_ebay_export_status', 'pushed' );
				$product->update_meta_data( '_ebay_export_error', '' );
				$product->update_meta_data( '_ebay_export_last_pushed', current_time( 'mysql' ) );
				$product->save();

				$state = self::get_export_state();
				self::update_export_state( array(
					'total_pushed'    => $state['total_pushed'] + 1,
					'last_item_title' => $title,
				) );

				$license_data = TCGiant_Sync_License::instance()->get_license_data();
				$license_type = 'free';
				if ( ! empty( $license_data['status'] ) && 'active' === $license_data['status'] ) {
					$license_type = ! empty( $license_data['variant'] ) ? $license_data['variant'] : 'pro';
				}

				// Send telemetry ping to TCGiant
				wp_remote_post( 'https://tcgiant.com/syncconnect/telemetry.php', array(
					'blocking' => false,
					'headers'  => array(
						'Content-Type' => 'application/json',
					),
					'body'     => wp_json_encode( array(
						'site_url'     => get_site_url(),
						'synced_count' => 1,
						'pushed_count' => 1,
						'license_type' => $license_type,
					) ),
				) );

				TCGiant_Sync_Logger::log(
					sprintf(
						'Export %s: "%s" (WC #%d) → eBay Item ID %s.',
						$action, $title, $product_id, $item_id
					),
					'success'
				);
			}
		} catch ( Exception $e ) {
			self::update_export_state( array(
				'total_errors' => self::get_export_state()['total_errors'] + 1,
			) );
			TCGiant_Sync_Logger::error( sprintf( 'Export exception for "%s" (WC #%d): %s', $title, $product_id, $e->getMessage() ) );
		} catch ( Error $e ) {
			self::update_export_state( array(
				'total_errors' => self::get_export_state()['total_errors'] + 1,
			) );
			TCGiant_Sync_Logger::error( sprintf( 'Export fatal error for "%s" (WC #%d): %s', $title, $product_id, $e->getMessage() ) );
		}

		$this->check_export_completion();
	}

	// -------------------------------------------------------------------------
	// Core Push Logic
	// -------------------------------------------------------------------------

	/**
	 * Execute the push for a single product — creates or updates the eBay listing.
	 *
	 * @param WC_Product $product The WooCommerce product.
	 * @return array|WP_Error On success, array with 'item_id' and 'action' keys.
	 */
	private function do_push( WC_Product $product ) {
		$settings = $this->get_export_settings( $product->get_id() );

		// Validate required settings before hitting the API.
		$validation = $this->validate_export_settings( $settings );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Refuse to list a stock-managed product with nothing available.
		// build_item_xml() floors the quantity at 1 so that Verify still
		// produces valid XML, which meant pushing an out-of-stock product
		// created a live eBay listing offering one unit that cannot be
		// fulfilled — and, with auto-end-on-zero-stock enabled, the stock
		// listener then immediately ended the listing it had just created.
		if ( ! $product->is_type( 'variable' ) && $product->managing_stock() && (int) $product->get_stock_quantity() < 1 ) {
			return new WP_Error(
				'out_of_stock',
				__( 'Cannot list on eBay: this product has no stock available. Add stock, or turn off stock management for it, then push again.', 'tcgiant-sync' )
			);
		}

		$item_xml  = $this->build_item_xml( $product, $settings );
		$api       = TCGiant_Sync_API::instance();
		$ebay_item_id = $product->get_meta( '_ebay_item_id' );

		// Refuse to revise a listing that more than one product claims.
		//
		// Duplicating a product used to copy _ebay_item_id, so the copy pointed
		// at the original's live listing. Revising from the copy would overwrite
		// a real listing with the copy's title, price and images. Products
		// duplicated before that was fixed still carry the stale link, so this
		// guard stays regardless.
		if ( ! empty( $ebay_item_id ) ) {
			$shared_with = self::find_products_sharing_item_id( $product->get_id(), $ebay_item_id );
			if ( ! empty( $shared_with ) ) {
				$message = sprintf(
					/* translators: 1: eBay Item ID, 2: comma-separated product IDs */
					__( 'Refusing to update eBay listing %1$s: product(s) #%2$s are linked to the same listing. This usually means this product was duplicated from another one. Use "Unlink from eBay" on whichever product should not own the listing, then push again.', 'tcgiant-sync' ),
					$ebay_item_id,
					implode( ', ', $shared_with )
				);
				TCGiant_Sync_Logger::error( sprintf(
					'Blocked push for WC #%d — eBay Item %s is also claimed by WC #%s.',
					$product->get_id(), $ebay_item_id, implode( ', #', $shared_with )
				) );
				return new WP_Error( 'shared_item_id', $message );
			}
		}

		if ( ! empty( $ebay_item_id ) ) {
			// Existing listing — revise it.
			$full_xml = '<Item>' . "\n" . '<ItemID>' . esc_attr( $ebay_item_id ) . '</ItemID>' . "\n" . $item_xml . "\n" . '</Item>';
			$response = $api->revise_item( $full_xml );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// ReviseItem returns ItemID in the response.
			$returned_item_id = $response['ItemID'] ?? $ebay_item_id;
			return array( 'item_id' => $returned_item_id, 'action' => 'updated' );

		} else {
			// New listing — add it. UUID prevents duplicate creation on timeout/retry.
			$uuid = TCGiant_Sync_API::generate_ebay_uuid();
			$full_xml = '<Item>' . "\n" . '<UUID>' . $uuid . '</UUID>' . "\n" . $item_xml . "\n" . '</Item>';
			$response = $api->add_item( $full_xml );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( empty( $response['ItemID'] ) ) {
				return new WP_Error( 'no_item_id', __( 'eBay did not return an Item ID after AddItem. Listing may have been created — check your eBay account.', 'tcgiant-sync' ) );
			}

			return array( 'item_id' => $response['ItemID'], 'action' => 'created' );
		}
	}

	/**
	 * Build the inner <Item> XML content (without the outer <Item> tags).
	 *
	 * @param WC_Product $product  The WooCommerce product.
	 * @param array      $settings Merged export settings.
	 * @return string XML string.
	 */
	public function build_item_xml_public( WC_Product $product, array $settings ) {
		return $this->build_item_xml( $product, $settings );
	}

	/**
	 * Build the inner <Item> XML content (without the outer <Item> tags).
	 *
	 * @param WC_Product $product  The WooCommerce product.
	 * @param array      $settings Merged export settings.
	 * @return string XML string.
	 */
	private function build_item_xml( WC_Product $product, array $settings ) {
		$title       = $this->sanitize_title( $product->get_name() );
		$description = $this->build_description( $product );
		$price       = wc_format_decimal( $product->get_regular_price(), 2 );
		// Floored at 1 so VerifyAddItem still gets valid XML for a zero-stock
		// product. do_push() blocks the real listing separately — do not rely
		// on this clamp to prevent overselling.
		$quantity    = max( 1, (int) $product->get_stock_quantity() );
		$sku         = $product->get_sku();
		$images      = $this->get_product_image_urls( $product );

		// Build picture URLs XML.
		$picture_xml = '';
		if ( ! empty( $images ) ) {
			$picture_xml = '<PictureDetails>' . "\n";
			foreach ( array_slice( $images, 0, self::MAX_IMAGES ) as $url ) {
				$picture_xml .= "\t<PictureURL>" . esc_url( $url ) . "</PictureURL>\n";
			}
			$picture_xml .= '</PictureDetails>';
		}

		// Build seller profiles XML (business policies).
		$profiles_xml = '';
		if ( ! empty( $settings['fulfillment_policy_id'] ) || ! empty( $settings['return_policy_id'] ) || ! empty( $settings['payment_policy_id'] ) ) {
			$profiles_xml = '<SellerProfiles>' . "\n";
			if ( ! empty( $settings['fulfillment_policy_id'] ) ) {
				$profiles_xml .= "\t<SellerShippingProfile>\n\t\t<ShippingProfileID>" . esc_attr( $settings['fulfillment_policy_id'] ) . "</ShippingProfileID>\n\t</SellerShippingProfile>\n";
			}
			if ( ! empty( $settings['return_policy_id'] ) ) {
				$profiles_xml .= "\t<SellerReturnProfile>\n\t\t<ReturnProfileID>" . esc_attr( $settings['return_policy_id'] ) . "</ReturnProfileID>\n\t</SellerReturnProfile>\n";
			}
			if ( ! empty( $settings['payment_policy_id'] ) ) {
				$profiles_xml .= "\t<SellerPaymentProfile>\n\t\t<PaymentProfileID>" . esc_attr( $settings['payment_policy_id'] ) . "</PaymentProfileID>\n\t</SellerPaymentProfile>\n";
			}
			$profiles_xml .= '</SellerProfiles>';
		}

		$xml  = '<Title>' . esc_xml( $title ) . '</Title>' . "\n";
		// Split any literal "]]>" in the description so it cannot terminate the
		// CDATA section early. Unescaped, a description containing that
		// sequence (common in code snippets) either breaks the request or
		// injects raw XML into the AddItem payload.
		$xml .= '<Description><![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $description ) . ']]></Description>' . "\n";
		$xml .= '<PrimaryCategory><CategoryID>' . esc_attr( $settings['category_id'] ) . '</CategoryID></PrimaryCategory>' . "\n";
		
	
		if ( ! $product->is_type( 'variable' ) ) {
			// Auctions are always quantity 1 on eBay.
			$is_auction = 'Chinese' === ( $settings['listing_type'] ?? 'FixedPriceItem' );
			$qty = $is_auction ? 1 : (int) $quantity;
			$xml .= '<StartPrice>' . esc_attr( $price ) . '</StartPrice>' . "\n";
			$xml .= '<Quantity>' . $qty . '</Quantity>' . "\n";
			if ( ! empty( $sku ) ) {
				$xml .= '<SKU>' . esc_xml( $sku ) . '</SKU>' . "\n";
			}
		}

		$xml .= '<CategoryMappingAllowed>true</CategoryMappingAllowed>' . "\n";

		// Condition: use new ConditionDescriptor system for eligible categories,
		// fall back to legacy ConditionID for everything else.
		$descriptor_xml = $this->build_condition_descriptors_xml( $settings );
		if ( ! empty( $descriptor_xml ) ) {
			// ConditionID is set inside build_condition_descriptors_xml based on type.
			$condition_id = 'graded' === $settings['condition_type'] ? '2750' : '4000';
			$xml .= '<ConditionID>' . esc_attr( $condition_id ) . '</ConditionID>' . "\n";
			$xml .= $descriptor_xml . "\n";
		} else {
			$xml .= '<ConditionID>' . esc_attr( $settings['condition_id'] ) . '</ConditionID>' . "\n";
		}

		// ItemSpecifics: required for Coins categories (e.g. Certification, Grade).
		$item_specifics_xml = $this->build_item_specifics_xml( $settings );
		if ( ! empty( $item_specifics_xml ) ) {
			$xml .= $item_specifics_xml . "\n";
		}

		// Derive Country, Currency, and Site from the configured marketplace.
		$marketplace = TCGiant_Sync_API::instance()->get_marketplace_config();

		$xml .= '<Country>' . esc_attr( $marketplace['country'] ) . '</Country>' . "\n";
		if ( ! empty( $settings['location'] ) ) {
			$xml .= '<Location>' . esc_xml( $settings['location'] ) . '</Location>' . "\n";
		}
		if ( ! empty( $settings['postal_code'] ) ) {
			$xml .= '<PostalCode>' . esc_xml( $settings['postal_code'] ) . '</PostalCode>' . "\n";
		}
		$xml .= '<Currency>' . esc_attr( $marketplace['currency'] ) . '</Currency>' . "\n";
		$xml .= '<DispatchTimeMax>3</DispatchTimeMax>' . "\n";

		// Listing type and duration: default to FixedPriceItem/GTC, overridable per-product.
		$listing_type     = $settings['listing_type'] ?? 'FixedPriceItem';
		$listing_duration = $settings['listing_duration'] ?? 'GTC';

		// Validate duration is compatible with listing type.
		$valid_durations = self::DURATIONS_BY_TYPE[ $listing_type ] ?? array( 'GTC' );
		if ( ! in_array( $listing_duration, $valid_durations, true ) ) {
			$listing_duration = $valid_durations[0]; // Fall back to first valid duration.
		}

		$xml .= '<ListingDuration>' . esc_attr( $listing_duration ) . '</ListingDuration>' . "\n";
		$xml .= '<ListingType>' . esc_attr( $listing_type ) . '</ListingType>' . "\n";
		$xml .= '<Site>' . esc_attr( $marketplace['site'] ) . '</Site>' . "\n";

		if ( $product instanceof WC_Product_Variable ) {
			$xml .= '<Variations>' . "\n";
			$xml .= "\t<VariationSpecificsSet>\n";
			$attributes = $product->get_variation_attributes();
			foreach ( $attributes as $attr_name => $options ) {
				$label = wc_attribute_label( $attr_name );
				$xml .= "\t\t<NameValueList>\n";
				$xml .= "\t\t\t<Name>" . esc_xml( $label ) . "</Name>\n";
				foreach ( $options as $opt ) {
					$term = get_term_by( 'slug', $opt, $attr_name );
					$val = $term ? $term->name : $opt;
					$xml .= "\t\t\t<Value>" . esc_xml( $val ) . "</Value>\n";
				}
				$xml .= "\t\t</NameValueList>\n";
			}
			$xml .= "\t</VariationSpecificsSet>\n";
			
			$children_ids = $product->get_children();
			foreach ( $children_ids as $child_id ) {
				$child = wc_get_product( $child_id );
				// get_children() on a variable product yields variations, but
				// guard explicitly so get_variation_attributes() is safe.
				if ( ! $child instanceof WC_Product_Variation || 'publish' !== $child->get_status() ) continue;
				
				$xml .= "\t<Variation>\n";
				$xml .= "\t\t<SKU>" . esc_xml( $child->get_sku() ) . "</SKU>\n";
				$xml .= "\t\t<StartPrice>" . esc_attr( wc_format_decimal( $child->get_regular_price(), 2 ) ) . "</StartPrice>\n";
				$xml .= "\t\t<Quantity>" . max( 0, (int) $child->get_stock_quantity() ) . "</Quantity>\n";
				
				$xml .= "\t\t<VariationSpecifics>\n";
				$child_attrs = $child->get_variation_attributes();
				foreach ( $child_attrs as $key => $val ) {
					$attr_name = str_replace( 'attribute_', '', $key );
					$label = wc_attribute_label( $attr_name );
					$term = get_term_by( 'slug', $val, $attr_name );
					$val_name = $term ? $term->name : $val;
					
					$xml .= "\t\t\t<NameValueList>\n";
					$xml .= "\t\t\t\t<Name>" . esc_xml( $label ) . "</Name>\n";
					$xml .= "\t\t\t\t<Value>" . esc_xml( $val_name ) . "</Value>\n";
					$xml .= "\t\t\t</NameValueList>\n";
				}
				$xml .= "\t\t</VariationSpecifics>\n";
				$xml .= "\t</Variation>\n";
			}
			$xml .= "</Variations>\n";
		}

		if ( $picture_xml ) {
			$xml .= $picture_xml . "\n";
		}

		if ( $profiles_xml ) {
			$xml .= $profiles_xml . "\n";
		}

		// Best Offer support (FixedPriceItem only).
		if ( 'FixedPriceItem' === $listing_type ) {
			$best_offer_enabled = ! empty( $settings['best_offer_enabled'] );
			$product_bo         = $product->get_meta( '_tcgiant_best_offer_enabled' );
			// Per-product override.
			if ( '' !== $product_bo ) {
				$best_offer_enabled = (bool) $product_bo;
			}

			if ( $best_offer_enabled ) {
				$xml .= '<BestOfferDetails>' . "\n";
				$xml .= "\t<BestOfferEnabled>true</BestOfferEnabled>\n";
				$xml .= '</BestOfferDetails>' . "\n";

				// Auto-accept/decline thresholds.
				$auto_accept  = $settings['best_offer_auto_accept'] ?? '';
				$auto_decline = $settings['best_offer_auto_decline'] ?? '';

				// Allow per-product override.
				$product_accept  = $product->get_meta( '_tcgiant_bo_auto_accept' );
				$product_decline = $product->get_meta( '_tcgiant_bo_auto_decline' );
				if ( '' !== $product_accept ) {
					$auto_accept = $product_accept;
				}
				if ( '' !== $product_decline ) {
					$auto_decline = $product_decline;
				}

				$bo_xml = '<ListingDetails>' . "\n";
				if ( ! empty( $auto_accept ) && is_numeric( $auto_accept ) ) {
					$accept_price = (float) $auto_accept > 1 ? $auto_accept : wc_format_decimal( (float) $price * (float) $auto_accept, 2 );
					$bo_xml .= "\t<MinimumBestOfferPrice>" . esc_attr( $accept_price ) . "</MinimumBestOfferPrice>\n";
				}
				if ( ! empty( $auto_decline ) && is_numeric( $auto_decline ) ) {
					$decline_price = (float) $auto_decline > 1 ? $auto_decline : wc_format_decimal( (float) $price * (float) $auto_decline, 2 );
					$bo_xml .= "\t<BestOfferAutoDeclinePrice>" . esc_attr( $decline_price ) . "</BestOfferAutoDeclinePrice>\n";
				}
				$bo_xml .= '</ListingDetails>' . "\n";
				$xml .= $bo_xml;
			}
		}

		// GPSR (EU General Product Safety Regulation) compliance fields.
		$gpsr_enabled = ! empty( $settings['gpsr_enabled'] );
		if ( $gpsr_enabled ) {
			$gpsr_xml = '';
			$gpsr_mfg = $settings['gpsr_manufacturer_name'] ?? '';
			$gpsr_address = $settings['gpsr_manufacturer_address'] ?? '';
			$gpsr_eu_rep = $settings['gpsr_eu_responsible'] ?? '';

			if ( ! empty( $gpsr_mfg ) || ! empty( $gpsr_address ) ) {
				$gpsr_xml .= '<ProductSafety>' . "\n";
				if ( ! empty( $gpsr_mfg ) ) {
					$gpsr_xml .= '<Component>' . "\n";
					$gpsr_xml .= "\t<Type>Manufacturer</Type>\n";
					$gpsr_xml .= "\t<Value>" . esc_xml( $gpsr_mfg ) . "</Value>\n";
					$gpsr_xml .= '</Component>' . "\n";
				}
				if ( ! empty( $gpsr_address ) ) {
					$gpsr_xml .= '<Component>' . "\n";
					$gpsr_xml .= "\t<Type>ManufacturerAddress</Type>\n";
					$gpsr_xml .= "\t<Value>" . esc_xml( $gpsr_address ) . "</Value>\n";
					$gpsr_xml .= '</Component>' . "\n";
				}
				if ( ! empty( $gpsr_eu_rep ) ) {
					$gpsr_xml .= '<Component>' . "\n";
					$gpsr_xml .= "\t<Type>ResponsiblePerson</Type>\n";
					$gpsr_xml .= "\t<Value>" . esc_xml( $gpsr_eu_rep ) . "</Value>\n";
					$gpsr_xml .= '</Component>' . "\n";
				}
				$gpsr_xml .= '</ProductSafety>' . "\n";
				$xml .= $gpsr_xml;
			}
		}

		return $xml;
	}

	// -------------------------------------------------------------------------
	// Settings & Validation
	// -------------------------------------------------------------------------

	/**
	 * Get merged export settings: global defaults overridden by per-product meta.
	 *
	 * @param int $product_id WooCommerce Product ID.
	 * @return array Merged settings ready for use in build_item_xml().
	 */
	public function get_export_settings( $product_id ) {
		$global   = TCGiant_Sync_OAuth::instance()->get_settings();
		$product  = wc_get_product( $product_id );

		$settings = array(
			'category_id'           => $global['export_category_id'] ?? '',
			'condition_id'          => $global['export_condition_id'] ?? '1000',
			'condition_type'        => $global['export_condition_type'] ?? '',
			'grader_id'             => $global['export_grader_id'] ?? '',
			'grade_value'           => $global['export_grade_value'] ?? '',
			'cert_number'           => $global['export_cert_number'] ?? '',
			'coin_year'             => $global['export_coin_year'] ?? '',
			'ungraded_condition'    => $global['export_ungraded_condition'] ?? '',
			'location'              => $global['export_location'] ?? '',
			'postal_code'           => $global['export_postal_code'] ?? '',
			'fulfillment_policy_id' => $global['export_fulfillment_policy'] ?? '',
			'return_policy_id'      => $global['export_return_policy'] ?? '',
			'payment_policy_id'     => $global['export_payment_policy'] ?? '',
			'listing_type'          => $global['export_listing_type'] ?? 'FixedPriceItem',
			'listing_duration'      => $global['export_listing_duration'] ?? 'GTC',
		);

		if ( $product ) {
			// Per-product overrides take precedence if set.
			$override_category  = $product->get_meta( '_ebay_export_category_id' );
			$override_condition = $product->get_meta( '_ebay_export_condition_id' );

			if ( ! empty( $override_category ) ) {
				$settings['category_id'] = $override_category;
			}
			if ( ! empty( $override_condition ) ) {
				$settings['condition_id'] = $override_condition;
			}

			// ConditionDescriptor per-product overrides.
			$override_cond_type = $product->get_meta( '_ebay_export_condition_type' );
			if ( ! empty( $override_cond_type ) ) {
				$settings['condition_type'] = $override_cond_type;
			}

			$override_item_type = $product->get_meta( '_ebay_export_item_type' );
			if ( ! empty( $override_item_type ) ) {
				$settings['item_type'] = $override_item_type;
			}

			$override_grader = $product->get_meta( '_ebay_export_grader_id' );
			if ( ! empty( $override_grader ) ) {
				$settings['grader_id'] = $override_grader;
			}

			$override_grade = $product->get_meta( '_ebay_export_grade_value' );
			if ( ! empty( $override_grade ) ) {
				$settings['grade_value'] = $override_grade;
			}

			$override_cert = $product->get_meta( '_ebay_export_cert_number' );
			if ( ! empty( $override_cert ) ) {
				$settings['cert_number'] = $override_cert;
			}

			$override_coin_year = $product->get_meta( '_ebay_export_coin_year' );
			if ( ! empty( $override_coin_year ) ) {
				$settings['coin_year'] = $override_coin_year;
			}

			$override_ungraded = $product->get_meta( '_ebay_export_ungraded_condition' );
			if ( ! empty( $override_ungraded ) ) {
				$settings['ungraded_condition'] = $override_ungraded;
			}

			// Listing type & duration per-product overrides.
			$override_listing_type = $product->get_meta( '_ebay_export_listing_type' );
			if ( ! empty( $override_listing_type ) ) {
				$settings['listing_type'] = $override_listing_type;
			}

			$override_listing_duration = $product->get_meta( '_ebay_export_listing_duration' );
			if ( ! empty( $override_listing_duration ) ) {
				$settings['listing_duration'] = $override_listing_duration;
			}

			// Per-product shipping/fulfillment policy override.
			$override_fulfillment = $product->get_meta( '_ebay_export_fulfillment_policy' );
			if ( ! empty( $override_fulfillment ) ) {
				$settings['fulfillment_policy_id'] = $override_fulfillment;
			}
		}

		// Flag category-item_type mismatch so validation can catch it.
		// e.g. user picks "Coins" but the global default category is 183050 (TCG).
		$item_type   = $settings['item_type'] ?? '';
		$category_id = $settings['category_id'] ?? '';
		if ( 'coins' === $item_type && in_array( (string) $category_id, self::DESCRIPTOR_CATEGORIES_TCG, true ) ) {
			$settings['_category_mismatch'] = 'coins_in_tcg';
		} elseif ( 'tcg' === $item_type && in_array( (string) $category_id, self::DESCRIPTOR_CATEGORIES_COINS, true ) ) {
			$settings['_category_mismatch'] = 'tcg_in_coins';
		}

		return $settings;
	}

	/**
	 * Validate that the minimum required settings for a push are present.
	 *
	 * @param array $settings Export settings array.
	 * @return true|WP_Error True on success, WP_Error describing what's missing.
	 */
	public function validate_export_settings( array $settings ) {
		$missing = array();

		// Check for category-item_type mismatch (set in get_export_settings).
		if ( ! empty( $settings['_category_mismatch'] ) ) {
			if ( 'coins_in_tcg' === $settings['_category_mismatch'] ) {
				$missing[] = __( 'eBay Category — this is a Coins item but the eBay category is set to Trading Cards. Set a Coins leaf category (e.g. "Coins: US > Dollars > Silver Eagles") on this product using Browse eBay Categories in the TCGiant Sync tab', 'tcgiant-sync' );
			} elseif ( 'tcg_in_coins' === $settings['_category_mismatch'] ) {
				$missing[] = __( 'eBay Category — this is a Trading Cards item but the eBay category is set to Coins. Set a TCG leaf category on this product using Browse eBay Categories in the TCGiant Sync tab', 'tcgiant-sync' );
			}
		}

		if ( empty( $settings['category_id'] ) ) {
			$missing[] = __( 'eBay Category ID (set a default in TCGiant Sync settings)', 'tcgiant-sync' );
		}
		if ( empty( $settings['location'] ) ) {
			$missing[] = __( 'Item Location (set city/state in TCGiant Sync settings)', 'tcgiant-sync' );
		}
		if ( empty( $settings['postal_code'] ) ) {
			$missing[] = __( 'Postal Code (set ZIP code in TCGiant Sync settings)', 'tcgiant-sync' );
		}
		if ( empty( $settings['fulfillment_policy_id'] ) ) {
			$missing[] = __( 'Fulfillment (Shipping) Policy (fetch policies in TCGiant Sync settings)', 'tcgiant-sync' );
		}
		if ( empty( $settings['return_policy_id'] ) ) {
			$missing[] = __( 'Return Policy (fetch policies in TCGiant Sync settings)', 'tcgiant-sync' );
		}
		if ( empty( $settings['payment_policy_id'] ) ) {
			$missing[] = __( 'Payment Policy (fetch policies in TCGiant Sync settings)', 'tcgiant-sync' );
		}

		// Validate ConditionDescriptor fields for eligible categories.
		$item_type = $settings['item_type'] ?? '';
		if ( self::is_descriptor_category( $settings['category_id'], $item_type ) ) {
			if ( empty( $settings['condition_type'] ) ) {
				$missing[] = __( 'Condition Type (select Graded or Ungraded in TCGiant Sync settings — required for this eBay category)', 'tcgiant-sync' );
			} elseif ( 'graded' === $settings['condition_type'] ) {
				if ( empty( $settings['grader_id'] ) ) {
					$missing[] = __( 'Professional Grader (required for graded items)', 'tcgiant-sync' );
				}
				if ( empty( $settings['grade_value'] ) ) {
					$missing[] = __( 'Grade (required for graded items)', 'tcgiant-sync' );
				}
			} elseif ( 'ungraded' === $settings['condition_type'] ) {
				if ( empty( $settings['ungraded_condition'] ) ) {
					$missing[] = __( 'Ungraded Condition (required for ungraded items in this category)', 'tcgiant-sync' );
				}
			}
		}

		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'missing_export_settings',
				/* translators: %s: comma-separated list of missing settings */
				sprintf( __( 'Cannot push to eBay — missing required settings: %s', 'tcgiant-sync' ), implode( ', ', $missing ) )
			);
		}

		// Validate listing duration compatibility with listing type.
		$listing_type     = $settings['listing_type'] ?? 'FixedPriceItem';
		$listing_duration = $settings['listing_duration'] ?? 'GTC';
		$valid_durations  = self::DURATIONS_BY_TYPE[ $listing_type ] ?? array( 'GTC' );
		if ( ! in_array( $listing_duration, $valid_durations, true ) ) {
			$type_label = self::LISTING_TYPES[ $listing_type ] ?? $listing_type;
			$valid_labels = array_map( function( $d ) { return self::LISTING_DURATIONS[ $d ] ?? $d; }, $valid_durations );
			return new WP_Error(
				'invalid_duration',
				sprintf(
					__( 'Invalid listing duration for %1$s listings. Valid options: %2$s', 'tcgiant-sync' ),
					$type_label,
					implode( ', ', $valid_labels )
				)
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// ItemSpecifics Methods
	// -------------------------------------------------------------------------

	/**
	 * Build <ItemSpecifics> XML for the listing.
	 *
	 * eBay Coins categories require item specifics like "Certification" (grader name)
	 * in addition to the structured ConditionDescriptors. Returns empty string for
	 * non-coin categories or if no relevant data is available.
	 *
	 * @param array $settings Merged export settings.
	 * @return string XML string or empty.
	 */
	private function build_item_specifics_xml( array $settings ) {
		$item_type = $settings['item_type'] ?? '';

		// Only generate ItemSpecifics for Coins categories.
		if ( ! self::is_coins_category( $settings['category_id'] ?? '', $item_type ) ) {
			return '';
		}

		$specifics = array();

		if ( 'graded' === ( $settings['condition_type'] ?? '' ) ) {
			// Certification — the grader name (e.g. "NGC", "PCGS").
			if ( ! empty( $settings['grader_id'] ) ) {
				$grader_name = self::get_grader_name_by_id( $settings['grader_id'], 'coins' );
				if ( $grader_name ) {
					$specifics['Certification'] = $grader_name;
				}
			}

			// Grade (e.g. "MS/PR 69").
			if ( ! empty( $settings['grade_value'] ) ) {
				$specifics['Grade'] = $settings['grade_value'];
			}

			// Certification Number.
			if ( ! empty( $settings['cert_number'] ) ) {
				$specifics['Certification Number'] = $settings['cert_number'];
			}
		} elseif ( 'ungraded' === ( $settings['condition_type'] ?? '' ) ) {
			// Ungraded coins should indicate they are not certified.
			$specifics['Certification'] = 'Uncertified';
		}

		if ( empty( $specifics ) ) {
			// Year is required for all Coins categories, even if no other specifics are set.
			if ( empty( $settings['coin_year'] ) ) {
				return '';
			}
		}

		// Year: required by eBay for all Coins & Paper Money categories.
		if ( ! empty( $settings['coin_year'] ) ) {
			$specifics['Year'] = $settings['coin_year'];
		}

		$xml = '<ItemSpecifics>' . "\n";
		foreach ( $specifics as $name => $value ) {
			$xml .= "\t<NameValueList>\n";
			$xml .= "\t\t<Name>" . esc_xml( $name ) . "</Name>\n";
			$xml .= "\t\t<Value>" . esc_xml( $value ) . "</Value>\n";
			$xml .= "\t</NameValueList>\n";
		}
		$xml .= '</ItemSpecifics>';

		return $xml;
	}

	/**
	 * Reverse-lookup a grader's display name from its eBay conditionDescriptorValueId.
	 *
	 * @param string $value_id The numeric value ID stored in settings.
	 * @param string $type     Either 'coins' or 'tcg'.
	 * @return string|false The grader display name, or false if not found.
	 */
	public static function get_grader_name_by_id( $value_id, $type = 'coins' ) {
		$map = 'coins' === $type ? self::GRADERS_COINS : self::GRADERS_TCG;

		foreach ( $map as $name => $id ) {
			if ( (string) $id === (string) $value_id ) {
				return $name;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// ConditionDescriptor Methods
	// -------------------------------------------------------------------------

	/**
	 * Check if a category ID requires ConditionDescriptors.
	 *
	 * @param string $category_id eBay category ID.
	 * @return bool True if the category requires descriptors.
	 */
	public static function is_descriptor_category( $category_id, $item_type = '' ) {
		// "All Other" items never use ConditionDescriptors.
		if ( 'other' === $item_type ) {
			return false;
		}
		if ( in_array( $item_type, array( 'tcg', 'coins' ), true ) ) {
			return true;
		}
		return in_array( (string) $category_id, self::DESCRIPTOR_CATEGORIES_TCG, true )
		    || in_array( (string) $category_id, self::DESCRIPTOR_CATEGORIES_COINS, true );
	}

	/**
	 * Check if a category is a Coins category (vs TCG).
	 *
	 * @param string $category_id eBay category ID.
	 * @param string $item_type Optional item type override.
	 * @return bool True if the category is a Coins category.
	 */
	public static function is_coins_category( $category_id, $item_type = '' ) {
		$cat_str = (string) $category_id;

		// The actual eBay category is authoritative. If the category is in a
		// known list, use that — regardless of what item_type the user selected.
		// This prevents sending Coins descriptors to a TCG category (or vice versa).
		if ( in_array( $cat_str, self::DESCRIPTOR_CATEGORIES_COINS, true ) ) {
			return true;
		}
		if ( in_array( $cat_str, self::DESCRIPTOR_CATEGORIES_TCG, true ) ) {
			return false;
		}

		// For custom/unknown categories, fall back to item_type.
		return 'coins' === $item_type;
	}

	/**
	 * Get the appropriate ungraded conditions for a category.
	 *
	 * @param string $category_id eBay category ID.
	 * @return array Value ID => label.
	 */
	public static function get_ungraded_conditions( $category_id, $item_type = '' ) {
		if ( self::is_coins_category( $category_id, $item_type ) ) {
			return self::UNGRADED_COINS;
		}
		return self::UNGRADED_TCG;
	}

	/**
	 * Build <ConditionDescriptors> XML for the listing.
	 *
	 * Returns empty string if the category does not require descriptors
	 * or if the condition_type is not set.
	 *
	 * @param array $settings Merged export settings.
	 * @return string XML string or empty.
	 */
	private function build_condition_descriptors_xml( array $settings ) {
		$item_type = $settings['item_type'] ?? '';
		if ( ! self::is_descriptor_category( $settings['category_id'], $item_type ) ) {
			return '';
		}

		if ( empty( $settings['condition_type'] ) ) {
			return '';
		}

		$xml = '<ConditionDescriptors>' . "\n";

		if ( 'graded' === $settings['condition_type'] ) {
			if ( self::is_coins_category( $settings['category_id'], $item_type ) ) {
				// ---- COINS: Graded ----
				// Professional Grader (Descriptor Name 1) — value must be a numeric ID.
				if ( ! empty( $settings['grader_id'] ) ) {
					$xml .= "\t<ConditionDescriptor>\n";
					$xml .= "\t\t<Name>1</Name>\n";
					$xml .= "\t\t<Value>" . esc_attr( $settings['grader_id'] ) . "</Value>\n";
					$xml .= "\t</ConditionDescriptor>\n";
				}

				// Grade (split into Letter [Descriptor 3] and Numeric [Descriptor 4])
				// User selects e.g. "MS/PR 65" — split into letter "MS/PR" and number "65",
				// then look up the eBay conditionDescriptorValueId for each.
				// Text aliases like "Gem BU (≈MS 65)" are resolved to their canonical
				// Sheldon-scale value before splitting.
				if ( ! empty( $settings['grade_value'] ) ) {
					$grade_raw = $settings['grade_value'];

					// Resolve text-based aliases to canonical Sheldon grades.
					if ( isset( self::TEXT_GRADE_ALIASES[ $grade_raw ] ) ) {
						$grade_raw = self::TEXT_GRADE_ALIASES[ $grade_raw ];
					}

					$parts = explode( ' ', $grade_raw, 2 );
					if ( count( $parts ) === 2 ) {
						$letter_label = $parts[0];
						$number_label = $parts[1];

						// Look up Letter Grade value ID.
						$letter_value_id = self::LETTER_GRADES_COINS[ $letter_label ] ?? '';
						if ( $letter_value_id ) {
							$xml .= "\t<ConditionDescriptor>\n";
							$xml .= "\t\t<Name>3</Name>\n";
							$xml .= "\t\t<Value>" . esc_attr( $letter_value_id ) . "</Value>\n";
							$xml .= "\t</ConditionDescriptor>\n";
						}

						// Look up Numeric Grade value ID.
						$numeric_value_id = self::NUMERIC_GRADES_COINS[ $number_label ] ?? '';
						if ( $numeric_value_id ) {
							$xml .= "\t<ConditionDescriptor>\n";
							$xml .= "\t\t<Name>4</Name>\n";
							$xml .= "\t\t<Value>" . esc_attr( $numeric_value_id ) . "</Value>\n";
							$xml .= "\t</ConditionDescriptor>\n";
						}
					}
				}

				// Certification Number (Descriptor Name 5) — free text, max 20 chars.
				if ( ! empty( $settings['cert_number'] ) ) {
					$xml .= "\t<ConditionDescriptor>\n";
					$xml .= "\t\t<Name>5</Name>\n";
					$xml .= "\t\t<AdditionalInfo>" . esc_attr( substr( $settings['cert_number'], 0, 20 ) ) . "</AdditionalInfo>\n";
					$xml .= "\t</ConditionDescriptor>\n";
				}
			} else {
				// ---- TCG: Graded ----
				// Professional Grader (required) for TCG.
				if ( ! empty( $settings['grader_id'] ) ) {
					$xml .= "\t<ConditionDescriptor>\n";
					$xml .= "\t\t<Name>27501</Name>\n";
					$xml .= "\t\t<Value>" . esc_attr( $settings['grader_id'] ) . "</Value>\n";
					$xml .= "\t</ConditionDescriptor>\n";
				}
	
				// Grade (required) for TCG.
				if ( ! empty( $settings['grade_value'] ) ) {
					$xml .= "\t<ConditionDescriptor>\n";
					$xml .= "\t\t<Name>27502</Name>\n";
					$xml .= "\t\t<Value>" . esc_attr( $settings['grade_value'] ) . "</Value>\n";
					$xml .= "\t</ConditionDescriptor>\n";
				}
	
				// Certification Number (optional) for TCG.
				if ( ! empty( $settings['cert_number'] ) ) {
					$xml .= "\t<ConditionDescriptor>\n";
					$xml .= "\t\t<Name>27503</Name>\n";
					$xml .= "\t\t<AdditionalInfo>" . esc_attr( $settings['cert_number'] ) . "</AdditionalInfo>\n";
					$xml .= "\t</ConditionDescriptor>\n";
				}
			}
		} elseif ( 'ungraded' === $settings['condition_type'] ) {
			if ( ! empty( $settings['ungraded_condition'] ) ) {
				if ( self::is_coins_category( $settings['category_id'], $item_type ) ) {
					// Coins: Descriptor Name 2 ("Coin Condition").
					$xml .= "\t<ConditionDescriptor>\n";
					$xml .= "\t\t<Name>2</Name>\n";
					$xml .= "\t\t<Value>" . esc_attr( $settings['ungraded_condition'] ) . "</Value>\n";
					$xml .= "\t</ConditionDescriptor>\n";
				} else {
					// TCG: Descriptor Name 40001 ("Card Condition").
					$xml .= "\t<ConditionDescriptor>\n";
					$xml .= "\t\t<Name>40001</Name>\n";
					$xml .= "\t\t<Value>" . esc_attr( $settings['ungraded_condition'] ) . "</Value>\n";
					$xml .= "\t</ConditionDescriptor>\n";
				}
			}
		}

		$xml .= '</ConditionDescriptors>';

		return $xml;
	}

	// -------------------------------------------------------------------------
	// Helper Methods
	// -------------------------------------------------------------------------

	/**
	 * Sanitize a product title to eBay's 80-character limit.
	 *
	 * @param string $title Raw WooCommerce product name.
	 * @return string Sanitized, truncated title.
	 */
	private function sanitize_title( $title ) {
		// eBay disallows some characters in titles.
		$title = preg_replace( '/[<>&"\'!@#*]/', '', $title );
		$title = trim( $title );

		if ( mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Export: Title truncated from %d to %d chars: "%s..."',
				mb_strlen( $title ),
				self::MAX_TITLE_LENGTH,
				mb_substr( $title, 0, 40 )
			), 'warning' );
			$title = mb_substr( $title, 0, self::MAX_TITLE_LENGTH );
		}

		return $title;
	}

	/**
	 * Build the HTML description for an eBay listing.
	 *
	 * Falls back to a simple formatted block if no WC description exists.
	 *
	 * @param WC_Product $product The WooCommerce product.
	 * @return string HTML description string.
	 */
	private function build_description( WC_Product $product ) {
		$desc = $product->get_description();

		if ( empty( trim( $desc ) ) ) {
			$desc = $product->get_short_description();
		}

		if ( empty( trim( $desc ) ) ) {
			// Auto-generate a minimal description from product attributes.
			$attrs = $product->get_attributes();
			if ( ! empty( $attrs ) ) {
				$lines = array();
				foreach ( $attrs as $attr ) {
					$name  = wc_attribute_label( $attr->get_name() );
					$value = implode( ', ', $attr->get_options() );
					if ( $name && $value ) {
						$lines[] = '<strong>' . esc_html( $name ) . ':</strong> ' . esc_html( $value );
					}
				}
				if ( ! empty( $lines ) ) {
					$desc = implode( '<br>', $lines );
				}
			}
		}

		if ( empty( trim( $desc ) ) ) {
			$desc = esc_html( $product->get_name() );
		}

		// Apply WP content filters (shortcodes, etc.) for a clean HTML output.
		return apply_filters( 'the_content', $desc );
	}

	/**
	 * Get all public image URLs for a WooCommerce product.
	 *
	 * Returns the featured image first, then gallery images, up to MAX_IMAGES.
	 *
	 * @param WC_Product $product The WooCommerce product.
	 * @return string[] Array of image URLs.
	 */
	private function get_product_image_urls( WC_Product $product ) {
		$urls = array();

		// Featured image.
		$thumb_id = (int) $product->get_image_id();
		if ( $thumb_id ) {
			$src = wp_get_attachment_image_url( $thumb_id, 'full' );
			if ( $src ) {
				$urls[] = $src;
			}
		}

		// Gallery images.
		$gallery_ids = $product->get_gallery_image_ids();
		foreach ( $gallery_ids as $img_id ) {
			if ( count( $urls ) >= self::MAX_IMAGES ) {
				break;
			}
			$src = wp_get_attachment_image_url( $img_id, 'full' );
			if ( $src ) {
				$urls[] = $src;
			}
		}

		return $urls;
	}

	/**
	 * Get cached business policies, or fetch fresh from eBay.
	 *
	 * Policies are stored permanently in wp_options. They only refresh when
	 * the user explicitly clicks "Fetch eBay Policies" (which calls clear_policy_cache()).
	 *
	 * @param string $type One of 'fulfillment', 'return', 'payment'.
	 * @return array|WP_Error Array of policy objects, or WP_Error on failure.
	 */
	public static function get_policies( $type ) {
		$option_key = 'tcgiant_export_policies_' . $type;
		$cached     = get_option( $option_key, false );

		if ( false !== $cached && is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$api = TCGiant_Sync_API::instance();

		switch ( $type ) {
			case 'fulfillment':
				$response = $api->get_fulfillment_policies();
				$key      = 'fulfillmentPolicies';
				$id_field = 'fulfillmentPolicyId';
				break;
			case 'return':
				$response = $api->get_return_policies();
				$key      = 'returnPolicies';
				$id_field = 'returnPolicyId';
				break;
			case 'payment':
				$response = $api->get_payment_policies();
				$key      = 'paymentPolicies';
				$id_field = 'paymentPolicyId';
				break;
			default:
				return new WP_Error( 'invalid_policy_type', 'Invalid policy type: ' . $type );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$policies = array();
		if ( isset( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
			foreach ( $response[ $key ] as $policy ) {
				$policies[] = array(
					'id'   => $policy[ $id_field ] ?? '',
					'name' => $policy['name'] ?? 'Unknown Policy',
				);
			}
		}

		// Store permanently — only refreshed when user clicks "Fetch eBay Policies".
		update_option( $option_key, $policies, false );

		return $policies;
	}

	/**
	 * Bust the cached business policies (called after user clicks "Refresh Policies").
	 */
	public static function clear_policy_cache() {
		delete_option( 'tcgiant_export_policies_fulfillment' );
		delete_option( 'tcgiant_export_policies_return' );
		delete_option( 'tcgiant_export_policies_payment' );
	}

	// -------------------------------------------------------------------------
	// Completion Check
	// -------------------------------------------------------------------------

	/**
	 * Check if the bulk export is complete and update state accordingly.
	 */
	private function check_export_completion() {
		$state = self::get_export_state();

		if ( ! in_array( $state['status'], array( 'queued', 'pushing' ), true ) ) {
			return;
		}

		self::update_export_state( array( 'status' => 'pushing' ) );

		$completed = $state['total_pushed'] + $state['total_errors'];
		$is_done   = ( $completed >= $state['total_queued'] && $state['total_queued'] > 0 );

		// Fallback: check Action Scheduler for any remaining pending jobs.
		if ( ! $is_done && function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions( array(
				'hook'     => self::PUSH_ACTION,
				'group'    => TCGiant_Sync_Importer::GROUP_SCAN,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			) );
			if ( empty( $pending ) ) {
				$is_done = true;
			}
		}

		if ( $is_done ) {
			self::update_export_state( array(
				'status'        => 'complete',
				'last_completed' => current_time( 'mysql' ),
			) );
			TCGiant_Sync_Logger::log( sprintf(
				'Export complete! %d pushed, %d errors out of %d total.',
				$state['total_pushed'] + 1, // +1 for the current item
				$state['total_errors'],
				$state['total_queued']
			), 'success' );
		}
	}
}
