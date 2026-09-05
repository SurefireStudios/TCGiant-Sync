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
}
