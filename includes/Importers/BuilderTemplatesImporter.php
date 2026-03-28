<?php
/**
 * Excel importer for Divi Theme Builder templates.
 *
 * Reads the Templates and Layouts sheets and updates wp_posts records.
 *
 * ## Key advantage over the Python tool
 * post_content is NOT stored in the xlsx. On import, each layout post's
 * post_content is read directly from the database, so the full shortcode
 * content is always preserved — no need to keep the original JSON file.
 *
 * ## Import rules
 *  - Templates are matched by post_title (not ID).
 *  - Template meta (_et_default, _et_enabled, _et_use_on, etc.) is updated.
 *  - Layouts are matched by ID.
 *  - Layout post_title and post_meta are updated; post_content is NOT touched.
 *  - A SnapshotManager snapshot is taken BEFORE any write.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Importers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Admin\SnapshotManager;
use D5DesignSystemHelper\Data\BuilderTemplatesRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Class BuilderTemplatesImporter
 */
class BuilderTemplatesImporter {

	private BuilderTemplatesRepository $repo;
	private string                     $file_path;

	/**
	 * @param string $file_path Absolute path to the uploaded .xlsx file.
	 */
	public function __construct( string $file_path ) {
		$this->file_path = $file_path;
		$this->repo      = new BuilderTemplatesRepository();
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Save a legacy backup.
	 *
	 * @return string Backup option key.
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
		SnapshotManager::push( 'builder_templates', $raw, 'import', basename( $this->file_path ) );

		$from_xlsx = $this->parse_xlsx();
		$diff      = $this->compute_diff( $from_xlsx, $raw );
		$new_count = 0;

		// Update templates (matched by title).
		foreach ( $from_xlsx['templates'] ?? [] as $tpl ) {
			$ctx      = 'Excel Template "' . ( $tpl['title'] ?? '?' ) . '"';
			$existing = get_posts( [
				'post_type'   => BuilderTemplatesRepository::TEMPLATE_POST_TYPE,
				'title'       => $tpl['title'],
				'post_status' => 'any',
				'numberposts' => 1,
			] );
			if ( empty( $existing ) ) {
				$new_count++;
				continue;
			}
			$tpl_id = $existing[0]->ID;
			update_post_meta( $tpl_id, '_et_default',      $tpl['default']  ? '1' : '0' );
			update_post_meta( $tpl_id, '_et_enabled',      $tpl['enabled']  ? '1' : '0' );
			update_post_meta( $tpl_id, '_et_use_on',       maybe_serialize( $tpl['use_on']       ?? [] ) );
			update_post_meta( $tpl_id, '_et_exclude_from', maybe_serialize( $tpl['exclude_from'] ?? [] ) );
			update_post_meta( $tpl_id, '_et_description',  \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_and_log( $tpl['description'] ?? '', $ctx, 'description' ) );
		}

		// Update layouts (matched by ID — post_content preserved from DB).
		foreach ( $from_xlsx['layouts'] ?? [] as $layout_id => $layout ) {
			$ctx  = 'Excel Layout #' . $layout_id;
			$post = get_post( (int) $layout_id );
			if ( ! $post ) {
				continue;
			}
			// Update title only (post_content comes from DB, not xlsx).
			if ( ! empty( $layout['post_title'] ) ) {
				$safe_title = \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_and_log( $layout['post_title'], $ctx, 'post_title' );
				wp_update_post( [ 'ID' => (int) $layout_id, 'post_title' => $safe_title ] );
			}
			foreach ( $layout['post_meta'] ?? [] as $meta_key => $meta_value ) {
				$safe_key = \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_and_log( $meta_key, $ctx, 'meta_key', 'key' );
				update_post_meta( (int) $layout_id, $safe_key, \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_meta_value( $meta_value, $ctx . ' meta ' . $safe_key ) );
			}
		}

		return [
			'updated'          => count( $diff['changes'] ),
			'skipped'          => count( $from_xlsx['templates'] ?? [] ) - count( $diff['changes'] ),
			'new'              => $new_count,
			'backup_option'    => 'd5dsh_snap_builder_templates_0',
			'sanitization_log' => \D5DesignSystemHelper\Admin\SimpleImporter::get_sanitization_log(),
		];
	}

	// ── Parser ────────────────────────────────────────────────────────────────

