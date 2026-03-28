<?php
/**
 * Excel file validator for D5 Design System Helper.
 *
 * Validates an uploaded .xlsx file and returns a structured report
 * using a four-level taxonomy:
 *
 *   FATAL   — file cannot be imported at all (corrupt, wrong type, missing structure)
 *   ERROR   — specific rows will be skipped or fail on import
 *   WARNING — data will import but may produce unexpected results
 *   INFO    — observations (near-duplicates, unusual values, inferred types)
 *
 * The validator intentionally does NOT require a Config sheet — it attempts
 * to infer the file type from sheet names and column headers when Config is
 * absent. This makes it useful for community-distributed design system files
 * that were not exported by this plugin.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Class Validator
 */
class Validator {

	// Issue severity levels.
	const FATAL   = 'fatal';
	const ERROR   = 'error';
	const WARNING = 'warning';
	const INFO    = 'info';

	// Known file types and their expected sheet names + required columns.
	const FILE_TYPE_SIGNATURES = [
		'vars'    => [
			'sheets'  => [ 'Colors', 'Numbers', 'Fonts', 'Images', 'Text', 'Links' ],
			'label'   => 'Variables',
			'require' => [ 'Colors' ],
		],
		'presets' => [
			'sheets'  => [ 'Presets – Modules', 'Presets – Groups' ],
			'label'   => 'Module Presets',
			'require' => [ 'Presets – Modules' ],
		],
		'layouts' => [
			'sheets'  => [ 'Layouts' ],
			'label'   => 'Layouts',
			'require' => [ 'Layouts' ],
		],
		'pages'   => [
			'sheets'  => [ 'Pages' ],
			'label'   => 'Pages',
			'require' => [ 'Pages' ],
		],
		'theme_customizer' => [
			'sheets'  => [ 'Customizer' ],
			'label'   => 'Theme Customizer',
			'require' => [ 'Customizer' ],
		],
		'builder_templates' => [
			'sheets'  => [ 'Templates' ],
			'label'   => 'Builder Templates',
			'require' => [ 'Templates' ],
		],
	];

	// Required columns per sheet (header text, case-insensitive).
	const REQUIRED_COLUMNS = [
		'Colors'           => [ 'ID', 'Label', 'Value' ],
		'Numbers'          => [ 'ID', 'Label', 'Value' ],
		'Fonts'            => [ 'ID', 'Label' ],
		'Images'           => [ 'ID', 'Label', 'Value' ],
		'Text'             => [ 'ID', 'Label', 'Value' ],
		'Links'            => [ 'ID', 'Label', 'Value' ],
		'Presets – Modules' => [ 'ID', 'Label' ],
		'Presets – Groups'  => [ 'ID', 'Label' ],
	];

	// Valid status values.
	const VALID_STATUSES = [ 'active', 'archived', 'inactive' ];

	// CSS color pattern for validation.
	const COLOR_PATTERN = '/^(#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})|rgba?\s*\([\d\s,\.]+\))$/';

	/** @var string Path to the xlsx file being validated. */
	private string $file_path;

	/** @var array<array{level:string,sheet:string,row:int|null,col:string|null,message:string}> */
	private array $issues = [];

	/** @var array<string,mixed> Metadata extracted from Config sheet or inferred. */
	private array $meta = [];

	/** @var string|null Detected file type. */
	private string|null $file_type = null;

	/**
	 * Constructor.
	 *
	 * @param string $file_path Absolute path to the xlsx file.
	 */
	public function __construct( string $file_path ) {
		$this->file_path = $file_path;
	}

