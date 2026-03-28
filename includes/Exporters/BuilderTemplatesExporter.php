<?php
/**
 * Excel exporter for Divi Theme Builder templates.
 *
 * Sheets:
 *   Templates       — one row per et_template post
 *   Layouts         — one row per layout post (NO post_content — read from DB on import)
 *   Global Colors   — color variables
 *   Global Variables — non-color variables
 *   Presets – Modules
 *   Presets – Groups
 *   Info
 *   Config          — hidden
 *   Blobs           — hidden
 *
 * ## Templates sheet columns
 *   Title | Default | Enabled | Use On (JSON) | Exclude From (JSON)
 *   | Header Layout ID | Body Layout ID | Footer Layout ID | Description
 *
 * ## Layouts sheet columns
 *   Layout ID | Post Title | Post Type | Is Global | Post Meta (JSON) | Images (JSON)
 *   (post_content intentionally omitted — read from DB on import)
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Exporters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Admin\SnapshotManager;
use D5DesignSystemHelper\Data\BuilderTemplatesRepository;
use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Data\PresetsRepository;
use D5DesignSystemHelper\Util\ExportUtil;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Class BuilderTemplatesExporter
 */
class BuilderTemplatesExporter {

	private BuilderTemplatesRepository $repo;
	private VarsRepository             $vars_repo;
	private PresetsRepository          $presets_repo;

