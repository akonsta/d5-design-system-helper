<?php
/**
 * ImpactAnalyzer — answers "what breaks if I delete this DSO?"
 *
 * Given a variable ID or preset ID, returns:
 *   - direct_content  : content items that reference it directly in post_content
 *   - via_presets     : per-preset breakdown → which content uses that preset
 *   - containing_presets : for a variable, which preset definitions embed it
 *   - dep_tree        : hierarchical dependency tree for the Dependencies tab
 *
 * The analyzer runs a targeted scan of the database on every request. It
 * intentionally does NOT cache results — data must be current when a user
 * is deciding whether to delete a DSO.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

use D5DesignSystemHelper\Data\PresetsRepository;
use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Util\DiviBlocParser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ImpactAnalyzer
 */
class ImpactAnalyzer {

	/** Nonce action — shares the audit nonce so no extra JS localisation is needed. */
	const NONCE_ACTION = 'd5dsh_audit_nonce';

	/** Required capability. */
	const CAPABILITY = 'manage_options';

	/** Post types to scan — mirrors ContentScanner::SCANNED_POST_TYPES. */
	const SCANNED_POST_TYPES = [
		'page',
		'post',
		'et_pb_layout',
		'et_template',
		'et_header_layout',
		'et_body_layout',
		'et_footer_layout',
	];

	/** Post statuses to scan — mirrors ContentScanner::SCANNED_STATUSES. */
	const SCANNED_STATUSES = [
		'publish',
		'draft',
		'pending',
		'private',
		'future',
		'trash',
	];

	/** Maximum posts to scan (matches ContentScanner). */
	const CONTENT_LIMIT = 1000;

	// ── AJAX endpoint ─────────────────────────────────────────────────────────

	/**
	 * Register AJAX actions.
	 */
	public function register(): void {
		add_action( 'wp_ajax_d5dsh_impact_analyze', [ $this, 'ajax_analyze' ] );
	}

