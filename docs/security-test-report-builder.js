// Security Test Report — D5 Design System Helper & Divi 5 Import/Export
// Built on docx-builder.js standard template (AGK)

const {
  Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
  Footer, AlignmentType, HeadingLevel, BorderStyle, WidthType, ShadingType,
  VerticalAlign, PageNumber, LevelFormat, PageBreak,
  Tab, TabStopType, TabStopPosition
} = require('docx');
const fs = require('fs');

// ── Colours ──────────────────────────────────────────────────────────────────
const H1_COLOR  = "365F91";
const H2_COLOR  = "4F81BD";
const H3_COLOR  = "4F81BD";
const TH_COLOR  = "0070C0";
const BLACK     = "000000";
const CODE_BG   = "F2F2F2";
const RED_BG    = "FDE8E8";
const AMBER_BG  = "FEF9C3";
const GREEN_BG  = "E8F5E9";

// ── Fonts / Sizes ─────────────────────────────────────────────────────────────
const F_HEADING = "Calibri";
const F_BODY    = "Georgia";
const F_CODE    = "Consolas";
const SZ_H1   = 32;
const SZ_H2   = 28;
const SZ_H3   = 24;
const SZ_BODY = 20;
const SZ_CODE = 20;
const SZ_FTR  = 16;
const SZ_TBL  = 18;

const SP_COMPACT = { before: 0, after: 60 };
const SP_NONE    = { before: 0, after: 0 };

const cellBorder  = { style: BorderStyle.SINGLE, size: 1, color: "CCCCCC" };
const cellBorders = { top: cellBorder, bottom: cellBorder, left: cellBorder, right: cellBorder };
const cellMargins = { top: 80, bottom: 80, left: 120, right: 120 };