	/**
	 * Parse Templates and Layouts sheets.
	 *
	 * @return array{templates: array, layouts: array}
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function parse_xlsx(): array {
		$ss     = IOFactory::load( $this->file_path );
		$result = [ 'templates' => [], 'layouts' => [] ];

		// ── Templates sheet ──
		$ws_tpl = $ss->getSheetByName( 'Templates' );
		if ( $ws_tpl ) {
			for ( $row = 2; $row <= $ws_tpl->getHighestDataRow(); $row++ ) {
				$title = trim( (string) $ws_tpl->getCell( 'A' . $row )->getValue() );
				if ( ! $title ) {
					continue;
				}

				$use_on_json       = trim( (string) $ws_tpl->getCell( 'D' . $row )->getValue() );
				$exclude_from_json = trim( (string) $ws_tpl->getCell( 'E' . $row )->getValue() );

				$result['templates'][] = [
					'title'        => $title,
					'default'      => strtolower( trim( (string) $ws_tpl->getCell( 'B' . $row )->getValue() ) ) === 'yes',
					'enabled'      => strtolower( trim( (string) $ws_tpl->getCell( 'C' . $row )->getValue() ) ) !== 'no',
					'use_on'       => $use_on_json       ? ( json_decode( $use_on_json,       true ) ?? [] ) : [],
					'exclude_from' => $exclude_from_json ? ( json_decode( $exclude_from_json, true ) ?? [] ) : [],
					'layouts'      => [
						'header' => (int) $ws_tpl->getCell( 'F' . $row )->getValue(),
						'body'   => (int) $ws_tpl->getCell( 'G' . $row )->getValue(),
						'footer' => (int) $ws_tpl->getCell( 'H' . $row )->getValue(),
					],
					'description'  => trim( (string) $ws_tpl->getCell( 'I' . $row )->getValue() ),
				];
			}
		}

		// ── Layouts sheet ──
		$ws_lay = $ss->getSheetByName( 'Layouts' );
		if ( $ws_lay ) {
			for ( $row = 2; $row <= $ws_lay->getHighestDataRow(); $row++ ) {
				$layout_id = (int) $ws_lay->getCell( 'A' . $row )->getValue();
				if ( ! $layout_id ) {
					continue;
				}
				$meta_json = trim( (string) $ws_lay->getCell( 'E' . $row )->getValue() );

				$result['layouts'][ $layout_id ] = [
					'post_title' => trim( (string) $ws_lay->getCell( 'B' . $row )->getValue() ),
					'post_type'  => trim( (string) $ws_lay->getCell( 'C' . $row )->getValue() ),
					'is_global'  => strtolower( trim( (string) $ws_lay->getCell( 'D' . $row )->getValue() ) ) === 'yes',
					'post_meta'  => $meta_json ? ( json_decode( $meta_json, true ) ?? [] ) : [],
					'images'     => [],
				];
			}
		}

		return $result;
	}

	// ── Diff engine ───────────────────────────────────────────────────────────

	/**
	 * Compare parsed xlsx against the current DB.
	 *
	 * @param array $from_xlsx
	 * @param array $current
	 * @return array
	 */
	private function compute_diff( array $from_xlsx, array $current ): array {
		$changes = [];

		// Build a title-keyed map of current templates.
		$current_by_title = [];
		foreach ( $current['templates'] ?? [] as $tpl ) {
			$current_by_title[ $tpl['title'] ] = $tpl;
		}

		foreach ( $from_xlsx['templates'] ?? [] as $tpl ) {
			$db = $current_by_title[ $tpl['title'] ] ?? null;
			if ( ! $db ) {
				continue; // New template — counted separately.
			}
			foreach ( [ 'default', 'enabled', 'description' ] as $field ) {
				$old = (string) ( $db[ $field ] ?? '' );
				$new = (string) ( $tpl[ $field ] ?? '' );
				if ( $old !== $new ) {
					$changes[] = [
						'id'        => $tpl['title'],
						'label'     => $tpl['title'],
						'type'      => 'builder_template',
						'field'     => $field,
						'old_value' => $old,
						'new_value' => $new,
					];
				}
			}
		}

		return [ 'changes' => $changes, 'new_entries' => [], 'parse_errors' => [] ];
	}
}
