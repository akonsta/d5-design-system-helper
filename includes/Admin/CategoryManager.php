<?php
/**
 * CategoryManager — user-defined DSO categories (variables and presets).
 *
 * Stores two option keys:
 *   d5dsh_var_categories   → [ { id, label, color }, ... ]
 *   d5dsh_var_category_map → { "var:gcid-xxx": ["cat-id-1", ...],
 *                              "gp:preset-id":  ["cat-id-1", "cat-id-2"],
 *                              "ep:preset-id":  ["cat-id-1"], ... }
 *
 * Legacy single-value maps ({ var_id: cat_id }) are migrated transparently on load.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CategoryManager
 */
class CategoryManager {

	const OPTION_CATEGORIES = 'd5dsh_var_categories';
	const OPTION_MAP        = 'd5dsh_var_category_map';
	const NONCE_ACTION      = 'd5dsh_manage';
	const CAPABILITY        = 'manage_options';

	// ── Registration ──────────────────────────────────────────────────────────

	public function register(): void {
		add_action( 'wp_ajax_d5dsh_categories_load',   [ $this, 'ajax_load'   ] );
		add_action( 'wp_ajax_d5dsh_categories_save',   [ $this, 'ajax_save'   ] );
		add_action( 'wp_ajax_d5dsh_categories_assign', [ $this, 'ajax_assign' ] );
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/**
	 * Return all categories and the assignment map.
	 */
	public function ajax_load(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}
		wp_send_json_success( [
			'categories'   => $this->get_categories(),
			'category_map' => $this->get_map(),
		] );
	}

	/**
	 * Save the full categories list (add/rename/recolor/delete via replace).
	 * Expects JSON body: { categories: [ { id?, label, color }, ... ] }
	 */
	public function ajax_save(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$body = json_decode( file_get_contents( 'php://input' ), true );
		if ( ! is_array( $body ) || ! isset( $body['categories'] ) ) {
			wp_send_json_error( [ 'message' => 'Invalid payload.' ], 400 );
		}

		$cats = [];
		foreach ( (array) $body['categories'] as $cat ) {
			if ( ! is_array( $cat ) ) { continue; }
			$id      = sanitize_text_field( $cat['id']      ?? '' );
			$label   = sanitize_text_field( $cat['label']   ?? '' );
			$color   = sanitize_hex_color( $cat['color']    ?? '' ) ?: '#6b7280';
			$comment = sanitize_textarea_field( $cat['comment'] ?? '' );
			if ( $label === '' ) { continue; }
			if ( $id === '' ) { $id = 'cat-' . wp_generate_uuid4(); }
			$cats[] = compact( 'id', 'label', 'color', 'comment' );
		}

		// Remove assignments for deleted categories.
		$valid_ids = array_column( $cats, 'id' );
		$map       = $this->get_map();
		foreach ( $map as $dso_key => $cat_ids ) {
			$filtered = array_values( array_filter( (array) $cat_ids, fn( $cid ) => in_array( $cid, $valid_ids, true ) ) );
			if ( empty( $filtered ) ) {
				unset( $map[ $dso_key ] );
			} else {
				$map[ $dso_key ] = $filtered;
			}
		}
		update_option( self::OPTION_MAP, $map, false );
		update_option( self::OPTION_CATEGORIES, $cats, false );

		wp_send_json_success( [
			'categories'   => $cats,
			'category_map' => $map,
		] );
	}

	/**
	 * Batch-assign DSOs to categories.
	 * Expects JSON body: { assignments: { "var:id": ["cat-id", ...], "gp:id": [...], ... } }
	 * An empty array or null value removes the DSO from all categories.
	 */
	public function ajax_assign(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$body = json_decode( file_get_contents( 'php://input' ), true );
		if ( ! is_array( $body ) || ! isset( $body['assignments'] ) ) {
			wp_send_json_error( [ 'message' => 'Invalid payload.' ], 400 );
		}

		$map = $this->get_map();
		foreach ( (array) $body['assignments'] as $dso_key => $cat_ids ) {
			$dso_key = sanitize_text_field( $dso_key );
			if ( $dso_key === '' ) { continue; }
			if ( $cat_ids === null || $cat_ids === '' || ( is_array( $cat_ids ) && empty( $cat_ids ) ) ) {
				unset( $map[ $dso_key ] );
			} else {
				$cleaned = array_values( array_filter( array_map( 'sanitize_text_field', (array) $cat_ids ) ) );
				if ( empty( $cleaned ) ) {
					unset( $map[ $dso_key ] );
				} else {
					$map[ $dso_key ] = $cleaned;
				}
			}
		}
		update_option( self::OPTION_MAP, $map, false );

		wp_send_json_success( [ 'category_map' => $map ] );
	}

	// ── Public data accessors ─────────────────────────────────────────────────

	public function get_categories(): array {
		$cats = get_option( self::OPTION_CATEGORIES, [] );
		return is_array( $cats ) ? $cats : [];
	}

	/**
	 * Returns the map, migrating legacy single-value format to arrays transparently.
	 * Legacy: { "gcid-xxx": "cat-id" }
	 * New:    { "var:gcid-xxx": ["cat-id"] }
	 */
	public function get_map(): array {
		$raw = get_option( self::OPTION_MAP, [] );
		if ( ! is_array( $raw ) ) { return []; }

		$migrated = false;
		$map      = [];
		foreach ( $raw as $k => $v ) {
			// Already new-format key (has a colon prefix).
			if ( str_contains( $k, ':' ) ) {
				$map[ $k ] = is_array( $v ) ? $v : [ $v ];
				continue;
			}
			// Legacy format: bare var ID → single cat ID string.
			$map[ 'var:' . $k ] = [ $v ];
			$migrated = true;
		}
		if ( $migrated ) {
			update_option( self::OPTION_MAP, $map, false );
		}
		return $map;
	}
}
