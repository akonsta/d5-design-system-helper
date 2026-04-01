<?php
/**
 * Plugin Name:  D5 Design System Helper
 * Plugin URI:   https://github.com/akonsta/d5-design-system-helper
 * Author:       Andrew Konstantaras and Claude Code
 * Author URI:   https://github.com/akonsta
 * Description:  Manage, export, import, and audit your entire Divi 5 design system — Global Variables (colors, numbers, fonts, images, text, links), Element Presets, Option Group Presets, Layouts, Pages, Theme Customizer settings, and Builder Templates. Requires Divi 5.0+, WordPress 6.2+, and PHP 8.1+. Not affiliated with or endorsed by Elegant Themes.
 * Version:      0.1.2
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  d5-design-system-helper
 * Domain Path:  /languages
 *
 * @package D5DesignSystemHelper
 */

/*
 * =============================================================================
 * WHAT THIS PLUGIN DOES — OVERVIEW FOR DEVELOPERS
 * =============================================================================
 *
 * D5 Design System Helper reads and writes the Divi 5 design system data that
 * Divi stores in the WordPress database (wp_options and wp_posts). It provides
 * an admin UI with tabs: Manage, Export, Import, Analysis, Style Guide, and Snapshots.
 *
 * SUPPORTED DATA TYPES AND WHERE THEY LIVE IN THE DATABASE
 * ---------------------------------------------------------
 * Divi 5 organises its design system in a hierarchy, from the most atomic to
 * the most complex. This plugin handles all of them:
 *
 *   1. Global Variables         — wp_options key: et_divi_global_variables
 *      Six types: colors, numbers, fonts, images, text, links.
 *      Colors also appear as "Global Colors" under the legacy wp_options key
 *      et_divi (alongside system fonts and other theme settings).
 *
 *   2. Element Presets          — wp_options key: et_divi_builder_global_presets_d5
 *      Per-module saved styles. An Element Preset is tied to a specific module
 *      type (e.g. a Button preset only applies to Button modules). Stored under
 *      the "module" sub-key inside the presets option.
 *      Previously called "Module Presets" in older Divi documentation.
 *
 *   3. Option Group Presets     — wp_options key: et_divi_builder_global_presets_d5
 *      Cross-element style bundles (spacing, typography, border, background,
 *      etc.) that can be applied to any element type — sections, rows, columns,
 *      or any module. Stored under the "group" sub-key inside the presets option.
 *
 *   4. Layouts                  — wp_posts: post_type = et_pb_layout
 *      Divi Library items (reusable sections, rows, modules, full-page layouts).
 *
 *   5. Pages                    — wp_posts: post_type = page (with Divi content)
 *      Full WordPress pages built with the Divi builder.
 *
 *   6. Theme Customizer         — wp_options key: theme_mods_Divi
 *      All Divi theme customizer settings (header, footer, sidebar, etc.).
 *
 *   7. Builder Templates        — wp_posts: post_type = et_template
 *      Theme Builder templates (headers, footers, body templates, single-post
 *      templates, etc.).
 *
 *
 * HOW DIVI 5 LINKS DESIGN SYSTEM OBJECTS: THE $variable() REFERENCE SYNTAX
 * --------------------------------------------------------------------------
 * Throughout preset style data and block markup, Divi references variables
 * using a token that looks like this (color example):
 *
 *   $variable({"type":"color","value":{"name":"gcid-primary-color","settings":{}}})$
 *
 * Where "gcid-primary-color" is the unique ID of a Global Color variable. If
 * that color variable defines #2176ff, then every element referencing it renders
 * as #2176ff — and if the variable is updated, all references update everywhere.
 *
 * A color variable can also reference another color variable with an optional
 * opacity modifier (a "derived color"):
 *
 *   $variable({"type":"color","value":{"name":"gcid-s0kqi6v11w","settings":{"opacity":12}}})$
 *
 * This means "use the Black color at 12% opacity."
 *
 * Global Variable IDs use two prefixes:
 *   gcid-  Global Color IDs  (from et_divi and et_divi_global_variables)
 *   gvid-  Global Variable IDs (numbers, fonts, images, text, links — from et_divi_global_variables)
 *
 * Note: some gvid-* IDs are Divi-internal built-ins (e.g. gvid-r41n4b9xo4 for
 * default spacing values). These are baked into Divi itself and will never appear
 * in a variable export — this is expected, not an error.
 *
 *
 * HOW PRESETS ARE REFERENCED INSIDE DIVI BLOCK MARKUP
 * -----------------------------------------------------
 * Divi 5 stores page content as WordPress block editor HTML with embedded JSON
 * attributes. Presets are referenced inside those attributes in two ways:
 *
 *   Element Preset reference:
 *     modulePreset: ["preset-id-here"]
 *
 *   Option Group Preset reference:
 *     groupPreset: {
 *       "slotName": {
 *         "presetId": ["preset-id-here"],
 *         "groupName": "divi/spacing"
 *       }
 *     }
 *
 * The dependency graph of a full Divi page is a Directed Acyclic Graph (DAG):
 * Pages → Layout Blocks → Element Presets → Option Group Presets → Variables.
 *
 *
 * DESIGN DECISIONS
 * ----------------
 * - Excel (.xlsx) import/export: Variables and Presets only. Layouts, Pages,
 *   Theme Customizer, and Builder Templates are JSON-only.
 * - Imports are non-destructive: records absent from the file are never deleted;
 *   new rows in the file are silently skipped (no record creation).
 * - Record matching on import: by ID only. IDs are site-specific (gcid-*, gvid-*),
 *   so cross-site Excel import only works when IDs match (same source site).
 * - A full snapshot of each data type is saved automatically before every write.
 * - No external HTTP calls: the plugin never phones home or loads remote assets.
 *
 *
 * SECURITY NOTES
 * --------------
 * All AJAX endpoints require the manage_options capability (Administrator only).
 * File uploads accept .xlsx files only and are validated via the hidden Config
 * sheet before any data is read or written.
 * All output is escaped with esc_html() / wp_kses_post() as appropriate.
 * Nonces are verified on all form submissions and AJAX requests.
 *
 *
 * COMPATIBILITY
 * -------------
 * WordPress : 6.2+
 * PHP       : 8.1+   (uses named arguments, enums, and readonly properties)
 * Divi      : 5.0+   (reads wp_options keys introduced in Divi 5)
 * This plugin is NOT compatible with Divi 4.x or earlier. The Divi 4 and
 * Divi 5 option schemas are completely different; Divi 4 data cannot be read.
 *
 *
 * THIRD-PARTY LIBRARIES (BUNDLED)
 * --------------------------------
 * PhpSpreadsheet (https://phpspreadsheet.readthedocs.io/) is included in the
 * vendor/ directory. It is used to read and write .xlsx files. It is not
 * loaded until an export or import action is triggered.
 *
 *
 * NOT AFFILIATED WITH ELEGANT THEMES
 * ------------------------------------
 * This plugin is an independent tool and is not affiliated with, endorsed by,
 * or supported by Elegant Themes, Inc. "Divi" is a trademark of Elegant Themes.
 * =============================================================================
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Plugin constants ──────────────────────────────────────────────────────────

/** Absolute path to the plugin directory (with trailing slash). */
if ( ! defined( 'D5DSH_PATH' ) ) {
	define( 'D5DSH_PATH', plugin_dir_path( __FILE__ ) );
}

