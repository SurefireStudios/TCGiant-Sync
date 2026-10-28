<?php
/**
 * eBay listing templates: what they are, where they apply, and what they send.
 *
 * Nothing changes for a shop until it picks a template. With none chosen -
 * no default, no category rule, no product set to one, which is every shop at
 * upgrade - a push sends exactly the description 3.27.7 sent
 * (TCGiant_Sync_Exporter::build_description(), held byte for byte by
 * tests/test-template-migration.php).
 *
 * A template is a private post of type tcgiant_tpl, with its body in post
 * meta rather than post_content: WordPress runs post_content through kses on
 * save for anyone without unfiltered_html, which strips <style>. The body is
 * stored as typed and only ever printed escaped, or after the sanitiser.
 *
 * - _tpl_html, _tpl_css: the body, with placeholders, and one stylesheet;
 * - _tpl_version: up by one on every save; _tpl_versions the last ten saves;
 * - _tpl_lang: en, de, fr, it or es; _tpl_content_filters: core or all;
 * - _tpl_design: the design-settings form - colours, logo, banner, parts shown;
 * - _tpl_hash: sha1 of everything above that shapes the output, and the
 *   versions of the blocks it uses.
 *
 * Text blocks are posts of type tcgiant_tpl_block (_blk_kind, _blk_html per
 * language, _blk_version). The two policy blocks, {{{policy.returns}}} and
 * {{{policy.handling}}}, are rendered from the shop's Business Policies, not
 * typed.
 *
 * The settings live in their own option, tcgiant_sync_template_settings, with
 * their own form. Both forms that save tcgiant_sync_ebay_settings rewrite the
 * whole option and carry other keys through hidden "preserve" lists, so a key
 * left off a list is wiped - which is how 3.27.4 lost Best Offer and GPSR on an
 * Import Settings save. A separate option cannot be lost that way.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Templates class.
 */
class TCGiant_Sync_Templates {

	/** Templates. */
	const POST_TYPE = 'tcgiant_tpl';

	/** Text blocks. */
	const BLOCK_TYPE = 'tcgiant_tpl_block';

	/** The templates' own settings. */
	const SETTINGS_OPTION = 'tcgiant_sync_template_settings';

	/** Which starters have been installed, so each is installed once. */
	const STARTERS_OPTION = 'tcgiant_sync_template_starters';

	/** What an exported template file says it is. */
	const FORMAT = 'tcgiant-template/1';

	/** Saves kept for Restore. */
	const MAX_VERSIONS = 10;

	/** The languages a template can be written in. */
	const LANGS = array( 'en', 'de', 'fr', 'it', 'es' );

	/**
	 * The main language of each marketplace's site, which eBay requires a
	 * description to be written in
	 * (https://www.ebay.com/help/policies/listing-policies/item-description-policy?id=4372).
	 */
	const MARKETPLACE_LANG = array(
		'EBAY_US' => 'en',
		'EBAY_GB' => 'en',
		'EBAY_CA' => 'en',
		'EBAY_AU' => 'en',
		'EBAY_DE' => 'de',
		'EBAY_FR' => 'fr',
		'EBAY_IT' => 'it',
		'EBAY_ES' => 'es',
	);

	/** A product's own choice: '' follows the rules, 'none', or a template ID. */
	const OVERRIDE_META = '_tcgiant_tpl_override';

	/** An eBay-only description, sent in place of the website's. */
	const EBAY_DESCRIPTION_META = '_tcgiant_ebay_description';

	/**
	 * What a product records once eBay has accepted a send, and only then:
	 * Verify builds the same item, and a listing recorded with a description
	 * eBay never received would mislead 3.28.1's re-send.
	 */
	const SENT_META = array( '_tcgiant_tpl_id', '_tcgiant_tpl_ver', '_tcgiant_tpl_hash_sent', '_tcgiant_content_hash', '_tcgiant_desc_hash', '_tcgiant_region_hash', '_tcgiant_tpl_item', '_tcgiant_desc_sent_at' );

	/**
	 * Forget what a product records of its last send: it is linked to a
	 * listing that send did not make (a re-link by SKU, the Link box).
	 *
	 * @param int $product_id Product.
	 */
	public static function forget_sent( $product_id ) {
		foreach ( self::SENT_META as $key ) {
			delete_post_meta( (int) $product_id, $key );
		}
	}

	/**
	 * Placeholders {{{x}}} may write unescaped: each is built from markup the
	 * pipeline cleans before it goes out.
	 */
	const RAW = array( 'description', 'short_description', 'specifics_table', 'variations_table', 'gallery', 'block.*', 'policy.*' );

	/** How many images {{image.N}} reaches, as WP-Lister's [[img_N]] does in practice. */
	const MAX_IMAGE_N = 24;

	/** Images in {{{gallery}}}. */
	const GALLERY_IMAGES = 6;

	/** The parts the design form can show or hide. The gallery is off by default. */
	const PARTS = array(
		'header'            => true,
		'short_description' => true,
		'grading'           => true,
		'specifics'         => true,
		'returns'           => true,
		'handling'          => true,
		'gallery'           => false,
	);

	/** The design form's colours, as a starter ships them. */
	const DESIGN_COLOURS = array(
		'accent'      => '#1f4e79',
		'accent_text' => '#ffffff',
		'background'  => '#ffffff',
	);

	/** A body larger than this can never be sent, so it is never kept. */
	const MAX_BODY_CHARS = 500000;

	/** Template files above this size are refused before they are read. */
	const MAX_IMPORT_BYTES = 2097152;

	/** Check with eBay presses today, by UTC day as the call counter goes. */
	const VERIFY_TRANSIENT = 'tcgiant_tpl_verify_';

	/**
	 * Instance.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * Templates already reported missing in this request, so a bulk push of a
	 * thousand products says it once.
	 *
	 * @var array<int, bool>
	 */
	private static $missing_said = array();

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
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_types' ) );

