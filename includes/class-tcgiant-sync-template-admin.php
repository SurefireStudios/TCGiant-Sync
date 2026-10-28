<?php
/**
 * The Listing Templates screens, and the template fields on products.
 *
 * Every template screen - the list, the editor, the blocks, the settings,
 * the WP-Lister converter - and every save, import and assignment from them
 * needs manage_options, the plugin's own capability, and a nonce. A product's
 * template and eBay-only description follow the rest of its eBay tab, which
 * checks edit_post. Template HTML is stored as typed, so it is only ever
 * printed escaped (esc_textarea() in the editor) or after the sanitiser (the
 * preview, inside a sandboxed iframe that may run no scripts). WP-Lister
 * shipped a stored-XSS fix in 2026, which is the kind of fault a template
 * editor invites.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Template_Admin class.
 */
class TCGiant_Sync_Template_Admin {

	/** The screen's slug. */
	const PAGE = 'tcgiant-templates';

	/** Its hook suffix, as admin_enqueue_scripts names it. */
	const HOOK = 'tcgiant-sync_page_tcgiant-templates';

	/** The nonce of the screen's AJAX: preview, Check with eBay, the product picker. */
	const AJAX_NONCE = 'tcgiant_tpl_ajax';

	/** Bulk actions on the products list: one per published template. */
	const BULK_PREFIX = 'tcgiant_tpl_set_';

	/** A few at most, so the dropdown stays a dropdown. */
	const BULK_TEMPLATES = 20;

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
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		add_action( 'admin_post_tcgiant_tpl_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_tcgiant_tpl_action', array( $this, 'handle_action' ) );
		add_action( 'admin_post_tcgiant_tpl_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_tcgiant_tpl_settings', array( $this, 'handle_settings' ) );
		add_action( 'admin_post_tcgiant_tpl_block', array( $this, 'handle_block' ) );

		add_action( 'wp_ajax_tcgiant_tpl_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_tcgiant_tpl_check_ebay', array( $this, 'ajax_check_ebay' ) );
		add_action( 'wp_ajax_tcgiant_tpl_products', array( $this, 'ajax_products' ) );

		add_filter( 'bulk_actions-edit-product', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );

