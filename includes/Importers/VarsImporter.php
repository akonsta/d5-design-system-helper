<?php
/**
 * Excel importer for Divi 5 global variables and global colors.
 *
 * Reads an .xlsx file produced by VarsExporter (or the Python export_vars.py
 * tool) and either:
 *  - Returns a diff (dry_run mode) showing which variables would change, or
 *  - Writes the changes back to the database.
 *
 * ## Storage split
 *
 *  Colors (gcid-xxx)         → et_divi[et_global_data][global_colors]
 *  System fonts (--et_global_*) → et_divi['heading_font'] / et_divi['body_font']
 *  All other types           → et_divi_global_variables
 *
 * ## Safety model
 *
 *  1. backup_current() — saves a full copy of both option sources to a
 *     timestamped backup key (d5dsh_backup_vars_YYYYMMDD_HHMMSS).
 *  2. dry_run()        — returns a ['changes' => [...]] array without writing.
 *  3. commit()         — calls backup_current() internally then writes.
 *
 * ## Import rules
 *
 *  - Only the editable fields are taken from Excel: label, value, status.
 *  - The 'id' and 'type' columns are used to locate each variable in the DB.
 *  - For colors the DB field is 'color'; this is mapped from the normalised 'value'.
 *  - The 'order' column is read but not used to re-order DB keys.
 *  - If a cell contains the blob placeholder, the current DB value is kept.
 *  - Variables present in the DB but absent from the Excel file are left untouched.
 *  - Variables present in the Excel file but absent from the DB are treated as new.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Importers;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Util\BlobUtil;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Class VarsImporter
 */
class VarsImporter {

	/** Sheets that contain variable data (everything except Info). */
	private const DATA_SHEETS = [ 'Colors', 'Numbers', 'Fonts', 'Images', 'Text', 'Links' ];

	/** Map of sheet title → variable type stored in the DB. */
	private const SHEET_TYPE_MAP = [
		'Colors'  => 'colors',
		'Numbers' => 'numbers',
		'Fonts'   => 'fonts',
		'Images'  => 'images',
		'Text'    => 'strings',
		'Links'   => 'links',
	];

	/** Column index of each field within the data sheets (1-based).
	 *  All type sheets share the same layout for columns 1-3.
	 *  Colors sheet layout: Order | ID | Label | Swatch | Value | Reference | Status | System | Hidden
	 *  Others sheet layout: Order | ID | Label | Value | Status | System */
	private const COL_ORDER  = 1;
	private const COL_ID     = 2;
	private const COL_LABEL  = 3;
	private const COL_VALUE  = 4; // Value/Font Family — col 4 on all non-Colors sheets

	// Colors sheet uses different column positions (Swatch occupies col 4):
	private const COL_COLOR_VALUE  = 5; // Value is col 5 on Colors (Swatch is col 4)

	// Status and System columns differ by sheet type:
	//   Colors : Status=7, System=8  (Swatch=4, Value=5, Reference=6)
	//   Others : Status=5, System=6
	private const COL_STATUS = 5;

	/** Status column is col 7 on the Colors sheet (col 5 on all others). */
	private const COL_COLOR_STATUS = 7;

	/** System flag column: col 8 on Colors sheet, col 6 on all others. */
	private const COL_COLOR_SYSTEM = 8;
	private const COL_SYSTEM       = 6;

	/** Hidden flag column: col 9 on Colors sheet only (read-only, not imported). */
	private const COL_COLOR_HIDDEN = 9;

	private VarsRepository $repo;
	private string         $file_path;

