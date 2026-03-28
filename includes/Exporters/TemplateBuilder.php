<?php
/**
 * Template builder for D5 Design System Helper.
 *
 * Generates downloadable Excel template files (.xlsx) that users can fill in
 * and import. Templates include:
 *  - A pre-filled Config sheet so the importer can detect the file type.
 *  - Correct column headers for each data sheet (matching the importers exactly).
 *  - An Instructions sheet (first, visible) explaining every column.
 *  - Sample data rows highlighted in blue to guide the user.
 *
 * Supported templates:
 *  - Variables template      (vars)
 *  - Presets template        (presets)
 *  - Theme Customizer template (theme_customizer)
 *
 * ## Column contracts
 *
 * ### Variables — non-color sheets (Numbers, Fonts, Images, Text, Links)
 *   A=Order, B=ID, C=Label, D=Value, E=Status, F=System
 *   (matches VarsImporter COL_ORDER=1 … COL_SYSTEM=6)
 *
 * ### Variables — Colors sheet
 *   A=Order, B=ID, C=Label, D=Value, E=Swatch, F=Status, G=Reference, H=System, I=Hidden
 *   (matches VarsImporter COL_COLOR_STATUS=6, COL_COLOR_SYSTEM=8)
 *
 * ### Presets — Module Presets sheet
 *   A=Element, B=Preset ID, C=Label, D=Version, E=Is Default, F=Order,
 *   G=Attrs (JSON), H=Style Attrs (JSON), I=Group Presets (JSON)
 *   (matches PresetsImporter: A=module_name, B=preset_id, C=name, D=version,
 *    E=isDefault, F=order[skipped], G=attrs, H=styleAttrs, I=groupPresets)
 *
 * ### Presets — Group Presets sheet
 *   A=Group Name, B=Preset ID, C=Label, D=Version, E=Module Name, F=Group ID,
 *   G=Is Default, H=Attrs (JSON), I=Style Attrs (JSON)
 *   (matches PresetsImporter: A=group_name, B=preset_id, C=name, D=version,
 *    E=moduleName, F=groupId, G=isDefault, H=attrs, I=styleAttrs)
 *
 * ### Theme Customizer — Settings sheet
 *   A=Category, B=Key, C=Value
 *   (matches ThemeCustomizerImporter: col B=key, col C=value; col A ignored)
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Exporters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color as SpreadsheetColor;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use D5DesignSystemHelper\Util\ExportUtil;

/**
 * Class TemplateBuilder
 */
class TemplateBuilder {

	/** Sample row fill — light blue tint, clearly different from real data rows. */
	private const SAMPLE_FILL = 'FFE3F2FD';

	/** Header row background (matches VarsExporter). */
	private const HEADER_BG = 'FF2C3338';
	private const HEADER_FG = 'FFFFFFFF';

	/** Sidebar note text colour (italic grey). */
	private const NOTE_COLOR = 'FF555555';

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Build and stream the Variables template.
	 */
	public function stream_vars_template(): void {
		ExportUtil::stream_xlsx( $this->build_vars_template(), 'd5dsh-template-variables.xlsx' );
	}

	/**
	 * Build and stream the Presets template.
	 */
	public function stream_presets_template(): void {
		ExportUtil::stream_xlsx( $this->build_presets_template(), 'd5dsh-template-presets.xlsx' );
	}

	/**
	 * Build and stream the Theme Customizer template.
	 */
	public function stream_theme_customizer_template(): void {
		ExportUtil::stream_xlsx( $this->build_theme_customizer_template(), 'd5dsh-template-theme-customizer.xlsx' );
	}

	// ── Variables template ────────────────────────────────────────────────────

