<?php
/**
 * GitHub-based automatic update checker for D5 Design System Helper.
 *
 * Uses the Plugin Update Checker library by Yahnis Elsts to check for new
 * releases on GitHub and enable one-click updates from the WordPress admin.
 *
 * @package D5DesignSystemHelper
 * @see     https://github.com/YahnisElsts/plugin-update-checker
 */

namespace D5DesignSystemHelper;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Initializes the GitHub-based update checker.
 *
 * This class wraps the Plugin Update Checker library and configures it to:
 * - Check the GitHub repository for new releases
 * - Download release assets (pre-built zip files) rather than source archives
 * - Display update notifications in the WordPress Plugins screen
 *
 * The update checker runs automatically on admin page loads (throttled to
 * every 12 hours by default). Users can force an immediate check via the
 * "Check for updates" link on the Plugins page.
 */
class UpdateChecker {

	/**
	 * The update checker instance.
	 *
	 * @var object|null
	 */
	private static ?object $instance = null;

	/**
	 * GitHub repository URL.
	 *
	 * @var string
	 */
	private const GITHUB_REPO = 'https://github.com/akonsta/d5-design-system-helper/';

	/**
	 * Plugin slug (should match the plugin directory name).
	 *
	 * @var string
	 */
	private const PLUGIN_SLUG = 'd5-design-system-helper';

	/**
	 * Initialize the update checker.
	 *
	 * Should be called once from the main plugin file, either at the top level
	 * or during the `plugins_loaded` hook. Must be called after the Composer
	 * autoloader is loaded.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file (__FILE__).
	 */
	public static function init( string $plugin_file ): void {
		// Prevent double initialization.
		if ( self::$instance !== null ) {
			return;
		}

		// Bail if the PUC library isn't available (shouldn't happen if Composer ran).
		if ( ! class_exists( PucFactory::class ) ) {
			return;
		}

		self::$instance = PucFactory::buildUpdateChecker(
			self::GITHUB_REPO,
			$plugin_file,
			self::PLUGIN_SLUG
		);

		// Use release assets (pre-built zip files) instead of GitHub's auto-generated
		// source archives. This is required because the plugin needs the vendor/
		// directory which is not included in source downloads.
		$vcs_api = self::$instance->getVcsApi();
		if ( $vcs_api !== null && method_exists( $vcs_api, 'enableReleaseAssets' ) ) {
			$vcs_api->enableReleaseAssets();
		}
	}

	/**
	 * Get the update checker instance (for debugging/testing).
	 *
	 * @return object|null The PUC instance, or null if not initialized.
	 */
	public static function get_instance(): ?object {
		return self::$instance;
	}
}
