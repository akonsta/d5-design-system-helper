<?php
/**
 * Data access for Divi Library layouts and Pages.
 *
 * Reads from wp_posts where post_type is 'et_pb_layout' (layouts)
 * or 'page' (pages), depending on the $post_type constructor parameter.
 *
 * Also includes post meta and taxonomy terms for each post.
 *
 * Snapshot restore uses wp_update_post / wp_insert_post to write back.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LayoutsRepository
 */
class LayoutsRepository {

	/** Post type for Divi Library layouts. */
	const POST_TYPE = 'et_pb_layout';

	/** Prefix for legacy backup keys. */
	const BACKUP_KEY_PREFIX = 'd5dsh_backup_layouts_';

	// ── Fetching ──────────────────────────────────────────────────────────────

	/**
	 * Return all layouts (or pages) as a structured array.
	 *
	 * Each entry:
	 *   post_id => [
	 *     'post_fields' => [...WP post fields...],
	 *     'post_meta'   => [...key => value...],
	 *     'terms'       => [...taxonomy => [slug, ...]...],
	 *   ]
	 *
	 * @param string $post_type  'et_pb_layout' or 'page'.
	 * @param string $status     'any', 'publish', 'draft', 'private', etc.
	 * @return array<int, array>
	 */
	public function get_all( string $post_type = self::POST_TYPE, string $status = 'any' ): array {
		$posts = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => $status,
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );

		$result = [];
		foreach ( $posts as $post ) {
			$result[ $post->ID ] = [
				'post_fields' => [
					'post_title'   => $post->post_title,
					'post_type'    => $post->post_type,
					'post_name'    => $post->post_name,
					'post_status'  => $post->post_status,
					'post_date'    => $post->post_date,
					'post_modified'=> $post->post_modified,
					'menu_order'   => $post->menu_order,
					'post_parent'  => $post->post_parent,
				],
				'post_meta' => $this->get_exportable_meta( $post->ID ),
				'terms'     => $this->get_terms( $post->ID ),
			];
		}

		return $result;
	}

	/**
	 * Return the structured layouts array for snapshot/restore.
	 *
	 * @return array
	 */
	public function get_raw(): array {
		return $this->get_all();
	}

	// ── Writing ───────────────────────────────────────────────────────────────

	/**
	 * Restore posts from a snapshot data array.
	 *
	 * Updates existing posts by ID; skips IDs not found.
	 *
	 * @param array $snapshot  get_raw() output.
	 * @return bool
	 */
	public function restore_posts( array $snapshot ): bool {
		foreach ( $snapshot as $post_id => $data ) {
			$existing = get_post( (int) $post_id );
			if ( ! $existing ) {
				continue;
			}
			wp_update_post( array_merge( [ 'ID' => (int) $post_id ], $data['post_fields'] ?? [] ) );
			foreach ( $data['post_meta'] ?? [] as $meta_key => $meta_value ) {
				update_post_meta( (int) $post_id, $meta_key, $meta_value );
			}
		}
		return true;
	}

	/**
	 * Save a timestamped backup to wp_options.
	 *
	 * @return string Backup option key.
	 */
	public function backup(): string {
		$key = self::BACKUP_KEY_PREFIX . gmdate( 'Ymd_His' );
		// Layouts are wp_posts — store a serialized snapshot of all layout IDs + meta.
		add_option( $key, $this->get_raw(), '', 'no' );
		return $key;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Return exportable post meta (excludes internal WP keys with no export value).
	 *
	 * @param int $post_id
	 * @return array<string, mixed>
	 */
	private function get_exportable_meta( int $post_id ): array {
		$meta = get_post_meta( $post_id );
		$out  = [];

		// Include Divi builder meta and any non-internal keys.
		$include_prefixes = [ '_et_', '_thumbnail_id', '_wp_page_template' ];

		foreach ( $meta as $key => $values ) {
			$include = false;
			foreach ( $include_prefixes as $prefix ) {
				if ( str_starts_with( $key, $prefix ) ) {
					$include = true;
					break;
				}
			}
			// Also include non-prefixed-underscore custom fields.
			if ( ! str_starts_with( $key, '_' ) ) {
				$include = true;
			}

			if ( $include ) {
				$out[ $key ] = maybe_unserialize( $values[0] ?? '' );
			}
		}

		return $out;
	}

	/**
	 * Return taxonomy term slugs for a post, keyed by taxonomy name.
	 *
	 * @param int $post_id
	 * @return array<string, string[]>
	 */
	private function get_terms( int $post_id ): array {
		$taxonomies = get_post_taxonomies( $post_id );
		$out        = [];

		foreach ( $taxonomies as $tax ) {
			$terms = get_the_terms( $post_id, $tax );
			if ( ! is_array( $terms ) || empty( $terms ) ) {
				continue;
			}
			$out[ $tax ] = array_map( static fn( $t ) => $t->slug, $terms );
		}

		return $out;
	}
}
