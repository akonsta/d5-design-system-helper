<?php
/**
 * Data access layer for Divi 5 global variables and global colors.
 *
 * ## Storage locations
 *
 * Non-color variables (numbers, fonts, images, strings):
 *   Option key: `et_divi_global_variables`
 *   Format: PHP-serialized associative array keyed by type then by ID.
 *
 *   array(
 *     'numbers' => array(
 *       'gvid-XXXXXXXX' => array(
 *         'id'     => 'gvid-XXXXXXXX',
 *         'label'  => 'My Variable',
 *         'value'  => 'clamp(...)',
 *         'status' => 'active',
 *       ),
 *       ...
 *     ),
 *     'fonts'   => array( ... ),
 *     'images'  => array( ... ),
 *     'strings' => array( ... ),
 *   )
 *
 * Colors:
 *   Option key: `et_divi` (the main Divi settings array)
 *   Path: et_divi['et_global_data']['global_colors']
 *   Format: associative array keyed by gcid-xxx, each entry:
 *     array(
 *       'id'          => 'gcid-XXXXXXXX',
 *       'label'       => 'Primary Color',
 *       'color'       => '#hex or $variable(...)$',
 *       'order'       => int,
 *       'status'      => 'active',
 *       'lastUpdated' => 'ISO8601',
 *       'folder'      => '',
 *       'usedInPosts' => array(),
 *     )
 *
 * NOTE: Color entries use 'color' as the value field (not 'value').
 *
 * System fonts (Divi built-ins, cannot be deleted or renamed):
 *   Option key: `et_divi` (the main Divi settings array)
 *   Keys: et_divi['heading_font'] and et_divi['body_font']
 *   Format: plain string (font family name, e.g. 'Fraunces')
 *   Synthesized IDs: '--et_global_heading_font' and '--et_global_body_font'
 *   Confirmed on a live Divi 5 site (see SESSION_LOG.md).
 *
 * NOTE: System font IDs/labels are fixed and cannot be changed. Only the
 *       value (font family) is editable. On import, only 'value' is written back.
 *
 * System colors (Divi built-ins, cannot be deleted or renamed):
 *   Option key: `et_divi` (the main Divi settings array)
 *   Keys: et_divi['accent_color'], et_divi['secondary_accent_color'],
 *         et_divi['header_color'], et_divi['font_color']
 *   Format: plain hex string (e.g. '#0000ff')
 *   Synthesized IDs: 'gcid-primary-color', 'gcid-secondary-color',
 *                    'gcid-heading-color', 'gcid-body-color'
 *   Confirmed on a live Divi 5 site (see SESSION_LOG.md).
 *
 * NOTE: System color IDs/labels are fixed. Only the hex value is editable.
 *       On import, only 'value' is written back to the et_divi key.
 *
 * ## Normalised format (used internally and by the exporter/importer)
 *
 * We flatten all types into a plain list of variable records:
 *
 *   array(
 *     array(
 *       'id'       => string,
 *       'label'    => string,
 *       'value'    => string,   // for colors: the 'color' field
 *       'type'     => string,   // 'colors'|'numbers'|'fonts'|'images'|'strings'
 *       'status'   => string,   // 'active'|'archived'|'inactive'
 *       'order'    => int,      // explicit order field or 1-based insertion position
 *       'system'   => bool,     // true for Divi built-in variables (id/label read-only)
 *       'hidden'   => bool,     // true for palette-only colors (hidden from main UI)
 *     ),
 *     ...
 *   )
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Data;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class VarsRepository
 *
 * Single responsibility: read/write Divi 5 global variables from/to the database.
 */
class VarsRepository {

	/**
	 * The wp_options key that holds Divi 5 global variables (numbers, fonts, images, strings).
	 * Confirmed against a live Divi 5 database (see SESSION_LOG.md).
	 */
	const OPTION_KEY = 'et_divi_global_variables';

	/**
	 * The wp_options key that holds global colors via et_global_data.global_colors.
	 * Colors are NOT in et_divi_global_variables — they live in the main et_divi option.
	 */
	const COLORS_OPTION_KEY = 'et_divi';

	/**
	 * The sub-key path within et_divi for global data.
	 */
	const COLORS_DATA_KEY = 'et_global_data';

	/**
	 * The sub-key within et_global_data for the colors dict.
	 */
	const COLORS_COLORS_KEY = 'global_colors';

