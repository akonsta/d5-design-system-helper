<?php
/**
 * Excel exporter for Divi 5 global variables.
 *
 * Produces an .xlsx workbook whose sheet structure exactly mirrors the output
 * of the Python tool's export_vars.py module so that the files are
 * interchangeable.
 *
 * ## Workbook structure
 *
 *  Sheet 1 – Info         : site metadata + SHA-256 hash (visible, front)
 *  Sheet 2 – Colors       : variables of type 'colors'
 *  Sheet 3 – Numbers      : variables of type 'numbers'
 *  Sheet 4 – Fonts        : variables of type 'fonts'
 *  Sheet 5 – Images       : variables of type 'images'
 *  Sheet 6 – Text         : variables of type 'strings'
 *  Sheet 7 – Links        : variables of type 'links'
 *
 * ## Column layout per type sheet
 *
 *  Colors    : Order | ID | Label | Value | Reference | Status | (Swatch fill) | System | Hidden
 *  Numbers   : Order | ID | Label | Value | Status | System
 *  Fonts     : Order | ID | Label | Font Family | Status | System
 *  Images    : Order | ID | Label | Value (URL or placeholder) | Status | System
 *  Text      : Order | ID | Label | Value | Status | System
 *  Links     : Order | ID | Label | Value | Status | System
 *
 * ## PhpSpreadsheet version note
 *
 *  This file targets PhpSpreadsheet ^2.x.
 *  The v1 methods setCellValueByColumnAndRow() / getCellByColumnAndRow() /
 *  getStyleByColumnAndRow() were removed in v2. All cell access goes through
 *  the private helper cell() which uses the v2 API:
 *    $ws->getCell( Coordinate::stringFromColumnIndex($col) . $row )
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Exporters;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Util\BlobUtil;
use D5DesignSystemHelper\Util\DiviBlocParser;
use D5DesignSystemHelper\Util\ExportUtil;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Class VarsExporter
 */
class VarsExporter {

	// ── Column index constants (1-based, matching Python output) ─────────────

	// Shared across all type sheets.
	private const COL_ORDER  = 1;
	private const COL_ID     = 2;
	private const COL_LABEL  = 3;

	// Colors sheet.  Order: Order | ID | Label | Swatch | Value | Reference | Status | System | Hidden
	private const COL_COLOR_SWATCH = 4;
	private const COL_COLOR_VALUE  = 5;
	private const COL_COLOR_REF    = 6;
	private const COL_COLOR_STATUS = 7;
	private const COL_COLOR_SYSTEM = 8;
	private const COL_COLOR_HIDDEN = 9;

	// Variables / Strings / Images sheet (shared layout).
	private const COL_GEN_VALUE  = 4;
	private const COL_GEN_STATUS = 5;
	private const COL_GEN_SYSTEM = 6;

	// Fonts sheet.
	private const COL_FONT_FAMILY = 4;
	private const COL_FONT_STATUS = 5;
	private const COL_FONT_SYSTEM = 6;

	/** ARGB for the header row background (dark navy, matching Python output). */
	private const HEADER_BG = 'FF1F2937';

	/** ARGB for the header row foreground. */
	private const HEADER_FG = 'FFFFFFFF';

	/** ARGB light gray fill for uneditable cells (system rows, protected columns). */
	private const SYSTEM_ROW_BG = 'FFE8E8E8';

	/** Placeholder substituted for base64 data URIs in Excel cells. */
	private const BLOB_PLACEHOLDER = 'Uneditable Data Not Shown';

	// ── Dependencies ─────────────────────────────────────────────────────────

	private VarsRepository $repo;

	/**
	 * Optional additional information provided via the export form.
	 * Keys: owner, customer, company, project, version_tag, status, environment, comments.
	 *
	 * @var array<string, string>
	 */
	private array $additional_info;

