<?php
/**
 * Uninstall routine for D5 Design System Helper.
 *
 * WordPress executes this file when the plugin is deleted through the
 * WP admin UI (Plugins → Delete). It runs before the plugin directory
 * is removed, so the autoloader is not available — we use $wpdb directly.
 *
 * What this removes:
 *   - All snapshot option keys  (d5dsh_snap_*)
 *   - All importer backup keys  (d5dsh_backup_*)
 *   - All plugin transients     (d5dsh_dry_run_result_*, d5dsh_import_result_*)
 *
 * What this intentionally does NOT remove:
 *   - et_divi_global_variables, et_divi, et_divi_builder_global_presets_d5,
 *     theme_mods_Divi — these are Divi's own option keys; the plugin only
 *     reads and writes them on behalf of the user. Deleting this plugin
 *     should never destroy the user's Divi design system data.
 *
 * @package D5DesignSystemHelper
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete all snapshot and backup options written by this plugin.
// Using LIKE with the plugin-specific prefix is safe — no Divi keys start with 'd5dsh_'.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'd5dsh_snap_' ) . '%'
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'd5dsh_backup_' ) . '%'
	)
);

// Delete any lingering transients (stored in options table as _transient_d5dsh_*).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_d5dsh_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_d5dsh_' ) . '%'
	)
);
