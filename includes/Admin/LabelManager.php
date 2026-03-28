<?php
/**
 * Label Manager — AJAX backend for the Manage tab.
 *
 * Handles two AJAX endpoints:
 *
 *   d5dsh_manage_load   — return the current Variables (and Global Colors) as JSON
 *   d5dsh_manage_save   — receive an edited variable list, snapshot, then save
 *
 * ## Data model exposed to the frontend
 *
 * Variables (et_divi_global_variables):
 *   Each item: { id, label, value, type, status, order }
 *   Types: colors | numbers | fonts | images | strings
 *
 * Global Colors (from et_divi_builder_global_presets_d5 → global_colors):
 *   NOTE: Global Colors live inside the presets option.  Their format is:
 *     { id => { id, label, value (hex), status } }
 *   We expose them as a flat list with type='global_color'.
 *
 * ## Save payload (POST JSON)
 *
 *   {
 *     "vars":          [ { id, label, value, type, status, order }, ... ],
 *     "global_colors": [ { id, label, value, status }, ... ]
 *   }
 *
 * Only `label`, `value`, `status`, and `order` are writable.
 * `id` and `type` are read-only — they are used only for matching.
 *
 * ## Bulk operations (applied server-side)
 *
 * The save endpoint accepts an optional `bulk` object:
 *
 *   {
 *     "op":      "prefix" | "suffix" | "find_replace" | "normalize",
 *     "scope":   "all" | "selected" | "type:colors" | ...,
 *     "find":    string,   // find_replace only
 *     "replace": string,   // find_replace only
 *     "value":   string,   // prefix / suffix text
 *     "case":    "title" | "upper" | "lower" | "snake" | "camel"  // normalize only
 *   }
 *
 * Bulk ops are applied to the label list first, THEN the edited list is saved.
 * This means the frontend does NOT need to apply bulk ops itself — it just
 * sends the current list + the bulk descriptor, and the server does both.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Data\PresetsRepository;
use D5DesignSystemHelper\Exporters\VarsExporter;

/**
 * Class LabelManager
 */
class LabelManager {

	/** Nonce action used by both AJAX endpoints. */
	const NONCE_ACTION = 'd5dsh_manage';

	/** Required capability. */
	const CAPABILITY = 'manage_options';

	// ── Registration ──────────────────────────────────────────────────────────

	/**
	 * Register the two AJAX actions.
	 * Call this from Plugin::boot() or AdminPage::register().
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_d5dsh_manage_load', [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_d5dsh_manage_save', [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_d5dsh_manage_xlsx', [ $this, 'ajax_xlsx' ] );
	}

	// ── AJAX: XLSX Export ─────────────────────────────────────────────────────

	/**
	 * AJAX handler: stream the current variables as an XLSX download.
	 *
	 * @return never
	 */
	public function ajax_xlsx(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		( new VarsExporter() )->stream_download();
	}

	// ── AJAX: Load ─────────────────────────────────────────────────────────

	/**
	 * AJAX handler: return the current variables + global colors as JSON.
	 *
	 * @return never
	 */
	public function ajax_load(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$vars_repo    = new VarsRepository();
		$presets_repo = new PresetsRepository();

		$vars          = $vars_repo->get_all();
		$global_colors = $this->get_global_colors( $presets_repo );

		wp_send_json_success( [
			'vars'          => $vars,
			'global_colors' => $global_colors,
		] );
	}

	// ── AJAX: Save ─────────────────────────────────────────────────────────

