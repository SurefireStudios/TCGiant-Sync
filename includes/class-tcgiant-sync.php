<?php
/**
 * Core Plugin Class
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync class
 */
final class TCGiant_Sync {

	/**
	 * Instance of this class.
	 *
	 * @var TCGiant_Sync
	 */
	private static $_instance = null;

	/**
	 * Main TCGiant_Sync Instance.
	 *
	 * Insures that only one instance of TCGiant_Sync exists in memory at any one
	 * time. Also prevents needing to define globals all over the place.
	 *
	 * @static
	 * @return TCGiant_Sync - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * TCGiant_Sync Constructor.
	 */
	public function __construct() {
		$this->autoloader_init();
		$this->init_hooks();
	}

	/**
	 * Initialize autoloader.
	 */
	private function autoloader_init() {
		spl_autoload_register( array( $this, 'autoload' ) );
	}

	/**
	 * Autoloader.
	 *
	 * @param string $class Class name.
	 */
	public function autoload( $class ) {
		$prefix = 'TCGiant_Sync_';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$class_name = str_replace( $prefix, '', $class );
		$file = strtolower( str_replace( '_', '-', $class_name ) );
		
		$path = TCGIANT_SYNC_PATH . 'includes/class-tcgiant-sync-' . $file . '.php';
		if ( 'admin' === $file ) {
			$path = TCGIANT_SYNC_PATH . 'admin/class-tcgiant-sync-' . $file . '.php';
		}

		if ( file_exists( $path ) ) {
			include_once $path;
		}
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		add_action( 'plugins_loaded', array( $this, 'init_subsystems' ), 20 );
	}

	/**
	 * Initialize subsystems.
	 */
	public function init_subsystems() {
		// Initialize Logger.
		TCGiant_Sync_Logger::instance();

		// Initialize OAuth.
		TCGiant_Sync_OAuth::instance();

		// Initialize API.
		TCGiant_Sync_API::instance();

		// Initialize Admin.
		if ( is_admin() ) {
			TCGiant_Sync_Admin::instance();
		}

		// Initialize License Manager (must be before Importer).
		TCGiant_Sync_License::instance();

		// Initialize Inventory Sync.
		TCGiant_Sync_Inventory::instance();
		
		// Initialize Importer.
		TCGiant_Sync_Importer::instance();

		// Initialize Cron.
		TCGiant_Sync_Cron::instance();

		// Initialize Webhooks.
		TCGiant_Sync_Webhooks::instance();
	}

	/**
	 * Cloning is forbidden.
	 */
	public function __clone() {
		wc_doing_it_wrong( __FUNCTION__, __( 'Cloning is forbidden.', 'tcgiant-sync' ), '1.0.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 */
	public function __wakeup() {
		wc_doing_it_wrong( __FUNCTION__, __( 'Unserializing instances of this class is forbidden.', 'tcgiant-sync' ), '1.0.0' );
	}
}
