<?php
/**
 * Excel exporter for Divi 5 element and group presets.
 *
 * Produces an .xlsx file with the following sheets:
 *   Element Presets — one row per preset item across all modules
 *   Group Presets   — one row per preset item across all groups
 *   Global Colors   — color variables (from VarsRepository)
 *   Global Variables — number/font/image/string variables (from VarsRepository)
 *   Info            — combined export metadata + technical details
 *
 * ## Element Presets sheet columns
 *   Module | Preset ID | Name | Version | Is Default | Order | Attrs (JSON)
 *   | Style Attrs (JSON) | Group Presets (JSON)
 *
 * ## Group Presets sheet columns
 *   Group Name | Preset ID | Name | Version | Module Name | Group ID
 *   | Is Default | Attrs (JSON) | Style Attrs (JSON)
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Exporters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Admin\SnapshotManager;
use D5DesignSystemHelper\Data\PresetsRepository;
use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Util\ExportUtil;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Class PresetsExporter
 */
class PresetsExporter {

	/**
	 * Excel cell character limit (hard limit is 32,767).
	 * We use a slightly lower value to leave headroom.
	 */
	private const EXCEL_CELL_LIMIT = 32000;

	private PresetsRepository $presets_repo;
	private VarsRepository    $vars_repo;

	public function __construct() {
		$this->presets_repo = new PresetsRepository();
		$this->vars_repo    = new VarsRepository();
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Take a snapshot of the current presets, build the spreadsheet, and
	 * stream it as a file download to the browser.
	 *
	 * @return never
	 */
	public function stream_download(): never {
		$raw = $this->presets_repo->get_raw();

		// Auto-snapshot so state can be restored from Snapshots tab.
		SnapshotManager::push(
			'presets',
			$raw,
			'export',
			'Export on ' . gmdate( 'Y-m-d H:i:s' )
		);

		$ss        = $this->build_spreadsheet( $raw );
		$timestamp = ( new \DateTime( 'now', wp_timezone() ) )->format( 'Y-m-d_H.i' );
		$filename  = 'divi5-presets-' . $timestamp . '.xlsx';
		ExportUtil::stream_xlsx( $ss, $filename );
	}

	/**
	 * Build the spreadsheet object without streaming.
	 * Useful for zip bundling when multiple types are exported together.
	 *
	 * @param array|null $raw Pre-fetched raw data (null → fetch from DB).
	 * @return Spreadsheet
	 */
	public function build_spreadsheet( ?array $raw = null ): Spreadsheet {
		if ( $raw === null ) {
			$raw = $this->presets_repo->get_raw();
		}

		$vars_raw = $this->vars_repo->get_raw();

		$ss = new Spreadsheet();
		$ss->removeSheetByIndex( 0 ); // Remove the default blank sheet.

		// ── Info sheet first ─────────────────────────────────────────────────
		$this->build_presets_info_sheet( $ss, $raw );

		// ── Instructions / Disclaimer sheet second ────────────────────────────
		ExportUtil::write_instructions_sheet( $ss );

		$this->build_element_presets_sheet( $ss, $raw['module'] ?? [] );
		$this->build_group_presets_sheet( $ss, $raw['group'] ?? [] );

		$global_colors = $this->extract_global_colors( $vars_raw );
		$global_vars   = $this->vars_repo->get_all();

		ExportUtil::add_global_colors_sheet( $ss, $global_colors );
		// Protect all columns in Global Colors (reference-only — values come from vars export).
		// Data starts at row 4 now (title=1, blank=2, header=3, data=4+).
		$gc_ws = $ss->getSheetByName( 'Global Colors' );
		if ( $gc_ws !== null ) {
			$gc_last = max( 4, count( $global_colors ) + 3 );
			$gc_ws->getProtection()->setSheet( true )->setPassword( 'password' )->setSort( false )->setAutoFilter( false );
			$this->protect_preset_columns( $gc_ws, [ 1, 2, 3, 4, 5, 6, 7 ], $gc_last, 4 );
			$gc_ws->setSelectedCells( 'A1' );
		}

		ExportUtil::add_global_variables_sheet( $ss, $global_vars );
		// Protect all columns in Variables (reference-only).
		$gv_ws = $ss->getSheetByName( 'Variables' );
		if ( $gv_ws !== null ) {
			$gv_last = max( 4, count( $global_vars ) + 3 );
			$gv_ws->getProtection()->setSheet( true )->setPassword( 'password' )->setSort( false )->setAutoFilter( false );
			$this->protect_preset_columns( $gv_ws, [ 1, 2, 3, 4, 5, 6, 7 ], $gv_last, 4 );
			$gv_ws->setSelectedCells( 'A1' );
		}

		// Activate the Info sheet (index 0).
		$ss->setActiveSheetIndex( 0 );

		return $ss;
	}

	// ── Sheet builders ────────────────────────────────────────────────────────

	/**
	 * Build a combined Info sheet for the presets export.
	 * Combines site metadata, export details, and technical/integrity info
	 * (option key + SHA-256 hash) in a single visible sheet.
	 */
	private function build_presets_info_sheet( Spreadsheet $ss, array $raw ): void {
		$ws = $ss->createSheet();
		$ws->setTitle( 'Info' );

		global $wp_version;
		$tz        = wp_timezone();
		$export_dt = ( new \DateTime( 'now', $tz ) )->format( 'Y-m-d H:i T' );
		$hash      = hash( 'sha256', maybe_serialize( $raw ) );

		// Title row (dark navy, same format as Vars export sheets).
		ExportUtil::write_sheet_title_row( $ws, 2, 'Info' );

		// Export Details section header at row 3.
		$ws->setCellValue( 'A3', 'Export Details' );
		$ws->getStyle( 'A3' )->getFont()->setBold( true )->setItalic( true );

		$r = 4;
		$ws->setCellValue( 'A' . $r, 'Source Site' );      $ws->setCellValue( 'B' . $r, get_bloginfo( 'url' ) );   $r++;
		$ws->setCellValue( 'A' . $r, 'Site Name' );        $ws->setCellValue( 'B' . $r, ExportUtil::site_name() );  $r++;
		$ws->setCellValue( 'A' . $r, 'Export Date' );      $ws->setCellValue( 'B' . $r, $export_dt );              $r++;
		$ws->setCellValue( 'A' . $r, 'Exported By' );      $ws->setCellValue( 'B' . $r, wp_get_current_user()->display_name ); $r++;
		$ws->setCellValue( 'A' . $r, 'Divi Version' );     $ws->setCellValue( 'B' . $r, defined( 'ET_CORE_VERSION' ) ? ET_CORE_VERSION : 'Unknown' ); $r++;
		$ws->setCellValue( 'A' . $r, 'WordPress Version' ); $ws->setCellValue( 'B' . $r, $wp_version ); $r++;
		$ws->setCellValue( 'A' . $r, 'Plugin Version' );   $ws->setCellValue( 'B' . $r, defined( 'D5DSH_VERSION' ) ? D5DSH_VERSION : '' ); $r++;

		// Technical section.
		$r++;
		$ws->getStyle( 'A' . $r . ':B' . $r )->getBorders()->getTop()->setBorderStyle( 'thin' );
		$ws->setCellValue( 'A' . $r, 'Technical' );
		$ws->getStyle( 'A' . $r )->getFont()->setBold( true )->setItalic( true );
		$r++;

		$ws->setCellValue( 'A' . $r, 'File Type' );    $ws->setCellValue( 'B' . $r, 'presets' );                      $r++;
		$ws->setCellValue( 'A' . $r, 'Option Key' );   $ws->setCellValue( 'B' . $r, PresetsRepository::OPTION_KEY );   $r++;
		$ws->setCellValue( 'A' . $r, 'SHA-256 Hash' ); $ws->setCellValue( 'B' . $r, $hash );                           $r++;

		$ws->getColumnDimension( 'A' )->setWidth( 24 );
		$ws->getColumnDimension( 'B' )->setWidth( 70 );

		// Bold all A-column labels (rows 3 onward).
		$ws->getStyle( 'A3:A' . ( $r - 1 ) )->getFont()->setBold( true );
		$ws->setSelectedCells( 'A1' );
	}

	/**
	 * Build the Element Presets sheet.
	 *
	 * @param Spreadsheet $ss
	 * @param array       $modules  Raw 'module' subtree from the DB.
	 */
	private function build_element_presets_sheet( Spreadsheet $ss, array $modules ): void {
		$ws = new Worksheet( $ss, 'Element Presets' );
		$ss->addSheet( $ws );

		$headers = [
			'Element', 'Preset ID', 'Label', 'Version', 'Is Default',
			'Order', 'Attrs (JSON)', 'Style Attrs (JSON)', 'Group Presets (JSON)',
		];
		ExportUtil::write_sheet_title_row( $ws, count( $headers ), 'Element Presets' );
		ExportUtil::write_header_row_at( $ws, $headers, 3, true );

		$row = 4;
		foreach ( $modules as $module_name => $module_data ) {
			$default = $module_data['default'] ?? '';
			$order   = 1;
			foreach ( $module_data['items'] ?? [] as $preset_id => $preset ) {
				ExportUtil::cell( $ws, 1, $row )->setValue( $module_name );
				ExportUtil::cell( $ws, 2, $row )->setValue( $preset_id );
				ExportUtil::cell( $ws, 3, $row )->setValue( $preset['name']    ?? '' );
				ExportUtil::cell( $ws, 4, $row )->setValue( $preset['version'] ?? '' );
				ExportUtil::cell( $ws, 5, $row )->setValue( $preset_id === $default ? 'Yes' : 'No' );
				ExportUtil::cell( $ws, 6, $row )->setValue( $order );

				// JSON columns — use write_json_cell() to handle Excel's 32k char limit.
				$attrs_json         = isset( $preset['attrs'] )        ? wp_json_encode( $preset['attrs'] )        : '';
				$style_attrs_json   = isset( $preset['styleAttrs'] )   ? wp_json_encode( $preset['styleAttrs'] )   : '';
				$group_presets_json = isset( $preset['groupPresets'] ) ? wp_json_encode( $preset['groupPresets'] ) : '';

				$this->write_json_cell( $ws, 7, $row, $attrs_json );
				$this->write_json_cell( $ws, 8, $row, $style_attrs_json );
				$this->write_json_cell( $ws, 9, $row, $group_presets_json );

				$order++;
				$row++;
			}
		}

		$last_row = max( 4, $row - 1 );
		ExportUtil::apply_sheet_formatting( $ws, $last_row, 9 );
		ExportUtil::set_column_widths( $ws, [ 24, 16, 24, 12, 12, 8, 40, 40, 30 ] );

		// Protect all columns except Label (col 3); data starts at row 4.
		if ( $last_row >= 4 ) {
			$ws->getProtection()->setSheet( true )->setPassword( 'password' )->setSort( false )->setAutoFilter( false );
			$ws->getStyle( 'A4:I' . $last_row )->getProtection()
				->setLocked( \PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED );
			$this->protect_preset_columns( $ws, [ 1, 2, 4, 5, 6, 7, 8, 9 ], $last_row, 4 );
		}
		$ws->setSelectedCells( 'A1' );
	}

	/**
	 * Build the Group Presets sheet.
	 *
	 * @param Spreadsheet $ss
	 * @param array       $groups  Raw 'group' subtree from the DB.
	 */
	private function build_group_presets_sheet( Spreadsheet $ss, array $groups ): void {
		$ws = new Worksheet( $ss, 'Group Presets' );
		$ss->addSheet( $ws );

		$headers = [
			'Group Name', 'Preset ID', 'Label', 'Version',
			'Module Name', 'Group ID', 'Is Default',
			'Attrs (JSON)', 'Style Attrs (JSON)',
		];
		ExportUtil::write_sheet_title_row( $ws, count( $headers ), 'Group Presets' );
		ExportUtil::write_header_row_at( $ws, $headers, 3, true );

		$row = 4;
		foreach ( $groups as $group_name => $group_data ) {
			$default = $group_data['default'] ?? '';
			foreach ( $group_data['items'] ?? [] as $preset_id => $preset ) {
				ExportUtil::cell( $ws, 1, $row )->setValue( $group_name );
				ExportUtil::cell( $ws, 2, $row )->setValue( $preset_id );
				ExportUtil::cell( $ws, 3, $row )->setValue( $preset['name']       ?? '' );
				ExportUtil::cell( $ws, 4, $row )->setValue( $preset['version']    ?? '' );
				ExportUtil::cell( $ws, 5, $row )->setValue( $preset['moduleName'] ?? '' );
				ExportUtil::cell( $ws, 6, $row )->setValue( $preset['groupId']    ?? '' );
				ExportUtil::cell( $ws, 7, $row )->setValue( $preset_id === $default ? 'Yes' : 'No' );

				$attrs_json       = isset( $preset['attrs'] )      ? wp_json_encode( $preset['attrs'] )      : '';
				$style_attrs_json = isset( $preset['styleAttrs'] ) ? wp_json_encode( $preset['styleAttrs'] ) : '';

				$this->write_json_cell( $ws, 8, $row, $attrs_json );
				$this->write_json_cell( $ws, 9, $row, $style_attrs_json );

				$row++;
			}
		}

		$last_row = max( 4, $row - 1 );
		ExportUtil::apply_sheet_formatting( $ws, $last_row, 9 );
		ExportUtil::set_column_widths( $ws, [ 20, 16, 24, 12, 20, 16, 12, 40, 40 ] );

		// Protect all columns except Label (col 3); data starts at row 4.
		if ( $last_row >= 4 ) {
			$ws->getProtection()->setSheet( true )->setPassword( 'password' )->setSort( false )->setAutoFilter( false );
			$ws->getStyle( 'A4:I' . $last_row )->getProtection()
				->setLocked( \PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED );
			$this->protect_preset_columns( $ws, [ 1, 2, 4, 5, 6, 7, 8, 9 ], $last_row, 4 );
		}
		$ws->setSelectedCells( 'A1' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Write a potentially long JSON string to a cell, splitting into overflow
	 * continuation columns if the string exceeds EXCEL_CELL_LIMIT characters.
	 *
	 * The first cell receives the content (or a prefix listing overflow cell refs
	 * if splitting was needed). Continuation cells are placed 3 columns to the
	 * right of the base column each time (so Attrs in col G overflows to J, M, P...).
	 *
	 * Returns the highest column index written to (for column width tracking).
	 *
	 * @param Worksheet $ws
	 * @param int       $base_col  1-based column index for the primary cell.
	 * @param int       $row       Row number.
	 * @param string    $json      The JSON string to write.
	 * @return int  Highest column index written.
	 */
	private function write_json_cell( Worksheet $ws, int $base_col, int $row, string $json ): int {
		if ( $json === '' ) {
			return $base_col;
		}

		$limit   = self::EXCEL_CELL_LIMIT;
		$max_col = $base_col;

		if ( strlen( $json ) <= $limit ) {
			$ws->getCell( Coordinate::stringFromColumnIndex( $base_col ) . $row )
			   ->setValue( $json );
			$ws->getStyle( Coordinate::stringFromColumnIndex( $base_col ) . $row )
			   ->getFont()->setName( 'Courier New' )->setSize( 9 );
			return $base_col;
		}

		// Split into chunks and determine overflow cell references.
		$chunks = str_split( $json, $limit );
		$col    = $base_col + 3; // First overflow column (3 to the right).
		$refs   = [];
		foreach ( array_slice( $chunks, 1 ) as $chunk ) {
			$ref    = Coordinate::stringFromColumnIndex( $col ) . $row;
			$refs[] = $ref;
			$ws->getCell( $ref )->setValue( $chunk );
			$ws->getStyle( $ref )->getFont()->setName( 'Courier New' )->setSize( 9 );
			$max_col = max( $max_col, $col );
			$col    += 3;
		}

		// Primary cell: prefix with overflow reference list, then content chunk 1.
		$primary_ref = Coordinate::stringFromColumnIndex( $base_col ) . $row;
		$prefix      = '[cont: ' . implode( ', ', $refs ) . '] ';
		$ws->getCell( $primary_ref )->setValue( $prefix . $chunks[0] );
		$ws->getStyle( $primary_ref )->getFont()->setName( 'Courier New' )->setSize( 9 );

		return $max_col;
	}

	/**
	 * Apply gray fill + cell protection to specified columns (data rows only).
	 * Mirrors VarsExporter::protect_columns() for use in presets sheets.
	 *
	 * @param Worksheet $ws
	 * @param int[]     $col_indices  1-based column indices.
	 * @param int       $last_row
	 * @param int       $start_row   First data row (default 2).
	 */
	private function protect_preset_columns( Worksheet $ws, array $col_indices, int $last_row, int $start_row = 2 ): void {
		if ( $last_row < $start_row ) { return; }
		foreach ( $col_indices as $col ) {
			$col_letter = Coordinate::stringFromColumnIndex( $col );
			$range      = $col_letter . $start_row . ':' . $col_letter . $last_row;
			$ws->getStyle( $range )->applyFromArray( [
				'fill' => [
					'fillType'   => Fill::FILL_SOLID,
					'startColor' => [ 'argb' => 'FFE8E8E8' ],
				],
			] );
			$ws->getStyle( $range )->getProtection()
				->setLocked( Protection::PROTECTION_PROTECTED );
		}
	}

	/**
	 * Extract global colors from the vars raw data into the format expected
	 * by ExportUtil::add_global_colors_sheet().
	 *
	 * @param array $vars_raw  Raw vars option value.
	 * @return array  [ [id, [label, color, status]], ... ]
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