		// The five starters, once, as drafts with nothing assigned.
		if ( class_exists( 'TCGiant_Sync_Template_Starters' ) ) {
			add_action( 'admin_init', array( 'TCGiant_Sync_Template_Starters', 'maybe_install' ) );
		}
	}

	/**
	 * The two private post types. No screens of WordPress's own: the plugin's
	 * Listing Templates page is the only way in, behind manage_options.
	 */
	public static function register_types() {
		$private = array(
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,
			'supports'            => array( 'title' ),
		);

		register_post_type( self::POST_TYPE, $private + array( 'label' => __( 'eBay listing templates', 'tcgiant-sync' ) ) );
		register_post_type( self::BLOCK_TYPE, $private + array( 'label' => __( 'eBay listing template blocks', 'tcgiant-sync' ) ) );
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	/**
	 * The defaults: None, no rules, nothing allowlisted.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'default_template_id' => 0,
			'category_map'        => array(),
			'meta_allowlist'      => array(),
			'verify_daily_cap'    => 20,
			'shop_name'           => '',
			'logo_id'             => 0,
			'ebay_store_url'      => '',
		);
	}

	/**
	 * The settings, with defaults for anything unsaved.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::SETTINGS_OPTION, array() );
		return array_merge( self::default_settings(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Clean what the settings form posted. Only these keys are ever kept.
	 *
	 * @param array    $input Posted values, unslashed.
	 * @param string[] $said  Filled with what the form should be told: a meta
	 *                        key refused, or one that is private.
	 * @return array
	 */
	public static function sanitize_settings( array $input, array &$said = array() ) {
		$out = self::default_settings();

		$default = absint( $input['default_template_id'] ?? 0 );
		$out['default_template_id'] = ( $default && self::is_usable( $default ) ) ? $default : 0;

		$map = array();
		foreach ( (array) ( $input['category_map'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$term_id = absint( $row['term_id'] ?? 0 );
			$tpl_id  = absint( $row['template_id'] ?? 0 );
			if ( ! $term_id || ! $tpl_id || ! self::is_usable( $tpl_id ) ) {
				continue;
			}
			if ( function_exists( 'term_exists' ) && ! term_exists( $term_id, 'product_cat' ) ) {
				continue;
			}
			$map[ $term_id ] = array(
				'term_id'     => $term_id,
				'template_id' => $tpl_id,
				'priority'    => max( 1, min( 99, (int) ( $row['priority'] ?? 10 ) ) ),
			);
		}
		$out['category_map'] = array_values( $map );

		// One key a line, or between commas: a space is part of what was
		// typed, so "bad key!" is refused whole rather than kept as "bad".
		$keys = $input['meta_allowlist'] ?? array();
		if ( is_string( $keys ) ) {
			$keys = preg_split( '/[\r\n,]+/', $keys );
		}
		$allow = array();
		foreach ( (array) $keys as $key ) {
			$key = trim( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( ! preg_match( '/^[A-Za-z0-9_\-:.]{1,64}$/', $key ) ) {
				/* translators: %s: what was typed */
				$said[] = sprintf( __( '"%s" is not a meta key (letters, numbers, _ - : and . only), so it was not added to the allowlist.', 'tcgiant-sync' ), $key );
				continue;
			}
			if ( '_' === $key[0] ) {
				/* translators: %s: a meta key */
				$said[] = sprintf( __( '%s is a private key: WordPress and plugins keep figures such as the cost of goods under keys that start with "_". Make sure it is safe to show buyers.', 'tcgiant-sync' ), $key );
			}
			$allow[ $key ] = true;
		}
		$out['meta_allowlist'] = array_keys( $allow );

		// An empty box keeps the cap as it is, rather than reading as 0,
		// which turns Check with eBay off.
		$checks = trim( (string) ( $input['verify_daily_cap'] ?? '' ) );
		$out['verify_daily_cap'] = '' === $checks || ! is_numeric( $checks )
			? (int) self::settings()['verify_daily_cap']
			: max( 0, min( 200, (int) $checks ) );
		if ( is_numeric( $checks ) && (string) $out['verify_daily_cap'] !== (string) (int) $checks ) {
			/* translators: 1: what was typed, 2: what it was set to */
			$said[] = sprintf( __( 'Check with eBay\'s daily limit goes from 0 to 200, so %1$s was set to %2$d.', 'tcgiant-sync' ), $checks, $out['verify_daily_cap'] );
		}
		$out['shop_name']        = mb_substr( sanitize_text_field( (string) ( $input['shop_name'] ?? '' ) ), 0, 80 );

		// Kept while it is an image, https or not: on a site not served over
		// https it is left out when sent, and the screens say why.
		$logo           = absint( $input['logo_id'] ?? 0 );
		$out['logo_id'] = ( $logo && function_exists( 'wp_attachment_is_image' ) && wp_attachment_is_image( $logo ) ) ? $logo : 0;

		$out['ebay_store_url'] = TCGiant_Sync_Template_Sanitiser::ebay_link( (string) ( $input['ebay_store_url'] ?? '' ) );

		return $out;
	}

	/**
	 * The language of the shop's eBay site.
	 *
	 * @return string
	 */
	public static function marketplace_lang() {
		$config = TCGiant_Sync_API::instance()->get_marketplace_config();
		return self::MARKETPLACE_LANG[ $config['marketplace_id'] ?? 'EBAY_US' ] ?? 'en';
	}

	// -------------------------------------------------------------------------
	// Templates
	// -------------------------------------------------------------------------

	/**
	 * A template as stored, or null.
	 *
	 * @param int $id Template ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$id   = (int) $id;
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}

		$versions = get_post_meta( $id, '_tpl_versions', true );

		return array(
			'id'              => $id,
			'name'            => (string) $post->post_title,
			'status'          => (string) $post->post_status,
			'html'            => (string) get_post_meta( $id, '_tpl_html', true ),
			'css'             => (string) get_post_meta( $id, '_tpl_css', true ),
			'lang'            => self::lang( get_post_meta( $id, '_tpl_lang', true ) ),
			'content_filters' => 'all' === get_post_meta( $id, '_tpl_content_filters', true ) ? 'all' : 'core',
			'design'          => self::sanitize_design( get_post_meta( $id, '_tpl_design', true ) ),
			'version'         => (int) get_post_meta( $id, '_tpl_version', true ),
			'versions'        => is_array( $versions ) ? $versions : array(),
			'hash'            => (string) get_post_meta( $id, '_tpl_hash', true ),
			'modified'        => (string) $post->post_modified,
		);
	}

	/**
	 * Whether a template can be used for a listing: it exists and is published.
	 *
	 * @param int $id Template ID.
	 * @return bool
	 */
	public static function is_usable( $id ) {
		$id   = (int) $id;
		$post = $id > 0 ? get_post( $id ) : null;
		return $post && self::POST_TYPE === $post->post_type && 'publish' === $post->post_status;
	}

	/**
	 * Every template, newest first.
	 *
	 * @param string $status 'publish', 'draft' or 'any'.
	 * @return array[] Each as get() returns it.
	 */
	public static function all( $status = 'any' ) {
		$ids = get_posts( array(
			'post_type'        => self::POST_TYPE,
			'post_status'      => 'any' === $status ? array( 'publish', 'draft' ) : $status,
			'numberposts'      => 200,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'suppress_filters' => true,
		) );

		$out = array();
		foreach ( (array) $ids as $id ) {
			$tpl = self::get( (int) $id );
			if ( $tpl ) {
				$out[] = $tpl;
			}
		}
		return $out;
	}

	/**
	 * The one save path: the editor, Restore, Import JSON, the WP-Lister
	 * converter and the starters all come through here.
	 *
	 * The body is kept as typed. A <style> typed into the HTML is moved to the
	 * stylesheet, which is where the pipeline expects it; everything else is
	 * cleaned each time the template is rendered, so a fix to the sanitiser
	 * reaches every template without anyone saving it again.
	 *
	 * @param int   $id      Template ID, or 0 for a new one.
	 * @param array $data    name, status, html, css, lang, content_filters, design.
	 * @param int   $user_id Who saved it.
	 * @return int|WP_Error The template's ID.
	 */
	public static function save( $id, array $data, $user_id = 0 ) {
		$id      = (int) $id;
		$current = $id ? self::get( $id ) : null;
		if ( $id && ! $current ) {
			return new WP_Error( 'no_template', __( 'That template no longer exists.', 'tcgiant-sync' ) );
		}

		$html = (string) ( $data['html'] ?? ( $current['html'] ?? '' ) );
		$css  = (string) ( $data['css'] ?? ( $current['css'] ?? '' ) );

		// A <style> typed into the HTML pane belongs in the CSS pane.
		if ( preg_match_all( '#<style\b[^>]*>(.*?)</style\s*>#is', $html, $styles ) ) {
			$html = (string) preg_replace( '#<style\b[^>]*>.*?</style\s*>#is', '', $html );
			$css  = trim( $css . "\n" . implode( "\n", array_map( 'trim', $styles[1] ) ) );
		}

		$chars = mb_strlen( $html . $css, 'UTF-8' );
		if ( $chars > self::MAX_BODY_CHARS ) {
			/* translators: 1: characters, 2: the limit */
			return new WP_Error( 'template_too_long', sprintf( __( 'This template is %1$s characters long. eBay takes descriptions of up to %2$s, so it could never be sent.', 'tcgiant-sync' ), number_format_i18n( $chars ), number_format_i18n( self::MAX_BODY_CHARS ) ) );
		}

		$name = trim( sanitize_text_field( (string) ( $data['name'] ?? ( $current['name'] ?? '' ) ) ) );
		if ( '' === $name ) {
			$name = __( 'Untitled template', 'tcgiant-sync' );
		}
		// One name, one template: the lists and the products' choices name
		// them, and two alike could not be told apart. A second gets " (2)".
		$taken = array();
		foreach ( self::all() as $other ) {
			if ( (int) $other['id'] !== (int) $id ) {
				$taken[ mb_strtolower( $other['name'], 'UTF-8' ) ] = true;
			}
		}
		if ( isset( $taken[ mb_strtolower( $name, 'UTF-8' ) ] ) ) {
			// "Clean (2)" saved again as a copy is "Clean (3)", never "Clean (2) (2)".
			$base = (string) preg_replace( '/ \(\d+\)$/', '', $name );
			for ( $n = 2; isset( $taken[ mb_strtolower( $name, 'UTF-8' ) ] ); $n++ ) {
				$name = sprintf( '%s (%d)', $base, $n );
			}
		}
		$status = in_array( $data['status'] ?? ( $current['status'] ?? 'draft' ), array( 'publish', 'draft' ), true ) ? ( $data['status'] ?? ( $current['status'] ?? 'draft' ) ) : 'draft';

		// A template something is set to stays published: a draft would send
		// those listings the plain description, unannounced.
		if ( $current && 'publish' === $current['status'] && 'draft' === $status && self::assigned( $id ) ) {
			$status = 'publish';
		}

		$post = array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => $name,
			'post_status'  => $status,
			'post_content' => '',
		);
		if ( $id ) {
			$post['ID'] = $id;
			$result     = wp_update_post( wp_slash( $post ), true );
		} else {
			$post['post_author'] = (int) $user_id;
			$result              = wp_insert_post( wp_slash( $post ), true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id = (int) $result;

		$lang    = self::lang( $data['lang'] ?? ( $current['lang'] ?? 'en' ) );
		$filters = 'all' === ( $data['content_filters'] ?? ( $current['content_filters'] ?? 'core' ) ) ? 'all' : 'core';
		$design  = self::sanitize_design( $data['design'] ?? ( $current['design'] ?? array() ) );

		$version  = $current ? $current['version'] + 1 : 1;
		$versions = $current ? $current['versions'] : array();
		if ( $current ) {
			$versions[] = array(
				'version'         => $current['version'],
				'html'            => $current['html'],
				'css'             => $current['css'],
				'lang'            => $current['lang'],
				'content_filters' => $current['content_filters'],
				'design'          => $current['design'],
				'saved_at'        => $current['modified'],
				'user_id'         => (int) get_post_meta( $id, '_tpl_saved_by', true ),
			);
			$versions = array_slice( $versions, -self::MAX_VERSIONS );
		}

		update_post_meta( $id, '_tpl_html', wp_slash( $html ) );
		update_post_meta( $id, '_tpl_css', wp_slash( $css ) );
		update_post_meta( $id, '_tpl_lang', $lang );
		update_post_meta( $id, '_tpl_content_filters', $filters );
		update_post_meta( $id, '_tpl_design', wp_slash( $design ) );
		update_post_meta( $id, '_tpl_version', $version );
		update_post_meta( $id, '_tpl_saved_by', (int) $user_id );

		// Not autoloaded: post meta never is, and ten bodies are not small.
		update_post_meta( $id, '_tpl_versions', wp_slash( $versions ) );

		update_post_meta( $id, '_tpl_hash', self::template_hash( array(
			'html'            => $html,
			'css'             => $css,
			'lang'            => $lang,
			'content_filters' => $filters,
			'design'          => $design,
		) ) );

		return $id;
	}

	/**
	 * Put an earlier save back, as a new version of its own.
	 *
	 * @param int $id      Template ID.
	 * @param int $version The version to bring back.
	 * @param int $user_id Who asked.
	 * @return int|WP_Error
	 */
	public static function restore( $id, $version, $user_id = 0 ) {
		$tpl = self::get( $id );
		if ( ! $tpl ) {
			return new WP_Error( 'no_template', __( 'That template no longer exists.', 'tcgiant-sync' ) );
		}
		foreach ( $tpl['versions'] as $old ) {
			if ( (int) ( $old['version'] ?? 0 ) === (int) $version ) {
				return self::save( $id, array(
					'html'            => (string) ( $old['html'] ?? '' ),
					'css'             => (string) ( $old['css'] ?? '' ),
					'lang'            => (string) ( $old['lang'] ?? $tpl['lang'] ),
					'content_filters' => (string) ( $old['content_filters'] ?? $tpl['content_filters'] ),
					'design'          => is_array( $old['design'] ?? null ) ? $old['design'] : $tpl['design'],
				), $user_id );
			}
		}
		return new WP_Error( 'no_version', __( 'That version is no longer kept.', 'tcgiant-sync' ) );
	}

	/**
	 * What the template is set to: the default, category rules, products.
	 *
	 * @param int $id Template ID.
	 * @return array{default: bool, categories: int, products: int}
	 */
	public static function assignments( $id ) {
		global $wpdb;

		$id       = (int) $id;
		$settings = self::settings();
		$cats     = 0;
		foreach ( (array) $settings['category_map'] as $row ) {
			if ( (int) ( $row['template_id'] ?? 0 ) === $id ) {
				$cats++;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$products = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
			self::OVERRIDE_META,
			(string) $id
		) );

		return array(
			'default'    => (int) $settings['default_template_id'] === $id,
			'categories' => $cats,
			'products'   => $products,
		);
	}

	/**
	 * How many products were last sent to eBay with the template.
	 *
	 * @param int $id Template ID.
	 * @return int
	 */
	public static function sent_count( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
			'_tcgiant_tpl_id',
			(string) (int) $id
		) );
	}

	/**
	 * Whether anything is set to the template.
	 *
	 * @param int $id Template ID.
	 * @return bool
	 */
	public static function assigned( $id ) {
		$a = self::assignments( $id );
		return $a['default'] || $a['categories'] > 0 || $a['products'] > 0;
	}

	/**
	 * Delete a template, unless something is set to it.
	 *
	 * @param int $id Template ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		if ( ! self::get( $id ) ) {
			return new WP_Error( 'no_template', __( 'That template no longer exists.', 'tcgiant-sync' ) );
		}
		if ( self::assigned( $id ) ) {
			return new WP_Error( 'assigned', __( 'This template is in use: it is the default, a category rule or a product is set to it. Change those first, then delete it.', 'tcgiant-sync' ) );
		}
		wp_delete_post( (int) $id, true );
		return true;
	}

	/**
	 * A copy of a template, as a draft.
	 *
	 * @param int $id      Template ID.
	 * @param int $user_id Who asked.
	 * @return int|WP_Error
	 */
	public static function duplicate( $id, $user_id = 0 ) {
		$tpl = self::get( $id );
		if ( ! $tpl ) {
			return new WP_Error( 'no_template', __( 'That template no longer exists.', 'tcgiant-sync' ) );
		}
		$copy = array_intersect_key( $tpl, array_flip( array( 'html', 'css', 'lang', 'content_filters', 'design' ) ) );
		/* translators: %s: template name */
		$copy['name']   = sprintf( __( '%s (copy)', 'tcgiant-sync' ), $tpl['name'] );
		$copy['status'] = 'draft';
		return self::save( 0, $copy, $user_id );
	}

	/**
	 * The hash of what shapes a template's output, with the blocks it uses.
	 *
	 * @param array $tpl As get() returns it.
	 * @return string
	 */
	public static function template_hash( array $tpl ) {
		$blocks = array();
		foreach ( TCGiant_Sync_Template_Renderer::names( (string) ( $tpl['html'] ?? '' ) ) as $name ) {
			if ( 0 === strpos( $name, 'block.' ) ) {
				$block                   = self::get_block( substr( $name, 6 ) );
				$blocks[ substr( $name, 6 ) ] = $block ? $block['version'] : 0;
			}
		}
		ksort( $blocks );

		return sha1( wp_json_encode( array(
			(string) ( $tpl['html'] ?? '' ),
			(string) ( $tpl['css'] ?? '' ),
			(string) ( $tpl['lang'] ?? 'en' ),
			(string) ( $tpl['content_filters'] ?? 'core' ),
			self::sanitize_design( $tpl['design'] ?? array() ),
			$blocks,
		) ) );
	}

	/**
	 * One of the five languages, or English.
	 *
	 * @param mixed $lang Language code.
	 * @return string
	 */
	private static function lang( $lang ) {
		$lang = strtolower( trim( (string) $lang ) );
		return in_array( $lang, self::LANGS, true ) ? $lang : 'en';
	}

	// -------------------------------------------------------------------------
	// The design-settings form
	// -------------------------------------------------------------------------

	/**
	 * Clean the design settings. Nothing typed here can reach the markup or the
	 * stylesheet as anything but a colour, an attachment of this site's own, or
	 * on/off: colours must be #rrggbb, images are attachment IDs whose address
	 * is https, and the parts are booleans.
	 *
	 * @param mixed $design Stored or posted design settings.
	 * @return array
	 */
	public static function sanitize_design( $design ) {
		$design = is_array( $design ) ? $design : array();
		$out    = array();

		foreach ( self::DESIGN_COLOURS as $key => $default ) {
			$out[ $key ] = self::colour( $design[ $key ] ?? '' ) ?: $default;
		}

		foreach ( array( 'logo_id', 'banner_id' ) as $key ) {
			$out[ $key ] = absint( $design[ $key ] ?? 0 );
		}

		$show        = is_array( $design['show'] ?? null ) ? $design['show'] : array();
		$out['show'] = array();
		foreach ( self::PARTS as $part => $default ) {
			$out['show'][ $part ] = array_key_exists( $part, $show ) ? ! empty( $show[ $part ] ) : $default;
		}

		return $out;
	}

	/**
	 * A colour as #rrggbb, or '' when it is not one.
	 *
	 * @param mixed $value Typed value.
	 * @return string
	 */
	public static function colour( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? strtolower( $value ) : '';
	}

	/**
	 * An attachment's full-size address, when it is an image of this site's
	 * served over https; '' otherwise.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function image_url( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || ! function_exists( 'wp_attachment_is_image' ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return '';
		}
		$url = (string) wp_get_attachment_image_url( $attachment_id, 'full' );
		if ( 0 === strpos( $url, 'http://' ) && self::site_https() ) {
			$url = 'https://' . substr( $url, 7 );
		}
		return 0 === strpos( $url, 'https://' ) ? $url : '';
	}

	/**
	 * The template's {{design.x}} and {{show.x}} values: the form's own, and
	 * colours worked out from the background so a dark page gets light text
	 * without anyone choosing one.
	 *
	 * @param array $design   Clean design settings.
	 * @param array $settings Template settings, for the shop logo.
	 * @return array Placeholder name => value.
	 */
	public static function design_values( array $design, array $settings ) {
		$dark = self::is_dark( $design['background'] );
		$logo = self::image_url( $design['logo_id'] );
		if ( '' === $logo ) {
			$logo = self::image_url( (int) ( $settings['logo_id'] ?? 0 ) );
		}

		$values = array(
			'design.accent'      => $design['accent'],
			'design.accent_text' => $design['accent_text'],
			'design.background'  => $design['background'],
			'design.text'        => $dark ? '#f2f2f2' : '#222222',
			'design.muted'       => $dark ? '#b8b8b8' : '#5f6368',
			'design.border'      => $dark ? '#3a3a3a' : '#dddddd',
			'design.panel'       => $dark ? '#262626' : '#f6f7f8',
			'design.logo_url'    => $logo,
			'design.banner_url'  => self::image_url( $design['banner_id'] ),
		);

		foreach ( $design['show'] as $part => $on ) {
			$values[ 'show.' . $part ] = (bool) $on;
		}

		return $values;
	}

	/**
	 * Whether a #rrggbb colour is dark, by its relative luminance.
	 *
	 * @param string $hex Colour.
	 * @return bool
	 */
	private static function is_dark( $hex ) {
		$hex = ltrim( $hex, '#' );
		$rgb = array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
		$lum = ( 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2] ) / 255;
		return $lum < 0.5;
	}

	/**
	 * An image's address for the admin's own screens, served over https or
	 * not: the form shows the picture chosen even where a listing leaves it
	 * out (see image_url()).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function admin_image_url( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || ! function_exists( 'wp_attachment_is_image' ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return '';
		}
		return (string) wp_get_attachment_image_url( $attachment_id, 'medium' );
	}

	/**
	 * What the screens say on a site that is not served over https.
	 *
	 * @return string
	 */
	public static function not_https_warning() {
		return __( 'Your site is not served over https, so the logo, the banner and the product photos are left out of the description: eBay takes only pictures served over https.', 'tcgiant-sync' );
	}

	/**
	 * Whether this site serves https.
	 *
	 * @return bool
	 */
	public static function site_https() {
		return 'https' === strtolower( (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) );
	}

	// -------------------------------------------------------------------------
	// Blocks
	// -------------------------------------------------------------------------

	/**
	 * A text block by slug, or null.
	 *
	 * @param string $slug Slug.
	 * @return array|null id, slug, label, kind, html (lang => text), version.
	 */
	public static function get_block( $slug ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			return null;
		}
		$found = get_posts( array(
			'post_type'        => self::BLOCK_TYPE,
			'name'             => $slug,
			'post_status'      => 'publish',
			'numberposts'      => 1,
			'suppress_filters' => true,
		) );
		if ( empty( $found ) ) {
			return null;
		}
		return self::block_row( $found[0] );
	}

	/**
	 * Every text block.
	 *
	 * @return array[]
	 */
	public static function blocks() {
		$out = array();
		foreach ( (array) get_posts( array(
			'post_type'        => self::BLOCK_TYPE,
			'post_status'      => 'publish',
			'numberposts'      => 200,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => true,
		) ) as $post ) {
			$out[] = self::block_row( $post );
		}
		return $out;
	}

	/**
	 * A block post as a row.
	 *
	 * @param WP_Post $post Block post.
	 * @return array
	 */
	private static function block_row( $post ) {
		$html = get_post_meta( $post->ID, '_blk_html', true );
		return array(
			'id'      => (int) $post->ID,
			'slug'    => (string) $post->post_name,
			'label'   => (string) $post->post_title,
			'kind'    => 'text',
			'html'    => is_array( $html ) ? array_map( 'strval', $html ) : array(),
			'version' => (int) get_post_meta( $post->ID, '_blk_version', true ),
		);
	}

	/**
	 * Save a text block. A new version changes the hash of every template that
	 * uses it.
	 *
	 * @param string $slug  Slug; a new block is made when none has it.
	 * @param string $label Name shown on the Blocks screen.
	 * @param array  $html  Language => text.
	 * @return int|WP_Error Block ID.
	 */
	public static function save_block( $slug, $label, array $html ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			return new WP_Error( 'no_slug', __( 'A block needs a short name of letters, numbers and hyphens.', 'tcgiant-sync' ) );
		}

		$text = array();
		foreach ( $html as $lang => $value ) {
			if ( in_array( $lang, self::LANGS, true ) && '' !== trim( (string) $value ) ) {
				$text[ $lang ] = (string) $value;
			}
		}

		$block = self::get_block( $slug );
		$post  = array(
			'post_type'   => self::BLOCK_TYPE,
			'post_title'  => '' !== trim( (string) $label ) ? sanitize_text_field( (string) $label ) : $slug,
			'post_name'   => $slug,
			'post_status' => 'publish',
		);
		if ( $block ) {
			$post['ID'] = $block['id'];
			$id         = wp_update_post( wp_slash( $post ), true );
		} else {
			$id = wp_insert_post( wp_slash( $post ), true );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( (int) $id, '_blk_kind', 'text' );
		update_post_meta( (int) $id, '_blk_html', wp_slash( $text ) );
		update_post_meta( (int) $id, '_blk_version', ( $block ? $block['version'] : 0 ) + 1 );

		// The templates that use it now hash differently.
		foreach ( self::all() as $tpl ) {
			if ( in_array( 'block.' . $slug, TCGiant_Sync_Template_Renderer::names( $tpl['html'] ), true ) ) {
				update_post_meta( $tpl['id'], '_tpl_hash', self::template_hash( $tpl ) );
			}
		}

		return (int) $id;
	}

	/**
	 * Delete a text block. A template that uses it then renders it empty and
	 * says so.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public static function delete_block( $slug ) {
		$block = self::get_block( $slug );
		if ( ! $block ) {
			return false;
		}
		wp_delete_post( $block['id'], true );
		return true;
	}

	// -------------------------------------------------------------------------
	// Import and export
	// -------------------------------------------------------------------------

	/**
	 * A template as a tcgiant-template/1 file.
	 *
	 * Images are this site's attachments, which mean nothing on another site,
	 * so the logo and banner are left out.
	 *
	 * @param int $id Template ID.
	 * @return string|WP_Error JSON.
	 */
	public static function export_json( $id ) {
		$tpl = self::get( $id );
		if ( ! $tpl ) {
			return new WP_Error( 'no_template', __( 'That template no longer exists.', 'tcgiant-sync' ) );
		}

		$design              = $tpl['design'];
		$design['logo_id']   = 0;
		$design['banner_id'] = 0;

		$blocks = array();
		foreach ( TCGiant_Sync_Template_Renderer::names( $tpl['html'] ) as $name ) {
			if ( 0 !== strpos( $name, 'block.' ) ) {
				continue;
			}
			$block = self::get_block( substr( $name, 6 ) );
			if ( $block ) {
				$blocks[] = array(
					'slug' => $block['slug'],
					'kind' => 'text',
					'html' => (string) ( $block['html'][ $tpl['lang'] ] ?? '' ),
				);
			}
		}

		return (string) wp_json_encode( array(
			'format'          => self::FORMAT,
			'name'            => $tpl['name'],
			'lang'            => $tpl['lang'],
			'html'            => $tpl['html'],
			'css'             => $tpl['css'],
			'content_filters' => $tpl['content_filters'],
			'design'          => $design,
			'blocks'          => $blocks,
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Read a tcgiant-template/1 file into a new draft, through the same save
	 * path as the editor. A block it carries is added when this site has none
	 * of that name; one it already has is kept as it is.
	 *
	 * @param string $json    The file.
	 * @param int    $user_id Who imported it.
	 * @return array|WP_Error id, and notes on the blocks.
	 */
	public static function import_json( $json, $user_id = 0 ) {
		if ( strlen( (string) $json ) > self::MAX_IMPORT_BYTES ) {
			return new WP_Error( 'too_large', __( 'That file is too large to be a template.', 'tcgiant-sync' ) );
		}
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? '' ) ) {
			return new WP_Error( 'not_a_template', __( 'That is not a TCGiant template file (tcgiant-template/1).', 'tcgiant-sync' ) );
		}
		foreach ( array( 'name', 'html' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				return new WP_Error( 'not_a_template', __( 'That template file is missing its name or its HTML.', 'tcgiant-sync' ) );
			}
		}

		$id = self::save( 0, array(
			'name'            => $data['name'],
			'status'          => 'draft',
			'html'            => $data['html'],
			'css'             => is_string( $data['css'] ?? null ) ? $data['css'] : '',
			'lang'            => is_string( $data['lang'] ?? null ) ? $data['lang'] : 'en',
			'content_filters' => is_string( $data['content_filters'] ?? null ) ? $data['content_filters'] : 'core',
			'design'          => is_array( $data['design'] ?? null ) ? array_merge( $data['design'], array( 'logo_id' => 0, 'banner_id' => 0 ) ) : array(),
		), $user_id );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$notes = array();
		$lang  = self::lang( $data['lang'] ?? 'en' );
		foreach ( is_array( $data['blocks'] ?? null ) ? $data['blocks'] : array() as $block ) {
			if ( ! is_array( $block ) || ! is_string( $block['slug'] ?? null ) || ! is_string( $block['html'] ?? null ) ) {
				continue;
			}
			$slug = sanitize_title( $block['slug'] );
			if ( '' === $slug ) {
				continue;
			}
			if ( self::get_block( $slug ) ) {
				/* translators: %s: block name */
				$notes[] = sprintf( __( 'Block "%s" already exists here, so yours was kept and the file\'s was not imported.', 'tcgiant-sync' ), $slug );
				continue;
			}
			$saved = self::save_block( $slug, $slug, array( $lang => $block['html'] ) );
			/* translators: %s: block name */
			$notes[] = is_wp_error( $saved ) ? $saved->get_error_message() : sprintf( __( 'Block "%s" was added.', 'tcgiant-sync' ), $slug );
		}

		return array(
			'id'    => (int) $id,
			'notes' => $notes,
		);
	}

	// -------------------------------------------------------------------------
	// Which template a product uses
	// -------------------------------------------------------------------------

	/**
	 * The template a product's listing uses, first match winning: the
	 * product's own choice; the WooCommerce category map, most specific
	 * category first, then priority (1 before 2); the default; None.
	 *
	 * A template that has been deleted or put back to draft sends None, and the
	 * log says so once a request.
	 *
	 * @param WC_Product $product  Product.
	 * @param array|null $settings Template settings; read when null.
	 * @return array{template_id: int, why: string}
	 */
	public static function resolve( $product, $settings = null ) {
		$settings = is_array( $settings ) ? $settings : self::settings();
		$product  = self::parent_of( $product );

		$override = (string) $product->get_meta( self::OVERRIDE_META );
		if ( 'none' === $override ) {
			return array( 'template_id' => 0, 'why' => 'override_none' );
		}
		if ( '' !== $override && ctype_digit( $override ) ) {
			return self::usable_or_none( (int) $override, 'override', $product );
		}

		$rule = self::category_rule( $product, (array) $settings['category_map'] );
		if ( $rule ) {
			return self::usable_or_none( (int) $rule['template_id'], 'category', $product );
		}

		$default = (int) $settings['default_template_id'];
		if ( $default > 0 ) {
			return self::usable_or_none( $default, 'default', $product );
		}

		return array( 'template_id' => 0, 'why' => 'none' );
	}

	/**
	 * The category rule for a product: the rule on the deepest of its
	 * categories or their parents, and of two as deep, the lower priority
	 * number.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $map     Rules.
	 * @return array|null
	 */
	public static function category_rule( $product, array $map ) {
		if ( empty( $map ) ) {
			return null;
		}

		$reach = array(); // term ID => depth.
		foreach ( (array) $product->get_category_ids() as $term_id ) {
			$term_id   = (int) $term_id;
			$ancestors = function_exists( 'get_ancestors' ) ? array_map( 'intval', (array) get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) : array();
			$reach[ $term_id ] = count( $ancestors );
			foreach ( $ancestors as $i => $ancestor ) {
				// get_ancestors() lists the parent first, so each step up is one shallower.
				$depth = count( $ancestors ) - $i - 1;
				if ( ! isset( $reach[ $ancestor ] ) || $reach[ $ancestor ] < $depth ) {
					$reach[ $ancestor ] = $depth;
				}
			}
		}

		$best = null;
		foreach ( $map as $row ) {
			$term_id = (int) ( $row['term_id'] ?? 0 );
			if ( ! isset( $reach[ $term_id ] ) ) {
				continue;
			}
			$row['depth'] = $reach[ $term_id ];
			if ( null === $best
				|| $row['depth'] > $best['depth']
				|| ( $row['depth'] === $best['depth'] && (int) $row['priority'] < (int) $best['priority'] ) ) {
				$best = $row;
			}
		}

		return $best;
	}

	/**
	 * The template, if it can be used; None otherwise, said once.
	 *
	 * @param int        $id      Template ID.
	 * @param string     $why     Which rule chose it.
	 * @param WC_Product $product Product.
	 * @return array{template_id: int, why: string}
	 */
	private static function usable_or_none( $id, $why, $product ) {
		if ( self::is_usable( $id ) ) {
			return array( 'template_id' => (int) $id, 'why' => $why );
		}
		if ( empty( self::$missing_said[ $id ] ) ) {
			self::$missing_said[ $id ] = true;
			TCGiant_Sync_Logger::warning( sprintf(
				'Listing template #%1$d (chosen by the %2$s rule, first for WC #%3$d) has been deleted or is a draft, so those listings are sent the plain description. Choose another template in TCGiant Sync > Listing Templates.',
				$id,
				'override' === $why ? 'product\'s own' : ( 'category' === $why ? 'category' : 'default' ),
				$product->get_id()
			) );
		}
		return array( 'template_id' => 0, 'why' => 'missing' );
	}

	/**
	 * A variation's parent, whose settings the listing goes by.
	 *
	 * @param WC_Product $product Product.
	 * @return WC_Product
	 */
	private static function parent_of( $product ) {
		if ( $product instanceof WC_Product_Variation && function_exists( 'wc_get_product' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				return $parent;
			}
		}
		return $product;
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * Render a product's description with a template.
	 *
	 * The exporter gathers what the listing says - the title it sends, the
	 * specifics after eBay's cut, the condition it sends, the policies it
	 * attaches - and hands them over as $facts, so the description can never
	 * disagree with the rest of the item it travels in. Push, Verify and the
	 * preview all come through TCGiant_Sync_Exporter::build_listing_description(),
	 * so the preview shows the bytes eBay gets.
	 *
	 * @param WC_Product $product  Product.
	 * @param array      $settings Export settings: template_id (or a
	 *                             template_draft for the editor's preview), and
	 *                             the policy IDs the same item sends.
	 * @param array      $facts    From the exporter: title, content_source,
	 *                             short_description, sku, attributes,
	 *                             condition_name, grading, specifics,
	 *                             variations, images.
	 * @return array html, template_id, version, hash, tpl_hash, content_hash,
	 *               chars, warnings, removed.
	 */
	public static function render( $product, array $settings, array $facts ) {
		$tpl_settings = self::settings();

		if ( isset( $settings['template_draft'] ) && is_array( $settings['template_draft'] ) ) {
			$tpl = array_merge( array(
				'id'              => (int) ( $settings['template_id'] ?? 0 ),
				'name'            => '',
				'version'         => 0,
				'html'            => '',
				'css'             => '',
				'lang'            => 'en',
				'content_filters' => 'core',
			), $settings['template_draft'] );
			$tpl['design'] = self::sanitize_design( $tpl['design'] ?? array() );
			$tpl['lang']   = self::lang( $tpl['lang'] );
		} else {
			$tpl = self::get( (int) ( $settings['template_id'] ?? 0 ) );
		}

		if ( ! $tpl ) {
			return array();
		}

		$warnings = array();
		$removed  = array();
		$sanitise = array(
			'home_host'  => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'site_https' => self::site_https(),
		);
		if ( ! $sanitise['site_https'] ) {
			$warnings[] = self::not_https_warning();
		}

		// The content region: the eBay-only description, or the website's.
		$source = (string) ( $facts['content_source'] ?? '' );
		if ( false !== stripos( $source, '<style' ) ) {
			$warnings[] = __( 'This product\'s description already holds a <style> block, which usually means it was imported with another listing template inside it. The style is removed; check the preview for a design inside a design.', 'tcgiant-sync' );
		}
		$content = TCGiant_Sync_Template_Sanitiser::clean( self::content_filters( $source, $tpl['content_filters'] ), $sanitise );
		$removed = array_merge( $removed, $content['removed'] );

		// The policy blocks, from the policies this same item attaches.
		$policies = TCGiant_Sync_Template_Policies::blocks( $settings );

		// On a dark page a browser's own link blue barely shows, and only the
		// stylesheet colours links, which eBay may drop (live test 2): a link
		// in the content with no colour of its own takes the accent inline.
		if ( self::is_dark( (string) $tpl['design']['background'] ) ) {
			$content['html'] = (string) preg_replace( '#<a\b(?![^>]*\sstyle=)#i', '<a style="color:' . $tpl['design']['accent'] . '"', $content['html'] );
		}

		$context = self::context( $facts, $tpl, $tpl_settings, $policies );
		// The content's wrapper carries a class only this render knows. The
		// marker goes on it after the whole body has been cleaned with no
		// marker allowed (below), so nothing a template writes - a data-tcg
		// split by a placeholder, or one a value brings - can be a second.
		$wrapper                      = 'tcgwrap' . bin2hex( random_bytes( 8 ) );
		$context['description']       = '<div class="' . $wrapper . '">' . $content['html'] . '</div>';
		$context['short_description'] = '' !== trim( (string) ( $facts['short_description'] ?? '' ) ) ? self::content_filters( (string) $facts['short_description'], $tpl['content_filters'] ) : '';

		// The short description and the blocks are cleaned as the content is,
		// before the marker is allowed, so neither can bring a marker of its
		// own for a re-import to take as the content region.
		foreach ( $context as $key => $value ) {
			if ( ( 'short_description' === $key || 0 === strpos( (string) $key, 'block.' ) ) && is_string( $value ) && '' !== $value ) {
				$part            = TCGiant_Sync_Template_Sanitiser::clean( $value, $sanitise );
				$context[ $key ] = $part['html'];
				$removed         = array_merge( $removed, $part['removed'] );
			}
		}

		$allow    = array_flip( (array) $tpl_settings['meta_allowlist'] );
		$resolver = function ( $name ) use ( $allow, $product ) {
			if ( 0 === strpos( $name, 'meta.' ) ) {
				$key = substr( $name, 5 );
				if ( ! isset( $allow[ $key ] ) ) {
					/* translators: %s: a meta key */
					return array( false, null, sprintf( __( '{{meta.%s}} is not on the allowlist in Template settings, so it rendered empty. Product meta can hold prices and costs, so each key must be allowed first.', 'tcgiant-sync' ), $key ) );
				}
				$value = self::parent_of( $product )->get_meta( $key );
				if ( is_array( $value ) || is_object( $value ) ) {
					/* translators: %s: a meta key */
					return array( false, null, sprintf( __( '{{meta.%s}} holds a list, not text, so it rendered empty.', 'tcgiant-sync' ), $key ) );
				}
				return array( true, (string) $value );
			}
			if ( 0 === strpos( $name, 'attr.' ) ) {
				/* translators: %s: an attribute slug */
				return array( false, null, sprintf( __( 'This product has no attribute "%s", so {{attr.%s}} rendered empty.', 'tcgiant-sync' ), substr( $name, 5 ), substr( $name, 5 ) ) );
			}
			if ( 0 === strpos( $name, 'block.' ) ) {
				/* translators: %s: a block name */
				return array( false, null, sprintf( __( 'There is no block "%s" in this template\'s language, so it rendered empty.', 'tcgiant-sync' ), substr( $name, 6 ) ) );
			}
			return null;
		};

		$body = TCGiant_Sync_Template_Renderer::render( $tpl['html'], $context, array(
			'raw'      => self::RAW,
			'resolver' => $resolver,
		) );
		$warnings = array_merge( $warnings, $body['warnings'] );

		// Cleaned with no marker allowed: every data-tcg in the rendered body
		// goes, however it got there - an imported or converted template could
		// plant one round text of its own, which a re-import would take for the
		// content region. Then our one marker, on our own wrapper; a second
		// {{{description}}} keeps its class and no marker.
		$clean         = TCGiant_Sync_Template_Sanitiser::clean( $body['html'], $sanitise );
		$clean['html'] = (string) preg_replace( '/class="' . $wrapper . '"/', 'class="tcg-desc" data-tcg="content"', $clean['html'], 1 );
		$clean['html'] = str_replace( 'class="' . $wrapper . '"', 'class="tcg-desc"', $clean['html'] );
		$removed = array_merge( $removed, $clean['removed'] );
		$warnings = array_merge( $warnings, $content['warnings'], $clean['warnings'] );

		// The stylesheet: only colours from the design form go into it.
		$css_values = array_intersect_key( $context, array_flip( array( 'design.accent', 'design.accent_text', 'design.background', 'design.text', 'design.muted', 'design.border', 'design.panel' ) ) );
		$css        = TCGiant_Sync_Template_Renderer::render( $tpl['css'], $css_values );
		$css        = TCGiant_Sync_Template_Sanitiser::filter_css( $css['html'] );
		$removed    = array_merge( $removed, $css['removed'] );

		$html  = ( '' !== $css['css'] ? '<style>' . $css['css'] . '</style>' : '' ) . $clean['html'];
		$html .= '<!--tcg:tpl=' . (int) $tpl['id'] . '.' . (int) $tpl['version'] . '-->';

		$warnings = array_merge( $warnings, TCGiant_Sync_Template_Sanitiser::lint( $html ), self::language_lint( $tpl, $body['used'], $policies['warnings'] ) );

		$used_policies = array();
		foreach ( array( 'policy.returns', 'policy.handling' ) as $name ) {
			if ( in_array( $name, $body['used'], true ) ) {
				$used_policies[ $name ] = (string) ( $context[ $name ] ?? '' );
			}
		}
		$shop = array();
		foreach ( array( 'shop_name', 'logo_url', 'ebay_store_url' ) as $name ) {
			if ( in_array( $name, $body['used'], true ) ) {
				$shop[ $name ] = (string) ( $context[ $name ] ?? '' );
			}
		}
		$tpl_hash = self::template_hash( $tpl );

		$product_side = array_diff_key( $context, array_flip( array_merge( array_keys( $used_policies ), array_keys( $shop ) ) ) );
		foreach ( array_keys( $product_side ) as $key ) {
			if ( 0 === strpos( $key, 'block.' ) || 0 === strpos( $key, 'policy.' ) || 0 === strpos( $key, 'design.' ) || 0 === strpos( $key, 'show.' ) ) {
				unset( $product_side[ $key ] );
			}
		}
		$product_side['content_source'] = $source;

		return array(
			'html'         => $html,
			'none'         => false,
			'template_id'  => (int) $tpl['id'],
			'version'      => (int) $tpl['version'],
			'name'         => (string) $tpl['name'],
			'lang'         => (string) $tpl['lang'],
			'hash'         => sha1( $html ),
			// The content region as an import reads it back, so that a listing
			// that comes back as it was sent leaves the website's description
			// alone (TCGiant_Sync_Mapper::save_as_product()).
			'region_hash'  => sha1( self::content_region( $html, true ) ),
			'tpl_hash'     => sha1( $tpl_hash . wp_json_encode( $used_policies ) . wp_json_encode( $shop ) ),
			'content_hash' => sha1( (string) wp_json_encode( $product_side ) ),
			'chars'        => mb_strlen( $html, 'UTF-8' ),
			'warnings'     => array_values( array_unique( $warnings ) ),
			'removed'      => self::count_removed( $removed ),
		);
	}

	/**
	 * Run the content through the template's chosen filters.
	 *
	 * "core" is WordPress's own formatting, in the order the_content runs it
	 * (do_blocks at 9, wpautop and shortcode_unautop at 10, do_shortcode at
	 * 11), without whatever other plugins hang on the_content. "all" is
	 * the_content itself, as the plain description has always had, other
	 * plugins' markup included.
	 *
	 * @param string $content Content.
	 * @param string $filters core or all.
	 * @return string
	 */
	private static function content_filters( $content, $filters ) {
		if ( 'all' === $filters ) {
			return (string) apply_filters( 'the_content', $content );
		}
		foreach ( array( 'do_blocks', 'wpautop', 'shortcode_unautop', 'do_shortcode' ) as $fn ) {
			if ( function_exists( $fn ) ) {
				$content = (string) call_user_func( $fn, $content );
			}
		}
		return $content;
	}

	/**
	 * What the template can put in, by placeholder name.
	 *
	 * @param array $facts        From the exporter.
	 * @param array $tpl          Template.
	 * @param array $tpl_settings Template settings.
	 * @param array $policies     The policy blocks.
	 * @return array
	 */
	private static function context( array $facts, array $tpl, array $tpl_settings, array $policies ) {
		$design = self::design_values( $tpl['design'], $tpl_settings );

		$context = array(
			'title'          => (string) ( $facts['title'] ?? '' ),
			'sku'            => (string) ( $facts['sku'] ?? '' ),
			'condition_name' => (string) ( $facts['condition_name'] ?? '' ),
			'grading'        => is_array( $facts['grading'] ?? null ) ? $facts['grading'] : false,
			'shop_name'      => (string) $tpl_settings['shop_name'],
			'logo_url'       => self::image_url( (int) $tpl_settings['logo_id'] ),
			'ebay_store_url' => (string) $tpl_settings['ebay_store_url'],
		) + $design;

		foreach ( (array) ( $facts['attributes'] ?? array() ) as $slug => $value ) {
			$context[ 'attr.' . $slug ] = (string) $value;
		}

		$specifics = array();
		foreach ( (array) ( $facts['specifics'] ?? array() ) as $row ) {
			$specifics[] = array( 'name' => (string) $row[0], 'value' => (string) $row[1] );
		}
		$context['specifics']       = $specifics;
		$context['specifics_table'] = self::table( $specifics, 'tcg-specifics', $design );

		$variations = array();
		foreach ( (array) ( $facts['variations'] ?? array() ) as $row ) {
			$variations[] = array( 'name' => (string) ( $row[0] ?? '' ), 'value' => implode( ', ', array_map( 'strval', (array) ( $row[1] ?? array() ) ) ) );
		}
		$context['variations_table'] = self::table( $variations, 'tcg-variations', $design );

		$images = array();
		foreach ( array_values( (array) ( $facts['images'] ?? array() ) ) as $i => $url ) {
			if ( $i >= self::MAX_IMAGE_N ) {
				break;
			}
			$images[]                          = array( 'url' => (string) $url );
			$context[ 'image.' . ( $i + 1 ) ] = (string) $url;
		}
		for ( $n = count( $images ) + 1; $n <= self::MAX_IMAGE_N; $n++ ) {
			$context[ 'image.' . $n ] = '';
		}
		$context['images']     = $images;
		$context['main_image'] = $images ? $images[0]['url'] : '';
		$context['gallery']    = self::gallery( array_slice( $images, 0, self::GALLERY_IMAGES ) );

		foreach ( TCGiant_Sync_Template_Renderer::names( $tpl['html'] ) as $name ) {
			if ( 0 === strpos( $name, 'block.' ) ) {
				$block = self::get_block( substr( $name, 6 ) );
				if ( $block && isset( $block['html'][ $tpl['lang'] ] ) ) {
					$context[ $name ] = $block['html'][ $tpl['lang'] ];
				}
			}
		}

		$context['policy.returns']  = $policies['returns'];
		$context['policy.handling'] = $policies['handling'];

		return $context;
	}

	/**
	 * The language warnings: a template not in the site's language, and the
	 * policy blocks' English on a site that is not English.
	 *
	 * @param array    $tpl      Template.
	 * @param string[] $used     Placeholders it used.
	 * @param string[] $missing  Why a policy block was left out, by block.
	 * @return string[]
	 */
	private static function language_lint( array $tpl, array $used, array $missing ) {
		$warnings = array();
		$site     = self::marketplace_lang();

		if ( $tpl['lang'] !== $site ) {
			/* translators: 1: template language, 2: site language */
			$warnings[] = sprintf( __( 'This template is written in %1$s, but your eBay site\'s language is %2$s. eBay requires descriptions in the site\'s main language.', 'tcgiant-sync' ), strtoupper( $tpl['lang'] ), strtoupper( $site ) );
		}

		foreach ( array( 'returns', 'handling' ) as $name ) {
			if ( ! in_array( 'policy.' . $name, $used, true ) ) {
				continue;
			}
			if ( isset( $missing[ $name ] ) ) {
				$warnings[] = $missing[ $name ];
			} elseif ( 'en' !== $site ) {
				/* translators: %s: site language */
				$warnings[] = sprintf( __( 'The policy blocks are written in English only in this version, and your eBay site\'s language is %s. Leave them out, or type the policy into a text block in that language.', 'tcgiant-sync' ), strtoupper( $site ) );
				break;
			}
		}

		return $warnings;
	}

	/**
	 * A two-column table of names and values.
	 *
	 * Layout in inline styles, so it reads the same if eBay drops the
	 * stylesheet.
	 *
	 * @param array  $rows   name, value.
	 * @param string $class  Class.
	 * @param array  $design Design values.
	 * @return string '' when there are no rows.
	 */
	private static function table( array $rows, $class, array $design ) {
		if ( empty( $rows ) ) {
			return '';
		}
		$border = $design['design.border'];
		$html   = '<table class="' . $class . '" style="width:100%;border-collapse:collapse;font-size:15px;line-height:1.4"><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr><th style="text-align:left;vertical-align:top;padding:7px 10px;border-bottom:1px solid ' . $border . ';width:40%;font-weight:600">'
				. TCGiant_Sync_Template_Renderer::escape( $row['name'] ) . '</th><td style="vertical-align:top;padding:7px 10px;border-bottom:1px solid ' . $border . '">'
				. TCGiant_Sync_Template_Renderer::escape( $row['value'] ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/**
	 * A static grid of up to six images. No JavaScript: eBay bans active
	 * content, and shows the listing's own photos above the description.
	 *
	 * @param array $images url rows.
	 * @return string
	 */
	private static function gallery( array $images ) {
		if ( empty( $images ) ) {
			return '';
		}
		// Floats, not display: WordPress's inline-style filter keeps float and
		// clear but drops display, so a grid built on it would collapse.
		$html = '<div class="tcg-gallery" style="margin:16px 0">';
		foreach ( $images as $image ) {
			$html .= '<div style="float:left;width:31.33%;margin:0 1% 8px"><img src="' . esc_url( $image['url'] ) . '" alt="" style="width:100%;height:auto;border-radius:4px"></div>';
		}
		return $html . '<div style="clear:both"></div></div>';
	}

	/**
	 * What the sanitiser removed, counted: "script (2)".
	 *
	 * @param string[] $removed Each removal.
	 * @return string[]
	 */
	public static function count_removed( array $removed ) {
		$out = array();
		foreach ( array_count_values( array_map( 'strval', $removed ) ) as $what => $n ) {
			$out[] = $n > 1 ? sprintf( '%s (%d)', $what, $n ) : (string) $what;
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// The way back from eBay
	// -------------------------------------------------------------------------

	/**
	 * The description to import from a listing a template made: only the
	 * content region, so a re-import never wraps one template inside another.
	 *
	 * Only with proof that TCGiant sent this listing with a template. A shop
	 * that never used one may well have a "tcg-desc" class, or a data-tcg
	 * attribute, of its own, and its description must import exactly as it
	 * always has. The proof is either:
	 * - the product linked to the listing recorded a template on its last
	 *   send (_tcgiant_tpl_id > 0, $sent_with_template); or
	 * - the description carries both our comment, <!--tcg:tpl=ID.VERSION-->,
	 *   and the marker exactly as we write it: data-tcg="content", in lower
	 *   case and double quotes.
	 * The class alone, tcg-desc as a class of its own, counts only with the
	 * first, in case eBay drops data-* attributes and comments (UNVERIFIED,
	 * live test 7).
	 *
	 * Without the proof, exactly what eBay sent, as before.
	 *
	 * @param string $description        eBay's Description.
	 * @param bool   $sent_with_template Whether the linked product recorded a
	 *                                   template on its last send.
	 * @return string
	 */
	public static function content_region( $description, $sent_with_template = false ) {
		$description = (string) $description;

		$patterns = array( '#<div\b[^>]*\sdata-tcg="content"[^>]*>#' );
		if ( $sent_with_template ) {
			$patterns[] = '#<div\b[^>]*\sclass=(["\'])(?:[^"\']*\s)?tcg-desc(?:\s[^"\']*)?\1[^>]*>#';
		} elseif ( ! preg_match( '#<!--tcg:tpl=\d+\.\d+-->#', $description ) ) {
			return $description;
		}

		$start = null;
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $description, $m, PREG_OFFSET_CAPTURE ) ) {
				$start = $m[0][1] + strlen( $m[0][0] );
				break;
			}
		}
		if ( null === $start ) {
			return $description;
		}

		// Its own closing tag: the first </div> that brings the depth back to
		// nothing.
		$depth = 1;
		if ( preg_match_all( '#<div\b[^>]*>|</div\s*>#i', $description, $tags, PREG_OFFSET_CAPTURE, $start ) ) {
			foreach ( $tags[0] as $tag ) {
				$depth += ( '/' === $tag[0][1] ) ? -1 : 1;
				if ( 0 === $depth ) {
					return trim( substr( $description, $start, $tag[1] - $start ) );
				}
			}
		}

		return $description;
	}
}
