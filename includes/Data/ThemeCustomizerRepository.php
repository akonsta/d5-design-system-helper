<?php
/**
 * Data access for Divi 5 Theme Customizer settings.
 *
 * Reads the theme_mods_Divi wp_options entry which contains all Divi
 * Customizer settings (colors, typography, layout, etc.).
 *
 * Global colors are stored inside et_global_data.global_colors as a nested
 * array and are exported separately to the Global Colors sheet.
 *
 * ## Key prefix categories (mirrors Python tool's CATEGORIES dict)
 *   body_       — Body text styling
 *   header_     — Header area
 *   nav_        — Navigation
 *   footer_     — Footer
 *   et_         — ET-specific (global data, Divi-specific flags)
 *   (other)     — General settings
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ThemeCustomizerRepository
 */
class ThemeCustomizerRepository {

	/** wp_options key for Divi theme mods. */
	const OPTION_KEY = 'theme_mods_Divi';

	/** Prefix for legacy backup keys. */
	const BACKUP_KEY_PREFIX = 'd5dsh_backup_customizer_';

	/** Category prefix → label mapping (for the Settings sheet Category column). */
	const CATEGORIES = [
		'body_'           => 'Body',
		'header_'         => 'Header',
		'nav_'            => 'Navigation',
		'footer_'         => 'Footer',
		'et_divi_'        => 'Divi Options',
		'et_global_'      => 'Global Data',
		'et_'             => 'ET General',
		'background_'     => 'Background',
		'button_'         => 'Button',
		'form_'           => 'Form',
		'menu_'           => 'Menu',
		'mobile_'         => 'Mobile',
		'sidebar_'        => 'Sidebar',
		'tablet_'         => 'Tablet',
	];

	/**
	 * Return the raw theme_mods_Divi option value.
	 *
	 * @return array
	 */
	public function get_raw(): array {
		$raw = get_option( self::OPTION_KEY );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Write a new theme_mods_Divi value.
	 *
	 * @param array $data
	 * @return bool
	 */
	public function save_raw( array $data ): bool {
		return (bool) update_option( self::OPTION_KEY, $data );
	}

	/**
	 * Return a flat list of [category, key, value] records suitable for the
	 * Settings sheet.  Excludes et_global_data (exported separately).
	 *
	 * @return array<int, array{category: string, key: string, value: mixed}>
	 */
	public function get_settings_list(): array {
		$raw  = $this->get_raw();
		$rows = [];

		foreach ( $raw as $key => $value ) {
			if ( $key === 'et_global_data' ) {
				continue; // Exported on Global Colors / Global Variables sheets.
			}
			$rows[] = [
				'category' => $this->categorize( $key ),
				'key'      => $key,
				'value'    => $value,
			];
		}

		// Sort by category then key for a stable export.
		usort( $rows, static function ( array $a, array $b ): int {
			$cat = strcmp( $a['category'], $b['category'] );
			return $cat !== 0 ? $cat : strcmp( $a['key'], $b['key'] );
		} );

		return $rows;
	}

	/**
	 * Return the global_colors sub-array from et_global_data.
	 *
	 * @return array  Keyed by color ID, each entry has {label, color, status}.
	 */
	public function get_global_colors(): array {
		$raw = $this->get_raw();
		return $raw['et_global_data']['global_colors'] ?? [];
	}

	/**
	 * Save a timestamped backup to wp_options (autoload=false).
	 *
	 * @return string Backup option key.
	 */
	public function backup(): string {
		$key = self::BACKUP_KEY_PREFIX . gmdate( 'Ymd_His' );
		add_option( $key, $this->get_raw(), '', 'no' );
		return $key;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Map a settings key to a human-readable category name using CATEGORIES.
	 *
	 * @param string $key
	 * @return string
	 */
	private function categorize( string $key ): string {
		foreach ( self::CATEGORIES as $prefix => $label ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return $label;
			}
		}
		return 'General';
	}
}
