<?php
/**
 * Help Manager — parses PLUGIN_USER_GUIDE.md and serves it as HTML via AJAX.
 *
 * ## Architecture
 *
 * - Source: PLUGIN_USER_GUIDE.md in the plugin root (Markdown, parsed with parsedown-extra)
 * - Cache: WP transient `d5dsh_help_html_{version}` (auto-invalidated on plugin update)
 * - Delivery: wp_ajax_d5dsh_help_content  (returns full HTML string)
 *             wp_ajax_d5dsh_help_index    (returns JSON array for Fuse.js search)
 * - WP native help tabs: registered per-tab via add_help_tab()
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

use ParsedownExtra;

class HelpManager {

	/** Transient key prefix — version appended at runtime. */
	const TRANSIENT_HTML  = 'd5dsh_help_html_';
	const TRANSIENT_INDEX = 'd5dsh_help_idx_';

	/** AJAX action names. */
	const AJAX_CONTENT = 'd5dsh_help_content';
	const AJAX_INDEX   = 'd5dsh_help_index';

	/**
	 * Register AJAX endpoints.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_CONTENT, [ $this, 'ajax_content' ] );
		add_action( 'wp_ajax_' . self::AJAX_INDEX,   [ $this, 'ajax_index' ] );
	}

	/**
	 * Register WP native help tabs on the plugin admin page.
	 * Must be called on the load-{hook} action (after add_menu_page resolves).
	 */
	public function register_help_tabs(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$guide_link = '<a href="#" data-help-anchor="%s">Open full guide &rarr;</a>';

		$tabs = [
			'manage' => [
				'title'   => 'Manage Tab',
				'content' => '<p><strong>Manage</strong> shows a live table of your design system. Use the section switcher to view Variables, Group Presets, Element Presets, All Presets, Everything, or <strong>Categories</strong>.</p>'
					. '<ul>'
					. '<li>Click a column header to sort. Use dropdown filters to narrow by type or status.</li>'
					. '<li><em>(Beta)</em> Click any <strong>Label</strong> or <strong>Value</strong> cell for a non-system, non-color variable to edit it inline. The Status column is an editable dropdown. Colors and Images are not inline-editable. A Save/Discard bar appears above the table when you have unsaved changes.</li>'
					. '<li>Use the <strong>i</strong> (Impact) button on any variable or preset row to open the Impact modal — shows which content items would break and a dependency tree.</li>'
					. '<li>Use <strong>Export CSV</strong> or <strong>Export Excel</strong> to download the current filtered view.</li>'
					. '<li><strong>Bulk Label Change</strong> mode lets you add prefixes/suffixes, find &amp; replace, or normalize case across many labels at once.</li>'
					. '<li><strong>Merge Variables</strong> mode lets you replace all preset references to one variable with another, then archive the retired variable. A preview shows affected presets before you confirm.</li>'
					. '<li><strong>Categories</strong> section: create color-coded categories and assign variables and presets to them for grouped exports and style guide filtering.</li>'
					. '</ul>'
					. '<p>' . sprintf( $guide_link, '4-managing-variables-and-presets-manage-tab' ) . '</p>',
			],
			'export' => [
				'title'   => 'Export Tab',
				'content' => '<p><strong>Export</strong> lets you download your design system data in three formats.</p>'
					. '<ul>'
					. '<li>Tick one or more data types from the tree and click <strong>Export Selected</strong>.</li>'
					. '<li>Single type → one file. Multiple types → .zip archive.</li>'
					. '<li><strong>Excel (.xlsx)</strong> — for editing and re-importing. Variables and Presets only.</li>'
					. '<li><strong>JSON</strong> — Divi-native format for all types, including Layouts, Pages, Builder Templates, and Theme Customizer.</li>'
					. '<li><strong>DTCG (design-tokens.json)</strong> — W3C standard format for Figma Tokens Studio, Style Dictionary, and other design tools.</li>'
					. '</ul>'
					. '<p>' . sprintf( $guide_link, '5-exporting-to-excel' ) . '</p>',
			],
			'import' => [
				'title'   => 'Import Tab',
				'content' => '<p><strong>Import</strong> reads .xlsx, .json, .zip, and design-tokens.json (DTCG) files.</p>'
					. '<ul>'
					. '<li>Drag-and-drop or browse to upload. File type is detected automatically.</li>'
					. '<li>DTCG files are detected by <code>$schema</code> or by the presence of DTCG token groups with <code>$value</code> entries.</li>'
					. '<li><strong>Preliminary Analysis</strong> shows new items, updates, and dependency warnings before you commit.</li>'
					. '<li><strong>Edit Labels</strong> — after analysis, each vars/presets file card shows an "Edit Labels" collapsible panel. Rename individual DSOs inline or apply bulk operations (prefix, suffix, find &amp; replace, normalize case) before import. Changes are applied server-side and never modify the original file.</li>'
					. '<li>Import is <strong>non-destructive</strong> — items absent from the file are left untouched.</li>'
					. '<li>A snapshot is taken automatically before every import.</li>'
					. '<li>Use <strong>Convert to Excel</strong> on any JSON file card to open it as a spreadsheet.</li>'
					. '<li><strong>Import sanitization</strong> — every value in every imported file (Excel, JSON, DTCG, zip) is sanitized before writing to the database. Labels, IDs, post content, meta values, and nested data structures are all cleaned. If any value is modified during sanitization, a yellow <strong>Sanitization Report</strong> appears in the results modal showing exactly what was changed and why.</li>'
					. '</ul>'
					. '<p>' . sprintf( $guide_link, '21-import-security-and-sanitization' ) . '</p>',
			],
			'snapshots' => [
				'title'   => 'Snapshots Tab',
				'content' => '<p><em>This tab requires Beta Preview to be enabled in Settings.</em></p>'
					. '<p><strong>Snapshots</strong> are saved automatically before every import and direct edit. Up to 10 snapshots are kept per data type.</p>'
					. '<ul>'
					. '<li>Use <strong>Restore</strong> to roll back to any previous state. A new snapshot is taken before restoring, so the restore is itself reversible.</li>'
					. '<li>Use <strong>Undo Last Import</strong> to restore the most recent pre-import snapshot for a type in one click.</li>'
					. '<li>Use <strong>Delete</strong> to permanently remove a snapshot.</li>'
					. '</ul>'
					. '<p>' . sprintf( $guide_link, '11-snapshots-tab' ) . '</p>',
			],
			'audit' => [
				'title'   => 'Analysis Tab',
				'content' => '<p><em>This tab requires Beta Preview to be enabled in Settings.</em></p>'
					. '<p>The <strong>Analysis</strong> tab has two sub-sections: <strong>Analysis</strong> and <strong>Content Scan</strong>.</p>'
					. '<p><strong>Analysis</strong> runs a health check on your design system. Choose <strong>Simple Audit</strong> (variables and presets only — no content scan required) or <strong>Contextual Audit</strong> (adds a content scan and 8 additional content-aware checks). Export results (whole report or per tier) as Excel, CSV, or Print.</p>'
					// ── Simple Audit checks ──────────────────────────────
					. '<h4>Simple Audit Checks (14)</h4>'
					. '<p>These checks run in both Simple and Contextual Audit. They analyse Global Variables and Presets without requiring a Content Scan.</p>'
					. '<table class="d5dsh-help-check-table"><thead><tr><th>Tier</th><th>Check</th><th>What it detects</th></tr></thead><tbody>'
					. '<tr><td>Error</td><td>broken_variable_refs</td><td>A preset references a variable ID that does not exist on this site.</td></tr>'
					. '<tr><td>Error</td><td>archived_vars_in_presets</td><td>A preset references an archived (inactive) variable.</td></tr>'
					. '<tr><td>Error</td><td>duplicate_labels</td><td>Two or more variables share the same label but have different values or types.</td></tr>'
					. '<tr><td>Warning</td><td>singleton_variables</td><td>A variable is referenced by exactly one preset — candidate for inlining.</td></tr>'
					. '<tr><td>Warning</td><td>near_duplicate_values</td><td>Two or more color variables have visually identical normalised values — deduplication candidates. Near-duplicate findings include a <strong>Merge…</strong> button.</td></tr>'
					. '<tr><td>Warning</td><td>preset_duplicate_names</td><td>Presets of the same module type share a name, making them ambiguous in the editor dropdown.</td></tr>'
					. '<tr><td>Warning</td><td>empty_label_variables</td><td>A variable or color has a blank or missing label.</td></tr>'
					. '<tr><td>Warning</td><td>unnamed_presets</td><td>A preset has a missing or blank name.</td></tr>'
					. '<tr><td>Warning</td><td>similar_variable_names</td><td>Variable labels that normalise to the same token (e.g. "Primary Blue" vs "primary-blue") — inconsistent naming.</td></tr>'
					. '<tr><td>Warning</td><td>naming_convention_inconsistency</td><td>Variables of the same type use mixed naming styles (Title Case vs kebab-case vs camelCase).</td></tr>'
					. '<tr><td>Advisory</td><td>hardcoded_extraction_candidates</td><td>A hardcoded hex color appears in 10+ presets — candidate for extraction into a global variable.</td></tr>'
					. '<tr><td>Advisory</td><td>orphaned_variables</td><td>A variable is not referenced by any preset or content item. Each row has an <strong>Impact</strong> link.</td></tr>'
					. '<tr><td>Advisory</td><td>preset_no_variable_refs</td><td>A preset contains no variable references — all values are hardcoded inline.</td></tr>'
					. '<tr><td>Advisory</td><td>variable_type_distribution</td><td>Distribution of variables by type. Flags any type exceeding 60% of all variables.</td></tr>'
					. '</tbody></table>'
					// ── Contextual Audit checks ──────────────────────────
					. '<h4>Contextual Audit Checks (+8)</h4>'
					. '<p>These additional checks run <strong>only</strong> in the Contextual Audit. They require a Content Scan and analyse the relationship between DSOs and published site content.</p>'
					. '<table class="d5dsh-help-check-table"><thead><tr><th>Tier</th><th>Check</th><th>What it detects</th></tr></thead><tbody>'
					. '<tr><td>Error</td><td>archived_dsos_in_content</td><td>An archived variable or preset is directly referenced in published content — the page will render incorrectly.</td></tr>'
					. '<tr><td>Error</td><td>broken_dso_refs_in_content</td><td>A variable or preset ID found in content does not exist on this site.</td></tr>'
					. '<tr><td>Warning</td><td>orphaned_presets</td><td>A preset exists but is not applied in any scanned content — may be stale.</td></tr>'
					. '<tr><td>Warning</td><td>high_impact_variables</td><td>A variable is referenced in 10+ content items — changes will have widespread impact.</td></tr>'
					. '<tr><td>Warning</td><td>preset_naming_convention</td><td>Presets for the same module use mixed naming styles (flags when 2+ styles appear among 4+ presets).</td></tr>'
					. '<tr><td>Advisory</td><td>variables_bypassing_presets</td><td>A variable embedded in a preset is also referenced directly in content, bypassing the preset system.</td></tr>'
					. '<tr><td>Advisory</td><td>singleton_presets</td><td>A preset is applied in only one content item — may not justify being a reusable component.</td></tr>'
					. '<tr><td>Advisory</td><td>overlapping_presets</td><td>Two presets for the same module share 80%+ of their variable references — near-duplicates that could be consolidated.</td></tr>'
					. '</tbody></table>'
					// ── Content Scan ─────────────────────────────────────
					. '<h4>Content Scan</h4>'
					. '<p>Scans up to 1,000 pages, posts, layouts, and templates for DSO usage:</p>'
					. '<ul>'
					. '<li><strong>Active Content</strong> — items with at least one variable or preset reference; includes Vars, Presets, and Vars in Presets (Tot Vars / Uniq Vars) counts.</li>'
					. '<li><strong>Content Inventory</strong> — all scanned items with direct Vars, Presets, and Vars in Presets counts.</li>'
					. '<li><strong>DSO Usage Index</strong> — reverse index: for each DSO, which content items use it. Each row has an <strong>Impact</strong> link.</li>'
					. '<li><strong>No-DSO Content</strong> — items with no design system references.</li>'
					. '<li><strong>Content &rarr; DSO Map</strong> — per-content tree showing every variable and preset referenced; variable nodes display label, ID, and resolved type (Color / Number / Font / etc.).</li>'
					. '<li><strong>DSO &rarr; Usage Chain</strong> — three cross-reference views: Variable &rarr; Usage Chain, Preset &rarr; Variables, Variable &rarr; Presets Containing It.</li>'
					. '<li>Run Content Scan first, then Analysis, to get DSO Usage columns in the audit report.</li>'
					. '</ul>'
					. '<p>' . sprintf( $guide_link, '12-audit-tab' ) . '</p>',
			],
			'styleguide' => [
				'title'   => 'Style Guide Tab',
				'content' => '<p><em>This tab requires Beta Preview to be enabled in Settings.</em></p>'
					. '<p>The <strong>Style Guide</strong> tab generates a visual preview of your design system.</p>'
					. '<ul>'
					. '<li>Click <strong>Generate Style Guide</strong> to build the preview from your current variables and presets.</li>'
					. '<li>Toggle <strong>System vars</strong>, <strong>Group by category</strong>, and <strong>Include presets</strong> to customise the output.</li>'
					. '<li><strong>Colors</strong> section shows a 4-column swatch grid with hex values and variable IDs.</li>'
					. '<li><strong>Typography</strong> section renders each font variable as a sample sentence in that font.</li>'
					. '<li><strong>Numbers / Spacing</strong> section shows proportional ruler bars.</li>'
					. '<li>Click <strong>Download HTML</strong> to export a fully self-contained HTML file (no external dependencies).</li>'
					. '<li>Click <strong>Print / Save as PDF</strong> to open the browser print dialog with WP admin chrome hidden.</li>'
					. '</ul>',
			],
		];

		foreach ( $tabs as $id => $tab ) {
			$screen->add_help_tab( [
				'id'      => 'd5dsh-help-' . $id,
				'title'   => $tab['title'],
				'content' => $tab['content'],
			] );
		}

		$screen->set_help_sidebar(
			'<p><strong>Further reading:</strong></p>'
			. '<p><a href="https://github.com/akonsta/d5-design-system-helper" target="_blank" rel="noopener">GitHub Repository</a></p>'
		);
	}

	/**
	 * AJAX: return full parsed HTML for the help panel.
	 */
	public function ajax_content(): void {
		// No nonce needed — content is not sensitive (read-only, no user data).
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		$html = $this->get_html();
		wp_send_json_success( [ 'html' => $html ] );
	}

	/**
	 * AJAX: return a JSON array suitable for Fuse.js search.
	 */
	public function ajax_index(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		$index = $this->get_search_index();
		wp_send_json_success( $index );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Return the parsed HTML of the user guide (from transient or fresh parse).
	 */
	private function get_html(): string {
		$key    = self::TRANSIENT_HTML . D5DSH_VERSION;
		$cached = get_transient( $key );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		$md   = $this->read_guide();
		$html = $this->parse_markdown( $md );

		// Cache for 7 days; invalidated automatically when version changes.
		set_transient( $key, $html, 7 * DAY_IN_SECONDS );

		return $html;
	}

	/**
	 * Return the Fuse.js search index (array of {id, heading, text} objects).
	 */
	private function get_search_index(): array {
		$key    = self::TRANSIENT_INDEX . D5DSH_VERSION;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$html  = $this->get_html();
		$index = $this->build_search_index( $html );

		set_transient( $key, $index, 7 * DAY_IN_SECONDS );

		return $index;
	}

	/**
	 * Read the user guide Markdown file.
	 */
	private function read_guide(): string {
		$path = D5DSH_PATH . 'PLUGIN_USER_GUIDE.md';
		if ( ! file_exists( $path ) ) {
			return "# Help\n\nUser guide not found.";
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return file_get_contents( $path );
	}

	/**
	 * Parse Markdown to HTML using parsedown-extra.
	 * Injects id= attributes onto every h2/h3 element so scrollHelpToAnchor() works.
	 */
	private function parse_markdown( string $md ): string {
		if ( ! class_exists( ParsedownExtra::class ) ) {
			// Fallback: escape and wrap in <pre>.
			return '<pre>' . esc_html( $md ) . '</pre>';
		}

		$parser = new ParsedownExtra();
		$parser->setSafeMode( false ); // We control the source file.
		$html = $parser->text( $md );

		// Add id= to h2/h3 elements that don't already have one.
		$html = preg_replace_callback(
			'/<(h[23])([^>]*)>(.*?)<\/h[23]>/si',
			function ( $m ) {
				// If already has an id attribute, leave as-is.
				if ( preg_match( '/\bid\s*=/i', $m[2] ) ) {
					return $m[0];
				}
				$text = strip_tags( $m[3] );
				$id   = $this->slug( $text );
				return '<' . $m[1] . $m[2] . ' id="' . esc_attr( $id ) . '">' . $m[3] . '</' . $m[1] . '>';
			},
			$html
		);

		return $html ?? '';
	}

	/**
	 * Build a flat array of search-index objects from parsed HTML.
	 *
	 * Each object: { "id": string, "heading": string, "text": string }
	 *
	 * Strategy: split on <h2> and <h3> tags. Each section gets an id derived
	 * from the heading slug. Strip inner HTML tags for the text field.
	 */
	private function build_search_index( string $html ): array {
		// Use DOMDocument to parse.
		$doc = new \DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$index   = [];
		$current = null;
		$text    = '';

		foreach ( $doc->getElementsByTagName( '*' ) as $node ) {
			/** @var \DOMElement $node */
			$tag = strtolower( $node->tagName );
			if ( in_array( $tag, [ 'h2', 'h3' ], true ) ) {
				// Save previous section.
				if ( $current !== null ) {
					$index[] = [
						'id'      => $current['id'],
						'heading' => $current['heading'],
						'text'    => trim( $text ),
					];
				}
				$heading = trim( $node->textContent );
				$id      = $node->getAttribute( 'id' ) ?: $this->slug( $heading );
				$current = [ 'id' => $id, 'heading' => $heading ];
				$text    = '';
			} elseif ( $current !== null && in_array( $tag, [ 'p', 'li', 'td', 'th' ], true ) ) {
				$text .= ' ' . trim( $node->textContent );
			}
		}

		// Save last section.
		if ( $current !== null ) {
			$index[] = [
				'id'      => $current['id'],
				'heading' => $current['heading'],
				'text'    => trim( $text ),
			];
		}

		return $index;
	}

	/**
	 * Convert a heading string to a URL-safe slug.
	 */
	private function slug( string $heading ): string {
		$slug = strtolower( $heading );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
		return trim( $slug ?? '', '-' );
	}
}
