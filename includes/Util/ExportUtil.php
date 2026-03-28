<?php
/**
 * Shared Excel building utilities for all exporters.
 *
 * Centralises PhpSpreadsheet v2 API calls and common sheet-building
 * operations so individual exporters stay focused on data logic.
 *
 * All methods are static. Exporters call ExportUtil::cell(), etc.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Util;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use D5DesignSystemHelper\Util\BlobUtil;
use D5DesignSystemHelper\Util\DiviBlocParser;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExportUtil {

    const HEADER_BG      = 'FF1F2937';
    const HEADER_FG      = 'FFFFFFFF';
    const MONO_FONT      = 'Courier New';
    const BLOB_PLACEHOLDER = 'Uneditable Data Not Shown';
    const EVEN_ROW_BG    = 'FFF9FAFB';

    /**
     * Return the WordPress site name, falling back to blogname then host.
     * Always non-empty.
     */
    public static function site_name(): string {
        $name = get_bloginfo( 'name' );
        if ( '' === trim( $name ) ) {
            $name = get_bloginfo( 'blogname' );
        }
        if ( '' === trim( $name ) ) {
            $name = parse_url( home_url(), PHP_URL_HOST ) ?? 'site';
        }
        return $name;
    }

    /**
     * Return the Cell at 1-based column $col and row $row (PhpSpreadsheet v2 API).
     */
    public static function cell( Worksheet $ws, int $col, int $row ): Cell {
        return $ws->getCell( Coordinate::stringFromColumnIndex( $col ) . $row );
    }

    /**
     * Write a styled header row (row 1) and freeze pane at A2.
     * @param string[] $headers Column labels, 1-based.
     */
    public static function write_header_row( Worksheet $ws, array $headers ): void {
        foreach ( $headers as $i => $label ) {
            static::cell( $ws, $i + 1, 1 )->setValue( $label );
        }
        $last_col = Coordinate::stringFromColumnIndex( count( $headers ) );
        $ws->getStyle( 'A1:' . $last_col . '1' )->applyFromArray( [
            'font' => [ 'bold' => true, 'color' => [ 'argb' => self::HEADER_FG ] ],
            'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => self::HEADER_BG ] ],
        ] );
        $ws->freezePane( 'A2' );
    }

    /**
     * Apply alternating row fill to rows 2–$last_row.
     */
    public static function apply_sheet_formatting( Worksheet $ws, int $last_row, int $col_count ): void {
        if ( $last_row < 2 ) { return; }
        $last_col = Coordinate::stringFromColumnIndex( $col_count );
        for ( $r = 2; $r <= $last_row; $r++ ) {
            if ( $r % 2 === 0 ) {
                $ws->getStyle( 'A' . $r . ':' . $last_col . $r )
                   ->getFill()->setFillType( Fill::FILL_SOLID )
                   ->getStartColor()->setARGB( self::EVEN_ROW_BG );
            }
        }
    }

    /**
     * Set column widths (characters) for columns 1..n.
     * @param float[] $widths One entry per column.
     */
    public static function set_column_widths( Worksheet $ws, array $widths ): void {
        foreach ( $widths as $i => $w ) {
            $ws->getColumnDimension( Coordinate::stringFromColumnIndex( $i + 1 ) )->setWidth( $w );
        }
    }

    /**
     * Write the visible Info sheet to the spreadsheet.
     *
     * @param string   $file_type     e.g. 'vars', 'presets', 'audit'
     * @param string   $option_key    The primary wp_options key being exported (for reference).
     * @param array    $sheet_columns Optional column inventory: [ 'Sheet Name' => ['Col A', 'Col B', ...], ... ]
     *                                When provided, a "Sheet Column Inventory" section is appended below
     *                                the metadata rows listing every worksheet, its column count, and
     *                                the column names in order.
     */
    public static function build_info_sheet(
        Spreadsheet $ss,
        string $file_type,
        string $option_key    = '',
        array  $sheet_columns = []
    ): void {
        $ws = $ss->createSheet();
        $ws->setTitle( 'Info' );

        $ws->mergeCells( 'A1:B1' );
        $ws->setCellValue( 'A1', 'Export Information' );
        $ws->getStyle( 'A1' )->getFont()->setBold( true )->setSize( 13 );

        $rows = [
            [ 'Source Site',       get_bloginfo( 'url' ) ],
            [ 'Site Name',         self::site_name() ],
            [ 'Export Date (UTC)', gmdate( 'Y-m-d\TH:i:s\Z' ) ],
            [ 'Divi Version',      defined( 'ET_CORE_VERSION' ) ? ET_CORE_VERSION : 'Unknown' ],
            [ 'WordPress Version', $GLOBALS['wp_version'] ?? '' ],
            [ 'Exported By',       wp_get_current_user()->display_name ],
            [ 'File Type',         $file_type ],
        ];
        if ( $option_key ) {
            $rows[] = [ 'Option Key', $option_key ];
        }
        foreach ( $rows as $i => $pair ) {
            $r = $i + 3;
            $ws->setCellValue( 'A' . $r, $pair[0] );
            $ws->setCellValue( 'B' . $r, $pair[1] );
        }
        $ws->getColumnDimension( 'A' )->setWidth( 24 );
        $ws->getColumnDimension( 'B' )->setWidth( 70 );
        $ws->getStyle( 'A3:A' . ( count( $rows ) + 2 ) )->getFont()->setBold( true );

        // ── Sheet Column Inventory ────────────────────────────────────────────
        if ( ! empty( $sheet_columns ) ) {
            $r = count( $rows ) + 4; // blank spacer row between metadata and inventory

            // Section heading
            $ws->mergeCells( 'A' . $r . ':B' . $r );
            $ws->setCellValue( 'A' . $r, 'Sheet Column Inventory' );
            $ws->getStyle( 'A' . $r )->getFont()->setBold( true )->setSize( 12 );
            $ws->getStyle( 'A' . $r . ':B' . $r )->getFill()
               ->setFillType( Fill::FILL_SOLID )
               ->getStartColor()->setARGB( 'FFF0F4F8' );
            $r++;

            // Column header row for the inventory table
            $inv_headers = [ 'Worksheet', 'Columns', 'Column Names (in order)' ];
            foreach ( $inv_headers as $ci => $label ) {
                $ws->setCellValue( Coordinate::stringFromColumnIndex( $ci + 1 ) . $r, $label );
            }
            $ws->getStyle( 'A' . $r . ':C' . $r )->applyFromArray( [
                'font' => [ 'bold' => true, 'color' => [ 'argb' => self::HEADER_FG ] ],
                'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => self::HEADER_BG ] ],
            ] );
            $r++;

            // One row per sheet
            foreach ( $sheet_columns as $sheet_name => $cols ) {
                $ws->setCellValue( 'A' . $r, $sheet_name );
                $ws->setCellValue( 'B' . $r, count( $cols ) );
                $ws->setCellValue( 'C' . $r, implode( ', ', $cols ) );
                $ws->getStyle( 'C' . $r )->getAlignment()->setWrapText( true );
                $ws->getRowDimension( $r )->setRowHeight( -1 ); // auto-height
                if ( $r % 2 === 0 ) {
                    $ws->getStyle( 'A' . $r . ':C' . $r )->getFill()
                       ->setFillType( Fill::FILL_SOLID )
                       ->getStartColor()->setARGB( self::EVEN_ROW_BG );
                }
                $r++;
            }

            // Extend column C width for the column names list
            $ws->getColumnDimension( 'C' )->setWidth( 80 );
        }
    }

    /**
     * Write the hidden Config sheet (SHA-256 hash, option key, timestamps).
     * @param array  $raw_data  The raw DB data to hash.
     * @param string $option_key
     * @param string $file_type
     */
    public static function build_config_sheet(
        Spreadsheet $ss,
        array $raw_data,
        string $option_key,
        string $file_type
    ): void {
        $ws = $ss->createSheet();
        $ws->setTitle( 'Config' );

        $hash = hash( 'sha256', maybe_serialize( $raw_data ) );
        $rows = [
            [ 'Option Key',      $option_key ],
            [ 'Export Date UTC', gmdate( 'Y-m-d\TH:i:s\Z' ) ],
            [ 'SHA-256 Hash',    $hash ],
            [ 'Plugin Version',  D5DSH_VERSION ],
            [ 'File Type',       $file_type ],
        ];
        foreach ( $rows as $i => $pair ) {
            $r = $i + 1;
            $ws->setCellValue( 'A' . $r, $pair[0] );
            $ws->setCellValue( 'B' . $r, $pair[1] );
        }
        $ws->getColumnDimension( 'A' )->setWidth( 22 );
        $ws->getColumnDimension( 'B' )->setWidth( 70 );
        $ws->getStyle( 'A1:A5' )->getFont()->setBold( true );
        $ws->setSheetState( Worksheet::SHEETSTATE_HIDDEN );
        $ws->getTabColor()->setARGB( 'FFFF0000' );
    }

    /**
     * Write the hidden Blobs sheet.
     * Each record: ['sheet'=>string, 'row'=>int, 'col'=>string, 'id'=>string, 'type'=>string]
     */
    public static function build_blobs_sheet( Spreadsheet $ss, array $blob_records ): void {
        $ws = $ss->createSheet();
        $ws->setTitle( 'Blobs' );
        self::write_header_row( $ws, [ 'Sheet', 'Row', 'Column', 'Variable ID', 'Type' ] );
        $row = 2;
        foreach ( $blob_records as $rec ) {
            self::cell( $ws, 1, $row )->setValue( $rec['sheet'] ?? '' );
            self::cell( $ws, 2, $row )->setValue( $rec['row']   ?? '' );
            self::cell( $ws, 3, $row )->setValue( $rec['col']   ?? '' );
            self::cell( $ws, 4, $row )->setValue( $rec['id']    ?? '' );
            self::cell( $ws, 5, $row )->setValue( $rec['type']  ?? '' );
            $row++;
        }
        self::set_column_widths( $ws, [ 16, 8, 8, 22, 12 ] );
        $ws->setSheetState( Worksheet::SHEETSTATE_HIDDEN );
        $ws->getTabColor()->setARGB( 'FFFF0000' );
    }

    /**
     * Write Global Colors rows to an existing sheet starting at $start_row.
     * Handles both list format [[id, {label,color,status,...}], ...] and
     * dict format [id => {label,color,...}].
     *
     * Columns: ID | Label | Color | Order | Status | Folder | Last Updated
     * Returns the next available row number.
     */
    public static function write_global_colors_rows(
        Worksheet $ws,
        array $global_colors,
        int $start_row = 2,
        bool $is_dict = false
    ): int {
        $row = $start_row;
        if ( $is_dict ) {
            foreach ( $global_colors as $id => $entry ) {
                self::cell( $ws, 1, $row )->setValue( $id );
                self::cell( $ws, 2, $row )->setValue( $entry['label']       ?? '' );
                self::cell( $ws, 3, $row )->setValue( $entry['color']       ?? '' );
                self::cell( $ws, 4, $row )->setValue( $entry['order']       ?? '' );
                self::cell( $ws, 5, $row )->setValue( $entry['status']      ?? '' );
                self::cell( $ws, 6, $row )->setValue( $entry['folder']      ?? '' );
                self::cell( $ws, 7, $row )->setValue( $entry['lastUpdated'] ?? '' );
                $row++;
            }
        } else {
            foreach ( $global_colors as $item ) {
                $id    = is_array( $item ) ? ( $item[0] ?? '' ) : '';
                $entry = is_array( $item ) ? ( $item[1] ?? [] ) : [];
                self::cell( $ws, 1, $row )->setValue( $id );
                self::cell( $ws, 2, $row )->setValue( $entry['label']       ?? '' );
                self::cell( $ws, 3, $row )->setValue( $entry['color']       ?? '' );
                self::cell( $ws, 4, $row )->setValue( $entry['order']       ?? '' );
                self::cell( $ws, 5, $row )->setValue( $entry['status']      ?? '' );
                self::cell( $ws, 6, $row )->setValue( $entry['folder']      ?? '' );
                self::cell( $ws, 7, $row )->setValue( $entry['lastUpdated'] ?? '' );
                $row++;
            }
        }
        return $row;
    }

    /**
     * Write Global Variables rows to an existing sheet starting at $start_row.
     * Columns: ID | Label | Swatch | Value | Type | Status | Order
     * Returns next available row.
     */
    public static function write_global_variables_rows(
        Worksheet $ws,
        array $global_variables,
        int $start_row = 2
    ): int {
        $row = $start_row;
        foreach ( $global_variables as $entry ) {
            self::cell( $ws, 1, $row )->setValue( $entry['id']     ?? '' );
            self::cell( $ws, 2, $row )->setValue( $entry['label']  ?? '' );
            // Col 3: Swatch — fill with color if value is a color type.
            $type  = $entry['type']  ?? '';
            $value = BlobUtil::sanitize( $entry['value'] ?? '' );
            if ( $type === 'colors' ) {
                $argb = self::value_to_argb( $value );
                if ( $argb !== null ) {
                    $ws->getStyle( 'C' . $row )->getFill()
                        ->setFillType( Fill::FILL_SOLID )
                        ->getStartColor()->setARGB( $argb );
                }
            }
            self::cell( $ws, 4, $row )->setValue( $value );
            self::cell( $ws, 5, $row )->setValue( $type );
            self::cell( $ws, 6, $row )->setValue( $entry['status'] ?? '' );
            self::cell( $ws, 7, $row )->setValue( $entry['order']  ?? '' );
            $row++;
        }
        return $row;
    }

    /**
     * Convert a CSS color value to an ARGB hex string for PhpSpreadsheet.
     * Returns null if not a recognised color.
     */
    public static function value_to_argb( string $value ): ?string {
        $value = trim( $value );
        if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
            return 'FF' . strtoupper( ltrim( $value, '#' ) );
        }
        if ( preg_match( '/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $value, $m ) ) {
            return 'FF' . strtoupper( $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3] );
        }
        if ( preg_match( '/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*([0-9.]+)\s*\)$/i', $value, $m ) ) {
            $r = max( 0, min( 255, (int) $m[1] ) );
            $g = max( 0, min( 255, (int) $m[2] ) );
            $b = max( 0, min( 255, (int) $m[3] ) );
            $a = max( 0.0, min( 1.0, (float) $m[4] ) );
            return strtoupper( sprintf( 'FF%02X%02X%02X',
                (int) round( $a * $r + ( 1 - $a ) * 255 ),
                (int) round( $a * $g + ( 1 - $a ) * 255 ),
                (int) round( $a * $b + ( 1 - $a ) * 255 )
            ) );
        }
        if ( preg_match( '/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i', $value, $m ) ) {
            return strtoupper( sprintf( 'FF%02X%02X%02X',
                max( 0, min( 255, (int) $m[1] ) ),
                max( 0, min( 255, (int) $m[2] ) ),
                max( 0, min( 255, (int) $m[3] ) )
            ) );
        }
        return null;
    }

    /**
     * Add a Global Colors sheet to the spreadsheet.
     * @param bool $is_dict True for theme_customizer dict format, false for list format.
     */
    public static function add_global_colors_sheet(
        Spreadsheet $ss,
        array $global_colors,
        bool $is_dict = false
    ): void {
        $headers = [ 'ID', 'Label', 'Color', 'Order', 'Status', 'Folder', 'Last Updated' ];
        $ws = $ss->createSheet();
        $ws->setTitle( 'Global Colors' );
        self::write_sheet_title_row( $ws, count( $headers ), 'Global Colors' );
        self::write_header_row_at( $ws, $headers, 3, true );
        $last = self::write_global_colors_rows( $ws, $global_colors, 4, $is_dict );
        self::apply_sheet_formatting( $ws, max( 4, $last - 1 ), 7 );
        self::set_column_widths( $ws, [ 24, 24, 12, 8, 12, 16, 24 ] );
        $ws->setSelectedCells( 'A1' );
    }

    /**
     * Add a Global Variables sheet to the spreadsheet.
     */
    public static function add_global_variables_sheet( Spreadsheet $ss, array $global_variables ): void {
        $headers = [ 'ID', 'Label', 'Swatch', 'Value', 'Type', 'Status', 'Order' ];
        $ws = $ss->createSheet();
        $ws->setTitle( 'Variables' );
        self::write_sheet_title_row( $ws, count( $headers ), 'Variables' );
        self::write_header_row_at( $ws, $headers, 3, true );
        $last = self::write_global_variables_rows( $ws, $global_variables, 4 );
        self::apply_sheet_formatting( $ws, max( 4, $last - 1 ), 7 );
        self::set_column_widths( $ws, [ 22, 24, 8, 50, 12, 12, 8 ] );
        $ws->setSelectedCells( 'A1' );
    }

    /**
     * Add Module Presets and Group Presets sheets to the spreadsheet.
     * @param array $presets ['module' => [...], 'group' => [...]]
     */
    public static function add_presets_sheets( Spreadsheet $ss, array $presets ): void {
        // Module Presets sheet
        $ws_mod = $ss->createSheet();
        $ws_mod->setTitle( 'Presets – Modules' );
        self::write_header_row( $ws_mod, [ 'Module', 'Preset ID', 'Name', 'Version', 'Is Default', 'Order', 'Attrs (JSON)', 'Style Attrs (JSON)' ] );
        $row = 2;
        foreach ( $presets['module'] ?? [] as $module_name => $module_data ) {
            $default = $module_data['default'] ?? '';
            $order   = 1;
            foreach ( $module_data['items'] ?? [] as $preset_id => $preset ) {
                self::cell( $ws_mod, 1, $row )->setValue( $module_name );
                self::cell( $ws_mod, 2, $row )->setValue( $preset_id );
                self::cell( $ws_mod, 3, $row )->setValue( $preset['name']    ?? '' );
                self::cell( $ws_mod, 4, $row )->setValue( $preset['version'] ?? '' );
                self::cell( $ws_mod, 5, $row )->setValue( $preset_id === $default ? 'Yes' : 'No' );
                self::cell( $ws_mod, 6, $row )->setValue( $order );
                self::cell( $ws_mod, 7, $row )->setValue( isset( $preset['attrs'] ) ? json_encode( $preset['attrs'] ) : '' );
                self::cell( $ws_mod, 8, $row )->setValue( isset( $preset['styleAttrs'] ) ? json_encode( $preset['styleAttrs'] ) : '' );
                $row++;
                $order++;
            }
        }
        self::apply_sheet_formatting( $ws_mod, $row - 1, 8 );
        self::set_column_widths( $ws_mod, [ 20, 16, 24, 20, 12, 8, 40, 40 ] );

        // Group Presets sheet
        $ws_grp = $ss->createSheet();
        $ws_grp->setTitle( 'Presets – Groups' );
        self::write_header_row( $ws_grp, [ 'Group Name', 'Preset ID', 'Name', 'Version', 'Module Name', 'Group ID', 'Is Default', 'Attrs (JSON)', 'Style Attrs (JSON)' ] );
        $row = 2;
        foreach ( $presets['group'] ?? [] as $group_name => $group_data ) {
            $default = $group_data['default'] ?? '';
            foreach ( $group_data['items'] ?? [] as $preset_id => $preset ) {
                self::cell( $ws_grp, 1, $row )->setValue( $group_name );
                self::cell( $ws_grp, 2, $row )->setValue( $preset_id );
                self::cell( $ws_grp, 3, $row )->setValue( $preset['name']       ?? '' );
                self::cell( $ws_grp, 4, $row )->setValue( $preset['version']    ?? '' );
                self::cell( $ws_grp, 5, $row )->setValue( $preset['moduleName'] ?? '' );
                self::cell( $ws_grp, 6, $row )->setValue( $preset['groupId']    ?? '' );
                self::cell( $ws_grp, 7, $row )->setValue( $preset_id === $default ? 'Yes' : 'No' );
                self::cell( $ws_grp, 8, $row )->setValue( isset( $preset['attrs'] ) ? json_encode( $preset['attrs'] ) : '' );
                self::cell( $ws_grp, 9, $row )->setValue( isset( $preset['styleAttrs'] ) ? json_encode( $preset['styleAttrs'] ) : '' );
                $row++;
            }
        }
        self::apply_sheet_formatting( $ws_grp, $row - 1, 9 );
        self::set_column_widths( $ws_grp, [ 20, 16, 24, 20, 20, 16, 12, 40, 40 ] );
    }

    /**
     * Write a styled title row (row 1) and blank spacer row (row 2).
     * Column headers should go on row 3, data on row 4+.
     * Matches the format used in VarsExporter sheets.
     *
     * @param Worksheet $ws
     * @param int       $col_count  Number of data columns (for merge range).
     * @param string    $sheet_name Optional prefix for the title string.
     */
    public static function write_sheet_title_row( Worksheet $ws, int $col_count, string $sheet_name = '' ): void {
        $site_name = self::site_name();
        $version   = defined( 'D5DSH_VERSION' ) ? D5DSH_VERSION : '';
        $date      = gmdate( 'Y-m-d H:i' ) . ' UTC';
        $prefix    = $sheet_name ? $sheet_name . ' — ' : '';
        $title     = $prefix . sprintf( '%s — D5 Design System Helper v%s — Exported %s', $site_name, $version, $date );

        $ws->getCell( 'A1' )->setValue( $title );
        if ( $col_count > 1 ) {
            $last = Coordinate::stringFromColumnIndex( $col_count );
            $ws->mergeCells( 'A1:' . $last . '1' );
        }
        $ws->getStyle( 'A1' )->applyFromArray( [
            'font' => [
                'bold'  => true,
                'size'  => 13,
                'color' => [ 'argb' => 'FF000000' ],
            ],
            'fill' => [
                'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => [ 'argb' => 'FFFFFFFF' ],
            ],
        ] );

        // Row 2: blank spacer with reduced height.
        $ws->getRowDimension( 2 )->setRowHeight( 6 );
    }

    /**
     * Write a styled header row starting at a specific row number.
     * Use $row = 3 when a title row has been written above.
     * Optionally enables autofilter on the header row.
     *
     * @param Worksheet $ws
     * @param string[]  $headers
     * @param int       $row        Row number for the header (default 1).
     * @param bool      $autofilter Whether to enable autofilter on this row.
     */
    public static function write_header_row_at(
        Worksheet $ws,
        array $headers,
        int $row = 1,
        bool $autofilter = false
    ): void {
        foreach ( $headers as $i => $label ) {
            static::cell( $ws, $i + 1, $row )->setValue( $label );
        }
        $last_col = Coordinate::stringFromColumnIndex( count( $headers ) );
        $range    = 'A' . $row . ':' . $last_col . $row;
        $ws->getStyle( $range )->applyFromArray( [
            'font' => [ 'bold' => true, 'color' => [ 'argb' => self::HEADER_FG ] ],
            'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => self::HEADER_BG ] ],
        ] );
        $ws->freezePane( 'A' . ( $row + 1 ) );
        if ( $autofilter ) {
            $ws->setAutoFilter( $range );
        }
    }

    /**
     * Stream a Spreadsheet to the browser as an .xlsx download and exit.
     */
    public static function stream_xlsx( \PhpOffice\PhpSpreadsheet\Spreadsheet $ss, string $filename ): never {
        while ( ob_get_level() ) { ob_end_clean(); }
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: max-age=0' );
        header( 'Pragma: public' );
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $ss );
        $writer->setIncludeCharts( true );
        $writer->save( 'php://output' );
        exit;
    }

    /**
     * Save a Spreadsheet to a temp file and return the file path.
     * Caller is responsible for unlinking the file.
     */
    public static function save_to_temp( \PhpOffice\PhpSpreadsheet\Spreadsheet $ss ): string {
        $tmp = tempnam( sys_get_temp_dir(), 'd5dsh_' ) . '.xlsx';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $ss );
        $writer->setIncludeCharts( true );
        $writer->save( $tmp );
        return $tmp;
    }

    /**
     * Extract the referenced variable name from a $variable({...})$ expression.
     * Returns '' if unparseable.
     *
     * Delegates to DiviBlocParser::extract_variable_ref_name().
     */
    public static function extract_variable_ref_name( string $value ): string {
        return DiviBlocParser::extract_variable_ref_name( $value );
    }

    /**
     * Return true if $value looks like #RGB or #RRGGBB.
     */
    public static function is_hex_color( string $value ): bool {
        return (bool) preg_match( '/^#[0-9a-fA-F]{3}$|^#[0-9a-fA-F]{6}$/', trim( $value ) );
    }

    /**
     * Return the Excel column letter (e.g. 1 → 'A', 27 → 'AA') for a 1-based column index.
     *
     * Thin wrapper around Coordinate::stringFromColumnIndex().
     */
    public static function col_letter( int $col ): string {
        return Coordinate::stringFromColumnIndex( $col );
    }

    /**
     * Write the Instructions / Disclaimer sheet as the first visible sheet in the workbook.
     *
     * This sheet appears at the front of every exported Excel file and discloses what the
     * file contains, what it does not contain, and how it may safely be edited.
     * The sheet is protected (read-only) so users are not tempted to edit it accidentally.
     *
     * Call this AFTER creating the Spreadsheet but BEFORE adding any data sheets.
     * The caller is responsible for setting the active sheet back to whichever sheet
     * should be active when Excel opens the file.
     *
     * @param Spreadsheet $ss The spreadsheet to add the sheet to.
     */
    public static function write_instructions_sheet( Spreadsheet $ss ): void {
        // Append Instructions as the second sheet; callers add Info first.
        $ws = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet( $ss, 'Instructions' );
        $ss->addSheet( $ws );
        $ss->setActiveSheetIndex( 0 );

        // ── Styles ───────────────────────────────────────────────────────────
        $title_style = [
            'font' => [ 'bold' => true, 'size' => 14, 'color' => [ 'argb' => 'FF1F2937' ] ],
        ];
        $heading_style = [
            'font' => [ 'bold' => true, 'size' => 11, 'color' => [ 'argb' => 'FF1F2937' ] ],
            'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FFF0F4F8' ] ],
        ];
        $body_style = [
            'alignment' => [
                'wrapText'   => true,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ];
        $note_style = [
            'font'      => [ 'italic' => true, 'size' => 10, 'color' => [ 'argb' => 'FF6B7280' ] ],
            'alignment' => [ 'wrapText' => true, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP ],
        ];

        // ── Column widths ────────────────────────────────────────────────────
        $ws->getColumnDimension( 'A' )->setWidth( 22 );
        $ws->getColumnDimension( 'B' )->setWidth( 80 );

        // ── Title ────────────────────────────────────────────────────────────
        $ws->mergeCells( 'A1:B1' );
        $ws->setCellValue( 'A1', 'D5 Design System Helper — About This File' );
        $ws->getStyle( 'A1' )->applyFromArray( $title_style );
        $ws->getRowDimension( 1 )->setRowHeight( 28 );

        $ws->mergeCells( 'A2:B2' );
        $ws->setCellValue( 'A2', 'Please read before editing. This sheet is for reference only.' );
        $ws->getStyle( 'A2' )->applyFromArray( $note_style );
        $ws->getRowDimension( 2 )->setRowHeight( 18 );

        // ── Content rows ─────────────────────────────────────────────────────
        $sections = [
            [
                'heading' => 'What This File Contains',
                'body'    => 'This Excel file was produced by the D5 Design System Helper plugin. It contains a snapshot of your Divi 5 design system — specifically your Global Variables (colors, numbers, fonts, images, text, and links) and your Element and Group Presets — as they existed at the moment of export. It is not a backup of your WordPress site or your Divi installation. Page content, layouts, builder templates, theme customizer settings, and all other site data are not included.',
            ],
            [
                'heading' => 'Embedded Image Data',
                'body'    => 'If any of your image variables store a base64-encoded image directly in the database (rather than a URL to a media file), the Value column for those rows will read "' . self::BLOB_PLACEHOLDER . '." The image itself is not in this file. Image variables that point to a URL are exported normally.',
            ],
            [
                'heading' => 'Color References',
                'body'    => 'Divi allows a color variable to point to another variable using its $variable() reference syntax. In those rows, the Value column contains the raw reference expression and the Reference column contains the referenced variable\'s name. The color swatch shows the resolved color visually, but the cell value remains a reference. If you edit these rows, understand the reference chain before changing the value to a plain hex color.',
            ],
            [
                'heading' => 'Preset Style Settings',
                'body'    => 'The Attrs and Style Attrs columns in the Presets sheets contain JSON text strings exactly as Divi stores them in the database. They are readable, but editing them by hand carries risk — a malformed JSON string will be rejected or silently ignored on import.',
            ],
            [
                'heading' => 'Read-Only Columns',
                'body'    => 'Columns with a gray background (Order, ID, Status, System) are locked and carry no effect on import even if edited in a spreadsheet application that bypasses the sheet protection. The Order column is an exception: changing it changes the display sequence of variables in the Divi editor after import. Only the Label column (and the Value column for most types) is intended for editing.',
            ],
            [
                'heading' => 'This File is a Point-in-Time Snapshot',
                'body'    => 'This file does not update automatically. If your design system changes after export, re-export to get a fresh file. Always use Dry Run mode before committing an import to preview what will change.',
            ],
            [
                'heading' => 'Hidden Sheets — Do Not Modify',
                'body'    => 'This workbook contains two hidden sheets named Config and Blobs. Config stores the SHA-256 hash used for import verification; Blobs stores records of any image placeholders. Do not unhide, edit, or delete these sheets — doing so may cause import to fail or produce incorrect results.',
            ],
        ];

        $row = 4;
        foreach ( $sections as $section ) {
            // Heading row.
            $ws->mergeCells( 'A' . $row . ':B' . $row );
            $ws->setCellValue( 'A' . $row, $section['heading'] );
            $ws->getStyle( 'A' . $row . ':B' . $row )->applyFromArray( $heading_style );
            $ws->getRowDimension( $row )->setRowHeight( 20 );
            $row++;

            // Body row.
            $ws->mergeCells( 'A' . $row . ':B' . $row );
            $ws->setCellValue( 'A' . $row, $section['body'] );
            $ws->getStyle( 'A' . $row . ':B' . $row )->applyFromArray( $body_style );
            $ws->getRowDimension( $row )->setRowHeight( 60 );
            $row++;

            // Spacer.
            $ws->getRowDimension( $row )->setRowHeight( 6 );
            $row++;
        }

        // ── Plugin version footer ─────────────────────────────────────────────
        $ws->mergeCells( 'A' . $row . ':B' . $row );
        $version_text = sprintf(
            'Generated by D5 Design System Helper v%s on %s UTC — %s',
            defined( 'D5DSH_VERSION' ) ? D5DSH_VERSION : '',
            gmdate( 'Y-m-d H:i' ),
            get_bloginfo( 'url' )
        );
        $ws->setCellValue( 'A' . $row, $version_text );
        $ws->getStyle( 'A' . $row . ':B' . $row )->applyFromArray( $note_style );

        // ── Sheet protection (read-only) ──────────────────────────────────────
        $ws->getProtection()
           ->setSheet( true )
           ->setPassword( 'password' )
           ->setSort( false )
           ->setAutoFilter( false );
    }
}