	/**
	 * Run all validation checks and return the report.
	 *
	 * @return array{
	 *   file_type: string|null,
	 *   meta: array,
	 *   issues: array,
	 *   counts: array{fatal:int, error:int, warning:int, info:int},
	 *   passed: bool,
	 * }
	 */
	public function validate(): array {
		try {
			$spreadsheet = IOFactory::load( $this->file_path );
		} catch ( \Throwable $e ) {
			$this->add( self::FATAL, '', null, null, 'File cannot be read: ' . $e->getMessage() );
			return $this->build_report();
		}

		$this->check_config_sheet( $spreadsheet );
		$this->detect_file_type( $spreadsheet );

		if ( $this->file_type === null ) {
			$this->add( self::FATAL, '', null, null, 'File type could not be determined. No Config sheet found and no recognised sheet names present.' );
			return $this->build_report();
		}

		$this->check_required_sheets( $spreadsheet );
		$this->check_sheet_columns( $spreadsheet );
		$this->check_data_rows( $spreadsheet );

		// Pro hook: allow additional validators to add issues.
		// d5dsh_validator_after_checks( $this, $spreadsheet )
		do_action( 'd5dsh_validator_after_checks', $this, $spreadsheet, $this->file_type );

		return $this->build_report();
	}

	// ── Config sheet ─────────────────────────────────────────────────────

	/**
	 * Check for Config sheet and extract metadata. Config is optional.
	 */
	private function check_config_sheet( Spreadsheet $ss ): void {
		$ws = $ss->getSheetByName( 'Config' );

		if ( $ws === null ) {
			$this->add( self::INFO, 'Config', null, null,
				'No Config sheet found. File type will be inferred from sheet names. ' .
				'This file was not exported by D5 Design System Helper.' );
			return;
		}

		// Read metadata rows (col A = key, col B = value).
		$data = [];
		foreach ( $ws->getRowIterator( 1, 20 ) as $row ) {
			$key = trim( (string) $ws->getCell( 'A' . $row->getRowIndex() )->getValue() );
			$val = trim( (string) $ws->getCell( 'B' . $row->getRowIndex() )->getValue() );
			if ( $key !== '' ) {
				$data[ strtolower( $key ) ] = $val;
			}
		}

		$this->meta = $data;

		// Extract file type from Config.
		$config_type = $data['file type'] ?? $data['file_type'] ?? null;
		if ( $config_type ) {
			$this->file_type = sanitize_key( $config_type );
			$this->add( self::INFO, 'Config', null, null,
				'Config sheet found. File type: ' . esc_html( $config_type ) . '.' );
		}

		// Plugin version check.
		$file_version   = $data['plugin version'] ?? $data['plugin_version'] ?? null;
		$current_version = defined( 'D5DSH_VERSION' ) ? D5DSH_VERSION : null;
		if ( $file_version && $current_version ) {
			if ( version_compare( $file_version, '0.6.0', '<' ) ) {
				$this->add( self::WARNING, 'Config', null, null,
					"File was exported with plugin v{$file_version}. Current version is v{$current_version}. " .
					'Some fields may be missing or formatted differently.' );
			} else {
				$this->add( self::INFO, 'Config', null, null,
					"Exported with plugin v{$file_version}." );
			}
		}

		// Cross-site warning.
		$export_url = $data['site url'] ?? $data['site_url'] ?? null;
		$current_url = get_site_url();
		if ( $export_url && $export_url !== $current_url ) {
			$this->add( self::WARNING, 'Config', null, null,
				"File was exported from a different site ({$export_url}). " .
				'Variable IDs are site-specific — existing IDs will be updated; unrecognised IDs will be skipped.' );
		}

		// SHA-256 tamper check.
		$stored_hash = $data['sha-256'] ?? $data['sha256'] ?? $data['hash'] ?? null;
		if ( $stored_hash ) {
			// We cannot recompute the hash from the spreadsheet object (it was computed
			// over raw DB data, not the file). Flag as INFO that hash is present.
			$this->add( self::INFO, 'Config', null, null,
				'SHA-256 integrity hash found. Tamper detection is available during import.' );
		}
	}

	// ── File type detection ───────────────────────────────────────────────

	/**
	 * Infer file type from sheet names if not already set from Config.
	 */
	private function detect_file_type( Spreadsheet $ss ): void {
		if ( $this->file_type !== null ) {
			return; // Already set from Config.
		}

		$sheet_names = array_map(
			fn( $ws ) => $ws->getTitle(),
			$ss->getAllSheets()
		);

		$best_match = null;
		$best_score = 0;

		foreach ( self::FILE_TYPE_SIGNATURES as $type => $sig ) {
			$score = count( array_intersect( $sig['sheets'], $sheet_names ) );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best_match = $type;
			}
		}