	/**
	 * @param array<string, string> $additional_info Optional project metadata from the export form.
	 */
	public function __construct( array $additional_info = [] ) {
		$this->repo            = new VarsRepository();
		$this->additional_info = $additional_info;
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Build the spreadsheet, send HTTP headers, and stream the file to the
	 * browser as a download. Terminates PHP execution on success.
	 *
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
	 * @return never
	 */
	public function stream_download(): never {
		$spreadsheet = $this->build_spreadsheet();

		$timestamp = ( new \DateTime( 'now', wp_timezone() ) )->format( 'Y-m-d_H.i' );
		$filename  = 'divi5-vars-' . $timestamp . '.xlsx';

		// Disable any output buffering so the file streams cleanly.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: max-age=0' );
		header( 'Pragma: public' );

		$writer = new Xlsx( $spreadsheet );
		$writer->save( 'php://output' );

		exit;
	}

	// ── Spreadsheet builder ───────────────────────────────────────────────────

	/**
	 * Build and return the complete Spreadsheet object.
	 *
	 * @return Spreadsheet
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	public function build_spreadsheet(): Spreadsheet {
		$vars = $this->repo->get_all();

		// Split variables by type — colors are now included from et_divi.
		$by_type = $this->group_by_type( $vars );

		$spreadsheet = new Spreadsheet();
		$spreadsheet->removeSheetByIndex( 0 ); // Remove the default empty sheet.

		// ── Info sheet first (site metadata + hash) ──────────────────────────
		$this->build_info_sheet( $spreadsheet );

		// ── Instructions / Disclaimer sheet second ────────────────────────────
		ExportUtil::write_instructions_sheet( $spreadsheet );

		// ── Type sheets ──────────────────────────────────────────────────────
		$this->build_colors_sheet( $spreadsheet, $by_type['colors']  ?? [] );
		$this->build_generic_sheet( $spreadsheet, 'Numbers', $by_type['numbers'] ?? [], 'Value' );
		// Left-align the Value column (D) on the Numbers sheet — numbers stored as
		// text strings (e.g. "16px", "1.5rem") should align left like the other columns.
		$numbers_ws = $spreadsheet->getSheetByName( 'Numbers' );
		if ( $numbers_ws !== null ) {
			$numbers_ws->getStyle( 'D4:D' . max( 4, count( $by_type['numbers'] ?? [] ) + 3 ) )
				->getAlignment()->setHorizontal( \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT );
			$numbers_ws->setSelectedCells( 'A1' );
		}
		$this->build_fonts_sheet(   $spreadsheet, $by_type['fonts']   ?? [] );
		$this->build_images_sheet(  $spreadsheet, $by_type['images']  ?? [] );
		$this->build_generic_sheet( $spreadsheet, 'Text',    $by_type['strings'] ?? [], 'Value' );
		$this->build_generic_sheet( $spreadsheet, 'Links',   $by_type['links']   ?? [], 'Value' );

		// Activate the Info sheet (index 0).
		$spreadsheet->setActiveSheetIndex( 0 );

		return $spreadsheet;
	}

	/**
	 * Build a Spreadsheet from a pre-normalized flat list of variable records.
	 *
	 * Accepts the same format that VarsRepository::get_all() returns — each
	 * record is an associative array with keys: id, label, value, type, order,
	 * status, system, hidden (colors only).
	 *
	 * Used by the JSON→XLSX conversion path so the output is identical in
	 * structure to a regular export and can be round-tripped through VarsImporter.
	 *
	 * @param array $vars Flat normalized variable records.
	 * @return Spreadsheet
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	public function build_spreadsheet_from_normalized( array $vars ): Spreadsheet {
		$by_type = $this->group_by_type( $vars );

		$spreadsheet = new Spreadsheet();
		$spreadsheet->removeSheetByIndex( 0 );

		$this->build_info_sheet( $spreadsheet );
		ExportUtil::write_instructions_sheet( $spreadsheet );

		$this->build_colors_sheet( $spreadsheet, $by_type['colors']  ?? [] );
		$this->build_generic_sheet( $spreadsheet, 'Numbers', $by_type['numbers'] ?? [], 'Value' );
		$numbers_ws = $spreadsheet->getSheetByName( 'Numbers' );
		if ( $numbers_ws !== null ) {
			$numbers_ws->getStyle( 'D4:D' . max( 4, count( $by_type['numbers'] ?? [] ) + 3 ) )
				->getAlignment()->setHorizontal( \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT );
			$numbers_ws->setSelectedCells( 'A1' );
		}
		$this->build_fonts_sheet(   $spreadsheet, $by_type['fonts']   ?? [] );
		$this->build_images_sheet(  $spreadsheet, $by_type['images']  ?? [] );
		$this->build_generic_sheet( $spreadsheet, 'Text',  $by_type['strings'] ?? [], 'Value' );
		$this->build_generic_sheet( $spreadsheet, 'Links', $by_type['links']   ?? [], 'Value' );

		$spreadsheet->setActiveSheetIndex( 0 );
		return $spreadsheet;
	}

	// ── Colors sheet ─────────────────────────────────────────────────────────

	/**
	 * Build the Colors sheet.
	 *
	 * Columns: Order | ID | Label | Swatch | Value | Reference | Status | System | Hidden
	 *
	 * Color variables may store a hex value directly OR a reference to another
	 * variable using the syntax:
	 *   $variable({"type":"color","value":{"name":"gcid-xxx","settings":{...}}})$
	 *
	 * When a reference is detected, the Value column shows the raw reference
	 * string and the Reference column shows the referenced variable name.
	 *
	 * Rows are ordered: system colors first, then visible user colors (by order),
	 * then hidden palette colors (by order) — already sorted by VarsRepository::normalize().
	 *
	 * @param Spreadsheet $spreadsheet
	 * @param array       $entries     Normalised variable records of type 'colors'.
	 * @return void
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function build_colors_sheet( Spreadsheet $spreadsheet, array $entries ): void {
		$ws = $spreadsheet->createSheet();
		$ws->setTitle( 'Colors' );

		$headers = [ 'Order', 'ID', 'Label', 'Swatch', 'Value', 'Reference', 'Status', 'System', 'Hidden' ];
		$this->write_sheet_title_row( $ws, count( $headers ), 'Colors' );
		$this->write_header_row( $ws, $headers, 3 );

		// Pre-pass: build a lookup of gcid → resolved direct colour value for
		// full-chain reference resolution. We iterate until no more references
		// remain (handles A→B→C→hex chains of arbitrary depth).
		$direct = []; // gcid => hex/rgba value (no $variable() reference)
		foreach ( $entries as $e ) {
			if ( ! str_contains( $e['value'] ?? '', '$variable(' ) ) {
				$direct[ $e['id'] ] = $e['value'] ?? '';
			}
		}
		// Resolve indirect entries up to 10 hops (practical limit; Divi chains are shallow).
		$resolved = $direct;
		for ( $pass = 0; $pass < 10; $pass++ ) {
			$changed = false;
			foreach ( $entries as $e ) {
				if ( isset( $resolved[ $e['id'] ] ) ) { continue; } // already direct
				$ref = DiviBlocParser::extract_variable_ref_name( $e['value'] ?? '' );
				if ( $ref && isset( $resolved[ $ref ] ) ) {
					$resolved[ $e['id'] ] = $resolved[ $ref ];
					$changed = true;
				}
			}
			if ( ! $changed ) { break; } // nothing left to resolve
		}

		// First pass: write all cell values; collect swatch ARGBs and system rows.
		$row         = 4; // Rows 1=title, 2=blank, 3=headers, data starts at 4.
		$swatches    = []; // row => ARGB string
		$system_rows = []; // list of row numbers that are system entries

		foreach ( $entries as $entry ) {
			$value     = $entry['value'] ?? '';
			$ref_name  = '';
			$is_system = ! empty( $entry['system'] );
			$is_hidden = ! empty( $entry['hidden'] );

			// Detect $variable(...) color reference.
			// The Reference column stores the full $variable()$ expression so that
			// it can be round-tripped on import. The extracted ID is only used
			// internally for swatch resolution and chain following.
			$ref_expr = '';
			if ( str_contains( $value, '$variable(' ) ) {
				$ref_name = DiviBlocParser::extract_variable_ref_name( $value );
				$ref_expr = $value; // preserve the complete $variable(...)$ string
			}

			$this->cell( $ws, self::COL_ORDER,        $row )->setValue( $entry['order'] );
			$this->cell( $ws, self::COL_ID,           $row )->setValue( $entry['id'] );
			$this->cell( $ws, self::COL_LABEL,        $row )->setValue( $entry['label'] );
			$this->cell( $ws, self::COL_COLOR_VALUE,  $row )->setValue( $value );
			$this->cell( $ws, self::COL_COLOR_REF,    $row )->setValue( $ref_expr );
			$this->cell( $ws, self::COL_COLOR_STATUS, $row )->setValue( $entry['status'] );
			$this->cell( $ws, self::COL_COLOR_SYSTEM, $row )->setValue( $is_system ? 'TRUE' : 'FALSE' );
			$this->cell( $ws, self::COL_COLOR_HIDDEN, $row )->setValue( $is_hidden ? 'TRUE' : 'FALSE' );

			// Collect swatch ARGB using the fully-resolved colour value.
			// For direct colours use the stored value; for references follow the
			// chain resolved above so every row gets a swatch if possible.
			$swatch_source = $resolved[ $entry['id'] ] ?? null;
			if ( $swatch_source !== null ) {
				$argb = $this->value_to_argb( $swatch_source );
				if ( $argb !== null ) {
					$swatches[ $row ] = $argb;
				}
			}

			if ( $is_system ) {
				$system_rows[] = $row;
			}

			$row++;
		}

		// Second pass: apply swatch fills BEFORE the alternating-row fill so that
		// the colours set here are never overwritten by the row-banding pass.
		foreach ( $swatches as $r => $argb ) {
			try {
				$coord = Coordinate::stringFromColumnIndex( self::COL_COLOR_SWATCH ) . $r;
				$ws->getStyle( $coord )
				   ->getFill()
				   ->setFillType( Fill::FILL_SOLID )
				   ->getStartColor()
				   ->setARGB( $argb );
			} catch ( \Throwable ) {
				// Invalid colour — leave cell unfilled.
			}
		}

		// Third pass: yellow highlight on ID and Label for system rows.
		foreach ( $system_rows as $r ) {
			$this->apply_system_row_highlight( $ws, $r, [ self::COL_ID, self::COL_LABEL ] );
		}

		// Fourth pass: alternating-row fill on every column EXCEPT the Swatch
		// column (G). Applying the banding after the swatch fills, and skipping
		// column G, guarantees no row-banding color ever lands on the swatch cell.
		$this->apply_colors_sheet_formatting( $ws, $row - 1 );

		$ws->setAutoFilter( 'A3:I' . max( 3, $row - 1 ) );
		$this->set_column_widths( $ws, [ 8, 22, 30, 22, 22, 12, 8, 8, 8 ] );
		$this->left_align_all( $ws, max( 3, $row - 1 ), 9 );
		// Enable sheet protection so cell locks are enforced by Excel.
		$ws->getProtection()->setSheet( true )->setPassword( 'password' )->setSort( false )->setAutoFilter( false );
		// Unlock all cells first, then re-lock specific columns.
		$all_range = 'A4:I' . max( 4, $row );
		$ws->getStyle( $all_range )->getProtection()
			->setLocked( \PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED );
		// Protect non-editable columns: Order, ID, Swatch, Reference, Status, System, Hidden.
		$this->protect_columns( $ws, [ 1, 2, 4, 6, 7, 8, 9 ], max( 4, $row ), 4 );

		// Additionally lock Label (col 3) for system rows — system color labels cannot be changed.
		foreach ( $system_rows as $r ) {
			$coord = Coordinate::stringFromColumnIndex( self::COL_LABEL ) . $r;
			$ws->getStyle( $coord )->getProtection()
				->setLocked( \PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_PROTECTED );
			$ws->getStyle( $coord )->getFill()
				->setFillType( Fill::FILL_SOLID )
				->getStartColor()
				->setARGB( self::SYSTEM_ROW_BG );
		}

		$ws->setSelectedCells( 'A1' );
	}

	// ── Generic sheet (Numbers, Images, Strings) ─────────────────────────────

	/**
	 * Build a generic 6-column sheet: Order | ID | Label | Value | Status | System.
	 *
	 * Used for types: numbers, images, text (strings), links.
	 *
	 * Image values that are base64 data URIs are replaced with the blob placeholder.
	 *
	 * @param Spreadsheet $spreadsheet
	 * @param string      $title       Sheet tab title.
	 * @param array       $entries     Normalised variable records.
	 * @param string      $value_label Column header for the value column.
	 * @return void
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function build_generic_sheet(
		Spreadsheet $spreadsheet,
		string $title,
		array $entries,
		string $value_label
	): void {
		$ws = $spreadsheet->createSheet();
		$ws->setTitle( $title );

		$headers = [ 'Order', 'ID', 'Label', $value_label, 'Status', 'System' ];
		$this->write_sheet_title_row( $ws, count( $headers ), $title );
		$this->write_header_row( $ws, $headers, 3 );

		// First pass: write all cell values; collect system rows.
		$row         = 4;
		$system_rows = [];

		foreach ( $entries as $entry ) {
			$value     = $entry['value'] ?? '';
			$is_system = ! empty( $entry['system'] );

			// Replace base64 blobs with placeholder.
			if ( BlobUtil::is_blob( $value ) ) {
				$value = self::BLOB_PLACEHOLDER;
			}

			$this->cell( $ws, self::COL_ORDER,      $row )->setValue( $entry['order'] );
			$this->cell( $ws, self::COL_ID,         $row )->setValue( $entry['id'] );
			$this->cell( $ws, self::COL_LABEL,      $row )->setValue( $entry['label'] );
			$this->cell( $ws, self::COL_GEN_VALUE,  $row )->setValue( $value );
			$this->cell( $ws, self::COL_GEN_STATUS, $row )->setValue( $entry['status'] );
			$this->cell( $ws, self::COL_GEN_SYSTEM, $row )->setValue( $is_system ? 'TRUE' : 'FALSE' );

			if ( $is_system ) {
				$system_rows[] = $row;
			}

			$row++;
		}

		// Apply alternating-row fill FIRST so highlights are not erased.
		$this->apply_sheet_formatting( $ws, $row - 1, count( $headers ), 4 );

		// Protect non-editable columns: Order, ID, Status, System (Label=3 and Value=4 are editable).
		if ( $row > 4 ) {
			$ws->getProtection()->setSheet( true )->setPassword( 'password' )->setSort( false )->setAutoFilter( false );
			$all_range = 'A4:F' . ( $row - 1 );
			$ws->getStyle( $all_range )->getProtection()
				->setLocked( \PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED );
			$this->protect_columns( $ws, [ 1, 2, 5, 6 ], $row - 1, 4 );
		}

		// Gray highlight on ID for system rows.
		foreach ( $system_rows as $r ) {
			$this->apply_system_row_highlight( $ws, $r, [ self::COL_ID ] );
		}

		$ws->setAutoFilter( 'A3:F' . max( 3, $row - 1 ) );
		$this->set_column_widths( $ws, [ 8, 22, 30, 50, 12, 8 ] );
		$this->left_align_all( $ws, max( 3, $row - 1 ), 6 );
		$ws->setSelectedCells( 'A1' );
	}

	// ── Images sheet ─────────────────────────────────────────────────────────

	/**
	 * Build the Images sheet.
	 *
	 * Columns: Order | ID | Label | Value | Status | System | Size
	 *
	 * The Size column shows the file size in KB for URL-based images.
	 * For local WordPress media, size is read from the filesystem.
	 * For remote URLs, a HEAD request fetches Content-Length.
	 * For base64 blobs, size is computed from the encoded data length.
	 * Shows '—' when size cannot be determined.
	 *
	 * @param Spreadsheet $spreadsheet
	 * @param array       $entries     Normalised variable records of type 'images'.
	 * @return void
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function build_images_sheet( Spreadsheet $spreadsheet, array $entries ): void {
		$ws = $spreadsheet->createSheet();
		$ws->setTitle( 'Images' );

		// Columns: Order | ID | Label | Title | Image Source | Dimensions | Size (KB) | Status | System
		// Label (col 3) is the only editable column.
		$headers = [ 'Order', 'ID', 'Label', 'Title', 'Image Source', 'Dimensions', 'Size (KB)', 'Status', 'System' ];
		$this->write_sheet_title_row( $ws, count( $headers ), 'Images' );
		$this->write_header_row( $ws, $headers, 3 );

		$row         = 4;
		$system_rows = [];

		foreach ( $entries as $entry ) {
			$raw_value = $entry['value'] ?? '';
			$is_system = ! empty( $entry['system'] );
			$size_kb   = $this->image_size_kb( $raw_value );

			if ( BlobUtil::is_blob( $raw_value ) ) {
				$title      = '[embedded image]';
				$source     = self::BLOB_PLACEHOLDER;
				$dimensions = '—';
			} else {
				// Title: WP media library post_title if available; otherwise derive from URL.
				$title = $this->image_title( $raw_value );
				// Image Source: the URL itself (or a short filename for very long URLs).
				$source = $raw_value;
				// Dimensions: from WP attachment metadata (local only; remote → "remote image").
				$dimensions = $this->image_dimensions( $raw_value );
			}

			$this->cell( $ws, 1, $row )->setValue( $entry['order'] );
			$this->cell( $ws, 2, $row )->setValue( $entry['id'] );
			$this->cell( $ws, 3, $row )->setValue( $entry['label'] );
			$this->cell( $ws, 4, $row )->setValue( $title );
			$this->cell( $ws, 5, $row )->setValue( $source );
			$this->cell( $ws, 6, $row )->setValue( $dimensions );
			$this->cell( $ws, 7, $row )->setValue( $size_kb );
			$this->cell( $ws, 8, $row )->setValue( $entry['status'] );
			$this->cell( $ws, 9, $row )->setValue( $is_system ? 'TRUE' : 'FALSE' );

			if ( $is_system ) {
				$system_rows[] = $row;
			}
			$row++;
		}

		$this->apply_sheet_formatting( $ws, $row - 1, count( $headers ), 4 );

		// Protect all columns except Label (col 3).
		if ( $row > 4 ) {
			$ws->getProtection()->setSheet( true )->setPassword( 'password' )->setSort( false )->setAutoFilter( false );
			$all_range = 'A4:I' . ( $row - 1 );
			$ws->getStyle( $all_range )->getProtection()
				->setLocked( \PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED );
			$this->protect_columns( $ws, [ 1, 2, 4, 5, 6, 7, 8, 9 ], $row - 1, 4 );
		}

		// Gray highlight on ID for system rows.
		foreach ( $system_rows as $r ) {
			$this->apply_system_row_highlight( $ws, $r, [ 2 ] );
		}

		$ws->setAutoFilter( 'A3:I' . max( 3, $row - 1 ) );
		$this->set_column_widths( $ws, [ 8, 22, 30, 30, 50, 14, 10, 12, 8 ] );
		$this->left_align_all( $ws, max( 3, $row - 1 ), count( $headers ) );
		$ws->setSelectedCells( 'A1' );
	}

	/**
	 * Get the WordPress media library title for an image URL.
	 * Returns the post_title if the URL maps to a WP attachment, otherwise
	 * derives a readable name from the URL filename.
	 *
	 * @param string $value Raw image URL.
	 * @return string
	 */
	private function image_title( string $value ): string {
		$value = trim( $value );
		if ( empty( $value ) ) { return ''; }

		// Try WP attachment lookup.
		if ( str_starts_with( $value, get_site_url() ) || str_starts_with( $value, '/' ) ) {
			$post_id = attachment_url_to_postid( $value );
			if ( $post_id ) {
				$post = get_post( $post_id );
				if ( $post && ! empty( $post->post_title ) ) {
					return $post->post_title;
				}
			}
		}

		// Fallback: derive from filename.
		$parts    = explode( '/', rtrim( $value, '/' ) );
		$filename = end( $parts ) ?: $value;
		// Strip extension and replace dashes/underscores with spaces.
		$name = preg_replace( '/\.[a-z0-9]+$/i', '', $filename );
		$name = str_replace( [ '-', '_' ], ' ', $name );
		return $name;
	}

	/**
	 * Get image dimensions for a local WP attachment URL.
	 * Returns "WxH" for local attachments with metadata, "remote image" for
	 * remote URLs, and "—" for blobs or unresolvable values.
	 *
	 * @param string $value Raw image URL.
	 * @return string
	 */
	private function image_dimensions( string $value ): string {
		$value = trim( $value );
		if ( empty( $value ) || BlobUtil::is_blob( $value ) ) { return '—'; }

		if ( str_starts_with( $value, get_site_url() ) || str_starts_with( $value, '/' ) ) {
			$post_id = attachment_url_to_postid( $value );
			if ( $post_id ) {
				$meta = wp_get_attachment_metadata( $post_id );
				if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
					return $meta['width'] . '×' . $meta['height'];
				}
			}
			return '—';
		}

		// Remote URL — do not download; just note it's remote.
		if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return 'remote image';
		}

