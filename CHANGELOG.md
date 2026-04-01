# Changelog

All notable changes to D5 Design System Helper are documented here.

## Version numbering

Public releases use three-part version numbers: `major.feature.minor` (e.g. `0.1.2`).
Interim builds — development snapshots between public releases — use a fourth element: `major.feature.minor.build` (e.g. `0.1.1.1`, `0.1.1.2`).
Interim builds are documented here for contributor visibility but are not published as GitHub releases. The next public release absorbs all interim changes.

---

## [0.1.2] – 2026-04-01

### Added

- **Pre-Import Audit** — before committing any import, click **Pre-Import Audit** in the Import tab toolbar to run a five-check health report against the staged file. Checks: broken preset references (`broken_refs_in_file`), items that will overwrite existing variables (`conflict_overwrite`), label collisions with existing variables (`label_clash`), variables not referenced by any preset in the file (`orphaned_in_file`), and naming convention mismatches versus the live site (`naming_convention`). Results appear in a panel below the analysis area; the Import button gains a red border warning if errors are found. Download the full report as `.xlsx`. Available for JSON, vars, and presets file types.
- **First-Run Setup Wizard** — on the first plugin load a 5-step modal opens automatically. Configures site/organisation name, file name prefix, report header and footer styles, date format, page number format, and optional Beta Preview / Debug Mode flags. Skipping shows a dismissible notice pointing to Settings. See Section 26 of the user guide.
- **Report header mode** (`report_header_mode`) — four options: plugin + site name (default), site name only, custom text, or no header.
- **Report footer mode** (`report_footer_mode`) — four options: date + page, page only, custom text + page, or no footer.
- **Date format setting** (`footer_date_format`) — six options including an option to inherit the WordPress General Settings date format.
- **Page number format setting** (`footer_page_format`) — five options (Page X of N, Page X, X/N, X, or none).
- **Debug system** — enable Debug Mode in Settings → Advanced to write detailed error information to a log file inside your uploads directory. See Section 24 of the user guide.
- **Error Reference** — Section 25 of the user guide documents all known error messages grouped by subsystem, with causes and fixes.
- **Security testing infrastructure** — `SecurityTestRunner` class and WP-CLI command (`wp d5dsh security-test`) that runs the full security test suite against a live site's import pipeline. Produces a structured report with per-fixture results and summary totals.
- **570 PHPUnit tests** — covers AuditEngine (all 22 checks), PreImportAuditor (all 5 checks), SimpleImporter (sanitization), AdminPage (settings), and all other core classes. Full test suite runs in < 1 second.

### Changed

- **Import tab audit button** — the former Beta-gated "Audit" button now runs the Pre-Import Audit against the staged file (not a live site audit). The Beta badge is removed; the feature is now standard.
- **Audit XLSX Summary sheet** — column headers renamed: "Checks" → "Checks Run", "Total Items" → "Findings". Prevents misleading display when checks run cleanly.
- **Import sanitization hardening** — CSS value fields now strip bare `{`/`}` to prevent CSS block-break injection. All `$_POST` reads in `handle_export()` wrapped with `wp_unslash()`. Zip archives are now validated for path traversal before extraction (not just after). `sanitize_text_field` replaced with `sanitize_textarea_field` for multiline content fields.
- **DOM API safety** — 16 `innerHTML` concatenation patterns in `admin.js` replaced with `createElement` / `textContent` / `.style.*` assignments to eliminate any XSS surface in client-side rendering.

### Fixed

- Zip import path-traversal guard now correctly handles non-existent paths inside the base directory (previously rejected valid unextracted paths).

---

## [0.1.1.3] – 2026-03-31

Interim build. Pre-Import Audit feature; first-run setup wizard; expanded report header/footer/date/page settings; audit worksheet column label fix; 47 new PHPUnit tests for PreImportAuditor; documentation updates.

### Added

