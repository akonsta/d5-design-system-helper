<?php
/**
 * Presets Manager — AJAX backend for the Manage tab (Presets sections).
 *
 * Handles two AJAX endpoints:
 *
 *   d5dsh_presets_manage_load  — return flattened element + group presets as JSON
 *   d5dsh_presets_manage_save  — receive changed name rows, snapshot, re-nest, save
 *
 * ## Data model exposed to the frontend
 *
 * element_presets:
 *   [ { preset_id, module_name, name, version, is_default, order }, ... ]
 *
 * group_presets:
 *   [ { preset_id, group_id, group_name, name, version, module_name, is_default, order }, ... ]
 *
 * ## Save payload (POST JSON)
 *
 *   {
 *     "element_presets": [ { preset_id, module_name, name }, ... ],
 *     "group_presets":   [ { preset_id, group_id, name }, ... ]
 *   }
 *
 * Only `name` is writable right now. All other fields are read-only (used for
 * matching). is_default editing is fully wired on the PHP side but disabled —
 * see comments in ajax_save() for how to enable it.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Data\PresetsRepository;
use D5DesignSystemHelper\Exporters\PresetsExporter;

/**
 * Class PresetsManager
 */
class PresetsManager {

	/** Nonce action used by both AJAX endpoints. */
	const NONCE_ACTION = 'd5dsh_presets_manage';

