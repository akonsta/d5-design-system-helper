<?php
/**
 * Excel exporter for Divi 5 Theme Customizer settings.
 *
 * Produces an .xlsx file with the following sheets:
 *   Settings        — all Divi Customizer key/value pairs (JSON for complex values)
 *   Global Colors   — color variables (from VarsRepository)
 *   Global Variables — number/font/image/string variables
 *   Info            — export metadata
 *   Config          — hidden: SHA-256 hash + file type marker
 *   Blobs           — hidden: placeholder records
 *
 * ## Settings sheet columns
 *   Category | Key | Value
 *   Values are JSON-encoded; simple strings/numbers are written as plain text.
 *   Complex values (arrays/objects) use Courier New 9pt for readability.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Exporters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Admin\SnapshotManager;
use D5DesignSystemHelper\Data\ThemeCustomizerRepository;
use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Util\ExportUtil;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Class ThemeCustomizerExporter
 */
class ThemeCustomizerExporter {

	private ThemeCustomizerRepository $repo;
	private VarsRepository            $vars_repo;

	public function __construct() {
		$this->repo      = new ThemeCustomizerRepository();
		$this->vars_repo = new VarsRepository();
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Snapshot, build, and stream the xlsx to the browser.
	 *
	 * @return never
	 */
	public function stream_download(): never {
		$raw = $this->repo->get_raw();

		SnapshotManager::push(
			'theme_customizer',
			$raw,
			'export',
			'Export on ' . gmdate( 'Y-m-d H:i:s' )
		);

		$ss       = $this->build_spreadsheet( $raw );
		$filename = 'divi5-theme-customizer-' . gmdate( 'Y-m-d' ) . '.xlsx';
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
			$raw = $this->repo->get_raw();
		}

		$vars_raw = $this->vars_repo->get_raw();

		$ss = new Spreadsheet();
		$ss->removeSheetByIndex( 0 );

		// Sheet order: Info → Instructions → data sheets → Config (hidden) → Blobs (hidden).
		ExportUtil::build_info_sheet( $ss, 'theme_customizer', ThemeCustomizerRepository::OPTION_KEY );
		ExportUtil::write_instructions_sheet( $ss );

		$this->build_settings_sheet( $ss );

		$global_colors = $this->extract_global_colors( $vars_raw );
		$global_vars   = $this->vars_repo->get_all();

		ExportUtil::add_global_colors_sheet( $ss, $global_colors );
		ExportUtil::add_global_variables_sheet( $ss, $global_vars );
		ExportUtil::build_config_sheet( $ss, $raw, ThemeCustomizerRepository::OPTION_KEY, 'theme_customizer' );
		ExportUtil::build_blobs_sheet( $ss, [] );

		$ss->setActiveSheetIndex( 0 );
		return $ss;
	}

	// ── Sheet builders ────────────────────────────────────────────────────────

	/**
	 * Build the Settings sheet from the repository.
	 *
	 * @param Spreadsheet $ss
	 */
	private function build_settings_sheet( Spreadsheet $ss ): void {
		$ws = new Worksheet( $ss, 'Settings' );
		$ss->addSheet( $ws );

		ExportUtil::write_header_row( $ws, [ 'Category', 'Key', 'Value' ] );

		$rows = $this->repo->get_settings_list();
		$row  = 2;

		foreach ( $rows as $item ) {
			$value = $item['value'];

			// Represent complex types as JSON; simple scalars as plain text.
			if ( is_array( $value ) || is_object( $value ) ) {
				$cell_value = wp_json_encode( $value );
				$is_complex = true;
			} else {
				$cell_value = (string) $value;
				$is_complex = false;
			}

			ExportUtil::cell( $ws, 1, $row )->setValue( $item['category'] );
			ExportUtil::cell( $ws, 2, $row )->setValue( $item['key'] );
			ExportUtil::cell( $ws, 3, $row )->setValue( $cell_value );

			// Courier New 9pt for JSON values to aid readability.
			if ( $is_complex ) {
				$ws->getStyle( 'C' . $row )->getFont()->setName( ExportUtil::MONO_FONT )->setSize( 9 );
			}

			$row++;
		}

		ExportUtil::apply_sheet_formatting( $ws, max( 1, $row - 1 ), 3 );
		$ws->setAutoFilter( 'A1:C' . max( 1, $row - 1 ) );
		ExportUtil::set_column_widths( $ws, [ 20, 40, 60 ] );
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