		if ( $best_match && $best_score > 0 ) {
			$this->file_type = $best_match;
			$label = self::FILE_TYPE_SIGNATURES[ $best_match ]['label'];
			$this->add( self::INFO, '', null, null,
				"File type inferred as \"{$label}\" based on sheet names." );
		}
	}

	// ── Required sheets ───────────────────────────────────────────────────

	/**
	 * Check that all required sheets for the detected file type are present.
	 */
	private function check_required_sheets( Spreadsheet $ss ): void {
		if ( $this->file_type === null ) {
			return;
		}

		$sig     = self::FILE_TYPE_SIGNATURES[ $this->file_type ] ?? null;
		if ( ! $sig ) {
			return;
		}

		$sheet_names = array_map( fn( $ws ) => $ws->getTitle(), $ss->getAllSheets() );

		foreach ( $sig['require'] as $required ) {
			if ( ! in_array( $required, $sheet_names, true ) ) {
				$this->add( self::FATAL, $required, null, null,
					"Required sheet \"{$required}\" is missing." );
			}
		}
	}

	// ── Column structure checks ─────────────────────────────────────────────

	/**
	 * Check that each sheet has the required column headers.
	 */
	private function check_sheet_columns( Spreadsheet $ss ): void {
		foreach ( self::REQUIRED_COLUMNS as $sheet_name => $required_cols ) {
			$ws = $ss->getSheetByName( $sheet_name );
			if ( $ws === null ) {
				continue; // Sheet missing — already flagged by check_required_sheets.
			}

			$headers = $this->read_headers( $ws );
			$headers_lower = array_map( 'strtolower', $headers );

			foreach ( $required_cols as $col ) {
				if ( ! in_array( strtolower( $col ), $headers_lower, true ) ) {
					$this->add( self::ERROR, $sheet_name, 1, null,
						"Required column \"{$col}\" not found in header row." );
				}
			}

			if ( empty( $headers ) ) {
				$this->add( self::FATAL, $sheet_name, 1, null,
					'Sheet appears to be empty — no header row found.' );
			}
		}
	}

	// ── Data row checks ───────────────────────────────────────────────────

	/**
	 * Check data rows in each relevant sheet.
	 */
	private function check_data_rows( Spreadsheet $ss ): void {
		$sheets_to_check = array_keys( self::REQUIRED_COLUMNS );

		foreach ( $sheets_to_check as $sheet_name ) {
			$ws = $ss->getSheetByName( $sheet_name );
			if ( $ws === null ) {
				continue;
			}

			$headers    = $this->read_headers( $ws );
			$col_map    = array_flip( array_map( 'strtolower', $headers ) );
			$id_col     = ( $col_map['id']     ?? null );
			$label_col  = ( $col_map['label']  ?? null );
			$value_col  = ( $col_map['value']  ?? null );
			$status_col = ( $col_map['status'] ?? null );

			$max_row    = $ws->getHighestDataRow();
			$seen_ids   = [];
			$data_count = 0;

			for ( $r = 2; $r <= $max_row; $r++ ) {
				$row_vals = [];
				$highest  = $ws->getHighestDataColumn();
				$col_count = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $highest );

				for ( $c = 1; $c <= $col_count; $c++ ) {
					$row_vals[ $c - 1 ] = trim( (string) $ws->getCell( Coordinate::stringFromColumnIndex( $c ) . $r )->getValue() );
				}

				// Skip entirely blank rows silently.
				if ( implode( '', $row_vals ) === '' ) {
					continue;
				}

				$data_count++;

				// ID checks.
				if ( $id_col !== null ) {
					$id = $row_vals[ $id_col ] ?? '';
					if ( $id === '' ) {
						$this->add( self::ERROR, $sheet_name, $r, 'ID',
							"Row {$r}: ID is blank. This row will be skipped on import." );
					} elseif ( isset( $seen_ids[ $id ] ) ) {
						$this->add( self::WARNING, $sheet_name, $r, 'ID',
							"Row {$r}: Duplicate ID \"{$id}\" — only the first occurrence will be used." );
					} else {
						$seen_ids[ $id ] = true;
					}
				}

				// Label checks.
				if ( $label_col !== null ) {
					$label = $row_vals[ $label_col ] ?? '';
					if ( $label === '' ) {
						$this->add( self::WARNING, $sheet_name, $r, 'Label',
							"Row {$r}: Label is blank." );
					}
				}

				// Value checks (sheet-specific).
				if ( $value_col !== null ) {
					$value = $row_vals[ $value_col ] ?? '';
					if ( $sheet_name === 'Colors' ) {
						$this->check_color_value( $sheet_name, $r, $value );
					}
				}

				// Status checks.
				if ( $status_col !== null ) {
					$status = strtolower( $row_vals[ $status_col ] ?? '' );
					if ( $status !== '' && ! in_array( $status, self::VALID_STATUSES, true ) ) {
						$this->add( self::WARNING, $sheet_name, $r, 'Status',
							"Row {$r}: Unknown status \"{$status}\". Expected: active, archived, or inactive." );
					}
				}
			}

			if ( $data_count === 0 ) {
				$this->add( self::INFO, $sheet_name, null, null,
					"Sheet \"{$sheet_name}\" has no data rows." );
			} else {
				$this->add( self::INFO, $sheet_name, null, null,
					"Sheet \"{$sheet_name}\": {$data_count} data row(s) found." );
			}
		}
	}

	// ── Color value validation ─────────────────────────────────────────────

	/**
	 * Validate a color value — hex, rgb(), rgba(), or $variable() reference.
	 */
	private function check_color_value( string $sheet, int $row, string $value ): void {
		if ( $value === '' ) {
			$this->add( self::WARNING, $sheet, $row, 'Value',
				"Row {$row}: Color value is blank." );
			return;
		}

		// $variable() references are valid (resolved at import time).
		if ( str_contains( $value, '$variable(' ) ) {
			return;
		}

		if ( ! preg_match( self::COLOR_PATTERN, $value ) ) {
			$this->add( self::ERROR, $sheet, $row, 'Value',
				"Row {$row}: \"{$value}\" does not appear to be a valid color (expected #hex, rgb(), or rgba())." );
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Read header row from a worksheet (row 1), returning 0-indexed array of strings.
	 *
	 * @return string[]
	 */
	private function read_headers( Worksheet $ws ): array {
		$headers  = [];
		$highest  = $ws->getHighestDataColumn();
		$col_count = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $highest );

		for ( $c = 1; $c <= $col_count; $c++ ) {
			$headers[] = trim( (string) $ws->getCell( Coordinate::stringFromColumnIndex( $c ) . 1 )->getValue() );
		}

		// Strip trailing empty headers.
		while ( ! empty( $headers ) && $headers[ count( $headers ) - 1 ] === '' ) {
			array_pop( $headers );
		}

		return $headers;
	}

	/**
	 * Add an issue to the report.
	 */
	public function add( string $level, string $sheet, ?int $row, ?string $col, string $message ): void {
		$this->issues[] = [
			'level'   => $level,
			'sheet'   => $sheet,
			'row'     => $row,
			'col'     => $col,
			'message' => $message,
		];
	}

	/**
	 * Build and return the final report array.
	 */
	private function build_report(): array {
		$counts = [ self::FATAL => 0, self::ERROR => 0, self::WARNING => 0, self::INFO => 0 ];
		foreach ( $this->issues as $issue ) {
			$counts[ $issue['level'] ] = ( $counts[ $issue['level'] ] ?? 0 ) + 1;
		}

		return [
			'file_type' => $this->file_type,
			'meta'      => $this->meta,
			'issues'    => $this->issues,
			'counts'    => $counts,
			'passed'    => $counts[ self::FATAL ] === 0 && $counts[ self::ERROR ] === 0,
		];
	}
}