	/**
	 * AJAX handler: apply bulk ops (if any), snapshot, then save.
	 *
	 * @return never
	 */
	public function ajax_save(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$raw = file_get_contents( 'php://input' );
		if ( ! $raw ) {
			wp_send_json_error( [ 'message' => 'Empty request body.' ], 400 );
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			wp_send_json_error( [ 'message' => 'Invalid JSON payload.' ], 400 );
		}

		$vars_list    = $payload['vars']          ?? [];
		$gc_list      = $payload['global_colors'] ?? [];
		$bulk         = $payload['bulk']          ?? null;

		// Validate + sanitize input.
		$vars_list = $this->sanitize_vars_list( $vars_list );
		$gc_list   = $this->sanitize_gc_list( $gc_list );

		// Apply bulk label operation if requested.
		if ( is_array( $bulk ) && ! empty( $bulk['op'] ) ) {
			[ $vars_list, $gc_list ] = $this->apply_bulk( $vars_list, $gc_list, $bulk );
		}

		$vars_repo = new VarsRepository();

		// Snapshot vars BEFORE writing (if there are vars to save).
		if ( ! empty( $vars_list ) ) {
			$current_vars_raw    = $vars_repo->get_raw();
			$current_colors_raw  = $vars_repo->get_raw_colors();
			SnapshotManager::push( 'vars', $current_vars_raw, 'manage', 'Before label edit' );
		}

		// Save non-color vars.
		$vars_saved = false;
		if ( ! empty( $vars_list ) ) {
			$nested     = $vars_repo->denormalize( $vars_list );
			$vars_saved = $vars_repo->save_raw( $nested );

			// Save colors (from vars_list type=colors) back to et_divi.
			$existing_colors = $vars_repo->get_raw_colors();
			$updated_colors  = $vars_repo->denormalize_colors( $vars_list, $existing_colors );
			if ( ! empty( $updated_colors ) ) {
				$vars_repo->save_raw_colors( $updated_colors );
			}
		}

		// gc_saved kept for API compatibility — colors now saved above via vars_list.
		$gc_saved = false;

		// Return the freshly-saved state for the frontend to re-render.
		$updated_vars = $vars_repo->get_all();

		wp_send_json_success( [
			'vars'          => $updated_vars,
			'global_colors' => [],
			'vars_saved'    => $vars_saved,
			'gc_saved'      => $gc_saved,
		] );
	}

	// ── Global colors helpers ─────────────────────────────────────────────────

	/**
	 * Extract global colors from the presets option and return as a flat list.
	 *
	 * The presets option has this shape at the top level:
	 *   { module: {...}, group: {...}, global_colors?: {...} }
	 *
	 * global_colors is a dict: { 'gcid-xxx' => { id, label, value, status } }
	 *
	 * @param  PresetsRepository $repo
	 * @return array<int, array<string, string>>
	 */
	protected function get_global_colors( PresetsRepository $repo ): array {
		$raw = $repo->get_raw();
		$gc  = $raw['global_colors'] ?? [];

		if ( ! is_array( $gc ) ) {
			return [];
		}

		$list = [];
		foreach ( $gc as $id => $entry ) {
			$list[] = [
				'id'     => (string) ( $entry['id']     ?? $id ),
				'label'  => (string) ( $entry['label']  ?? '' ),
				'value'  => (string) ( $entry['value']  ?? '' ),
				'status' => (string) ( $entry['status'] ?? 'active' ),
				'type'   => 'global_color',
			];
		}
		return $list;
	}

	/**
	 * Write an edited global-colors list back into the presets option.
	 *
	 * @param  PresetsRepository                        $repo
	 * @param  array<int, array<string, string>>        $list
	 * @return bool
	 */
	protected function save_global_colors( PresetsRepository $repo, array $list ): bool {
		$raw = $repo->get_raw();

		// Rebuild the global_colors dict.
		$gc = [];
		foreach ( $list as $entry ) {
			$id = $entry['id'] ?? '';
			if ( ! $id ) {
				continue;
			}
			$gc[ $id ] = [
				'id'     => $id,
				'label'  => $entry['label']  ?? '',
				'value'  => $entry['value']  ?? '',
				'status' => $entry['status'] ?? 'active',
			];
		}

		$raw['global_colors'] = $gc;
		return $repo->save_raw( $raw );
	}

	// ── Sanitization ──────────────────────────────────────────────────────────

