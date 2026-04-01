<?php
/**
 * NotesManager — persistent notes, tags, and audit suppression for DSOs and content.
 *
 * Stores a single WordPress option (`d5dsh_notes`) containing a JSON-encoded
 * associative array keyed by entity identifier:
 *
 *   "var:{id}"     — Global variable or color (gcid-*, gvid-*)
 *   "preset:{id}"  — Element or Group Preset
 *   "post:{id}"    — WordPress post / page / layout (by post_id)
 *   "check:{name}" — Audit check name (e.g. "broken_variable_refs")
 *
 * Each value is an array with three keys:
 *   "note"     string   Free-text comment
 *   "tags"     string[] Arbitrary tag strings
 *   "suppress" string[] Audit check names suppressed for this entity
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

use D5DesignSystemHelper\Util\DebugLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NotesManager {

	const OPTION_KEY   = 'd5dsh_notes';
	const NONCE        = 'd5dsh_notes_nonce';
	const AJAX_SAVE    = 'd5dsh_notes_save';
	const AJAX_DELETE  = 'd5dsh_notes_delete';
	const AJAX_GET_ALL = 'd5dsh_notes_get_all';

	// ── Registration ─────────────────────────────────────────────────────────

	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_SAVE,    [ $this, 'ajax_save'    ] );
		add_action( 'wp_ajax_' . self::AJAX_DELETE,  [ $this, 'ajax_delete'  ] );
		add_action( 'wp_ajax_' . self::AJAX_GET_ALL, [ $this, 'ajax_get_all' ] );
	}

	// ── AJAX handlers ────────────────────────────────────────────────────────

	public function ajax_save(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$key  = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
		if ( $key === '' || ! self::valid_key( $key ) ) {
			wp_send_json_error( [ 'message' => 'Invalid key.' ], 400 );
		}

		$note     = sanitize_textarea_field( wp_unslash( $_POST['note']     ?? '' ) );
		$tags_raw = sanitize_text_field(     wp_unslash( $_POST['tags']     ?? '' ) );
		$suppress = array_map( 'sanitize_key', (array) ( $_POST['suppress'] ?? [] ) );

		// Parse comma-separated tags string into array.
		$tags = array_values( array_filter(
			array_map( 'trim', explode( ',', $tags_raw ) )
		) );

		$data = [
			'note'     => $note,
			'tags'     => $tags,
			'suppress' => $suppress,
		];

		try {
			self::save( $key, $data );
		} catch ( \Throwable $e ) {
			DebugLogger::send_error( $e, __METHOD__, 'Failed to save note.' );
		}
		wp_send_json_success( [ 'key' => $key, 'data' => $data ] );
	}

	public function ajax_delete(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$key = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
		if ( $key === '' ) {
			wp_send_json_error( [ 'message' => 'Invalid key.' ], 400 );
		}

		try {
			self::delete( $key );
		} catch ( \Throwable $e ) {
			DebugLogger::send_error( $e, __METHOD__, 'Failed to delete note.' );
		}
		wp_send_json_success( [ 'key' => $key ] );
	}

	public function ajax_get_all(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		try {
			$all = self::get_all();
		} catch ( \Throwable $e ) {
			DebugLogger::send_error( $e, __METHOD__, 'Failed to load notes.' );
		}
		wp_send_json_success( $all );
	}

	// ── Static data API ───────────────────────────────────────────────────────

	/**
	 * Return all notes as an associative array keyed by entity key.
	 *
	 * @return array<string, array{note: string, tags: string[], suppress: string[]}>
	 */
	public static function get_all(): array {
		$raw = get_option( self::OPTION_KEY, [] );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Return the note data for a single entity key, or a default empty structure.
	 *
	 * @param string $key  e.g. "var:gcid-abc123"
	 * @return array{note: string, tags: string[], suppress: string[]}
	 */
	public static function get( string $key ): array {
		$all = self::get_all();
		return $all[ $key ] ?? [ 'note' => '', 'tags' => [], 'suppress' => [] ];
	}

	/**
	 * Persist note data for a single entity key.
	 *
	 * @param string $key   e.g. "var:gcid-abc123"
	 * @param array  $data  {note, tags, suppress}
	 */
	public static function save( string $key, array $data ): void {
		$all         = self::get_all();
		$all[ $key ] = [
			'note'     => (string) ( $data['note']     ?? '' ),
			'tags'     => array_values( array_filter( (array) ( $data['tags']     ?? [] ), 'is_string' ) ),
			'suppress' => array_values( array_filter( (array) ( $data['suppress'] ?? [] ), 'is_string' ) ),
		];

		// Remove the key entirely if all fields are empty.
		if ( $all[ $key ]['note'] === '' && empty( $all[ $key ]['tags'] ) && empty( $all[ $key ]['suppress'] ) ) {
			unset( $all[ $key ] );
		}

		update_option( self::OPTION_KEY, $all, false /* not autoloaded */ );
	}

	/**
	 * Remove all note data for a single entity key.
	 *
	 * @param string $key
	 */
	public static function delete( string $key ): void {
		$all = self::get_all();
		unset( $all[ $key ] );
		update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * Return true if a given audit check should be suppressed for an entity.
	 *
	 * Suppression is active if:
	 *   (a) the entity's own note entry lists $check_name in its suppress array, OR
	 *   (b) the check-level entry ("check:{check_name}") has a suppress entry
	 *       that includes $check_name (i.e. "suppress everything for this check").
	 *
	 * @param string $entity_key  e.g. "var:gcid-abc123" or "post:42"
	 * @param string $check_name  e.g. "broken_variable_refs"
	 */
	public static function is_suppressed( string $entity_key, string $check_name ): bool {
		$all = self::get_all();

		// Per-item suppression.
		$entity_suppress = $all[ $entity_key ]['suppress'] ?? [];
		if ( in_array( $check_name, $entity_suppress, true ) ) {
			return true;
		}

		// Per-check suppression (suppress this check for all items).
		$check_suppress = $all[ 'check:' . $check_name ]['suppress'] ?? [];
		if ( in_array( $check_name, $check_suppress, true ) ) {
			return true;
		}

		return false;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Validate that a key has a known prefix.
	 */
	private static function valid_key( string $key ): bool {
		foreach ( [ 'var:', 'preset:', 'post:', 'check:' ] as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