	public function __construct() {
		$this->repo         = new BuilderTemplatesRepository();
		$this->vars_repo    = new VarsRepository();
		$this->presets_repo = new PresetsRepository();
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Snapshot, build, and stream the xlsx.
	 *
	 * @return never
	 */
	public function stream_download(): never {
		$raw = $this->repo->get_all();

		SnapshotManager::push(
			'builder_templates',
			$raw,
			'export',
			'Export on ' . gmdate( 'Y-m-d H:i:s' )
		);

		$ss       = $this->build_spreadsheet( $raw );
		$filename = 'divi5-builder-templates-' . gmdate( 'Y-m-d' ) . '.xlsx';
		ExportUtil::stream_xlsx( $ss, $filename );
	}

	/**
	 * Build the spreadsheet object (for zip bundling).
	 *
	 * @param array|null $raw Pre-fetched raw data.
	 * @return Spreadsheet
	 */
	public function build_spreadsheet( ?array $raw = null ): Spreadsheet {
		if ( $raw === null ) {
			$raw = $this->repo->get_all();
		}

		$vars_raw    = $this->vars_repo->get_raw();
		$presets_raw = $this->presets_repo->get_raw();

		$ss = new Spreadsheet();
		$ss->removeSheetByIndex( 0 );

		// Sheet order: Info → Instructions → data sheets → Config (hidden) → Blobs (hidden).
		ExportUtil::build_info_sheet( $ss, 'builder_templates', BuilderTemplatesRepository::TEMPLATE_POST_TYPE );
		ExportUtil::write_instructions_sheet( $ss );

		$this->build_templates_sheet( $ss, $raw['templates'] ?? [] );
		$this->build_layouts_sheet( $ss, $raw['layouts'] ?? [] );

		$global_colors = $this->extract_global_colors( $vars_raw );
		$global_vars   = $this->vars_repo->get_all();

		ExportUtil::add_global_colors_sheet( $ss, $global_colors );
		ExportUtil::add_global_variables_sheet( $ss, $global_vars );
		ExportUtil::add_presets_sheets( $ss, $presets_raw );
		ExportUtil::build_config_sheet( $ss, $raw, BuilderTemplatesRepository::TEMPLATE_POST_TYPE, 'builder_templates' );
		ExportUtil::build_blobs_sheet( $ss, [] );

		$ss->setActiveSheetIndex( 0 );
		return $ss;
	}

	// ── Sheet builders ────────────────────────────────────────────────────────

	/**
	 * Build the Templates sheet.
	 *
	 * @param Spreadsheet $ss
	 * @param array       $templates
	 */
	private function build_templates_sheet( Spreadsheet $ss, array $templates ): void {
		$ws = new Worksheet( $ss, 'Templates' );
		$ss->addSheet( $ws );

		$headers = [
			'Title', 'Default', 'Enabled',
			'Use On (JSON)', 'Exclude From (JSON)',
			'Header Layout ID', 'Body Layout ID', 'Footer Layout ID',
			'Description',
		];
		ExportUtil::write_header_row( $ws, $headers );

		$row = 2;
		foreach ( $templates as $tpl ) {
			ExportUtil::cell( $ws, 1, $row )->setValue( $tpl['title']   ?? '' );
			ExportUtil::cell( $ws, 2, $row )->setValue( ( $tpl['default'] ?? false ) ? 'Yes' : 'No' );
			ExportUtil::cell( $ws, 3, $row )->setValue( ( $tpl['enabled'] ?? true  ) ? 'Yes' : 'No' );

			$use_on_json       = wp_json_encode( $tpl['use_on']       ?? [] );
			$exclude_from_json = wp_json_encode( $tpl['exclude_from'] ?? [] );

			ExportUtil::cell( $ws, 4, $row )->setValue( $use_on_json );
			ExportUtil::cell( $ws, 5, $row )->setValue( $exclude_from_json );
			ExportUtil::cell( $ws, 6, $row )->setValue( $tpl['layouts']['header'] ?? 0 );
			ExportUtil::cell( $ws, 7, $row )->setValue( $tpl['layouts']['body']   ?? 0 );
			ExportUtil::cell( $ws, 8, $row )->setValue( $tpl['layouts']['footer'] ?? 0 );
			ExportUtil::cell( $ws, 9, $row )->setValue( $tpl['description'] ?? '' );

			$row++;
		}

		ExportUtil::apply_sheet_formatting( $ws, max( 1, $row - 1 ), 9 );
		ExportUtil::set_column_widths( $ws, [ 30, 10, 10, 30, 30, 18, 16, 18, 40 ] );
	}

	/**
	 * Build the Layouts sheet (no post_content column).
	 *
	 * @param Spreadsheet $ss
	 * @param array       $layouts  Keyed by post_id.
	 */
	private function build_layouts_sheet( Spreadsheet $ss, array $layouts ): void {
		$ws = new Worksheet( $ss, 'Layouts' );
		$ss->addSheet( $ws );

		$headers = [
			'Layout ID', 'Post Title', 'Post Type', 'Is Global',
			'Post Meta (JSON)', 'Images (JSON)',
		];
		ExportUtil::write_header_row( $ws, $headers );

		$row = 2;
		foreach ( $layouts as $layout_id => $layout ) {
			$meta_json   = wp_json_encode( $layout['post_meta'] ?? [] );
			$images_json = wp_json_encode( $layout['images']   ?? [] );

			ExportUtil::cell( $ws, 1, $row )->setValue( $layout_id );
			ExportUtil::cell( $ws, 2, $row )->setValue( $layout['post_title'] ?? '' );
			ExportUtil::cell( $ws, 3, $row )->setValue( $layout['post_type']  ?? '' );
			ExportUtil::cell( $ws, 4, $row )->setValue( ( $layout['is_global'] ?? false ) ? 'Yes' : 'No' );
			ExportUtil::cell( $ws, 5, $row )->setValue( $meta_json );
			ExportUtil::cell( $ws, 6, $row )->setValue( $images_json );

			$ws->getStyle( 'E' . $row )->getFont()->setName( ExportUtil::MONO_FONT )->setSize( 9 );

			$row++;
		}

		ExportUtil::apply_sheet_formatting( $ws, max( 1, $row - 1 ), 6 );
		ExportUtil::set_column_widths( $ws, [ 12, 30, 20, 12, 50, 30 ] );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * @param array $vars_raw
	 * @return array
	 */
	private function extract_global_colors( array $vars_raw ): array {
		$out = [];
		foreach ( $vars_raw['colors'] ?? [] as $id => $entry ) {
			$out[] = [
				$id,
				[
					'label'  => $entry['label']  ?? '',
					'color'  => $entry['value']  ?? '',
					'status' => $entry['status'] ?? 'active',
				],
			];
		}
		return $out;
	}
}