		// Switching from WP-Lister: the converter, and the notice while it is active.
		add_action( 'admin_post_tcgiant_tpl_convert', array( $this, 'handle_convert' ) );
		add_action( 'admin_notices', array( $this, 'wplister_notice' ) );
		add_action( 'admin_post_tcgiant_dismiss_wplister_notice', array( $this, 'handle_dismiss_wplister' ) );
	}

	// -------------------------------------------------------------------------
	// The screen
	// -------------------------------------------------------------------------

	/**
	 * The screen's address.
	 *
	 * @param string $view list, edit, blocks, settings or convert.
	 * @param array  $args More query arguments.
	 * @return string
	 */
	public static function url( $view = 'list', array $args = array() ) {
		$query = array( 'page' => self::PAGE );
		if ( 'list' !== $view ) {
			$query['view'] = $view;
		}
		return add_query_arg( array_merge( $query, $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Draw the screen.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}
		include TCGIANT_SYNC_PATH . 'admin/views/templates.php';
	}

	/**
	 * The editor's scripts: WordPress's bundled CodeMirror, the media library
	 * for the logo and banner, and the screen's own script.
	 *
	 * @param string $hook Screen.
	 */
	public function enqueue( $hook ) {
		if ( self::HOOK !== $hook ) {
			return;
		}

		$view     = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings = array();
		if ( 'edit' === $view && function_exists( 'wp_enqueue_code_editor' ) ) {
			$settings = array(
				'html' => wp_enqueue_code_editor( array( 'type' => 'text/html' ) ),
				'css'  => wp_enqueue_code_editor( array( 'type' => 'text/css' ) ),
			);
		}
		if ( in_array( $view, array( 'edit', 'settings' ), true ) && function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		$ver = (string) filemtime( TCGIANT_SYNC_PATH . 'admin/assets/js/templates.js' );
		wp_enqueue_script( 'tcgiant-sync-templates', TCGIANT_SYNC_URL . 'admin/assets/js/templates.js', array( 'jquery' ), $ver, true );
		wp_localize_script( 'tcgiant-sync-templates', 'tcgiantTemplates', array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::AJAX_NONCE ),
			'codeEditor' => $settings,
			'limit'      => TCGiant_Sync_Template_Sanitiser::MAX_CHARS,
			'text'       => array(
				'loading'  => __( 'Rendering…', 'tcgiant-sync' ),
				'checking' => __( 'Asking eBay…', 'tcgiant-sync' ),
				'failed'   => __( 'The request to this site failed.', 'tcgiant-sync' ),
				'none'     => __( 'Nothing removed.', 'tcgiant-sync' ),
				'clean'    => __( 'No warnings.', 'tcgiant-sync' ),
				'pick'     => __( 'Choose an image', 'tcgiant-sync' ),
				'confirm'  => __( 'This sends one call to eBay to check the listing without creating it. Continue?', 'tcgiant-sync' ),
				/* translators: 1: characters, 2: the limit */
				'count'    => __( '%1$s / %2$s characters', 'tcgiant-sync' ),
				'plain'    => __( ' - the plain description, no template', 'tcgiant-sync' ),
				/* translators: 1: checks left today, 2: the daily limit */
				'left'     => __( 'One eBay call a press, without creating a listing. %1$d of %2$d left today.', 'tcgiant-sync' ),
			),
			'checkCap'   => (int) TCGiant_Sync_Templates::settings()['verify_daily_cap'],
			// Not connected: no confirm before a press the server refuses.
			'connected'  => TCGiant_Sync_OAuth::instance()->is_authenticated(),
		) );
	}

	// -------------------------------------------------------------------------
	// Saves
	// -------------------------------------------------------------------------

	/**
	 * Refused without the capability. The nonce is checked first, by the caller.
	 */
	private static function must_manage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}
	}

	/**
	 * The editor's Save.
	 */
	public function handle_save() {
		check_admin_referer( 'tcgiant_tpl_save' );
		self::must_manage();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the body is stored as typed and only ever printed escaped or after the sanitiser.
		$id   = absint( $_POST['template_id'] ?? 0 );
		$data = array(
			'name'            => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'status'          => 'publish' === ( $_POST['status'] ?? '' ) ? 'publish' : 'draft',
			'html'            => (string) wp_unslash( $_POST['html'] ?? '' ),
			'css'             => (string) wp_unslash( $_POST['css'] ?? '' ),
			'lang'            => sanitize_key( wp_unslash( $_POST['lang'] ?? 'en' ) ),
			'content_filters' => 'all' === ( $_POST['content_filters'] ?? '' ) ? 'all' : 'core',
			'design'          => self::posted_design( $_POST['design'] ?? array() ),
		);
		// phpcs:enable

		$saved = TCGiant_Sync_Templates::save( $id, $data, get_current_user_id() );
		if ( is_wp_error( $saved ) ) {
			self::notice( 'error', $saved->get_error_message() );
			self::back( self::url( 'edit', $id ? array( 'id' => $id ) : array() ) );
		}

		$tpl = TCGiant_Sync_Templates::get( $saved );
		/* translators: 1: template name, 2: version */
		$said = sprintf( __( 'Saved %1$s as version %2$d.', 'tcgiant-sync' ), $tpl['name'], $tpl['version'] );
		$warn = false;
		if ( 'draft' === $data['status'] && 'publish' === $tpl['status'] ) {
			// TCGiant_Sync_Templates::save() kept it published.
			$said .= ' ' . __( 'It stays Published: it is the default, a category rule or a product uses it, and as a draft those listings would be sent the plain description. Change those first, then set it to Draft.', 'tcgiant-sync' );
			$warn  = true;
		}
		if ( ! TCGiant_Sync_Templates::site_https() ) {
			$said .= ' ' . TCGiant_Sync_Templates::not_https_warning();
			$warn  = true;
		}
		self::notice( $warn ? 'warning' : 'success', $said );
		self::back( self::url( 'edit', array( 'id' => $saved ) ) );
	}

	/**
	 * The design form, as posted: only its own keys, each left for
	 * TCGiant_Sync_Templates::sanitize_design() to hold to its type.
	 *
	 * @param mixed $raw Posted, slashed.
	 * @return array
	 */
	private static function posted_design( $raw ) {
		$raw  = is_array( $raw ) ? wp_unslash( $raw ) : array();
		$show = array();
		foreach ( array_keys( TCGiant_Sync_Templates::PARTS ) as $part ) {
			$show[ $part ] = ! empty( $raw['show'][ $part ] );
		}
		return array(
			'accent'      => sanitize_text_field( (string) ( $raw['accent'] ?? '' ) ),
			'accent_text' => sanitize_text_field( (string) ( $raw['accent_text'] ?? '' ) ),
			'background'  => sanitize_text_field( (string) ( $raw['background'] ?? '' ) ),
			'logo_id'     => absint( $raw['logo_id'] ?? 0 ),
			'banner_id'   => absint( $raw['banner_id'] ?? 0 ),
			'show'        => $show,
		);
	}

	/**
	 * The list's actions: new, duplicate, delete, set as default, export,
	 * restore a version.
	 */
	public function handle_action() {
		check_admin_referer( 'tcgiant_tpl_action' );
		self::must_manage();

		$do  = sanitize_key( wp_unslash( $_REQUEST['do'] ?? '' ) );
		$id  = absint( $_REQUEST['id'] ?? 0 );
		$uid = get_current_user_id();

		switch ( $do ) {
			case 'new':
				$starter = sanitize_key( wp_unslash( $_REQUEST['starter'] ?? '' ) );
				$data    = class_exists( 'TCGiant_Sync_Template_Starters' ) ? TCGiant_Sync_Template_Starters::get( $starter ) : null;
				$new     = TCGiant_Sync_Templates::save( 0, $data ? array_merge( $data, array( 'status' => 'draft' ) ) : array( 'name' => __( 'New template', 'tcgiant-sync' ), 'status' => 'draft', 'html' => '<div style="max-width:700px;margin:0 auto">{{{description}}}</div>' ), $uid );
				if ( is_wp_error( $new ) ) {
					self::notice( 'error', $new->get_error_message() );
					self::back( self::url() );
				}
				self::back( self::url( 'edit', array( 'id' => $new ) ) );
				break;

			case 'duplicate':
				$copy = TCGiant_Sync_Templates::duplicate( $id, $uid );
				if ( is_wp_error( $copy ) ) {
					self::notice( 'error', $copy->get_error_message() );
					self::back( self::url() );
				}
				self::notice( 'success', __( 'Copied, as a draft.', 'tcgiant-sync' ) );
				self::back( self::url( 'edit', array( 'id' => $copy ) ) );
				break;

			case 'delete':
				$gone = TCGiant_Sync_Templates::delete( $id );
				self::notice( is_wp_error( $gone ) ? 'error' : 'success', is_wp_error( $gone ) ? $gone->get_error_message() : __( 'Template deleted.', 'tcgiant-sync' ) );
				self::back( self::url() );
				break;

			case 'default':
				$settings = TCGiant_Sync_Templates::settings();
				if ( $id && ! TCGiant_Sync_Templates::is_usable( $id ) ) {
					self::notice( 'error', __( 'Only a published template can be the default. Open it, set it to Published and save it first.', 'tcgiant-sync' ) );
					self::back( self::url() );
				}
				$settings['default_template_id'] = $id;
				update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, TCGiant_Sync_Templates::sanitize_settings( $settings ), false );
				self::notice( 'success', $id ? __( 'Default template set. Listings that follow the rules and have no category rule use it from their next push; nothing is sent to eBay now.', 'tcgiant-sync' ) : __( 'The default is None again: the plain WooCommerce description.', 'tcgiant-sync' ) );
				self::back( self::url() );
				break;

			case 'restore':
				$version  = absint( $_REQUEST['version'] ?? 0 );
				$restored = TCGiant_Sync_Templates::restore( $id, $version, $uid );
				if ( is_wp_error( $restored ) ) {
					self::notice( 'error', $restored->get_error_message() );
				} else {
					/* translators: 1: old version, 2: new version */
					self::notice( 'success', sprintf( __( 'Version %1$d restored, as version %2$d.', 'tcgiant-sync' ), $version, TCGiant_Sync_Templates::get( $id )['version'] ) );
				}
				self::back( self::url( 'edit', array( 'id' => $id ) ) );
				break;

			case 'export':
				$json = TCGiant_Sync_Templates::export_json( $id );
				if ( is_wp_error( $json ) ) {
					self::notice( 'error', $json->get_error_message() );
					self::back( self::url() );
				}
				$name = sanitize_title( TCGiant_Sync_Templates::get( $id )['name'] );
				nocache_headers();
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="' . ( '' !== $name ? $name : 'template' ) . '.tcgiant-template.json"' );
				echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON file download.
				exit;
		}

		self::back( self::url() );
	}

	/**
	 * Import JSON: a tcgiant-template/1 file, uploaded or pasted, into a draft.
	 */
	public function handle_import() {
		check_admin_referer( 'tcgiant_tpl_import' );
		self::must_manage();

		$json = '';
		if ( ! empty( $_FILES['template_file']['tmp_name'] ) ) {
			$file = $_FILES['template_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > TCGiant_Sync_Templates::MAX_IMPORT_BYTES ) {
				self::notice( 'error', __( 'That file could not be read, or is too large to be a template.', 'tcgiant-sync' ) );
				self::back( self::url() );
			}
			$json = (string) file_get_contents( $file['tmp_name'] );
		} elseif ( isset( $_POST['template_json'] ) ) {
			$json = (string) wp_unslash( $_POST['template_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$in = TCGiant_Sync_Templates::import_json( $json, get_current_user_id() );
		if ( is_wp_error( $in ) ) {
			self::notice( 'error', $in->get_error_message() );
			self::back( self::url() );
		}

		self::notice( 'success', trim( __( 'Imported, as a draft. Preview it with a few products before you publish it.', 'tcgiant-sync' ) . ' ' . implode( ' ', $in['notes'] ) ) );
		self::back( self::url( 'edit', array( 'id' => $in['id'] ) ) );
	}

	/**
	 * Template settings: the default, the category map, the shop's own fields,
	 * the meta allowlist, Check with eBay's cap, and the bulk-push limit.
	 */
	public function handle_settings() {
		check_admin_referer( 'tcgiant_tpl_settings' );
		self::must_manage();

		$input = isset( $_POST['tpl'] ) && is_array( $_POST['tpl'] ) ? wp_unslash( $_POST['tpl'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_settings() holds every key to its type.
		$said  = array( __( 'Template settings saved. Nothing has been sent to eBay: a listing gets its template on its next push.', 'tcgiant-sync' ) );
		$clean = TCGiant_Sync_Templates::sanitize_settings( $input, $said );
		update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, $clean, false );

		// Its own option: the forms that save tcgiant_sync_ebay_settings rewrite
		// the whole option and would drop it. An empty field, or one that is
		// not a number, keeps the limit as it is rather than reading as 0.
		$cap = isset( $_POST['bulk_push_cap'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['bulk_push_cap'] ) ) ) : '';
		if ( '' !== $cap && class_exists( 'TCGiant_Sync_Exporter' ) ) {
			if ( ! preg_match( '/^\d+$/', $cap ) ) {
				/* translators: %s: the limit kept */
				$said[] = sprintf( __( 'The daily bulk-push limit must be a whole number from 50 to 20,000, so it was left at %s.', 'tcgiant-sync' ), number_format_i18n( TCGiant_Sync_Exporter::bulk_push_cap() ) );
			} else {
				TCGiant_Sync_Exporter::set_bulk_push_cap( (int) $cap );
				if ( TCGiant_Sync_Exporter::bulk_push_cap() !== (int) $cap ) {
					/* translators: 1: what was typed, 2: what it was set to */
					$said[] = sprintf( __( 'The daily bulk-push limit goes from 50 to 20,000, so %1$s was set to %2$s.', 'tcgiant-sync' ), $cap, number_format_i18n( TCGiant_Sync_Exporter::bulk_push_cap() ) );
				}
			}
		}

		// UI review L1: on a site not served over https, every picture is
		// left out of the description, which would otherwise go unsaid.
		if ( ! TCGiant_Sync_Templates::site_https() ) {
			$said[] = TCGiant_Sync_Templates::not_https_warning();
		}

		self::notice( 1 < count( $said ) ? 'warning' : 'success', implode( ' ', $said ) );
		self::back( self::url( 'settings' ) );
	}

	/**
	 * Save or delete a text block.
	 */
	public function handle_block() {
		check_admin_referer( 'tcgiant_tpl_block' );
		self::must_manage();

		$slug = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		if ( ! empty( $_POST['delete'] ) ) {
			TCGiant_Sync_Templates::delete_block( $slug );
			self::notice( 'success', __( 'Block deleted. A template that uses it shows nothing in its place, and the preview says so.', 'tcgiant-sync' ) );
			self::back( self::url( 'blocks' ) );
		}

		$html = array();
		foreach ( TCGiant_Sync_Templates::LANGS as $lang ) {
			$html[ $lang ] = (string) wp_unslash( $_POST['html'][ $lang ] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned whenever it is rendered.
		}
		$saved = TCGiant_Sync_Templates::save_block( $slug, sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ), $html );
		self::notice( is_wp_error( $saved ) ? 'error' : 'success', is_wp_error( $saved ) ? $saved->get_error_message() : __( 'Block saved. Templates that use it show it from their next push.', 'tcgiant-sync' ) );
		self::back( self::url( 'blocks' ) );
	}

	// -------------------------------------------------------------------------
	// Preview and Check with eBay
	// -------------------------------------------------------------------------

	/**
	 * The exact bytes a push would send, for one product and one template:
	 * saved, or as the editor holds it now.
	 *
	 * @param int        $product_id  Product.
	 * @param int        $template_id Template; 0 for what the product's rules choose.
	 * @param array|null $draft       The editor's unsaved html, css, lang,
	 *                                content_filters and design, or null.
	 * @return array|WP_Error The render, with the export settings it used.
	 */
	public static function preview_render( $product_id, $template_id, $draft = null ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'no_product', __( 'Choose a product to preview with.', 'tcgiant-sync' ) );
		}
		if ( $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}

		$exporter = TCGiant_Sync_Exporter::instance();
		$settings = $exporter->get_export_settings( $product->get_id() );

		$settings['_preview'] = true;
		if ( $template_id > 0 ) {
			$settings['template_id'] = (int) $template_id;
		}
		if ( is_array( $draft ) ) {
			$settings['template_draft'] = $draft;
		}

		$render             = $exporter->build_listing_description( $product, $settings );
		$render['settings'] = $settings;
		$render['product']  = $product;

		// A shortcode whose output changes on every render - a date, a
		// counter, a random pick - makes the listing look changed every time.
		$again = $exporter->build_listing_description( $product, $settings );
		if ( $again['hash'] !== $render['hash'] ) {
			$render['warnings'][] = __( 'This description comes out differently each time it is rendered, usually because of a shortcode that shows a date, a counter or a random pick. eBay would be sent something new on every push.', 'tcgiant-sync' );
		}

		return $render;
	}

	/**
	 * The draft the editor posted, or null.
	 *
	 * @return array|null
	 */
	private static function posted_draft() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the callers check the nonce; the draft is rendered through the sanitiser.
		if ( empty( $_POST['draft'] ) ) {
			return null;
		}
		return array(
			'name'            => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'html'            => (string) wp_unslash( $_POST['html'] ?? '' ),
			'css'             => (string) wp_unslash( $_POST['css'] ?? '' ),
			'lang'            => sanitize_key( wp_unslash( $_POST['lang'] ?? 'en' ) ),
			'content_filters' => 'all' === ( $_POST['content_filters'] ?? '' ) ? 'all' : 'core',
			'design'          => self::posted_design( $_POST['design'] ?? array() ),
			'version'         => absint( $_POST['version'] ?? 0 ),
		);
		// phpcs:enable
	}

	/**
	 * AJAX: the preview.
	 */
	public function ajax_preview() {
		check_ajax_referer( self::AJAX_NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'tcgiant-sync' ) ) );
		}

		$render = self::preview_render( absint( $_POST['product_id'] ?? 0 ), absint( $_POST['template_id'] ?? 0 ), self::posted_draft() );
		if ( is_wp_error( $render ) ) {
			wp_send_json_error( array( 'message' => $render->get_error_message() ) );
		}

		wp_send_json_success( array(
			// The sanitised bytes, for the sandboxed iframe's srcdoc. Never put
			// into this page's own markup.
			'html'     => $render['html'],
			'chars'    => (int) $render['chars'],
			'limit'    => TCGiant_Sync_Template_Sanitiser::MAX_CHARS,
			'over'     => (int) $render['chars'] > TCGiant_Sync_Template_Sanitiser::MAX_CHARS,
			'warnings' => array_values( array_map( 'strval', $render['warnings'] ) ),
			'removed'  => array_values( array_map( 'strval', $render['removed'] ) ),
			'none'     => ! empty( $render['none'] ),
			'title'    => $render['product']->get_name(),
		) );
	}

	/**
	 * Check with eBay presses left today: verify_daily_cap a site a UTC day,
	 * the day the call counter keeps.
	 *
	 * @return int
	 */
	public static function checks_left() {
		$used = (int) get_transient( TCGiant_Sync_Templates::VERIFY_TRANSIENT . gmdate( 'Y-m-d' ) );
		return max( 0, (int) TCGiant_Sync_Templates::settings()['verify_daily_cap'] - $used );
	}

	/**
	 * AJAX: Check with eBay. One Verify call a press, as the push would make
	 * it, through the same description the preview shows.
	 */
	public function ajax_check_ebay() {
		check_ajax_referer( self::AJAX_NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'tcgiant-sync' ) ) );
		}

		if ( self::checks_left() < 1 ) {
			/* translators: %d: the daily cap */
			wp_send_json_error( array( 'message' => sprintf( __( 'Check with eBay has been used %d times today, the limit set in Template settings. It resets at midnight UTC.', 'tcgiant-sync' ), (int) TCGiant_Sync_Templates::settings()['verify_daily_cap'] ) ) );
		}

		// Nothing to ask eBay without a connection: said, and not counted.
		if ( ! TCGiant_Sync_OAuth::instance()->is_authenticated() ) {
			wp_send_json_error( array(
				'message' => __( 'This site is not connected to eBay, so there is nothing to check with. Connect it on the Settings screen, then try again. Nothing was counted.', 'tcgiant-sync' ),
				'left'    => self::checks_left(),
			) );
		}

		$render = self::preview_render( absint( $_POST['product_id'] ?? 0 ), absint( $_POST['template_id'] ?? 0 ), self::posted_draft() );
		if ( is_wp_error( $render ) ) {
			wp_send_json_error( array( 'message' => $render->get_error_message() ) );
		}

		$exporter = TCGiant_Sync_Exporter::instance();
		$product  = $render['product'];
		$settings = $render['settings'];

		$valid = $exporter->validate_export_settings( $settings );
		if ( is_wp_error( $valid ) ) {
			$missing = (array) ( $valid->get_error_data()['missing'] ?? array() );
			wp_send_json_error( array(
				'message' => $missing
					/* translators: %s: the settings missing */
					? sprintf( __( 'Check with eBay needs these settings first: %s. Nothing was counted.', 'tcgiant-sync' ), implode( ', ', $missing ) )
					: $valid->get_error_message(),
				'left'    => self::checks_left(),
			) );
		}

		$item_xml = $exporter->build_item_xml_public( $product, $settings );
		$too_long = $exporter->description_too_long();
		if ( is_wp_error( $too_long ) ) {
			wp_send_json_error( array( 'message' => $too_long->get_error_message() ) );
		}

		$api    = TCGiant_Sync_API::instance();
		$full   = '<Item>' . "\n" . $item_xml . "\n" . '</Item>';
		$result = $exporter->uses_fixed_price_calls( $product, $settings, '' ) ? $api->verify_add_fixed_price_item( $full ) : $api->verify_add_item( $full );

		// Counted once the call is made: refused here, before any request -
		// a staging site, the day's call budget, no token - it is not.
		$unsent = is_wp_error( $result ) && ( in_array( $result->get_error_code(), array( 'staging_blocked', 'no_token' ), true ) || ! empty( $result->get_error_data()['daily_budget'] ) );
		if ( ! $unsent ) {
			$key = TCGiant_Sync_Templates::VERIFY_TRANSIENT . gmdate( 'Y-m-d' );
			set_transient( $key, (int) get_transient( $key ) + 1, DAY_IN_SECONDS );
		}

		if ( is_wp_error( $result ) ) {
			if ( 'api_error' === $result->get_error_code() ) {
				/* translators: %s: eBay's answer */
				$message = sprintf( __( 'eBay would refuse this listing: %s', 'tcgiant-sync' ), $result->get_error_message() );
			} elseif ( 'no_token' === $result->get_error_code() ) {
				/* translators: %s: why */
				$message = sprintf( __( 'eBay did not accept this site\'s connection (%s). Reconnect it on the Settings screen, then try again.', 'tcgiant-sync' ), $result->get_error_message() );
			} else {
				/* translators: %s: why */
				$message = sprintf( __( 'The check did not get an answer from eBay: %s', 'tcgiant-sync' ), $result->get_error_message() );
			}
			wp_send_json_error( array( 'message' => $message, 'left' => self::checks_left() ) );
		}

		wp_send_json_success( array(
			'message' => __( 'eBay accepts this listing, description included. Nothing was listed.', 'tcgiant-sync' ),
			'left'    => self::checks_left(),
		) );
	}

	/**
	 * AJAX: products to preview with, by name or SKU; with nothing typed, the
	 * last pushed first.
	 */
	public function ajax_products() {
		check_ajax_referer( self::AJAX_NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'tcgiant-sync' ) ) );
		}

		$term = sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) );
		$ids  = array();

		if ( '' === $term ) {
			$ids = get_posts( array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'meta_key'       => '_ebay_export_last_pushed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
			) );
			if ( empty( $ids ) ) {
				$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 20, 'fields' => 'ids' ) );
			}
		} else {
			$by_sku = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $term ) : 0;
			if ( $by_sku ) {
				$ids[] = $by_sku;
			}
			$ids = array_merge( $ids, get_posts( array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 20,
				'fields'         => 'ids',
				's'              => $term,
			) ) );
		}

		$out = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$out[] = array(
					'id'   => $id,
					'name' => $product->get_name(),
					'sku'  => (string) $product->get_sku(),
				);
			}
		}
		wp_send_json_success( array( 'products' => array_slice( $out, 0, 20 ) ) );
	}

	// -------------------------------------------------------------------------
	// The product's eBay tab
	// -------------------------------------------------------------------------

	/**
	 * The template choice and the eBay-only description, on the product's
	 * eBay tab.
	 *
	 * @param int $product_id Product.
	 */
	public static function render_product_fields( $product_id ) {
		$product   = wc_get_product( $product_id );
		$override  = (string) get_post_meta( $product_id, TCGiant_Sync_Templates::OVERRIDE_META, true );
		$ebay_only = (string) get_post_meta( $product_id, TCGiant_Sync_Templates::EBAY_DESCRIPTION_META, true );

		// What the rules would choose, with this product following them.
		$rules = __( 'None (plain description)', 'tcgiant-sync' );
		if ( $product ) {
			$settings = TCGiant_Sync_Templates::settings();
			$rule     = TCGiant_Sync_Templates::category_rule( $product, (array) $settings['category_map'] );
			$by_rules = $rule ? (int) $rule['template_id'] : (int) $settings['default_template_id'];
			$tpl      = $by_rules ? TCGiant_Sync_Templates::get( $by_rules ) : null;
			if ( $tpl && 'publish' === $tpl['status'] ) {
				$rules = $tpl['name'];
			}
		}

		$preview = self::url( 'preview', array( 'product' => (int) $product_id ) );
		?>
		<div class="tc-ebay-section" id="tc-section-template">
			<div class="tc-ebay-section-head" data-target="tc-body-template">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
				<span><?php esc_html_e( 'Listing template and eBay description', 'tcgiant-sync' ); ?></span>
			</div>
			<div class="tc-ebay-section-body" id="tc-body-template">
				<?php wp_nonce_field( 'tcgiant_tpl_product', '_tcgiant_tpl_nonce' ); ?>
				<input type="hidden" name="_tcgiant_tpl_fields" value="1">
				<p class="form-field">
					<label for="_tcgiant_tpl_override"><?php esc_html_e( 'Listing template', 'tcgiant-sync' ); ?></label>
					<select id="_tcgiant_tpl_override" name="_tcgiant_tpl_override">
						<?php /* translators: %s: the template the rules choose now */ ?>
						<option value="" <?php selected( $override, '' ); ?>><?php echo esc_html( sprintf( __( 'Follow the rules (now: %s)', 'tcgiant-sync' ), $rules ) ); ?></option>
						<option value="none" <?php selected( $override, 'none' ); ?>><?php esc_html_e( 'None (plain description)', 'tcgiant-sync' ); ?></option>
						<?php foreach ( TCGiant_Sync_Templates::all( 'publish' ) as $tpl ) : ?>
							<option value="<?php echo esc_attr( (string) $tpl['id'] ); ?>" <?php selected( $override, (string) $tpl['id'] ); ?>><?php echo esc_html( $tpl['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<a href="<?php echo esc_url( $preview ); ?>" target="_blank" rel="noopener" style="margin-left:6px;"><?php esc_html_e( 'Preview', 'tcgiant-sync' ); ?></a>
				</p>
				<p class="form-field">
					<label for="_tcgiant_ebay_description"><?php esc_html_e( 'eBay-only description', 'tcgiant-sync' ); ?></label>
					<textarea id="_tcgiant_ebay_description" name="_tcgiant_ebay_description" rows="6" style="width:100%;"><?php echo esc_textarea( $ebay_only ); ?></textarea>
				</p>
				<p style="margin:0 12px 6px;font-size:11px;color:#666;">
					<?php esc_html_e( 'Optional. When filled, eBay is sent this instead of the website description, in the template or on its own, and it is always cleaned of scripts and links eBay does not allow. Leave it empty to send the website description. Push and Verify use what is here, saved or not.', 'tcgiant-sync' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Save the product's template choice and eBay-only description.
	 *
	 * Only a published template, None, or nothing (follow the rules) is kept.
	 *
	 * @param int   $post_id Product.
	 * @param array $source  The posted fields, still slashed.
	 * @return bool Whether anything was saved.
	 */
	public static function save_product_fields( $post_id, array $source ) {
		if ( empty( $source['_tcgiant_tpl_fields'] ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$choice = sanitize_text_field( wp_unslash( (string) ( $source['_tcgiant_tpl_override'] ?? '' ) ) );
		if ( 'none' !== $choice && ! ( ctype_digit( $choice ) && TCGiant_Sync_Templates::is_usable( (int) $choice ) ) ) {
			$choice = '';
		}
		if ( '' === $choice ) {
			delete_post_meta( $post_id, TCGiant_Sync_Templates::OVERRIDE_META );
		} else {
			update_post_meta( $post_id, TCGiant_Sync_Templates::OVERRIDE_META, $choice );
		}

		if ( isset( $source['_tcgiant_ebay_description'] ) ) {
			// Kept as typed: it goes through the sanitiser on every render.
			$text = trim( (string) wp_unslash( $source['_tcgiant_ebay_description'] ) );
			if ( '' === $text ) {
				delete_post_meta( $post_id, TCGiant_Sync_Templates::EBAY_DESCRIPTION_META );
			} else {
				update_post_meta( $post_id, TCGiant_Sync_Templates::EBAY_DESCRIPTION_META, wp_slash( $text ) );
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// The products list
	// -------------------------------------------------------------------------

	/**
	 * "eBay template: ..." bulk actions, one for each published template.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function register_bulk_actions( $actions ) {
		$actions[ self::BULK_PREFIX . 'rules' ] = __( 'eBay template: follow the rules', 'tcgiant-sync' );
		$actions[ self::BULK_PREFIX . 'none' ]  = __( 'eBay template: None (plain description)', 'tcgiant-sync' );
		foreach ( array_slice( TCGiant_Sync_Templates::all( 'publish' ), 0, self::BULK_TEMPLATES ) as $tpl ) {
			/* translators: %s: template name */
			// WordPress prints these labels as they are, so the name is
			// escaped here.
			$actions[ self::BULK_PREFIX . $tpl['id'] ] = sprintf( __( 'eBay template: %s', 'tcgiant-sync' ), esc_html( $tpl['name'] ) );
		}
		return $actions;
	}

	/**
	 * Set the template of the products chosen. WordPress has checked the list's
	 * nonce before this runs; each product needs edit_post, as on its own tab.
	 * Nothing is sent to eBay.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action name.
	 * @param int[]  $post_ids    Products chosen.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( 0 !== strpos( (string) $action, self::BULK_PREFIX ) ) {
			return $redirect_to;
		}

		$choice = substr( $action, strlen( self::BULK_PREFIX ) );
		if ( 'rules' !== $choice && 'none' !== $choice && ! ( ctype_digit( $choice ) && TCGiant_Sync_Templates::is_usable( (int) $choice ) ) ) {
			return $redirect_to;
		}

		$changed = 0;
		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			if ( 'rules' === $choice ) {
				delete_post_meta( $post_id, TCGiant_Sync_Templates::OVERRIDE_META );
			} else {
				update_post_meta( $post_id, TCGiant_Sync_Templates::OVERRIDE_META, $choice );
			}
			$changed++;
		}

		return add_query_arg( array(
			'tcgiant_tpl_set'  => $changed,
			'tcgiant_tpl_name' => 'rules' === $choice || 'none' === $choice ? $choice : (int) $choice,
			'post_type'        => 'product',
		), $redirect_to );
	}

	/**
	 * What the bulk action did.
	 */
	public function bulk_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		// Only for someone who can set a product's template: the address
		// names any template, drafts too.
		if ( ! isset( $_REQUEST['tcgiant_tpl_set'] ) || ! current_user_can( 'edit_products' ) ) {
			return;
		}
		$count = (int) $_REQUEST['tcgiant_tpl_set'];
		$name  = sanitize_key( wp_unslash( $_REQUEST['tcgiant_tpl_name'] ?? '' ) );
		// phpcs:enable

		if ( 'rules' === $name ) {
			$label = __( 'follow the rules', 'tcgiant-sync' );
		} elseif ( 'none' === $name ) {
			$label = __( 'None (plain description)', 'tcgiant-sync' );
		} else {
			$tpl   = TCGiant_Sync_Templates::get( (int) $name );
			$label = $tpl ? $tpl['name'] : '';
		}

		echo '<div class="notice notice-success is-dismissible"><p>';
		printf(
			/* translators: 1: number of products, 2: template name */
			esc_html( _n( '%1$d product set to eBay template: %2$s.', '%1$d products set to eBay template: %2$s.', $count, 'tcgiant-sync' ) ),
			(int) $count,
			esc_html( $label )
		);
		echo ' ';
		esc_html_e( 'Nothing has been sent to eBay: each listing gets its template on its next push.', 'tcgiant-sync' );
		echo '</p></div>';
	}

	/**
	 * What changed between two bodies, line by line, for the Versions drawer.
	 *
	 * The longest common run of lines, so a line moved or edited reads as one
	 * line out and one in. Bodies too long to compare that way are shown whole,
	 * as removed and added.
	 *
	 * @param string $old Earlier body.
	 * @param string $new Later body.
	 * @return array[] Each array( 'add'|'del'|'same', line ).
	 */
	public static function line_diff( $old, $new ) {
		$a = explode( "\n", str_replace( "\r\n", "\n", (string) $old ) );
		$b = explode( "\n", str_replace( "\r\n", "\n", (string) $new ) );
		$n = count( $a );
		$m = count( $b );

		if ( $n * $m > 250000 ) {
			$out = array();
			foreach ( $a as $line ) {
				$out[] = array( 'del', $line );
			}
			foreach ( $b as $line ) {
				$out[] = array( 'add', $line );
			}
			return $out;
		}

		// Lengths of the longest common runs from each pair of lines onwards.
		$len = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$len[ $i ][ $j ] = $a[ $i ] === $b[ $j ] ? $len[ $i + 1 ][ $j + 1 ] + 1 : max( $len[ $i + 1 ][ $j ], $len[ $i ][ $j + 1 ] );
			}
		}

		$out = array();
		$i   = 0;
		$j   = 0;
		while ( $i < $n && $j < $m ) {
			if ( $a[ $i ] === $b[ $j ] ) {
				$out[] = array( 'same', $a[ $i ] );
				$i++;
				$j++;
			} elseif ( $len[ $i + 1 ][ $j ] >= $len[ $i ][ $j + 1 ] ) {
				$out[] = array( 'del', $a[ $i++ ] );
			} else {
				$out[] = array( 'add', $b[ $j++ ] );
			}
		}
		for ( ; $i < $n; $i++ ) {
			$out[] = array( 'del', $a[ $i ] );
		}
		for ( ; $j < $m; $j++ ) {
			$out[] = array( 'add', $b[ $j ] );
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Switching from WP-Lister
	// -------------------------------------------------------------------------

	/** Per user: the WP-Lister notice was dismissed. */
	const WPLISTER_DISMISSED = 'tcgiant_wplister_notice_dismissed';

	/**
	 * Convert a WP-Lister template: an uploaded zip, read in memory; one of
	 * this site's own WP-Lister templates; or HTML and CSS pasted in. Into a
	 * draft, with a report.
	 */
	public function handle_convert() {
		check_admin_referer( 'tcgiant_tpl_convert' );
		self::must_manage();

		$from  = sanitize_key( wp_unslash( $_POST['source'] ?? '' ) );
		$file  = isset( $_FILES['wplister_zip'] ) && is_array( $_FILES['wplister_zip'] ) ? $_FILES['wplister_zip'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$error = isset( $file['error'] ) ? (int) $file['error'] : ( empty( $file['tmp_name'] ) ? UPLOAD_ERR_NO_FILE : UPLOAD_ERR_OK );

		// A zip PHP refused as too large is said, whichever radio is ticked.
		if ( in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			self::notice( 'error', TCGiant_Sync_WPLister_Converter::too_large_message() );
			self::back( self::url( 'convert' ) );
		}

		// The radio says where from. When what it names was left empty, the
		// one other source filled in is used - a file chosen, or HTML pasted;
		// the folder list always posts its first folder, so it is never taken
		// for one filled in. With none, or two, it asks.
		$has_file  = UPLOAD_ERR_OK === $error && ! empty( $file['tmp_name'] );
		$has_paste = '' !== trim( (string) wp_unslash( $_POST['paste_html'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 'upload' === $from && UPLOAD_ERR_NO_FILE === $error && $has_paste ) {
			$from = 'paste';
		} elseif ( 'paste' === $from && ! $has_paste && $has_file ) {
			$from = 'upload';
		} elseif ( 'installed' === $from && ( $has_file || $has_paste ) ) {
			self::notice( 'error', __( 'A template on this site is ticked, but a zip was chosen or HTML pasted as well. Leave only the one to convert, and convert again.', 'tcgiant-sync' ) );
			self::back( self::url( 'convert' ) );
		}

		if ( 'upload' === $from ) {
			if ( ! $has_file || ! is_uploaded_file( $file['tmp_name'] ) ) {
				self::notice( 'error', UPLOAD_ERR_NO_FILE === $error || UPLOAD_ERR_OK === $error
					? __( 'Choose the .zip that WP-Lister\'s Templates page downloads.', 'tcgiant-sync' )
					: __( 'The zip did not upload. Try again, or paste the template\'s HTML and CSS instead.', 'tcgiant-sync' ) );
				self::back( self::url( 'convert' ) );
			}
			if ( (int) $file['size'] > TCGiant_Sync_WPLister_Converter::MAX_ZIP_BYTES ) {
				self::notice( 'error', TCGiant_Sync_WPLister_Converter::too_large_message() );
				self::back( self::url( 'convert' ) );
			}
			$source = TCGiant_Sync_WPLister_Converter::read_zip( $file['tmp_name'] );
		} elseif ( 'installed' === $from ) {
			// As posted: read_installed() takes only a folder on its own list,
			// by exact name, and sanitize_file_name() would change "My Shop".
			$source = TCGiant_Sync_WPLister_Converter::read_installed( (string) wp_unslash( $_POST['installed'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		} elseif ( 'paste' === $from && ! $has_paste ) {
			$source = new WP_Error( 'empty_paste', __( 'Paste the template\'s template.html first: there is nothing to convert.', 'tcgiant-sync' ) );
		} elseif ( 'paste' === $from ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- converted, then cleaned whenever it is rendered.
			$source = TCGiant_Sync_WPLister_Converter::from_paste( (string) wp_unslash( $_POST['paste_html'] ?? '' ), (string) wp_unslash( $_POST['paste_css'] ?? '' ), (string) wp_unslash( $_POST['paste_config'] ?? '' ) );
		} else {
			$source = new WP_Error( 'no_source', __( 'Choose where the WP-Lister template comes from.', 'tcgiant-sync' ) );
		}

		if ( is_wp_error( $source ) ) {
			self::notice( 'error', $source->get_error_message() );
			self::back( self::url( 'convert' ) );
		}

		$saved = TCGiant_Sync_WPLister_Converter::save_draft( $source, get_current_user_id() );
		if ( is_wp_error( $saved ) ) {
			self::notice( 'error', $saved->get_error_message() );
			self::back( self::url( 'convert' ) );
		}

		set_transient( 'tcgiant_tpl_convert_' . get_current_user_id(), $saved, HOUR_IN_SECONDS );
		self::notice( 'success', __( 'Converted, as a draft. Read the report below, then open the draft and preview it with a few products.', 'tcgiant-sync' ) );
		self::back( self::url( 'convert', array( 'report' => 1 ) ) );
	}

	/**
	 * While WP-Lister for eBay is active: both plugins can import the same
	 * eBay orders and push stock, so the shop should turn WP-Lister's off.
	 * For manage_options only, until dismissed by that user.
	 */
	public function wplister_notice() {
		if ( ! class_exists( 'TCGiant_Sync_WPLister_Converter' ) || ! TCGiant_Sync_WPLister_Converter::wplister_active() || ! TCGiant_Sync_WPLister_Converter::wplister_doubles_up() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || get_user_meta( get_current_user_id(), self::WPLISTER_DISMISSED, true ) ) {
			return;
		}

		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=tcgiant_dismiss_wplister_notice' ), 'tcgiant_dismiss_wplister_notice' );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'TCGiant Sync - WP-Lister for eBay is active too', 'tcgiant-sync' ); ?></strong><br>
				<?php esc_html_e( 'Both plugins can import the same eBay orders and push stock to eBay, so orders could arrive twice and stock be pushed twice. In WP-Lister, turn off its order creation and its stock sync before you rely on TCGiant Sync\'s.', 'tcgiant-sync' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( self::url( 'convert' ) ); ?>" class="button"><?php esc_html_e( 'Switching from WP-Lister', 'tcgiant-sync' ); ?></a>
				<a href="<?php echo esc_url( $dismiss ); ?>" style="margin-left:8px;"><?php esc_html_e( 'Do not show this again', 'tcgiant-sync' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * The WP-Lister notice's dismiss link: for this user only.
	 */
	public function handle_dismiss_wplister() {
		check_admin_referer( 'tcgiant_dismiss_wplister_notice' );
		self::must_manage();

		update_user_meta( get_current_user_id(), self::WPLISTER_DISMISSED, 1 );

		$back = wp_get_referer();
		self::back( $back ? $back : admin_url( 'admin.php?page=tcgiant-sync' ) );
	}

	// -------------------------------------------------------------------------
	// Notices and redirects
	// -------------------------------------------------------------------------

	/**
	 * A notice for the next page this user sees.
	 *
	 * @param string $type success or error.
	 * @param string $text Plain text.
	 */
	private static function notice( $type, $text ) {
		set_transient( 'tcgiant_tpl_notice_' . get_current_user_id(), array( 'type' => in_array( $type, array( 'error', 'warning' ), true ) ? $type : 'success', 'text' => (string) $text ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Show, once, the notice left for this user.
	 */
	public static function show_notice() {
		$key    = 'tcgiant_tpl_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['text'] ) );
	}

	/**
	 * Back to a screen.
	 *
	 * @param string $url Where.
	 */
	private static function back( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