- **Pre-Import Audit** (`includes/Admin/PreImportAuditor.php`) — new static class that runs five checks against a staged import file before the user commits. Checks: `broken_refs_in_file` (Error), `conflict_overwrite` (Warning), `label_clash` (Warning), `orphaned_in_file` (Advisory), `naming_convention` (Advisory). Report shape matches `AuditEngine` output for XLSX reuse.
- **`ajax_pre_import_audit()` and `ajax_pre_import_audit_xlsx()`** in `AdminPage` — two new AJAX handlers. The audit handler reads the staged file from the `d5dsh_si_{user_id}` session transient (already cached by `ajax_analyze`) and calls `PreImportAuditor::run()`. The XLSX handler passes the report to `AuditExporter::export_audit_xlsx()`.
- **Pre-Import Audit panel** in the Import tab UI — collapsible panel below the analysis area showing summary pills, meta line, and per-tier check tables. Download Report (.xlsx) button in the panel header. Import button gains red border warning state when errors are found.
- **`tests/Unit/Admin/PreImportAuditorTest.php`** — 47 PHPUnit tests covering all 5 checks, helper methods (`detect_name_style`, `extract_var_refs_from_attrs`, `build_site_var_index`, `extract_file_vars`), and `run()` integration / meta shape.
- **`PLUGIN_USER_GUIDE.md` Section 8 — Pre-Import Audit subsection** — documents all 5 checks, the Import button warning state, and the Download Report button.

### Changed

- **Import tab "Audit" button** — removed Beta badge; button renamed "Pre-Import Audit" and rewired from `d5dsh_audit_run` (live site audit) to `d5dsh_pre_import_audit` (file audit). Button is now a standard feature.
- **Audit XLSX Summary sheet column headers** — "Checks" renamed to "Checks Run"; "Total Items" renamed to "Findings". Prevents confusion when checks run but produce zero findings.
- **Section 12 (Analysis Tab)** in user guide — added explicit note that the Analysis tab audits live site data only, with a cross-reference to Section 8 for pre-import auditing.
- **Section 20 (Beta Features)** in user guide — removed "Import Audit button" from the beta feature list.
- **`PLUGIN_USER_GUIDE.md` version table** — updated to reflect 0.1.1.3.

---

## [0.1.1.2] – 2026-03-31

Interim build. Import security hardening (CSS value sanitization, wp_unslash, multiline fix, zip traversal pre-extraction guard); output escaping improvements in admin.js (DOM API conversions); security test report updated with revised risk classifications and new SAFE_DEFAULT result code; documentation updates for security additions and debug system; first-run setup wizard; expanded report header/footer/date/page-number settings.

### Added

- **`SimpleImporter::sanitize_css_value()`** — strips bare `{` and `}` from CSS property values to prevent CSS block-break injection being stored as a carrier payload. Applied to number and font variable value fields in both JSON and Excel import paths.
- **`SAFE_DEFAULT` result code** in security test report taxonomy (`security-info/security-test-report.md`) — distinguishes structural normalization (null/missing/wrong-type fields safely coerced) from `BLOCKED` (payload stripped) and `PASSED` (payload stored verbatim).
- **`setElMsg(el, className, text)` JS utility** (`assets/js/admin.js`) — creates a `<p>` via `createElement` and sets content via `textContent`; replaces inline `innerHTML` + `escHtml()` string concatenation for single-message status updates.
- **First-run setup wizard** (`initWizard()` in `admin.js`, wizard modal in `AdminPage.php`) — 5-step modal that opens automatically on first plugin load. Configures site name, file name prefix, report header mode, report footer mode, date format, page number format, and optional beta/debug flags. Skipping shows a dismissible notice. Controlled by `setup_complete` flag in `d5dsh_settings`. See Section 26 of user guide.
- **Report header mode setting** (`report_header_mode`) — four options: `default` (plugin + site name), `site` (site name only), `custom` (free text), `none`. Stored in `d5dsh_settings`.
- **Report footer mode setting** (`report_footer_mode`) — four options: `date_page`, `page`, `custom_page`, `none`. Stored in `d5dsh_settings`.
- **Date format setting** (`footer_date_format`) — six options: `dmy`, `mdy`, `ymd`, `short_dmy`, `short_mdy`, `wp` (inherits WordPress General Settings format). Default `dmy` (31 Mar 2026).
- **Page number format setting** (`footer_page_format`) — five options: `page_x_of_n`, `page_x`, `x_of_n`, `x`, `none`. Default `page_x_of_n`.
- **`setup_complete` flag** in `d5dsh_settings` — write-once boolean; prevents wizard from reopening after first save or skip.
- **`tests/Unit/Admin/AdminPageSettingsTest.php`** — 47 new PHPUnit tests covering all new settings fields: defaults, persistence, enum validation (all valid/invalid values for all 4 enum fields), site_abbr 20-char clamp, setup_complete write-once behaviour, full-replace save semantics, permission check, and malformed input handling.
- **`PLUGIN_USER_GUIDE.md` Section 24 — Debug Mode and Error Logging** and **Section 25 — Error Reference** — complete documentation of the debug system added in v0.1.1.1.
- **`PLUGIN_USER_GUIDE.md` Section 26 — First-Run Setup Wizard** — documents all 5 wizard steps, skip behaviour, and post-skip notice.

