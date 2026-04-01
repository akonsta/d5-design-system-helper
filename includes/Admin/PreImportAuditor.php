<?php
/**
 * PreImportAuditor — runs a five-check audit against a staged import file
 * before the user commits the import.
 *
 * All checks are read-only: no data is written to the database.
 *
 * ## Report shape (matches AuditEngine output for XLSX reuse)
 *
 *   [
 *     'errors'     => [ ['check' => string, 'items' => [...]], ... ],
 *     'warnings'   => [ ['check' => string, 'items' => [...]], ... ],
 *     'advisories' => [ ['check' => string, 'items' => [...]], ... ],
 *     'meta'       => [
 *       'audit_target'   => 'pre_import',
 *       'filename'       => string,
 *       'file_type'      => string,   // 'vars' | 'presets' | 'et_native' | …
 *       'file_vars'      => int,
 *       'file_presets'   => int,
 *       'site_vars'      => int,
 *       'site_presets'   => int,
 *       'ran_at'         => string,   // UTC
 *       'is_full'        => false,    // always false — no content scan
 *     ],
 *   ]
 *
 * ## Checks
 *
 *   conflict_overwrite   Warning   IDs present on site with different value/label
 *   broken_refs_in_file  Error     Preset refs to var IDs absent from file AND site
 *   label_clash          Warning   File label matches a different-ID site variable
 *   orphaned_in_file     Advisory  File variables not referenced by any file preset
 *   naming_convention    Advisory  File labels use a different naming style to the site
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Data\PresetsRepository;
use D5DesignSystemHelper\Util\DebugLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PreImportAuditor {

	/** Built-in Divi variable IDs — never flagged as broken refs. */
	private const DIVI_BUILTIN_IDS = [
		'gvid-r41n4b9xo4',
	];

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Run a pre-import audit against the decoded file data.
	 *
	 * @param array  $file_data     Decoded JSON (or normalised array from xlsx).
	 * @param string $file_type     Type key: 'vars' | 'presets' | 'et_native' | 'dtcg'.
	 * @param string $display_name  Original filename for meta.
	 * @return array Report array (errors / warnings / advisories / meta).
	 */
	public static function run( array $file_data, string $file_type, string $display_name ): array {

		$vars_repo    = new VarsRepository();
		$presets_repo = new PresetsRepository();

		$site_vars    = $vars_repo->get_raw();
		$site_colors  = $vars_repo->get_raw_colors();
		$site_presets = $presets_repo->get_raw();

		// Flatten site variable IDs → value/label for fast lookup.
		// { id => [ 'label' => string, 'value' => string, 'var_type' => string ] }
		$site_var_index = self::build_site_var_index( $site_vars, $site_colors );

		// Flatten site preset IDs for fast lookup.
		// { id => [ 'name' => string, 'module' => string ] }
		$site_preset_index = self::build_site_preset_index( $site_presets );

		// Extract items from the file.
		$file_var_index    = self::extract_file_vars( $file_data, $file_type );
		$file_preset_index = self::extract_file_presets( $file_data, $file_type );

		// Run checks.
		$errors = [
			self::check_broken_refs_in_file( $file_data, $file_type, $file_var_index, $site_var_index ),
		];

		$warnings = [
			self::check_conflict_overwrite( $file_var_index, $site_var_index ),
			self::check_label_clash( $file_var_index, $site_var_index ),
		];

		$advisories = [
			self::check_orphaned_in_file( $file_var_index, $file_data, $file_type ),
			self::check_naming_convention( $file_var_index, $site_var_index ),
		];

		// Strip empty-items checks to keep the report lean.
		$filter = fn( array $checks ) => array_values( array_filter(
			$checks,
			fn( $c ) => ! empty( $c['items'] )
		) );

		return [
			'errors'     => $filter( $errors ),
			'warnings'   => $filter( $warnings ),
			'advisories' => $filter( $advisories ),
			'meta'       => [
				'audit_target' => 'pre_import',
				'filename'     => $display_name,
				'file_type'    => $file_type,
				'file_vars'    => count( $file_var_index ),
				'file_presets' => count( $file_preset_index ),
				'site_vars'    => count( $site_var_index ),
				'site_presets' => count( $site_preset_index ),
				'ran_at'       => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
				'is_full'      => false,
			],
		];
	}

	// ── Index builders ────────────────────────────────────────────────────────

	/**
	 * Flatten site vars + colors into a single id-keyed index.
	 *
	 * @return array<string, array{label: string, value: string, var_type: string}>
	 */
	private static function build_site_var_index( array $raw_vars, array $raw_colors ): array {
		$index = [];

		foreach ( $raw_vars as $vtype => $items ) {
			if ( ! is_array( $items ) ) { continue; }
			foreach ( $items as $id => $item ) {
				$index[ (string) $id ] = [
					'label'    => (string) ( $item['label'] ?? '' ),
					'value'    => (string) ( $item['value'] ?? '' ),
					'var_type' => (string) $vtype,
				];
			}
		}

		foreach ( $raw_colors as $id => $item ) {
			$index[ (string) $id ] = [
				'label'    => (string) ( $item['label'] ?? '' ),
				'value'    => (string) ( $item['color'] ?? $item['value'] ?? '' ),
				'var_type' => 'colors',
			];
		}

		return $index;
	}

	/**
	 * Flatten site presets into a single id-keyed index.
	 *
	 * @return array<string, array{name: string, module: string}>
	 */
	private static function build_site_preset_index( array $raw_presets ): array {
		$index = [];

		foreach ( $raw_presets['module'] ?? [] as $mod => $mod_data ) {
			foreach ( $mod_data['items'] ?? [] as $pid => $preset ) {
				$index[ (string) $pid ] = [
					'name'   => (string) ( $preset['name'] ?? $preset['label'] ?? '' ),
					'module' => (string) $mod,
				];
			}
		}

		foreach ( $raw_presets['group'] ?? [] as $grp => $grp_data ) {
			foreach ( $grp_data['items'] ?? [] as $pid => $preset ) {
				$index[ (string) $pid ] = [
					'name'   => (string) ( $preset['name'] ?? $preset['label'] ?? '' ),
					'module' => 'group:' . $grp,
				];
			}
		}

		return $index;
	}

	/**
	 * Extract variables from the import file into an id-keyed index.
	 *
	 * @return array<string, array{label: string, value: string, var_type: string}>
	 */
	private static function extract_file_vars( array $data, string $type ): array {
		$index = [];

		if ( $type === 'vars' ) {
			foreach ( $data['et_divi_global_variables'] ?? [] as $vtype => $items ) {
				if ( ! is_array( $items ) ) { continue; }
				foreach ( $items as $id => $item ) {
					$index[ (string) $id ] = [
						'label'    => (string) ( $item['label'] ?? '' ),
						'value'    => (string) ( $item['value'] ?? '' ),
						'var_type' => (string) $vtype,
					];
				}
			}
		} elseif ( $type === 'et_native' ) {
			foreach ( $data['global_variables'] ?? [] as $item ) {
				if ( ! is_array( $item ) || empty( $item['id'] ) ) { continue; }
				$id = (string) $item['id'];
				$index[ $id ] = [
					'label'    => (string) ( $item['label'] ?? '' ),
					'value'    => (string) ( $item['value'] ?? '' ),
					'var_type' => (string) ( $item['variableType'] ?? $item['type'] ?? '' ),
				];
			}
			foreach ( $data['global_colors'] ?? [] as $pair ) {
				$id   = is_array( $pair ) ? (string) ( $pair[0] ?? '' ) : '';
				$item = is_array( $pair ) ? ( $pair[1] ?? [] ) : [];
				if ( $id === '' ) { continue; }
				$index[ $id ] = [
					'label'    => (string) ( $item['label'] ?? '' ),
					'value'    => (string) ( $item['color'] ?? $item['value'] ?? '' ),
					'var_type' => 'colors',
				];
			}
		}

		return $index;
	}

	/**
	 * Extract presets from the import file into an id-keyed index.
	 *
	 * @return array<string, array{name: string, module: string, attrs: array}>
	 */
	private static function extract_file_presets( array $data, string $type ): array {
		$index = [];
		$raw   = [];

		if ( $type === 'presets' ) {
			$raw = $data['et_divi_builder_global_presets_d5'] ?? [];
		} elseif ( $type === 'et_native' ) {
			$raw = $data['presets'] ?? [];
		}

		foreach ( $raw['module'] ?? [] as $mod => $mod_data ) {
			foreach ( $mod_data['items'] ?? [] as $pid => $preset ) {
				$index[ (string) $pid ] = [
					'name'   => (string) ( $preset['name'] ?? $preset['label'] ?? '' ),
					'module' => (string) $mod,
					'attrs'  => is_array( $preset['attrs'] ?? null ) ? $preset['attrs'] : [],
				];
			}
		}

		foreach ( $raw['group'] ?? [] as $grp => $grp_data ) {
			foreach ( $grp_data['items'] ?? [] as $pid => $preset ) {
				$index[ (string) $pid ] = [
					'name'   => (string) ( $preset['name'] ?? $preset['label'] ?? '' ),
					'module' => 'group:' . $grp,
					'attrs'  => is_array( $preset['attrs'] ?? null ) ? $preset['attrs'] : [],
				];
			}
		}

		return $index;
	}

	// ── Checks ────────────────────────────────────────────────────────────────

	/**
	 * Error: presets in the file reference variable IDs not present in the file
	 * or on the site (and not a Divi built-in).
	 */
	private static function check_broken_refs_in_file(
		array $data,
		string $type,
		array $file_var_index,
		array $site_var_index
	): array {
		$items = [];

		// Collect all variable IDs referenced in file preset attrs via $variable(...)$ tokens.
		$file_presets = self::extract_file_presets( $data, $type );
		$known_ids    = array_merge( array_keys( $file_var_index ), array_keys( $site_var_index ) );
		$known_set    = array_flip( $known_ids );

		foreach ( $file_presets as $pid => $preset ) {
			$refs = self::extract_var_refs_from_attrs( $preset['attrs'] );
			foreach ( $refs as $ref_id ) {
				if ( in_array( $ref_id, self::DIVI_BUILTIN_IDS, true ) ) { continue; }
				if ( isset( $known_set[ $ref_id ] ) ) { continue; }
				$items[] = [
					'id'       => $pid,
					'label'    => $preset['name'],
					'var_type' => $preset['module'],
					'detail'   => sprintf(
						'Preset "%s" references variable ID %s which is not in the import file or on this site.',
						$preset['name'],
						$ref_id
					),
				];
				break; // one missing ref per preset is enough to flag it
			}
		}

		return [ 'check' => 'broken_refs_in_file', 'items' => $items ];
	}

	/**
	 * Warning: IDs in the file already exist on the site with a different value or label.
	 * These will be silently overwritten on import.
	 */
	private static function check_conflict_overwrite(
		array $file_var_index,
		array $site_var_index
	): array {
		$items = [];

		foreach ( $file_var_index as $id => $file_item ) {
			if ( ! isset( $site_var_index[ $id ] ) ) { continue; }

			$site_item     = $site_var_index[ $id ];
			$label_changed = $site_item['label'] !== '' &&
			                 $file_item['label'] !== '' &&
			                 $site_item['label'] !== $file_item['label'];
			$value_changed = $site_item['value'] !== '' &&
			                 $file_item['value'] !== '' &&
			                 $site_item['value'] !== $file_item['value'];

			if ( ! $label_changed && ! $value_changed ) { continue; }

			$changes = [];
			if ( $label_changed ) {
				$changes[] = sprintf( 'label "%s" → "%s"', $site_item['label'], $file_item['label'] );
			}
			if ( $value_changed ) {
				$changes[] = sprintf( 'value "%s" → "%s"', $site_item['value'], $file_item['value'] );
			}

			$items[] = [
				'id'       => $id,
				'label'    => $file_item['label'] ?: $site_item['label'],
				'var_type' => $file_item['var_type'],
				'detail'   => 'Existing variable will be overwritten: ' . implode( '; ', $changes ) . '.',
			];
		}

		return [ 'check' => 'conflict_overwrite', 'items' => $items ];
	}

	/**
	 * Warning: a label in the file matches a label on the site but belongs to a different ID.
	 * Creates a naming collision — two variables with identical labels after import.
	 */
	private static function check_label_clash(
		array $file_var_index,
		array $site_var_index
	): array {
		// Build reverse map: lowercase label → site id.
		$site_label_map = [];
		foreach ( $site_var_index as $id => $item ) {
			$lower = strtolower( trim( $item['label'] ) );
			if ( $lower !== '' ) {
				$site_label_map[ $lower ] = $id;
			}
		}

		$items = [];

		foreach ( $file_var_index as $id => $file_item ) {
			$lower = strtolower( trim( $file_item['label'] ) );
			if ( $lower === '' ) { continue; }
			if ( ! isset( $site_label_map[ $lower ] ) ) { continue; }
			$clash_id = $site_label_map[ $lower ];
			if ( $clash_id === $id ) { continue; } // same ID — handled by conflict_overwrite

			$items[] = [
				'id'       => $id,
				'label'    => $file_item['label'],
				'var_type' => $file_item['var_type'],
				'detail'   => sprintf(
					'Label "%s" already exists on this site (ID: %s). After import, two different variables will share this label.',
					$file_item['label'],
					$clash_id
				),
			];
		}

		return [ 'check' => 'label_clash', 'items' => $items ];
	}

	/**
	 * Advisory: variables in the file not referenced by any preset in the file.
	 * May be intentional (variables without presets), but worth noting.
	 */
	private static function check_orphaned_in_file(
		array $file_var_index,
		array $data,
		string $type
	): array {
		if ( empty( $file_var_index ) ) {
			return [ 'check' => 'orphaned_in_file', 'items' => [] ];
		}

		$file_presets = self::extract_file_presets( $data, $type );
		if ( empty( $file_presets ) ) {
			// No presets in file — all vars would be "orphaned", skip to avoid noise.
			return [ 'check' => 'orphaned_in_file', 'items' => [] ];
		}

		// Collect all var IDs referenced in any file preset.
		$referenced = [];
		foreach ( $file_presets as $preset ) {
			foreach ( self::extract_var_refs_from_attrs( $preset['attrs'] ) as $ref_id ) {
				$referenced[ $ref_id ] = true;
			}
		}

		$items = [];
		foreach ( $file_var_index as $id => $item ) {
			if ( isset( $referenced[ $id ] ) ) { continue; }
			$items[] = [
				'id'       => $id,
				'label'    => $item['label'],
				'var_type' => $item['var_type'],
				'detail'   => 'This variable is not referenced by any preset in the import file.',
			];
		}

		return [ 'check' => 'orphaned_in_file', 'items' => $items ];
	}

	/**
	 * Advisory: file labels use a different naming convention to the site's existing
	 * variables of the same type.
	 *
	 * Detects: Title Case, kebab-case, snake_case, camelCase.
	 * Only fires when the site has ≥4 variables of the same type AND the file uses
	 * a different dominant style.
	 */
	private static function check_naming_convention(
		array $file_var_index,
		array $site_var_index
	): array {
		if ( empty( $file_var_index ) || empty( $site_var_index ) ) {
			return [ 'check' => 'naming_convention', 'items' => [] ];
		}

		// Determine dominant naming style per type on the site.
		$site_styles = []; // var_type => [ style => count ]
		foreach ( $site_var_index as $item ) {
			$vtype = $item['var_type'];
			$style = self::detect_name_style( $item['label'] );
			if ( $style ) {
				$site_styles[ $vtype ][ $style ] = ( $site_styles[ $vtype ][ $style ] ?? 0 ) + 1;
			}
		}

		// For each type with ≥4 site vars, find the dominant style.
		$dominant = [];
		foreach ( $site_styles as $vtype => $counts ) {
			$total = array_sum( $counts );
			if ( $total < 4 ) { continue; }
			arsort( $counts );
			$top_style  = array_key_first( $counts );
			$top_count  = $counts[ $top_style ];
			if ( $top_count / $total >= 0.6 ) {
				$dominant[ $vtype ] = $top_style;
			}
		}

		if ( empty( $dominant ) ) {
			return [ 'check' => 'naming_convention', 'items' => [] ];
		}

		$items = [];
		foreach ( $file_var_index as $id => $item ) {
			$vtype = $item['var_type'];
			if ( ! isset( $dominant[ $vtype ] ) ) { continue; }
			$file_style = self::detect_name_style( $item['label'] );
			if ( $file_style === null || $file_style === $dominant[ $vtype ] ) { continue; }
			$items[] = [
				'id'       => $id,
				'label'    => $item['label'],
				'var_type' => $vtype,
				'detail'   => sprintf(
					'Label uses %s style; the site\'s existing %s variables predominantly use %s.',
					$file_style,
					$vtype,
					$dominant[ $vtype ]
				),
			];
		}

		return [ 'check' => 'naming_convention', 'items' => $items ];
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Extract all $variable(type:id)$ IDs from a preset attrs array.
	 *
	 * @param array $attrs Preset attrs (key → value pairs).
	 * @return string[] List of variable IDs referenced.
	 */
	private static function extract_var_refs_from_attrs( array $attrs ): array {
		$ids = [];
		foreach ( $attrs as $value ) {
			if ( ! is_string( $value ) ) { continue; }
			if ( preg_match_all( '/\$variable\([^:]+:([^)]+)\)\$/', $value, $matches ) ) {
				foreach ( $matches[1] as $id ) {
					$ids[] = $id;
				}
			}
		}
		return array_unique( $ids );
	}

	/**
	 * Detect a simple naming style from a label string.
	 *
	 * @return string|null  'Title Case' | 'kebab-case' | 'snake_case' | 'camelCase' | null
	 */
	private static function detect_name_style( string $label ): ?string {
		$label = trim( $label );
		if ( $label === '' ) { return null; }
		if ( str_contains( $label, '-' ) )  { return 'kebab-case'; }
		if ( str_contains( $label, '_' ) )  { return 'snake_case'; }
		if ( preg_match( '/^[A-Z]/', $label ) && str_contains( $label, ' ' ) ) { return 'Title Case'; }
		if ( preg_match( '/^[a-z].*[A-Z]/', $label ) ) { return 'camelCase'; }
		return null;
	}
}
