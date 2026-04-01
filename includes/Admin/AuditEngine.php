<?php
/**
 * Audit Engine — scans live site Divi 5 data and produces a three-tier
 * Error / Warning / Advisory report.
 *
 * All checks are read-only: no data is written to the database.
 *
 * ## Report shape (Simple Audit — run())
 *
 *   [
 *     'errors'     => [ ['check' => string, 'items' => [...]], ... ],
 *     'warnings'   => [ ['check' => string, 'items' => [...]], ... ],
 *     'advisories' => [ ['check' => string, 'items' => [...]], ... ],
 *     'meta'       => [
 *       'variable_count' => int,
 *       'color_count'    => int,
 *       'preset_count'   => int,
 *       'content_count'  => int,
 *       'ran_at'         => string,
 *       'is_full'        => bool,
 *     ],
 *   ]
 *
 * Contextual Audit (run_full()) merges 8 additional content-dependent checks
 * into the same report shape and sets meta.is_full = true.  The dso_usage
 * index from a ContentScanner run is passed in; no second DB scan is performed.
 *
 * Each item has shape: [ 'id' => string, 'label' => string, 'detail' => string ]
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Data\PresetsRepository;
use D5DesignSystemHelper\Util\DiviBlocParser;
use D5DesignSystemHelper\Util\DebugLogger;
use D5DesignSystemHelper\Admin\AuditExporter;
use D5DesignSystemHelper\Admin\NotesManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AuditEngine
 */
class AuditEngine {

	/**
	 * Known Divi built-in variable IDs — never flagged as errors or orphans.
	 */
	private const DIVI_BUILTIN_IDS = [
		'gvid-r41n4b9xo4', // Internal default spacing/layout variable (Divi-internal)
	];

	/**
	 * Minimum number of presets a hardcoded hex must appear in to be flagged
	 * as an extraction candidate.
	 */
	private const HARDCODE_THRESHOLD = 10;

	/**
	 * Minimum number of content items a variable must appear in (directly) to
	 * be flagged as high-impact by check_high_impact_variables().
	 */
	public const HIGH_IMPACT_THRESHOLD = 10;

	/**
	 * Minimum overlap ratio (0–1) between two presets' variable reference sets
	 * to be flagged as overlapping by check_overlapping_presets().
	 */
	public const OVERLAP_RATIO_THRESHOLD = 0.8;

	// ── AJAX ─────────────────────────────────────────────────────────────────

	/**
	 * AJAX handler. Hooked to wp_ajax_d5dsh_audit_run.
	 */
	public function ajax_run(): void {
		check_ajax_referer( 'd5dsh_audit_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		try {
			$report = $this->run();
		} catch ( \Throwable $e ) {
			DebugLogger::send_error( $e, __METHOD__, 'Audit failed.' );
		}
		wp_send_json_success( $report );
	}

