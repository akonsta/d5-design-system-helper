<?php
/**
 * Excel exporter for Divi Library layouts and WordPress pages.
 *
 * A single class handles both post types; pass 'layouts' or 'pages' as
 * the $file_type constructor parameter.
 *
 * Sheets:
 *   Layouts / Pages — one row per post
 *   Global Colors   — color variables
 *   Global Variables — non-color variables
 *   Presets – Modules — module preset rows
 *   Presets – Groups  — group preset rows
 *   Info            — export metadata
 *   Config          — hidden
 *   Blobs           — hidden
 *
 * ## Layout/Pages sheet columns
 *   ID | Title | Post Type | Post Name | Status | Date | Modified
 *   | Menu Order | Parent ID | Post Meta (JSON) | Terms (JSON)
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Exporters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Admin\SnapshotManager;
use D5DesignSystemHelper\Data\LayoutsRepository;
use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Data\PresetsRepository;
use D5DesignSystemHelper\Util\ExportUtil;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Class LayoutsExporter
 */
class LayoutsExporter {

	private LayoutsRepository $repo;
	private VarsRepository    $vars_repo;
	private PresetsRepository $presets_repo;
	private string            $file_type;  // 'layouts' or 'pages'
	private string            $post_type;  // 'et_pb_layout' or 'page'
	private string            $status;

	/**
	 * @param string $file_type  'layouts' or 'pages'.
	 * @param string $status     Post status filter ('any', 'publish', 'draft', 'private').
	 */
	public function __construct( string $file_type = 'layouts', string $status = 'any' ) {
		$this->file_type   = $file_type;
		$this->status      = $status;
		$this->post_type   = ( $file_type === 'pages' ) ? 'page' : LayoutsRepository::POST_TYPE;
		$this->repo        = new LayoutsRepository();
		$this->vars_repo   = new VarsRepository();
		$this->presets_repo= new PresetsRepository();
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Snapshot, build, and stream the xlsx.
	 *
	 * @return never
	 */
	public function stream_download(): never {
		$raw = $this->repo->get_all( $this->post_type, $this->status );

		SnapshotManager::push(
			$this->file_type,
			$raw,
			'export',
			'Export on ' . gmdate( 'Y-m-d H:i:s' )
		);

		$ss       = $this->build_spreadsheet( $raw );
		$filename = 'divi5-' . $this->file_type . '-' . gmdate( 'Y-m-d' ) . '.xlsx';
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
			$raw = $this->repo->get_all( $this->post_type, $this->status );
		}

		$vars_raw    = $this->vars_repo->get_raw();
		$presets_raw = $this->presets_repo->get_raw();

		$ss = new Spreadsheet();
		$ss->removeSheetByIndex( 0 );

		// Sheet order: Info → Instructions → data sheets → Config (hidden) → Blobs (hidden).
		ExportUtil::build_info_sheet( $ss, $this->file_type, LayoutsRepository::POST_TYPE );
		ExportUtil::write_instructions_sheet( $ss );

		$this->build_posts_sheet( $ss, $raw );

		$global_colors = $this->extract_global_colors( $vars_raw );
		$global_vars   = $this->vars_repo->get_all();

		ExportUtil::add_global_colors_sheet( $ss, $global_colors );
		ExportUtil::add_global_variables_sheet( $ss, $global_vars );
		ExportUtil::add_presets_sheets( $ss, $presets_raw );
		ExportUtil::build_config_sheet( $ss, $raw, LayoutsRepository::POST_TYPE, $this->file_type );
		ExportUtil::build_blobs_sheet( $ss, [] );

		$ss->setActiveSheetIndex( 0 );
		return $ss;
	}

	// ── Sheet builders ────────────────────────────────────────────────────────

	/**
	 * Build the main Layouts or Pages sheet.
	 *
	 * @param Spreadsheet $ss
	 * @param array       $posts  Result of LayoutsRepository::get_all().
	 */
	private function build_posts_sheet( Spreadsheet $ss, array $posts ): void {
		$sheet_name = ucfirst( $this->file_type ); // 'Layouts' or 'Pages'
		$ws         = new Worksheet( $ss, $sheet_name );
		$ss->addSheet( $ws );

		$headers = [
			'ID', 'Title', 'Post Type', 'Post Name', 'Status',
			'Date', 'Modified', 'Menu Order', 'Parent ID',
			'Post Meta (JSON)', 'Terms (JSON)',
		];
		ExportUtil::write_header_row( $ws, $headers );

		$row = 2;
		foreach ( $posts as $post_id => $post_data ) {
			$fields = $post_data['post_fields'] ?? [];
			ExportUtil::cell( $ws, 1,  $row )->setValue( $post_id );
			ExportUtil::cell( $ws, 2,  $row )->setValue( $fields['post_title']    ?? '' );
			ExportUtil::cell( $ws, 3,  $row )->setValue( $fields['post_type']     ?? '' );
			ExportUtil::cell( $ws, 4,  $row )->setValue( $fields['post_name']     ?? '' );
			ExportUtil::cell( $ws, 5,  $row )->setValue( $fields['post_status']   ?? '' );
			ExportUtil::cell( $ws, 6,  $row )->setValue( $fields['post_date']     ?? '' );
			ExportUtil::cell( $ws, 7,  $row )->setValue( $fields['post_modified'] ?? '' );
			ExportUtil::cell( $ws, 8,  $row )->setValue( $fields['menu_order']    ?? '' );
			ExportUtil::cell( $ws, 9,  $row )->setValue( $fields['post_parent']   ?? '' );

			$meta_json  = wp_json_encode( $post_data['post_meta'] ?? [] );
			$terms_json = wp_json_encode( $post_data['terms']     ?? [] );

			ExportUtil::cell( $ws, 10, $row )->setValue( $meta_json );
			ExportUtil::cell( $ws, 11, $row )->setValue( $terms_json );

			// Monospace for JSON columns.
			$ws->getStyle( 'J' . $row )->getFont()->setName( ExportUtil::MONO_FONT )->setSize( 9 );
			$ws->getStyle( 'K' . $row )->getFont()->setName( ExportUtil::MONO_FONT )->setSize( 9 );

			$row++;
		}

		ExportUtil::apply_sheet_formatting( $ws, max( 1, $row - 1 ), 11 );
		$ws->setAutoFilter( 'A1:K' . max( 1, $row - 1 ) );
		ExportUtil::set_column_widths( $ws, [ 10, 30, 16, 24, 12, 20, 20, 10, 10, 40, 30 ] );
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