	private function build_vars_template(): Spreadsheet {
		$ss = new Spreadsheet();
		$ss->removeSheetByIndex( 0 );

		$this->build_vars_instructions( $ss );
		$this->build_config_sheet( $ss, 'vars' );

		// Colors — 9 columns matching VarsImporter COL_COLOR_* constants.
		$this->build_colors_template_sheet( $ss );

		// Non-color sheets — 6 columns: Order, ID, Label, Value, Status, System.
		$this->build_simple_var_sheet( $ss, 'Numbers', [
			[ 1, 'gvid-example-body-size',    'Body Font Size',   '16px',  'active', 'FALSE' ],
			[ 2, 'gvid-example-section-pad',  'Section Padding',  '40px',  'active', 'FALSE' ],
		] );
		$this->build_simple_var_sheet( $ss, 'Fonts', [
			[ 1, 'gvid-example-body-font',    'Body Font',        'Inter',  'active', 'FALSE' ],
			[ 2, 'gvid-example-heading-font', 'Heading Font',     'Playfair Display', 'active', 'FALSE' ],
		] );
		$this->build_simple_var_sheet( $ss, 'Images', [
			[ 1, 'gvid-example-logo',         'Site Logo',        'https://example.com/logo.png', 'active', 'FALSE' ],
		] );
		$this->build_simple_var_sheet( $ss, 'Text', [
			[ 1, 'gvid-example-tagline',      'Site Tagline',     'Your tagline here', 'active', 'FALSE' ],
		] );
		$this->build_simple_var_sheet( $ss, 'Links', [
			[ 1, 'gvid-example-contact',      'Contact Page URL', 'https://example.com/contact', 'active', 'FALSE' ],
		] );

		$ss->setActiveSheetIndex( 0 );
		return $ss;
	}

	/**
	 * Colors sheet — 9 columns.
	 * A=Order, B=ID, C=Label, D=Value, E=Swatch(leave blank), F=Status,
	 * G=Reference(leave blank), H=System, I=Hidden
	 */
	private function build_colors_template_sheet( Spreadsheet $ss ): void {
		$ws = $ss->createSheet();
		$ws->setTitle( 'Colors' );

		$headers = [ 'Order', 'ID', 'Label', 'Value', 'Swatch', 'Status', 'Reference', 'System', 'Hidden' ];
		$this->write_header( $ws, $headers );

		$samples = [
			[ 1, 'gcid-example-primary', 'Brand Primary',    '#0055A4', '', 'active', '', 'FALSE', 'FALSE' ],
			[ 2, 'gcid-example-accent',  'Brand Accent',     '#F5A623', '', 'active', '', 'FALSE', 'FALSE' ],
			[ 3, 'gcid-example-dark',    'Dark Text',        '#1A1A1A', '', 'active', '', 'FALSE', 'FALSE' ],
			[ 4, 'gcid-example-light',   'Light Background', '#FAFAFA', '', 'active', '', 'FALSE', 'FALSE' ],
		];

		$this->write_sample_rows( $ws, $samples, count( $headers ) );

		$notes = [
			'Editable: Label, Value, Status.',
			'Value: hex (#RRGGBB), rgb(), rgba(), or $variable(gvid-xxx)$.',
			'Leave Swatch empty — auto-filled on export.',
			'Leave Reference empty — managed by the plugin.',
			'System: FALSE for user colors, TRUE for Divi system colors.',
			'Hidden: FALSE normally; TRUE for palette colors managed by Divi.',
			'Do not change the ID column.',
		];
		$this->write_instructions_sidebar( $ws, $notes, count( $headers ) + 1, count( $samples ) );
		$this->set_widths( $ws, [ 7, 28, 28, 16, 8, 10, 28, 8, 8 ] );
		$ws->setAutoFilter( 'A1:' . Coordinate::stringFromColumnIndex( count( $headers ) ) . '1' );
	}

