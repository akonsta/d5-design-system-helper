# Changelog

All notable changes to D5 Design System Helper are documented here.

## Version numbering

Public releases use three-part version numbers: `major.feature.minor` (e.g. `0.1.2`).
Interim builds — development snapshots between public releases — use a fourth element: `major.feature.minor.build` (e.g. `0.1.1.1`, `0.1.1.2`).
Interim builds are documented here for contributor visibility but are not published as GitHub releases. The next public release absorbs all interim changes.

---

## [0.1.1.2] – 2026-03-31

Interim build. Import security hardening (CSS value sanitization, wp_unslash, multiline fix, zip traversal pre-extraction guard); output escaping improvements in admin.js (DOM API conversions); security test report updated with revised risk classifications and new SAFE_DEFAULT result code.

### Added

- **`SimpleImporter::sanitize_css_value()`** — strips bare `{` and `}` from CSS property values to prevent CSS block-break injection being stored as a carrier payload. Applied to number and font variable value fields in both JSON and Excel import paths.
- **`SAFE_DEFAULT` result code** in security test report taxonomy (`security-info/security-test-report.md`) — distinguishes structural normalization (null/missing/wrong-type fields safely coerced) from `BLOCKED` (payload stripped) and `PASSED` (payload stored verbatim). Four `§5.10` rows recoded accordingly.
- **`setElMsg(el, className, text)` JS utility** (`assets/js/admin.js`) — creates a `<p>` via `createElement` and sets content via `textContent`; replaces inline `innerHTML` + `escHtml()` string concatenation for single-message status updates.

### Changed

- **`SimpleImporter::sanitize_deep()` and `sanitize_meta_value()`** — switched from `sanitize_text_field` to `sanitize_textarea_field` for string leaves, preserving legitimate multiline content (CSS blocks, code values) that `sanitize_text_field` was silently collapsing to a single line.
- **`SimpleImporter::sanitize_and_log()`** — extended with `'css_value'` and `'textarea'` method options to match the above.
- **`AdminPage::handle_export()`** — all 8 `$_POST` reads now wrapped with `wp_unslash()` before sanitization, preventing double-escaped apostrophes in exported metadata fields.
- **`SimpleImporter::analyze_zip()`** — added pre-extraction traversal guard: iterates all zip entries via `ZipArchive::getNameIndex()` before calling `extractTo()`, rejecting any entry whose normalised path starts with `/`, contains `../`, or is `..` exactly. `realpath()`-only checks cannot guard against non-existent paths before extraction.
- **`SimpleImporter::validate_path_within()`** — added parent-directory fallback: when `realpath($path)` returns false (file does not exist yet), resolves the parent directory with `realpath()` and appends `basename()` to construct a valid boundary-checkable path.
- **`assets/js/admin.js`** — 16 single-message `innerHTML` concatenation patterns converted to `setElMsg()` or `createElement` + `textContent`. Color swatch inline style set via `.style.background` property (never via `innerHTML`). No complex HTML builders were changed.
- **Security test report** (`security-info/security-test-report.md`) — §7.1 risk reclassifications: template injection / serialised PHP / path traversal upgraded from Low to Medium (WordPress shared `wp_options` / PHP Object Injection vector); SQL injection upgraded from Low to Low–Medium (second-order SQLi via third-party plugin); `%252F` finding updated to name WAF-evasion significance; CSS `expression()` downgraded to informational (IE ≤7 only, no modern-browser risk).

### Fixed

- **`tests/Unit/Admin/SimpleImporterTest.php`** — split `validate_path_within_rejects_nonexistent_path` into two tests to correctly reflect the updated method behavior: one asserting non-existent files inside the base are accepted, one asserting traversal outside the base is still rejected.

---

## [0.1.1.1] – 2026-03-31

Interim build. Security testing infrastructure and full security test run.

### Added

- **`SecurityTestRunner`** (`includes/Cli/SecurityTestRunner.php`) — shared engine used by both the WP-CLI command and the admin AJAX handler. Accepts a batch of decoded JSON fixtures, snapshots the database before each test, imports via the normal `SimpleImporter` path, captures the sanitization log, and restores the database between each run. Returns a structured report with per-fixture results and summary totals.
- **`SecurityTestCommand`** (`includes/Cli/SecurityTestCommand.php`) — WP-CLI command (`wp d5dsh security-test`) that scans a directory for `*.json` fixture files, delegates to `SecurityTestRunner`, prints colorized pass/fail output, writes post-import export files (in Divi-native envelope format) for diff comparison, and saves a timestamped JSON report to the output directory.
- **Security test fixtures** (`tests/fixtures/security/`) — ~85 JSON fixture files covering all tested attack categories: XSS, PHP injection, CSS injection (block break, split-payload chain, clamp/calc/min expressions), SQL injection, path traversal, SSRF, null bytes, serialization, structural edge cases, size/volume (50/500/5,000 entries), preset poisoning, precision deletion, import-over-existing merge behavior, and export carrier. Both Divi-native and D5DSH post-import exports are included for comparison.
- **Security test report** (`docs/security-test-report-builder.js` / `docs/security-test-report.docx`) — 10-section Word document covering test environment, methodology, attack taxonomy, full Divi native results (§5, §8), D5DSH code-review predictions (§6), key findings (§7), and D5DSH actual results (§9). All 29 D5DSH fixtures run: 28/29 PASS, 1 exception (fixed — see below).

### Fixed

- **`SimpleImporter::sanitize_and_log()`** — added a type guard so that non-string values (arrays, null) passed to this method are coerced to an empty string rather than throwing a `TypeError`. Triggered by the structural fixture containing `"value": ["this","is","an","array"]`.

---

## [0.1.1] – 2026-03-31

### Added

- **Security Testing panel** — a developer tool on the Import tab (visible only when **Show Security Testing Features** is enabled in Settings → Advanced). Upload one or more `.json` fixture files directly from the browser, or provide a server-side directory path, and the plugin runs every file through the normal import pipeline. Each fixture is isolated: the database is snapshotted before import and restored immediately after. Results appear in a table showing status (PASS / WARN / FAIL), import counts, and a collapsible sanitization log for any fields that were modified. The full report is written to `wp-content/uploads/d5dsh-logs/` and can be downloaded as JSON from the results panel.
- **WP-CLI command** — `wp d5dsh security-test --dir=<path> [--out=<path>] [--verbose]` — runs the same fixture pipeline from the command line and writes a structured JSON report. Both the CLI command and the UI panel share the same `SecurityTestRunner` engine.

---

## [0.1.0] – 2026-03-27

First public release. The codebase is identical to internal development version 0.6.40 — the version number resets to 0.1.0 to reflect the start of public versioning.

### Features

- **Manage tab** — live tables for Variables, Group Presets, Element Presets, All Presets, Everything, and Categories. Column filters, drag-to-resize columns with localStorage persistence, inline label/value editing (beta), bulk label operations, Impact modal with dependency tree
- **Export** — Excel (.xlsx) and JSON (native + DTCG) export for variables and presets; CSV export from Manage tab; full zip export (variables + presets + layouts + pages + theme customizer + builder templates)
- **Import** — Excel, JSON (native + DTCG), and zip import with full sanitization and sanitization report UI
- **Audit** — Simple Audit (14 checks, no content scan) and Contextual Audit (22 checks with full content scan); Excel export of audit report
- **Help** — contextual help panel with per-tab documentation

Test suite: 439 tests, 954 assertions passing.

### Requirements

- WordPress 6.2+
- PHP 8.1+
- Divi 5.0+
