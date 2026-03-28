<?php
/**
 * Data access for Divi Theme Builder templates.
 *
 * Theme Builder templates are stored across several custom post types in wp_posts:
 *   et_template      — the template record (header/body/footer layout IDs)
 *   et_header_layout — header layout post
 *   et_body_layout   — body layout post
 *   et_footer_layout — footer layout post
 *
 * ## Return format (templates)
 *   [
 *     [
 *       'title'        => string,
 *       'default'      => bool,
 *       'enabled'      => bool,
 *       'use_on'       => array,
 *       'exclude_from' => array,
 *       'layouts'      => ['header' => int, 'body' => int, 'footer' => int],
 *       'description'  => string,
 *     ],
 *     ...
 *   ]
 *
 * ## Return format (layouts)
 *   [
 *     post_id => [
 *       'post_title' => string,
 *       'post_type'  => string,
 *       'is_global'  => bool,
 *       'post_meta'  => array,
 *       'images'     => array,
 *     ],
 *     ...
 *   ]
 *
 * post_content is intentionally omitted from get_all() / exports.
 * Use get_layout_content() to retrieve it on import.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BuilderTemplatesRepository
 */
class BuilderTemplatesRepository {

	/** et_template post type. */
	const TEMPLATE_POST_TYPE = 'et_template';

	/** Layout post types associated with templates. */
	const LAYOUT_POST_TYPES = [ 'et_header_layout', 'et_body_layout', 'et_footer_layout' ];

	/** Prefix for legacy backup keys. */
	const BACKUP_KEY_PREFIX = 'd5dsh_backup_builder_';

	// ── Fetching ──────────────────────────────────────────────────────────────

	/**
	 * Return a structured snapshot of all templates and their layouts.
	 *
	 * @return array{templates: array, layouts: array}
	 */
	public function get_all(): array {
		$template_posts = get_posts( [
			'post_type'      => self::TEMPLATE_POST_TYPE,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		] );

		$templates  = [];
		$layout_ids = [];

		foreach ( $template_posts as $tpl ) {
			$meta = get_post_meta( $tpl->ID );

			$header_id = (int) ( $meta['_et_header_layout_id'][0] ?? 0 );
			$body_id   = (int) ( $meta['_et_body_layout_id'][0]   ?? 0 );
			$footer_id = (int) ( $meta['_et_footer_layout_id'][0] ?? 0 );

			$use_on       = maybe_unserialize( $meta['_et_use_on'][0]       ?? '' );
			$exclude_from = maybe_unserialize( $meta['_et_exclude_from'][0] ?? '' );

			$templates[] = [
				'title'        => $tpl->post_title,
				'default'      => (bool) ( $meta['_et_default'][0]  ?? false ),
				'enabled'      => (bool) ( $meta['_et_enabled'][0]  ?? true ),
				'use_on'       => is_array( $use_on )       ? $use_on       : [],
				'exclude_from' => is_array( $exclude_from ) ? $exclude_from : [],
				'layouts'      => [
					'header' => $header_id,
					'body'   => $body_id,
					'footer' => $footer_id,
				],
				'description'  => $meta['_et_description'][0] ?? '',
			];

			foreach ( [ $header_id, $body_id, $footer_id ] as $lid ) {
				if ( $lid ) {
					$layout_ids[] = $lid;
				}
			}
		}

		// Build the layouts dictionary (keyed by post_id, no post_content).
		$layouts = [];
		foreach ( array_unique( $layout_ids ) as $lid ) {
			$post = get_post( $lid );
			if ( ! $post ) {
				continue;
			}

			$meta      = get_post_meta( $lid );
			$is_global = (bool) ( $meta['_et_pb_is_global'][0] ?? false );
			$images    = maybe_unserialize( $meta['_et_pb_images'][0] ?? '' );

			// Export a curated subset of post meta (Divi builder keys + ET keys).
			$export_meta = [];
			foreach ( $meta as $k => $v ) {
				if ( str_starts_with( $k, '_et_' ) || in_array( $k, [ '_wp_page_template', '_thumbnail_id' ], true ) ) {
					$export_meta[ $k ] = maybe_unserialize( $v[0] ?? '' );
				}
			}

			$layouts[ $lid ] = [
				'post_title' => $post->post_title,
				'post_type'  => $post->post_type,
				'is_global'  => $is_global,
				'post_meta'  => $export_meta,
				'images'     => is_array( $images ) ? $images : [],
			];
		}

		return [
			'templates' => $templates,
			'layouts'   => $layouts,
		];
	}

	/**
	 * Alias for compatibility with SnapshotManager.
	 *
	 * @return array
	 */
	public function get_raw(): array {
		return $this->get_all();
	}

	/**
	 * Return the post_content for a layout post.
	 * Used by the importer to preserve content (not stored in xlsx).
	 *
	 * @param int $post_id
	 * @return string
	 */
	public function get_layout_content( int $post_id ): string {
		$post = get_post( $post_id );
		return $post ? $post->post_content : '';
	}

	// ── Writing ───────────────────────────────────────────────────────────────

	/**
	 * Restore templates from a snapshot.
	 *
	 * Locates each et_template post by title and updates its meta.
	 * Layout post meta is also restored.
	 *
	 * @param array $snapshot  Output of get_all().
	 * @return bool
	 */
	public function restore_templates( array $snapshot ): bool {
		foreach ( $snapshot['templates'] ?? [] as $tpl_data ) {
			$existing = get_posts( [
				'post_type'   => self::TEMPLATE_POST_TYPE,
				'title'       => $tpl_data['title'],
				'post_status' => 'any',
				'numberposts' => 1,
			] );
			if ( empty( $existing ) ) {
				continue;
			}
			$tpl_id = $existing[0]->ID;
			update_post_meta( $tpl_id, '_et_default',      $tpl_data['default']  ? '1' : '0' );
			update_post_meta( $tpl_id, '_et_enabled',      $tpl_data['enabled']  ? '1' : '0' );
			update_post_meta( $tpl_id, '_et_use_on',       maybe_serialize( $tpl_data['use_on']       ?? [] ) );
			update_post_meta( $tpl_id, '_et_exclude_from', maybe_serialize( $tpl_data['exclude_from'] ?? [] ) );
			update_post_meta( $tpl_id, '_et_description',  $tpl_data['description'] ?? '' );
		}

		foreach ( $snapshot['layouts'] ?? [] as $lid => $layout_data ) {
			$post = get_post( (int) $lid );
			if ( ! $post ) {
				continue;
			}
			wp_update_post( [ 'ID' => (int) $lid, 'post_title' => $layout_data['post_title'] ?? $post->post_title ] );
			foreach ( $layout_data['post_meta'] ?? [] as $meta_key => $meta_value ) {
				update_post_meta( (int) $lid, $meta_key, $meta_value );
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
		add_option( $key, $this->get_all(), '', 'no' );
		return $key;
	}
}