	/**
	 * Non-color variable sheets — 6 columns.
	 * A=Order, B=ID, C=Label, D=Value, E=Status, F=System
	 */
	private function build_simple_var_sheet( Spreadsheet $ss, string $title, array $samples ): void {
		$ws = $ss->createSheet();
		$ws->setTitle( $title );

		$headers = [ 'Order', 'ID', 'Label', 'Value', 'Status', 'System' ];
		$this->write_header( $ws, $headers );
		$this->write_sample_rows( $ws, $samples, count( $headers ) );

		$notes_by_type = [
			'Numbers' => [
				'Editable: Label, Value, Status.',
				'Value: any CSS value — 16px, 1.5rem, 40px, etc.',
				'System: FALSE for user variables, TRUE for Divi system variables.',
				'Do not change the ID column.',
			],
			'Fonts' => [
				'Editable: Label, Value, Status.',
				'Value: exact font family name — Inter, Arial, Playfair Display, etc.',
				'System: FALSE for user fonts. gcid-heading/body-font are TRUE.',
				'Do not change the ID column.',
			],
			'Images' => [
				'Editable: Label, Value (URL only), Status.',
				'Value: public image URL. Base64 data URIs cannot be imported via template.',
				'Images with base64 data already on the site show "Uneditable Data" — skip those rows.',
				'System: FALSE for user images.',
				'Do not change the ID column.',
			],
			'Text' => [
				'Editable: Label, Value, Status.',
				'Value: any plain text string — tagline, phone number, label, etc.',
				'System: FALSE for user text variables.',
				'Do not change the ID column.',
			],
			'Links' => [
				'Editable: Label, Value, Status.',
				'Value: a full URL — https://example.com/contact etc.',
				'System: FALSE for user link variables.',
				'Do not change the ID column.',
			],
		];

		$notes = $notes_by_type[ $title ] ?? [
			'Editable: Label, Value, Status.',
			'Do not change the ID column.',
		];
		$this->write_instructions_sidebar( $ws, $notes, count( $headers ) + 1, count( $samples ) );
		$this->set_widths( $ws, [ 7, 28, 28, 36, 10, 8 ] );
		$ws->setAutoFilter( 'A1:F1' );
	}

	private function build_vars_instructions( Spreadsheet $ss ): void {
		$ws = $ss->createSheet();
		$ws->setTitle( 'Instructions' );

		$lines = [
			'D5 Design System Helper — Variables Import Template',
			'',
			'HOW TO USE THIS TEMPLATE',
			'1. Each data sheet (Colors, Numbers, Fonts, Images, Text, Links) has blue sample rows.',
			'2. Replace the sample rows with your own data. Delete the sample rows when done.',
			'3. Do NOT rename the sheets, change the column headers, or edit the hidden Config sheet.',
			'4. For new variables: leave the ID column blank — Divi assigns IDs automatically.',
			'5. For existing variables: use the exact ID shown in the plugin Manage tab.',
			'6. Save the file as .xlsx and import it using the Import tab.',
			'',
			'WHAT EACH COLUMN MEANS (all sheets)',
			'Order  — Display order within the type group. Any integer; used for sorting.',
			'ID     — Unique identifier.',
			'          Colors: gcid-XXXX',
			'          All others: gvid-XXXX',
			'          Leave BLANK for new entries (ID assigned by Divi on next save).',
			'          Do NOT change IDs of existing variables.',
			'Label  — Human-readable name shown in the Divi Builder. Editable.',
			'Value  — The actual value. See per-sheet guidance in the sidebar notes.',
			'Status — active | archived | inactive',
			'System — TRUE for Divi system variables (read-only in Divi). FALSE for yours.',
			'',
			'COLORS SHEET — ADDITIONAL COLUMNS',
			'Swatch    — Leave blank. Auto-generated from Value on export.',
			'Reference — Leave blank. Managed automatically for color variable references.',
			'Hidden    — TRUE for palette colors Divi manages internally. FALSE normally.',
			'',
			'IMPORT RULES',
			'- Existing variables (matched by ID) are updated with Label, Value, and Status.',
			'- New variables (blank ID) are NOT inserted via this template — use the Divi Builder.',
			'- Variables on the site but absent from the file are left untouched.',
			'- The import NEVER deletes variables.',
			'- System variables: only Value is writable; Label is managed by Divi.',
			'',
			'NEED HELP?',
			'Open the ? button in the plugin header for the full User Guide.',
			'Report issues: https://github.com/akonsta/d5-design-system-helper/issues',
		];

		$this->write_instruction_lines( $ws, $lines );
	}

	// ── Presets template ──────────────────────────────────────────────────────

