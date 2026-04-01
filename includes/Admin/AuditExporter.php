<?php
/**
 * AuditExporter — builds XLSX files for Audit and Content Scan reports.
 *
 * Delegates all PhpSpreadsheet work to ExportUtil helpers so the pattern
 * stays consistent with every other exporter in the plugin.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

use D5DesignSystemHelper\Util\ExportUtil;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AuditExporter {

	// ── Tier colours (ARGB) ───────────────────────────────────────────────────
	private const COLOR_ERROR    = 'FFFDE8E8'; // light red
	private const COLOR_WARNING  = 'FFFEF9C3'; // light yellow
	private const COLOR_ADVISORY = 'FFE0F2FE'; // light blue
	private const COLOR_NOTE     = 'FFE8F5E9'; // light green — rows with a note

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Build and stream an XLSX for an audit report.
	 *
	 * @param array      $report     Audit report array (errors/warnings/advisories/meta).
	 * @param array      $notes      NotesManager::get_all() result — keyed by "var:id", "check:name", etc.
	 * @param array|null $scan_data  Optional content scan report for DSO usage cross-reference.
	 */
	public static function export_audit_xlsx( array $report, array $notes = [], ?array $scan_data = null ): never {
		$is_full = ! empty( $report['meta']['is_full'] );

		// Build a flat DSO usage index from the scan report (id => ['count' => int, 'titles' => string]).
		$dso_usage_index = self::build_dso_usage_index( $scan_data );
		$has_usage       = ! empty( $dso_usage_index );

		$tier_cols = [ 'Tier', 'Check', 'Audit Type', 'ID', 'Type', 'Label', 'Detail', 'Note', 'Tags', 'Suppressed' ];
		if ( $has_usage ) {
			$tier_cols[] = 'DSO Uses';
			$tier_cols[] = 'Used In';
		}

		// Sheet column inventory for the Info sheet (built before sheets exist — that's fine).
		$summary_cols = [ 'Run at (UTC)', 'Audit Type', 'Variables', 'Colors', 'Presets', 'Content scanned', '— (Tier summary: Tier, Checks Run, Findings, Suppressed)' ];
		if ( $is_full ) {
			$summary_cols[] = 'Variable Type Distribution chart';
		}
		$audit_sheet_columns = [
			'Summary'     => $summary_cols,
			'Errors'      => $tier_cols,
			'Warnings'    => $tier_cols,
			'Advisories'  => $tier_cols,
		];

		// Build workbook. Sheet order: Info → Instructions → [Chart (Contextual only)] → Summary → Errors → Warnings → Advisories.
		$ss = new Spreadsheet();
		$ss->removeSheetByIndex( 0 ); // remove default empty sheet

		// Sheet 1: Info
		ExportUtil::build_info_sheet( $ss, $is_full ? 'contextual-audit' : 'simple-audit', '', $audit_sheet_columns );

		// Sheet 2: Instructions
		self::write_audit_instructions_sheet( $ss, $is_full );

		// Sheet 3 (Contextual Audit only): Distribution Chart
		if ( $is_full ) {
			self::write_audit_chart_sheet( $ss, $report );
		}

		// Data sheets
		self::write_audit_summary_sheet( $ss, $report, $is_full );
		self::write_audit_tier_sheet( $ss, 'Errors',    'Error',    $report['errors']     ?? [], $notes, $dso_usage_index, $is_full );
		self::write_audit_tier_sheet( $ss, 'Warnings',  'Warning',  $report['warnings']   ?? [], $notes, $dso_usage_index, $is_full );
		self::write_audit_tier_sheet( $ss, 'Advisories','Advisory', $report['advisories'] ?? [], $notes, $dso_usage_index, $is_full );

		$ss->setActiveSheetIndex( 0 ); // open on Info sheet
		ExportUtil::stream_xlsx( $ss, 'd5dsh-audit-report.xlsx' );
	}

	/**
	 * Flatten the scan dso_usage into a simple id => [ count, titles ] map.
	 *
	 * @param array|null $scan_data Full scan report or null.
	 * @return array<string, array{count: int, titles: string}>
	 */
	private static function build_dso_usage_index( ?array $scan_data ): array {
		if ( ! is_array( $scan_data ) ) {
			return [];
		}
		$dso_usage = $scan_data['dso_usage'] ?? [];
		$index     = [];

		foreach ( [ 'variables', 'presets' ] as $group ) {
			foreach ( $dso_usage[ $group ] ?? [] as $id => $entry ) {
				$titles = implode( ', ', array_map(
					fn( $p ) => ( $p['post_title'] ?? '' ) . ' (#' . ( $p['post_id'] ?? '' ) . ')',
					$entry['posts'] ?? []
				) );
				$index[ $id ] = [
					'count'  => (int) ( $entry['count'] ?? 0 ),
					'titles' => $titles,
				];
			}
		}

		return $index;
	}

	/**
	 * Build and stream an XLSX for a content scan report.
	 *
	 * @param array $report  Scan report array (active_content/inventory/dso_usage/meta).
	 * @param array $notes   NotesManager::get_all() result.
	 */
	public static function export_scan_xlsx( array $report, array $notes = [] ): never {
		$ss = new Spreadsheet();

		// Remove the default empty "Worksheet" created by the Spreadsheet constructor.
		$ss->removeSheetByIndex( 0 );

		// Sheet order: Info → Instructions → data sheets.
		$scan_sheet_columns = [
			'Active Content'    => [ 'Post ID', 'Type', 'Status', 'Title', 'Modified', 'Vars', 'Presets', 'Tot Vars (in Presets)', 'Uniq Vars (in Presets)', 'DSO IDs', 'Note', 'Tags' ],
			'Content Inventory' => [ 'Post ID', 'Canvas', 'Type', 'Status', 'Title', 'Modified', 'Vars', 'Presets', 'Tot Vars (in Presets)', 'Uniq Vars (in Presets)', 'DSO IDs', 'Note', 'Tags' ],
			'DSO Usage'         => [ 'DSO Type', 'DSO ID', 'Label', 'Used By (count)', 'Content Items' ],
			'No-DSO Content'    => [ 'Post ID', 'Type', 'Status', 'Title', 'Modified' ],
		];
		ExportUtil::build_info_sheet( $ss, 'content-scan', '', $scan_sheet_columns );
		self::write_scan_instructions_sheet( $ss );

		self::write_scan_active_sheet( $ss, $report['active_content'] ?? [], $notes );
		self::write_scan_inventory_sheet( $ss, $report['inventory'] ?? [], $notes );
		self::write_scan_dso_usage_sheet( $ss, $report['dso_usage'] ?? [] );
		self::write_scan_no_dso_sheet( $ss, $report['no_dso_content'] ?? [] );

		$ss->setActiveSheetIndex( 0 ); // Info sheet first
		ExportUtil::stream_xlsx( $ss, 'd5dsh-scan-report.xlsx' );
	}

	/**
	 * Write the Instructions sheet customised for Content Scan exports.
	 */
	private static function write_scan_instructions_sheet( Spreadsheet $ss ): void {
		$ws = new Worksheet( $ss, 'Instructions' );
		$ss->addSheet( $ws );

		$title_style = [
			'font' => [ 'bold' => true, 'size' => 14, 'color' => [ 'argb' => 'FF1F2937' ] ],
		];
		$heading_style = [
			'font' => [ 'bold' => true, 'size' => 11, 'color' => [ 'argb' => 'FF1F2937' ] ],
			'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FFF0F4F8' ] ],
		];
		$body_style = [
			'alignment' => [
				'wrapText' => true,
				'vertical' => Alignment::VERTICAL_TOP,
			],
		];
		$note_style = [
			'font'      => [ 'italic' => true, 'size' => 10, 'color' => [ 'argb' => 'FF6B7280' ] ],
			'alignment' => [ 'wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP ],
		];

		$ws->getColumnDimension( 'A' )->setWidth( 22 );
		$ws->getColumnDimension( 'B' )->setWidth( 24 );
		$ws->getColumnDimension( 'C' )->setWidth( 72 );

		$ws->mergeCells( 'A1:C1' );
		$ws->setCellValue( 'A1', 'D5 Design System Helper — Content Scan Report' );
		$ws->getStyle( 'A1' )->applyFromArray( $title_style );
		$ws->getRowDimension( 1 )->setRowHeight( 28 );

		$ws->mergeCells( 'A2:C2' );
		$ws->setCellValue( 'A2', 'This sheet explains each worksheet in this file and defines every field. It is for reference only.' );
		$ws->getStyle( 'A2' )->applyFromArray( $note_style );
		$ws->getRowDimension( 2 )->setRowHeight( 18 );

		$sections = [
			[
				'heading' => 'What This File Contains',
				'body'    => 'This Excel file was produced by the D5 Design System Helper Content Scan. It contains a point-in-time snapshot of all scanned pages, posts, Divi Library layouts, and Theme Builder templates (all statuses, up to 1,000 items) and their relationship to your Divi 5 design system objects (DSOs — Variables and Presets). It does not contain page content, database records, or any data outside the scanned post types.',
			],
			[
				'heading' => 'Info (Sheet 1)',
				'body'    => 'Site metadata: WordPress site URL and name, export date (UTC), Divi and WordPress versions, the user who ran the scan, and a column inventory listing the sheets and their columns.',
			],
			[
				'heading' => 'Active Content (Sheet 3)',
				'body'    => 'All scanned content items that contain at least one DSO reference (variable or preset). Only items actively using the design system appear here.',
			],
			[
				'heading' => 'Content Inventory (Sheet 4)',
				'body'    => 'Every scanned content item regardless of DSO usage. Canvas posts (Theme Builder header/body/footer) are listed as indented sub-rows under their parent template.',
			],
			[
				'heading' => 'DSO Usage (Sheet 5)',
				'body'    => 'A reverse index: for each variable or preset used on the site, lists every content item that references it. Useful for understanding the blast radius of changing or deleting a specific DSO.',
			],
			[
				'heading' => 'No-DSO Content (Sheet 6)',
				'body'    => 'Content items with no variable or preset references — pages, posts, layouts, and templates not yet using the design system.',
			],
			[
				'heading' => 'Notes and Tags',
				'body'    => "Notes and Tags are user-defined annotations stored in the D5 Design System Helper plugin. They are associated with individual content items by post ID. Tags are comma-separated strings; Notes are free-form text.",
			],
			[
				'heading' => 'This File is a Point-in-Time Snapshot',
				'body'    => 'This file does not update automatically. Run a new Content Scan from the D5 Design System Helper plugin to get a fresh report. The scan processes up to 1,000 items; sites with more content will see a limit warning in the plugin UI.',
			],
		];

		$row = 4;
		foreach ( $sections as $section ) {
			$ws->mergeCells( 'A' . $row . ':C' . $row );
			$ws->setCellValue( 'A' . $row, $section['heading'] );
			$ws->getStyle( 'A' . $row . ':C' . $row )->applyFromArray( $heading_style );
			$ws->getRowDimension( $row )->setRowHeight( 20 );
			$row++;

			$ws->mergeCells( 'A' . $row . ':C' . $row );
			$ws->setCellValue( 'A' . $row, $section['body'] );
			$ws->getStyle( 'A' . $row . ':C' . $row )->applyFromArray( $body_style );
			$ws->getRowDimension( $row )->setRowHeight( 50 );
			$row++;

			$ws->getRowDimension( $row )->setRowHeight( 6 );
			$row++;
		}

		// \u2500\u2500 Field Definitions table \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
		$row++; // extra spacer

		$ws->mergeCells( 'A' . $row . ':C' . $row );
		$ws->setCellValue( 'A' . $row, 'Field Definitions' );
		$ws->getStyle( 'A' . $row . ':C' . $row )->applyFromArray( [
			'font' => [ 'bold' => true, 'size' => 12, 'color' => [ 'argb' => 'FFFFFFFF' ] ],
			'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FF1F2937' ] ],
		] );
		$ws->getRowDimension( $row )->setRowHeight( 22 );
		$row++;

		// Field definition table header
		$fd_header_style = [
			'font' => [ 'bold' => true, 'color' => [ 'argb' => 'FFFFFFFF' ] ],
			'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FF374151' ] ],
		];
		$ws->setCellValue( 'A' . $row, 'Field' );
		$ws->setCellValue( 'B' . $row, 'Sheet(s)' );
		$ws->setCellValue( 'C' . $row, 'Definition / Context' );
		$ws->getStyle( 'A' . $row . ':C' . $row )->applyFromArray( $fd_header_style );
		$ws->getRowDimension( $row )->setRowHeight( 18 );
		$row++;

		$field_defs = [
			[ 'Post ID',         'Active Content, Content Inventory, No-DSO Content', 'The WordPress internal integer ID assigned to each post, page, layout, or template. This is the primary key in the wp_posts table and is unique site-wide. It is not a Divi-specific identifier. You can navigate directly to a post in the WordPress admin using: wp-admin/post.php?post={ID}&action=edit.' ],
			[ 'Canvas',          'Content Inventory',                                  'For Theme Builder templates (post type: et_template), each template can have up to three child canvas layouts: Header, Body, and Footer. These canvas rows are indented under their parent template row. The Canvas column is blank for all non-canvas rows.' ],
			[ 'Type',            'Active Content, Content Inventory, No-DSO Content', 'The WordPress post type. Common values: page (standard WordPress page), post (blog post), et_pb_layout (Divi Library layout), et_template (Theme Builder template), et_header_layout / et_body_layout / et_footer_layout (Theme Builder canvas posts).' ],
			[ 'Status',          'Active Content, Content Inventory, No-DSO Content', 'The publication status of the content item. Values: publish (publicly visible), draft (not published), private (visible only to administrators), future (scheduled for publication), pending (awaiting review), trash (in the trash, not deleted).' ],
			[ 'Title',           'Active Content, Content Inventory, No-DSO Content', 'The WordPress post title as entered in the editor. For Theme Builder canvases this is typically blank or auto-generated by Divi.' ],
			[ 'Modified',        'Active Content, Content Inventory',                  'The date and time the content item was last saved in WordPress (post_modified field, stored in local site time). Format: YYYY-MM-DD HH:MM:SS.' ],
			[ 'Vars',            'Active Content, Content Inventory',                  'Count of variable references found in this content item\'s post_content. Each $variable(type:id)$ token in the Divi block markup is counted. The same variable referenced multiple times in one item is counted multiple times.' ],
			[ 'Presets',         'Active Content, Content Inventory',                  'Count of preset references found in this content item\'s post_content. Each modulePreset or presetId key in the Divi block JSON is counted. The same preset referenced multiple times in one item is counted multiple times.' ],
			[ 'Tot Vars',        'Active Content, Content Inventory (Vars in Presets group)', 'Total number of variable references found inside the presets used by this content item. Only counts variables embedded in preset definitions, not direct variable references in post_content (those are in the Vars column).' ],
			[ 'Uniq Vars',       'Active Content, Content Inventory (Vars in Presets group)', 'Number of distinct variables referenced inside the presets used by this content item. If two presets both reference the same variable, it counts as 1 unique variable.' ],
			[ 'DSO IDs',         'Active Content, Content Inventory',                  'A comma-separated list of all unique DSO identifiers referenced in this content item — variable IDs (e.g. gcid-abc123) and preset IDs combined. Used to quickly see which specific design system objects this content depends on.' ],
			[ 'DSO Type',        'DSO Usage',                                          'Whether this DSO is a Variable or a Preset. Variables are global design tokens (colors, spacing, typography, etc.). Presets are Element Presets or Group Presets that apply saved styles to Divi modules.' ],
			[ 'DSO ID',          'DSO Usage',                                          'The unique identifier for this Design System Object as stored in the Divi database. Variable IDs typically begin with "gcid-" (e.g. gcid-abc123). Preset IDs are typically UUIDs. These IDs are assigned by Divi and cannot be changed.' ],
			[ 'Label',           'DSO Usage',                                          'The human-readable name assigned to this DSO in the Divi design system — the name shown in the Divi editor\'s variable or preset picker. This is the "label" field for variables and the "name" field for presets.' ],
			[ 'Used By (count)', 'DSO Usage',                                          'The number of content items (pages, posts, layouts, templates) that contain at least one reference to this DSO. A content item is counted once regardless of how many times it references the same DSO.' ],
			[ 'Content Items',   'DSO Usage',                                          'A comma-separated list of content item titles and their post IDs — one entry per item that references this DSO. Format: "Title (#PostID)". Useful for impact analysis: tells you exactly which content would be affected if this DSO is changed or deleted.' ],
			[ 'Note',            'Active Content, Content Inventory',                  'A user-defined free-text annotation attached to this content item in the D5 Design System Helper plugin. Notes are stored separately from WordPress content and do not affect the live site.' ],
			[ 'Tags',            'Active Content, Content Inventory',                  'Comma-separated user-defined tags attached to this content item in the D5 Design System Helper plugin. Used to categorise or filter content items for review (e.g. "legacy", "do-not-touch", "needs-review").' ],
		];

		$fd_body_style = [
			'alignment' => [ 'wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP ],
		];
		$fd_field_style = [
			'font'      => [ 'bold' => true ],
			'alignment' => [ 'vertical' => Alignment::VERTICAL_TOP ],
		];

		foreach ( $field_defs as $i => $def ) {
			$ws->setCellValue( 'A' . $row, $def[0] );
			$ws->setCellValue( 'B' . $row, $def[1] );
			$ws->setCellValue( 'C' . $row, $def[2] );
			$ws->getStyle( 'A' . $row )->applyFromArray( $fd_field_style );
			$ws->getStyle( 'B' . $row . ':C' . $row )->applyFromArray( $fd_body_style );
			$ws->getRowDimension( $row )->setRowHeight( -1 ); // auto-height
			if ( $i % 2 === 0 ) {
				$ws->getStyle( 'A' . $row . ':C' . $row )
				   ->getFill()->setFillType( Fill::FILL_SOLID )
				   ->getStartColor()->setARGB( ExportUtil::EVEN_ROW_BG );
			}
			$row++;
		}

		// Footer note
		$ws->getRowDimension( $row )->setRowHeight( 8 );
		$row++;

		$ws->mergeCells( 'A' . $row . ':C' . $row );
		$ws->setCellValue( 'A' . $row, sprintf(
			'Generated by D5 Design System Helper v%s on %s UTC — %s',
			defined( 'D5DSH_VERSION' ) ? D5DSH_VERSION : '',
			gmdate( 'Y-m-d H:i' ),
			get_bloginfo( 'url' )
		) );
		$ws->getStyle( 'A' . $row )->applyFromArray( $note_style );
	}

	// ── Audit instructions sheet ────────────────────────────────────────────────

	private static function write_audit_instructions_sheet( Spreadsheet $ss, bool $is_full = false ): void {
		$ws = new Worksheet( $ss, 'Instructions' );
		$ss->addSheet( $ws ); // appended — Info is already at index 0
		$ss->setActiveSheetIndex( 0 );

		$audit_type = $is_full ? 'Contextual Audit' : 'Simple Audit';

		$title_style = [
			'font' => [ 'bold' => true, 'size' => 14, 'color' => [ 'argb' => 'FF1F2937' ] ],
		];
		$heading_style = [
			'font' => [ 'bold' => true, 'size' => 11, 'color' => [ 'argb' => 'FF1F2937' ] ],
			'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FFF0F4F8' ] ],
		];
		$body_style = [
			'alignment' => [ 'wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP ],
		];
		$note_style = [
			'font'      => [ 'italic' => true, 'size' => 10, 'color' => [ 'argb' => 'FF6B7280' ] ],
			'alignment' => [ 'wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP ],
		];

		$ws->getColumnDimension( 'A' )->setWidth( 24 );
		$ws->getColumnDimension( 'B' )->setWidth( 24 );
		$ws->getColumnDimension( 'C' )->setWidth( 72 );

		$ws->mergeCells( 'A1:C1' );
		$ws->setCellValue( 'A1', 'D5 Design System Helper — ' . $audit_type . ' Report' );
		$ws->getStyle( 'A1' )->applyFromArray( $title_style );
		$ws->getRowDimension( 1 )->setRowHeight( 28 );

		$ws->mergeCells( 'A2:C2' );
		$ws->setCellValue( 'A2', 'This sheet explains each worksheet in this file and defines every field. It is for reference only.' );
		$ws->getStyle( 'A2' )->applyFromArray( $note_style );
		$ws->getRowDimension( 2 )->setRowHeight( 18 );

		$chart_note = $is_full ? ' Sheet 3 contains a Variable Type Distribution chart.' : '';
		$data_sheets = $is_full ? 'Sheets 4–6' : 'Sheets 3–5';
		$summary_sheet = $is_full ? 'Sheet 4' : 'Sheet 3';

		$sections = [
			[
				'heading' => 'What This File Contains',
				'body'    => 'This Excel file was produced by the D5 Design System Helper ' . $audit_type . '. It contains a point-in-time check of all Divi 5 Global Variables and Presets (Design System Objects, or DSOs) for consistency issues. It is not a backup of your design system — use the Export function in the Vars/Presets tabs for that.',
			],
			[
				'heading' => 'Sheet Order',
				'body'    => 'Sheet 1: Info (site metadata and column inventory). Sheet 2: Instructions (this sheet).' . $chart_note . ' ' . $summary_sheet . ': Summary (counts and tier totals). ' . $data_sheets . ': Errors, Warnings, Advisories (one sheet per tier).',
			],
			[
				'heading' => 'Summary (' . $summary_sheet . ')',
				'body'    => 'High-level counts: run timestamp, audit type, variable/color/preset counts, content scanned count (' . ( $is_full ? 'Contextual Audit' : 'Simple Audit only shows 0' ) . '), and a tier summary table (Errors, Warnings, Advisories) with check counts, total items, and suppressed items. Also includes a "Vars in Presets" sub-table with the total and unique variable references across all presets.',
			],
			[
				'heading' => 'Errors / Warnings / Advisories (' . $data_sheets . ')',
				'body'    => 'One sheet per audit tier. Each row represents one DSO that triggered a check. Columns: Tier, Check (name of the audit rule), Audit Type (Simple or Contextual — indicates which audit mode produces this check), ID (unique DSO identifier), Type (variable type or "colors"), Label, Detail (explanation of the issue), Note, Tags, Suppressed.' . ( $is_full ? ' Two additional columns appear in this Contextual Audit: DSO Uses (count of content items referencing this DSO) and Used In (comma-separated list of those items).' : '' ),
			],
			[
				'heading' => 'Suppressed Items',
				'body'    => 'Items marked Suppressed = Yes have been individually suppressed in the D5 Design System Helper plugin via the Notes system. They still appear in the audit for visibility but are excluded from action counts.',
			],
			[
				'heading' => 'Notes and Tags',
				'body'    => 'Notes and Tags are user-defined annotations stored in the plugin. Notes are free-form text; Tags are comma-separated strings. Both are associated with a specific DSO by its ID.',
			],
			[
				'heading' => 'This File is a Point-in-Time Snapshot',
				'body'    => 'This file does not update automatically. Run a new audit to get a fresh report.',
			],
		];

		$row = 4;
		foreach ( $sections as $section ) {
			$ws->mergeCells( 'A' . $row . ':C' . $row );
			$ws->setCellValue( 'A' . $row, $section['heading'] );
			$ws->getStyle( 'A' . $row . ':C' . $row )->applyFromArray( $heading_style );
			$ws->getRowDimension( $row )->setRowHeight( 20 );
			$row++;

			$ws->mergeCells( 'A' . $row . ':C' . $row );
			$ws->setCellValue( 'A' . $row, $section['body'] );
			$ws->getStyle( 'A' . $row . ':C' . $row )->applyFromArray( $body_style );
			$ws->getRowDimension( $row )->setRowHeight( 50 );
			$row++;

			$ws->getRowDimension( $row )->setRowHeight( 6 );
			$row++;
		}

		// ── Field Definitions table
		$row++;

		$ws->mergeCells( 'A' . $row . ':C' . $row );
		$ws->setCellValue( 'A' . $row, 'Field Definitions' );
		$ws->getStyle( 'A' . $row . ':C' . $row )->applyFromArray( [
			'font' => [ 'bold' => true, 'size' => 12, 'color' => [ 'argb' => 'FFFFFFFF' ] ],
			'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FF1F2937' ] ],
		] );
		$ws->getRowDimension( $row )->setRowHeight( 22 );
		$row++;

		$fd_header_style = [
			'font' => [ 'bold' => true, 'color' => [ 'argb' => 'FFFFFFFF' ] ],
			'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FF374151' ] ],
		];
		$ws->setCellValue( 'A' . $row, 'Field' );
		$ws->setCellValue( 'B' . $row, 'Sheet(s)' );
		$ws->setCellValue( 'C' . $row, 'Definition / Context' );
		$ws->getStyle( 'A' . $row . ':C' . $row )->applyFromArray( $fd_header_style );
		$ws->getRowDimension( $row )->setRowHeight( 18 );
		$row++;

		$field_defs = [
			[ 'Tier',        'Errors, Warnings, Advisories', 'The severity level of this audit finding. Error = a definite problem requiring attention; Warning = a potential problem worth reviewing; Advisory = a design pattern that reduces the value of the design system but is not technically broken.' ],
			[ 'Check',       'Errors, Warnings, Advisories', 'The name of the specific audit rule that produced this row. Each check tests for a particular type of consistency issue. The check name is stable across audit runs and can be used to filter or suppress a class of findings. See the "Audit Check Names" table below for a definition of every check.' ],
			[ 'Audit Type',  'Errors, Warnings, Advisories', 'Which audit mode produces this check: "Simple" = present in both Simple Audit and Contextual Audit; "Contextual" = only produced by the Contextual Audit (requires a Content Scan).' ],
			[ 'ID',          'Errors, Warnings, Advisories', 'The unique identifier of the DSO that triggered this finding. Variable IDs begin with "gcid-" (e.g. gcid-abc123). Preset IDs are UUIDs. These IDs are assigned by Divi and cannot be changed by the user.' ],
			[ 'Type',        'Errors, Warnings, Advisories', 'The variable type category as stored in the Divi design system (e.g. colors, typography, spacing, sizing, border, shadow, custom). For preset findings this column may be blank.' ],
			[ 'Label',       'Errors, Warnings, Advisories', 'The human-readable name assigned to this DSO in the Divi editor\'s variable or preset picker. For variables this is the label field; for presets this is the name field.' ],
			[ 'Detail',      'Errors, Warnings, Advisories', 'A description of the specific issue found for this DSO. May include referenced IDs, conflicting values, or counts.' ],
			[ 'Note',        'Errors, Warnings, Advisories', 'A user-defined free-text annotation attached to this DSO in the D5 Design System Helper plugin. Notes are stored separately and do not affect the live site.' ],
			[ 'Tags',        'Errors, Warnings, Advisories', 'Comma-separated user-defined tags attached to this DSO. Used to categorise findings for review workflows (e.g. "legacy", "do-not-touch", "sprint-3").' ],
			[ 'Suppressed',  'Errors, Warnings, Advisories', 'Yes if this specific finding has been suppressed via the Notes system in the plugin. Suppressed items are excluded from action counts but remain in the export for full visibility.' ],
			[ 'DSO Uses',    'Errors, Warnings, Advisories (Contextual Audit only)', 'Count of content items (pages, posts, layouts, templates) that reference this DSO. Only populated in a Contextual Audit (which includes a Content Scan). Blank for Simple Audit.' ],
			[ 'Used In',     'Errors, Warnings, Advisories (Contextual Audit only)', 'Comma-separated list of content item titles and post IDs that reference this DSO. Format: "Title (#PostID)". Only populated for Contextual Audits. Useful for understanding the impact of fixing or removing this DSO.' ],
			[ 'Run at (UTC)', 'Summary',                      'The UTC timestamp when the audit was run. Audits are server-side operations; the time is the server\'s UTC clock at the moment the audit completed.' ],
			[ 'Audit Type',  'Summary',                      'Simple Audit or Contextual Audit. A Contextual Audit runs a Content Scan first and adds 8 additional content-aware checks.' ],
			[ 'Variables',   'Summary',                      'Total count of Global Variables defined in your Divi 5 design system at audit time (excludes colors, which are counted separately).' ],
			[ 'Colors',      'Summary',                      'Total count of Global Color variables defined in your Divi 5 design system at audit time.' ],
			[ 'Presets',     'Summary',                      'Total count of Element Presets and Group Presets defined in your Divi 5 design system at audit time.' ],
			[ 'Content scanned', 'Summary',                  'Count of pages, posts, layouts, and templates scanned during the Content Scan phase of a Contextual Audit. Zero for Simple Audit.' ],
			[ 'Total / Unique (Vars in Presets)', 'Summary', 'Total: total count of all variable references found across all preset attribute sets. Unique: count of distinct variable IDs referenced by at least one preset. Both figures are site-wide aggregates, not per-preset.' ],
		];

		$fd_body_style = [
			'alignment' => [ 'wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP ],
		];
		$fd_field_style = [
			'font'      => [ 'bold' => true ],
			'alignment' => [ 'vertical' => Alignment::VERTICAL_TOP ],
		];

		foreach ( $field_defs as $i => $def ) {
			$ws->setCellValue( 'A' . $row, $def[0] );
			$ws->setCellValue( 'B' . $row, $def[1] );
			$ws->setCellValue( 'C' . $row, $def[2] );
			$ws->getStyle( 'A' . $row )->applyFromArray( $fd_field_style );
			$ws->getStyle( 'B' . $row . ':C' . $row )->applyFromArray( $fd_body_style );
			$ws->getRowDimension( $row )->setRowHeight( -1 );
			if ( $i % 2 === 0 ) {
				$ws->getStyle( 'A' . $row . ':C' . $row )
				   ->getFill()->setFillType( Fill::FILL_SOLID )
				   ->getStartColor()->setARGB( ExportUtil::EVEN_ROW_BG );
			}
			$row++;
		}

		$ws->getRowDimension( $row )->setRowHeight( 8 );
		$row++;

		// ── Audit Check Names: Simple + Contextual ───────────────────────────
		$ws->getRowDimension( $row )->setRowHeight( 8 );
		$row++;

		$ws->mergeCells( 'A' . $row . ':C' . $row );
		$ws->setCellValue( 'A' . $row, 'Audit Check Names' );
		$ws->getStyle( 'A' . $row . ':C' . $row )->applyFromArray( [
			'font' => [ 'bold' => true, 'size' => 12, 'color' => [ 'argb' => 'FFFFFFFF' ] ],
			'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FF1F2937' ] ],
		] );
		$ws->getRowDimension( $row )->setRowHeight( 22 );
		$row++;

		// ── Simple Audit section header ──────────────────────────────────────
		$ws->mergeCells( 'A' . $row . ':C' . $row );
		$ws->setCellValue( 'A' . $row, 'Simple Audit Checks' );
		$ws->getStyle( 'A' . $row . ':C' . $row )->applyFromArray( [
			'font' => [ 'bold' => true, 'size' => 11, 'color' => [ 'argb' => 'FF1F2937' ] ],
			'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FFE8F5E9' ] ],
		] );
		$ws->getRowDimension( $row )->setRowHeight( 20 );
		$row++;

		$ws->mergeCells( 'A' . $row . ':C' . $row );
		$ws->setCellValue( 'A' . $row, 'These checks run in both Simple Audit and Contextual Audit. They analyse Global Variables and Presets without requiring a Content Scan.' );
		$ws->getStyle( 'A' . $row )->applyFromArray( $note_style );
		$ws->getRowDimension( $row )->setRowHeight( 18 );
		$row++;

		$ws->setCellValue( 'A' . $row, 'Check name' );
		$ws->setCellValue( 'B' . $row, 'Tier' );
		$ws->setCellValue( 'C' . $row, 'What it tests' );
		$ws->getStyle( 'A' . $row . ':C' . $row )->applyFromArray( $fd_header_style );
		$ws->getRowDimension( $row )->setRowHeight( 18 );
		$row++;

		$simple_check_defs = [
			[ 'broken_variable_refs',              'Error',    'Presets that reference a variable ID not defined on this site.' ],
			[ 'archived_vars_in_presets',           'Error',    'Archived variables that are still referenced by active presets.' ],
			[ 'duplicate_labels',                   'Error',    'Two or more DSOs that share the same label but have different values or types.' ],
			[ 'singleton_variables',                'Warning',  'Variables referenced by exactly one preset — possible candidates for inlining as a direct preset value.' ],
			[ 'near_duplicate_values',              'Warning',  'Variables sharing identical normalised values (e.g. two color variables both set to #ff0000) — deduplication candidates.' ],
			[ 'preset_duplicate_names',             'Warning',  'Presets of the same module type that share a name, making them ambiguous in the Divi editor dropdown.' ],
			[ 'empty_label_variables',              'Warning',  'Variables or colors with a blank or missing label — they will appear unnamed in the editor.' ],
			[ 'unnamed_presets',                    'Warning',  'Presets with a missing or blank name.' ],
			[ 'similar_variable_names',             'Warning',  'Variables whose labels normalise to the same token after removing spaces, hyphens, and underscores (e.g. "Primary Blue", "primary-blue", "PrimaryBlue" all collapse to "primaryblue"). Indicates inconsistent naming.' ],
			[ 'naming_convention_inconsistency',    'Warning',  'Variables of the same type using mixed naming styles (e.g. some use Title Case, others use kebab-case or camelCase). Flags when two or more styles are detected in a type with at least 4 labelled variables.' ],
			[ 'hardcoded_extraction_candidates',    'Advisory', 'Hardcoded hex colors appearing as literal values in 10 or more presets — candidates for extraction into a global variable.' ],
			[ 'orphaned_variables',                 'Advisory', 'Variables defined on the site but not referenced by any preset or content item.' ],
			[ 'preset_no_variable_refs',            'Advisory', 'Presets that contain no variable references — all attribute values are hardcoded inline. These presets cannot respond to global design system changes.' ],
			[ 'variable_type_distribution',         'Advisory', 'Distribution of variables by type. Flags any single type that exceeds 60% of all variables, which may indicate an unbalanced design system (e.g. hundreds of colors but no typography tokens). Always includes a summary distribution row.' ],
		];

		foreach ( $simple_check_defs as $i => $def ) {
			$ws->setCellValue( 'A' . $row, $def[0] );
			$ws->setCellValue( 'B' . $row, $def[1] );
			$ws->setCellValue( 'C' . $row, $def[2] );
			$ws->getStyle( 'A' . $row )->applyFromArray( $fd_field_style );
			$ws->getStyle( 'B' . $row . ':C' . $row )->applyFromArray( $fd_body_style );
			$ws->getRowDimension( $row )->setRowHeight( -1 );
			if ( $i % 2 === 0 ) {
				$ws->getStyle( 'A' . $row . ':C' . $row )
				   ->getFill()->setFillType( Fill::FILL_SOLID )
				   ->getStartColor()->setARGB( ExportUtil::EVEN_ROW_BG );
			}
			$row++;
		}

		// ── Contextual Audit checks section ──────────────────────────────────
		$ws->getRowDimension( $row )->setRowHeight( 10 );
		$row++;

		$ws->mergeCells( 'A' . $row . ':C' . $row );
		$ws->setCellValue( 'A' . $row, 'Contextual Audit Checks' );
		$ws->getStyle( 'A' . $row . ':C' . $row )->applyFromArray( [
			'font' => [ 'bold' => true, 'size' => 11, 'color' => [ 'argb' => 'FF1F2937' ] ],
			'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FFE0F2FE' ] ],
		] );
		$ws->getRowDimension( $row )->setRowHeight( 20 );
		$row++;

		$ws->mergeCells( 'A' . $row . ':C' . $row );
		$ws->setCellValue( 'A' . $row, 'These checks run only in the Contextual Audit. They require a Content Scan and analyse the relationship between DSOs and your published site content. They do NOT run in the Simple Audit.' );
		$ws->getStyle( 'A' . $row )->applyFromArray( $note_style );
		$ws->getRowDimension( $row )->setRowHeight( 18 );
		$row++;

		$ws->setCellValue( 'A' . $row, 'Check name' );
		$ws->setCellValue( 'B' . $row, 'Tier' );
		$ws->setCellValue( 'C' . $row, 'What it tests' );
		$ws->getStyle( 'A' . $row . ':C' . $row )->applyFromArray( $fd_header_style );
		$ws->getRowDimension( $row )->setRowHeight( 18 );
		$row++;

		$contextual_check_defs = [
			[ 'archived_dsos_in_content',        'Error',    'An archived variable or preset is directly referenced in published post content. The page will render incorrectly because the DSO is no longer active. Requires a Content Scan.' ],
			[ 'broken_dso_refs_in_content',      'Error',    'A variable or preset ID found in published post content is not defined on this site. The element will fail to load the intended style. Requires a Content Scan.' ],
			[ 'orphaned_presets',                 'Warning',  'A preset exists in the design system but is not applied in any scanned content item. It may be stale and a candidate for removal. Requires a Content Scan.' ],
			[ 'high_impact_variables',            'Warning',  'A variable is directly referenced in ' . AuditEngine::HIGH_IMPACT_THRESHOLD . ' or more content items. Changes to this variable will have widespread impact across the site. Requires a Content Scan.' ],
			[ 'preset_naming_convention',         'Warning',  'Presets for the same module type use mixed naming styles (e.g. some "Title Case", others "kebab-case"). Flags when two or more styles appear among 4+ presets for a module. Requires a Content Scan.' ],
			[ 'variables_bypassing_presets',      'Advisory', 'A variable that is also embedded inside preset definitions is being referenced directly in post content. Inline references bypass the preset system — consider applying a preset instead. Requires a Content Scan.' ],
			[ 'singleton_presets',                'Advisory', 'A preset is applied in only one content item. It may have been created for a one-off style need and may not justify its existence as a reusable design system component. Requires a Content Scan.' ],
			[ 'overlapping_presets',              'Advisory', 'Two presets for the same module type share ' . round( AuditEngine::OVERLAP_RATIO_THRESHOLD * 100 ) . '% or more of their variable references (minimum 3 shared variables). They may be near-duplicates that could be consolidated. Requires a Content Scan.' ],
		];

		foreach ( $contextual_check_defs as $i => $def ) {
			$ws->setCellValue( 'A' . $row, $def[0] );
			$ws->setCellValue( 'B' . $row, $def[1] );
			$ws->setCellValue( 'C' . $row, $def[2] );
			$ws->getStyle( 'A' . $row )->applyFromArray( $fd_field_style );
			$ws->getStyle( 'B' . $row . ':C' . $row )->applyFromArray( $fd_body_style );
			$ws->getRowDimension( $row )->setRowHeight( -1 );
			if ( $i % 2 === 0 ) {
				$ws->getStyle( 'A' . $row . ':C' . $row )
				   ->getFill()->setFillType( Fill::FILL_SOLID )
				   ->getStartColor()->setARGB( 'FFE8F4FB' ); // light blue tint for Contextual rows
			}
			$row++;
		}

		$ws->getRowDimension( $row )->setRowHeight( 8 );
		$row++;

		$ws->mergeCells( 'A' . $row . ':C' . $row );
		$ws->setCellValue( 'A' . $row, sprintf(
			'Generated by D5 Design System Helper v%s on %s UTC — %s',
			defined( 'D5DSH_VERSION' ) ? D5DSH_VERSION : '',
			gmdate( 'Y-m-d H:i' ),
			get_bloginfo( 'url' )
		) );
		$ws->getStyle( 'A' . $row )->applyFromArray( $note_style );
	}

	// ── Audit sheets ──────────────────────────────────────────────────────────

	private static function write_audit_summary_sheet( Spreadsheet $ss, array $report, bool $is_full = false ): void {
		$ws = $ss->createSheet();
		$ws->setTitle( 'Summary' );

		$meta       = $report['meta'] ?? [];
		$audit_type = $is_full ? 'Contextual Audit' : 'Simple Audit';

		// Title
		ExportUtil::write_sheet_title_row( $ws, 4, $audit_type . ' — Summary' );

		// Meta block (row 4+)
		$meta_rows = [
			[ 'Run at (UTC)',    $meta['ran_at']         ?? '' ],
			[ 'Audit Type',     $audit_type                    ],
			[ 'Variables',      $meta['variable_count']  ?? 0  ],
			[ 'Colors',         $meta['color_count']     ?? 0  ],
			[ 'Presets',        $meta['preset_count']    ?? 0  ],
			[ 'Content scanned',$meta['content_count']   ?? 0  ],
		];
		$r = 4;
		foreach ( $meta_rows as $pair ) {
			ExportUtil::cell( $ws, 1, $r )->setValue( $pair[0] );
			ExportUtil::cell( $ws, 2, $r )->setValue( $pair[1] );
			$r++;
		}
		$ws->getStyle( 'A4:A' . ( $r - 1 ) )->getFont()->setBold( true );

		// Spacer
		$r++;

		// Tier summary table
		$tier_headers = [ 'Tier', 'Checks Run', 'Findings', 'Suppressed' ];
		ExportUtil::write_header_row_at( $ws, $tier_headers, $r );
		$r++;

		$tier_map = [
			[ 'label' => 'Errors',     'key' => 'errors',     'argb' => self::COLOR_ERROR    ],
			[ 'label' => 'Warnings',   'key' => 'warnings',   'argb' => self::COLOR_WARNING  ],
			[ 'label' => 'Advisories', 'key' => 'advisories', 'argb' => self::COLOR_ADVISORY ],
		];

		foreach ( $tier_map as $tier ) {
			$checks       = $report[ $tier['key'] ] ?? [];
			$n_checks     = count( $checks );
			$n_items      = 0;
			$n_suppressed = 0;
			foreach ( $checks as $check ) {
				$items = $check['items'] ?? [];
				foreach ( $items as $item ) {
					if ( ! empty( $item['suppressed'] ) ) {
						$n_suppressed++;
					} else {
						$n_items++;
					}
				}
			}
			ExportUtil::cell( $ws, 1, $r )->setValue( $tier['label'] );
			ExportUtil::cell( $ws, 2, $r )->setValue( $n_checks );
			ExportUtil::cell( $ws, 3, $r )->setValue( $n_items );
			ExportUtil::cell( $ws, 4, $r )->setValue( $n_suppressed );
			$ws->getStyle( 'A' . $r . ':D' . $r )
			   ->getFill()->setFillType( Fill::FILL_SOLID )
			   ->getStartColor()->setARGB( $tier['argb'] );
			$r++;
		}

		// Spacer + Vars-in-Presets two-line sub-table (columns E–F)
		$vr = 4; // Start at same row as meta block to place the sub-table alongside it.

		// Row 1: merged group header "Vars in Presets"
		$ws->mergeCells( 'E' . $vr . ':F' . $vr );
		$ws->setCellValue( 'E' . $vr, 'Vars in Presets' );
		$ws->getStyle( 'E' . $vr . ':F' . $vr )
		   ->applyFromArray( [
			   'font'      => [ 'bold' => true ],
			   'alignment' => [ 'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER ],
			   'fill'      => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FFE0F2FE' ] ],
		   ] );
		$vr++;

		// Row 2: sub-column headers "Total" | "Unique"
		ExportUtil::cell( $ws, 5, $vr )->setValue( 'Total' );
		ExportUtil::cell( $ws, 6, $vr )->setValue( 'Unique' );
		$ws->getStyle( 'E' . $vr . ':F' . $vr )
		   ->applyFromArray( [
			   'font'      => [ 'bold' => true ],
			   'alignment' => [ 'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER ],
			   'fill'      => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FFF0F4F8' ] ],
		   ] );
		$vr++;

		// Row 3: values
		ExportUtil::cell( $ws, 5, $vr )->setValue( $meta['total_vars_in_presets']  ?? 0 );
		ExportUtil::cell( $ws, 6, $vr )->setValue( $meta['unique_vars_in_presets'] ?? 0 );
		$ws->getStyle( 'E' . $vr . ':F' . $vr )
		   ->getAlignment()->setHorizontal( \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER );

		ExportUtil::set_column_widths( $ws, [ 22, 12, 14, 14, 14, 14 ] );
	}

	/** Check names that only appear in the Contextual Audit. */
	private const CONTEXTUAL_CHECKS = [
		'archived_dsos_in_content',
		'broken_dso_refs_in_content',
		'orphaned_presets',
		'high_impact_variables',
		'preset_naming_convention',
		'variables_bypassing_presets',
		'singleton_presets',
		'overlapping_presets',
	];

	/**
	 * Write the Variable Type Distribution bar chart sheet (Contextual Audit only).
	 * Reads variable_type_distribution advisory data to produce a styled horizontal bar chart.
	 */
	private static function write_audit_chart_sheet( Spreadsheet $ss, array $report ): void {
		$ws = $ss->createSheet();
		$ws->setTitle( 'Var Type Distribution' );

		// Find the distribution advisory check and its summary item.
		$dist_items = [];
		foreach ( $report['advisories'] ?? [] as $check ) {
			if ( ( $check['check'] ?? '' ) === 'variable_type_distribution' ) {
				foreach ( $check['items'] ?? [] as $item ) {
					$dist_items[] = $item;
				}
			}
		}

		// Parse the "Distribution" summary item to extract type → count pairs.
		$detail_str = '';
		foreach ( $dist_items as $item ) {
			if ( ( $item['label'] ?? '' ) === 'Distribution' ) {
				$detail_str = $item['detail'] ?? '';
				break;
			}
		}

		// Parse "Variable type breakdown — Colors: 12, Numbers: 5, ... (total: 20)."
		$entries = [];
		$total   = 0;
		if ( $detail_str !== '' ) {
			// Strip everything before and including the em dash (—).
			$bare = preg_replace( '/.*\x{2014}\s*/u', '', $detail_str );
			$bare = preg_replace( '/\s*\(total:.*/', '', $bare );
			foreach ( explode( ',', $bare ) as $pair ) {
				if ( preg_match( '/^\s*(.+?):\s*(\d+)\s*$/', $pair, $m ) ) {
					$entries[] = [ 'type' => trim( $m[1] ), 'count' => (int) $m[2] ];
					$total    += (int) $m[2];
				}
			}
		}

		// Title and column layout.
		ExportUtil::write_sheet_title_row( $ws, 2, 'Variable Type Distribution' );
		$ws->getColumnDimension( 'A' )->setWidth( 16 );
		$ws->getColumnDimension( 'B' )->setWidth( 12 );
		$ws->getColumnDimension( 'C' )->setWidth( 12 );

		$hdr = 3;
		$ws->setCellValue( 'A' . $hdr, 'Type' );
		$ws->setCellValue( 'B' . $hdr, 'Count' );
		$ws->setCellValue( 'C' . $hdr, '%' );
		$ws->getStyle( 'A' . $hdr . ':C' . $hdr )->applyFromArray( [
			'font' => [ 'bold' => true, 'color' => [ 'argb' => 'FFFFFFFF' ] ],
			'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => ExportUtil::HEADER_BG ] ],
		] );

		$row = $hdr + 1;
		if ( $total === 0 || empty( $entries ) ) {
			$ws->mergeCells( 'A' . $row . ':C' . $row );
			$ws->setCellValue( 'A' . $row, 'No variable type distribution data available.' );
			return;
		}

		// Type fill colours (ARGB).
		$type_colors = [
			'Colors'  => 'FFE85353',
			'Numbers' => 'FF4B8EF1',
			'Fonts'   => 'FFF5A623',
			'Images'  => 'FF7ED321',
			'Text'    => 'FF9B59B6',
			'Links'   => 'FF1ABC9C',
		];
		$default_color = 'FF90A4AE';

		// Write data rows.
		foreach ( $entries as $i => $entry ) {
			$pct = $total > 0 ? round( $entry['count'] / $total * 100, 1 ) : 0;
			$ws->setCellValue( 'A' . $row, $entry['type'] );
			$ws->setCellValue( 'B' . $row, $entry['count'] );
			$ws->setCellValue( 'C' . $row, $pct );
			$ws->getStyle( 'C' . $row )->getNumberFormat()->setFormatCode( '0.0"%"' );
			$ws->getStyle( 'A' . $row )->getFont()->setBold( true );
			$ws->getStyle( 'A' . $row . ':C' . $row )
			   ->getFill()->setFillType( Fill::FILL_SOLID )
			   ->getStartColor()->setARGB( $i % 2 === 0 ? ExportUtil::EVEN_ROW_BG : 'FFFFFFFF' );
			$row++;
		}

		// Total row.
		$ws->setCellValue( 'A' . $row, 'Total' );
		$ws->setCellValue( 'B' . $row, $total );
		$ws->getStyle( 'A' . $row . ':C' . $row )->applyFromArray( [
			'font' => [ 'bold' => true ],
			'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FFF0F4F8' ] ],
		] );

		// Build a horizontal bar chart with per-bar colors and value+percent labels.
		$data_start = $hdr + 1;
		$data_end   = $row - 1; // last data row (before Total)
		$sheet_name = 'Var Type Distribution';

		// Per-point colors — one hex string per entry, matching the UI palette.
		$point_colors = [];
		foreach ( $entries as $entry ) {
			$argb = $type_colors[ $entry['type'] ] ?? $default_color;
			$point_colors[] = substr( $argb, 2 ); // strip FF alpha prefix → 6-char hex
		}

		$cats   = [ new \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues(
			'String', "'{$sheet_name}'!\$A\${$data_start}:\$A\${$data_end}", null, count( $entries )
		) ];
		$values = [ new \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues(
			'Number', "'{$sheet_name}'!\$B\${$data_start}:\$B\${$data_end}", null, count( $entries )
		) ];
		$values[0]->setFillColor( $point_colors );

		$series = new \PhpOffice\PhpSpreadsheet\Chart\DataSeries(
			\PhpOffice\PhpSpreadsheet\Chart\DataSeries::TYPE_BARCHART,
			\PhpOffice\PhpSpreadsheet\Chart\DataSeries::GROUPING_STANDARD,
			range( 0, count( $values ) - 1 ),
			[],
			$cats,
			$values
		);
		$series->setPlotDirection( \PhpOffice\PhpSpreadsheet\Chart\DataSeries::DIRECTION_HORIZONTAL );

		// Data labels: show value and percentage at end of each bar.
		$layout = new \PhpOffice\PhpSpreadsheet\Chart\Layout();
		$layout->setShowVal( true );
		$layout->setShowPercent( true );

		$plotArea = new \PhpOffice\PhpSpreadsheet\Chart\PlotArea( $layout, [ $series ] );
		$title    = new \PhpOffice\PhpSpreadsheet\Chart\Title( 'Variable Distribution (' . $total . ' total)' );

		$chart = new \PhpOffice\PhpSpreadsheet\Chart\Chart(
			'varTypeDist',
			$title,
			null,       // no legend needed — category labels are on the axis
			$plotArea
		);
		$chart->setTopLeftPosition( 'A14' );
		$chart->setBottomRightPosition( 'H' . max( $row + 14, 28 ) );

		$ws->addChart( $chart );
	}

	private static function write_audit_tier_sheet(
		Spreadsheet $ss,
		string $sheet_title,
		string $tier_label,
		array $checks,
		array $notes,
		array $dso_usage_index = [],
		bool $is_full = false
	): void {
		$ws        = $ss->createSheet();
		$ws->setTitle( $sheet_title );
		$has_usage = ! empty( $dso_usage_index );

		// Tier | Check | Audit Type | ID | Type | Label | Detail | Note | Tags | Suppressed [| DSO Uses | Used In]
		$headers = [ 'Tier', 'Check', 'Audit Type', 'ID', 'Type', 'Label', 'Detail', 'Note', 'Tags', 'Suppressed' ];
		if ( $has_usage ) {
			$headers[] = 'DSO Uses';
			$headers[] = 'Used In';
		}
		ExportUtil::write_sheet_title_row( $ws, count( $headers ), $sheet_title );
		ExportUtil::write_header_row_at( $ws, $headers, 3, true );

		$row = 4;
		foreach ( $checks as $check ) {
			$check_name = $check['check'] ?? '';
			$items      = $check['items'] ?? [];
			$audit_type = in_array( $check_name, self::CONTEXTUAL_CHECKS, true ) ? 'Contextual' : 'Simple';

			// Check-level note / suppression
			$check_note_data = $notes[ 'check:' . $check_name ] ?? [];

			foreach ( $items as $item ) {
				$item_id = $item['id'] ?? '';

				// Item-level note — try var: and preset: prefixes
				$item_note_data = $notes[ 'var:' . $item_id ]
				               ?? $notes[ 'preset:' . $item_id ]
				               ?? [];

				$note_text  = $item_note_data['note'] ?? ( $check_note_data['note'] ?? '' );
				$tags       = implode( ', ', $item_note_data['tags'] ?? ( $check_note_data['tags'] ?? [] ) );
				$suppressed = ! empty( $item['suppressed'] ) ? 'Yes' : 'No';

				ExportUtil::cell( $ws, 1, $row )->setValue( $tier_label );
				ExportUtil::cell( $ws, 2, $row )->setValue( $check_name );
				ExportUtil::cell( $ws, 3, $row )->setValue( $audit_type );
				ExportUtil::cell( $ws, 4, $row )->setValue( $item_id );
				ExportUtil::cell( $ws, 5, $row )->setValue( $item['var_type'] ?? '' );
				ExportUtil::cell( $ws, 6, $row )->setValue( $item['label']  ?? '' );
				ExportUtil::cell( $ws, 7, $row )->setValue( $item['detail'] ?? '' );
				ExportUtil::cell( $ws, 8, $row )->setValue( $note_text );
				ExportUtil::cell( $ws, 9, $row )->setValue( $tags );
				ExportUtil::cell( $ws, 10, $row )->setValue( $suppressed );

				if ( $has_usage ) {
					// Look up the first ID in the item (comma-separated for multi-ID items).
					$first_id    = trim( explode( ',', $item_id )[0] );
					$usage_entry = $dso_usage_index[ $first_id ] ?? null;
					ExportUtil::cell( $ws, 11, $row )->setValue( $usage_entry ? $usage_entry['count'] : '' );
					ExportUtil::cell( $ws, 12, $row )->setValue( $usage_entry ? $usage_entry['titles'] : '' );
				}

				// Row background: Contextual-only check gets light-blue tint; note tint takes priority.
				$last_col = $has_usage ? 'L' : 'J';
				if ( $note_text !== '' ) {
					$argb = self::COLOR_NOTE;
				} elseif ( $suppressed === 'Yes' ) {
					$argb = 'FFE8E8E8';
				} elseif ( $audit_type === 'Contextual' ) {
					$argb = ( $row % 2 === 0 ) ? 'FFE8F4FB' : 'FFF0F9FF'; // light blue tint for Contextual rows
				} else {
					$argb = ( $row % 2 === 0 ) ? ExportUtil::EVEN_ROW_BG : 'FFFFFFFF';
				}
				$ws->getStyle( 'A' . $row . ':' . $last_col . $row )
				   ->getFill()->setFillType( \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID )
				   ->getStartColor()->setARGB( $argb );

				$row++;
			}
		}

		// Wrap Detail (col G), Note (col H), and Used In (col L when present)
		if ( $row > 4 ) {
			$ws->getStyle( 'G4:H' . ( $row - 1 ) )->getAlignment()->setWrapText( true );
			if ( $has_usage ) {
				$ws->getStyle( 'L4:L' . ( $row - 1 ) )->getAlignment()->setWrapText( true );
			}
		}

		// Tier | Check | Audit Type | ID | Type | Label | Detail | Note | Tags | Suppressed [| DSO Uses | Used In]
		$widths = [ 12, 32, 14, 22, 14, 28, 60, 40, 24, 12 ];
		if ( $has_usage ) {
			$widths[] = 10; // DSO Uses
			$widths[] = 60; // Used In
		}
		ExportUtil::apply_sheet_formatting( $ws, max( 4, $row - 1 ), count( $headers ) );
		ExportUtil::set_column_widths( $ws, $widths );
	}

	// ── Scan sheets ───────────────────────────────────────────────────────────

	private static function write_scan_active_sheet( Spreadsheet $ss, array $active_content, array $notes ): void {
		$ws = $ss->createSheet();
		$ws->setTitle( 'Active Content' );

		// Columns: Post ID(A), Type(B), Status(C), Title(D), Modified(E),
		//          Vars(F), Presets(G), [group: Vars in Presets] Tot Vars(H), Uniq Vars(I),
		//          DSO IDs(J), Note(K), Tags(L)
		$col_count = 12;
		ExportUtil::write_sheet_title_row( $ws, $col_count, 'Active Content' );

		// Row 3: flat headers A–G + merged group label H–I + flat J–L
		$flat_headers = [ 'Post ID', 'Type', 'Status', 'Title', 'Modified', 'Vars', 'Presets' ];
		ExportUtil::write_header_row_at( $ws, $flat_headers, 3, true );

		$ws->mergeCells( 'H3:I3' );
		$ws->setCellValue( 'H3', 'Vars in Presets' );
		$ws->getStyle( 'H3:I3' )->applyFromArray( [
			'font'      => [ 'bold' => true, 'color' => [ 'argb' => 'FFFFFFFF' ] ],
			'fill'      => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FF1F2937' ] ],
			'alignment' => [ 'horizontal' => Alignment::HORIZONTAL_CENTER ],
		] );

		ExportUtil::cell( $ws, 10, 3 )->setValue( 'DSO IDs' );
		ExportUtil::cell( $ws, 11, 3 )->setValue( 'Note' );
		ExportUtil::cell( $ws, 12, 3 )->setValue( 'Tags' );
		$ws->getStyle( 'J3:L3' )->applyFromArray( [
			'font' => [ 'bold' => true, 'color' => [ 'argb' => 'FFFFFFFF' ] ],
			'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FF1F2937' ] ],
		] );

		// Row 4: sub-headers for all columns
		$sub_headers = [ 'Post ID', 'Type', 'Status', 'Title', 'Modified', 'Vars', 'Presets', 'Tot Vars', 'Uniq Vars', 'DSO IDs', 'Note', 'Tags' ];
		ExportUtil::write_header_row_at( $ws, $sub_headers, 4, true );

		$row = 5;
		foreach ( $active_content['by_type'] ?? [] as $post_type => $type_rows ) {
			foreach ( $type_rows as $r ) {
				self::write_scan_content_row( $ws, $row, $r, $notes );
				$row++;
			}
		}

		ExportUtil::apply_sheet_formatting( $ws, max( 5, $row - 1 ), $col_count );
		// Post ID(A=10), Type(B=18), Status(C=12), Title(D=40), Modified(E=20),
		// Vars(F=10), Presets(G=12), Tot Vars(H=14), Uniq Vars(I=14), DSO IDs(J=60), Note(K=40), Tags(L=24)
		ExportUtil::set_column_widths( $ws, [ 10, 18, 12, 40, 20, 10, 12, 14, 14, 60, 40, 24 ] );
		$ws->getStyle( 'J5:K' . max( 5, $row - 1 ) )->getAlignment()->setWrapText( true );
	}

	private static function write_scan_inventory_sheet( Spreadsheet $ss, array $inventory, array $notes ): void {
		$ws = $ss->createSheet();
		$ws->setTitle( 'Content Inventory' );

		// Columns: Post ID(A), Canvas(B), Type(C), Status(D), Title(E), Modified(F),
		//          Vars(G), Presets(H), [group header: Vars in Presets] Tot Vars(I), Uniq Vars(J),
		//          DSO IDs(K), Note(L), Tags(M)
		$col_count = 13;
		ExportUtil::write_sheet_title_row( $ws, $col_count, 'Content Inventory' );

		// Row 3: flat headers for cols A–H and K–M; merged group label for I–J
		$flat_headers = [ 'Post ID', 'Canvas', 'Type', 'Status', 'Title', 'Modified', 'Vars', 'Presets' ];
		ExportUtil::write_header_row_at( $ws, $flat_headers, 3, true );

		// Merged group header "Vars in Presets" spanning I3:J3
		$ws->mergeCells( 'I3:J3' );
		$ws->setCellValue( 'I3', 'Vars in Presets' );
		$ws->getStyle( 'I3:J3' )->applyFromArray( [
			'font'      => [ 'bold' => true, 'color' => [ 'argb' => 'FFFFFFFF' ] ],
			'fill'      => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FF1F2937' ] ],
			'alignment' => [ 'horizontal' => Alignment::HORIZONTAL_CENTER ],
		] );

		// Remaining flat headers K–M on row 3
		ExportUtil::cell( $ws, 11, 3 )->setValue( 'DSO IDs' );
		ExportUtil::cell( $ws, 12, 3 )->setValue( 'Note' );
		ExportUtil::cell( $ws, 13, 3 )->setValue( 'Tags' );
		$ws->getStyle( 'K3:M3' )->applyFromArray( [
			'font' => [ 'bold' => true, 'color' => [ 'argb' => 'FFFFFFFF' ] ],
			'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => 'FF1F2937' ] ],
		] );

		// Row 4: sub-column headers for the grouped pair (I4: Tot Vars, J4: Uniq Vars)
		// plus blank/repeat labels for A–H, K–M so filters and formatting work correctly.
		$sub_headers = [ 'Post ID', 'Canvas', 'Type', 'Status', 'Title', 'Modified', 'Vars', 'Presets', 'Tot Vars', 'Uniq Vars', 'DSO IDs', 'Note', 'Tags' ];
		ExportUtil::write_header_row_at( $ws, $sub_headers, 4, true );

		$row = 5;
		foreach ( $inventory['rows'] ?? [] as $r ) {
			// Main row
			self::write_scan_inventory_row( $ws, $row, $r, '', $notes );
			$row++;

			// Canvas sub-rows (et_template header/body/footer)
			foreach ( $r['canvases'] ?? [] as $canvas ) {
				self::write_scan_inventory_row( $ws, $row, $canvas, $canvas['canvas_label'] ?? '', $notes );
				// Indent canvas rows with light grey
				$ws->getStyle( 'A' . $row . ':M' . $row )
				   ->getFill()->setFillType( Fill::FILL_SOLID )
				   ->getStartColor()->setARGB( 'FFF3F4F6' );
				$row++;
			}
		}

		ExportUtil::apply_sheet_formatting( $ws, max( 5, $row - 1 ), $col_count );
		// Post ID(A=10), Canvas(B=10), Type(C=18), Status(D=12), Title(E=40), Modified(F=20),
		// Vars(G=10), Presets(H=12), Tot Vars(I=14), Uniq Vars(J=14), DSO IDs(K=60), Note(L=40), Tags(M=24)
		ExportUtil::set_column_widths( $ws, [ 10, 10, 18, 12, 40, 20, 10, 12, 14, 14, 60, 40, 24 ] );
		$ws->getStyle( 'K5:L' . max( 5, $row - 1 ) )->getAlignment()->setWrapText( true );
	}

	private static function write_scan_dso_usage_sheet( Spreadsheet $ss, array $dso_usage ): void {
		$ws = $ss->createSheet();
		$ws->setTitle( 'DSO Usage' );

		// Columns: DSO Type | DSO ID | Label | Used By (count) | Content Items
		$headers = [ 'DSO Type', 'DSO ID', 'Label', 'Used By (count)', 'Content Items' ];
		ExportUtil::write_sheet_title_row( $ws, count( $headers ), 'DSO Usage Index' );
		ExportUtil::write_header_row_at( $ws, $headers, 3, true );

		$row = 4;

		foreach ( [ 'variables', 'presets' ] as $key ) {
			foreach ( $dso_usage[ $key ] ?? [] as $dso_id => $usage ) {
				$titles = array_map(
					fn( $p ) => ( $p['post_title'] ?? '' ) . ' (#' . ( $p['post_id'] ?? '' ) . ')',
					$usage['posts'] ?? []
				);
				ExportUtil::cell( $ws, 1, $row )->setValue( $usage['dso_type'] ?? ( $key === 'variables' ? 'Variable' : 'Preset' ) );
				ExportUtil::cell( $ws, 2, $row )->setValue( $dso_id );
				ExportUtil::cell( $ws, 3, $row )->setValue( $usage['label'] ?? '' );
				ExportUtil::cell( $ws, 4, $row )->setValue( $usage['count'] ?? 0 );
				ExportUtil::cell( $ws, 5, $row )->setValue( implode( ', ', $titles ) );
				$row++;
			}
		}

		ExportUtil::apply_sheet_formatting( $ws, max( 4, $row - 1 ), count( $headers ) );
		ExportUtil::set_column_widths( $ws, [ 12, 28, 40, 16, 80 ] );
		$ws->getStyle( 'C4:C' . max( 4, $row - 1 ) )->getAlignment()->setWrapText( true );
		$ws->getStyle( 'E4:E' . max( 4, $row - 1 ) )->getAlignment()->setWrapText( true );
	}

	private static function write_scan_no_dso_sheet( Spreadsheet $ss, array $no_dso_content ): void {
		$ws = $ss->createSheet();
		$ws->setTitle( 'No-DSO Content' );

		$headers = [ 'Post ID', 'Type', 'Status', 'Title', 'Modified' ];
		ExportUtil::write_sheet_title_row( $ws, count( $headers ), 'Content Without DSO References' );
		ExportUtil::write_header_row_at( $ws, $headers, 3, true );

		$row = 4;
		foreach ( $no_dso_content['by_type'] ?? [] as $post_type => $type_rows ) {
			foreach ( $type_rows as $r ) {
				ExportUtil::cell( $ws, 1, $row )->setValue( $r['post_id']      ?? '' );
				ExportUtil::cell( $ws, 2, $row )->setValue( $r['post_type']    ?? '' );
				ExportUtil::cell( $ws, 3, $row )->setValue( $r['post_status']  ?? '' );
				ExportUtil::cell( $ws, 4, $row )->setValue( $r['post_title']   ?? '' );
				ExportUtil::cell( $ws, 5, $row )->setValue( $r['post_modified'] ?? '' );

				if ( $row % 2 === 0 ) {
					$ws->getStyle( 'A' . $row . ':E' . $row )
					   ->getFill()->setFillType( Fill::FILL_SOLID )
					   ->getStartColor()->setARGB( ExportUtil::EVEN_ROW_BG );
				}
				$row++;
			}
		}

		ExportUtil::apply_sheet_formatting( $ws, max( 4, $row - 1 ), count( $headers ) );
		ExportUtil::set_column_widths( $ws, [ 10, 18, 12, 60, 20 ] );
	}

	// ── Row helpers ───────────────────────────────────────────────────────────

	/**
	 * Write one Active Content row (12 columns, no Canvas column).
	 *
	 * Columns: Post ID | Type | Status | Title | Modified |
	 *          Vars | Presets | Tot Vars (Vars in Presets) | Uniq Vars (Vars in Presets) |
	 *          DSO IDs | Note | Tags
	 */
	private static function write_scan_content_row( $ws, int $row, array $r, array $notes ): void {
		$post_id    = $r['post_id'] ?? '';
		$note_data  = $notes[ 'post:' . $post_id ] ?? [];
		$dso_ids    = self::collect_dso_ids( $r );
		$var_refs   = $r['var_refs'] ?? [];

		ExportUtil::cell( $ws, 1, $row )->setValue( $post_id );
		ExportUtil::cell( $ws, 2, $row )->setValue( $r['post_type']     ?? '' );
		ExportUtil::cell( $ws, 3, $row )->setValue( $r['post_status']   ?? '' );
		ExportUtil::cell( $ws, 4, $row )->setValue( $r['post_title']    ?? '' );
		ExportUtil::cell( $ws, 5, $row )->setValue( $r['post_modified'] ?? '' );
		ExportUtil::cell( $ws, 6, $row )->setValue( count( $var_refs ) );
		ExportUtil::cell( $ws, 7, $row )->setValue( count( $r['preset_refs'] ?? [] ) );
		ExportUtil::cell( $ws, 8, $row )->setValue( (int) ( $r['tot_vars_in_presets']  ?? 0 ) );
		ExportUtil::cell( $ws, 9, $row )->setValue( (int) ( $r['uniq_vars_in_presets'] ?? 0 ) );
		ExportUtil::cell( $ws, 10, $row )->setValue( $dso_ids );
		ExportUtil::cell( $ws, 11, $row )->setValue( $note_data['note'] ?? '' );
		ExportUtil::cell( $ws, 12, $row )->setValue( implode( ', ', $note_data['tags'] ?? [] ) );

		if ( ! empty( $note_data['note'] ) ) {
			$ws->getStyle( 'A' . $row . ':L' . $row )
			   ->getFill()->setFillType( Fill::FILL_SOLID )
			   ->getStartColor()->setARGB( self::COLOR_NOTE );
		}
	}

	/**
	 * Write one Inventory row (13 columns, includes Canvas + Var Type columns).
	 *
	 * Columns: Post ID | Canvas | Type | Status | Title | Modified |
	 *          Var Refs | Preset Refs | Tot Var Types | Uniq Var Types |
	 *          DSO IDs | Note | Tags
	 */
	private static function write_scan_inventory_row( $ws, int $row, array $r, string $canvas_label, array $notes ): void {
		$post_id   = $r['post_id'] ?? '';
		$note_data = $notes[ 'post:' . $post_id ] ?? [];
		$dso_ids   = self::collect_dso_ids( $r );
		$var_refs  = $r['var_refs'] ?? [];

		ExportUtil::cell( $ws, 1, $row )->setValue( $post_id );
		ExportUtil::cell( $ws, 2, $row )->setValue( $canvas_label );
		ExportUtil::cell( $ws, 3, $row )->setValue( $r['post_type']     ?? '' );
		ExportUtil::cell( $ws, 4, $row )->setValue( $r['post_status']   ?? '' );
		ExportUtil::cell( $ws, 5, $row )->setValue( $r['post_title']    ?? '' );
		ExportUtil::cell( $ws, 6, $row )->setValue( $r['post_modified'] ?? '' );
		ExportUtil::cell( $ws, 7, $row )->setValue( count( $var_refs ) );
		ExportUtil::cell( $ws, 8, $row )->setValue( count( $r['preset_refs'] ?? [] ) );
		ExportUtil::cell( $ws, 9, $row )->setValue( (int) ( $r['tot_vars_in_presets']  ?? 0 ) );
		ExportUtil::cell( $ws, 10, $row )->setValue( (int) ( $r['uniq_vars_in_presets'] ?? 0 ) );
		ExportUtil::cell( $ws, 11, $row )->setValue( $dso_ids );
		ExportUtil::cell( $ws, 12, $row )->setValue( $note_data['note'] ?? '' );
		ExportUtil::cell( $ws, 13, $row )->setValue( implode( ', ', $note_data['tags'] ?? [] ) );

		if ( ! empty( $note_data['note'] ) ) {
			$ws->getStyle( 'A' . $row . ':M' . $row )
			   ->getFill()->setFillType( Fill::FILL_SOLID )
			   ->getStartColor()->setARGB( self::COLOR_NOTE );
		}
	}

	/**
	 * Build a comma-separated string of all DSO IDs referenced in a scan row.
	 */
	private static function collect_dso_ids( array $r ): string {
		$ids = [];
		foreach ( $r['var_refs'] ?? [] as $ref ) {
			$name = $ref['name'] ?? '';
			if ( $name !== '' ) { $ids[] = $name; }
		}
		foreach ( $r['preset_refs'] ?? [] as $ref ) {
			if ( $ref !== '' ) { $ids[] = $ref; }
		}
		return implode( ', ', array_unique( $ids ) );
	}
}
