<?php
/**
 * PHPUnit bootstrap for D5 Design System Helper.
 *
 * Loads:
 *   1. Composer autoloader (plugin classes + PHPUnit)
 *   2. WordPress function / constant stubs so the plugin classes can be
 *      instantiated without a running WordPress installation.
 *
 * The stubs live in tests/Stubs/WPFunctions.php and are global functions
 * that mirror the WordPress API signatures used by the plugin classes.
 */

declare( strict_types=1 );

// ── Autoloader ───────────────────────────────────────────────────────────────

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	echo "vendor/autoload.php not found.\nRun: composer install\n";
	exit( 1 );
}
require_once $autoload;

// ── WordPress constants ───────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/fake/wp/' );
}
if ( ! defined( 'D5DSH_VERSION' ) ) {
	define( 'D5DSH_VERSION', '0.6.0-test' );
}
if ( ! defined( 'D5DSH_PATH' ) ) {
	define( 'D5DSH_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'D5DSH_URL' ) ) {
	define( 'D5DSH_URL', 'https://example.com/wp-content/plugins/d5-design-system-helper/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
// Enable debug mode so DebugLogger writes to the test log directory.
// Tests that need debug-off behaviour use TestableDebugLogger (overrides is_active()).
if ( ! defined( 'D5DSH_DEBUG' ) ) {
	define( 'D5DSH_DEBUG', true );
}

// ── WordPress function stubs ──────────────────────────────────────────────────

require_once __DIR__ . '/Stubs/WPFunctions.php';

// ── Namespace-scoped function shadows ────────────────────────────────────────
// Must be loaded AFTER WPFunctions.php (globals initialised) and AFTER
// the autoloader (so plugin namespaces exist).

require_once __DIR__ . '/Stubs/NamespaceShadows.php';