	private function build_presets_template(): Spreadsheet {
		$ss = new Spreadsheet();
		$ss->removeSheetByIndex( 0 );

		$this->build_presets_instructions( $ss );
		$this->build_config_sheet( $ss, 'presets' );

		// Module Presets — columns must match PresetsImporter parse_xlsx() exactly.
		// A=Element, B=Preset ID, C=Label, D=Version, E=Is Default, F=Order,
		// G=Attrs (JSON), H=Style Attrs (JSON), I=Group Presets (JSON)
		$module_headers = [
			'Element', 'Preset ID', 'Label', 'Version', 'Is Default', 'Order',
			'Attrs (JSON)', 'Style Attrs (JSON)', 'Group Presets (JSON)',
		];
		$module_samples = [
			[
				'et_pb_button',
				'epid-example-btn',
				'Primary Button',
				'1',
				'Yes',
				1,
				'{"background_color":"#0055A4","font_icon":"%%38%%","use_icon":"on"}',
				'{}',
				'[]',
			],
			[
				'et_pb_text',
				'epid-example-text',
				'Body Text',
				'1',
				'Yes',
				1,
				'{"font_size":"16px","line_height":"1.8em"}',
				'{}',
				'[]',
			],
		];

		$module_notes = [
			'Element: Divi module name, e.g. et_pb_button, et_pb_text, et_pb_section.',
			'Preset ID: epid-XXXX. Leave blank for new presets (ID assigned by Divi).',
			'Label: human name shown in Divi. Editable.',
			'Version: leave as "1" unless you know the Divi version string.',
			'Is Default: Yes | No — only one preset per Element can be the default.',
			'Order: display order (integer).',
			'Attrs / Style Attrs: JSON objects from a Divi export. Must be valid JSON.',
			'Group Presets: JSON array of group preset IDs used by this preset.',
		];

		$ws_mod = $ss->createSheet();
		$ws_mod->setTitle( 'Module Presets' );
		$this->write_header( $ws_mod, $module_headers );
		$this->write_sample_rows( $ws_mod, $module_samples, count( $module_headers ) );
		$this->write_instructions_sidebar( $ws_mod, $module_notes, count( $module_headers ) + 1, count( $module_samples ) );
		$this->set_widths( $ws_mod, [ 22, 24, 24, 10, 12, 7, 36, 36, 36 ] );
		$ws_mod->setAutoFilter( 'A1:' . Coordinate::stringFromColumnIndex( count( $module_headers ) ) . '1' );

		// Group Presets — columns must match PresetsImporter parse_xlsx() exactly.
		// A=Group Name, B=Preset ID, C=Label, D=Version, E=Module Name, F=Group ID,
		// G=Is Default, H=Attrs (JSON), I=Style Attrs (JSON)
		$group_headers = [
			'Group Name', 'Preset ID', 'Label', 'Version', 'Module Name', 'Group ID',
			'Is Default', 'Attrs (JSON)', 'Style Attrs (JSON)',
		];
		$group_samples = [
			[
				'brand-kit',
				'epgid-example-kit',
				'Brand Kit',
				'1',
				'et_pb_button',
				'etbuilder-group-brand-kit',
				'Yes',
				'{"background_color":"#0055A4","font_size":"16px"}',
				'{}',
			],
		];

		$group_notes = [
			'Group Name: logical group identifier, e.g. brand-kit, typography-base.',
			'Preset ID: epgid-XXXX. Leave blank for new group presets.',
			'Label: human name shown in Divi.',
			'Version: leave as "1".',
			'Module Name: Divi module this group applies to, e.g. et_pb_button.',
			'Group ID: etbuilder-group-XXXX identifier string.',
			'Is Default: Yes | No.',
			'Attrs / Style Attrs: JSON objects from a Divi export.',
		];

		$ws_grp = $ss->createSheet();
		$ws_grp->setTitle( 'Group Presets' );
		$this->write_header( $ws_grp, $group_headers );
		$this->write_sample_rows( $ws_grp, $group_samples, count( $group_headers ) );
		$this->write_instructions_sidebar( $ws_grp, $group_notes, count( $group_headers ) + 1, count( $group_samples ) );
		$this->set_widths( $ws_grp, [ 22, 24, 24, 10, 22, 28, 12, 36, 36 ] );
		$ws_grp->setAutoFilter( 'A1:' . Coordinate::stringFromColumnIndex( count( $group_headers ) ) . '1' );

		$ss->setActiveSheetIndex( 0 );
		return $ss;
	}