	/**
	 * @param string $file_path Absolute path to the uploaded .xlsx file.
	 */
	public function __construct( string $file_path ) {
		$this->file_path = $file_path;
		$this->repo      = new VarsRepository();
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Save a backup of the current database state.
	 *
	 * Returns the wp_options key under which the backup was stored.
	 *
	 * @return string Backup option key.
	 */
	public function backup_current(): string {
		return $this->repo->backup();
	}

	/**
	 * Parse the Excel file and return a diff without writing anything.
	 *
	 * Return format:
	 * [
	 *   'changes' => [
	 *     [
	 *       'id'        => 'gvid-xxx',
	 *       'label'     => 'My Variable',
	 *       'type'      => 'numbers',
	 *       'field'     => 'value'|'label'|'status',
	 *       'old_value' => '...',
	 *       'new_value' => '...',
	 *     ],
	 *     ...
	 *   ],
	 *   'new_entries' => [ ... ],   // IDs not found in the current DB
	 *   'parse_errors'=> [ ... ],   // Row-level parse problems (non-fatal)
	 * ]
	 *
	 * @return array
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	public function dry_run(): array {
		$from_excel          = $this->parse_excel();
		$current_vars        = $this->repo->get_raw();
		$current_colors      = $this->repo->get_raw_colors();
		$current_sys_fonts   = $this->repo->get_raw_system_fonts();
		$current_sys_colors  = $this->repo->get_raw_system_colors();

		return $this->compute_diff( $from_excel, $current_vars, $current_colors, $current_sys_fonts, $current_sys_colors );
	}

	/**
	 * Write the imported data to the database.
	 *
	 * Automatically takes a backup before writing.
	 *
	 * @return array [
	 *   'updated'        => int,   // number of changed variables
	 *   'skipped'        => int,   // unchanged variables
	 *   'new'            => int,   // newly added variables
	 *   'backup_option'  => string // backup key name
	 * ]
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	public function commit(): array {
		\D5DesignSystemHelper\Admin\SimpleImporter::reset_sanitization_log();
		$backup_key          = $this->backup_current();
		$from_excel          = $this->parse_excel();
		$current_vars        = $this->repo->get_raw();
		$current_colors      = $this->repo->get_raw_colors();
		$current_sys_fonts   = $this->repo->get_raw_system_fonts();
		$current_sys_colors  = $this->repo->get_raw_system_colors();
		$diff                = $this->compute_diff( $from_excel, $current_vars, $current_colors, $current_sys_fonts, $current_sys_colors );

		$updated_vars        = $current_vars;
		$updated_colors      = $current_colors;
		$updated_sys_fonts   = $current_sys_fonts;
		$updated_sys_colors  = $current_sys_colors;
		$new_count           = 0;
		$system_font_ids     = array_keys( VarsRepository::SYSTEM_FONTS );
		$system_color_ids    = array_keys( VarsRepository::SYSTEM_COLORS );

		foreach ( $from_excel as $entry ) {
			$type  = $entry['type'];
			$id    = $entry['id'];
			$value = $entry['value'];

			if ( $type === 'colors' && in_array( $id, $system_color_ids, true ) ) {
				// System color: only the hex value is writable.
				// Label and ID are fixed — never count as new entries.
				$updated_sys_colors[ $id ] = $value;
			} elseif ( $type === 'colors' ) {
				// Skip blob placeholder — preserve existing DB value.
				if ( $value === 'Uneditable Data Not Shown' ) {
					$value = $current_colors[ $id ]['color'] ?? $value;
				}
				$is_new = ! isset( $updated_colors[ $id ] );
				// Merge into existing entry to preserve non-editable fields.
				$existing = $updated_colors[ $id ] ?? [ 'id' => $id ];
				$existing['id']     = $id;
				$existing['label']  = $entry['label'];
				$existing['color']  = $value;
				$existing['status'] = $entry['status'];
				if ( isset( $entry['order'] ) ) {
					$existing['order'] = $entry['order'];
				}
				$updated_colors[ $id ] = $existing;
				if ( $is_new ) {
					$new_count++;
				}
			} elseif ( $type === 'fonts' && in_array( $id, $system_font_ids, true ) ) {
				// System font: only the font family value is writable.
				// Label and ID are fixed — never count as new entries.
				$updated_sys_fonts[ $id ] = $value;
			} else {
				// Skip blob placeholder — preserve existing DB value.
				if ( $value === 'Uneditable Data Not Shown' ) {
					$value = $current_vars[ $type ][ $id ]['value'] ?? $value;
				}
				$is_new = ! isset( $updated_vars[ $type ][ $id ] );
				$updated_vars[ $type ][ $id ] = [
					'id'     => $id,
					'label'  => $entry['label'],
					'value'  => $value,
					'status' => $entry['status'],
				];
				if ( $is_new ) {
					$new_count++;
				}
			}
		}

		$total_in_excel = count( $from_excel );
		$skipped_count  = $total_in_excel - count( $diff['changes'] ) - $new_count;

		$this->repo->save_raw( $updated_vars );
		$this->repo->save_raw_colors( $updated_colors );
		$this->repo->save_raw_system_fonts( $updated_sys_fonts );
		$this->repo->save_raw_system_colors( $updated_sys_colors );

		return [
			'updated'          => count( $diff['changes'] ),
			'skipped'          => max( 0, $skipped_count ),
			'new'              => $new_count,
			'backup_option'    => $backup_key,
			'sanitization_log' => \D5DesignSystemHelper\Admin\SimpleImporter::get_sanitization_log(),
		];
	}

	// ── Parser ────────────────────────────────────────────────────────────────

	/**
	 * Parse all data sheets from the Excel file.
	 *
	 * Returns a flat list of variable records in the same normalised format
	 * as VarsRepository::get_all().
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function parse_excel(): array {
		$spreadsheet = IOFactory::load( $this->file_path );
		$records     = [];

		foreach ( self::DATA_SHEETS as $sheet_title ) {
			$ws = $spreadsheet->getSheetByName( $sheet_title );
			if ( ! $ws ) {
				continue; // Sheet may not exist if no variables of that type.
			}

			$type        = self::SHEET_TYPE_MAP[ $sheet_title ];
			$is_colors   = ( $sheet_title === 'Colors' );
			$status_col  = $is_colors ? self::COL_COLOR_STATUS : self::COL_STATUS;
			$system_col  = $is_colors ? self::COL_COLOR_SYSTEM : self::COL_SYSTEM;

			$max_row = $ws->getHighestDataRow();

			for ( $row = 2; $row <= $max_row; $row++ ) {
				$id = trim( (string) $ws->getCell( Coordinate::stringFromColumnIndex( self::COL_ID ) . $row )->getValue() );

				// Skip empty rows.
				if ( ! $id ) {
					continue;
				}

				$label      = trim( (string) $ws->getCell( Coordinate::stringFromColumnIndex( self::COL_LABEL )                               . $row )->getValue() );
				$value_col  = $is_colors ? self::COL_COLOR_VALUE : self::COL_VALUE;
				$value      = trim( (string) $ws->getCell( Coordinate::stringFromColumnIndex( $value_col )                                 . $row )->getValue() );
				$status     = trim( (string) $ws->getCell( Coordinate::stringFromColumnIndex( $status_col )                                . $row )->getValue() );
				$order      = (int)           $ws->getCell( Coordinate::stringFromColumnIndex( self::COL_ORDER )                           . $row )->getValue();
				$system_raw = trim( (string) $ws->getCell( Coordinate::stringFromColumnIndex( $system_col )                                . $row )->getValue() );
				$is_system  = ( strtoupper( $system_raw ) === 'TRUE' );

				// Colors sheet layout: Order | ID | Label | Swatch | Value | Reference | Status | System | Hidden
				// Col 4 = Swatch (read-only), col 5 = Value (hex). Col 6 = Reference (informational, not imported).

				$ctx     = 'Excel Variable ' . $id;
				$label   = \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_and_log( $label, $ctx, 'label' );
				$value   = \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_and_log( $value, $ctx, 'value' );
				$status  = \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_and_log( $status ?: 'active', $ctx, 'status', 'key' );

				$records[] = [
					'id'     => $id,
					'label'  => $label,
					'value'  => $value,
					'type'   => $type,
					'status' => $status,
					'order'  => $order,
					'system' => $is_system,
				];
			}
		}

		return $records;
	}

	// ── Diff engine ───────────────────────────────────────────────────────────

	/**
	 * Compare parsed Excel records against the current DB state and return a
	 * structured diff.
	 *
	 * A "change" is recorded for every field (label, value, status) that
	 * differs between the Excel row and the current DB entry. This matches
	 * the Python tool's "full comparable dict" change detection.
	 *
	 * @param array $from_excel Parsed records from the Excel file.
	 * @param array $current    Raw (nested) data from the database.
	 * @return array
	 */
	private function compute_diff( array $from_excel, array $current_vars, array $current_colors, array $current_sys_fonts = [], array $current_sys_colors = [] ): array {
		$changes          = [];
		$new_entries      = [];
		$parse_errors     = [];
		$system_font_ids  = array_keys( VarsRepository::SYSTEM_FONTS );
		$system_color_ids = array_keys( VarsRepository::SYSTEM_COLORS );

		foreach ( $from_excel as $entry ) {
			$type = $entry['type'];
			$id   = $entry['id'];

			if ( $type === 'colors' && in_array( $id, $system_color_ids, true ) ) {
				// System color: only value (hex) is writable; id/label are fixed.
				$compare = [
					'value' => (string) ( $current_sys_colors[ $id ] ?? '' ),
				];
				$check_fields = [ 'value' ];
			} elseif ( $type === 'colors' ) {
				if ( ! isset( $current_colors[ $id ] ) ) {
					$new_entries[] = $entry;
					continue;
				}
				$db_entry = $current_colors[ $id ];
				// DB uses 'color' field; normalised uses 'value'.
				$compare = [
					'label'  => (string) ( $db_entry['label']  ?? '' ),
					'value'  => (string) ( $db_entry['color']  ?? '' ),
					'status' => (string) ( $db_entry['status'] ?? 'active' ),
				];
				$check_fields = [ 'label', 'value', 'status' ];
			} elseif ( $type === 'fonts' && in_array( $id, $system_font_ids, true ) ) {
				// System font: only value (font family) is writable; id/label are fixed.
				$compare = [
					'value' => (string) ( $current_sys_fonts[ $id ] ?? '' ),
				];
				$check_fields = [ 'value' ];
			} else {
				if ( ! isset( $current_vars[ $type ][ $id ] ) ) {
					$new_entries[] = $entry;
					continue;
				}
				$db_entry = $current_vars[ $type ][ $id ];
				$compare = [
					'label'  => (string) ( $db_entry['label']  ?? '' ),
					'value'  => (string) ( $db_entry['value']  ?? '' ),
					'status' => (string) ( $db_entry['status'] ?? 'active' ),
				];
				$check_fields = [ 'label', 'value', 'status' ];
			}

			foreach ( $check_fields as $field ) {
				$old = $compare[ $field ];
				$new = (string) ( $entry[ $field ] ?? '' );

				if ( $new === 'Uneditable Data Not Shown' ) {
					continue;
				}

				if ( $old !== $new ) {
					$changes[] = [
						'id'        => $id,
						'label'     => $entry['label'],
						'type'      => $type,
						'field'     => $field,
						'old_value' => $old,
						'new_value' => $new,
					];
				}
			}
		}

		return [
			'changes'      => $changes,
			'new_entries'  => $new_entries,
			'parse_errors' => $parse_errors,
		];
	}
}