	/**
	 * System font variables built into Divi 5.
	 * These are stored as plain strings in et_divi (not in et_divi_global_variables).
	 * Their IDs and labels are fixed; only the font family value is editable.
	 *
	 * Map of synthesized CSS-property ID => [ et_divi_key, display_label ]
	 * Confirmed on a live Divi 5 site: et_divi['heading_font'] and et_divi['body_font'].
	 */
	const SYSTEM_FONTS = [
		'--et_global_heading_font' => [ 'heading_font', 'Heading' ],
		'--et_global_body_font'    => [ 'body_font',    'Body'    ],
	];

	/**
	 * System color variables built into Divi 5.
	 * These are stored as plain hex strings in et_divi (not in global_colors).
	 * Their IDs and labels are fixed; only the hex value is editable.
	 *
	 * Map of synthesized gcid-xxx ID => [ et_divi_key, display_label, order ]
	 * Confirmed on a live Divi 5 site: accent_color, secondary_accent_color,
	 * header_color, font_color, link_color.
	 *
	 * Note: Divi core's Portability.php $excluded_colors only lists the first four
	 * gcids (those cannot be deleted). link_color is a Customizer-only setting that
	 * does not have a gcid in the global-colors system, so we synthesize one.
	 */
	const SYSTEM_COLORS = [
		'gcid-primary-color'   => [ 'accent_color',           'Primary Color',      1 ],
		'gcid-secondary-color' => [ 'secondary_accent_color', 'Secondary Color',    2 ],
		'gcid-heading-color'   => [ 'header_color',           'Heading Text Color', 3 ],
		'gcid-body-color'      => [ 'font_color',             'Body Text Color',    4 ],
		'gcid-link-color'      => [ 'link_color',             'Link Color',         5 ],
	];

	/**
	 * Prefix used for backup wp_options keys.
	 * Format: d5dsh_backup_vars_YYYYMMDD_HHMMSS
	 */
	const BACKUP_KEY_PREFIX = 'd5dsh_backup_vars_';

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Read all global variables, global colors, and system fonts from the database
	 * and return them as a normalised flat list:
	 *   colors first (sorted by order), then numbers, fonts (system first, then user), images, strings.
	 *
	 * Returns an empty array if all option sources are empty or malformed.
	 *
	 * @return array<int, array<string, mixed>> List of variable records.
	 */
	public function get_all(): array {
		$vars_raw        = get_option( self::OPTION_KEY );
		$colors_raw      = $this->get_raw_colors();
		$system_fonts    = $this->get_raw_system_fonts();
		$system_colors   = $this->get_raw_system_colors();

		$vars_array = ( is_array( $vars_raw ) && ! empty( $vars_raw ) ) ? $vars_raw : [];

		return $this->normalize( $vars_array, $colors_raw, $system_fonts, $system_colors );
	}