	private function build_presets_instructions( Spreadsheet $ss ): void {
		$ws = $ss->createSheet();
		$ws->setTitle( 'Instructions' );

		$lines = [
			'D5 Design System Helper — Presets Import Template',
			'',
			'HOW TO USE THIS TEMPLATE',
			'1. This template has two data sheets: Module Presets and Group Presets.',
			'2. Replace the blue sample rows with your actual preset data.',
			'3. Do NOT rename the sheets, change the column headers, or edit the hidden Config sheet.',
			'4. Attrs columns must contain valid JSON. Get JSON from a Divi export file.',
			'5. Save as .xlsx and import it using the Import tab.',
			'',
			'WHAT EACH COLUMN MEANS — MODULE PRESETS',
			'Element        — Divi module internal name: et_pb_button, et_pb_text, et_pb_blurb, etc.',
			'Preset ID      — epid-XXXX. Leave blank for new presets (Divi assigns the ID).',
			'Label          — Human-readable preset name shown in the Divi Builder.',
			'Version        — Version string. Use "1" unless copying from a Divi export.',
			'Is Default     — Yes or No. Only one preset per Element can be the default.',
			'Order          — Display order integer.',
			'Attrs (JSON)   — JSON object of module attribute overrides. Must be valid JSON.',
			'Style Attrs    — JSON object of module style attribute overrides.',
			'Group Presets  — JSON array of group preset ID strings applied to this preset.',
			'',
			'WHAT EACH COLUMN MEANS — GROUP PRESETS',
			'Group Name     — Logical group identifier used as the grouping key.',
			'Preset ID      — epgid-XXXX. Leave blank for new group presets.',
			'Label          — Human-readable name for the group preset.',
			'Version        — Version string. Use "1" unless copying from a Divi export.',
			'Module Name    — The Divi module type this group preset applies to.',
			'Group ID       — etbuilder-group-XXXX identifier string.',
			'Is Default     — Yes or No.',
			'Attrs (JSON)   — JSON object of group attribute overrides.',
			'Style Attrs    — JSON object of group style attribute overrides.',
			'',
			'IMPORT RULES',
			'- Existing presets (matched by Preset ID) are updated.',
			'- New presets (blank Preset ID) are added when imported.',
			'- Presets on the site but absent from the file are left untouched.',
			'- The import NEVER deletes presets.',
			'',
			'TIP',
			'The easiest way to get correct JSON is to export your existing presets as .xlsx,',
			'copy the Attrs cells from that file into this template, and modify as needed.',
			'',
			'NEED HELP?',
			'Open the ? button in the plugin header for the full User Guide.',
			'Report issues: https://github.com/akonsta/d5-design-system-helper/issues',
		];

		$this->write_instruction_lines( $ws, $lines );
	}

	// ── Theme Customizer template ─────────────────────────────────────────────

	private function build_theme_customizer_template(): Spreadsheet {
		$ss = new Spreadsheet();
		$ss->removeSheetByIndex( 0 );

		$this->build_theme_customizer_instructions( $ss );
		$this->build_config_sheet( $ss, 'theme_customizer' );

		// Settings sheet — columns must match ThemeCustomizerImporter exactly.
		// A=Category (informational, not stored), B=Key, C=Value
		$headers = [ 'Category', 'Key', 'Value' ];

		$samples = [
			[ 'General', 'accent_color',                '#0055A4' ],
			[ 'General', 'secondary_accent_color',      '#F5A623' ],
			[ 'Typography', 'body_font',                'Inter||||||||' ],
			[ 'Typography', 'header_font',              'Playfair Display||||||||' ],
			[ 'Typography', 'body_font_size',           '16' ],
			[ 'Header', 'header_style',                 'centered' ],
			[ 'Layout', 'content_width',                '1080' ],
			[ 'Layout', 'gutter_width',                 '3' ],
			[ 'Custom CSS', 'custom_css',               '/* your custom CSS here */' ],
		];

		$notes = [
			'Category: informational grouping — not stored in the database.',
			'Key: exact Divi Customizer setting key. Do not change existing keys.',
			'Value: plain text for simple values; JSON for complex ones.',
			'Font values use Divi\'s pipe-delimited format: Family|style|weight|...',
			'Color values: hex string, e.g. #0055A4.',
			'Get valid keys and values by exporting Theme Customizer first.',
			'Keys absent from the site are added as new settings.',
			'Keys on the site but absent from this file are left untouched.',
		];

		$ws = $ss->createSheet();
		$ws->setTitle( 'Settings' );
		$this->write_header( $ws, $headers );
		$this->write_sample_rows( $ws, $samples, count( $headers ) );
		$this->write_instructions_sidebar( $ws, $notes, count( $headers ) + 1, count( $samples ) );
		$this->set_widths( $ws, [ 20, 36, 60 ] );
		$ws->setAutoFilter( 'A1:C1' );

		$ss->setActiveSheetIndex( 0 );
		return $ss;
	}