/** Public URL to the plugin directory (with trailing slash). */
if ( ! defined( 'D5DSH_URL' ) ) {
	define( 'D5DSH_URL', plugin_dir_url( __FILE__ ) );
}

/** Plugin version string. */
if ( ! defined( 'D5DSH_VERSION' ) ) {
	define( 'D5DSH_VERSION', '0.1.2' );
}

/**
 * Debug mode flag — true when the user has enabled debug mode in Settings → Advanced.
 * Set from the d5dsh_settings wp_options entry so it is available to all PHP code
 * without requiring the AdminPage class to be loaded.
 */
if ( ! defined( 'D5DSH_DEBUG' ) ) {
	$_d5dsh_settings = get_option( 'd5dsh_settings', [] );
	define( 'D5DSH_DEBUG', ! empty( $_d5dsh_settings['debug_mode'] ) );
	unset( $_d5dsh_settings );
}

/**
 * Minimum Divi major version required.
 *
 * This plugin reads wp_options keys introduced in Divi 5:
 *   et_divi_global_variables         — Global Variables (colors, numbers, fonts, etc.)
 *   et_divi_builder_global_presets_d5 — Element Presets and Option Group Presets
 *
 * These keys do not exist in Divi 4. The plugin will refuse to activate if the
 * installed version of Divi is older than this.
 */
if ( ! defined( 'D5DSH_MIN_DIVI_VERSION' ) ) {
	define( 'D5DSH_MIN_DIVI_VERSION', '5.0.0' );
}