	/**
	 * AJAX handler for Contextual Audit.
	 * Accepts the ContentScanner dso_usage index as JSON body alongside the
	 * basic-audit trigger, runs run_full(), and returns the enriched report.
	 * Hooked to wp_ajax_d5dsh_audit_run_full.
	 */
	public function ajax_run_full(): void {
		check_ajax_referer( 'd5dsh_audit_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw     = file_get_contents( 'php://input' );
		$payload = $raw ? json_decode( $raw, true ) : null;

		// dso_usage index: { variables: { id: { count, posts:[{post_id,post_title,post_status}] } }, presets: { … } }
		$dso_usage = ( is_array( $payload ) && isset( $payload['dso_usage'] ) )
			? $payload['dso_usage']
			: [];

		try {
			$report = $this->run_full( $dso_usage );
		} catch ( \Throwable $e ) {
			DebugLogger::send_error( $e, __METHOD__, 'Contextual audit failed.' );
		}
		wp_send_json_success( $report );
	}

	/**
	 * AJAX handler. Accepts audit report data as JSON body, streams XLSX.
	 * Hooked to wp_ajax_d5dsh_audit_xlsx.
	 */
	public function ajax_audit_xlsx(): never {
		check_ajax_referer( 'd5dsh_audit_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw     = file_get_contents( 'php://input' );
		$payload = $raw ? json_decode( $raw, true ) : null;

		if ( ! is_array( $payload ) ) {
			wp_send_json_error( [ 'message' => 'Invalid report data.' ], 400 );
		}

		// Support both legacy shape (audit data at top level) and new shape { audit: {…}, scan: {…} }.
		$audit_data = isset( $payload['audit'] ) ? $payload['audit'] : $payload;
		$scan_data  = $payload['scan'] ?? null;

		try {
			AuditExporter::export_audit_xlsx( $audit_data, NotesManager::get_all(), $scan_data );
			// export_audit_xlsx streams and exits — execution ends there on success.
		} catch ( \Throwable $e ) {
			DebugLogger::send_error( $e, __METHOD__, 'Audit XLSX export failed.' );
		}
	}

	/**
	 * AJAX handler. Accepts content scan report data as JSON body, streams XLSX.
	 * Hooked to wp_ajax_d5dsh_scan_xlsx.
	 */
	public function ajax_scan_xlsx(): never {
		check_ajax_referer( 'd5dsh_audit_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw  = file_get_contents( 'php://input' );
		$data = $raw ? json_decode( $raw, true ) : null;

		if ( ! is_array( $data ) ) {
			wp_send_json_error( [ 'message' => 'Invalid scan data.' ], 400 );
		}

		try {
			AuditExporter::export_scan_xlsx( $data, NotesManager::get_all() );
			// export_scan_xlsx streams and exits — execution ends there on success.
		} catch ( \Throwable $e ) {
			DebugLogger::send_error( $e, __METHOD__, 'Scan XLSX export failed.' );
		}
	}

	// ── Public orchestration ─────────────────────────────────────────────────

	/**
	 * Run all audit checks and return the full report.
	 *
	 * @return array
	 */
	public function run(): array {
		$vars_repo    = new VarsRepository();
		$presets_repo = new PresetsRepository();

		$raw_vars    = $vars_repo->get_raw();
		$raw_colors  = $vars_repo->get_raw_colors();
		$raw_presets = $presets_repo->get_raw();
		$layout_content = $this->load_layout_content();

		// Pre-build flat structures used by multiple checks.
		$all_var_ids      = $this->collect_var_ids( $raw_vars, $raw_colors );
		$all_var_types    = $this->collect_var_types( $raw_vars, $raw_colors );
		$all_preset_items = $this->collect_preset_items( $raw_presets );

		$notes = NotesManager::get_all();

		$raw_checks = [
			'errors'     => [
				$this->check_broken_variable_refs( $raw_colors, $raw_vars, $all_preset_items, $all_var_types ),
				$this->check_archived_vars_in_presets( $raw_colors, $raw_vars, $all_preset_items, $all_var_types ),
				$this->check_duplicate_labels( $raw_colors, $raw_vars ),
			],
			'warnings'   => [
				$this->check_singleton_variables( $all_var_ids, $all_preset_items, $all_var_types ),
				$this->check_near_duplicate_values( $raw_colors, $raw_vars ),
				$this->check_preset_duplicate_names( $raw_presets ),
				$this->check_empty_label_variables( $raw_colors, $raw_vars ),
				$this->check_unnamed_presets( $raw_presets ),
				$this->check_similar_variable_names( $raw_colors, $raw_vars ),
				$this->check_naming_convention_inconsistency( $raw_colors, $raw_vars ),
			],
			'advisories' => [
				$this->check_hardcoded_extraction_candidates( $all_preset_items ),
				$this->check_orphaned_variables( $all_var_ids, $all_preset_items, $layout_content, $all_var_types ),
				$this->check_preset_no_variable_refs( $all_preset_items ),
				$this->check_variable_type_distribution( $raw_colors, $raw_vars ),
			],
		];

		$suppressed_total = 0;
		$tiers            = [];

		foreach ( $raw_checks as $tier_key => $checks ) {
			$filtered_checks = [];
			foreach ( $checks as $check ) {
				$check_name     = $check['check'] ?? '';
				$filtered_items = [];
				foreach ( $check['items'] as $item ) {
					$item_id       = $item['id'] ?? '';
					$is_suppressed =
						NotesManager::is_suppressed( 'var:'    . $item_id,    $check_name ) ||
						NotesManager::is_suppressed( 'preset:' . $item_id,    $check_name ) ||
						NotesManager::is_suppressed( 'post:'   . $item_id,    $check_name ) ||
						NotesManager::is_suppressed( 'check:'  . $check_name, $check_name );

					if ( $is_suppressed ) {
						$suppressed_total++;
						// Include in report with a suppressed flag so the XLSX exporter can show it.
						$filtered_items[] = array_merge( $item, [ 'suppressed' => true ] );
					} else {
						$filtered_items[] = $item;
					}
				}
				$filtered_checks[] = [ 'check' => $check_name, 'items' => $filtered_items ];
			}
			$tiers[ $tier_key ] = $filtered_checks;
		}

		// Compute total and unique variable references across all preset attrs.
		$total_vars_in_presets  = 0;
		$unique_var_ids_in_presets = [];
		foreach ( $all_preset_items as $preset ) {
			$attrs_raw = DiviBlocParser::preset_attrs_to_string( $preset );
			foreach ( DiviBlocParser::extract_variable_refs( $attrs_raw ) as $ref ) {
				$total_vars_in_presets++;
				$unique_var_ids_in_presets[ $ref['name'] ] = true;
			}
		}

		return array_merge( $tiers, [
			'meta' => [
				'variable_count'          => count( $all_var_ids ),
				'color_count'             => count( $raw_colors ),
				'preset_count'            => count( $all_preset_items ),
				'content_count'           => count( $layout_content ),
				'suppressed_count'        => $suppressed_total,
				'total_vars_in_presets'   => $total_vars_in_presets,
				'unique_vars_in_presets'  => count( $unique_var_ids_in_presets ),
				'ran_at'                  => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
				'is_full'                 => false,
			],
		] );
	}

	/**
	 * Run the full audit — all basic checks plus content-dependent checks.
	 *
	 * The $dso_usage array must be the dso_usage sub-tree from a ContentScanner
	 * run: { 'variables' => { id => { count, posts, label } }, 'presets' => { … } }.
	 * If empty, the content-dependent checks are skipped gracefully (returning 0
	 * items each) so the caller always gets a complete report shape.
	 *
	 * @param array $dso_usage DSO usage index from ContentScanner::run()['dso_usage'].
	 * @return array Full audit report — same shape as run() with meta.is_full = true
	 *               and additional checks merged into each tier.
	 */
	public function run_full( array $dso_usage = [] ): array {
		$base = $this->run();

		$vars_repo    = new VarsRepository();
		$presets_repo = new PresetsRepository();

		$raw_vars    = $vars_repo->get_raw();
		$raw_colors  = $vars_repo->get_raw_colors();
		$raw_presets = $presets_repo->get_raw();

		$all_var_ids      = $this->collect_var_ids( $raw_vars, $raw_colors );
		$all_var_types    = $this->collect_var_types( $raw_vars, $raw_colors );
		$all_preset_items = $this->collect_preset_items( $raw_presets );
		$layout_content   = $this->load_layout_content();

		$extra_errors = [
			$this->check_archived_dsos_in_content( $raw_colors, $raw_vars, $all_preset_items, $dso_usage, $all_var_types ),
			$this->check_broken_dso_refs_in_content( $all_var_ids, $all_preset_items, $dso_usage ),
		];

		$extra_warnings = [
			$this->check_orphaned_presets( $all_preset_items, $dso_usage ),
			$this->check_high_impact_variables( $all_var_ids, $dso_usage, $all_var_types ),
			$this->check_preset_naming_convention( $raw_presets ),
		];

		$extra_advisories = [
			$this->check_variables_bypassing_presets( $all_var_ids, $all_preset_items, $dso_usage, $all_var_types ),
			$this->check_singleton_presets( $all_preset_items, $dso_usage ),
			$this->check_overlapping_presets( $raw_presets ),
		];

		// Merge extra checks into the base report tiers.
		$report                = $base;
		$report['errors']      = array_merge( $base['errors'],      $extra_errors      );
		$report['warnings']    = array_merge( $base['warnings'],    $extra_warnings    );
		$report['advisories']  = array_merge( $base['advisories'],  $extra_advisories  );
		$report['meta']['is_full'] = true;

		return $report;
	}

	// ── Tier: Errors ─────────────────────────────────────────────────────────

	/**
	 * E1 — Find presets that reference variable IDs not present on this site.
	 *
	 * @param array $raw_colors   Raw colors keyed by gcid-*.
	 * @param array $raw_vars     Raw non-color vars keyed by type => id.
	 * @param array $preset_items Flat array of preset items (each with id, name, attrs).
	 * @return array{check: string, items: list<array{id: string, label: string, detail: string}>}
	 */
	private function check_broken_variable_refs(
		array $raw_colors,
		array $raw_vars,
		array $preset_items,
		array $all_var_types = []
	): array {
		$site_ids = $this->collect_var_ids( $raw_vars, $raw_colors );
		$items    = [];

		foreach ( $preset_items as $preset ) {
			$preset_id   = $preset['id']   ?? '';
			$preset_name = $preset['name'] ?? $preset_id;
			$attrs_raw   = DiviBlocParser::preset_attrs_to_string( $preset );

			foreach ( DiviBlocParser::extract_variable_refs( $attrs_raw ) as $ref ) {
				$ref_id = $ref['name'];

				if ( in_array( $ref_id, self::DIVI_BUILTIN_IDS, true ) ) {
					continue;
				}
				if ( isset( $site_ids[ $ref_id ] ) ) {
					continue;
				}

				// Avoid duplicate items for the same missing ID within the same preset.
				$key = $ref_id . '|' . $preset_id;
				if ( isset( $seen_e1[ $key ] ) ) {
					continue;
				}
				$seen_e1[ $key ] = true;

				// Infer type from the ID prefix when not in the known-types map.
				$var_type = $all_var_types[ $ref_id ] ?? ( str_starts_with( $ref_id, 'gcid-' ) ? 'colors' : '' );

				$items[] = [
					'id'       => $ref_id,
					'label'    => '',
					'var_type' => $var_type,
					'detail'   => 'Referenced in preset "' . $preset_name . '" (' . $preset_id . ') but not defined on this site.',
				];
			}
		}

		return [ 'check' => 'broken_variable_refs', 'items' => $items ];
	}

	/**
	 * E2 — Find archived variables that are still referenced by active presets.
	 *
	 * Divi 5 stores a 'status' key on both non-color variables and color variables.
	 * An archived variable has status !== 'active'. If such a variable is
	 * referenced in a preset's attrs, it is flagged as an error.
	 *
	 * @param array $raw_colors   Raw colors keyed by gcid-*.
	 * @param array $raw_vars     Raw non-color vars keyed by type => id.
	 * @param array $preset_items Flat array of preset items.
	 * @return array{check: string, items: list<array{id: string, label: string, detail: string}>}
	 */
	private function check_archived_vars_in_presets(
		array $raw_colors,
		array $raw_vars,
		array $preset_items,
		array $all_var_types = []
	): array {
		// Collect archived IDs.
		$archived_ids = [];

		foreach ( $raw_colors as $id => $color_entry ) {
			$status = $color_entry['status'] ?? 'active';
			if ( $status !== 'active' ) {
				$archived_ids[ $id ] = $color_entry['label'] ?? $id;
			}
		}

		foreach ( $raw_vars as $var_type => $type_vars ) {
			if ( ! is_array( $type_vars ) ) {
				continue;
			}
			foreach ( $type_vars as $id => $var ) {
				$status = $var['status'] ?? 'active';
				if ( $status !== 'active' ) {
					$archived_ids[ $id ] = $var['label'] ?? $id;
				}
			}
		}

		// If Divi doesn't expose an archive flag, there will be nothing to check.
		if ( empty( $archived_ids ) ) {
			return [ 'check' => 'archived_vars_in_presets', 'items' => [] ];
		}

		$items    = [];
		$seen_e2  = [];

		foreach ( $preset_items as $preset ) {
			$preset_id   = $preset['id']   ?? '';
			$preset_name = $preset['name'] ?? $preset_id;
			$attrs_raw   = DiviBlocParser::preset_attrs_to_string( $preset );

			foreach ( DiviBlocParser::extract_variable_refs( $attrs_raw ) as $ref ) {
				$ref_id = $ref['name'];
				if ( ! isset( $archived_ids[ $ref_id ] ) ) {
					continue;
				}
				$key = $ref_id . '|' . $preset_id;
				if ( isset( $seen_e2[ $key ] ) ) {
					continue;
				}
				$seen_e2[ $key ] = true;

				$items[] = [
					'id'       => $ref_id,
					'label'    => $archived_ids[ $ref_id ],
					'var_type' => $all_var_types[ $ref_id ] ?? ( str_starts_with( $ref_id, 'gcid-' ) ? 'colors' : '' ),
					'detail'   => 'Variable is archived but still referenced in preset "' . $preset_name . '" (' . $preset_id . ').',
				];
			}
		}

		return [ 'check' => 'archived_vars_in_presets', 'items' => $items ];
	}

	/**
	 * E3 — Variables and colors that share a label but have different values or types.
	 *
	 * Checks globally across all DSO types (colors, numbers, fonts, images, strings).
	 * Two DSOs sharing an identical label are only flagged if they are NOT the same
	 * type+value (i.e. exact duplicates are not flagged — see near_duplicate_values).
	 *
	 * @param array $raw_colors Raw colors keyed by gcid-*.
	 * @param array $raw_vars   Raw non-color vars keyed by type => id => entry.
	 * @return array{check: string, items: list<array{id: string, label: string, detail: string}>}
	 */
	private function check_duplicate_labels(
		array $raw_colors,
		array $raw_vars
	): array {
		// Build: label (lowercase) => list of [ id, type, value ]
		$by_label = [];

		foreach ( $raw_colors as $id => $entry ) {
			$label = strtolower( trim( $entry['label'] ?? '' ) );
			if ( $label === '' ) {
				continue;
			}
			$by_label[ $label ][] = [
				'id'    => $id,
				'type'  => 'color',
				'value' => $entry['color'] ?? '',
			];
		}

		foreach ( $raw_vars as $var_type => $type_vars ) {
			if ( ! is_array( $type_vars ) ) {
				continue;
			}
			foreach ( $type_vars as $id => $var ) {
				$label = strtolower( trim( $var['label'] ?? '' ) );
				if ( $label === '' ) {
					continue;
				}
				$by_label[ $label ][] = [
					'id'    => $id,
					'type'  => $var_type,
					'value' => $var['value'] ?? '',
				];
			}
		}

		$items = [];
		foreach ( $by_label as $label => $entries ) {
			if ( count( $entries ) < 2 ) {
				continue;
			}

			// Check if all entries are the exact same type + value — skip if so.
			$first_type  = $entries[0]['type'];
			$first_value = $entries[0]['value'];
			$all_identical = true;
			foreach ( $entries as $e ) {
				if ( $e['type'] !== $first_type || $e['value'] !== $first_value ) {
					$all_identical = false;
					break;
				}
			}
			if ( $all_identical ) {
				continue;
			}

			$ids_str   = implode( ', ', array_column( $entries, 'id' ) );
			$types_str = implode( ', ', array_unique( array_column( $entries, 'type' ) ) );
			$items[] = [
				'id'     => $ids_str,
				'label'  => ucfirst( $label ),
				'detail' => 'Label "' . ucfirst( $label ) . '" is shared by ' . count( $entries ) . ' DSOs across types: ' . $types_str . ' — same label with different values or types can cause confusion.',
			];
		}

		return [ 'check' => 'duplicate_labels', 'items' => $items ];
	}

	// ── Tier: Warnings ───────────────────────────────────────────────────────

	/**
	 * W1 — Variables referenced by exactly one preset (singleton candidates).
	 *
	 * A variable used by only one preset is a design-system smell: it may be
	 * better expressed as an inline value on that preset rather than a global.
	 *
	 * @param array $all_var_ids  Flat map of id => label.
	 * @param array $preset_items Flat array of preset items.
	 * @return array{check: string, items: list<array{id: string, label: string, detail: string}>}
	 */
	private function check_singleton_variables(
		array $all_var_ids,
		array $preset_items,
		array $all_var_types = []
	): array {
		// Count how many presets reference each variable ID.
		$ref_count  = []; // id => count of presets
		$ref_preset  = []; // id => first referencing preset name

		foreach ( $preset_items as $preset ) {
			$preset_id   = $preset['id']   ?? '';
			$preset_name = $preset['name'] ?? $preset_id;
			$attrs_raw   = DiviBlocParser::preset_attrs_to_string( $preset );
			$seen_in_preset = [];

			foreach ( DiviBlocParser::extract_variable_refs( $attrs_raw ) as $ref ) {
				$id = $ref['name'];
				if ( in_array( $id, self::DIVI_BUILTIN_IDS, true ) ) {
					continue;
				}
				if ( isset( $seen_in_preset[ $id ] ) ) {
					continue; // Count once per preset, not per occurrence.
				}
				$seen_in_preset[ $id ] = true;
				$ref_count[ $id ] = ( $ref_count[ $id ] ?? 0 ) + 1;
				if ( ! isset( $ref_preset[ $id ] ) ) {
					$ref_preset[ $id ] = $preset_name;
				}
			}
		}

		$items = [];
		foreach ( $ref_count as $id => $count ) {
			if ( $count !== 1 ) {
				continue;
			}
			$label = $all_var_ids[ $id ] ?? '';
			$items[] = [
				'id'       => $id,
				'label'    => $label,
				'var_type' => $all_var_types[ $id ] ?? ( str_starts_with( $id, 'gcid-' ) ? 'colors' : '' ),
				'detail'   => 'Used by only 1 preset ("' . $ref_preset[ $id ] . '") — consider whether this should be a global variable or an inline value.',
			];
		}

		return [ 'check' => 'singleton_variables', 'items' => $items ];
	}

	/**
	 * W2 — Color variables sharing identical normalised hex values.
	 *
	 * Two or more IDs with the same hex suggest a deduplication opportunity.
	 * Non-color variables with identical values are also flagged.
	 *
	 * @param array $raw_colors Raw colors keyed by gcid-*.
	 * @param array $raw_vars   Raw non-color vars keyed by type => id.
	 * @return array{check: string, items: list<array{id: string, label: string, detail: string}>}
	 */
	private function check_near_duplicate_values(
		array $raw_colors,
		array $raw_vars
	): array {
		$items = [];

		// ── Colors ─────────────────────────────────────────────────────────
		$by_hex = []; // normalised_hex => [ id, ... ]

		foreach ( $raw_colors as $id => $entry ) {
			$raw_value = $entry['color'] ?? '';
			// Skip $variable()$ references — those are intentional aliases.
			if ( str_starts_with( $raw_value, '$variable(' ) ) {
				continue;
			}
			// Normalise: lowercase, strip leading #, collapse 3-digit → 6-digit.
			$norm = $this->normalise_hex( $raw_value );
			if ( $norm === '' ) {
				continue;
			}
			$by_hex[ $norm ][] = $id;
		}

		foreach ( $by_hex as $hex => $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			$items[] = [
				'id'     => implode( ', ', $ids ),
				'label'  => '',
				'detail' => count( $ids ) . ' color variables share value #' . $hex . ' — consider deduplication.',
			];
		}

		// ── Non-color variables ─────────────────────────────────────────────
		foreach ( $raw_vars as $var_type => $type_vars ) {
			if ( ! is_array( $type_vars ) ) {
				continue;
			}
			$by_value = [];
			foreach ( $type_vars as $id => $var ) {
				$val = trim( (string) ( $var['value'] ?? '' ) );
				if ( $val === '' ) {
					continue;
				}
				$by_value[ $var_type . ':' . $val ][] = $id;
			}
			foreach ( $by_value as $key => $ids ) {
				if ( count( $ids ) < 2 ) {
					continue;
				}
				[ $type_name, $val ] = explode( ':', $key, 2 );
				$items[] = [
					'id'     => implode( ', ', $ids ),
					'label'  => '',
					'detail' => count( $ids ) . ' ' . $type_name . ' variables share value "' . $val . '" — consider deduplication.',
				];
			}
		}

		return [ 'check' => 'near_duplicate_values', 'items' => $items ];
	}

	/**
	 * W3 — Presets of the same module type sharing the same name.
	 *
	 * Duplicate preset names within the same module type make it impossible
	 * for editors to identify the correct preset in the Divi editor dropdown.
	 *
	 * @param array $raw_presets Raw presets array with 'module' and 'group' keys.
	 * @return array{check: string, items: list<array{id: string, label: string, detail: string}>}
	 */
	private function check_preset_duplicate_names( array $raw_presets ): array {
		$items = [];

		foreach ( [ 'module', 'group' ] as $tier ) {
			foreach ( $raw_presets[ $tier ] ?? [] as $module_name => $module_data ) {
				// Build name (lowercase) => list of [ id ]
				$by_name = [];
				foreach ( $module_data['items'] ?? [] as $preset_id => $preset ) {
					$name = strtolower( trim( $preset['name'] ?? '' ) );
					if ( $name === '' ) {
						continue;
					}
					$by_name[ $name ][] = $preset_id;
				}
				foreach ( $by_name as $name => $ids ) {
					if ( count( $ids ) < 2 ) {
						continue;
					}
					$items[] = [
						'id'     => implode( ', ', $ids ),
						'label'  => ucfirst( $name ),
						'detail' => count( $ids ) . ' ' . $tier . ' presets for "' . $module_name . '" share the name "' . ucfirst( $name ) . '" — duplicate names make preset selection ambiguous in the editor.',
					];
				}
			}
		}

		return [ 'check' => 'preset_duplicate_names', 'items' => $items ];
	}

	/**
	 * W4 — Variables or colors with blank/empty labels.
	 *
	 * An unlabelled variable is effectively undiscoverable in the Divi editor.
	 *
	 * @param array $raw_colors Raw colors keyed by gcid-*.
	 * @param array $raw_vars   Raw non-color vars keyed by type => id => entry.
	 * @return array{check: string, items: list<array{id: string, label: string, detail: string}>}
	 */
	private function check_empty_label_variables(
		array $raw_colors,
		array $raw_vars
	): array {
		$items = [];

		foreach ( $raw_colors as $id => $entry ) {
			$label = trim( $entry['label'] ?? '' );
			if ( $label === '' ) {
				$items[] = [
					'id'       => $id,
					'label'    => '',
					'var_type' => 'colors',
					'detail'   => 'Color variable has no label — it will be unnamed in the Divi editor.',
				];
			}
		}

		foreach ( $raw_vars as $var_type => $type_vars ) {
			if ( ! is_array( $type_vars ) ) {
				continue;
			}
			foreach ( $type_vars as $id => $var ) {
				$label = trim( $var['label'] ?? '' );
				if ( $label === '' ) {
					$items[] = [
						'id'       => $id,
						'label'    => '',
						'var_type' => $var_type,
						'detail'   => ucfirst( $var_type ) . ' variable has no label — it will be unnamed in the Divi editor.',
					];
				}
			}
		}

		return [ 'check' => 'empty_label_variables', 'items' => $items ];
	}

	/**
	 * W5 — Presets with blank or missing names.
	 *
	 * An unnamed preset cannot be meaningfully selected in the Divi editor
	 * and is a sign of incomplete setup.
	 *
	 * @param array $raw_presets Raw presets array with 'module' and 'group' keys.
	 * @return array{check: string, items: list<array{id: string, label: string, detail: string}>}
	 */
	private function check_unnamed_presets( array $raw_presets ): array {
		$items = [];

		foreach ( [ 'module', 'group' ] as $tier ) {
			foreach ( $raw_presets[ $tier ] ?? [] as $module_name => $module_data ) {
				foreach ( $module_data['items'] ?? [] as $preset_id => $preset ) {
					$name = trim( $preset['name'] ?? '' );
					if ( $name === '' ) {
						$items[] = [
							'id'     => $preset_id,
							'label'  => '',
							'detail' => ucfirst( $tier ) . ' preset for "' . $module_name . '" has no name — unnamed presets are ambiguous in the Divi editor.',
						];
					}
				}
			}
		}

		return [ 'check' => 'unnamed_presets', 'items' => $items ];
	}

	// ── Tier: Advisories ─────────────────────────────────────────────────────

	/**
	 * A1 — Hardcoded hex values appearing in HARDCODE_THRESHOLD or more presets.
	 *
	 * Scans raw preset attrs strings for literal hex colors (#rrggbb) that are
	 * not wrapped in a $variable()$ token. A hex appearing in many presets is
	 * a candidate for extraction into a global variable.
	 *
	 * @param array $preset_items Flat array of preset items.
	 * @return array{check: string, items: list<array{id: string, label: string, detail: string}>}
	 */
	private function check_hardcoded_extraction_candidates( array $preset_items ): array {
		// For each hex, count how many presets contain it as a hardcoded value.
		$hex_presets = []; // normalised_hex => set of preset IDs

		foreach ( $preset_items as $preset ) {
			$preset_id = $preset['id'] ?? '';
			$attrs_raw = DiviBlocParser::preset_attrs_to_string( $preset );

			// Strip $variable()$ tokens first so we don't match hex values inside them.
			$stripped = DiviBlocParser::strip_variable_tokens( $attrs_raw );

			// Match hex colors: #rgb or #rrggbb or #rrggbbaa (3, 6, or 8 chars).
			if ( ! preg_match_all( '/#([0-9a-fA-F]{3}(?:[0-9a-fA-F]{3}(?:[0-9a-fA-F]{2})?)?)/', $stripped, $m ) ) {
				continue;
			}

			foreach ( $m[1] as $hex_digits ) {
				$norm = $this->normalise_hex( '#' . $hex_digits );
				if ( $norm === '' ) {
					continue;
				}
				if ( ! isset( $hex_presets[ $norm ] ) ) {
					$hex_presets[ $norm ] = [];
				}
				$hex_presets[ $norm ][ $preset_id ] = true;
			}
		}

		$items = [];
		foreach ( $hex_presets as $hex => $preset_set ) {
			$count = count( $preset_set );
			if ( $count < self::HARDCODE_THRESHOLD ) {
				continue;
			}
			$items[] = [
				'id'     => '',
				'label'  => '#' . $hex,
				'detail' => 'Hardcoded color value #' . $hex . ' appears in ' . $count . ' presets — candidate for extraction into a global variable.',
			];
		}

		return [ 'check' => 'hardcoded_extraction_candidates', 'items' => $items ];
	}

	/**
	 * A2 — Variables defined on the site but not referenced by any preset or layout.
	 *
	 * An orphaned variable is a design-system smell — it may be a leftover from a
	 * previous design system or simply unused.
	 *
	 * @param array $all_var_ids    Flat map of id => label.
	 * @param array $preset_items   Flat array of preset items.
	 * @param array $layout_content Array of post_content strings.
	 * @return array{check: string, items: list<array{id: string, label: string, detail: string}>}
	 */
	private function check_orphaned_variables(
		array $all_var_ids,
		array $preset_items,
		array $layout_content,
		array $all_var_types = []
	): array {
		// Collect all referenced IDs from presets.
		$referenced = [];

		foreach ( $preset_items as $preset ) {
			$attrs_raw = DiviBlocParser::preset_attrs_to_string( $preset );
			foreach ( DiviBlocParser::extract_variable_refs( $attrs_raw ) as $ref ) {
				$referenced[ $ref['name'] ] = true;
			}
		}

		// Collect from layout post_content.
		foreach ( $layout_content as $content ) {
			foreach ( DiviBlocParser::extract_variable_refs( $content ) as $ref ) {
				$referenced[ $ref['name'] ] = true;
			}
		}

		$items = [];
		foreach ( $all_var_ids as $id => $label ) {
			if ( in_array( $id, self::DIVI_BUILTIN_IDS, true ) ) {
				continue;
			}
			if ( isset( $referenced[ $id ] ) ) {
				continue;
			}
			$items[] = [
				'id'       => $id,
				'label'    => $label,
				'var_type' => $all_var_types[ $id ] ?? ( str_starts_with( $id, 'gcid-' ) ? 'colors' : '' ),
				'detail'   => 'Defined but not referenced in any preset or layout.',
			];
		}

		return [ 'check' => 'orphaned_variables', 'items' => $items ];
	}

	/**
	 * W6 — Variable labels that are very similar to another variable's label.
	 *
	 * Uses a normalised token comparison: strips spaces/dashes/underscores,
	 * lowercases, then compares. Labels that collapse to the same token string
	 * are flagged. This catches cases like "Primary Blue" vs "primary-blue" vs
	 * "PrimaryBlue" — all three normalise to "primaryblue" and would be
	 * confusing in the editor.
	 *
	 * Only reports groups with 2 or more distinct IDs (not already flagged by
	 * duplicate_labels which checks identical labels).
	 *
	 * @param array $raw_colors Raw colors keyed by gcid-*.
	 * @param array $raw_vars   Raw non-color vars keyed by type => id => entry.
	 * @return array{check: string, items: list<array{id: string, label: string, detail: string}>}
	 */
	private function check_similar_variable_names(
		array $raw_colors,
		array $raw_vars
	): array {
		// Build: normalised_token => [ { id, label, original_label }, ... ]
		$by_token = [];

		foreach ( $raw_colors as $id => $entry ) {
			$label = trim( $entry['label'] ?? '' );
			if ( $label === '' ) { continue; }
			$token = preg_replace( '/[\s\-_]+/', '', strtolower( $label ) );
			$by_token[ $token ][] = [ 'id' => $id, 'label' => $label ];
		}

		foreach ( $raw_vars as $var_type => $type_vars ) {
			if ( ! is_array( $type_vars ) ) { continue; }
			foreach ( $type_vars as $id => $var ) {
				$label = trim( $var['label'] ?? '' );
				if ( $label === '' ) { continue; }
				$token = preg_replace( '/[\s\-_]+/', '', strtolower( $label ) );
				$by_token[ $token ][] = [ 'id' => $id, 'label' => $label ];
			}
		}

		$items = [];
		foreach ( $by_token as $token => $entries ) {
			if ( count( $entries ) < 2 ) { continue; }

			// Skip if all labels are identical (that's duplicate_labels territory).
			$distinct_labels = array_unique( array_column( $entries, 'label' ) );
			if ( count( $distinct_labels ) === 1 ) { continue; }

			$ids_str    = implode( ', ', array_column( $entries, 'id' ) );
			$labels_str = implode( ', ', $distinct_labels );
			$items[] = [
				'id'     => $ids_str,
				'label'  => $labels_str,
				'detail' => count( $entries ) . ' variables have similar names that normalise to the same token "' . $token . '": ' . $labels_str . ' — consider standardising the label format (e.g. all "Title Case" or all "kebab-case").',
			];
		}

		return [ 'check' => 'similar_variable_names', 'items' => $items ];
	}

	/**
	 * W7 — Inconsistent naming convention within a variable type.
	 *
	 * Detects when variables of the same type use mixed naming styles
	 * (e.g. some use "Title Case" and others use "kebab-case" or "snake_case").
	 * Only flagged when a type has at least 4 labelled variables and more than
	 * one naming style is in use.
	 *
	 * Style detection:
	 *   - "kebab-case"  — contains hyphens, no spaces
	 *   - "snake_case"  — contains underscores, no spaces
	 *   - "camelCase"   — no spaces/hyphens/underscores, mixed case starting lower
	 *   - "PascalCase"  — no separators, starts with uppercase
	 *   - "Title Case"  — words separated by spaces, each word capitalised
	 *   - "lower case"  — words separated by spaces, all lowercase
	 *   - "UPPER CASE"  — all uppercase
	 *   - "mixed"       — anything else
	 *
	 * @param array $raw_colors Raw colors keyed by gcid-*.
	 * @param array $raw_vars   Raw non-color vars keyed by type => id => entry.
	 * @return array{check: string, items: list<array{id: string, label: string, detail: string}>}
	 */
	private function check_naming_convention_inconsistency(
		array $raw_colors,
		array $raw_vars
	): array {
		// Collect labels per type.
		$by_type = [];

		foreach ( $raw_colors as $id => $entry ) {
			$label = trim( $entry['label'] ?? '' );
			if ( $label === '' ) { continue; }
			$by_type['colors'][] = [ 'id' => $id, 'label' => $label ];
		}

		foreach ( $raw_vars as $var_type => $type_vars ) {
			if ( ! is_array( $type_vars ) ) { continue; }
			foreach ( $type_vars as $id => $var ) {
				$label = trim( $var['label'] ?? '' );
				if ( $label === '' ) { continue; }
				$by_type[ $var_type ][] = [ 'id' => $id, 'label' => $label ];
			}
		}

		$items = [];

		foreach ( $by_type as $type => $entries ) {
			if ( count( $entries ) < 4 ) { continue; }

			$style_counts = [];
			foreach ( $entries as $entry ) {
				$style = $this->detect_naming_style( $entry['label'] );
				$style_counts[ $style ] = ( $style_counts[ $style ] ?? 0 ) + 1;
			}

			if ( count( $style_counts ) < 2 ) { continue; }

			// Build a summary of styles found.
			arsort( $style_counts );
			$style_summary = [];
			foreach ( $style_counts as $style => $count ) {
				$style_summary[] = $count . ' × ' . $style;
			}

			$type_label = ucfirst( $type );
			$items[] = [
				'id'     => '',
				'label'  => $type_label,
				'detail' => $type_label . ' variables use mixed naming styles: ' . implode( ', ', $style_summary ) . '. Consistent naming makes the variable list easier to scan in the Divi editor.',
			];
		}

		return [ 'check' => 'naming_convention_inconsistency', 'items' => $items ];
	}

	/**
	 * A3 — Presets with no variable references (fully hardcoded).
	 *
	 * A preset that contains no variable references is self-contained and
	 * cannot benefit from global design system changes. It is a sign that the
	 * preset was built with hardcoded values that could be extracted.
	 *
	 * @param array $preset_items Flat array of preset items.
	 * @return array{check: string, items: list<array{id: string, label: string, detail: string}>}
	 */
	private function check_preset_no_variable_refs( array $preset_items ): array {
		$items = [];

		foreach ( $preset_items as $preset ) {
			$preset_id   = $preset['id']   ?? '';
			$preset_name = $preset['name'] ?? $preset_id;
			$attrs_raw   = DiviBlocParser::preset_attrs_to_string( $preset );
			$refs        = DiviBlocParser::extract_variable_refs( $attrs_raw );

			if ( ! empty( $refs ) ) { continue; }

			$items[] = [
				'id'     => $preset_id,
				'label'  => $preset_name,
				'detail' => 'Preset "' . $preset_name . '" contains no variable references — all values are hardcoded. Consider extracting repeated values into global variables so this preset can respond to design system changes.',
			];
		}

		return [ 'check' => 'preset_no_variable_refs', 'items' => $items ];
	}

	/**
	 * A4 — Variable type distribution summary.
	 *
	 * Produces one advisory item per variable type showing the count and
	 * percentage. Flags any type that represents more than 60 % of all
	 * variables, which may indicate an unbalanced design system (e.g. hundreds
	 * of color variables but no typography tokens).
	 *
	 * @param array $raw_colors Raw colors keyed by gcid-*.
	 * @param array $raw_vars   Raw non-color vars keyed by type => id => entry.
	 * @return array{check: string, items: list<array{id: string, label: string, detail: string}>}
	 */
	private function check_variable_type_distribution(
		array $raw_colors,
		array $raw_vars
	): array {
		$counts = [];

		$color_count = count( $raw_colors );
		if ( $color_count > 0 ) {
			$counts['colors'] = $color_count;
		}

		foreach ( $raw_vars as $var_type => $type_vars ) {
			if ( ! is_array( $type_vars ) ) { continue; }
			$c = count( $type_vars );
			if ( $c > 0 ) {
				$counts[ $var_type ] = $c;
			}
		}

		if ( empty( $counts ) ) {
			return [ 'check' => 'variable_type_distribution', 'items' => [] ];
		}

		$total = array_sum( $counts );
		arsort( $counts );

		$items = [];
		foreach ( $counts as $type => $count ) {
			$pct = round( ( $count / $total ) * 100 );
			if ( $pct > 60 ) {
				$items[] = [
					'id'     => '',
					'label'  => ucfirst( $type ),
					'detail' => ucfirst( $type ) . ' variables make up ' . $pct . '% of your design system (' . $count . ' of ' . $total . ' total). A balanced design system typically covers colors, typography, spacing, and sizing. Consider whether other variable types are needed.',
				];
			}
		}

		// Always include one summary item showing the full distribution.
		$dist_parts = [];
		foreach ( $counts as $type => $count ) {
			$dist_parts[] = ucfirst( $type ) . ': ' . $count;
		}
		$items[] = [
			'id'     => '',
			'label'  => 'Distribution',
			'detail' => 'Variable type breakdown — ' . implode( ', ', $dist_parts ) . ' (total: ' . $total . ').',
		];

		return [ 'check' => 'variable_type_distribution', 'items' => $items ];
	}

	// ── Contextual Audit: Content-Dependent Checks ───────────────────────────

	/**
	 * E4 — Archived variables or presets referenced in published content.
	 *
	 * E2 already catches archived vars inside preset definitions. This check
	 * catches archived DSOs referenced directly in post_content of published
	 * pages/posts/layouts — a live site breakage.
	 *
	 * @param array $raw_colors   Raw colors keyed by gcid-*.
	 * @param array $raw_vars     Raw non-color vars keyed by type => id.
	 * @param array $preset_items Flat array of preset items.
	 * @param array $dso_usage    DSO usage index from ContentScanner.
	 * @param array $all_var_types Flat id => type map.
	 * @return array{check: string, items: list<array>}
	 */
	private function check_archived_dsos_in_content(
		array $raw_colors,
		array $raw_vars,
		array $preset_items,
		array $dso_usage,
		array $all_var_types = []
	): array {
		if ( empty( $dso_usage ) ) {
			return [ 'check' => 'archived_dsos_in_content', 'items' => [] ];
		}

		// Collect archived variable IDs.
		$archived_vars = [];
		foreach ( $raw_colors as $id => $entry ) {
			if ( ( $entry['status'] ?? 'active' ) !== 'active' ) {
				$archived_vars[ $id ] = $entry['label'] ?? $id;
			}
		}
		foreach ( $raw_vars as $var_type => $type_vars ) {
			if ( ! is_array( $type_vars ) ) { continue; }
			foreach ( $type_vars as $id => $var ) {
				if ( ( $var['status'] ?? 'active' ) !== 'active' ) {
					$archived_vars[ $id ] = $var['label'] ?? $id;
				}
			}
		}

		// Collect archived preset IDs.
		$archived_presets = [];
		foreach ( $preset_items as $preset ) {
			$status = $preset['status'] ?? 'active';
			if ( $status !== 'active' ) {
				$archived_presets[ $preset['id'] ] = $preset['name'] ?? $preset['id'];
			}
		}

		$items = [];

		foreach ( $dso_usage['variables'] ?? [] as $id => $entry ) {
			if ( ! isset( $archived_vars[ $id ] ) ) { continue; }
			$published_posts = array_filter(
				$entry['posts'] ?? [],
				fn( $p ) => ( $p['post_status'] ?? '' ) === 'publish'
			);
			if ( empty( $published_posts ) ) { continue; }
			$titles = implode( ', ', array_map( fn( $p ) => $p['post_title'] ?? '', $published_posts ) );
			$items[] = [
				'id'       => $id,
				'label'    => $archived_vars[ $id ],
				'var_type' => $all_var_types[ $id ] ?? ( str_starts_with( $id, 'gcid-' ) ? 'colors' : '' ),
				'detail'   => 'Archived variable is directly referenced in ' . count( $published_posts ) . ' published content item(s): ' . $titles . '. Published pages will render incorrectly.',
			];
		}

		foreach ( $dso_usage['presets'] ?? [] as $id => $entry ) {
			if ( ! isset( $archived_presets[ $id ] ) ) { continue; }
			$published_posts = array_filter(
				$entry['posts'] ?? [],
				fn( $p ) => ( $p['post_status'] ?? '' ) === 'publish'
			);
			if ( empty( $published_posts ) ) { continue; }
			$titles = implode( ', ', array_map( fn( $p ) => $p['post_title'] ?? '', $published_posts ) );
			$items[] = [
				'id'     => $id,
				'label'  => $archived_presets[ $id ],
				'detail' => 'Archived preset is applied in ' . count( $published_posts ) . ' published content item(s): ' . $titles . '. Published pages will render incorrectly.',
			];
		}

		return [ 'check' => 'archived_dsos_in_content', 'items' => $items ];
	}

	/**
	 * E5 — Variable or preset IDs referenced in published content but not defined on this site.
	 *
	 * E1 catches missing variable refs inside preset definitions. This check
	 * catches them when referenced directly in post_content (inline in layouts).
	 *
	 * @param array $all_var_ids  Flat id => label map.
	 * @param array $preset_items Flat array of preset items.
	 * @param array $dso_usage    DSO usage index from ContentScanner.
	 * @return array{check: string, items: list<array>}
	 */
	private function check_broken_dso_refs_in_content(
		array $all_var_ids,
		array $preset_items,
		array $dso_usage
	): array {
		if ( empty( $dso_usage ) ) {
			return [ 'check' => 'broken_dso_refs_in_content', 'items' => [] ];
		}

		$known_preset_ids = [];
		foreach ( $preset_items as $preset ) {
			$known_preset_ids[ $preset['id'] ] = true;
		}

		$items = [];

		foreach ( $dso_usage['variables'] ?? [] as $id => $entry ) {
			if ( in_array( $id, self::DIVI_BUILTIN_IDS, true ) ) { continue; }
			if ( isset( $all_var_ids[ $id ] ) ) { continue; }
			$published_posts = array_filter(
				$entry['posts'] ?? [],
				fn( $p ) => ( $p['post_status'] ?? '' ) === 'publish'
			);
			if ( empty( $published_posts ) ) { continue; }
			$titles = implode( ', ', array_map( fn( $p ) => $p['post_title'] ?? '', $published_posts ) );
			$items[] = [
				'id'     => $id,
				'label'  => '',
				'detail' => 'Variable ID "' . $id . '" is referenced in ' . count( $published_posts ) . ' published content item(s) but is not defined on this site: ' . $titles . '.',
			];
		}

		foreach ( $dso_usage['presets'] ?? [] as $id => $entry ) {
			if ( isset( $known_preset_ids[ $id ] ) ) { continue; }
			$published_posts = array_filter(
				$entry['posts'] ?? [],
				fn( $p ) => ( $p['post_status'] ?? '' ) === 'publish'
			);
			if ( empty( $published_posts ) ) { continue; }
			$titles = implode( ', ', array_map( fn( $p ) => $p['post_title'] ?? '', $published_posts ) );
			$items[] = [
				'id'     => $id,
				'label'  => '',
				'detail' => 'Preset ID "' . $id . '" is applied in ' . count( $published_posts ) . ' published content item(s) but is not defined on this site: ' . $titles . '.',
			];
		}

		return [ 'check' => 'broken_dso_refs_in_content', 'items' => $items ];
	}

	/**
	 * W8 — Presets that are not used in any scanned content item.
	 *
	 * A preset that exists in the design system but is not applied to any page,
	 * post, or layout may be stale and a candidate for removal.
	 *
	 * @param array $preset_items Flat array of preset items.
	 * @param array $dso_usage    DSO usage index from ContentScanner.
	 * @return array{check: string, items: list<array>}
	 */
	private function check_orphaned_presets(
		array $preset_items,
		array $dso_usage
	): array {
		if ( empty( $dso_usage ) ) {
			return [ 'check' => 'orphaned_presets', 'items' => [] ];
		}

		$used_preset_ids = array_keys( $dso_usage['presets'] ?? [] );

		$items = [];
		foreach ( $preset_items as $preset ) {
			$id   = $preset['id']   ?? '';
			$name = $preset['name'] ?? $id;
			if ( in_array( $id, $used_preset_ids, true ) ) { continue; }
			$items[] = [
				'id'     => $id,
				'label'  => $name,
				'detail' => 'Preset "' . $name . '" is not applied in any scanned content item. It may be unused and a candidate for removal.',
			];
		}

		return [ 'check' => 'orphaned_presets', 'items' => $items ];
	}

	/**
	 * W9 — Variables referenced directly in a large number of content items.
	 *
	 * A variable used by many content items directly (not via a preset) is
	 * high-impact: renaming or changing its value will affect widespread content.
	 * Designers should treat these variables with extra care.
	 *
	 * @param array $all_var_ids  Flat id => label map.
	 * @param array $dso_usage    DSO usage index from ContentScanner.
	 * @param array $all_var_types Flat id => type map.
	 * @return array{check: string, items: list<array>}
	 */
	private function check_high_impact_variables(
		array $all_var_ids,
		array $dso_usage,
		array $all_var_types = []
	): array {
		if ( empty( $dso_usage ) ) {
			return [ 'check' => 'high_impact_variables', 'items' => [] ];
		}

		$items = [];
		foreach ( $dso_usage['variables'] ?? [] as $id => $entry ) {
			$count = $entry['count'] ?? 0;
			if ( $count < self::HIGH_IMPACT_THRESHOLD ) { continue; }
			$label = $all_var_ids[ $id ] ?? ( $entry['label'] ?? $id );
			$items[] = [
				'id'       => $id,
				'label'    => $label,
				'var_type' => $all_var_types[ $id ] ?? ( str_starts_with( $id, 'gcid-' ) ? 'colors' : '' ),
				'detail'   => 'Variable "' . $label . '" is directly referenced in ' . $count . ' content items. Changes to this variable will have widespread impact — rename or change values with care.',
			];
		}

		// Sort by count descending so highest-impact appears first.
		usort( $items, fn( $a, $b ) => substr_count( $b['detail'], ' content items' ) <=> substr_count( $a['detail'], ' content items' ) );

		return [ 'check' => 'high_impact_variables', 'items' => $items ];
	}

	/**
	 * W10 — Inconsistent naming conventions within a preset module type.
	 *
	 * Extends the existing naming_convention_inconsistency check (W7, variables only)
	 * to cover preset names grouped by module type. A module with presets using
	 * mixed naming styles makes the editor dropdown hard to scan.
	 *
	 * @param array $raw_presets Raw presets array with 'module' and 'group' keys.
	 * @return array{check: string, items: list<array>}
	 */
	private function check_preset_naming_convention( array $raw_presets ): array {
		$items = [];

		foreach ( [ 'module', 'group' ] as $tier ) {
			foreach ( $raw_presets[ $tier ] ?? [] as $module_name => $module_data ) {
				$entries = [];
				foreach ( $module_data['items'] ?? [] as $preset_id => $preset ) {
					$name = trim( $preset['name'] ?? '' );
					if ( $name === '' ) { continue; }
					$entries[] = [ 'id' => $preset_id, 'label' => $name ];
				}

				if ( count( $entries ) < 4 ) { continue; }

				$style_counts = [];
				foreach ( $entries as $entry ) {
					$style = $this->detect_naming_style( $entry['label'] );
					$style_counts[ $style ] = ( $style_counts[ $style ] ?? 0 ) + 1;
				}

				if ( count( $style_counts ) < 2 ) { continue; }

				arsort( $style_counts );
				$style_summary = [];
				foreach ( $style_counts as $style => $count ) {
					$style_summary[] = $count . ' × ' . $style;
				}

				$tier_label = $tier === 'module' ? 'Element Preset' : 'Group Preset';
				$items[] = [
					'id'     => '',
					'label'  => $tier_label . ': ' . $module_name,
					'detail' => $tier_label . ' presets for "' . $module_name . '" use mixed naming styles: ' . implode( ', ', $style_summary ) . '. Consistent names make the preset dropdown easier to scan in the Divi editor.',
				];
			}
		}

		return [ 'check' => 'preset_naming_convention', 'items' => $items ];
	}

	/**
	 * A5 — Variables referenced directly in content, bypassing the preset system.
	 *
	 * When a variable is referenced directly in post_content rather than through
	 * a preset, an editor has applied design tokens inline. At low counts this is
	 * normal; at high counts it suggests the preset library is missing styles that
	 * designers need, or that presets are not being used as intended.
	 *
	 * Only variables that are referenced directly AND also exist in at least one
	 * preset are flagged — pure "content-only" variables (e.g. background images
	 * set per page) are excluded.
	 *
	 * @param array $all_var_ids  Flat id => label map.
	 * @param array $preset_items Flat array of preset items.
	 * @param array $dso_usage    DSO usage index from ContentScanner.
	 * @param array $all_var_types Flat id => type map.
	 * @return array{check: string, items: list<array>}
	 */
	private function check_variables_bypassing_presets(
		array $all_var_ids,
		array $preset_items,
		array $dso_usage,
		array $all_var_types = []
	): array {
		if ( empty( $dso_usage ) ) {
			return [ 'check' => 'variables_bypassing_presets', 'items' => [] ];
		}

		// Collect all variable IDs referenced in any preset.
		$vars_in_presets = [];
		foreach ( $preset_items as $preset ) {
			$attrs_raw = DiviBlocParser::preset_attrs_to_string( $preset );
			foreach ( DiviBlocParser::extract_variable_refs( $attrs_raw ) as $ref ) {
				$vars_in_presets[ $ref['name'] ] = true;
			}
		}

		$items = [];
		foreach ( $dso_usage['variables'] ?? [] as $id => $entry ) {
			// Only flag variables that are also used inside at least one preset.
			if ( ! isset( $vars_in_presets[ $id ] ) ) { continue; }
			if ( in_array( $id, self::DIVI_BUILTIN_IDS, true ) ) { continue; }

			$count = $entry['count'] ?? 0;
			if ( $count < 1 ) { continue; }

			$label = $all_var_ids[ $id ] ?? ( $entry['label'] ?? $id );
			$items[] = [
				'id'       => $id,
				'label'    => $label,
				'var_type' => $all_var_types[ $id ] ?? ( str_starts_with( $id, 'gcid-' ) ? 'colors' : '' ),
				'detail'   => 'Variable "' . $label . '" is referenced directly in ' . $count . ' content item(s) as well as inside preset definitions. Direct inline references bypass the preset system — consider whether a preset should be created or applied instead.',
			];
		}

		return [ 'check' => 'variables_bypassing_presets', 'items' => $items ];
	}

	/**
	 * A6 — Presets applied in exactly one content item (singleton presets).
	 *
	 * A preset used by only one content item may have been created for a one-off
	 * style need rather than as a reusable design system component. It is a
	 * candidate for inlining or consolidation.
	 *
	 * @param array $preset_items Flat array of preset items.
	 * @param array $dso_usage    DSO usage index from ContentScanner.
	 * @return array{check: string, items: list<array>}
	 */
	private function check_singleton_presets(
		array $preset_items,
		array $dso_usage
	): array {
		if ( empty( $dso_usage ) ) {
			return [ 'check' => 'singleton_presets', 'items' => [] ];
		}

		$preset_name_map = [];
		foreach ( $preset_items as $preset ) {
			$preset_name_map[ $preset['id'] ] = $preset['name'] ?? $preset['id'];
		}

		$items = [];
		foreach ( $dso_usage['presets'] ?? [] as $id => $entry ) {
			if ( ( $entry['count'] ?? 0 ) !== 1 ) { continue; }
			$name  = $preset_name_map[ $id ] ?? ( $entry['label'] ?? $id );
			$title = $entry['posts'][0]['post_title'] ?? '';
			$items[] = [
				'id'     => $id,
				'label'  => $name,
				'detail' => 'Preset "' . $name . '" is applied in only 1 content item ("' . $title . '"). Consider whether this preset is a reusable design system component or a one-off style that could be applied inline.',
			];
		}

		return [ 'check' => 'singleton_presets', 'items' => $items ];
	}

	/**
	 * A7 — Presets of the same module type whose variable reference sets overlap significantly.
	 *
	 * Two presets for the same module that reference most of the same variables
	 * may be near-duplicates that could be consolidated. Overlap is measured as
	 * |intersection| / |smaller set|. Only pairs where the smaller set has at
	 * least 3 variables are checked (to avoid false positives on tiny presets).
	 *
	 * @param array $raw_presets Raw presets array with 'module' and 'group' keys.
	 * @return array{check: string, items: list<array>}
	 */
	private function check_overlapping_presets( array $raw_presets ): array {
		$items = [];

		foreach ( [ 'module', 'group' ] as $tier ) {
			foreach ( $raw_presets[ $tier ] ?? [] as $module_name => $module_data ) {
				// Build id => var_ref_set for each preset in this module.
				$preset_var_sets = [];
				foreach ( $module_data['items'] ?? [] as $preset_id => $preset ) {
					$attrs_raw = DiviBlocParser::preset_attrs_to_string( $preset );
					$refs      = DiviBlocParser::extract_variable_refs( $attrs_raw );
					if ( empty( $refs ) ) { continue; }
					$var_ids = array_unique( array_column( $refs, 'name' ) );
					$preset_var_sets[ $preset_id ] = [
						'name' => $preset['name'] ?? $preset_id,
						'vars' => array_flip( $var_ids ),
					];
				}

				$preset_ids = array_keys( $preset_var_sets );

				for ( $i = 0; $i < count( $preset_ids ); $i++ ) {
					for ( $j = $i + 1; $j < count( $preset_ids ); $j++ ) {
						$id_a = $preset_ids[ $i ];
						$id_b = $preset_ids[ $j ];
						$vars_a = $preset_var_sets[ $id_a ]['vars'];
						$vars_b = $preset_var_sets[ $id_b ]['vars'];

						$size_a = count( $vars_a );
						$size_b = count( $vars_b );
						$smaller = min( $size_a, $size_b );

						if ( $smaller < 3 ) { continue; }

						$intersection = count( array_intersect_key( $vars_a, $vars_b ) );
						$overlap = $intersection / $smaller;

						if ( $overlap < self::OVERLAP_RATIO_THRESHOLD ) { continue; }

						$pct   = round( $overlap * 100 );
						$name_a = $preset_var_sets[ $id_a ]['name'];
						$name_b = $preset_var_sets[ $id_b ]['name'];
						$tier_label = $tier === 'module' ? 'Element Preset' : 'Group Preset';
						$items[] = [
							'id'     => $id_a . ', ' . $id_b,
							'label'  => $name_a . ' / ' . $name_b,
							'detail' => $tier_label . 's "' . $name_a . '" and "' . $name_b . '" for "' . $module_name . '" share ' . $pct . '% of their variable references (' . $intersection . ' of ' . $smaller . ' variables). These presets may be near-duplicates — consider consolidating them.',
						];
					}
				}
			}
		}

		return [ 'check' => 'overlapping_presets', 'items' => $items ];
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Load post_content strings from all Divi-relevant content for orphan scanning.
	 *
	 * Covers pages, blog posts, Divi Library layouts, Theme Builder templates,
	 * and all three canvas types (header / body / footer).  All non-auto-draft
	 * post statuses are included so that variables used only in drafts or private
	 * pages are not incorrectly flagged as orphaned.
	 *
	 * Limit raised to 1 000 to cover medium-to-large sites.  Sites with more
	 * content than this ceiling will produce a conservative orphan report (some
	 * live references may be missed).
	 *
	 * Returns an empty array when $wpdb is unavailable (test environment).
	 *
	 * @return string[]
	 */
	protected function load_layout_content(): array {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_content FROM {$wpdb->posts}
				 WHERE post_type IN (
				           'page',
				           'post',
				           'et_pb_layout',
				           'et_template',
				           'et_header_layout',
				           'et_body_layout',
				           'et_footer_layout'
				       )
				   AND post_status IN (
				           'publish','draft','pending','private','future','trash'
				       )
				 LIMIT %d",
				1000
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_column( $rows, 'post_content' );
	}

	/**
	 * Build a flat map of variable ID => label from raw vars and raw colors.
	 *
	 * @param array $raw_vars   Raw non-color vars keyed by type => id => entry.
	 * @param array $raw_colors Raw colors keyed by gcid-*.
	 * @return array<string, string> Map of id => label.
	 */
	private function collect_var_ids( array $raw_vars, array $raw_colors ): array {
		$ids = [];

		foreach ( $raw_vars as $var_type => $type_vars ) {
			if ( ! is_array( $type_vars ) ) {
				continue;
			}
			foreach ( $type_vars as $id => $var ) {
				$ids[ $id ] = $var['label'] ?? $id;
			}
		}

		foreach ( $raw_colors as $id => $entry ) {
			$ids[ $id ] = $entry['label'] ?? $id;
		}

		return $ids;
	}

	/**
	 * Build a flat map of variable ID => type from raw vars and raw colors.
	 *
	 * @param array $raw_vars   Raw non-color vars keyed by type => id => entry.
	 * @param array $raw_colors Raw colors keyed by gcid-*.
	 * @return array<string, string> Map of id => type string (e.g. 'colors', 'numbers').
	 */
	private function collect_var_types( array $raw_vars, array $raw_colors ): array {
		$types = [];

		foreach ( $raw_vars as $var_type => $type_vars ) {
			if ( ! is_array( $type_vars ) ) {
				continue;
			}
			foreach ( $type_vars as $id => $var ) {
				$types[ $id ] = $var_type;
			}
		}

		foreach ( $raw_colors as $id => $entry ) {
			$types[ $id ] = 'colors';
		}

		return $types;
	}

	/**
	 * Flatten the nested presets structure into a plain list of preset item arrays.
	 *
	 * @param array $raw_presets Raw presets array with 'module' and 'group' keys.
	 * @return array[] Each element has at least 'id', 'name', 'attrs' keys.
	 */
	private function collect_preset_items( array $raw_presets ): array {
		$items = [];

		foreach ( [ 'module', 'group' ] as $tier ) {
			foreach ( $raw_presets[ $tier ] ?? [] as $module_data ) {
				foreach ( $module_data['items'] ?? [] as $preset_id => $preset ) {
					$items[] = $preset;
				}
			}
		}

		return $items;
	}

	/**
	 * Normalise a hex color string to a lowercase 6-digit form without #.
	 *
	 * Returns empty string if the input is not a valid hex color.
	 *
	 * @param string $hex Input e.g. '#FFF', '#ffffff', '#aabbcc'.
	 * @return string Normalised hex digits (no #), or ''.
	 */
	/**
	 * Detect the naming style of a label string.
	 *
	 * @param string $label The variable label to classify.
	 * @return string One of: 'kebab-case', 'snake_case', 'camelCase', 'PascalCase',
	 *                        'Title Case', 'lower case', 'UPPER CASE', 'mixed'.
	 */
	private function detect_naming_style( string $label ): string {
		// kebab-case: hyphens as separators, no spaces or underscores.
		if ( preg_match( '/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/', $label ) ) {
			return 'kebab-case';
		}
		// snake_case: underscores as separators, no spaces or hyphens.
		if ( preg_match( '/^[a-z][a-z0-9]*(_[a-z0-9]+)+$/', $label ) ) {
			return 'snake_case';
		}
		// UPPER CASE: all caps with optional spaces.
		if ( $label === strtoupper( $label ) && preg_match( '/[A-Z]/', $label ) ) {
			return 'UPPER CASE';
		}
		// Title Case: space-separated words each starting with uppercase.
		if ( strpos( $label, ' ' ) !== false ) {
			$words       = explode( ' ', $label );
			$is_title    = true;
			$is_lower    = true;
			foreach ( $words as $word ) {
				if ( $word === '' ) { continue; }
				if ( ! preg_match( '/^[A-Z]/', $word ) ) { $is_title = false; }
				if ( strtolower( $word ) !== $word ) { $is_lower = false; }
			}
			if ( $is_lower ) { return 'lower case'; }
			if ( $is_title ) { return 'Title Case'; }
			return 'mixed';
		}
		// camelCase: starts lowercase, has at least one uppercase letter.
		if ( preg_match( '/^[a-z][a-z0-9]*[A-Z]/', $label ) ) {
			return 'camelCase';
		}
		// PascalCase: starts uppercase, no separators.
		if ( preg_match( '/^[A-Z][a-zA-Z0-9]+$/', $label ) ) {
			return 'PascalCase';
		}
		return 'mixed';
	}

	private function normalise_hex( string $hex ): string {
		$hex = ltrim( strtolower( trim( $hex ) ), '#' );

		if ( strlen( $hex ) === 3 ) {
			// Expand #rgb → #rrggbb
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( ! preg_match( '/^[0-9a-f]{6}(?:[0-9a-f]{2})?$/', $hex ) ) {
			return '';
		}

		// Use only 6 digits for comparison (ignore alpha channel differences).
		return substr( $hex, 0, 6 );
	}
}