	private function build_theme_customizer_instructions( Spreadsheet $ss ): void {
		$ws = $ss->createSheet();
		$ws->setTitle( 'Instructions' );

		$lines = [
			'D5 Design System Helper — Theme Customizer Import Template',
			'',
			'HOW TO USE THIS TEMPLATE',
			'1. The Settings sheet has one row per Customizer setting.',
			'2. Replace the blue sample rows with your actual settings.',
			'3. Do NOT rename the sheet, change column headers, or edit the hidden Config sheet.',
			'4. Save as .xlsx and import it using the Import tab.',
			'',
			'WHAT EACH COLUMN MEANS',
			'Category — Organisational grouping label (e.g. General, Typography, Layout).',
			'           This column is NOT stored — it is for your reference only.',
			'Key      — The exact Divi Customizer option key (e.g. accent_color, body_font).',
			'Value    — The setting value.',
			'           Simple values: plain text — #0055A4, 16, centered.',
			'           Complex values: valid JSON — {"key":"value"}.',
			'           Fonts: Divi pipe-delimited format — Family|style|weight|...',
			'',
			'IMPORTANT NOTES',
			'- The safest way to get correct keys and values is to EXPORT your existing',
			'  Theme Customizer settings as .xlsx first, then copy and modify those rows.',
			'- Unknown keys will be ADDED to the database — Divi may ignore unknown keys.',
			'- Existing keys are UPDATED with the value from this file.',
			'- Keys on the site but absent from this file are LEFT UNTOUCHED.',
			'- The import NEVER deletes Theme Customizer settings.',
			'',
			'IMPORT RULES',
			'- Row 1 is the header row. Data starts at row 2.',
			'- The Category column (A) is ignored during import.',
			'- The Value column is JSON-decoded automatically if valid JSON is detected.',
			'',
			'NEED HELP?',
			'Open the ? button in the plugin header for the full User Guide.',
			'Report issues: https://github.com/akonsta/d5-design-system-helper/issues',
		];

		$this->write_instruction_lines( $ws, $lines );
	}

	// ── Config sheet ──────────────────────────────────────────────────────────

	private function build_config_sheet( Spreadsheet $ss, string $file_type ): void {
		$ws = $ss->createSheet();
		$ws->setTitle( 'Config' );
		$ws->getTabColor()->setARGB( 'FFCCCCCC' );

		$version = defined( 'D5DSH_VERSION' ) ? D5DSH_VERSION : '0.6.9.9';
		$rows    = [
			[ 'File Type',      $file_type ],
			[ 'Plugin Version', $version ],
			[ 'Export Date',    gmdate( 'Y-m-d H:i' ) . ' UTC (template)' ],
			[ 'Site URL',       '' ],
			[ 'Template',       'true' ],
		];

		foreach ( $rows as $i => $row ) {
			$ws->getCell( 'A' . ( $i + 1 ) )->setValue( $row[0] );
			$ws->getCell( 'B' . ( $i + 1 ) )->setValue( $row[1] );
		}

		$ws->getStyle( 'A1:A' . count( $rows ) )->getFont()->setBold( true );
		$ws->getColumnDimension( 'A' )->setWidth( 18 );
		$ws->getColumnDimension( 'B' )->setWidth( 40 );
		$ws->setSheetState( Worksheet::SHEETSTATE_HIDDEN );
	}

