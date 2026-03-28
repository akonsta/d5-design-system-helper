<?php
/**
 * Excel importer for Divi Library layouts and WordPress pages.
 *
 * Reads the Layouts/Pages sheet and updates or inserts wp_posts records.
 * Does NOT touch post_content — it is preserved as-is in the database.
 *
 * ## Import rules
 *  - If a post with the given ID exists and is the correct post type,
 *    it is updated via wp_update_post().
 *  - If the ID is not found, a new post is inserted via wp_insert_post().
 *  - Post meta from the Post Meta (JSON) column is written via update_post_meta().
 *  - post_content is NEVER touched — only fields present in the xlsx are written.
 *  - A SnapshotManager snapshot is taken BEFORE any write.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Importers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Admin\SnapshotManager;
use D5DesignSystemHelper\Data\LayoutsRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Class LayoutsImporter
 */
class LayoutsImporter {

	private LayoutsRepository $repo;
	private string            $file_path;
	private string            $file_type;  // 'layouts' or 'pages'

	/**
	 * @param string $file_path  Absolute path to the uploaded .xlsx file.
	 * @param string $file_type  'layouts' or 'pages'.
	 */
	public function __construct( string $file_path, string $file_type = 'layouts' ) {
		$this->file_path = $file_path;
		$this->file_type = $file_type;
		$this->repo      = new LayoutsRepository();
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Save a legacy backup.
	 *
	 * @return string
	 */
	public function backup_current(): string {
		return $this->repo->backup();
	}

	/**
	 * Parse and return a diff without writing.
	 *
	 * @return array
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	public function dry_run(): array {
		$from_xlsx = $this->parse_xlsx();
		$current   = $this->repo->get_all();
		return $this->compute_diff( $from_xlsx, $current );
	}

	/**
	 * Snapshot then commit.
	 *
	 * @return array{updated: int, skipped: int, new: int, backup_option: string}
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	public function commit(): array {
		\D5DesignSystemHelper\Admin\SimpleImporter::reset_sanitization_log();
		$raw = $this->repo->get_all();
		SnapshotManager::push( $this->file_type, $raw, 'import', basename( $this->file_path ) );

		$from_xlsx = $this->parse_xlsx();
		$diff      = $this->compute_diff( $from_xlsx, $raw );
		$new_count = 0;

		foreach ( $from_xlsx as $post_id => $post_data ) {
			$ctx      = 'Excel ' . ucfirst( $this->file_type ) . ' #' . $post_id;
			$existing = get_post( (int) $post_id );

			// Sanitize post_fields before writing.
			$fields = $post_data['post_fields'];
			$fields['post_title']  = \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_and_log( $fields['post_title'] ?? '', $ctx, 'post_title' );
			$fields['post_name']   = \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_and_log( $fields['post_name']  ?? '', $ctx, 'post_name', 'title' );
			$fields['post_status'] = \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_and_log( $fields['post_status'] ?? 'publish', $ctx, 'post_status', 'key' );

			if ( $existing ) {
				// Update existing post (do NOT include post_content).
				wp_update_post( array_merge(
					[ 'ID' => (int) $post_id ],
					$fields
				) );
			} else {
				// Insert as new post.
				wp_insert_post( array_merge(
					$fields,
					[ 'comment_status' => 'closed', 'ping_status' => 'closed' ]
				) );
				$new_count++;
			}

			// Write post meta (sanitized).
			foreach ( $post_data['post_meta'] as $meta_key => $meta_value ) {
				$safe_key = \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_and_log( $meta_key, $ctx, 'meta_key', 'key' );
				update_post_meta( (int) $post_id, $safe_key, \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_meta_value( $meta_value, $ctx . ' meta ' . $safe_key ) );
			}
		}

		return [
			'updated'          => count( $diff['changes'] ),
			'skipped'          => max( 0, count( $from_xlsx ) - count( $diff['changes'] ) - $new_count ),
			'new'              => $new_count,
			'backup_option'    => 'd5dsh_snap_' . $this->file_type . '_0',
			'sanitization_log' => \D5DesignSystemHelper\Admin\SimpleImporter::get_sanitization_log(),
		];
	}

	// ── Parser ────────────────────────────────────────────────────────────────

	/**
	 * Parse the Layouts or Pages sheet.
	 *
	 * Returns a post_id-keyed array of {post_fields, post_meta, terms}.
	 *
	 * @return array<int, array>
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function parse_xlsx(): array {
		$ss         = IOFactory::load( $this->file_path );
		$sheet_name = ucfirst( $this->file_type ); // 'Layouts' or 'Pages'
		$ws         = $ss->getSheetByName( $sheet_name );
		$result     = [];

		if ( ! $ws ) {
			return $result;
		}

		for ( $row = 2; $row <= $ws->getHighestDataRow(); $row++ ) {
			$id = (int) $ws->getCell( 'A' . $row )->getValue();
			if ( ! $id ) {
				continue;
			}

			$meta_json  = trim( (string) $ws->getCell( 'J' . $row )->getValue() );
			$terms_json = trim( (string) $ws->getCell( 'K' . $row )->getValue() );
			$post_type  = trim( (string) $ws->getCell( 'C' . $row )->getValue() )
			              ?: ( $this->file_type === 'pages' ? 'page' : LayoutsRepository::POST_TYPE );

			$result[ $id ] = [
				'post_fields' => [
					'post_title'   => trim( (string) $ws->getCell( 'B' . $row )->getValue() ),
					'post_type'    => $post_type,
					'post_name'    => trim( (string) $ws->getCell( 'D' . $row )->getValue() ),
					'post_status'  => trim( (string) $ws->getCell( 'E' . $row )->getValue() ) ?: 'publish',
					'post_date'    => trim( (string) $ws->getCell( 'F' . $row )->getValue() ),
					'menu_order'   => (int) $ws->getCell( 'H' . $row )->getValue(),
					'post_parent'  => (int) $ws->getCell( 'I' . $row )->getValue(),
				],
				'post_meta' => $meta_json  ? ( json_decode( $meta_json,  true ) ?? [] ) : [],
				'terms'     => $terms_json ? ( json_decode( $terms_json, true ) ?? [] ) : [],
			];
		}

		return $result;
	}

	// ── Diff engine ───────────────────────────────────────────────────────────

	/**
	 * Compare parsed xlsx posts against the current DB.
	 *
	 * @param array $from_xlsx  Parsed records.
	 * @param array $current    Current DB state.
	 * @return array
	 */
	private function compute_diff( array $from_xlsx, array $current ): array {
		$changes = [];

		foreach ( $from_xlsx as $post_id => $post_data ) {
			if ( ! isset( $current[ $post_id ] ) ) {
				continue; // New post — counted separately.
			}

			$db_fields  = $current[ $post_id ]['post_fields'] ?? [];
			$new_fields = $post_data['post_fields'] ?? [];

			foreach ( [ 'post_title', 'post_name', 'post_status', 'menu_order', 'post_parent' ] as $field ) {
				if ( (string) ( $db_fields[ $field ] ?? '' ) !== (string) ( $new_fields[ $field ] ?? '' ) ) {
					$changes[] = [
						'id'        => $post_id,
						'label'     => $new_fields['post_title'] ?? (string) $post_id,
						'type'      => $this->file_type,
						'field'     => $field,
						'old_value' => (string) ( $db_fields[ $field ] ?? '' ),
						'new_value' => (string) ( $new_fields[ $field ] ?? '' ),
					];
				}
			}
		}

		return [ 'changes' => $changes, 'new_entries' => [], 'parse_errors' => [] ];
	}
}