	/**
	 * Sanitize a submitted vars list.
	 * Strips any keys that are not writable; ensures required keys exist.
	 *
	 * @param  mixed $input
	 * @return array<int, array<string, mixed>>
	 */
	protected function sanitize_vars_list( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}
		$out = [];
		foreach ( $input as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			$out[] = [
				'id'     => sanitize_text_field( $item['id'] ),
				'label'  => sanitize_text_field( $item['label']  ?? '' ),
				'value'  => sanitize_text_field( $item['value']  ?? '' ),
				'type'   => sanitize_key( $item['type']   ?? 'numbers' ),
				'status' => sanitize_key( $item['status'] ?? 'active' ),
				'order'  => max( 1, (int) ( $item['order'] ?? 1 ) ),
			];
		}
		return $out;
	}

	/**
	 * Sanitize a submitted global-colors list.
	 *
	 * @param  mixed $input
	 * @return array<int, array<string, string>>
	 */
	protected function sanitize_gc_list( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}
		$out = [];
		foreach ( $input as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			$out[] = [
				'id'     => sanitize_text_field( $item['id'] ),
				'label'  => sanitize_text_field( $item['label']  ?? '' ),
				'value'  => sanitize_text_field( $item['value']  ?? '' ),
				'status' => sanitize_key( $item['status'] ?? 'active' ),
			];
		}
		return $out;
	}

	// ── Bulk operations ───────────────────────────────────────────────────────

	/**
	 * Apply a bulk label operation to vars and/or global colors.
	 *
	 * @param  array<int, array<string, mixed>> $vars
	 * @param  array<int, array<string, string>> $gc
	 * @param  array<string, string>             $bulk
	 * @return array{ 0: array, 1: array }  [ $vars, $gc ]
	 */
	protected function apply_bulk( array $vars, array $gc, array $bulk ): array {
		$op    = sanitize_key( $bulk['op'] ?? '' );
		$scope = sanitize_text_field( $bulk['scope'] ?? 'all' );

		$apply_to_vars = $this->scope_includes( $scope, 'vars' );
		$apply_to_gc   = $this->scope_includes( $scope, 'global_colors' );

		// For type-scoped ops on vars, limit to the specified var type.
		$type_filter = null;
		if ( str_starts_with( $scope, 'type:' ) ) {
			$type_filter   = substr( $scope, 5 );
			$apply_to_vars = true;
			$apply_to_gc   = false;
		}

		$fn = match ( $op ) {
			'prefix'       => fn( string $label ) => sanitize_text_field( $bulk['value'] ?? '' ) . $label,
			'suffix'       => fn( string $label ) => $label . sanitize_text_field( $bulk['value'] ?? '' ),
			'find_replace' => fn( string $label ) => str_replace(
				$bulk['find']    ?? '',
				$bulk['replace'] ?? '',
				$label
			),
			'normalize'    => fn( string $label ) => $this->normalize_label(
				$label,
				sanitize_key( $bulk['case'] ?? 'title' )
			),
			default => null,
		};

		if ( $fn === null ) {
			return [ $vars, $gc ];
		}

		if ( $apply_to_vars ) {
			foreach ( $vars as &$item ) {
				if ( $type_filter !== null && $item['type'] !== $type_filter ) {
					continue;
				}
				$item['label'] = $fn( (string) $item['label'] );
			}
			unset( $item );
		}

		if ( $apply_to_gc ) {
			foreach ( $gc as &$item ) {
				$item['label'] = $fn( (string) $item['label'] );
			}
			unset( $item );
		}

		return [ $vars, $gc ];
	}

	/**
	 * Determine whether a scope descriptor includes the given section.
	 *
	 * @param  string $scope   'all' | 'vars' | 'global_colors' | 'type:colors' | ...
	 * @param  string $section 'vars' | 'global_colors'
	 * @return bool
	 */
	protected function scope_includes( string $scope, string $section ): bool {
		if ( $scope === 'all' ) {
			return true;
		}
		if ( $scope === $section ) {
			return true;
		}
		// type: prefix applies to vars only.
		if ( str_starts_with( $scope, 'type:' ) ) {
			return $section === 'vars';
		}
		return false;
	}

	/**
	 * Convert a label string to the requested case style.
	 *
	 * @param  string $label
	 * @param  string $case  'title' | 'upper' | 'lower' | 'snake' | 'camel'
	 * @return string
	 */
	protected function normalize_label( string $label, string $case ): string {
		return match ( $case ) {
			'title'  => ucwords( strtolower( $label ) ),
			'upper'  => strtoupper( $label ),
			'lower'  => strtolower( $label ),
			'snake'  => strtolower( preg_replace( '/[\s\-]+/', '_', $label ) ?? $label ),
			'camel'  => lcfirst( str_replace( ' ', '', ucwords( strtolower( $label ) ) ) ),
			default  => $label,
		};
	}
}