### Changed

- **`SimpleImporter::sanitize_deep()` and `sanitize_meta_value()`** — switched from `sanitize_text_field` to `sanitize_textarea_field` for string leaves, preserving legitimate multiline content (CSS blocks, code values) that `sanitize_text_field` was silently collapsing to a single line.
- **`SimpleImporter::sanitize_and_log()`** — extended with `'css_value'` and `'textarea'` method options to match the above.
- **`AdminPage::handle_export()`** — all 8 `$_POST` reads now wrapped with `wp_unslash()` before sanitization, preventing double-escaped apostrophes in exported metadata fields.
- **`SimpleImporter::analyze_zip()`** — added pre-extraction traversal guard: iterates all zip entries via `ZipArchive::getNameIndex()` before calling `extractTo()`, rejecting any entry whose normalised path starts with `/`, contains `../`, or is `..` exactly. `realpath()`-only checks cannot guard against non-existent paths before extraction.
- **`SimpleImporter::validate_path_within()`** — added parent-directory fallback: when `realpath($path)` returns false (file does not exist yet), resolves the parent directory with `realpath()` and appends `basename()` to construct a valid boundary-checkable path.
- **`assets/js/admin.js`** — 16 single-message `innerHTML` concatenation patterns converted to `setElMsg()` or `createElement` + `textContent`. Color swatch inline style set via `.style.background` property (never via `innerHTML`). No complex HTML builders were changed.
- **`AdminPage::get_settings()`** — added five new keys with defaults: `report_header_mode`, `report_footer_mode`, `footer_date_format`, `footer_page_format`, `setup_complete`.
- **`AdminPage::ajax_save_settings()`** — validates and saves all new enum fields with whitelist enforcement; clamps `site_abbr` to 20 characters; `setup_complete` is write-once (payload `false` ignored when already `true`).
- **`d5dtSettings` JS object** — now includes `reportHeaderMode`, `reportFooterMode`, `footerDateFormat`, `footerPageFormat`, `wpDateFormat` (from `get_option('date_format')`), `setupComplete`.
- **Site name fallback chain** — `get_bloginfo('name')` → `get_bloginfo('blogname')` → `parse_url(home_url(), PHP_URL_HOST)` → literal `'site-name'`. `site_abbr` slug fallback is `'site_name'` when all sources produce an empty string.
- **`assets/css/admin.css`** — wizard styles added (~110 lines): progress dots, radio option grid, preview box, summary list, footer layout.
- **Security test report** (`security-info/security-test-report.md`) — updated with revised risk classifications and additional result code documentation.
- **`PLUGIN_USER_GUIDE.md` Section 19 — Settings** — General tab fully rewritten to document all new header/footer mode, date format, page number format, and site abbreviation options (now 26 sections total).

### Fixed

- **`tests/Unit/Admin/SimpleImporterTest.php`** — split `validate_path_within_rejects_nonexistent_path` into two tests to correctly reflect the updated method behavior: one asserting non-existent files inside the base are accepted, one asserting traversal outside the base is still rejected.

---

## [0.1.1.1] – 2026-03-31

Interim build. Security testing infrastructure and full security test run.

### Added

- **`SecurityTestRunner`** (`includes/Cli/SecurityTestRunner.php`) — shared engine used by both the WP-CLI command and the admin AJAX handler. Accepts a batch of decoded JSON fixtures, snapshots the database before each test, imports via the normal `SimpleImporter` path, captures the sanitization log, and restores the database between each run. Returns a structured report with per-fixture results and summary totals.
- **`SecurityTestCommand`** (`includes/Cli/SecurityTestCommand.php`) — WP-CLI command (`wp d5dsh security-test`) that scans a directory for `*.json` fixture files, delegates to `SecurityTestRunner`, prints colorized pass/fail output, writes post-import export files (in Divi-native envelope format) for diff comparison, and saves a timestamped JSON report to the output directory.
- **Security test fixtures** (`tests/fixtures/security/`) — JSON fixture files covering all tested attack categories: XSS, PHP injection, CSS injection (block break, split-payload chain, clamp/calc/min expressions), SQL injection, path traversal, SSRF, null bytes, serialization, structural edge cases, size/volume (50/500/5,000 entries), preset poisoning, precision deletion, import-over-existing merge behavior, and export carrier.
- **Security test report** (`security-info/security-test-report.md`) — covers test environment, methodology, attack taxonomy, D5DSH import test results, key findings, and recommendations. All 29 D5DSH fixtures run: 28/29 PASS, 1 exception (fixed — see below).

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