	/**
	 * AJAX handler for d5dsh_impact_analyze.
	 *
	 * Expects JSON body: { dso_type: "variable"|"preset", dso_id: string }
	 */
	public function ajax_analyze(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$body = json_decode( file_get_contents( 'php://input' ), true );
		if ( ! is_array( $body ) ) {
			wp_send_json_error( [ 'message' => 'Invalid request body.' ], 400 );
		}

		$dso_type = sanitize_text_field( $body['dso_type'] ?? '' );
		$dso_id   = sanitize_text_field( $body['dso_id']   ?? '' );

		if ( ! in_array( $dso_type, [ 'variable', 'preset' ], true ) || $dso_id === '' ) {
			wp_send_json_error( [ 'message' => 'dso_type and dso_id are required.' ], 400 );
		}

		$result = $this->analyze( $dso_type, $dso_id );
		wp_send_json_success( $result );
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Analyze the impact of removing a DSO.
	 *
	 * @param string $dso_type  'variable' or 'preset'
	 * @param string $dso_id    The variable or preset ID
	 * @return array{
	 *   label:               string,
	 *   dso_type:            string,
	 *   direct_content:      array,
	 *   via_presets:         array,
	 *   containing_presets:  array,
	 *   dep_tree:            array,
	 * }
	 */
	public function analyze( string $dso_type, string $dso_id ): array {
		$preset_var_map = $this->build_preset_var_map();
		$content_rows   = $this->load_content_rows();
		$label          = $this->resolve_label( $dso_type, $dso_id );

		if ( $dso_type === 'variable' ) {
			return $this->analyze_variable( $dso_id, $label, $preset_var_map, $content_rows );
		}

		return $this->analyze_preset( $dso_id, $label, $preset_var_map, $content_rows );
	}

	// ── Variable analysis ─────────────────────────────────────────────────────

	/**
	 * @param string $var_id
	 * @param string $label
	 * @param array  $preset_var_map  preset_id → [ { type, name }, ... ]
	 * @param array  $content_rows    raw post rows
	 * @return array
	 */
	private function analyze_variable(
		string $var_id,
		string $label,
		array $preset_var_map,
		array $content_rows
	): array {
		// Presets that directly contain this variable.
		$containing_presets = $this->find_presets_containing_var( $var_id, $preset_var_map );
		$containing_ids     = array_column( $containing_presets, 'preset_id' );

		// Content that directly references this variable in post_content.
		$direct_content = [];
		// Content that references it via a preset.
		$via_presets_map = []; // preset_id → [ post_ref, ... ]

		foreach ( $content_rows as $row ) {
			$scanned = $this->scan_row( $row );

			// Direct variable reference.
			$has_direct = false;
			foreach ( $scanned['var_refs'] as $ref ) {
				if ( ( $ref['name'] ?? '' ) === $var_id ) {
					$has_direct = true;
					break;
				}
			}
			if ( $has_direct ) {
				$direct_content[] = $this->post_ref( $scanned );
			}

			// Via preset: any preset in this content that contains the variable.
			foreach ( $scanned['preset_refs'] as $pid ) {
				if ( in_array( $pid, $containing_ids, true ) ) {
					$via_presets_map[ $pid ][] = $this->post_ref( $scanned );
				}
			}
		}

		// Build via_presets array (preset → content list).
		$preset_labels = $this->build_preset_label_map();
		$via_presets   = [];
		foreach ( $via_presets_map as $pid => $posts ) {
			$via_presets[] = [
				'preset_id'    => $pid,
				'preset_label' => $preset_labels[ $pid ] ?? $pid,
				'content'      => array_values( $this->unique_post_refs( $posts ) ),
			];
		}

		// Dependency tree: variable → presets that contain it → content using those presets.
		$dep_tree = [
			'id'       => $var_id,
			'label'    => $label,
			'type'     => 'variable',
			'children' => [],
		];
		foreach ( $via_presets as $pdata ) {
			$dep_tree['children'][] = [
				'id'       => $pdata['preset_id'],
				'label'    => $pdata['preset_label'],
				'type'     => 'preset',
				'children' => array_map(
					fn( $p ) => [ 'id' => (string) $p['post_id'], 'label' => $p['post_title'], 'type' => 'content', 'status' => $p['post_status'], 'post_type' => $p['post_type'], 'children' => [] ],
					$pdata['content']
				),
			];
		}
		// Also add direct-content references under a virtual "direct" branch if any.
		if ( ! empty( $direct_content ) ) {
			array_unshift( $dep_tree['children'], [
				'id'       => '__direct__',
				'label'    => 'Direct references',
				'type'     => 'group',
				'children' => array_map(
					fn( $p ) => [ 'id' => (string) $p['post_id'], 'label' => $p['post_title'], 'type' => 'content', 'status' => $p['post_status'], 'post_type' => $p['post_type'], 'children' => [] ],
					$direct_content
				),
			] );
		}

		return [
			'label'              => $label,
			'dso_type'           => 'variable',
			'dso_id'             => $var_id,
			'direct_content'     => array_values( $this->unique_post_refs( $direct_content ) ),
			'via_presets'        => $via_presets,
			'containing_presets' => $containing_presets,
			'dep_tree'           => $dep_tree,
		];
	}

	// ── Preset analysis ───────────────────────────────────────────────────────

	/**
	 * @param string $preset_id
	 * @param string $label
	 * @param array  $preset_var_map
	 * @param array  $content_rows
	 * @return array
	 */
	private function analyze_preset(
		string $preset_id,
		string $label,
		array $preset_var_map,
		array $content_rows
	): array {
		$var_refs_in_preset = $preset_var_map[ $preset_id ] ?? [];
		$var_labels         = $this->build_var_label_map();

		// All content that uses this preset.
		$direct_content = [];
		foreach ( $content_rows as $row ) {
			$scanned = $this->scan_row( $row );
			if ( in_array( $preset_id, $scanned['preset_refs'], true ) ) {
				$direct_content[] = $this->post_ref( $scanned );
			}
		}

		// Variables the preset contains.
		$containing_presets = []; // For consistency in response shape — presets have no "containing" presets.
		$vars_in_preset     = [];
		foreach ( $var_refs_in_preset as $ref ) {
			$vid = $ref['name'] ?? '';
			if ( $vid === '' ) { continue; }
			$vars_in_preset[] = [
				'var_id'   => $vid,
				'var_label' => $var_labels[ $vid ] ?? $vid,
				'var_type'  => $ref['type'] ?? '',
			];
		}

		// Dependency tree: preset → variables it contains + content using it.
		$dep_tree = [
			'id'       => $preset_id,
			'label'    => $label,
			'type'     => 'preset',
			'children' => [],
		];

		if ( ! empty( $vars_in_preset ) ) {
			$dep_tree['children'][] = [
				'id'       => '__vars__',
				'label'    => 'Variables in this preset',
				'type'     => 'group',
				'children' => array_map(
					fn( $v ) => [ 'id' => $v['var_id'], 'label' => $v['var_label'], 'type' => 'variable', 'var_type' => $v['var_type'], 'children' => [] ],
					$vars_in_preset
				),
			];
		}

		if ( ! empty( $direct_content ) ) {
			$dep_tree['children'][] = [
				'id'       => '__content__',
				'label'    => 'Content using this preset',
				'type'     => 'group',
				'children' => array_map(
					fn( $p ) => [ 'id' => (string) $p['post_id'], 'label' => $p['post_title'], 'type' => 'content', 'status' => $p['post_status'], 'post_type' => $p['post_type'], 'children' => [] ],
					$direct_content
				),
			];
		}

		return [
			'label'              => $label,
			'dso_type'           => 'preset',
			'dso_id'             => $preset_id,
			'direct_content'     => array_values( $this->unique_post_refs( $direct_content ) ),
			'via_presets'        => [],
			'containing_presets' => $vars_in_preset,
			'dep_tree'           => $dep_tree,
		];
	}

	// ── Data helpers ──────────────────────────────────────────────────────────

	/**
	 * Build preset_id → [ { type, name }, ... ] map.
	 */
	protected function build_preset_var_map(): array {
		$repo = new PresetsRepository();
		$raw  = $repo->get_raw();
		$map  = [];

		foreach ( [ 'module', 'group' ] as $group ) {
			foreach ( $raw[ $group ] ?? [] as $module_presets ) {
				foreach ( $module_presets['items'] ?? [] as $preset_id => $preset ) {
					$attrs_string      = DiviBlocParser::preset_attrs_to_string( $preset );
					$map[ $preset_id ] = DiviBlocParser::extract_variable_refs( $attrs_string );
				}
			}
		}

		return $map;
	}

	/**
	 * Return [ { preset_id, preset_label, module_name, var_count }, ... ]
	 * for all presets that contain the given variable ID.
	 */
	private function find_presets_containing_var( string $var_id, array $preset_var_map ): array {
		$repo         = new PresetsRepository();
		$raw          = $repo->get_raw();
		$preset_meta  = []; // preset_id → { name, module_name }
		foreach ( [ 'module', 'group' ] as $group ) {
			foreach ( $raw[ $group ] ?? [] as $module_name => $module_presets ) {
				foreach ( $module_presets['items'] ?? [] as $pid => $preset ) {
					$preset_meta[ $pid ] = [
						'name'        => $preset['name']       ?? $pid,
						'module_name' => $preset['moduleName'] ?? $module_name,
					];
				}
			}
		}

		$result = [];
		foreach ( $preset_var_map as $pid => $refs ) {
			foreach ( $refs as $ref ) {
				if ( ( $ref['name'] ?? '' ) === $var_id ) {
					$result[] = [
						'preset_id'    => $pid,
						'preset_label' => $preset_meta[ $pid ]['name']        ?? $pid,
						'module_name'  => $preset_meta[ $pid ]['module_name'] ?? '',
						'var_count'    => count( $preset_var_map[ $pid ] ),
					];
					break; // found in this preset — no need to keep scanning refs
				}
			}
		}

		return $result;
	}

	/**
	 * Load all content rows from the database (same query pattern as ContentScanner).
	 */
	protected function load_content_rows(): array {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return [];
		}

		$types_in    = implode( ',', array_fill( 0, count( self::SCANNED_POST_TYPES ), '%s' ) );
		$statuses_in = implode( ',', array_fill( 0, count( self::SCANNED_STATUSES ), '%s' ) );

		$prepare_args = array_merge(
			self::SCANNED_POST_TYPES,
			self::SCANNED_STATUSES,
			[ self::CONTENT_LIMIT ]
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID AS post_id, post_type, post_status, post_title, post_modified, post_content, post_parent
				 FROM {$wpdb->posts}
				 WHERE post_type IN ({$types_in})
				   AND post_status IN ({$statuses_in})
				   AND post_status != 'auto-draft'
				 ORDER BY post_modified DESC
				 LIMIT %d",
				$prepare_args
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Scan a single content row for var_refs and preset_refs.
	 */
	private function scan_row( array $row ): array {
		$content     = $row['post_content'] ?? '';
		$var_refs    = DiviBlocParser::extract_variable_refs( $content );
		$preset_refs = DiviBlocParser::extract_preset_refs( $content );

		return [
			'post_id'     => (int) $row['post_id'],
			'post_type'   => $row['post_type']   ?? '',
			'post_status' => $row['post_status'] ?? '',
			'post_title'  => $row['post_title']  ?? '',
			'var_refs'    => $var_refs,
			'preset_refs' => $preset_refs,
		];
	}

	/**
	 * Build a compact post reference array.
	 */
	private function post_ref( array $scanned ): array {
		return [
			'post_id'    => $scanned['post_id'],
			'post_title' => $scanned['post_title'] ?: '(untitled)',
			'post_type'  => $scanned['post_type'],
			'post_status' => $scanned['post_status'],
		];
	}

	/**
	 * Deduplicate post refs by post_id.
	 */
	private function unique_post_refs( array $refs ): array {
		$seen   = [];
		$result = [];
		foreach ( $refs as $ref ) {
			$id = $ref['post_id'];
			if ( ! isset( $seen[ $id ] ) ) {
				$seen[ $id ]  = true;
				$result[ $id ] = $ref;
			}
		}
		return $result;
	}

	/**
	 * Build var_id → label map from VarsRepository.
	 */
	private function build_var_label_map(): array {
		$repo = new VarsRepository();
		$map  = [];
		foreach ( $repo->get_all() as $var ) {
			$map[ $var['id'] ] = $var['label'] ?? $var['id'];
		}
		return $map;
	}

	/**
	 * Build preset_id → label map from PresetsRepository.
	 */
	private function build_preset_label_map(): array {
		$repo = new PresetsRepository();
		$raw  = $repo->get_raw();
		$map  = [];
		foreach ( [ 'module', 'group' ] as $group ) {
			foreach ( $raw[ $group ] ?? [] as $module_presets ) {
				foreach ( $module_presets['items'] ?? [] as $pid => $preset ) {
					$map[ $pid ] = $preset['name'] ?? $pid;
				}
			}
		}
		return $map;
	}

	/**
	 * Resolve the human-readable label for a DSO.
	 */
	private function resolve_label( string $dso_type, string $dso_id ): string {
		if ( $dso_type === 'variable' ) {
			return $this->build_var_label_map()[ $dso_id ] ?? $dso_id;
		}
		return $this->build_preset_label_map()[ $dso_id ] ?? $dso_id;
	}
}
