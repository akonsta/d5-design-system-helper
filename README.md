# D5 Design System Helper

| Version | Date | Notes |
|---------|------|-------|
| 0.1.0 | 2026-03-27 | Initial public release |

Export, import, audit, and manage your [Divi 5](https://www.elegantthemes.com/gallery/divi/) design system(s).

**Author:** Andrew Konstantaras with Claude Code AI	<br>
**License:** GPL-2.0-or-later <br>
**WordPress:** 6.2+ | **PHP:** 8.1+ | **Divi:** 5.0+

---
## Background

The plugin project exists because as I was learning the new Divi 5 system I discovered that some of the tools I wanted to see did not exist (yet). Being impatient, I started working with Claude Code and things moved rather quickly.  

From the inception of the idea to publishing this repository, it took 3 weeks. Not all of it was easy (I ran this text by Claude shortly after one of my many rants about how it wasn't doing everything exactly the way I wanted, Claude encouraged me to "lean more into it if I want." Fair enough.)  Safe to say, I have a love-hate relationship with Claude.

When I realized the potential value this side project could bring, I saw a chance to give back to the programming communities that have given me so much through free software.  Obviously, this doesn't come close to paying off that debt, but it is a start.

If others find this valuable, this project may grow.  I have considered a "Pro" version that would cost a nominal amount, but that decision has not been made.  **IF** that does occur, I am committed to making the free version awesome on its own, not a tease.  That being said, I don't know what comes next.  

If there are features you love or hate; if there is something you would love to see; or if you would like to know more about my 3 week affair with Claude, please let me know.  

I hope this makes at least one person's life a little easier. Thank you to all the amazing people on GitHub and all the other public repositories for making my life better and more informed.

## What This Does

Divi 5 stores your entire design system — colors, typography, spacing, module presets, layouts, and more — in the WordPress database. D5 Design System Helper gives you full control over that data without leaving WP Admin.

That data is not a 1flat list. It is a **directed hierarchy** where every layer depends on the layers below it:

```
Variables  →  Option Group Presets  →  Element Presets  →  Layouts  →  Pages / Builder Templates
```

Change a variable and every preset that references it updates. Export a layout without its presets and it will not render correctly on a new site. This plugin makes those dependencies visible and manageable.

### Supported Data Types

| Type | WordPress Source | Notes |
|---|---|---|
| **Global Variables** | `et_divi_global_variables` + `et_divi` | Colors, Numbers, Fonts, Images, Text, Links |
| **Element Presets** | `et_divi_builder_global_presets_d5` | Per-module saved styles |
| **Option Group Presets** | `et_divi_builder_global_presets_d5` | Cross-module style bundles |
| **Layouts** | `et_pb_layout` posts | Divi Library layouts |
| **Pages** | `page` posts with Divi builder content | Full page exports |
| **Theme Customizer** | `theme_mods_Divi` | All Divi theme mod settings |
| **Builder Templates** | `et_template` posts | Theme builder templates |

---

## Installation

1. Download the latest `d5-design-system-helper-vX.X.X.zip` from the [Releases](../../releases) page.
2. In WordPress Admin: **Plugins → Add New → Upload Plugin** → choose the zip → Install Now → Activate.
3. Navigate to **Divi → Design System Helper** (or **Tools → Design System Helper** if Divi's admin menu is not present).

> **Note:** The release ZIP includes the pre-built `vendor/` directory — no `composer install` needed.

---

## Tabs

### Manage (default)

The Manage tab is the default view. It shows a live table of your entire design system with sections selectable via a section switcher at the top:

- **Variables** — all Global Variables (Colors, Numbers, Fonts, Images, Text, Links) and Global Colors in one table
- **Group Presets** — Option Group Presets
- **Element Presets** — per-module saved styles
- **All Presets** — combined view of both preset types
- **Everything** — combined view of all variables and all presets
- **Categories** — create and assign color-coded categories to organize your variables and presets

Each section has column-header dropdown filters (▼) and drag-to-resize columns whose widths persist across sessions. Export CSV and Export Excel buttons are in the toolbar.

**Inline editing** _(Beta)_ — click any Label or Value cell for a non-system, non-color variable to edit in place; the Status column is an editable dropdown. Enter/Blur to commit, Escape to cancel. Colors and Images are not inline-editable. A Save/Discard bar appears showing the count of pending changes. A snapshot is taken automatically before every save.

**Impact button (i)** — every variable and preset row has an **i** (impact) button. Clicking it opens the Impact Modal showing:
- _What Breaks?_ tab: which content items would break (direct references + via-preset breakdown), severity-coded by publish status
- _Dependencies_ tab: a collapsible tree of the full dependency chain
- Toolbar: Expand All, Collapse All, and Print for both tabs

**Bulk Label Change mode** — switch modes via the toolbar. Available operations: Add Prefix, Add Suffix, Find & Replace, Normalize Case (Title Case / UPPER / lower / snake_case / camelCase).

**Merge Variables mode** — select two variables (Keep + Retire). A live preview shows every preset that references the retiring variable. Confirming replaces all occurrences in preset `attrs`, `styleAttrs`, and `groupPresets`, then archives the retiring variable. A snapshot is taken before writing.

**Color references** — colors using `$variable()$` references display as `→ Referenced Label` with the resolved swatch inline.

### Export

1. Check types in the hierarchical tree (Everything / All Variables / All Presets / individual types). The Export Selected button is disabled until at least one type is checked.
2. Choose format:
   - **Excel (.xlsx)** — for editing and re-importing; Variables and Presets only
   - **JSON** — Divi-native format for all types including Layouts, Pages, Builder Templates, Theme Customizer
   - **DTCG (design-tokens.json)** — W3C Design Tokens format for Variables; use with Figma Tokens Studio, Style Dictionary, and other DTCG-compatible tools
3. Optionally expand **Additional Information** to attach project metadata to the file.
4. Click **Export Selected**. Single type → one file. Multiple types → zip archive.

### Import

Supports `.xlsx`, `.json`, `.zip`, and `design-tokens.json` (DTCG) files.

**Excel import:**
1. Upload an `.xlsx` or `.zip` file exported by this plugin.
2. The file type is detected automatically from the hidden Config sheet.
3. Review the preliminary analysis — new items, updates, and dependency warnings.
4. Click Import. A snapshot is taken before every commit. Import is non-destructive.

**JSON / DTCG import:**
1. Drag and drop a Divi `.json`, `.zip`, or DTCG `design-tokens.json` file onto the drop zone.
2. The plugin auto-detects the file type. DTCG files are detected by `$schema` or by the presence of DTCG token groups (`color`, `dimension`, `number`, `fontFamily`, `string`) with `$value` entries.
3. A per-file analysis card shows type, item counts, and dependency warnings.
4. Click Import to apply. A snapshot is taken before every write.

**Edit Labels during import** — after analysis, each vars/presets JSON file card shows an "Edit Labels" collapsible panel. Rename individual DSOs inline, or apply bulk operations (prefix, suffix, find & replace, normalize case) before committing. Label changes are applied server-side before any data is written; the original labels in the file are never modified.

**Import conflict resolution** _(not exhaustively tested)_ — when the analysis detects label or ID conflicts between the imported file and existing data, a per-row conflict resolution panel appears. Each conflict offers granular choices: accept the imported value, keep the current value, rename with a custom label, or skip the item entirely. The Import button is disabled until every conflict is resolved. After import, the results modal includes a Conflict Resolutions log documenting each decision for future reference.

**DTCG round-trip** — files exported by this plugin include a `d5dsh:id` extension key. On re-import, that key is used to match tokens back to the correct Divi variable, ensuring a clean round-trip (Divi → Figma → Divi).

**Convert to Excel** — available after analysis on any JSON file card. Produces a structured Excel workbook that can be imported directly through the main Import panel.

**Import sanitization** — every value in every imported file (Excel, JSON, DTCG, zip) is sanitized before it reaches the database. Labels, IDs, post content, meta values, and nested structures are all cleaned using WordPress sanitization functions. If any value is modified during import, a **Sanitization Report** appears in the results modal showing the original value, the cleaned value, and which item was affected. This protects your site when importing files from any source — client handoffs, shared team files, marketplace exports, or third-party tools.

### Analysis

The Analysis tab runs health checks and content analysis. It has two sub-sections:

#### Analysis — Design System Health Check

Runs a live health check against your active design system. Choose **Simple Audit** (variables and presets only) or **Contextual Audit** (includes a content scan for 8 additional checks). Checks are organised in three tiers:

| Tier | Checks |
|---|---|
| **Error** | Broken variable references in presets; archived variables still in active use |
| **Warning** | Singleton variables (used by only one preset); near-duplicate color values |
| **Advisory** | Hardcoded hex colors that could be extracted as variables; orphaned variables not referenced anywhere |

When a Content Scan has been run in the same session, **DSO Uses** and **Used In** columns are added automatically to each tier panel, cross-referencing which content items use each flagged DSO.

Results can be exported as Excel (.xlsx), CSV, or Print — for the entire report or per-tier.

#### Content Scan

Scans all pages, posts, Divi Library layouts, and Theme Builder templates (all statuses, up to 1,000 items) for DSO usage. Produces four collapsible sections:

- **Active Content** — items that reference at least one variable or preset; columns include direct Vars/Presets counts and a **Vars in Presets** group (Tot Vars / Uniq Vars) showing variables embedded inside the presets the item uses
- **Content Inventory** — all scanned items with post type, status, Vars, Presets, and the same Vars in Presets columns; Theme Builder canvas sub-rows indented under their parent template
- **DSO Usage Index** — reverse index: for each DSO, lists every content item that references it (variables and presets in separate sub-tables)
- **No-DSO Content** — items with no variable or preset references
- **Content → DSO Map** — for each active content item: a flat table of every DSO reference and a collapsible tree (Content → Presets → Variables). Variable nodes show label, ID, and resolved type (Color / Number / Font / etc.).
- **DSO → Usage Chain** — answers "where is this DSO used?" from three angles: Variable → Usage Chain, Preset → Variables, and Variable → Presets Containing It

Each section has its own Print / Excel / CSV export bar. The scan Excel export includes an Info sheet and a customized Instructions sheet. Run the Content Scan before the Analysis to get the richest cross-referenced results.

Near-duplicate Warnings include a **Merge…** button that deep-links to Manage → Merge Variables mode with both variable IDs pre-populated.

DSO Usage Index rows include an **Impact** link that opens the Impact Modal for that DSO.

### Style Guide

The Style Guide tab (between Analysis and Snapshots) generates a visual preview of your entire design system:

- **Colors** — 4-column swatch grid with circle swatches, labels, hex values, and variable IDs
- **Typography** — each font variable rendered as a sample sentence in that font
- **Numbers / Spacing** — proportional ruler bars
- **Other** — images, links, strings listed as values
- **Presets** (optional) — module and group presets with variable reference counts

Toggle options: system vars, group by category, include presets.

Export options:
- **Download HTML** — self-contained `.html` file with inline CSS; no external dependencies
- **Print / Save as PDF** — browser print dialog; WP admin chrome hidden automatically

### Snapshots

Every import and every direct edit triggers an automatic snapshot of the affected data type before writing. The Snapshots tab lets you:

- Browse up to 10 snapshots per type (newest first), showing timestamp, trigger, description, and item count
- Restore any snapshot to roll back changes (a new snapshot of the current state is taken before restoring)
- Delete individual snapshots
- **Undo Last Import** — one-click shortcut to restore the most recent pre-import snapshot for any type

---

## Excel File Structure

Every `.xlsx` file contains:

| Sheet | Contents |
|---|---|
| **Colors** | Order, ID, Label, Swatch, Value, Reference, Status, System, Hidden |
| **Numbers / Fonts / Images / Text / Links** | Order, ID, Label, Value, Status, System, Hidden |
| **Element Presets / Group Presets** | Per-preset row with style JSON |
| **Info** | Site metadata (site URL, site name, export date, user, Divi version, WP version) + optional project information |
| **Config** *(hidden)* | Source path, export timestamp, SHA-256 hash, file type identifier |

The Order, ID, Swatch, System, and Hidden columns are protected. Edit Label, Value, and Status only.

Base64 image data (`data:image/...;base64,...`) is replaced with a placeholder in Excel and preserved from the original source on import — as long as the placeholder cell is not modified.

---

## JSON File Types

Imported JSON files are labelled by type and composition:

- **Standalone** — contains only its target layer (e.g. Element Presets without their variable dependencies)
- **Composite** — bundles the target layer with the lower-level objects it references (e.g. Element Presets + the variables they use)

Composite does not mean complete. The dependency analysis panel shows what is still missing on the destination site.

**Import order for site migrations:** Variables → Group Presets → Element Presets → Layouts/Pages/Templates.

---

## DTCG Format

The DTCG format (W3C Design Tokens Community Group) is the standard interchange format between design tools.

**Export:** Use the DTCG format option in the Export tab. Covers Colors, Numbers, Fonts, and Text variables. Color references are resolved to actual hex values.

**Import:** Drop any DTCG-format `.json` file onto the Import drop zone. Detection is automatic. Tokens map to Divi variable types: `color → Colors`, `dimension/number → Numbers`, `fontFamily → Fonts`, `string → Strings`.

**Compatible tools:**
- **Tokens Studio for Figma** — import `design-tokens.json` as Figma token sets
- **Style Dictionary** — transform tokens into CSS custom properties, Sass, Swift, Kotlin, and more
- Any other DTCG-compatible tool (Supernova, Specify, Cobalt UI, etc.)

---

## Key Design Decisions

- **Non-destructive imports** — items absent from the Excel file are never deleted from the database.
- **Auto-detection** — the import form reads the hidden Config sheet to identify file type automatically. DTCG files are detected by `$schema` or token group structure.
- **Snapshot safety** — a full snapshot of each data type is saved before every write. Up to 10 snapshots per type, each restoring in one click.
- **No external HTTP calls** — the plugin never phones home or loads remote assets.
- **Dependency transparency** — import analysis shows exactly what a file contains, what it references, and what is missing on the destination site before you commit.
- **Conflict detection** — import analysis flags label changes (same ID, different name) and duplicate labels (different IDs, same name) before any data is written, with per-item resolution controls. _(Not exhaustively tested.)_
- **Site name resilience** — all exports use a fallback chain for the site name (`bloginfo('name')` → `bloginfo('blogname')` → URL host) so the Info sheet and title rows are always populated.

---

## Repository Structure

```
d5-design-system-helper/
├── d5-design-system-helper.php        Plugin entry point + PSR-4 autoloader
├── build-zip.sh                       Production zip build script
├── composer.json
├── README.md
├── CHANGELOG.md
├── PLUGIN_USER_GUIDE.md
├── CONTRIBUTING.md
├── SECURITY.md
├── CODE_OF_CONDUCT.md
├── docs/
│   └── SERIALIZATION_SPEC.md     Divi 5 serialization format + ABNF grammar
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── includes/
│   ├── Admin/
│   │   ├── AdminPage.php        3-tab admin UI (6 with Beta Preview) + Snapshots handlers
│   │   ├── AuditEngine.php      Design system health checks (3 tiers, 22 checks)
│   │   ├── AuditExporter.php    Audit + Content Scan XLSX builder
│   │   ├── CategoryManager.php  DSO category CRUD + multi-assignment
│   │   ├── ContentScanner.php   Content scan (active, inventory, DSO index, no-DSO)
│   │   ├── HelpManager.php      In-plugin help panel (parses PLUGIN_USER_GUIDE.md)
│   │   ├── ImpactAnalyzer.php   "What breaks?" modal backend
│   │   ├── LabelManager.php     AJAX backend for Manage tab variable editing
│   │   ├── MergeManager.php     Merge duplicate variables (update all preset refs)
│   │   ├── NotesManager.php     Per-DSO notes and suppression flags
│   │   ├── PresetsManager.php   AJAX backend for Manage tab presets sections
│   │   ├── SimpleImporter.php   JSON / DTCG import with dependency analysis
│   │   ├── SnapshotManager.php  Snapshot stack CRUD (up to 10 per type)
│   │   ├── StyleGuideBuilder.php Style Guide data AJAX endpoint
│   │   └── Validator.php        Excel file validation
│   ├── Data/                    One Repository per data type
│   ├── Exporters/               One Exporter per data type + DtcgExporter, PrintBuilder
│   ├── Importers/               One Importer per data type
│   └── Util/
│       ├── DebugLogger.php
│       ├── DiviBlocParser.php   Variable and preset reference extraction
│       └── ExportUtil.php       Shared PhpSpreadsheet helpers
└── tests/
```

---

## Building from Source

```bash
bash build-zip.sh
```

Reads the version from the plugin header, installs `--no-dev` Composer dependencies, copies only production files, and outputs the zip to `archived-releases/`.

---

## Contributing

Please read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting a pull request. For security vulnerabilities, see [SECURITY.md](SECURITY.md) — do not open a public issue.

---

## Contact

| Purpose | Contact |
|---|---|
| General enquiries | [konsta@me2we.com](mailto:konsta@me2we.com) |
| Bug reports & feature requests | [GitHub Issues](https://github.com/akonsta/d5-design-system-helper/issues) |
| Security vulnerabilities | [security@me2we.com](mailto:security@me2we.com) — do not open a public issue |

---

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)

This project is not affiliated with, endorsed by, or sponsored by Elegant Themes. "Divi" is a trademark of Elegant Themes, Inc.