// ── Helpers ───────────────────────────────────────────────────────────────────
function body(text, opts = {}) {
  const runs = opts.runs || [new TextRun({ text, font: F_BODY, size: SZ_BODY, color: BLACK, bold: opts.bold || false })];
  return new Paragraph({ children: runs, spacing: opts.spacing || SP_COMPACT, keepLines: opts.keepLines, keepNext: opts.keepNext });
}
function h1(text) {
  return new Paragraph({ heading: HeadingLevel.HEADING_1, children: [new TextRun({ text, font: F_HEADING, size: SZ_H1, bold: true, color: H1_COLOR })], spacing: { before: 240, after: 120 }, keepNext: true });
}
function h2(text) {
  return new Paragraph({ heading: HeadingLevel.HEADING_2, children: [new TextRun({ text, font: F_HEADING, size: SZ_H2, bold: true, color: H2_COLOR })], spacing: { before: 180, after: 60 }, keepNext: true });
}
function h3(text) {
  return new Paragraph({ heading: HeadingLevel.HEADING_3, children: [new TextRun({ text, font: F_HEADING, size: SZ_H3, bold: true, color: H3_COLOR })], spacing: { before: 120, after: 40 }, keepNext: true });
}
function bullet(text) {
  return new Paragraph({ children: [new TextRun({ text, font: F_BODY, size: SZ_BODY, color: BLACK })], spacing: { before: 0, after: 40 }, indent: { left: 576, hanging: 288 }, keepLines: true });
}
function numbered(num, boldText, rest) {
  return new Paragraph({
    children: [
      new TextRun({ text: `${num}. `, font: F_BODY, size: SZ_BODY, color: BLACK }),
      new TextRun({ text: boldText, font: F_BODY, size: SZ_BODY, color: BLACK, bold: true }),
      new TextRun({ text: rest, font: F_BODY, size: SZ_BODY, color: BLACK }),
    ],
    spacing: { before: 0, after: 60 }, indent: { left: 576, hanging: 288 }, keepLines: true,
  });
}
function codeLine(text) {
  return new Paragraph({ children: [new TextRun({ text, font: F_CODE, size: SZ_CODE, color: BLACK })], spacing: SP_NONE, keepLines: true, shading: { type: ShadingType.CLEAR, fill: CODE_BG }, indent: { left: 360, right: 144 } });
}
function codeBlock(lines) {
  return lines.split('\n').map(l => codeLine(l));
}
function spacer() {
  return new Paragraph({ children: [new TextRun({ text: '' })], spacing: SP_NONE });
}
function noteText(label, text) {
  return new Paragraph({ children: [new TextRun({ text: label + ' ', font: F_BODY, size: SZ_BODY, bold: true, color: BLACK }), new TextRun({ text, font: F_BODY, size: SZ_BODY, color: BLACK })], spacing: SP_COMPACT });
}
function parseCellRuns(text) {
  const parts = text.split(/(`[^`]+`)/g);
  return parts.map(p => {
    if (p.startsWith('`') && p.endsWith('`'))
      return new TextRun({ text: p.slice(1, -1), font: F_CODE, size: SZ_TBL, color: BLACK });
    return new TextRun({ text: p, font: F_BODY, size: SZ_TBL, color: BLACK });
  });
}
function makeTable(headers, rows, colWidths, opts = {}) {
  const headerRow = new TableRow({
    cantSplit: true, tableHeader: true,
    children: headers.map((h, i) => new TableCell({
      borders: cellBorders, width: { size: colWidths[i], type: WidthType.DXA }, margins: cellMargins, verticalAlign: VerticalAlign.CENTER,
      shading: opts.headerBg ? { type: ShadingType.CLEAR, fill: opts.headerBg } : undefined,
      children: [new Paragraph({ children: [new TextRun({ text: h, font: F_BODY, size: SZ_TBL, bold: true, color: opts.headerBg ? BLACK : TH_COLOR })], spacing: SP_NONE })],
    })),
  });
  const dataRows = rows.map((row, ri) => new TableRow({
    cantSplit: true,
    children: row.map((cell, i) => {
      const bg = opts.rowColors ? opts.rowColors[ri] : null;
      const cellChildren = typeof cell === 'string'
        ? [new Paragraph({ children: parseCellRuns(cell), spacing: SP_NONE, shading: bg ? { type: ShadingType.CLEAR, fill: bg } : undefined })]
        : cell;
      return new TableCell({
        borders: cellBorders, width: { size: colWidths[i], type: WidthType.DXA }, margins: cellMargins, verticalAlign: VerticalAlign.TOP,
        shading: bg ? { type: ShadingType.CLEAR, fill: bg } : undefined,
        children: cellChildren,
      });
    }),
  }));
  return new Table({ width: { size: colWidths.reduce((a,b)=>a+b,0), type: WidthType.DXA }, columnWidths: colWidths, rows: [headerRow, ...dataRows] });
}
function makeFooter() {
  return new Footer({ children: [
    new Paragraph({ alignment: AlignmentType.RIGHT, spacing: SP_NONE, children: [new TextRun({ children: ["FILENAME"], font: F_BODY, size: SZ_FTR, color: BLACK })] }),
    new Paragraph({ alignment: AlignmentType.RIGHT, spacing: SP_NONE, children: [
      new TextRun({ children: ["DATE \\@ \"d MMM yyyy\""], font: F_BODY, size: SZ_FTR, color: BLACK }),
      new TextRun({ text: " \u2013 Page ", font: F_BODY, size: SZ_FTR, color: BLACK }),
      new TextRun({ children: [PageNumber.CURRENT], font: F_BODY, size: SZ_FTR, color: BLACK }),
      new TextRun({ text: " of ", font: F_BODY, size: SZ_FTR, color: BLACK }),
      new TextRun({ children: [PageNumber.TOTAL_PAGES], font: F_BODY, size: SZ_FTR, color: BLACK }),
    ]}),
  ]});
}

// ── Legend key ────────────────────────────────────────────────────────────────
// BLOCKED = Divi stripped value to empty string
// PARTIAL = Divi stripped some of the payload but kept the entry
// PASSED  = value stored exactly as imported — potential vulnerability
// ENCODED = Divi URL-encoded the value (neutralized the quote but payload survives)
// CRASH   = import caused the Divi builder to go blank / database corruption

// ── Result colour coding ──────────────────────────────────────────────────────
const R = { BLOCKED: GREEN_BG, PARTIAL: AMBER_BG, PASSED: RED_BG, ENCODED: AMBER_BG, CRASH: "FECACA", UNTESTED: "F3F4F6" };

// ─────────────────────────────────────────────────────────────────────────────
// DOCUMENT CONTENT
// ─────────────────────────────────────────────────────────────────────────────
const doc = new Document({
  styles: {
    default: { document: { run: { font: F_BODY, size: SZ_BODY, color: BLACK } } },
    paragraphStyles: [
      { id: "Heading1", name: "Heading 1", basedOn: "Normal", next: "Normal", quickFormat: true, run: { size: SZ_H1, bold: true, font: F_HEADING, color: H1_COLOR }, paragraph: { spacing: { before: 240, after: 120 }, outlineLevel: 0, keepNext: true } },
      { id: "Heading2", name: "Heading 2", basedOn: "Normal", next: "Normal", quickFormat: true, run: { size: SZ_H2, bold: true, font: F_HEADING, color: H2_COLOR }, paragraph: { spacing: { before: 180, after: 60 }, outlineLevel: 1, keepNext: true } },
      { id: "Heading3", name: "Heading 3", basedOn: "Normal", next: "Normal", quickFormat: true, run: { size: SZ_H3, bold: true, font: F_HEADING, color: H3_COLOR }, paragraph: { spacing: { before: 120, after: 40 }, outlineLevel: 2, keepNext: true } },
    ],
  },
  sections: [{
    properties: { page: { size: { width: 12240, height: 15840 }, margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 } } },
    footers: { default: makeFooter() },
    children: [

      // ── TITLE ────────────────────────────────────────────────────────────────
      new Paragraph({
        heading: HeadingLevel.HEADING_1, alignment: AlignmentType.CENTER,
        children: [new TextRun({ text: "D5 Design System Helper — Security Test Report", font: F_HEADING, size: SZ_H1, bold: true, color: H1_COLOR })],
        spacing: { before: 480, after: 120 },
      }),
      body("Applies to: Divi 5 import/export and D5 Design System Helper plugin import/export", { spacing: SP_NONE }),
      body("Status: Divi native tests complete (§5, §8). D5DSH plugin tests complete (§9). Pending: render tests, export-carrier re-run, follow-up items.", { spacing: { before: 0, after: 240 } }),

      // ── VERSION HISTORY ──────────────────────────────────────────────────────
      h2("Version History"),
      makeTable(
        ['Version', 'Date', 'Summary'],
        [
          ['0.1', '2026-03-30', 'Initial report: Divi 5 native import/export tests for strings, links, images, and number variables (split-file phase). D5DSH plugin tests pending.'],
          ['0.2', '2026-03-31', 'Added Divi native results for colors, fonts, links-extra, images-extra, numbers-extra, structural edge cases, size/volume, and CSS-injection split-payload chain. Updated key findings and next steps.'],
          ['0.3', '2026-03-31', 'Created 9 new fixture files for pending tests: 3 preset poison files (names, attrs, structural), precision deletion, 2 import-over-existing, 2 size scale-up (500/5000 entries), and export carrier/cross-site spread. §8 updated to describe each test and its methodology.'],
          ['0.4', '2026-03-31', 'Phase 3 results recorded in §8: presets poison names/attrs/structural, precision deletion (confirmed silent 3-entry removal), import-over-existing (results inconclusive on first run — DB was reset), size scale-up to 5,000 entries (no limit found), export carrier (blocked by CSS break payload). Key new findings: presets attrs importer performs stricter schema validation than variables importer; presets structural malformed entry triggers save failure.'],
          ['0.5', '2026-03-31', 'Re-tested §8.5 (import over existing) without DB reset. CONFIRMED: Divi import is merge not replace — all 9 baseline vars not in the import file survived. UNEXPECTED: color values were NOT overwritten even though variable values were — colors appear append-only. Color override behavior flagged for follow-up test.'],
          ['0.6', '2026-03-31', 'D5DSH plugin import tests completed via automated Security Testing panel (v0.1.1). All 29 fixtures run. 28/29 PASS; 1 exception (TypeError in structural fixture — non-string value passed to sanitize_and_log). 42 fields sanitized across the full run. Full D5DSH results in §9. Bug fix applied: sanitize_and_log now guards against array/null values.'],
        ],
        [1100, 1500, 6660]
      ),
      spacer(),

      // ── §1 PURPOSE ───────────────────────────────────────────────────────────
      h2("1. Purpose and Scope"),
      body("This document records security tests conducted against the Divi 5 native import/export function and the D5 Design System Helper (D5DSH) plugin import/export function. The goal is to determine:"),
      numbered(1, "What Divi 5 sanitizes", " when importing global variables via its own import/export UI."),
      numbered(2, "What D5DSH sanitizes", " when importing the same payloads via the plugin's Import tab."),
      numbered(3, "What gaps remain", " in either system that could allow malicious content to reach the database or be rendered in a browser."),
      spacer(),
      body("Tests are conducted on a local WordPress site (Local by Flywheel) and do not affect any production environment. All test fixture files are stored in the project repository under tests/fixtures/security/."),
      spacer(),

      // ── §2 TEST ENVIRONMENT ──────────────────────────────────────────────────
      h2("2. Test Environment"),
      makeTable(
        ['Item', 'Detail'],
        [
          ['WordPress', 'Local by Flywheel — local development environment'],
          ['Divi version', '5.x (current at time of testing)'],
          ['D5DSH version', '0.1.1 (Security Testing panel, automated fixture runner)'],
          ['Test fixture directory', '`tests/fixtures/security/`'],
          ['Clean baseline file', '`test-vars-clean.json`'],
          ['Database reset method', 'Local by Flywheel database reset between each test'],
          ['Export after each test', 'Exported via Divi Theme Options → Export immediately after import'],
        ],
        [2500, 6860]
      ),
      spacer(),

      // ── §3 METHODOLOGY ───────────────────────────────────────────────────────
      h2("3. Test Methodology"),
      body("Each test follows this sequence:"),
      numbered(1, "Reset:", " Restore the database to a clean known state."),
      numbered(2, "Import:", " Import a single poison test file via the importer under test."),
      numbered(3, "Observe:", " Check for errors, blank screens, or other visible failures."),
      numbered(4, "Export:", " Export all global variables immediately after import."),
      numbered(5, "Compare:", " Compare the exported file against the imported file to determine what survived sanitization."),
      spacer(),
      body("Each poison file is isolated to a single attack category so that the culprit of any failure can be pinpointed. Where a test causes a crash, the database is reset before proceeding."),
      spacer(),
      body("Result codes used in tables:", { bold: true }),
      makeTable(
        ['Code', 'Meaning'],
        [
          ['BLOCKED', 'Value was stripped to empty string — attack neutralized'],
          ['PARTIAL', 'Some payload was removed but the variable entry still exists with residual content'],
          ['PASSED', 'Value stored exactly as imported — potential vulnerability, no sanitization applied'],
          ['ENCODED', 'Value was URL-encoded rather than stripped — quote neutralized but payload survives in encoded form'],
          ['CRASH', 'Import caused the Divi builder to go blank or database to become corrupted'],
          ['UNTESTED', 'Test not yet run'],
        ],
        [1500, 7860],
        { rowColors: [R.BLOCKED, R.PARTIAL, R.PASSED, R.ENCODED, R.CRASH, R.UNTESTED] }
      ),
      spacer(),

      // ── §4 ATTACK TAXONOMY ───────────────────────────────────────────────────
      h2("4. Attack Taxonomy"),
      body("The following categories of malicious content were tested. These represent the standard OWASP web attack surface as it applies to a field that accepts free-form design token values and is later rendered in a browser or processed server-side."),
      makeTable(
        ['Category', 'Code', 'Description'],
        [
          ['Cross-Site Scripting', 'XSS', 'HTML/JS injection via script tags, event handlers, SVG onload, iframe src, data: URIs, javascript: URIs, CSS context breaks'],
          ['SQL Injection', 'SQLI', 'SQL fragments injected into field values, exploitable if values are ever interpolated into a raw database query'],
          ['PHP Code Execution', 'PHP', 'PHP opening tags (<?php, <?=) that execute if a value is written to a cached PHP file'],
          ['Path Traversal', 'PATH', 'Directory traversal sequences (../../) that could be used to read or write files outside the intended directory'],
          ['Server-Side Request Forgery', 'SSRF', 'URLs pointing to internal network resources (AWS metadata endpoint, localhost) that Divi or WP might fetch server-side'],
          ['CSS Injection', 'CSS', 'CSS property injection and expression() payloads that execute in older browsers or break page styling'],
          ['Serialization Attack', 'SER', 'PHP serialized object/array strings that execute if passed to unserialize()'],
          ['Null Byte Injection', 'NULL', 'Null bytes (\\u0000) that truncate strings at the C layer and can bypass length-based validators'],
          ['Structural / Prototype Pollution', 'STRUCT', 'JSON keys (__proto__, constructor) that pollute JavaScript object prototypes; deeply nested objects; type confusion'],
          ['Open Redirect', 'REDIR', 'Protocol-relative URLs (//evil.com) used as link values to redirect users to attacker-controlled sites'],
          ['Attribute Break', 'ATTR', 'Quote characters in values designed to break out of HTML attribute context and inject event handlers'],
          ['Split-Payload Chain', 'CHAIN', 'A number variable using var() to reference a string variable, where the string carries the actual XSS payload — neither variable looks dangerous in isolation'],
        ],
        [2400, 800, 6160]
      ),
      spacer(),

      // ── §5 DIVI NATIVE IMPORT TESTS ──────────────────────────────────────────
      h2("5. Divi 5 Native Import/Export Tests"),
      body("These tests were conducted using the Divi Theme Options import/export UI. The import file was uploaded directly through Divi's own interface, not through the D5DSH plugin."),
      spacer(),
      noteText("Note:", "Divi rejected files containing extra top-level JSON keys (such as _meta, _structural_attacks) as invalid. All test files were therefore formatted using only the standard Divi export envelope: the context, data, presets, global_colors, global_variables, canvases, images, and thumbnails keys."),
      spacer(),
      noteText("Note:", "The null byte test (null bytes embedded in variable id, label, and value fields) was tested separately. It caused the Divi builder to go blank on next page load and required a full database reset. It is documented in §5.1 as CRASH and excluded from subsequent combined tests."),
      spacer(),

      h3("5.1 String Variables (test-vars-poison-text.json)"),
      body("Ten string-type global variables were imported, each containing a different payload. The export after import was compared field by field."),
      makeTable(
        ['Variable ID', 'Attack', 'Imported Value (abbreviated)', 'Exported Value', 'Result'],
        [
          ['`gvid-str-script-tag`', 'XSS-1', '`<script>alert(...)</script>`', 'Empty string', 'BLOCKED'],
          ['`gvid-str-attr-break`', 'XSS-2 / ATTR', '`" onmouseover="alert(...)`', 'Value unchanged', 'PASSED'],
          ['`gvid-str-template-inject`', 'Template injection', '`{{7*7}}`', 'Value unchanged', 'PASSED'],
          ['`gvid-str-el-inject`', 'EL injection', '`${7*7}`', 'Value unchanged', 'PASSED'],
          ['`gvid-str-serialized`', 'SER-1', '`O:8:"stdClass":1:{...}`', 'Value unchanged', 'PASSED'],
          ['`gvid-str-path-traversal`', 'PATH-1', '`../../wp-config.php`', 'Value unchanged', 'PASSED'],
          ['`gvid-str-sqli`', 'SQLI-1', "`1234 Main St'; DROP TABLE wp_options; --`", 'Value unchanged', 'PASSED'],
          ['`gvid-str-php-tag`', 'PHP-1', '`<?php system(\'id\'); ?>`', 'Empty string', 'BLOCKED'],
          ['`gvid-str-iframe`', 'XSS-3', '`<iframe src=\'javascript:...\'>`', 'Empty string', 'BLOCKED'],
          ['`gvid-str-css-break`', 'CSS-1', '`Read More</style><script>...`', '`Read More` (suffix stripped)', 'PARTIAL'],
          ['`gvid-null-byte`', 'NULL', 'Null bytes in id, label, value', 'Builder blank on next load', 'CRASH'],
        ],
        [2200, 1100, 2200, 1800, 1460],
        { rowColors: [R.BLOCKED, R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.BLOCKED, R.BLOCKED, R.PARTIAL, R.CRASH] }
      ),
      spacer(),
      body("Summary: Divi blocks bare HTML tags (script, iframe, PHP open tags). It does not sanitize attribute-break quotes, template/EL injection syntax, serialized PHP, path traversal, or SQL injection. The CSS break was partially mitigated. Null bytes caused a crash."),
      spacer(),

      h3("5.2 Link Variables (test-vars-poison-links.json)"),
      makeTable(
        ['Variable ID', 'Attack', 'Imported Value (abbreviated)', 'Exported Value', 'Result'],
        [
          ['`gvid-lnk-js-uri`', 'XSS-4', '`javascript:alert(...)`', 'Empty string', 'BLOCKED'],
          ['`gvid-lnk-data-html`', 'XSS-5', '`data:text/html,<script>...`', 'Empty string', 'BLOCKED'],
          ['`gvid-lnk-data-svg`', 'XSS-6', '`data:image/svg+xml,<svg onload=...`', 'Empty string', 'BLOCKED'],
          ['`gvid-lnk-vbscript`', 'XSS-7', '`vbscript:msgbox(1)`', 'Empty string', 'BLOCKED'],
          ['`gvid-lnk-open-redirect`', 'REDIR', '`//evil.example.com/steal?c=`', 'Value unchanged', 'PASSED'],
          ['`gvid-lnk-ssrf-metadata`', 'SSRF-1', '`http://169.254.169.254/...`', 'Value unchanged', 'PASSED'],
          ['`gvid-lnk-ssrf-localhost`', 'SSRF-2', '`http://localhost/wp-admin/...`', 'Value unchanged', 'PASSED'],
          ['`gvid-lnk-file-uri`', 'PATH-2', '`file:///etc/passwd`', 'Empty string', 'BLOCKED'],
          ['`gvid-lnk-attr-break`', 'ATTR', '`https://example.com" onclick=...`', 'URL-encoded (quote → %20)', 'ENCODED'],
        ],
        [2200, 1100, 2200, 1800, 1460],
        { rowColors: [R.BLOCKED, R.BLOCKED, R.BLOCKED, R.BLOCKED, R.PASSED, R.PASSED, R.PASSED, R.BLOCKED, R.ENCODED] }
      ),
      spacer(),
      body("Summary: Divi blocks javascript:, vbscript:, data:, and file:// schemes in link variables. It does not block protocol-relative open redirects or SSRF URLs (AWS metadata, localhost). Attribute-break quotes were URL-encoded (payload survives in encoded form)."),
      spacer(),

      h3("5.3 Image Variables (test-vars-poison-images.json)"),
      makeTable(
        ['Variable ID', 'Attack', 'Imported Value (abbreviated)', 'Exported Value', 'Result'],
        [
          ['`gvid-img-data-html`', 'XSS-5', '`data:text/html,<script>...`', '`data:text/html,` (payload stripped, scheme kept)', 'PARTIAL'],
          ['`gvid-img-data-svg`', 'XSS-6', '`data:image/svg+xml,<svg onload=...`', '`data:image/svg+xml,` (payload stripped, scheme kept)', 'PARTIAL'],
          ['`gvid-img-js-uri`', 'XSS-4', '`javascript:alert(...)`', 'Value unchanged', 'PASSED'],
          ['`gvid-img-ssrf-metadata`', 'SSRF-1', '`http://169.254.169.254/...`', 'Value unchanged', 'PASSED'],
          ['`gvid-img-ssrf-localhost`', 'SSRF-2', '`http://localhost/wp-admin/...`', 'Value unchanged', 'PASSED'],
          ['`gvid-img-file-uri`', 'PATH-2', '`file:///etc/passwd`', 'Value unchanged', 'PASSED'],
          ['`gvid-img-path-traversal`', 'PATH-1', '`../../wp-config.php`', 'Value unchanged', 'PASSED'],
          ['`gvid-img-attr-break`', 'ATTR', '`photo.jpg" onerror="alert(...)`', 'Value unchanged', 'PASSED'],
          ['`gvid-img-data-b64-svg`', 'XSS-6b', '`data:image/svg+xml;base64,...`', 'Value unchanged — executes JS on load', 'PASSED'],
        ],
        [2200, 1100, 2200, 1800, 1460],
        { rowColors: [R.PARTIAL, R.PARTIAL, R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.PASSED] }
      ),
      spacer(),
      body("Summary: Images are the weakest point in Divi's sanitization. The javascript: URI is blocked in links but passes unmodified in images. Base64-encoded SVG data URIs (which execute JavaScript on load in all modern browsers) pass through completely. Divi partially strips data: URIs by removing the payload after the comma, but keeps the scheme — this is inconsistent and incomplete. SSRF, file://, path traversal, and attribute-break payloads all pass."),
      spacer(),

      h3("5.4 Number Variables — Individual Test Results"),
      body("The combined numbers poison file caused the Divi builder to go blank on next page load, requiring a full database reset. Tests were re-run using six individual files, one payload per file. All six tests completed with results as follows."),
      makeTable(
        ['File', 'Attack', 'Payload (abbreviated)', 'Exported Value', 'Result'],
        [
          ['`num-css-break`', 'CSS-2', '`16px; } body { display:none; } .evil {`', 'Builder blank — database reset required', 'CRASH'],
          ['`num-clamp-expr`', 'CSS-3', '`clamp(1px, expression(alert(...)), 100px)`', 'Value unchanged', 'PASSED'],
          ['`num-calc-break`', 'CSS-4', '`calc(8px + 0) !important; background:url(javascript:alert(1))`', 'Value unchanged', 'PASSED'],
          ['`num-var-chain`', 'CHAIN', 'Number: `var(--gvid-str-xss-payload, 1px)` — String: `</style><script>...`', 'Number: unchanged / String: empty', 'PARTIAL — chain broken by string sanitization'],
          ['`num-min-inject`', 'CSS-5', '`min(4vw, 60px); } .injected { color: red`', 'Value unchanged', 'PASSED'],
          ['`num-style-break`', 'XSS-8', '`1.8em</style><script>alert(\'style-break\')</script>`', '`1.8em` — script tag stripped, CSS value kept', 'PARTIAL'],
        ],
        [1800, 900, 2600, 2200, 1460],
        { rowColors: [R.CRASH, R.PASSED, R.PASSED, R.PARTIAL, R.PASSED, R.PARTIAL] }
      ),
      spacer(),
      noteText("Confirmed crash culprit:", "test-vars-poison-num-css-break.json. The payload is a plain CSS block break: a semicolon followed by a closing brace (}) with no CSS function wrapping. Divi injects number variable values directly into a <style> block without CSS-grammar escaping. The bare } closes the current CSS rule, corrupting the style block Divi uses to initialize the Visual Builder. The builder cannot recover and renders a blank screen."),
      spacer(),
      body("Key finding — CSS grammar vs. HTML tag detection: Divi partially handles the </style> HTML tag (stripping the script content in num-style-break) but has no awareness of CSS grammar. A bare } inside a number value is structurally more dangerous than </style> and goes completely unchecked. Similarly, CSS functions (clamp, calc, min) and !important pass through unmodified."),
      spacer(),
      body("Key finding — split-payload chain: The var() reference in the number variable survived (PASSED) but the XSS payload in the companion string variable was blocked because it contained a <script> tag. The chain is broken by Divi's HTML-tag filtering on the string side — but only because the payload used HTML syntax. A string payload using pure CSS injection (no HTML tags) would survive and complete the chain via the var() reference."),
      spacer(),

      // ── §5.5 COLORS ──────────────────────────────────────────────────────────
      h3("5.5 Color Variables (test-vars-poison-colors.json)"),
      body("Eight color entries were imported. Colors are stored in a separate option (et_divi[et_global_data][global_colors]) as [id, {color, status, label}] pairs. All eight entries survived import. Findings:"),
      makeTable(
        ['Variable ID', 'Attack', 'Imported Value / Label', 'Exported Value / Label', 'Result'],
        [
          ['`gcid-test-homoglyph`', 'SPOOF', 'Label: "Рrimary" (Cyrillic Р U+0420)', 'Label: "\u0420rimary" — unchanged', 'PASSED'],
          ['`gcid-test-css-expression`', 'CSS-6', 'Color: `rgba(0,0,0); expression(alert(document.cookie))`', 'Color: stored verbatim', 'PASSED'],
          ['`gcid-test-broken-varref`', 'STRUCT', 'Color: `$variable({...gcid-does-not-exist...})$`', 'Stored verbatim — broken ref preserved in DB', 'PASSED'],
          ['`gcid-test-overlong`', 'SIZE', 'Color: "#" + 262 "a" chars', 'Stored verbatim — no length cap', 'PASSED'],
          ['`gcid-test-color-xss-label`', 'XSS', 'Label: `<script>alert(\'NUM-EX-1: XSS in color label\')</script>`', 'Label: exported as "#0000ff" (color value used as label — script stripped)', 'PARTIAL'],
          ['`gcid-test-color-attr-break`', 'ATTR', 'Label: `Normal" onmouseover="alert(\'COLOR-2\')`', 'Label: stored verbatim including injected attribute string', 'PASSED'],
          ['`gcid-test-color-js-value`', 'XSS', 'Color: `javascript:alert(\'COLOR-3\')`', 'Color: stored verbatim', 'PASSED'],
          ['`gcid-test-color-null-stripped`', 'UNICODE', 'Label with four U+200B zero-width spaces', 'Label: stored verbatim including all U+200B chars', 'PASSED'],
        ],
        [2200, 900, 2200, 2000, 1460],
        { rowColors: [R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.PARTIAL, R.PASSED, R.PASSED, R.PASSED] }
      ),
      spacer(),
      body("Summary: Color values receive virtually no sanitization. CSS expression() payloads, javascript: URIs, broken variable references, overlong values, attribute-break quotes, and Unicode tricks all pass through. The XSS label was partially handled — the script tag was stripped but the label field was replaced with the color value, which is an unexpected and undocumented behavior. Zero-width spaces survive, enabling silent Unicode spoofing of color names."),
      spacer(),

      // ── §5.6 FONTS ───────────────────────────────────────────────────────────
      h3("5.6 Font Variables (test-vars-poison-fonts.json)"),
      body("Seven font-type variables were imported. Font variables store a font family name string. Results:"),
      makeTable(
        ['Variable ID', 'Attack', 'Imported Value', 'Exported Value', 'Result'],
        [
          ['`gvid-font-css-string-break`', 'CSS-7', "`'; font-family: inherit; content: '`", 'Value unchanged', 'PASSED'],
          ['`gvid-font-url-inject`', 'XSS', '`Arial, url(javascript:alert(...))`', 'Value unchanged', 'PASSED'],
          ['`gvid-font-html-entity`', 'XSS', '`Arial&lt;script&gt;alert(...)&lt;/script&gt;`', 'Stored as `Arial&lt;script&gt;...&lt;/script&gt;` — HTML-encoded, not stripped', 'PARTIAL'],
          ['`gvid-font-script-tag`', 'XSS', '`Impact<script>alert(\'FONT-4: script in font value\')</script>`', 'Stored as empty string — script tag stripped, font name discarded', 'BLOCKED'],
          ['`gvid-font-css-break`', 'CSS-8', '`Georgia; } body { display:none; } .evil {`', 'Value unchanged — same CSS block break pattern as numbers crash, but no crash here', 'PASSED'],
          ['`gvid-font-xss-label`', 'XSS', 'Label: `<script>alert(\'FONT-6: XSS in font label\')</script>`', 'Label: empty string — script stripped', 'BLOCKED'],
          ['`gvid-font-path-traversal`', 'PATH', '`../../wp-content/uploads/evil.ttf`', 'Value unchanged', 'PASSED'],
        ],
        [2000, 900, 2500, 2000, 1360],
        { rowColors: [R.PASSED, R.PASSED, R.PARTIAL, R.BLOCKED, R.PASSED, R.BLOCKED, R.PASSED] }
      ),
      spacer(),
      body("Summary: Font values containing bare script tags are stripped to empty. HTML-entity-encoded payloads are stored as-is (double-decode risk if rendered via innerHTML). The CSS block break (Georgia; } body {...}) passed through for fonts — the same pattern that caused a crash in number variables. This inconsistency suggests the crash was specific to how number variables are emitted in the style block, not a general CSS-grammar crash. The javascript: url() injection and CSS string-break also passed through. Path traversal survived."),
      noteText("Notable:", "The CSS block break payload (Georgia; } body { display:none }) passes in font variables but causes a crash in number variables. Both values are injected into the same style block — the difference in behavior needs further investigation. It may be related to variable rendering order or how Divi prefixes number vs. font variable CSS declarations."),
      spacer(),

      // ── §5.7 LINKS EXTRA ─────────────────────────────────────────────────────
      h3("5.7 Link Variables — Additional (test-vars-poison-links-extra.json)"),
      body("Eight additional link attack vectors were tested, targeting evasion techniques not covered in §5.2."),
      makeTable(
        ['Variable ID', 'Attack', 'Imported Value', 'Exported Value', 'Result'],
        [
          ['`gvid-lnk-bidi-override`', 'SPOOF', 'URL with U+202E (BiDi RIGHT-TO-LEFT OVERRIDE)', 'Stored with U+202E intact — spoofed URL direction preserved', 'PASSED'],
          ['`gvid-lnk-idn-homograph`', 'SPOOF', '`https://evil.\u0441om/steal` (Cyrillic \u0441)', 'Stored verbatim — homograph domain preserved', 'PASSED'],
          ['`gvid-lnk-double-encoded`', 'PATH', '`https://example.com%252F..%252Fwp-config.php`', 'Stored verbatim — double-encoding preserved', 'PASSED'],
          ['`gvid-lnk-data-b64-html`', 'XSS', '`data:text/html;base64,...` (base64 script)', 'Empty string — data: scheme blocked', 'BLOCKED'],
          ['`gvid-lnk-tab-newline`', 'XSS', '`java\\tscript:alert(...)` (tab-split scheme)', 'Empty string — blocked despite tab evasion', 'BLOCKED'],
          ['`gvid-lnk-unicode-js`', 'XSS', '`\u24d9avascript:alert(...)` (Unicode \u24d9 = j)', 'Empty string — blocked despite Unicode evasion', 'BLOCKED'],
          ['`gvid-lnk-null-in-url`', 'NULL', '`https://example.com/page.php\\u0000../../etc/passwd`', '`https://example.com/page.php` — null byte stripped, URL truncated', 'PARTIAL'],
          ['`gvid-lnk-xss-label`', 'XSS', 'Label: `<script>alert(\'LNK-EX-3: XSS in link label\')</script>`', 'Label: empty string — script stripped', 'BLOCKED'],
        ],
        [2200, 900, 2500, 2000, 1160],
        { rowColors: [R.PASSED, R.PASSED, R.PASSED, R.BLOCKED, R.BLOCKED, R.BLOCKED, R.PARTIAL, R.BLOCKED] }
      ),
      spacer(),
      body("Summary: Divi's javascript: filter is robust — it handles tab-split schemes and Unicode character substitutions (circled j ⓙ). The data: block is also robust for link variables. BiDi override characters, IDN homograph domains, and double-encoded paths all pass unmodified, enabling phishing and URL-spoofing attacks. The null byte was cleanly stripped in the URL context, truncating the path traversal tail."),
      spacer(),

      // ── §5.8 IMAGES EXTRA ────────────────────────────────────────────────────
      h3("5.8 Image Variables — Additional (test-vars-poison-images-extra.json)"),
      makeTable(
        ['Variable ID', 'Attack', 'Imported Value', 'Exported Value', 'Result'],
        [
          ['`gvid-img-svg-foreignobject`', 'XSS', '`data:image/svg+xml,<svg><foreignObject><script>...`', '`data:image/svg+xml,` — SVG payload stripped, scheme kept', 'PARTIAL'],
          ['`gvid-img-overlong-b64`', 'SIZE', '`data:image/png;base64,` + 900+ char string', 'Stored verbatim in full — no length limit applied', 'PASSED'],
          ['`gvid-img-svg-use-href`', 'XSS', '`data:image/svg+xml,<svg><use href="data:...#x"/>`', 'HTML-encoded SVG kept: `data:image/svg+xml,&lt;use href=...`', 'PARTIAL'],
          ['`gvid-img-content-type-sniff`', 'SSRF', '`https://example.com/image.php?file=evil.js`', 'Stored verbatim', 'PASSED'],
          ['`gvid-img-xss-label`', 'XSS', 'Label: `<script>alert(\'IMG-EX-3: XSS in image label\')</script>`', 'Label: empty string', 'BLOCKED'],
          ['`gvid-img-css-break-url`', 'CSS', "`https://example.com/img.jpg'); background:url(javascript:alert(...);//`", 'Stored verbatim — CSS break in URL value passes', 'PASSED'],
        ],
        [2000, 900, 2700, 2000, 1160],
        { rowColors: [R.PARTIAL, R.PASSED, R.PARTIAL, R.PASSED, R.BLOCKED, R.PASSED] }
      ),
      spacer(),
      body("Summary: SVG content inside data: URIs is partially stripped (script and foreignObject payload removed, scheme preserved) but the SVG use-href skeleton is HTML-encoded and stored — not fully blocked. There is no length limit on image values. The CSS break in a URL value (closing a CSS url() call and injecting a new background rule) passes through completely. Content-type sniff URLs are not validated."),
      spacer(),

      // ── §5.9 NUMBERS EXTRA ───────────────────────────────────────────────────
      h3("5.9 Number Variables — Additional (test-vars-poison-numbers-extra.json)"),
      makeTable(
        ['Variable ID', 'Attack', 'Imported Value', 'Exported Value', 'Result'],
        [
          ['`gvid-num-negative-overflow`', 'CSS', '`-999999`', 'Stored verbatim', 'PASSED'],
          ['`gvid-num-huge-value`', 'SIZE', '`999999999px`', 'Stored verbatim', 'PASSED'],
          ['`gvid-num-scientific`', 'CSS', '`1e9px`', 'Stored verbatim', 'PASSED'],
          ['`gvid-num-unit-confusion`', 'CSS', '`100% important`', 'Stored verbatim', 'PASSED'],
          ['`gvid-num-css-comment`', 'CSS-9', '`1px /* } body { display:none } */`', 'Stored verbatim — CSS comment hiding a block break survives', 'PASSED'],
          ['`gvid-num-unicode-escape`', 'CSS', '`1\\65 x` (CSS unicode escape for "ex")', 'Stored verbatim', 'PASSED'],
          ['`gvid-num-xss-label`', 'XSS', 'Label: `<script>alert(\'NUM-EX-1: XSS in number label\')</script>`', 'Label: empty string', 'BLOCKED'],
        ],
        [2000, 900, 2700, 2000, 1160],
        { rowColors: [R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.BLOCKED] }
      ),
      spacer(),
      body("Summary: All numeric edge cases pass through. A CSS comment wrapping a block break (/* } body { ... } */) survives — this is a more subtle version of the confirmed CSS-break crash vector. The comment syntax may prevent the immediate crash while preserving an injection path in browsers that support CSS comments in custom property values. Scientific notation, huge values, negative values, and Unicode CSS escapes all pass unmodified. XSS in labels is blocked."),
      spacer(),

      // ── §5.10 STRUCTURAL ─────────────────────────────────────────────────────
      h3("5.10 Structural Edge Cases (test-vars-poison-structural.json)"),
      makeTable(
        ['Variable ID', 'Attack', 'Scenario', 'Exported Result', 'Result'],
        [
          ['`gvid-struct-10k-entries-anchor`', 'STRUCT', 'Anchor — present to confirm 10k test loaded', 'Present in export', 'PASSED'],
          ['`gvid-struct-dupe-id` (×2)', 'STRUCT', 'Two entries with identical ID — first: "first", second: "Second Definition — duplicate ID"', 'Only second definition exported — last-write-wins confirmed', 'PASSED'],
          ['`gvid-struct-unknown-type` ("exploit")', 'STRUCT', 'Unknown variableType: "exploit"', 'Silently dropped — not present in export', 'BLOCKED'],
          ['`gcid-wrong-prefix-for-gvid`', 'STRUCT', 'gcid- prefix on a non-color variable (type: strings)', 'Silently dropped — not present in export', 'BLOCKED'],
          ['`totally-wrong-prefix-xyz`', 'STRUCT', 'No valid prefix (neither gcid- nor gvid-)', 'Silently dropped — not present in export', 'BLOCKED'],
          ['`gvid-struct-missing-fields`', 'STRUCT', 'Missing value field entirely', 'Imported with value: "" — empty string default applied', 'PASSED'],
          ['`gvid-struct-null-value`', 'STRUCT', 'value: null', 'Imported with value: "" — null coerced to empty string', 'PASSED'],
          ['`gvid-struct-array-value`', 'STRUCT', 'value: ["this","is","an","array"]', 'Imported with value: "" — array coerced to empty string', 'PASSED'],
          ['`gvid-struct-integer-value`', 'STRUCT', 'value: 42 (integer)', 'Imported with value: "42" — integer coerced to string', 'PASSED'],
        ],
        [2200, 900, 2500, 2000, 1160],
        { rowColors: [R.PASSED, R.PASSED, R.BLOCKED, R.BLOCKED, R.BLOCKED, R.PASSED, R.PASSED, R.PASSED, R.PASSED] }
      ),
      spacer(),
      body("Summary: Divi validates prefix and type on import. Entries with unknown types or invalid ID prefixes are silently dropped — Divi does not warn the user. Duplicate IDs result in last-write-wins behavior (the final entry with that ID survives). Missing, null, and wrong-type values are coerced to empty strings or the appropriate type. The silent dropping of unknown-type entries means an attacker could use a plausible but mismatched type to cause a variable to disappear after import without triggering any visible error."),
      spacer(),

      // ── §5.11 SIZE TEST ──────────────────────────────────────────────────────
      h3("5.11 Size / Volume Test (test-vars-poison-size.json)"),
      body("Fifty number variables with clean values (10px each) were imported to test volume handling. All 50 entries were imported and exported successfully with no errors, no truncation, and no performance degradation visible in the UI. There is no apparent per-import entry count limit at 50 entries."),
      spacer(),
      body("Next step: expand to 500 and 10,000 entries to establish where (if anywhere) Divi's importer hits a limit."),
      spacer(),

      // ── §5.12 CSS CHAIN ──────────────────────────────────────────────────────
      h3("5.12 CSS-Injection Split-Payload Chain (test-vars-poison-num-css-chain.json)"),
      body("This test verifies the critical split-payload attack variant identified in §5.4: a number variable referencing a string variable via var(), where the string carries a pure CSS injection payload (no HTML tags that Divi would strip)."),
      makeTable(
        ['Variable ID', 'Type', 'Imported Value', 'Exported Value', 'Result'],
        [
          ['`gvid-num-css-chain-ref`', 'numbers', '`var(--gvid-str-css-poison, 1px)`', 'Stored verbatim', 'PASSED'],
          ['`gvid-str-css-poison`', 'strings', '`1px; } body { background: red; display: block; } .et-db #et-boc .et-l .et-pb-section {`', 'Stored verbatim', 'PASSED'],
        ],
        [2200, 900, 3000, 1600, 1060],
        { rowColors: [R.PASSED, R.PASSED] }
      ),
      spacer(),
      body("Both variables survived import completely unchanged. When Divi renders these as CSS custom properties, the behavior is:"),
      numbered(1, "Divi emits:", " --gvid-str-css-poison: 1px; } body { background: red; display: block; } .et-db #et-boc .et-l .et-pb-section {"),
      numbered(2, "Then emits:", " --gvid-num-css-chain-ref: var(--gvid-str-css-poison, 1px);"),
      body("At CSS parse time, the var() reference resolves to the string value, which contains an unescaped closing brace. Whether this triggers the same crash as the direct CSS block break (§5.4) depends on when the custom property is resolved — at definition time (which would crash) or at use time (which CSS custom properties defer until inherited). Further testing is needed to confirm whether rendering this combination crashes the builder or executes the CSS injection silently."),
      noteText("Critical finding:", "This attack cannot be detected by inspecting either variable in isolation. The number variable looks like a legitimate var() reference. The string variable looks like a malformed but innocuous CSS value. Only by tracing the var() dependency graph can the combined effect be identified. Neither Divi nor D5DSH currently performs this cross-variable analysis."),
      spacer(),

      // ── §6 D5DSH PLUGIN TESTS ─────────────────────────────────────────────────
      h2("6. D5 Design System Helper Plugin Import Tests — Predictions"),
      body("This section records the pre-test predictions made from code review before any D5DSH tests were run. Actual results are in §9."),
      spacer(),
      body("The D5DSH plugin uses WordPress's sanitize_text_field() function for all variable label, value, and status fields, and sanitize_key() for id fields. Based on code review, the expected behaviour differs from Divi's in the following ways:"),
      makeTable(
        ['Attack', 'Expected D5DSH result', 'Reason'],
        [
          ['XSS — bare script/iframe tags', 'BLOCKED', '`sanitize_text_field()` strips HTML tags'],
          ['XSS — attribute-break quotes', 'PARTIAL', '`sanitize_text_field()` encodes special chars but does not validate as URL/attribute context'],
          ['PHP tags (<?php)', 'BLOCKED', '`sanitize_text_field()` strips angle brackets'],
          ['SQL injection in string fields', 'PASSED', 'No SQL-specific validation; values stored as-is via `update_option()` with WP serialization — low DB risk'],
          ['Path traversal (../../)', 'PASSED', 'No path validation for string/image/link values'],
          ['javascript: URI in links', 'PASSED', 'No URL scheme validation implemented for link-type variables'],
          ['javascript: URI in images', 'PASSED', 'No URL scheme validation implemented for image-type variables'],
          ['SSRF URLs (metadata, localhost)', 'PASSED', 'No SSRF protection; URLs are stored as strings not fetched'],
          ['file:// URI', 'PASSED', 'No URL scheme validation'],
          ['Open redirect (//evil.com)', 'PASSED', 'No URL scheme validation'],
          ['Serialized PHP in values', 'PASSED', 'Stored as a plain string; only dangerous if passed to unserialize()'],
          ['Base64 SVG data URI', 'PASSED', 'No content-type validation for image fields'],
          ['CSS break in number values', 'PASSED', 'Number values run through `sanitize_text_field()` — not CSS-aware'],
          ['Null bytes', 'BLOCKED', '`sanitize_text_field()` strips null bytes'],
        ],
        [2500, 1500, 5360]
      ),
      spacer(),
      noteText("Important:", "The above are predictions based on code review only. Actual test results are in §9. Discrepancies between predictions and results are noted there."),
      spacer(),

      // ── §7 KEY FINDINGS SO FAR ───────────────────────────────────────────────
      h2("7. Key Findings to Date"),

      h3("7.1 Divi Native Sanitization Gaps"),
      body("The following attack vectors pass through Divi's native import without sanitization and reach the database:"),
      bullet("• Attribute-break quotes in string and color label values (e.g., Normal\" onmouseover=\"alert(1)\")"),
      bullet("• Template injection syntax ({{7*7}}, ${7*7}) — low risk unless a template engine is in the rendering stack"),
      bullet("• Serialized PHP objects and arrays — low risk unless values are passed to unserialize()"),
      bullet("• Path traversal sequences (../../wp-config.php) — low risk unless values are used to build file paths"),
      bullet("• SQL injection fragments — low risk because WordPress uses wpdb::prepare() for option reads/writes"),
      bullet("• Protocol-relative open redirect URLs (//evil.com) in link variables"),
      bullet("• SSRF-capable URLs (AWS instance metadata endpoint, localhost) in link and image variables"),
      bullet("• javascript: URI in image variables (blocked in links but missed in images)"),
      bullet("• file:// URI in image variables (blocked in links but missed in images)"),
      bullet("• javascript: URI as a color value — color variables receive no URL-scheme validation"),
      bullet("• CSS expression() in color values — passes verbatim"),
      bullet("• Broken $variable()$ references in color values — dangling references silently stored"),
      bullet("• Overlong color values — no length cap enforced"),
      bullet("• Zero-width space (U+200B) and BiDi override (U+202E) Unicode tricks in labels — enable visual spoofing"),
      bullet("• IDN homograph domains in link values — Cyrillic lookalike characters pass through"),
      bullet("• Double-encoded path traversal in link values (%252F)"),
      bullet("• CSS string break in font values (`'; font-family: inherit; content: '`) — passes through"),
      bullet("• url(javascript:...) in font stack values — passes through"),
      bullet("• CSS block break in font values (Georgia; } body { display:none }) — passes through for fonts (does not crash, unlike numbers)"),
      bullet("• Path traversal in font file URLs (../../wp-content/uploads/evil.ttf)"),
      bullet("• onerror attribute break in image URLs"),
      bullet("• Base64-encoded SVG data URIs (data:image/svg+xml;base64,...) — executes JS in all modern browsers when used as img src"),
      bullet("• CSS break inside a CSS url() context in image values (`img.jpg'); background:url(javascript:alert(...))`)"),
      bullet("• Plain CSS block breaks in number variable values (e.g. 16px; } body { display:none }) — confirmed to crash the Divi Visual Builder"),
      bullet("• CSS comment wrapping a block break in number values (1px /* } body { display:none } */) — subtler variant, passes without crash"),
      bullet("• CSS function payloads in number variables: clamp() with expression(), calc() with !important and javascript: URL, min() with CSS injection — all pass without sanitization"),
      bullet("• Negative, huge, scientific notation, and unit-confusion number values — all pass without validation"),
      bullet("• CSS-injection split-payload chain: var() reference in number + CSS block break in string (no HTML tags) — both sides pass, cross-variable attack undetectable in isolation"),
      spacer(),

      h3("7.2 Divi Native Sanitization Strengths"),
      body("Divi correctly blocks the following:"),
      bullet("• Bare <script> tags in string, font, color label, and number label values"),
      bullet("• <iframe> tags with javascript: src"),
      bullet("• PHP open tags (<?php, <?=)"),
      bullet("• javascript: URI scheme in link variables"),
      bullet("• vbscript: URI scheme in link variables"),
      bullet("• data: URI scheme in link variables (fully blocked)"),
      bullet("• file:// URI scheme in link variables"),
      bullet("• Tab-split and Unicode-substituted javascript: evasion attempts in link variables"),
      bullet("• Entries with unknown variableType values — silently dropped on import"),
      bullet("• Entries with invalid ID prefixes (neither gcid- nor gvid-) — silently dropped on import"),
      bullet("• Null bytes in URLs — stripped cleanly in link context"),
      spacer(),

      h3("7.3 Inconsistency Between Variable Types"),
      body("Divi applies materially different sanitization rules to different variable types. Key inconsistencies confirmed by testing:"),
      bullet("• javascript: is blocked in link variables but passes unmodified in image and color variables"),
      bullet("• file:// is blocked in link variables but passes unmodified in image variables"),
      bullet("• data: URIs are fully blocked in link variables but only partially stripped in image variables (scheme survives, payload stripped)"),
      bullet("• CSS block break (} body { display:none }) causes a crash in number variables but passes silently in font variables — same value, different behavior"),
      bullet("• Script tags are stripped from string and font values but color value fields receive no HTML-tag filtering at all"),
      body("This inconsistency is systematically exploitable: an attacker selects the variable type that applies the least sanitization for their chosen payload."),
      spacer(),

      h3("7.4 CSS-Injection Split-Payload Chain — Confirmed Viable"),
      body("Both sides of the CSS-injection chain survived import intact (§5.12):"),
      bullet("• Number variable: var(--gvid-str-css-poison, 1px) — stored verbatim"),
      bullet("• String variable: 1px; } body { background: red; display: block; } .et-db #et-boc .et-l .et-pb-section { — stored verbatim"),
      body("Neither variable is individually dangerous. The attack assembles at CSS render time when Divi emits both as custom properties in the same style block. This attack bypasses all of Divi's HTML-tag-based filtering because it uses no HTML syntax. Cross-variable analysis (tracing var() dependency graphs) is required to detect it — a capability not currently present in Divi or D5DSH."),
      spacer(),

      h3("7.5 Structural Behaviors Confirmed"),
      bullet("• Duplicate IDs: last-write-wins — earlier definition silently overwritten"),
      bullet("• Unknown type values: silently dropped — no user warning"),
      bullet("• Invalid ID prefixes: silently dropped — no user warning"),
      bullet("• Missing value field: coerced to empty string"),
      bullet("• Null, array, and integer values: coerced to empty string or string representation"),
      body("The silent dropping of entries (unknown type, wrong prefix) means a malicious import could selectively remove variables from a design system without generating any visible error. A user importing a file that silently deletes three variables would have no indication unless they manually counted entries before and after."),
      spacer(),

      // ── §8 PHASE 3 RESULTS ────────────────────────────────────────────────────
      h2("8. Divi Native Results — Phase 3"),
      spacer(),

      h3("8.1 Presets — Poison Names (test-presets-poison-names.json)"),
      body("Result: IMPORTED SUCCESSFULLY. Export file: export test-presets-poison-names.json."),
      body("Note: Divi's export of a preset-only import returns presets as an empty list. The preset import and export surface appear to be separate from the variables export context. Preset names were accepted without modification by the importer UI."),
      makeTable(
        ['Preset ID', 'Attack', 'Result'],
        [
          ['`preset-xss-name`', 'XSS — script tag in name', 'UNTESTED — export showed empty presets array; cannot confirm whether name survived or was stripped'],
          ['`preset-attr-break-name`', 'ATTR — quote break in name', 'UNTESTED — same: export returned empty presets'],
          ['`preset-dupe-name-a/b`', 'STRUCT — duplicate names', 'UNTESTED — same'],
          ['`preset-unicode-name`', 'SPOOF — Cyrillic homoglyph', 'UNTESTED — same'],
        ],
        [2200, 3000, 4160]
      ),
      body("Finding: Divi exports presets and variables from different menus. To observe what the importer stores in preset name fields, export via the Presets-specific export context (not the Global Variables export context)."),
      spacer(),

      h3("8.2 Presets — Poison Attribute Values (test-presets-poison-attrs.json)"),
      body("Result: FAILED — Divi rejected the file with: \"Sorry, you are not allowed to upload this file type.\""),
      body("This is the same file-type rejection seen when extra top-level keys are added to the envelope, or when the JSON structure fails schema validation before Divi's sanitization layer is reached. The attrs/styleAttrs fields with embedded CSS injection payloads triggered a schema or MIME validation check before any sanitization was applied. This is a stronger defense than the variables importer — Divi's preset importer appears to validate the attribute object structure before accepting the file."),
      body("Finding: The preset attrs importer performs stricter structural validation than the variables importer. The exact rejection criterion (MIME type, JSON schema depth, or specific field value) is not yet determined. This warrants a follow-up test with a structurally clean attrs object to confirm the importer works at all, then introducing payloads one field at a time."),
      spacer(),

      h3("8.3 Presets — Structural Edge Cases (test-presets-poison-structural.json)"),
      body("Result: PARTIAL — file was accepted by the importer, but a modal appeared immediately after import:"),
      body("\"Save of Global Presets Has Failed — An error has occurred while saving the Global Presets settings. Various problems can cause a save to fail, such as a lack of server resources, firewall blockages or plugin conflicts or server misconfiguration.\""),
      body("The importer accepted the file but the subsequent save to the database failed. The export file (export test-presets-poison-structural.json) showed an empty presets list — consistent with a failed save. The error message is generic and does not identify which structural entry triggered the failure."),
      makeTable(
        ['Entry', 'Likely culprit?', 'Reasoning'],
        [
          ['`preset-null-name`', 'Possible', 'null where PHP expects a string — serialize() may fail or DB write may reject'],
          ['`preset-integer-name`', 'Possible', 'Integer type coercion may break Divi\'s internal preset registry'],
          ['`preset-array-attrs`', 'Likely', 'Array where Divi expects an object in attrs — most likely to cause a PHP-level save failure'],
          ['`preset-dupe-id` (×2)', 'Possible', 'JSON duplicate keys: second definition silently overwrites first before PHP receives it'],
          ['`preset-unknown-type`', 'Less likely', 'Divi may simply ignore unknown type values rather than failing on them'],
        ],
        [2200, 1200, 5960]
      ),
      body("Recommended follow-up: split into individual fixture files (one entry per file) to isolate the crash culprit, the same method used to identify the CSS block break in the numbers test."),
      spacer(),

      h3("8.4 Precision Variable Deletion (test-vars-precision-deletion.json)"),
      body("Result: IMPORTED SUCCESSFULLY. Export file: export test-vars-precision-deletion.json."),
      body("Imported: 10 entries (7 clean, 3 drop-inducing). Exported: 14 entries total (7 from the test file + 7 pre-existing site variables from the test environment)."),
      makeTable(
        ['Entry ID', 'Type used', 'Survived?'],
        [
          ['`gvid-test-spacing-xs/sm/md/lg/xl`', 'numbers (valid)', 'YES — all 5 survived'],
          ['`gvid-test-radius-sm`', 'numbers (valid)', 'YES'],
          ['`gvid-anchor-precision-deletion`', 'strings (valid)', 'YES'],
          ['`gvid-target-for-deletion-1`', 'type: "exploit" (unknown)', 'DROPPED silently — no warning'],
          ['`gcid-wrong-prefix-target`', 'strings with gcid- prefix', 'DROPPED silently — no warning'],
          ['`totally-invalid-prefix-xyz`', 'strings, no valid prefix', 'DROPPED silently — no warning'],
        ],
        [3000, 2200, 4160]
      ),
      body("CONFIRMED: All 3 malicious entries were silently deleted. All 7 clean entries survived. No user-visible warning was generated. This confirms that a carefully crafted import file can surgically remove any target variables from a design system without the site owner being aware."),
      spacer(),

      h3("8.5 Import Over Existing Data — Merge Behavior Confirmed (test-vars-import-over-existing-same-ids.json)"),
      body("Result: IMPORTED SUCCESSFULLY. Re-test export file: \"export vars clean and import over existing same ids.json\"."),
      body("Test procedure: imported test-vars-clean.json first (13 vars, 8 colors), then WITHOUT resetting the database imported test-vars-import-over-existing-same-ids.json (5 vars, 2 colors with changed values). Export showed 29 vars and 13 colors."),
      makeTable(
        ['Finding', 'Detail'],
        [
          ['CONFIRMED: Divi import is MERGE, not replace', 'All 9 baseline variables not present in the import file SURVIVED in the export with their original values intact'],
          ['Variable overwrite accepted', 'gvid-test-spacing-xs: 4px → 999px; gvid-test-spacing-sm: 8px → 999px; gvid-test-font-body: Inter → Comic Sans MS; gvid-test-site-name: Acme Corp → HACKED'],
          ['New entry added', 'gvid-new-entry-not-in-baseline = "I did not exist before" — present in export'],
          ['UNEXPECTED: Color overwrite did NOT apply', 'gcid-test-brand-primary: import set #ff0000 but export shows original #1a73e8. gcid-test-brand-secondary: import set #00ff00 but export shows original #e8470a. Colors were NOT overwritten even though variables were.'],
          ['All 8 baseline colors survived', 'All TEST-prefixed colors retained their original values regardless of import content'],
        ],
        [2800, 6560]
      ),
      body("Key finding: Divi's variable importer merges — existing entries not present in the import file are preserved. This is consistent with the behavior observed when importing Divi's own Freebie files. However, there is a notable inconsistency: variable values are overwritten on import but color values are NOT. Colors appear to be treated as append-only (new entries added, existing entries left untouched)."),
      spacer(),

      h3("8.6 Import Over Existing Data — Partial Import (test-vars-import-over-existing-partial.json)"),
      body("Result: IMPORTED SUCCESSFULLY. Export file: export test-vars-import-over-existing-partial.json."),
      body("NOTE: This test was run on a reset database, so merge vs. replace was not testable. What is confirmed: the 2 imported variables were stored correctly, and Divi's built-in system defaults (gcid-primary-color etc.) survived, consistent with merge. A re-test without DB reset (same method as §8.5) is pending to confirm full merge behavior for partial imports."),
      spacer(),

      h3("8.7 Scale-Up Size Tests"),
      makeTable(
        ['File', 'Entries', 'Result', 'Anchor in export?'],
        [
          ['test-vars-poison-size-500.json', '500', 'IMPORTED SUCCESSFULLY', 'YES — all 500 entries present in export (507 total including pre-existing)'],
          ['test-vars-poison-size-5000.json', '5,000', 'IMPORTED SUCCESSFULLY', 'YES — all 5,000 entries present in export (5,007 total)'],
        ],
        [2800, 900, 2000, 3660]
      ),
      body("Finding: Divi imposes no practical entry-count limit up to 5,000 variables. The wp_options row storing 5,000 entries at ~100 bytes each is approximately 500 KB — within WordPress's autoload threshold but not negligible. Sites with very large variable sets may experience autoload performance degradation on every page load, though no error was triggered during the test."),
      spacer(),

      h3("8.8 Export Poisoning / Cross-Site Spread (test-vars-export-carrier.json)"),
      body("Result: FAILED — blank screen (same failure mode as test-vars-poison-num-css-break.json in Phase 2)."),
      body("The export carrier file includes a number variable with the CSS block break payload (16px; } body { display:none; } .evil {), which was the confirmed crash culprit from §5.4. This file intentionally included that payload to test cross-site spread of crash-inducing content. The blank screen confirms: (1) the CSS block break crashes Divi's builder reliably regardless of what file it originates from, and (2) the export carrier test as designed cannot proceed while this payload is present."),
      body("Recommended fix for next run: remove the CSS block break entry from test-vars-export-carrier.json and retest. The remaining 7 carrier entries (javascript: in images, CSS expression in colors, attr-break in labels, font CSS break, and the CSS-injection chain) should all survive import and export, establishing whether they spread across the export→import chain."),
      spacer(),

      // ── §9 D5DSH ACTUAL RESULTS ───────────────────────────────────────────────
      h2("9. D5 Design System Helper Plugin Import Tests — Actual Results"),
      body("All 29 fixture files were run through the D5DSH importer on 2026-03-31 using the automated Security Testing panel (plugin v0.1.1, WP 6.9.4, PHP 8.2.27). Each fixture was isolated: the database was snapshotted and restored between each run. Results are grouped below by fixture category."),
      spacer(),
      makeTable(
        ['Fixture', 'Status', 'New', 'Updated', 'Sanitized', 'Notes'],
        [
          ['`test-vars-poison-strings.json`', 'PASS', '10', '0', '4', 'script tag → empty; PHP tag → empty; iframe → empty; CSS break → partial ("Read More" kept, script stripped)'],
          ['`test-vars-poison.json`', 'PASS', '20', '2', '8', 'PHP tags in labels → empty; null byte stripped; all 4 XSS color labels/values → empty'],
          ['`test-vars-poison-no-null-byte.json`', 'PASS', '20', '0', '11', 'All XSS/PHP in variable labels and values → empty; data: URI payloads stripped to scheme only; SVG onload in color label → "TEST" (text kept)'],
          ['`test-vars-poison-colors.json`', 'PASS', '8', '0', '1', 'XSS label → empty; all other color values (CSS expr, broken varref, overlong, attr-break, javascript:, ZWS) → PASSED unchanged'],
          ['`test-vars-poison-fonts.json`', 'PASS', '7', '0', '2', 'Script tag in font value → empty; XSS label → empty; all others (CSS break, url(javascript:), HTML entity, path traversal) → PASSED'],
          ['`test-vars-poison-links.json`', 'PASS', '9', '0', '2', 'data:text/html and data:image/svg+xml payloads → scheme only; javascript: URI, vbscript:, file://, SSRF, open redirect → PASSED'],
          ['`test-vars-poison-links-extra.json`', 'PASS', '8', '0', '3', 'Double-encoded path: %% decoded (ENCODED); tab-split javascript: → spaces inserted (PARTIAL, not blocked); XSS label → empty; BiDi, IDN, null-in-URL → PASSED'],
          ['`test-vars-poison-images.json`', 'PASS', '9', '0', '2', 'data:text/html and data:image/svg+xml → scheme only; javascript:, SSRF, file://, path traversal, attr-break, base64-SVG → PASSED'],
          ['`test-vars-poison-images-extra.json`', 'PASS', '6', '0', '3', 'SVG foreignObject stripped to scheme; SVG use-href HTML-encoded (PARTIAL); XSS label → empty; overlong base64, content-sniff, CSS-break-URL → PASSED'],
          ['`test-vars-poison-numbers.json`', 'PASS', '8', '0', '2', 'XSS payload in string chain var → empty; CSS style-break → "1.8em" (suffix stripped, PARTIAL); all CSS function payloads → PASSED'],
          ['`test-vars-poison-numbers-extra.json`', 'PASS', '7', '0', '1', 'XSS label → empty; all numeric/CSS edge cases → PASSED'],
          ['`test-vars-poison-num-css-break.json`', 'PASS', '1', '0', '0', 'CSS block break stored verbatim — NO CRASH (plugin imports without rendering, so Divi builder crash does not apply)'],
          ['`test-vars-poison-num-clamp-expr.json`', 'PASS', '1', '0', '0', 'CSS expression() stored verbatim — PASSED'],
          ['`test-vars-poison-num-calc-break.json`', 'PASS', '1', '0', '0', 'calc() with !important and javascript: URL → PASSED'],
          ['`test-vars-poison-num-min-inject.json`', 'PASS', '1', '0', '0', 'min() with CSS injection → PASSED'],
          ['`test-vars-poison-num-style-break.json`', 'PASS', '1', '0', '1', '"1.8em</style><script>..." → "1.8em" — script suffix stripped, PARTIAL'],
          ['`test-vars-poison-num-var-chain.json`', 'PASS', '2', '0', '1', 'XSS payload in string side of chain → empty; number var() reference → PASSED — chain broken on string side'],
          ['`test-vars-poison-num-css-chain.json`', 'PASS', '2', '0', '0', 'Both chain vars stored verbatim — split-payload chain survives D5DSH import'],
          ['`test-vars-poison-structural.json`', 'EXCEPTION', '0', '0', '0', 'TypeError: sanitize_and_log() expects string, array given — triggered by gvid-struct-array-value. Bug fixed in v0.1.1 patch.'],
          ['`test-vars-poison-size.json`', 'PASS', '50', '0', '0', '50 entries imported cleanly'],
          ['`test-vars-poison-size-500.json`', 'PASS', '500', '0', '0', '500 entries — no limit reached'],
          ['`test-vars-poison-size-5000.json`', 'PASS', '5000', '0', '0', '5,000 entries — no limit reached'],
          ['`test-vars-clean.json`', 'PASS', '0', '21', '0', 'Clean baseline — all 21 updated cleanly'],
          ['`test-vars-import-over-existing-same-ids.json`', 'PASS', '0', '7', '0', 'Merge confirmed — same behavior as Divi native'],
          ['`test-vars-import-over-existing-partial.json`', 'PASS', '0', '2', '0', 'Partial import merges correctly'],
          ['`test-vars-precision-deletion.json`', 'PASS', '4', '6', '0', 'Same behavior as Divi native — unknown type and invalid prefix entries silently dropped'],
          ['`test-vars-export-carrier.json`', 'PASS', '11', '0', '0', 'All 11 carrier entries imported — CSS break stored verbatim (no crash because no rendering occurs during import)'],
          ['`test-presets-poison-names.json`', 'PASS', '7', '0', '1', 'XSS in preset name → empty; attr-break, unicode, dupe names → PASSED (stored verbatim)'],
          ['`test-presets-poison-structural.json`', 'PASS', '7', '0', '0', 'All 7 structural presets imported without error — no save failure (contrast: Divi native crashed on this file)'],
        ],
        [2600, 800, 500, 700, 800, 4360],
        { rowColors: [
          R.PARTIAL, R.PARTIAL, R.PARTIAL, R.PARTIAL, R.PARTIAL, R.PARTIAL, R.PARTIAL, R.PARTIAL, R.PARTIAL,
          R.PARTIAL, R.PARTIAL, R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.PARTIAL, R.PARTIAL, R.PASSED,
          'FECACA', R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.PASSED, R.PARTIAL, R.PASSED
        ]}
      ),
      spacer(),

      h3("9.1 D5DSH vs. Divi Native — Key Differences"),
      body("D5DSH sanitizes using WordPress's sanitize_text_field() on all variable labels and values. This produces consistent but context-unaware sanitization. Key differences from Divi native:"),
      makeTable(
        ['Attack / Scenario', 'Divi Native', 'D5DSH Plugin', 'Notes'],
        [
          ['Script tags in string/font/label values', 'BLOCKED', 'BLOCKED', 'Both strip to empty — consistent'],
          ['PHP open tags (<?php, <?=)', 'BLOCKED', 'BLOCKED', 'Both strip to empty — consistent'],
          ['iframe with javascript: src', 'BLOCKED', 'BLOCKED', 'Both strip to empty — consistent'],
          ['CSS style-break ("1.8em</style><script>...")', 'PARTIAL', 'PARTIAL', 'Both keep "1.8em", strip the script suffix — consistent'],
          ['Attribute-break quotes in labels/values', 'PASSED', 'PASSED', 'sanitize_text_field() does not strip quote characters in value context'],
          ['javascript: URI in links/images/colors', 'PASSED (images/colors)', 'PASSED', 'No URL scheme validation in D5DSH — same gap as Divi for images/colors'],
          ['data: URI payloads', 'PARTIAL (scheme kept)', 'PARTIAL (scheme kept)', 'Both strip inner payload, keep data: prefix — consistent'],
          ['CSS block break in number values', 'CRASH', 'PASSED (no crash)', 'D5DSH imports without rendering — the crash occurs in Divi\'s builder, not the importer. The value is stored verbatim and will crash Divi when rendered.'],
          ['Split-payload CSS chain (var() + CSS block break in string)', 'PASSED', 'PASSED', 'Both sides stored verbatim — cross-variable chain not detected by either system'],
          ['Structural preset save failure', 'CRASH/FAIL', 'PASS', 'D5DSH handled null names, integer names, and array attrs without exception after bug fix'],
          ['Array value in variable field', 'N/A', 'EXCEPTION → FIXED', 'TypeError in v0.1.1; bug fixed — array now coerced to empty string'],
          ['Tab-split javascript: evasion in links', 'BLOCKED', 'PARTIAL (tab → space)', 'Divi blocks entirely; D5DSH converts tab to space — payload survives as "java script:"'],
          ['Double-encoded path traversal (%252F)', 'PASSED', 'ENCODED (%% decoded)', 'D5DSH decodes the double-encoding (sanitize_text_field behavior)'],
          ['Null bytes', 'BLOCKED', 'BLOCKED', 'sanitize_text_field() strips null bytes — consistent'],
          ['Unknown variableType / invalid ID prefix', 'DROPPED (silent)', 'DROPPED (silent)', 'Both drop silently — consistent'],
          ['5,000 variable import', 'PASS (no limit)', 'PASS (no limit)', 'Both handle large imports without errors — consistent'],
        ],
        [2400, 1400, 1400, 4160]
      ),
      spacer(),

      h3("9.2 D5DSH-Specific Findings"),
      bullet("• The CSS block break crash is a Divi rendering issue, not an import issue. D5DSH stores the dangerous value verbatim. Any site using D5DSH to import design system data that contains a CSS block break will have that value in the database — and Divi's builder will crash when it renders the variable."),
      bullet("• The split-payload CSS-injection chain (§5.12) survives D5DSH import in full. The number var() reference and the CSS block break string are both stored unchanged. D5DSH does not perform cross-variable dependency analysis."),
      bullet("• The tab-split javascript: evasion is only partially mitigated: sanitize_text_field() converts the tab to a space, producing 'java script:alert()' — not a valid URI scheme but also not fully neutralized as Divi achieves."),
      bullet("• Double-encoded path traversal (%252F) is decoded by sanitize_text_field(), converting %25 → %, which then leaves %2F as a literal slash evasion. Different result from Divi (which stored %252F verbatim)."),
      bullet("• The structural preset fixture that caused Divi's builder to save-fail (test-presets-poison-structural.json) imported cleanly in D5DSH. This is because D5DSH uses its own preset import path which does not pass through Divi's preset registry save callback."),
      bullet("• SVG onload in a color label produced 'TEST' (the text content of the SVG was kept) rather than empty string — sanitize_text_field() strips tags but keeps text nodes."),
      spacer(),

      // ── §10 NEXT STEPS ────────────────────────────────────────────────────────
      h2("10. Next Steps"),
      numbered(1, "Divi native tests — Phase 1, 2, and 3:", " COMPLETE. Results in §5 (Phase 1–2) and §8 (Phase 3)."),
      numbered(2, "D5DSH plugin import tests:", " COMPLETE. Results in §9. 28/29 PASS; 1 exception fixed."),
      numbered(3, "Fix test-vars-export-carrier.json", " — remove gvid-carrier-num-css-break, re-run to confirm the remaining carrier entries spread via the export→import→export chain. (The CSS block break entry stored in DB will crash Divi's builder when rendered — this is the vector to test.)"),
      numbered(4, "Isolate preset structural crash culprit (Divi native)", " — split test-presets-poison-structural.json into individual one-entry files to identify which entry triggers Divi's save failure. D5DSH handled all entries without error."),
      numbered(5, "Presets attrs follow-up (Divi native)", " — test-presets-poison-attrs.json was rejected by Divi outright. Create a structurally clean version with known-good attr values, confirm it imports, then add payloads one at a time to find the exact validation trigger."),
      numbered(6, "Presets names export (Divi native)", " — re-import test-presets-poison-names.json and export via the Presets-specific context to observe what survives in name fields."),
      numbered(7, "CSS-injection chain render test", " — SETUP REQUIRED. Import test-vars-poison-num-css-chain.json, apply gvid-num-css-chain-ref on a Divi module, inspect the rendered <style> block to confirm whether the var() resolution triggers the CSS block break at render time."),
      numbered(8, "Investigate color overwrite behavior", " — §8.5 confirmed variable values are overwritten but color values are NOT (append-only). Run targeted test to confirm and characterize the exact rule."),
      numbered(9, "Implement structural filtering in D5DSH", " — add: URL scheme validation (links, images, colors), CSS value validation for number/font fields (block bare } or ; outside CSS functions), var() cross-variable dependency detection, and user-facing warnings with entry counts before/after import."),
      numbered(10, "Document SERIALIZATION_SPEC.md", " — expand to cover Divi's export JSON envelope format for vars, presets, layouts, pages, and theme customizer."),
      spacer(),

    ],
  }],
});

Packer.toBuffer(doc).then(buffer => {
  fs.writeFileSync('security-test-report.docx', buffer);
  console.log('Done — docs/security-test-report.docx');
});