	/**
	 * Return the raw (un-normalised) non-color variables array exactly as stored.
	 *
	 * Used by the importer when computing diffs and when taking backups.
	 *
	 * @return array<string, mixed>
	 */
	public function get_raw(): array {
		$raw = get_option( self::OPTION_KEY );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Return the raw global_colors dict from et_divi[et_global_data][global_colors].
	 *
	 * @return array<string, mixed> Dict keyed by gcid-xxx, or empty array.
	 */
	public function get_raw_colors(): array {
		$et_divi = get_option( self::COLORS_OPTION_KEY );
		if ( ! is_array( $et_divi ) ) {
			return [];
		}
		$global_data = $et_divi[ self::COLORS_DATA_KEY ] ?? [];
		if ( ! is_array( $global_data ) ) {
			return [];
		}
		$colors = $global_data[ self::COLORS_COLORS_KEY ] ?? [];
		return is_array( $colors ) ? $colors : [];
	}

	/**
	 * Return the system font values from et_divi as a simple map:
	 *   [ '--et_global_heading_font' => 'Fraunces', '--et_global_body_font' => 'Manrope' ]
	 *
	 * Returns an empty array if et_divi is not set.
	 *
	 * @return array<string, string> Map of synthesized ID => font family string.
	 */
	public function get_raw_system_fonts(): array {
		$et_divi = get_option( self::COLORS_OPTION_KEY );
		if ( ! is_array( $et_divi ) ) {
			return [];
		}
		$result = [];
		foreach ( self::SYSTEM_FONTS as $id => [ $key, ] ) {
			if ( isset( $et_divi[ $key ] ) ) {
				$result[ $id ] = (string) $et_divi[ $key ];
			}
		}
		return $result;
	}

	/**
	 * Write updated system font values back to et_divi.
	 *
	 * Only the font family value is written — IDs and labels are fixed.
	 * Preserves all other et_divi keys.
	 *
	 * @param array<string, string> $system_fonts Map of synthesized ID => font family string.
	 * @return bool True on success.
	 */
	public function save_raw_system_fonts( array $system_fonts ): bool {
		$et_divi = get_option( self::COLORS_OPTION_KEY );
		if ( ! is_array( $et_divi ) ) {
			$et_divi = [];
		}
		foreach ( self::SYSTEM_FONTS as $id => [ $key, ] ) {
			if ( array_key_exists( $id, $system_fonts ) ) {
				$et_divi[ $key ] = $system_fonts[ $id ];
			}
		}
		return update_option( self::COLORS_OPTION_KEY, $et_divi );
	}

	/**
	 * Return the system color values from et_divi as a simple map:
	 *   [ 'gcid-primary-color' => '#0000ff', 'gcid-secondary-color' => '#91209c', ... ]
	 *
	 * Returns an empty array if et_divi is not set.
	 *
	 * @return array<string, string> Map of synthesized ID => hex color string.
	 */
	public function get_raw_system_colors(): array {
		$et_divi = get_option( self::COLORS_OPTION_KEY );
		if ( ! is_array( $et_divi ) ) {
			return [];
		}
		$result = [];
		foreach ( self::SYSTEM_COLORS as $id => [ $key, ] ) {
			if ( isset( $et_divi[ $key ] ) ) {
				$result[ $id ] = (string) $et_divi[ $key ];
			}
		}
		return $result;
	}

	/**
	 * Scan et_divi for colour-valued keys that are not in SYSTEM_COLORS.
	 *
	 * This acts as a forward-compatibility probe: if Divi adds new system-level
	 * colour settings in a future release, this method will surface them so the
	 * plugin can be updated. The return value is written to the export Info sheet.
	 *
	 * A key is considered an "unknown colour" when:
	 *   - Its value matches a CSS colour pattern (#hex, rgb(), rgba())
	 *   - It is not already in SYSTEM_COLORS
	 *   - It is not a known non-colour et_divi key (font sizes, font names, etc.)
	 *
	 * @return array<string, string> Map of et_divi_key => colour_value, may be empty.
	 */
	public function detect_unknown_system_colors(): array {
		$et_divi = get_option( self::COLORS_OPTION_KEY );
		if ( ! is_array( $et_divi ) ) {
			return [];
		}

		// Keys we already handle — exclude from the unknown list.
		$known_keys = array_merge(
			array_column( array_values( self::SYSTEM_COLORS ), 0 ),
			array_column( array_values( self::SYSTEM_FONTS ),  0 )
		);

		// Non-colour et_divi keys we know about (font sizes, weights, etc.)
		// Expand this list if Divi adds new scalar settings.
		$non_colour_patterns = [
			'/_size$/', '/_height$/', '/_weight$/', '/_style$/',
			'/^et_global_data$/', '/^et_pb_/', '/^et_divi_/',
		];

		$colour_pattern = '/^(#[0-9a-fA-F]{3,8}|rgba?\s*\()/i';
		$unknown        = [];

		foreach ( $et_divi as $key => $val ) {
			if ( ! is_string( $val ) ) { continue; }
			if ( in_array( $key, $known_keys, true ) ) { continue; }
			$skip = false;
			foreach ( $non_colour_patterns as $pat ) {
				if ( preg_match( $pat, $key ) ) { $skip = true; break; }
			}
			if ( $skip ) { continue; }
			if ( preg_match( $colour_pattern, trim( $val ) ) ) {
				$unknown[ $key ] = $val;
			}
		}

		return $unknown;
	}

	/**
	 * Write updated system color values back to et_divi.
	 *
	 * Only the hex value is written — IDs and labels are fixed.
	 * Preserves all other et_divi keys.
	 *
	 * @param array<string, string> $system_colors Map of synthesized ID => hex color string.
	 * @return bool True on success.
	 */
	public function save_raw_system_colors( array $system_colors ): bool {
		$et_divi = get_option( self::COLORS_OPTION_KEY );
		if ( ! is_array( $et_divi ) ) {
			$et_divi = [];
		}
		foreach ( self::SYSTEM_COLORS as $id => [ $key, ] ) {
			if ( array_key_exists( $id, $system_colors ) ) {
				$et_divi[ $key ] = $system_colors[ $id ];
			}
		}
		return update_option( self::COLORS_OPTION_KEY, $et_divi );
	}

	/**
	 * Persist an updated non-color variables array back to the database.
	 *
	 * The caller is responsible for passing data in the same nested format
	 * that the DB stores (keyed by type then by ID).
	 *
	 * @param array<string, mixed> $data Nested variable data to save.
	 * @return bool True on success, false on failure.
	 */
	public function save_raw( array $data ): bool {
		return update_option( self::OPTION_KEY, $data );
	}

	/**
	 * Persist an updated global_colors dict back to et_divi[et_global_data][global_colors].
	 *
	 * @param array<string, mixed> $colors Dict keyed by gcid-xxx.
	 * @return bool True on success, false on failure.
	 */
	public function save_raw_colors( array $colors ): bool {
		$et_divi = get_option( self::COLORS_OPTION_KEY );
		if ( ! is_array( $et_divi ) ) {
			$et_divi = [];
		}
		if ( ! isset( $et_divi[ self::COLORS_DATA_KEY ] ) || ! is_array( $et_divi[ self::COLORS_DATA_KEY ] ) ) {
			$et_divi[ self::COLORS_DATA_KEY ] = [];
		}
		$et_divi[ self::COLORS_DATA_KEY ][ self::COLORS_COLORS_KEY ] = $colors;
		return update_option( self::COLORS_OPTION_KEY, $et_divi );
	}

	/**
	 * Take a snapshot of the current data (both variables and colors) and
	 * persist it under a timestamped backup key. Returns the backup option key name.
	 *
	 * Backups are stored as plain PHP arrays in wp_options and are NOT
	 * autoloaded to avoid bloating the options cache.
	 *
	 * @return string The wp_options key under which the backup was saved.
	 */
	public function backup(): string {
		$key = self::BACKUP_KEY_PREFIX . gmdate( 'Ymd_His' );
		$current = [
			'vars'          => $this->get_raw(),
			'colors'        => $this->get_raw_colors(),
			'system_fonts'  => $this->get_raw_system_fonts(),
			'system_colors' => $this->get_raw_system_colors(),
		];
		add_option( $key, $current, '', false ); // 'no' autoload.
		return $key;
	}

	/**
	 * List all backup keys stored in wp_options.
	 *
	 * @return string[] Array of backup option key names, newest first.
	 */
	public function list_backups(): array {
		global $wpdb;

		$prefix = self::BACKUP_KEY_PREFIX;
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name DESC",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		return is_array( $results ) ? $results : [];
	}

	// ── Normalization ─────────────────────────────────────────────────────────

	/**
	 * Palette color labels that are treated as "hidden" in the Colors export.
	 *
	 * These are Divi's built-in palette color slots (Primary Qual 1–5,
	 * Secondary Qual 1–5, Quant Color 1–5). They appear in global_colors but
	 * are not user-facing variables — they back the colour palette UI.
	 * Detection is by label pattern because no DB flag distinguishes them.
	 */
	private const HIDDEN_COLOR_PATTERN = '/^(Primary Qual|Secondary Qual|Quant Color)\s+\d+$/i';

	/**
	 * Flatten the DB structures into the normalised flat list format.
	 *
	 * Colors come from et_divi[et_global_data][global_colors] (dict keyed by gcid-xxx,
	 * value field is 'color'). System fonts come from et_divi['heading_font'] /
	 * et_divi['body_font'] and are prepended before user-created font entries.
	 * All other types come from et_divi_global_variables (nested by type then by ID,
	 * value field is 'value').
	 *
	 * Output order: colors (system first, then visible user colors sorted by order,
	 * then hidden palette colors sorted by order), then numbers, then fonts
	 * (system first, then user), then images, then strings, then links.
	 *
	 * System entries carry 'system' => true to signal that id/label are read-only.
	 * Hidden entries carry 'hidden' => true to signal palette-only colors.
	 *
	 * @param array<string, mixed>  $vars_raw      Raw et_divi_global_variables option value.
	 * @param array<string, mixed>  $colors_raw    Raw global_colors dict from et_divi.
	 * @param array<string, string> $system_fonts  Map of synthesized ID => font family string.
	 * @param array<string, string> $system_colors Map of synthesized ID => hex color string.
	 * @return array<int, array<string, mixed>>
	 */
	public function normalize( array $vars_raw, array $colors_raw = [], array $system_fonts = [], array $system_colors = [] ): array {
		$flat = [];

		// ── System colors (from et_divi top-level keys) — prepended first ─────
		foreach ( self::SYSTEM_COLORS as $sys_id => [ , $sys_label, $sys_order ] ) {
			if ( isset( $system_colors[ $sys_id ] ) ) {
				$flat[] = [
					'id'     => $sys_id,
					'label'  => $sys_label,
					'value'  => $system_colors[ $sys_id ],
					'type'   => 'colors',
					'status' => 'active',
					'order'  => $sys_order,
					'system' => true,
					'hidden' => false,
				];
			}
		}

		// ── User colors (from et_divi[et_global_data][global_colors]) ─────────
		$visible_colors = [];
		$hidden_colors  = [];
		foreach ( $colors_raw as $id => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$label      = (string) ( $entry['label'] ?? '' );
			$is_hidden  = (bool) preg_match( self::HIDDEN_COLOR_PATTERN, $label );
			$record     = [
				'id'     => (string) ( $entry['id']    ?? $id ),
				'label'  => $label,
				'value'  => (string) ( $entry['color'] ?? '' ), // 'color' field, not 'value'
				'type'   => 'colors',
				'status' => (string) ( $entry['status'] ?? 'active' ),
				'order'  => isset( $entry['order'] ) ? (int) $entry['order'] : null,
				'system' => false,
				'hidden' => $is_hidden,
			];
			if ( $is_hidden ) {
				$hidden_colors[]  = $record;
			} else {
				$visible_colors[] = $record;
			}
		}
		// Sort each group by the explicit order field, hidden colors go last.
		usort( $visible_colors, fn( $a, $b ) => $a['order'] <=> $b['order'] );
		usort( $hidden_colors,  fn( $a, $b ) => $a['order'] <=> $b['order'] );
		$flat = array_merge( $flat, $visible_colors, $hidden_colors );

		// ── Non-color variables (from et_divi_global_variables) ──────────────
		$type_order  = [ 'numbers', 'fonts', 'images', 'strings', 'links' ];
		$extra_types = array_diff( array_keys( $vars_raw ), $type_order );
		$all_types   = array_merge( $type_order, $extra_types );

		foreach ( $all_types as $type ) {
			// For fonts, prepend system font entries before user-created ones.
			if ( $type === 'fonts' && ! empty( $system_fonts ) ) {
				$sys_order = 1;
				foreach ( self::SYSTEM_FONTS as $sys_id => [ , $sys_label ] ) {
					if ( isset( $system_fonts[ $sys_id ] ) ) {
						$flat[] = [
							'id'     => $sys_id,
							'label'  => $sys_label,
							'value'  => $system_fonts[ $sys_id ],
							'type'   => 'fonts',
							'status' => 'active',
							'order'  => $sys_order,
							'system' => true,
						];
						$sys_order++;
					}
				}
			}

			if ( ! isset( $vars_raw[ $type ] ) || ! is_array( $vars_raw[ $type ] ) ) {
				continue;
			}
			$position = 1;
			foreach ( $vars_raw[ $type ] as $id => $entry ) {
				$flat[] = [
					'id'     => (string) ( $entry['id']     ?? $id ),
					'label'  => (string) ( $entry['label']  ?? '' ),
					'value'  => (string) ( $entry['value']  ?? '' ),
					'type'   => $type,
					'status' => (string) ( $entry['status'] ?? 'active' ),
					'order'  => $position,
					'system' => false,
				];
				$position++;
			}
		}

		return $flat;
	}

	/**
	 * Convert a flat normalised list back into the nested DB format for non-color variables.
	 *
	 * Skips color entries — colors are handled by denormalize_colors().
	 *
	 * @param array<int, array<string, mixed>> $flat Normalised variable records.
	 * @return array<string, array<string, array<string, string>>> Nested DB format for et_divi_global_variables.
	 */
	public function denormalize( array $flat ): array {
		$nested = [];

		$system_font_ids  = array_keys( self::SYSTEM_FONTS );

		foreach ( $flat as $entry ) {
			$type = $entry['type'] ?? 'numbers';
			$id   = $entry['id'] ?? '';

			// Skip colors (handled by denormalize_colors / save_raw_system_colors)
			// and system fonts (handled by save_raw_system_fonts).
			if ( ! $id || $type === 'colors' || in_array( $id, $system_font_ids, true ) ) {
				continue;
			}

			$nested[ $type ][ $id ] = [
				'id'     => $id,
				'label'  => $entry['label']  ?? '',
				'value'  => $entry['value']  ?? '',
				'status' => $entry['status'] ?? 'active',
			];
		}

		return $nested;
	}

	/**
	 * Extract system font values from a flat normalised list.
	 *
	 * Returns a map suitable for passing to save_raw_system_fonts():
	 *   [ '--et_global_heading_font' => 'Fraunces', ... ]
	 *
	 * Only entries whose id is in SYSTEM_FONTS are included.
	 *
	 * @param array<int, array<string, mixed>> $flat Normalised variable records.
	 * @return array<string, string> Map of synthesized ID => font family string.
	 */
	public function denormalize_system_fonts( array $flat ): array {
		$system_ids = array_keys( self::SYSTEM_FONTS );
		$result     = [];
		foreach ( $flat as $entry ) {
			$id = $entry['id'] ?? '';
			if ( in_array( $id, $system_ids, true ) ) {
				$result[ $id ] = (string) ( $entry['value'] ?? '' );
			}
		}
		return $result;
	}

	/**
	 * Extract system color values from a flat normalised list.
	 *
	 * Returns a map suitable for passing to save_raw_system_colors():
	 *   [ 'gcid-primary-color' => '#0000ff', ... ]
	 *
	 * Only entries whose id is in SYSTEM_COLORS are included.
	 *
	 * @param array<int, array<string, mixed>> $flat Normalised variable records.
	 * @return array<string, string> Map of synthesized ID => hex color string.
	 */
	public function denormalize_system_colors( array $flat ): array {
		$system_ids = array_keys( self::SYSTEM_COLORS );
		$result     = [];
		foreach ( $flat as $entry ) {
			$id = $entry['id'] ?? '';
			if ( in_array( $id, $system_ids, true ) ) {
				$result[ $id ] = (string) ( $entry['value'] ?? '' );
			}
		}
		return $result;
	}

	/**
	 * Convert a flat normalised list back into the global_colors dict format.
	 *
	 * Skips system color entries (they live in et_divi top-level keys, not global_colors).
	 * Merges updated editable fields (label, color/value, status, order) back into
	 * the existing raw color entries to preserve non-editable fields
	 * (lastUpdated, folder, usedInPosts).
	 *
	 * @param array<int, array<string, mixed>>  $flat      Normalised variable records (all types).
	 * @param array<string, mixed>              $raw_colors Existing raw global_colors dict.
	 * @return array<string, mixed> Updated global_colors dict keyed by gcid-xxx.
	 */
	public function denormalize_colors( array $flat, array $raw_colors ): array {
		$result         = $raw_colors; // Start from existing to preserve non-editable fields.
		$system_color_ids = array_keys( self::SYSTEM_COLORS );

		foreach ( $flat as $entry ) {
			if ( ( $entry['type'] ?? '' ) !== 'colors' ) {
				continue;
			}
			$id = $entry['id'] ?? '';
			if ( ! $id ) {
				continue;
			}
			// Skip system colors — they are not stored in global_colors.
			if ( in_array( $id, $system_color_ids, true ) ) {
				continue;
			}
			// Merge editable fields into the existing entry.
			$existing = $result[ $id ] ?? [ 'id' => $id ];
			$existing['id']     = $id;
			$existing['label']  = $entry['label']  ?? '';
			$existing['color']  = $entry['value']  ?? ''; // normalised 'value' → DB 'color'
			$existing['status'] = $entry['status'] ?? 'active';
			if ( isset( $entry['order'] ) ) {
				$existing['order'] = $entry['order'];
			}
			$result[ $id ] = $existing;
		}

		return $result;
	}
}
