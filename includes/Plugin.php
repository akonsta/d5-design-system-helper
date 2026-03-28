<?php
/**
 * Main plugin bootstrap class.
 *
 * Responsible for:
 *  - Verifying environment requirements (WP version, Divi 5).
 *  - Registering admin menus and AJAX handlers.
 *  - Enqueueing admin assets.
 *
 * ## Divi child theme compatibility
 *
 * The activation hook fires before themes are fully loaded, so ET_CORE_VERSION
 * may not yet be defined at that point when a child theme is in use. To handle
 * this correctly:
 *  - activate() only checks the WordPress version (always available).
 *  - The Divi presence/version check runs at plugins_loaded via init(), where
 *    all theme functions.php files have already executed and ET_CORE_VERSION is
 *    reliably defined regardless of whether Divi is the parent theme, a child
 *    theme, or the Divi Builder plugin.
 *  - detect_divi_version() uses four fallback detection sources so child themes
 *    with non-standard configurations are also covered.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Admin\AdminPage;

/**
 * Class Plugin
 *
 * Entry point for all plugin functionality.
 */
class Plugin {

	// ── Static lifecycle hooks ────────────────────────────────────────────────

	/**
	 * Runs on plugin activation.
	 *
	 * Only checks the WordPress version here. Divi constants (ET_CORE_VERSION)
	 * are not reliably available at activation hook time when a child theme is
	 * active — themes load after the activation hook fires. The Divi check
	 * runs at plugins_loaded (init()) instead.
	 *
	 * @return void
	 */
	public static function activate(): void {
		global $wp_version;
		if ( version_compare( $wp_version, D5DSH_MIN_WP_VERSION, '<' ) ) {
			wp_die(
				'<p>' . esc_html( sprintf(
					/* translators: 1: required version, 2: installed version */
					__( 'D5 Design System Helper requires WordPress %1$s or higher. You are running %2$s.', 'd5-design-system-helper' ),
					D5DSH_MIN_WP_VERSION,
					$wp_version
				) ) . '</p>',
				esc_html__( 'Plugin Activation Error', 'd5-design-system-helper' ),
				[ 'back_link' => true ]
			);
		}
	}

	/**
	 * Runs on plugin deactivation. Currently a no-op but reserved for cleanup.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Nothing to clean up in v1.0.
	}

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Initialise the plugin.
	 *
	 * Called from the `plugins_loaded` hook so all themes and plugins are
	 * fully loaded — ET_CORE_VERSION is available here in all configurations.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! self::check_requirements() ) {
			return;
		}

		// Only load admin-facing code when in the admin area.
		if ( is_admin() ) {
			$admin = new AdminPage();
			$admin->register();
		}

		// ── Pro extension hook ────────────────────────────────────────────────
		// The Pro plugin hooks into this action to register its own components.
		// This action fires after all free plugin components are loaded.
		// Hook: add_action( 'd5dsh_loaded', [ MyProClass::class, 'init' ] );
		do_action( 'd5dsh_loaded', $this );
	}

	// ── Requirements ─────────────────────────────────────────────────────────

	/**
	 * Verify that minimum WordPress and Divi version requirements are met.
	 *
	 * Registers a persistent admin notice listing any unmet requirements.
	 * Returns true only when all requirements are satisfied.
	 *
	 * @return bool True when all requirements are satisfied.
	 */
	private static function check_requirements(): bool {
		global $wp_version;

		$errors = [];

		// WordPress version check.
		if ( version_compare( $wp_version, D5DSH_MIN_WP_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: required version, 2: installed version */
				__( 'D5 Design System Helper requires WordPress %1$s or higher. You are running %2$s.', 'd5-design-system-helper' ),
				D5DSH_MIN_WP_VERSION,
				$wp_version
			);
		}

		// Divi check — uses multiple detection sources to support parent theme,
		// child themes, and the Divi Builder plugin equally.
		$divi_version = self::detect_divi_version();

		if ( $divi_version === null ) {
			$errors[] = __( 'D5 Design System Helper requires the Divi theme (or a Divi child theme) or the Divi Builder plugin to be active.', 'd5-design-system-helper' );
		} elseif ( $divi_version !== 'unknown' && version_compare( $divi_version, D5DSH_MIN_DIVI_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: required version, 2: installed version */
				__( 'D5 Design System Helper requires Divi %1$s or higher. You are running %2$s.', 'd5-design-system-helper' ),
				D5DSH_MIN_DIVI_VERSION,
				$divi_version
			);
		}

		if ( empty( $errors ) ) {
			return true;
		}

		// Register a persistent admin notice listing all unmet requirements.
		add_action( 'admin_notices', function () use ( $errors ): void {
			foreach ( $errors as $msg ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
			}
		} );

		return false;
	}

	/**
	 * Detect the active Divi version from multiple sources.
	 *
	 * Returns:
	 *  - Version string (e.g. '5.0.0') when Divi is active and the version is known.
	 *  - 'unknown'     when Divi appears active but the exact version cannot be read.
	 *                  We allow 'unknown' through rather than blocking, so that
	 *                  unusual configurations (e.g. heavily customised child themes)
	 *                  do not get a false-negative block.
	 *  - null          when Divi does not appear to be active at all.
	 *
	 * Detection sources (checked in priority order):
	 *  1. ET_CORE_VERSION constant  — defined by the Divi parent theme and the
	 *                                  Divi Builder plugin. The most reliable source.
	 *  2. ET_BUILDER_VERSION constant — secondary constant, present in some
	 *                                   configurations.
	 *  3. get_template() === 'Divi'  — the parent theme directory name. Reliably
	 *                                  identifies a Divi child theme even when
	 *                                  constants are not yet defined.
	 *  4. et_divi_global_variables   — if the Divi 5 wp_options key exists, Divi 5
	 *                                  must have been active on this site.
	 *
	 * @return string|null  Version string, 'unknown', or null.
	 */
	private static function detect_divi_version(): ?string {
		// Source 1: ET_CORE_VERSION (most reliable — set by parent theme + builder plugin).
		if ( defined( 'ET_CORE_VERSION' ) ) {
			return ET_CORE_VERSION;
		}

		// Source 2: ET_BUILDER_VERSION fallback.
		if ( defined( 'ET_BUILDER_VERSION' ) ) {
			return ET_BUILDER_VERSION;
		}

		// Source 3: Parent theme directory name is 'Divi' (child theme scenario).
		// get_template() returns the parent theme's folder name, not the child theme's.
		if ( function_exists( 'get_template' ) && strtolower( get_template() ) === 'divi' ) {
			return 'unknown';
		}

		// Source 4: Divi 5 wp_options key exists in the database.
		if ( get_option( 'et_divi_global_variables' ) !== false ) {
			return 'unknown';
		}

		return null; // Divi not detected by any method.
	}
}