		return '—';
	}

	/**
	 * Return the file size in KB for a given image value.
	 *
	 * Resolution order:
	 *   1. Base64 data URI — compute from encoded string length.
	 *   2. Local WP attachment URL — use filesystem via attachment_url_to_postid() + get_attached_file().
	 *   3. Remote URL — HEAD request, read Content-Length header.
	 *   4. Anything else — return '—'.
	 *
	 * @param string $value Raw image value (URL, base64 data URI, or placeholder).
	 * @return string Formatted size string (e.g. '42.3 KB') or '—'.
	 */
	private function image_size_kb( string $value ): string {
		$value = trim( $value );

		if ( empty( $value ) || $value === self::BLOB_PLACEHOLDER ) {
			return '—';
		}

		// ── Base64 data URI ───────────────────────────────────────────────────
		if ( BlobUtil::is_blob( $value ) ) {
			// data:image/png;base64,XXXX — actual bytes ≈ base64_len * 3/4
			$comma = strpos( $value, ',' );
			if ( $comma !== false ) {
				$base64_data = substr( $value, $comma + 1 );
				$bytes       = (int) ( strlen( $base64_data ) * 3 / 4 );
				return number_format( $bytes / 1024, 1 ) . ' KB';
			}
			return '—';
		}

		// ── Local WP attachment ───────────────────────────────────────────────
		if ( str_starts_with( $value, get_site_url() ) || str_starts_with( $value, '/' ) ) {
			$post_id = attachment_url_to_postid( $value );
			if ( $post_id ) {
				$file = get_attached_file( $post_id );
				if ( $file && file_exists( $file ) ) {
					return number_format( filesize( $file ) / 1024, 1 ) . ' KB';
				}
			}
		}

		// ── Remote URL (HEAD request) ─────────────────────────────────────────
		if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
			$response = wp_remote_head( $value, [ 'timeout' => 5, 'redirection' => 3 ] );
			if ( ! is_wp_error( $response ) ) {
				$length = wp_remote_retrieve_header( $response, 'content-length' );
				if ( $length && is_numeric( $length ) ) {
					return number_format( (int) $length / 1024, 1 ) . ' KB';
				}
			}
		}

		return '—';
	}

	// ── Fonts sheet ──────────────────────────────────────────────────────────

	/**
	 * Build the Fonts sheet.
	 *
	 * Columns: Order | ID | Label | Font Family | Status
	 *
	 * The value field for font variables holds the font family name.
	 *
	 * @param Spreadsheet $spreadsheet
	 * @param array       $entries     Normalised variable records of type 'fonts'.
	 * @return void
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function build_fonts_sheet( Spreadsheet $spreadsheet, array $entries ): void {
		$ws = $spreadsheet->createSheet();
		$ws->setTitle( 'Fonts' );

		$headers = [ 'Order', 'ID', 'Label', 'Font Family', 'Status', 'System' ];
		$this->write_sheet_title_row( $ws, count( $headers ), 'Fonts' );
		$this->write_header_row( $ws, $headers, 3 );

		// First pass: write all cell values; collect system rows.
		$row         = 4;
		$system_rows = [];

		foreach ( $entries as $entry ) {
			$is_system = ! empty( $entry['system'] );

			$this->cell( $ws, self::COL_ORDER,       $row )->setValue( $entry['order'] );
			$this->cell( $ws, self::COL_ID,          $row )->setValue( $entry['id'] );
			$this->cell( $ws, self::COL_LABEL,       $row )->setValue( $entry['label'] );
			$this->cell( $ws, self::COL_FONT_FAMILY, $row )->setValue( $entry['value'] );
			$this->cell( $ws, self::COL_FONT_STATUS, $row )->setValue( $entry['status'] );
			$this->cell( $ws, self::COL_FONT_SYSTEM, $row )->setValue( $is_system ? 'TRUE' : 'FALSE' );

			if ( $is_system ) {
				$system_rows[] = $row;
			}

			$row++;
		}

		// Apply alternating-row fill FIRST so highlights are not erased.
		$this->apply_sheet_formatting( $ws, $row - 1, count( $headers ), 4 );

		// Second pass: yellow highlight on ID and Label for system rows.
		foreach ( $system_rows as $r ) {
			$this->apply_system_row_highlight( $ws, $r, [ self::COL_ID, self::COL_LABEL ] );
		}

		// Protect non-editable columns: Order, ID, Status, System (Label=3 and Font Family=4 are editable).
		if ( $row > 4 ) {
			$ws->getProtection()->setSheet( true )->setPassword( 'password' )->setSort( false )->setAutoFilter( false );
			$all_range = 'A4:F' . ( $row - 1 );
			$ws->getStyle( $all_range )->getProtection()
				->setLocked( \PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED );
			$this->protect_columns( $ws, [ 1, 2, 5, 6 ], $row - 1, 4 );
		}

		$ws->setAutoFilter( 'A3:F' . max( 3, $row - 1 ) );
		$this->set_column_widths( $ws, [ 8, 22, 30, 30, 12, 8 ] );
		$this->left_align_all( $ws, max( 3, $row - 1 ), 6 );
		$ws->setSelectedCells( 'A1' );
	}

	// ── Info sheet (visible, front) ──────────────────────────────────────────

	/**
	 * Labels for the additional information fields, in display order.
	 * Keys match $this->additional_info array keys.
	 */
	private const ADDITIONAL_INFO_LABELS = [
		'owner'       => 'Owner / Web Designer Name',
		'customer'    => 'Customer Name',
		'company'     => 'Customer Company',
		'project'     => 'Project Name',
		'version_tag' => 'Project / Version Tag',
		'status'      => 'Project Status',
		'environment' => 'Environment',
		'comments'    => 'Comments',
	];

	/**
	 * Build the combined Info sheet — visible, positioned first in the workbook.
	 *
	 * Layout depends on whether additional information was provided:
	 *
	 *   WITH additional info:
	 *     Row 1  : Title
	 *     Row 3  : "Additional Information" sub-heading
	 *     Rows 4–11 : 8 additional info field labels + values (all always written)
	 *     Row 13 : "Export Details" sub-heading (separator border above)
	 *     Rows 14–20 : site/export metadata
	 *     Row 22 : "Technical" sub-heading (separator border above)
	 *     Rows 23–26 : file type, option keys, hash
	 *
	 *   WITHOUT additional info:
	 *     Row 1  : Title
	 *     Row 3  : "Export Details" sub-heading
	 *     Rows 4–10 : site/export metadata
	 *     Row 12 : "Technical" sub-heading (separator border above)
	 *     Rows 13–16 : file type, option keys, hash
	 *     Row 18 : "Additional Information" sub-heading (separator border above)
	 *     Rows 19–26 : 8 additional info field labels (values empty)
	 *
	 * @param Spreadsheet $spreadsheet
	 * @return void
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function build_info_sheet( Spreadsheet $spreadsheet ): void {
		$ws = $spreadsheet->createSheet();
		$ws->setTitle( 'Info' );

		// ── Shared data ───────────────────────────────────────────────────────
		global $wp_version;
		$tz         = wp_timezone();
		$export_dt  = ( new \DateTime( 'now', $tz ) )->format( 'Y-m-d H:i T' );
		$raw_vars        = $this->repo->get_raw();
		$raw_colors      = $this->repo->get_raw_colors();
		$raw_sys_fonts   = $this->repo->get_raw_system_fonts();
		$raw_sys_colors  = $this->repo->get_raw_system_colors();
		$hash            = hash( 'sha256', maybe_serialize( $raw_vars ) . maybe_serialize( $raw_colors ) . maybe_serialize( $raw_sys_fonts ) . maybe_serialize( $raw_sys_colors ) );
		$has_additional  = ! empty( array_filter( $this->additional_info ) );
		$anomaly_text    =
			'Anomalies are conditions detected at export time that may indicate data inconsistencies ' .
			'between your Divi installation and the plugin. They do not prevent export or import, but ' .
			'should be reviewed. Unknown color keys appear when a global color ID is present in the ' .
			'database but does not match any known Divi system color key — this can occur after a Divi ' .
			'update adds new system colors or if data was migrated from another site.';

		// ── Helpers ───────────────────────────────────────────────────────────
		// Write a section heading: A col label, indented in B (merged A+B used
		// as visual header), bold+italic, +2pt font, top border.
		$base_font_size = 11;
		$section_font_size = $base_font_size + 2;

		$write_section_heading = function ( int $row, string $label, bool $top_border = true ) use ( $ws, $section_font_size ): void {
			if ( $top_border ) {
				$ws->getStyle( 'A' . $row . ':B' . $row )->getBorders()->getTop()->setBorderStyle( 'thin' );
			}
			// Put heading in col A with indent 5 for visual inset.
			$ws->setCellValue( 'A' . $row, $label );
			$ws->getStyle( 'A' . $row )->getFont()
				->setBold( true )
				->setItalic( true )
				->setSize( $section_font_size );
			$ws->getStyle( 'A' . $row )->getAlignment()
				->setHorizontal( \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT )
				->setIndent( 5 );
		};

		// Write an anomalies section: heading row, then merged explanation row below it.
		$write_anomaly_section = function ( int $r, array $unknown_colors ) use ( $ws, $write_section_heading, $anomaly_text ): int {
			$unknown_str = implode( ', ', array_map(
				fn( $k, $v ) => $k . '=' . $v,
				array_keys( $unknown_colors ), array_values( $unknown_colors )
			) );
			// Heading row.
			$write_section_heading( $r, 'Anomalies / Errors' );
			$r++;
			// Merged explanation row beneath heading (A+B merged, italic grey text).
			$ws->mergeCells( 'A' . $r . ':B' . $r );
			$ws->setCellValue( 'A' . $r, $anomaly_text );
			$ws->getStyle( 'A' . $r )->getFont()->setItalic( true )->setColor(
				( new \PhpOffice\PhpSpreadsheet\Style\Color( 'FF4B5563' ) )
			);
			$ws->getStyle( 'A' . $r )->getAlignment()
				->setWrapText( true )
				->setVertical( \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER );
			$ws->getRowDimension( $r )->setRowHeight( 75 );
			$r++;
			// Detail row.
			$ws->setCellValue( 'A' . $r, 'Unknown et_divi Color Keys' );
			$ws->setCellValue( 'B' . $r, $unknown_str );
			$ws->getStyle( 'A' . $r )->getFont()->setColor(
				( new \PhpOffice\PhpSpreadsheet\Style\Color( 'FFB32D2E' ) )
			);
			$ws->getStyle( 'B' . $r )->getAlignment()
				->setWrapText( true )
				->setVertical( \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER );
			$ws->getRowDimension( $r )->setRowHeight( -1 );
			$r++;
			return $r;
		};

		// ── Title row (always row 1) ──────────────────────────────────────────
		$ws->mergeCells( 'A1:B1' );
		$ws->setCellValue( 'A1', 'D5 Design System Helper — Export Info' );
		$ws->getStyle( 'A1' )->getFont()->setBold( true )->setSize( 13 );

		// Track row ranges for bold-label styling and protection.
		$protect_start = null; // first row of Export Details (everything below this gets protected)

		if ( $has_additional ) {
			// ── Additional Information FIRST (not protected — user-editable) ──
			$write_section_heading( 3, 'Additional Information', false );
			$info_range = 'A4:A11';
			$r = 4;
			foreach ( self::ADDITIONAL_INFO_LABELS as $key => $label ) {
				$ws->setCellValue( 'A' . $r, $label );
				$ws->setCellValue( 'B' . $r, $this->additional_info[ $key ] ?? '' );
				$r++;
			}

			// ── Export Details ────────────────────────────────────────────────
			$meta_start    = $r + 1;
			$protect_start = $meta_start;
			$write_section_heading( $meta_start, 'Export Details' );
			$r = $meta_start + 1;
			$ws->setCellValue( 'A' . $r, 'Source Site' );        $ws->setCellValue( 'B' . $r, get_bloginfo( 'url' ) );  $r++;
			$ws->setCellValue( 'A' . $r, 'Site Name' );          $ws->setCellValue( 'B' . $r, ExportUtil::site_name() ); $r++;
			$ws->setCellValue( 'A' . $r, 'Export Date' );        $ws->setCellValue( 'B' . $r, $export_dt );             $r++;
			$ws->setCellValue( 'A' . $r, 'Exported By' );        $ws->setCellValue( 'B' . $r, wp_get_current_user()->display_name ); $r++;
			$ws->setCellValue( 'A' . $r, 'Divi Version' );       $ws->setCellValue( 'B' . $r, defined( 'ET_CORE_VERSION' ) ? ET_CORE_VERSION : 'Unknown' ); $r++;
			$ws->setCellValue( 'A' . $r, 'WordPress Version' );  $ws->setCellValue( 'B' . $r, $wp_version );            $r++;
			$ws->setCellValue( 'A' . $r, 'Plugin Version' );     $ws->setCellValue( 'B' . $r, D5DSH_VERSION );          $r++;
			$meta_range = 'A' . ( $meta_start + 1 ) . ':A' . ( $meta_start + 7 );

			// ── Technical ─────────────────────────────────────────────────────
			$tech_start = $r + 1;
			$write_section_heading( $tech_start, 'Technical' );
			$r = $tech_start + 1;
			$ws->setCellValue( 'A' . $r, 'File Type' );          $ws->setCellValue( 'B' . $r, 'vars' );                              $r++;
			$ws->setCellValue( 'A' . $r, 'Option Key' );         $ws->setCellValue( 'B' . $r, VarsRepository::OPTION_KEY );          $r++;
			$ws->setCellValue( 'A' . $r, 'Colors Option Key' );  $ws->setCellValue( 'B' . $r, VarsRepository::COLORS_OPTION_KEY );   $r++;
			$ws->setCellValue( 'A' . $r, 'SHA-256 Hash' );       $ws->setCellValue( 'B' . $r, $hash );                               $r++;
			$tech_range = 'A' . ( $tech_start + 1 ) . ':A' . ( $tech_start + 4 );

			// ── Anomalies (conditional) ───────────────────────────────────────
			$unknown_colors = $this->repo->detect_unknown_system_colors();
			if ( ! empty( $unknown_colors ) ) {
				$r = $write_anomaly_section( $r, $unknown_colors );
			}

			$last_protected_row = $r - 1;

		} else {
			// ── Export Details FIRST ──────────────────────────────────────────
			$protect_start = 3;
			$write_section_heading( 3, 'Export Details', false );
			$ws->setCellValue( 'A4', 'Source Site' );        $ws->setCellValue( 'B4',  get_bloginfo( 'url' ) );
			$ws->setCellValue( 'A5', 'Site Name' );          $ws->setCellValue( 'B5',  ExportUtil::site_name() );
			$ws->setCellValue( 'A6', 'Export Date' );        $ws->setCellValue( 'B6',  $export_dt );
			$ws->setCellValue( 'A7', 'Exported By' );        $ws->setCellValue( 'B7',  wp_get_current_user()->display_name );
			$ws->setCellValue( 'A8', 'Divi Version' );       $ws->setCellValue( 'B8',  defined( 'ET_CORE_VERSION' ) ? ET_CORE_VERSION : 'Unknown' );
			$ws->setCellValue( 'A9', 'WordPress Version' );  $ws->setCellValue( 'B9',  $wp_version );
			$ws->setCellValue( 'A10', 'Plugin Version' );    $ws->setCellValue( 'B10', D5DSH_VERSION );
			$meta_range = 'A4:A10';

			// ── Technical ─────────────────────────────────────────────────────
			$write_section_heading( 12, 'Technical' );
			$ws->setCellValue( 'A13', 'File Type' );         $ws->setCellValue( 'B13', 'vars' );
			$ws->setCellValue( 'A14', 'Option Key' );        $ws->setCellValue( 'B14', VarsRepository::OPTION_KEY );
			$ws->setCellValue( 'A15', 'Colors Option Key' ); $ws->setCellValue( 'B15', VarsRepository::COLORS_OPTION_KEY );
			$ws->setCellValue( 'A16', 'SHA-256 Hash' );      $ws->setCellValue( 'B16', $hash );
			$tech_range = 'A13:A16';
			$r          = 17;

			// ── Anomalies (conditional) ───────────────────────────────────────
			$unknown_colors_no_info = $this->repo->detect_unknown_system_colors();
			if ( ! empty( $unknown_colors_no_info ) ) {
				$r = $write_anomaly_section( $r, $unknown_colors_no_info );
			}

			$last_protected_row = $r - 1;

			// ── Additional Information at bottom (template, unprotected) ──────
			$write_section_heading( $r, 'Additional Information' );
			$info_range = 'A' . ( $r + 1 ) . ':A' . ( $r + 8 );
			$r++;
			foreach ( self::ADDITIONAL_INFO_LABELS as $key => $label ) {
				$ws->setCellValue( 'A' . $r, $label );
				$ws->setCellValue( 'B' . $r, '' );
				$r++;
			}
		}

		// ── Shared styling ────────────────────────────────────────────────────
		$ws->getColumnDimension( 'A' )->setWidth( 28 );
		$ws->getColumnDimension( 'B' )->setWidth( 70 );
		$ws->getStyle( $meta_range )->getFont()->setBold( true );
		$ws->getStyle( $tech_range )->getFont()->setBold( true );
		$ws->getStyle( $info_range )->getFont()->setBold( true );

		// ── Sheet protection (Export Details + Technical + Anomalies) ─────────
		// Enable sheet protection but leave Additional Information rows editable.
		$ws->getProtection()->setSheet( true )->setPassword( 'password' )->setSort( false )->setAutoFilter( false );

		// First unlock ALL cells (default protected=true when sheet is protected,
		// so we must explicitly unlock the cells we want editable).
		$ws->getStyle( 'A1:B' . ( $r + 5 ) )->getProtection()
			->setLocked( Protection::PROTECTION_UNPROTECTED );

		// Then lock the protected region (from Export Details heading down through anomalies).
		if ( $protect_start !== null ) {
			$ws->getStyle( 'A' . $protect_start . ':B' . $last_protected_row )
				->getProtection()->setLocked( Protection::PROTECTION_PROTECTED );
		}

		$ws->setSelectedCells( 'A1' );
	}

	// ── Formatting helpers ────────────────────────────────────────────────────

	/**
	 * Write a styled header row (row 1) with column labels.
	 *
	 * @param Worksheet $ws      Target worksheet.
	 * @param string[]  $headers Column header labels (1-based).
	 * @return void
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	/**
	 * Write a title row (row 1) to a worksheet containing site name, plugin version,
	 * and export date. Leaves row 2 blank. Column headers go on row 3.
	 *
	 * Call this BEFORE write_header_row() and pass $header_row = 3 to that call
	 * when a title row is used.
	 *
	 * @param Worksheet $ws
	 * @param int       $col_count  Number of data columns (for merging).
	 */
	private function write_sheet_title_row( Worksheet $ws, int $col_count, string $sheet_name = '' ): void {
		$site_name = ExportUtil::site_name();
		$version   = defined( 'D5DSH_VERSION' ) ? D5DSH_VERSION : '';
		$date      = gmdate( 'Y-m-d H:i' ) . ' UTC';

		$prefix = $sheet_name ? $sheet_name . ' — ' : '';
		$title = $prefix . sprintf( '%s — D5 Design System Helper v%s — Exported %s', $site_name, $version, $date );

		$ws->getCell( 'A1' )->setValue( $title );

		// Merge across all data columns.
		if ( $col_count > 1 ) {
			$last = Coordinate::stringFromColumnIndex( $col_count );
			$ws->mergeCells( 'A1:' . $last . '1' );
		}

		$ws->getStyle( 'A1' )->applyFromArray( [
			'font' => [
				'bold'   => true,
				'size'   => 13,
				'color'  => [ 'argb' => 'FF000000' ],
			],
			'fill' => [
				'fillType'   => Fill::FILL_SOLID,
				'startColor' => [ 'argb' => 'FFFFFFFF' ],
			],
		] );

		// Row 2 is intentionally blank — leave as-is.
		$ws->getRowDimension( 1 )->setRowHeight( 18 );
		$ws->getRowDimension( 2 )->setRowHeight( 6 );
	}

	/**
	 * Write column header row. By default writes to row 1.
	 * Pass $row = 3 when a title row occupies rows 1-2.
	 *
	 * @param Worksheet $ws
	 * @param string[]  $headers
	 * @param int       $row      Row number for headers (default 1).
	 */
	private function write_header_row( Worksheet $ws, array $headers, int $row = 1 ): void {
		foreach ( $headers as $i => $label ) {
			$this->cell( $ws, $i + 1, $row )->setValue( $label );
		}

		$last_col = Coordinate::stringFromColumnIndex( count( $headers ) );
		$range    = 'A' . $row . ':' . $last_col . $row;

		$ws->getStyle( $range )->applyFromArray( [
			'font' => [
				'bold'  => true,
				'color' => [ 'argb' => self::HEADER_FG ],
			],
			'fill' => [
				'fillType'   => Fill::FILL_SOLID,
				'startColor' => [ 'argb' => self::HEADER_BG ],
			],
		] );

		$freeze = Coordinate::stringFromColumnIndex( 1 ) . ( $row + 1 );
		$ws->freezePane( $freeze );
	}

	/**
	 * Apply alternating row fill to a completed data sheet.
	 *
	 * @param Worksheet $ws        Target worksheet.
	 * @param int       $last_row  Last data row index (1-based).
	 * @param int       $col_count Number of columns.
	 * @return void
	 */
	private function apply_sheet_formatting( Worksheet $ws, int $last_row, int $col_count, int $start_row = 2 ): void {
		if ( $last_row < $start_row ) {
			return;
		}

		$last_col = Coordinate::stringFromColumnIndex( $col_count );

		for ( $r = $start_row; $r <= $last_row; $r++ ) {
			if ( $r % 2 === 0 ) {
				$ws->getStyle( 'A' . $r . ':' . $last_col . $r )
				   ->getFill()
				   ->setFillType( Fill::FILL_SOLID )
				   ->getStartColor()
				   ->setARGB( 'FFF9FAFB' );
			}
		}
	}

	/**
	 * Apply alternating-row fill to the Colors sheet, intentionally skipping
	 * the Swatch column (column D / COL_COLOR_SWATCH) so that swatch fills
	 * written in an earlier pass are never overwritten by the row-banding colour.
	 *
	 * Uses two separate range calls per even row — A:C and E:I — rather than a
	 * full-row range, which avoids touching column D entirely.
	 *
	 * @param Worksheet $ws       Target worksheet.
	 * @param int       $last_row Last data row index (1-based).
	 * @return void
	 */
	private function apply_colors_sheet_formatting( Worksheet $ws, int $last_row ): void {
		if ( $last_row < 2 ) {
			return;
		}

		$swatch_col     = self::COL_COLOR_SWATCH; // 4 = column D
		$before_swatch  = Coordinate::stringFromColumnIndex( $swatch_col - 1 ); // C
		$after_swatch   = Coordinate::stringFromColumnIndex( $swatch_col + 1 ); // E
		$last_col       = Coordinate::stringFromColumnIndex( 9 );               // I

		for ( $r = 2; $r <= $last_row; $r++ ) {
			if ( $r % 2 !== 0 ) {
				continue;
			}
			// Columns A–F (before swatch).
			$ws->getStyle( 'A' . $r . ':' . $before_swatch . $r )
			   ->getFill()
			   ->setFillType( Fill::FILL_SOLID )
			   ->getStartColor()
			   ->setARGB( 'FFF9FAFB' );
			// Columns H–I (after swatch).
			$ws->getStyle( $after_swatch . $r . ':' . $last_col . $r )
			   ->getFill()
			   ->setFillType( Fill::FILL_SOLID )
			   ->getStartColor()
			   ->setARGB( 'FFF9FAFB' );
		}
	}

	/**
	 * Set column widths in characters for the given worksheet.
	 *
	 * @param Worksheet $ws     Target worksheet.
	 * @param float[]   $widths Array of widths, one per column (1-based).
	 * @return void
	 */
	/**
	 * Mark specified columns as protected (read-only) with a light yellow fill
	 * to signal they should not be edited.
	 *
	 * @param Worksheet $ws
	 * @param int[]     $col_indices  1-based column indices to protect.
	 * @param int       $last_row     Last data row.
	 */
	private function protect_columns( Worksheet $ws, array $col_indices, int $last_row, int $start_row = 2 ): void {
		if ( $last_row < $start_row ) { return; }
		foreach ( $col_indices as $col ) {
			$col_letter = Coordinate::stringFromColumnIndex( $col );
			$range      = $col_letter . $start_row . ':' . $col_letter . $last_row;
			$ws->getStyle( $range )->applyFromArray( [
				'fill' => [
					'fillType'   => Fill::FILL_SOLID,
					'startColor' => [ 'argb' => 'FFE8E8E8' ], // light gray (uneditable)
				],
			] );
			// PhpSpreadsheet cell protection (requires sheet protection to enforce).
			$ws->getStyle( $range )->getProtection()
				->setLocked( \PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_PROTECTED );
		}
	}

	/**
	 * Left-align all cells in a data sheet (overrides Excel's default right-align for numbers).
	 *
	 * @param Worksheet $ws       Target worksheet.
	 * @param int       $last_row Last data row.
	 * @param int       $col_count Number of columns.
	 */
	private function left_align_all( Worksheet $ws, int $last_row, int $col_count ): void {
		if ( $last_row < 1 ) { return; }
		$last_col = Coordinate::stringFromColumnIndex( $col_count );
		$ws->getStyle( 'A1:' . $last_col . $last_row )
			->getAlignment()
			->setHorizontal( \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT );
	}

	private function set_column_widths( Worksheet $ws, array $widths ): void {
		foreach ( $widths as $i => $width ) {
			$col = Coordinate::stringFromColumnIndex( $i + 1 );
			$ws->getColumnDimension( $col )->setWidth( $width );
		}
	}

	/**
	 * Apply yellow fill to the specified columns of a system (read-only) data row.
	 *
	 * Called for rows where the variable's 'system' flag is true. The yellow fill
	 * signals to the user that the ID and Label cannot be changed on import.
	 *
	 * @param Worksheet $ws      Target worksheet.
	 * @param int       $row     1-based row index.
	 * @param int[]     $cols    1-based column indices to highlight.
	 * @return void
	 */
	private function apply_system_row_highlight( Worksheet $ws, int $row, array $cols ): void {
		foreach ( $cols as $col ) {
			try {
				$coord = Coordinate::stringFromColumnIndex( $col ) . $row;
				$ws->getStyle( $coord )
				   ->getFill()
				   ->setFillType( Fill::FILL_SOLID )
				   ->getStartColor()
				   ->setARGB( self::SYSTEM_ROW_BG );
			} catch ( \Throwable ) {
				// Skip on invalid coordinate.
			}
		}
	}

	// ── Cell access helper (PhpSpreadsheet v2 API) ────────────────────────────

	/**
	 * Return the Cell object at the given 1-based column and row.
	 *
	 * PhpSpreadsheet v2 removed setCellValueByColumnAndRow() and
	 * getCellByColumnAndRow(). This helper centralises the v2-compatible
	 * coordinate conversion so call sites remain readable.
	 *
	 * @param Worksheet $ws  Target worksheet.
	 * @param int       $col 1-based column index.
	 * @param int       $row 1-based row index.
	 * @return \PhpOffice\PhpSpreadsheet\Cell\Cell
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function cell( Worksheet $ws, int $col, int $row ): \PhpOffice\PhpSpreadsheet\Cell\Cell {
		return $ws->getCell( Coordinate::stringFromColumnIndex( $col ) . $row );
	}

	// ── Data helpers ─────────────────────────────────────────────────────────

	/**
	 * Group a flat list of normalised variable records by their 'type' field.
	 *
	 * @param array $vars Flat list from VarsRepository::get_all().
	 * @return array<string, array> Associative: type → list of records.
	 */
	private function group_by_type( array $vars ): array {
		$groups = [];
		foreach ( $vars as $entry ) {
			$groups[ $entry['type'] ][] = $entry;
		}
		return $groups;
	}

	/**
	 * Convert a CSS colour value to an ARGB hex string suitable for PhpSpreadsheet.
	 *
	 * Handles:
	 *   #RGB         → FFRRGGBB  (shorthand, expanded)
	 *   #RRGGBB      → FFRRGGBB
	 *   rgba(r,g,b,a) → AARRGGBB  (alpha 0–1 → 0–255)
	 *   rgb(r,g,b)   → FFRRGGBB
	 *
	 * Returns null if the value is not a recognised colour format.
	 *
	 * @param string $value Raw CSS colour value.
	 * @return string|null 8-char uppercase ARGB hex, or null.
	 */
	private function value_to_argb( string $value ): ?string {
		$value = trim( $value );

		// ── Hex colours ────────────────────────────────────────────────────
		if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
			return 'FF' . strtoupper( ltrim( $value, '#' ) );
		}
		if ( preg_match( '/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $value, $m ) ) {
			return 'FF' . strtoupper( $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3] );
		}

		// ── rgba() ─────────────────────────────────────────────────────────
		// Blend over white background so the Excel cell fill shows the visual colour.
		// Alpha-compositing: out = alpha * src + (1 - alpha) * 255
		if ( preg_match( '/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*([0-9.]+)\s*\)$/i', $value, $m ) ) {
			$r  = max( 0, min( 255, (int) $m[1] ) );
			$g  = max( 0, min( 255, (int) $m[2] ) );
			$b  = max( 0, min( 255, (int) $m[3] ) );
			$a  = max( 0.0, min( 1.0, (float) $m[4] ) );
			$ro = (int) round( $a * $r + ( 1 - $a ) * 255 );
			$go = (int) round( $a * $g + ( 1 - $a ) * 255 );
			$bo = (int) round( $a * $b + ( 1 - $a ) * 255 );
			return strtoupper( sprintf( 'FF%02X%02X%02X', $ro, $go, $bo ) );
		}

		// ── rgb() ──────────────────────────────────────────────────────────
		if ( preg_match( '/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i', $value, $m ) ) {
			$r = max( 0, min( 255, (int) $m[1] ) );
			$g = max( 0, min( 255, (int) $m[2] ) );
			$b = max( 0, min( 255, (int) $m[3] ) );
			return strtoupper( sprintf( 'FF%02X%02X%02X', $r, $g, $b ) );
		}

		return null;
	}

	/**
	 * Return true if $value looks like a CSS hex colour (#RGB or #RRGGBB).
	 *
	 * @param string $value
	 * @return bool
	 */
	private function is_hex_color( string $value ): bool {
		return (bool) preg_match( '/^#[0-9a-fA-F]{3}$|^#[0-9a-fA-F]{6}$/', trim( $value ) );
	}
}
