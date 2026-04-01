<?php
/**
 * MergeManager — merge two variables, redirecting all preset references.
 *
 * The merge operation:
 *   1. Takes a snapshot of the current presets (before state).
 *   2. Scans every preset's attrs and styleAttrs for the retire_id.
 *   3. Replaces retire_id → keep_id everywhere in the serialized JSON blob.
 *   4. Saves the updated presets.
 *   5. Archives the retired variable via VarsRepository.
 *
 * Two AJAX endpoints:
 *   d5dsh_merge_preview — dry-run: returns which presets would be updated.
 *   d5dsh_merge_vars    — executes the merge.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

use D5DesignSystemHelper\Admin\SnapshotManager;
use D5DesignSystemHelper\Data\PresetsRepository;
use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Util\DiviBlocParser;
use D5DesignSystemHelper\Util\DebugLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MergeManager
 */
class MergeManager {

	const NONCE_ACTION = 'd5dsh_manage';
	const CAPABILITY   = 'manage_options';

	// ── Registration ──────────────────────────────────────────────────────────

	public function register(): void {
		add_action( 'wp_ajax_d5dsh_merge_preview', [ $this, 'ajax_preview' ] );
		add_action( 'wp_ajax_d5dsh_merge_vars',    [ $this, 'ajax_merge'   ] );
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/**
	 * Dry-run: return the list of presets that reference retire_id.
	 * Expects JSON body: { retire_id: string }
	 */
	public function ajax_preview(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$body      = json_decode( file_get_contents( 'php://input' ), true );
		$retire_id = sanitize_text_field( $body['retire_id'] ?? '' );
		if ( $retire_id === '' ) {
			wp_send_json_error( [ 'message' => 'retire_id is required.' ], 400 );
		}

		try {
			$affected = $this->find_affected_presets( $retire_id );
		} catch ( \Throwable $e ) {
			DebugLogger::send_error( $e, __METHOD__, 'Merge preview failed.' );
		}
		wp_send_json_success( [ 'affected_presets' => $affected, 'count' => count( $affected ) ] );
	}

	/**
	 * Execute the merge.
	 * Expects JSON body: { keep_id: string, retire_id: string }
	 */
	public function ajax_merge(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$body      = json_decode( file_get_contents( 'php://input' ), true );
		$keep_id   = sanitize_text_field( $body['keep_id']   ?? '' );
		$retire_id = sanitize_text_field( $body['retire_id'] ?? '' );

		if ( $keep_id === '' || $retire_id === '' ) {
			wp_send_json_error( [ 'message' => 'keep_id and retire_id are required.' ], 400 );
		}
		if ( $keep_id === $retire_id ) {
			wp_send_json_error( [ 'message' => 'keep_id and retire_id must differ.' ], 400 );
		}

		try {
			$result = $this->merge( $keep_id, $retire_id );
		} catch ( \Throwable $e ) {
			DebugLogger::send_error( $e, __METHOD__, 'Merge failed.' );
		}
		if ( isset( $result['error'] ) ) {
			wp_send_json_error( [ 'message' => $result['error'] ] );
		}
		wp_send_json_success( $result );
	}

	// ── Core logic ────────────────────────────────────────────────────────────

	/**
	 * Execute the merge and return a result summary.
	 *
	 * @return array{ updated_presets: int, retired_label: string }|array{ error: string }
	 */
	public function merge( string $keep_id, string $retire_id ): array {
		$presets_repo = new PresetsRepository();
		$vars_repo    = new VarsRepository();

		$raw = $presets_repo->get_raw();

		// Snapshot before state.
		SnapshotManager::push( 'presets', $raw, 'manage', 'Before merge: retire ' . $retire_id );

		// Walk every preset and replace retire_id → keep_id in the JSON blob.
		$updated_count = 0;
		foreach ( [ 'module', 'group' ] as $group ) {
			if ( ! isset( $raw[ $group ] ) || ! is_array( $raw[ $group ] ) ) {
				continue;
			}
			foreach ( $raw[ $group ] as $module_name => &$module_presets ) {
				if ( ! isset( $module_presets['items'] ) || ! is_array( $module_presets['items'] ) ) {
					continue;
				}
				foreach ( $module_presets['items'] as $preset_id => &$preset ) {
					$changed = false;

					// Replace in attrs.
					if ( isset( $preset['attrs'] ) ) {
						$json    = wp_json_encode( $preset['attrs'] );
						$updated = str_replace( $retire_id, $keep_id, $json );
						if ( $updated !== $json ) {
							$decoded = json_decode( $updated, true );
							if ( is_array( $decoded ) ) {
								$preset['attrs'] = $decoded;
								$changed = true;
							}
						}
					}

					// Replace in styleAttrs.
					if ( isset( $preset['styleAttrs'] ) ) {
						$json    = wp_json_encode( $preset['styleAttrs'] );
						$updated = str_replace( $retire_id, $keep_id, $json );
						if ( $updated !== $json ) {
							$decoded = json_decode( $updated, true );
							if ( is_array( $decoded ) ) {
								$preset['styleAttrs'] = $decoded;
								$changed = true;
							}
						}
					}

					// Replace in groupPresets (option group presets).
					if ( isset( $preset['groupPresets'] ) ) {
						$json    = wp_json_encode( $preset['groupPresets'] );
						$updated = str_replace( $retire_id, $keep_id, $json );
						if ( $updated !== $json ) {
							$decoded = json_decode( $updated, true );
							if ( is_array( $decoded ) ) {
								$preset['groupPresets'] = $decoded;
								$changed = true;
							}
						}
					}

					if ( $changed ) {
						$preset['updated'] = time();
						$updated_count++;
					}
				}
				unset( $preset );
			}
			unset( $module_presets );
		}

		// Save updated presets.
		$presets_repo->save_raw( $raw );

		// Archive the retired variable.
		$retired_label = $this->archive_variable( $vars_repo, $retire_id );

		return [
			'updated_presets' => $updated_count,
			'retired_label'   => $retired_label,
			'keep_id'         => $keep_id,
			'retire_id'       => $retire_id,
		];
	}

	/**
	 * Return list of presets that reference retire_id.
	 *
	 * @return array[] Each item: { preset_id, preset_label, module_name }
	 */
	private function find_affected_presets( string $retire_id ): array {
		$repo    = new PresetsRepository();
		$raw     = $repo->get_raw();
		$result  = [];

		foreach ( [ 'module', 'group' ] as $group ) {
			foreach ( $raw[ $group ] ?? [] as $module_name => $module_presets ) {
				foreach ( $module_presets['items'] ?? [] as $preset_id => $preset ) {
					$blob = wp_json_encode( [
						'attrs'        => $preset['attrs']        ?? [],
						'styleAttrs'   => $preset['styleAttrs']   ?? [],
						'groupPresets' => $preset['groupPresets']  ?? [],
					] );
					if ( str_contains( $blob, $retire_id ) ) {
						$result[] = [
							'preset_id'    => $preset_id,
							'preset_label' => $preset['name']       ?? $preset_id,
							'module_name'  => $preset['moduleName'] ?? $module_name,
						];
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Set a variable's status to 'archived'.
	 *
	 * @return string The variable's label (for the success response).
	 */
	private function archive_variable( VarsRepository $vars_repo, string $var_id ): string {
		$all   = $vars_repo->get_all();
		$label = '';

		// Find the variable and grab its label.
		foreach ( $all as $var ) {
			if ( ( $var['id'] ?? '' ) === $var_id ) {
				$label = $var['label'] ?? $var_id;
				break;
			}
		}

		// Modify the flat list — set status to archived.
		$updated = array_map( function ( $var ) use ( $var_id ) {
			if ( ( $var['id'] ?? '' ) === $var_id ) {
				$var['status'] = 'archived';
			}
			return $var;
		}, $all );

		// Snapshot vars before archiving.
		SnapshotManager::push( 'vars', $vars_repo->get_raw(), 'manage', 'Before merge: archive ' . $var_id );

		// Denormalize and save.
		$nested = $vars_repo->denormalize( $updated );
		$vars_repo->save_raw( $nested );

		// Handle colors separately.
		$existing_colors = get_option( 'et_divi', [] );
		$updated_colors  = $vars_repo->denormalize_colors( $updated, $existing_colors );
		$vars_repo->save_raw_colors( $updated_colors );

		return $label;
	}
}
