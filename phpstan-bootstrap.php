<?php
/**
 * PHPStan bootstrap — symbol declarations only, never executed at runtime.
 *
 * Two categories of symbol are invisible to static analysis:
 *
 *  1. Plugin constants. They are defined with define() inside the main plugin
 *     file, which PHPStan does not evaluate, so every use reads as undefined.
 *  2. A handful of WordPress core constants that live in
 *     wp-includes/default-constants.php and are set at runtime rather than
 *     declared, so they are absent from wordpress-stubs.
 *
 * @package TCGiant_Sync
 */

// --- Plugin constants (see tcgiant-sync.php) ---
define( 'TCGIANT_SYNC_VERSION', '0.0.0' );
define( 'TCGIANT_SYNC_FILE', __FILE__ );
define( 'TCGIANT_SYNC_PATH', __DIR__ . '/' );
define( 'TCGIANT_SYNC_URL', 'https://example.test/wp-content/plugins/tcgiant-sync/' );
define( 'TCGIANT_SYNC_BASENAME', 'tcgiant-sync/tcgiant-sync.php' );
define( 'TCGIANT_SYNC_IS_STAGING', false );

// --- WordPress core constants set at runtime ---
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MONTH_IN_SECONDS', 2592000 );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );
define( 'OBJECT_K', 'OBJECT_K' );
define( 'WP_DEBUG', false );
