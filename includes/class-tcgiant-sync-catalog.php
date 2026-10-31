<?php
/**
 * The eBay vocabulary: what the settings screens and product panels offer.
 *
 * These lived in the exporter, which was fine while the exporter was in every
 * build. It will not be: the Lite edition imports only and ships no exporter,
 * yet its import settings still offer "eBay Standard Categories to Import" from
 * this list. So the list has to live somewhere every edition has.
 *
 * Everything here is data the interface shows. The exporter keeps what it
 * alone needs to build a listing - grade-alias tables, title and image limits,
 * the descriptor category lists.
 *
 * @package TCGiant_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reference data for eBay listings. All static; nothing to instantiate.
 */
class TCGiant_Sync_Catalog {

	/**
	 * Of the above, the ones eBay only accepts on books, film, music and games.
	 *
	 * Kept separate so the settings field can group them under a heading that
	 * says so. Correcting the labels moved the words "Very Good" off 3000,
	 * which every category accepts, onto 4000, which most refuse - so a shop
	 * that had 3000 saved would open Settings, read "Used" where it used to
	 * say "Very Good", assume something had changed under it, and re-pick the
	 * words it recognised. That swaps a working condition for one eBay throws
	 * out, and nothing in the plugin would have caught it.
	 */
	const CONDITIONS_MEDIA_ONLY = array( '2750', '4000', '5000', '6000' );