	/** Required capability. */
	const CAPABILITY = 'manage_options';

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Register the two AJAX actions.
	 * Call this from AdminPage::register_hooks().
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_d5dsh_presets_manage_load', [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_d5dsh_presets_manage_save', [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_d5dsh_presets_manage_xlsx', [ $this, 'ajax_xlsx' ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: Load
	// -------------------------------------------------------------------------

	/**
	 * Return flattened element + group presets as JSON.
	 *
	 * @return never
	 */
	public function ajax_load(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$repo = new PresetsRepository();
		$raw  = $repo->get_raw();

		wp_send_json_success( [
			'element_presets' => $this->flatten_element_presets( $raw ),
			'group_presets'   => $this->flatten_group_presets( $raw ),
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: Save
	// -------------------------------------------------------------------------

	/**
	 * Validate, snapshot, apply name changes, save.
	 *
	 * @return never
	 */
	public function ajax_save(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$raw_body = file_get_contents( 'php://input' );
		if ( ! $raw_body ) {
			wp_send_json_error( [ 'message' => 'Empty request body.' ], 400 );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			wp_send_json_error( [ 'message' => 'Invalid JSON payload.' ], 400 );
		}

		$element_changes = $this->sanitize_element_changes( $payload['element_presets'] ?? [] );
		$group_changes   = $this->sanitize_group_changes( $payload['group_presets'] ?? [] );

		if ( empty( $element_changes ) && empty( $group_changes ) ) {
			wp_send_json_error( [ 'message' => 'No changes to save.' ], 400 );
		}

		$repo = new PresetsRepository();
		$raw  = $repo->get_raw();

		// Snapshot before writing.
		SnapshotManager::push( 'presets', $raw, 'manage', 'Before preset name edit' );

		// Apply name changes.
		if ( ! empty( $element_changes ) ) {
			$raw = $this->apply_element_changes( $raw, $element_changes );
			// To also apply Is Default changes for element presets, uncomment:
			// $raw = $this->apply_element_default_changes( $raw, $element_changes );
		}

		if ( ! empty( $group_changes ) ) {
			$raw = $this->apply_group_changes( $raw, $group_changes );
			// To also apply Is Default changes for group presets, uncomment:
			// $raw = $this->apply_group_default_changes( $raw, $group_changes );
		}

		$saved = $repo->save_raw( $raw );
		$fresh = $repo->get_raw();

		wp_send_json_success( [
			'element_presets' => $this->flatten_element_presets( $fresh ),
			'group_presets'   => $this->flatten_group_presets( $fresh ),
			'saved'           => $saved,
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: Excel download
	// -------------------------------------------------------------------------

	/**
	 * Stream the presets spreadsheet as an XLSX download.
	 *
	 * @return never
	 */
	public function ajax_xlsx(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		( new PresetsExporter() )->stream_download();
	}

	// -------------------------------------------------------------------------
	// Flatten helpers
	// -------------------------------------------------------------------------

	/**
	 * Flatten the nested module->items structure into a flat list.
	 *
	 * @param  array $raw Full presets raw array.
	 * @return array<int, array<string, mixed>>
	 */
	public function flatten_element_presets( array $raw ): array {
		$modules = $raw['module'] ?? [];
		$list    = [];
		$order   = 1;

		foreach ( $modules as $module_name => $module_data ) {
			if ( ! is_array( $module_data ) ) {
				continue;
			}
			$default_id = (string) ( $module_data['default'] ?? '' );
			$items      = $module_data['items'] ?? [];

			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $preset_id => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$list[] = [
					'preset_id'   => (string) $preset_id,
					'module_name' => (string) $module_name,
					'name'        => (string) ( $item['name']    ?? '' ),
					'version'     => (string) ( $item['version'] ?? '' ),
					'is_default'  => ( (string) $preset_id === $default_id ),
					'order'       => $order++,
				];
			}
		}

		return $list;
	}

	/**
	 * Flatten the nested group->items structure into a flat list.
	 *
	 * @param  array $raw Full presets raw array.
	 * @return array<int, array<string, mixed>>
	 */
	public function flatten_group_presets( array $raw ): array {
		$groups = $raw['group'] ?? [];
		$list   = [];
		$order  = 1;

		foreach ( $groups as $group_id => $group_data ) {
			if ( ! is_array( $group_data ) ) {
				continue;
			}
			// group_name may be stored in a 'name' key; fall back to the group ID.
			$group_name = (string) ( $group_data['name'] ?? $group_id );
			$default_id = (string) ( $group_data['default'] ?? '' );
			$items      = $group_data['items'] ?? [];

			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $preset_id => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$list[] = [
					'preset_id'   => (string) $preset_id,
					'group_id'    => (string) $group_id,
					'group_name'  => $group_name,
					'name'        => (string) ( $item['name']       ?? '' ),
					'version'     => (string) ( $item['version']    ?? '' ),
					'module_name' => (string) ( $item['moduleName'] ?? '' ),
					'is_default'  => ( (string) $preset_id === $default_id ),
					'order'       => $order++,
				];
			}
		}

		return $list;
	}

	// -------------------------------------------------------------------------
	// Apply name changes
	// -------------------------------------------------------------------------

	/**
	 * Write element preset name changes back into the raw structure.
	 *
	 * @param array $raw     Full presets raw array.
	 * @param array $changes Sanitized list: [ { preset_id, module_name, name }, ... ]
	 * @return array
	 */
	protected function apply_element_changes( array $raw, array $changes ): array {
		foreach ( $changes as $c ) {
			$mod = $c['module_name'] ?? '';
			$pid = $c['preset_id']   ?? '';
			if ( $mod && $pid && isset( $raw['module'][ $mod ]['items'][ $pid ] ) ) {
				$raw['module'][ $mod ]['items'][ $pid ]['name'] = $c['name'] ?? '';
			}
		}
		return $raw;
	}

	/**
	 * Write group preset name changes back into the raw structure.
	 *
	 * @param array $raw     Full presets raw array.
	 * @param array $changes Sanitized list: [ { preset_id, group_id, name }, ... ]
	 * @return array
	 */
	protected function apply_group_changes( array $raw, array $changes ): array {
		foreach ( $changes as $c ) {
			$gid = $c['group_id']  ?? '';
			$pid = $c['preset_id'] ?? '';
			if ( $gid && $pid && isset( $raw['group'][ $gid ]['items'][ $pid ] ) ) {
				$raw['group'][ $gid ]['items'][ $pid ]['name'] = $c['name'] ?? '';
			}
		}
		return $raw;
	}

	// -------------------------------------------------------------------------
	// Apply Is Default changes (currently unused — easy to enable)
	// -------------------------------------------------------------------------

	/**
	 * Set the default preset for each module where is_default changed to true.
	 *
	 * To enable: add `is_default` to sanitize_element_changes() and
	 * uncomment the call in ajax_save().
	 *
	 * @param array $raw     Full presets raw array.
	 * @param array $changes Sanitized list: [ { preset_id, module_name, is_default }, ... ]
	 * @return array
	 */
	protected function apply_element_default_changes( array $raw, array $changes ): array {
		foreach ( $changes as $c ) {
			$mod = $c['module_name'] ?? '';
			$pid = $c['preset_id']   ?? '';
			if ( $mod && $pid && ! empty( $c['is_default'] ) && isset( $raw['module'][ $mod ] ) ) {
				$raw['module'][ $mod ]['default'] = $pid;
			}
		}
		return $raw;
	}

	/**
	 * Set the default preset for each group where is_default changed to true.
	 *
	 * To enable: add `is_default` to sanitize_group_changes() and
	 * uncomment the call in ajax_save().
	 *
	 * @param array $raw     Full presets raw array.
	 * @param array $changes Sanitized list: [ { preset_id, group_id, is_default }, ... ]
	 * @return array
	 */
	protected function apply_group_default_changes( array $raw, array $changes ): array {
		foreach ( $changes as $c ) {
			$gid = $c['group_id']  ?? '';
			$pid = $c['preset_id'] ?? '';
			if ( $gid && $pid && ! empty( $c['is_default'] ) && isset( $raw['group'][ $gid ] ) ) {
				$raw['group'][ $gid ]['default'] = $pid;
			}
		}
		return $raw;
	}

	// -------------------------------------------------------------------------
	// Sanitization
	// -------------------------------------------------------------------------

	/**
	 * Sanitize incoming element preset change rows.
	 *
	 * @param  mixed $input
	 * @return array<int, array<string, mixed>>
	 */
	protected function sanitize_element_changes( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}
		$out = [];
		foreach ( $input as $item ) {
			if ( ! is_array( $item ) || empty( $item['preset_id'] ) || empty( $item['module_name'] ) ) {
				continue;
			}
			$out[] = [
				'preset_id'   => sanitize_text_field( $item['preset_id'] ),
				'module_name' => sanitize_text_field( $item['module_name'] ),
				'name'        => sanitize_text_field( $item['name'] ?? '' ),
				// To enable Is Default editing, add:
				// 'is_default' => ! empty( $item['is_default'] ),
			];
		}
		return $out;
	}

	/**
	 * Sanitize incoming group preset change rows.
	 *
	 * @param  mixed $input
	 * @return array<int, array<string, mixed>>
	 */
	protected function sanitize_group_changes( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}
		$out = [];
		foreach ( $input as $item ) {
			if ( ! is_array( $item ) || empty( $item['preset_id'] ) || empty( $item['group_id'] ) ) {
				continue;
			}
			$out[] = [
				'preset_id' => sanitize_text_field( $item['preset_id'] ),
				'group_id'  => sanitize_text_field( $item['group_id'] ),
				'name'      => sanitize_text_field( $item['name'] ?? '' ),
				// To enable Is Default editing, add:
				// 'is_default' => ! empty( $item['is_default'] ),
			];
		}
		return $out;
	}
}