/** Minimum WordPress version required. */
if ( ! defined( 'D5DSH_MIN_WP_VERSION' ) ) {
	define( 'D5DSH_MIN_WP_VERSION', '6.2' );
}

/** Minimum PHP version required. */
if ( ! defined( 'D5DSH_MIN_PHP_VERSION' ) ) {
	define( 'D5DSH_MIN_PHP_VERSION', '8.1' );
}

// ── Autoloader ────────────────────────────────────────────────────────────────

/**
 * Simple PSR-4-style autoloader for the D5DesignSystemHelper namespace.
 *
 * Maps:
 *   D5DesignSystemHelper\Foo\Bar  →  includes/Foo/Bar.php
 *
 * @param string $class Fully-qualified class name.
 */
spl_autoload_register( function ( string $class ): void {
	$prefix = 'D5DesignSystemHelper\\';
	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file     = D5DSH_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( file_exists( $file ) ) {
		require $file;
	}
} );

// ── Vendor (PhpSpreadsheet) ───────────────────────────────────────────────────

/**
 * PhpSpreadsheet is bundled inside the plugin's vendor/ directory.
 *
 * If this directory is missing, it almost certainly means you downloaded the
 * raw source code from GitHub (via the green "Code" button) rather than the
 * packaged release ZIP from the Releases page. The release ZIP includes the
 * pre-built vendor folder — no Composer installation is required.
 *
 * If you are a developer working from source, run:
 *   composer install --no-dev --optimize-autoloader
 */
$vendor_autoload = D5DSH_PATH . 'vendor/autoload.php';
if ( ! file_exists( $vendor_autoload ) ) {
	add_action( 'admin_notices', function (): void {
		echo '<div class="notice notice-error"><p><strong>D5 Design System Helper:</strong> '
		     . esc_html__(
			     'The plugin is missing required files and cannot run. '
			     . 'Please download the release ZIP from the Releases page on GitHub '
			     . '(not the "Code" button — that downloads source code only). '
			     . 'The release ZIP includes everything needed and requires no additional setup.',
			     'd5-design-system-helper'
		     )
		     . '</p></div>';
	} );
	return;
}
require_once $vendor_autoload;

// ── Update Checker ───────────────────────────────────────────────────────────

/**
 * Initialize the GitHub-based update checker.
 *
 * This must be done early (outside hooks or in plugins_loaded at latest) and
 * only in the admin context. The UpdateChecker class handles the actual
 * integration with the Plugin Update Checker library.
 *
 * Updates are delivered via GitHub Releases. When a new release is published
 * with a version tag (e.g., v0.7.0), WordPress will detect it and show an
 * update notification on the Plugins page.
 */
if ( is_admin() ) {
	\D5DesignSystemHelper\UpdateChecker::init( __FILE__ );
}

// ── Activation / deactivation hooks ──────────────────────────────────────────

/**
 * Plugin activation: checks requirements (WordPress version, PHP version, Divi 5).
 * If any requirement is unmet, activation is blocked and an error is shown.
 * See includes/Plugin.php for the full requirements check.
 */
register_activation_hook( __FILE__, [ 'D5DesignSystemHelper\\Plugin', 'activate' ] );

/**
 * Plugin deactivation: performs any cleanup needed (currently a no-op placeholder).
 * Deactivation does NOT delete data — use Plugins → Delete for full removal.
 */
register_deactivation_hook( __FILE__, [ 'D5DesignSystemHelper\\Plugin', 'deactivate' ] );

// ── Bootstrap ────────────────────────────────────────────────────────────────

/**
 * Initialise the plugin after all plugins are loaded.
 *
 * We hook onto plugins_loaded (not init) so that Plugin::init() can safely
 * check whether the Divi theme or Divi Builder plugin is active before
 * registering admin menus, AJAX handlers, and asset enqueues.
 */
add_action( 'plugins_loaded', function (): void {
	( new \D5DesignSystemHelper\Plugin() )->init();
} );

// ── WP-CLI commands ───────────────────────────────────────────────────────────

/**
 * Register WP-CLI commands when running in CLI context.
 *
 * Command: wp d5dsh security-test --dir=<path> [--out=<path>] [--verbose]
 *
 * Runs a batch of JSON fixture files through the D5DSH importer, records
 * results (status, sanitization log, entry counts, post-import export), and
 * restores the database to its pre-test state between runs.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command(
		'd5dsh security-test',
		\D5DesignSystemHelper\Cli\SecurityTestCommand::class
	);
}