	/**
	 * eBay condition IDs, for categories that do not use ConditionDescriptors.
	 *
	 * Three of these were labelled one rung off eBay's own ladder: 3000 was
	 * shown as "Very Good" when eBay calls it Used, 4000 as "Good" when eBay
	 * calls it Very Good, and 5000 as "Acceptable" when eBay calls it Good.
	 * A seller picking "Good" was therefore sending the code eBay publishes as
	 * "Very Good" - overstating the item on a live listing, which is where
	 * returns and disputes come from. Verified against two independent
	 * tables before correcting.
	 *
	 * eBay's exact display name for an ID varies by category - 1000 reads
	 * "Brand New" in one and "New with tags" in another - and the VALID set
	 * varies too. The four media grades below are rejected outright in
	 * categories like Computers/Networking, which is why they are marked.
	 *
	 * DO NOT let the "media only" labels lead you to "correct" build_item_xml()
	 * around the ConditionDescriptor branch. In Trading Card categories eBay
	 * gives two of these IDs entirely different meanings - 2750 is Graded and
	 * 4000 is Ungraded, not Like New and Very Good - which is exactly what that
	 * branch sends, and it is right. The labels here describe what these IDs
	 * mean in the categories this list is OFFERED for, which are the ones that
	 * do not use descriptors. Same numbers, different vocabulary.
	 *
	 * This list is the fallback. get_condition_policies() in the API class
	 * returns what eBay actually permits for a chosen category, and that is
	 * what should populate the field.
	 */
	const CONDITIONS = array(
		'1000' => 'New',
		'1500' => 'New — other (see description)',
		'1750' => 'New — with defects',
		'2000' => 'Refurbished — certified',
		'2500' => 'Refurbished — by seller',
		'3000' => 'Used',
		'7000' => 'For parts or not working',

		// Books, films, music and games only. eBay refuses these elsewhere.
		'2750' => 'Like New',
		'4000' => 'Very Good',
		'5000' => 'Good',
		'6000' => 'Acceptable',
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
	 * Grade label => eBay conditionDescriptorValueId.
	 * Trading Cards — Descriptor Name 27502.
	 *
	 * eBay takes the ID, never the grade as it reads: a PSA 8 sent as "8" is
	 * refused with "Condition descriptor value 8 is not valid for condition
	 * descriptor 27502". From eBay's table of condition descriptor IDs for
	 * trading cards (MIP user guide); the graders' IDs (GRADERS_TCG) match it.
	 */
	const GRADE_IDS_TCG = array(
		'10'                   => '275020',
		'9.5'                  => '275021',
		'9'                    => '275022',
		'8.5'                  => '275023',
		'8'                    => '275024',
		'7.5'                  => '275025',
		'7'                    => '275026',
		'6.5'                  => '275027',
		'6'                    => '275028',
		'5.5'                  => '275029',
		'5'                    => '2750210',
		'4.5'                  => '2750211',
		'4'                    => '2750212',
		'3.5'                  => '2750213',
		'3'                    => '2750214',
		'2.5'                  => '2750215',
		'2'                    => '2750216',
		'1.5'                  => '2750217',
		'1'                    => '2750218',
		'Authentic'            => '2750219',
		'Authentic Altered'    => '2750220',
		'Authentic - Trimmed'  => '2750221',
		'Authentic - Coloured' => '2750222',
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
	 * The categories each ungraded card condition (descriptor 40001) is valid
	 * in, from eBay's table of condition descriptor IDs for trading cards (MIP
	 * user guide): Near Mint or Better in all three; Excellent, Very Good and
	 * Poor in the two trading-card singles categories; the three "Played"
	 * values in CCG Individual Cards only. eBay refuses a value outside its
	 * category, so the exporter sends none and says so.
	 */
	const UNGRADED_TCG_CATEGORIES = array(
		'400010' => array( '183050', '183454', '261328' ),
		'400011' => array( '183050', '261328' ),
		'400012' => array( '183050', '261328' ),
		'400013' => array( '183050', '261328' ),
		'400015' => array( '183454' ),
		'400016' => array( '183454' ),
		'400017' => array( '183454' ),
	);

	/**
	 * The categories each professional grader (descriptor 27501) is valid in,
	 * from the same table of eBay's: most in all three; CSG in Sports Trading
	 * Card Singles only; PCA, PCG, Ace, TCG and ARK in CCG Individual Cards
	 * only. eBay refuses a grader outside its category, so the exporter sends
	 * none and says so. A grader not in the table (GRADERS_TCG's 2750124 to
	 * 2750129) is unknown to it, and goes as it is.
	 */
	const GRADER_TCG_CATEGORIES = array(
		'275010'  => array( '183050', '183454', '261328' ), // PSA
		'275011'  => array( '183050', '183454', '261328' ), // BCCG
		'275012'  => array( '183050', '183454', '261328' ), // BVG
		'275013'  => array( '183050', '183454', '261328' ), // BGS
		'275014'  => array( '261328' ),                     // CSG
		'275015'  => array( '183050', '183454', '261328' ), // CGC
		'275016'  => array( '183050', '183454', '261328' ), // SGC
		'275017'  => array( '183050', '183454', '261328' ), // KSA
		'275018'  => array( '183050', '183454', '261328' ), // GMA
		'275019'  => array( '183050', '183454', '261328' ), // HGA
		'2750110' => array( '183050', '183454', '261328' ), // ISA
		'2750111' => array( '183454' ),                     // PCA
		'2750112' => array( '183050', '183454', '261328' ), // GSG
		'2750113' => array( '183050', '183454', '261328' ), // PGS
		'2750114' => array( '183050', '183454', '261328' ), // MNT
		'2750115' => array( '183050', '183454', '261328' ), // TAG
		'2750116' => array( '183050', '183454', '261328' ), // Rare
		'2750117' => array( '183050', '183454', '261328' ), // RCG
		'2750118' => array( '183454' ),                     // PCG
		'2750119' => array( '183454' ),                     // Ace
		'2750120' => array( '183050', '183454', '261328' ), // CGA
		'2750121' => array( '183454' ),                     // TCG
		'2750122' => array( '183454' ),                     // ARK
		'2750123' => array( '183050', '183454', '261328' ), // Other
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
	 *
	 * eBay US's own leaf categories for single cards, the three its condition
	 * descriptors for trading cards apply to (TCGiant_Sync_Exporter::
	 * DESCRIPTOR_CATEGORIES_TCG). The game goes in the "Game" item specific,
	 * not in the category: eBay files every card game's singles under CCG
	 * Individual Cards. Until 3.28.1 this list offered a category per game
	 * under IDs eBay files elsewhere - "Pokémon Individual Cards" was 180006,
	 * eBay's Desiccant & Moisture Absorbers - see RETIRED_CATEGORIES.
	 */
	const CATEGORIES = array(
		''       => '— Select a default category —',
		'183454' => 'CCG Individual Cards: Pokémon, Magic, Yu-Gi-Oh!, Lorcana, One Piece and other card games',
		'183050' => 'Non-Sport Trading Card Singles',
		'261328' => 'Sports Trading Card Singles',
		'253'    => 'Coins: US',
		'256'    => 'Coins: World',
		'3377'   => 'Coins: Canada',
		'4733'   => 'Coins: Ancient',
		'18466'  => 'Coins: Medieval',
		'custom' => 'Custom Category ID...',
	);

	/**
	 * The coin categories CATEGORIES offers, and eBay US's name for each
	 * (3.28.5). Each is a parent on eBay US, not a leaf: eBay takes none of
	 * them as a listing's category, and files no listing under one. A listing
	 * in a subcategory has the parent's name as the second part of the path
	 * eBay gives with its category: "Coins & Paper Money:Coins: US:Small
	 * Cents:Lincoln (1959-Now)".
	 */
	const COIN_PARENT_CATEGORIES = array(
		'253'   => 'Coins: US',
		'256'   => 'Coins: World',
		'3377'  => 'Coins: Canada',
		'4733'  => 'Coins: Ancient',
		'18466' => 'Coins: Medieval',
	);

	/** eBay US's names for the three single-card categories. */
	const CARD_CATEGORY_NAMES = array(
		'183454' => 'CCG Individual Cards',
		'183050' => 'Non-Sport Trading Card Singles',
		'261328' => 'Sports Trading Card Singles',
	);

	/**
	 * Category IDs this plugin's list offered until 3.28.1 that eBay US files
	 * elsewhere, and the category each was meant to be (ID => ID). A product
	 * or the default saved with one is pushed to the right category, with a
	 * warning; the stored value is left for the shop to change.
	 */
	const RETIRED_CATEGORIES = array(
		'2536'   => '183454', // Collectible Card Games: a parent of CCG Individual Cards, not a leaf.
		'261068' => '183050', // Action Figures.
		'180006' => '183454', // Desiccant & Moisture Absorbers.
		'176055' => '183454', // No category on eBay US.
		'69243'  => '183454', // No category on eBay US.
		'183446' => '183454', // Building Toys.
		'185089' => '183454', // No category on eBay US.
	);

	/** What the list called each retired ID. */
	const RETIRED_CATEGORY_LABELS = array(
		'2536'   => 'Trading Cards',
		'261068' => 'Non-Sport Trading Card Games',
		'180006' => 'Pokémon Individual Cards',
		'176055' => 'Magic: The Gathering Cards',
		'69243'  => 'Yu-Gi-Oh! Individual Cards',
		'183446' => 'Disney Lorcana',
		'185089' => 'One Piece Card Game',
	);

	/**
	 * eBay US's own name for the retired IDs that are real categories. Saved
	 * with the ID as its category name, it says the shop chose that category
	 * from eBay's own tree (Browse eBay Categories, Suggest Category): a
	 * custom choice, which is never mapped.
	 */
	const RETIRED_CATEGORY_EBAY_NAMES = array(
		'2536'   => 'Collectible Card Games',
		'261068' => 'Action Figures',
		'180006' => 'Desiccant & Moisture Absorbers',
		'183446' => 'Building Toys',
	);

	/**
	 * The category a retired ID is pushed to, or '' when the ID is not one,
	 * or is the shop's own: typed into a custom category box (the ID saved
	 * as typed with it, 3.28.1), typed into Settings' custom categories, or
	 * chosen from eBay's own tree (its eBay name saved with it, as the name
	 * or the end of the path the category browser saves).
	 *
	 * @param string $category_id    Stored category ID.
	 * @param string $category_name  The category name stored with it.
	 * @param string $category_typed The ID last saved from the custom box, if any.
	 * @return string
	 */
	public static function retired_category( $category_id, $category_name = '', $category_typed = '' ) {
		$category_id = trim( (string) $category_id );
		if ( ! isset( self::RETIRED_CATEGORIES[ $category_id ] ) ) {
			return '';
		}

		// Typed into the custom box: the shop's own, never mapped. Only while
		// it is the ID saved; one chosen since from the list is not typed.
		if ( trim( (string) $category_typed ) === $category_id ) {
			return '';
		}

		// The IDs, and where they go, are eBay US's: another site's shop keeps
		// what it saved, as a category of its own.
		if ( ! self::maps_retired_categories() ) {
			return '';
		}

		// In the list only as one of the shop's custom categories.
		if ( isset( self::get_categories()[ $category_id ] ) ) {
			return '';
		}

		$ebay_name = self::RETIRED_CATEGORY_EBAY_NAMES[ $category_id ] ?? '';
		$name      = strtolower( trim( (string) $category_name ) );
		if ( '' !== $ebay_name && '' !== $name && strtolower( $ebay_name ) === substr( $name, -strlen( $ebay_name ) ) ) {
			return '';
		}

		return self::RETIRED_CATEGORIES[ $category_id ];
	}

	/**
	 * Whether this shop's retired IDs are mapped: only when its Primary eBay
	 * Marketplace is eBay US, or unset, which every call reads as eBay US
	 * (TCGiant_Sync_API). RETIRED_CATEGORIES is eBay US's tree.
	 *
	 * @return bool
	 */
	public static function maps_retired_categories() {
		$settings    = TCGiant_Sync_OAuth::instance()->get_settings();
		$marketplace = is_array( $settings ) && is_string( $settings['marketplace'] ?? null ) ? $settings['marketplace'] : '';

		return '' === $marketplace || 'EBAY_US' === $marketplace;
	}

	/**
	 * The eBay sites whose single-card leaf categories are the three eBay
	 * US's (183454, 183050, 261328), as checked on each site (3.28.5).
	 */
	const CARD_LEAF_SITES = array( 'EBAY_US', 'EBAY_GB', 'EBAY_AU', 'EBAY_CA' );

	/**
	 * Whether this shop's Primary eBay Marketplace is one of CARD_LEAF_SITES,
	 * or unset, which every call reads as eBay US.
	 *
	 * @return bool
	 */
	public static function card_leaves_verified() {
		$settings    = TCGiant_Sync_OAuth::instance()->get_settings();
		$marketplace = is_array( $settings ) && is_string( $settings['marketplace'] ?? null ) ? $settings['marketplace'] : '';

		return '' === $marketplace || in_array( $marketplace, self::CARD_LEAF_SITES, true );
	}

	/**
	 * What the warnings for a retired ID add where the category it goes to
	 * may not be what the shop meant (3.28.5); '' for the others. "Trading
	 * Cards" (2536) covered sports cards too, and goes to CCG Individual
	 * Cards.
	 *
	 * @param string $category_id A retired ID.
	 * @return string
	 */
	public static function retired_category_note( $category_id ) {
		if ( '2536' !== trim( (string) $category_id ) ) {
			return '';
		}

		return __( 'Sports cards belong in Sports Trading Card Singles (261328), the only one of the three single-card categories that takes CSG as a grader: choose that one for them.', 'tcgiant-sync' );
	}

	/**
	 * Why a category cannot be a listing's (3.28.5), when it is one of the
	 * coin parents (COIN_PARENT_CATEGORIES), for a shop on eBay US or with no
	 * marketplace saved, as maps_retired_categories() asks: they are eBay
	 * US's IDs. '' for any other. The push, Verify, Bulk Verify and the
	 * product's eBay tab say it in these words.
	 *
	 * @param string $category_id Category ID.
	 * @return string
	 */
	public static function coin_parent_problem( $category_id ) {
		$category_id = trim( (string) $category_id );
		if ( ! isset( self::COIN_PARENT_CATEGORIES[ $category_id ] ) || ! self::maps_retired_categories() ) {
			return '';
		}

		return sprintf(
			/* translators: 1: category name, 2: its ID */
			__( '%1$s (%2$s) is a parent category on eBay, which takes only a bottom-level one: choose a subcategory of it with Browse eBay Categories', 'tcgiant-sync' ),
			self::COIN_PARENT_CATEGORIES[ $category_id ],
			$category_id
		);
	}

	/**
	 * The coin parent a listing's category path puts it under: its ID, or ''.
	 *
	 * @param mixed $path PrimaryCategory.CategoryName as eBay sends it.
	 * @return string
	 */
	public static function coin_parent_in_path( $path ) {
		if ( ! is_string( $path ) ) {
			return '';
		}

		foreach ( self::COIN_PARENT_CATEGORIES as $id => $name ) {
			if ( 0 === stripos( $path, 'Coins & Paper Money:' . $name . ':' ) ) {
				return (string) $id;
			}
		}

		return '';
	}

	/**
	 * How a stored retired ID is shown: "(retired) Pokémon Individual Cards
	 * (180006) → CCG Individual Cards (183454)".
	 *
	 * @param string $category_id A key of RETIRED_CATEGORIES.
	 * @return string
	 */
	public static function retired_category_label( $category_id ) {
		$category_id = (string) $category_id;
		$to          = self::RETIRED_CATEGORIES[ $category_id ] ?? '';

		return sprintf(
			/* translators: 1: the old label, 2: its ID, 3: the category it is pushed to, 4: that category's ID */
			__( '(retired) %1$s (%2$s) → %3$s (%4$s)', 'tcgiant-sync' ),
			self::RETIRED_CATEGORY_LABELS[ $category_id ] ?? $category_id,
			$category_id,
			self::CARD_CATEGORY_NAMES[ $to ] ?? $to,
			$to
		);
	}

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
	);

	/*
	 * 60 and 90 Days used to be listed here and were offered on the Settings
	 * page. eBay accepts neither for anything this plugin creates - they are
	 * classified-ad and vehicle values - so every push that used one was
	 * refused, and the seller was told their own choice was invalid after
	 * being offered it. A value nobody can use is not worth a label.
	 */

	/**
	 * Valid durations per listing type (eBay rules).
	 */
	const DURATIONS_BY_TYPE = array(
		/*
		 * One value, because eBay honours one.
		 *
		 * Fixed-duration fixed-price listings ended in March 2019; eBay's
		 * Trading API guide now says Good 'Til Cancelled is the only supported
		 * duration for fixed price on every marketplace, vehicles aside. We went
		 * on offering 30 Days and sending it, and eBay quietly made it GTC -
		 * so sellers were choosing between two options that did the same thing,
		 * and the one they picked was not the one they got.
		 */
		'FixedPriceItem' => array( 'GTC' ),
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
}