	// ── Shared helpers ────────────────────────────────────────────────────────

	/**
	 * Write an instructions sheet from an array of text lines.
	 * Bold the title (row 1) and section-header lines (ALL CAPS keywords).
	 */
	private function write_instruction_lines( Worksheet $ws, array $lines ): void {
		foreach ( $lines as $i => $line ) {
			$ws->getCell( 'A' . ( $i + 1 ) )->setValue( $line );
		}

		// Title row.
		$ws->getStyle( 'A1' )->getFont()->setBold( true )->setSize( 13 );
		$ws->getRowDimension( 1 )->setRowHeight( 22 );

		// Section headers (lines where the first word is entirely upper-case).
		$section_keywords = [ 'HOW TO USE', 'WHAT EACH', 'IMPORT RULES', 'NEED HELP', 'TIP', 'IMPORTANT', 'COLORS ', 'ADDITIONAL' ];
		foreach ( $lines as $i => $line ) {
			foreach ( $section_keywords as $kw ) {
				if ( str_starts_with( $line, $kw ) ) {
					$ws->getStyle( 'A' . ( $i + 1 ) )->getFont()->setBold( true );
				}
			}
		}

		$ws->getColumnDimension( 'A' )->setWidth( 90 );
	}

	private function write_header( Worksheet $ws, array $headers ): void {
		foreach ( $headers as $i => $label ) {
			$ws->getCell( Coordinate::stringFromColumnIndex( $i + 1 ) . '1' )->setValue( $label );
		}
		$last  = Coordinate::stringFromColumnIndex( count( $headers ) );
		$range = 'A1:' . $last . '1';
		$ws->getStyle( $range )->applyFromArray( [
			'font' => [ 'bold' => true, 'color' => [ 'argb' => self::HEADER_FG ] ],
			'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => self::HEADER_BG ] ],
		] );
		$ws->freezePane( 'A2' );
	}

	private function write_sample_rows( Worksheet $ws, array $samples, int $col_count ): void {
		foreach ( $samples as $si => $sample ) {
			$row = $si + 2;
			foreach ( $sample as $ci => $val ) {
				$ws->getCell( Coordinate::stringFromColumnIndex( $ci + 1 ) . $row )->setValue( $val );
			}
			$last = Coordinate::stringFromColumnIndex( $col_count );
			$ws->getStyle( 'A' . $row . ':' . $last . $row )->applyFromArray( [
				'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => self::SAMPLE_FILL ] ],
			] );
		}

		// Reminder below the samples.
		$note_row = count( $samples ) + 2;
		$ws->getCell( 'A' . $note_row )->setValue( '← Replace the blue sample rows above with your data. Delete this row when done.' );
		$ws->getStyle( 'A' . $note_row )->getFont()
			->setItalic( true )
			->setColor( new SpreadsheetColor( 'FF888888' ) );
	}

	private function write_instructions_sidebar( Worksheet $ws, array $notes, int $start_col, int $max_row ): void {
		$col_letter = Coordinate::stringFromColumnIndex( $start_col );
		$ws->getColumnDimension( $col_letter )->setWidth( 52 );
		foreach ( $notes as $i => $note ) {
			$row = $i + 2;
			if ( $row > $max_row + 1 ) {
				break;
			}
			$ws->getCell( Coordinate::stringFromColumnIndex( $start_col ) . $row )->setValue( '• ' . $note );
			$ws->getStyle( $col_letter . $row )->getFont()
				->setItalic( true )
				->setSize( 10 )
				->setColor( new SpreadsheetColor( self::NOTE_COLOR ) );
		}
	}

	private function set_widths( Worksheet $ws, array $widths ): void {
		foreach ( $widths as $i => $width ) {
			$ws->getColumnDimension( Coordinate::stringFromColumnIndex( $i + 1 ) )->setWidth( $width );
		}
	}
}
