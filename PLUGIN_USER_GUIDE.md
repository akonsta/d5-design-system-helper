# D5 Design System Helper — User Guide

| Version | Date | Notes |
|---------|------|-------|
| 0.1.0 | 2026-03-27 | Initial public release |

---

## 1. What This Plugin Does

**D5 Design System Helper** gives you full control over your Divi 5 design system without leaving WordPress Admin. If you have ever wanted to edit all your colors at once in a spreadsheet, copy a design system from one site to another, audit your design system for problems, or understand what content is actually using your variables and presets, this plugin is for you.

### The core things you can do

- **Manage variables and presets** — view, search, filter, and rename your variables directly in the browser. See what you have, find duplicates, and bulk-rename to bring order to a messy variable set.
- **Export to Excel** — pull your colors, fonts, spacing values, and other design variables into a spreadsheet where you can bulk-edit them, share them with a team, or use them as documentation.
- **Import from Excel** — apply your edited spreadsheet back to Divi. The plugin shows you exactly what will change before it writes anything.
- **Export and import JSON** — move your entire design system (variables, presets, layouts, pages, templates) between sites using Divi's own file format.
- **Import DTCG tokens** — import design token files in the W3C DTCG format (e.g. from Figma Tokens Studio or Style Dictionary) directly into your Divi variables.
- **Audit your design system** — run a health check to find broken variable references, near-duplicate colors, unused variables, and other issues. Export results as Excel.
- **Scan content** — find out which pages, posts, and layouts are using which variables and presets. Understand the impact of any change before you make it.
- **Snapshots** — every write operation saves an automatic backup. If something goes wrong, restore in one click.

### What this plugin does not do

- It is not a full-site backup tool. Use a dedicated backup plugin for database and file backups.
- It cannot move page content through a spreadsheet. The Excel workflow covers design variables and presets only.
- It does not connect to any external service. No data ever leaves your server.

---

## 2. Your Divi Design System: A Quick Overview

Before diving into the tabs, it helps to understand how Divi 5 structures its design system. You do not need to memorise any of this — but knowing the basic shape will help you understand why some things need to be done in a particular order (especially when moving data between sites).

### The layers

Divi 5 organises its design data in layers, from the simplest to the most complex:

```
Variables  →  Option Group Presets  →  Element Presets  →  Layouts / Pages / Templates
```

**Variables** are named values: a specific hex color, a font name, a spacing measurement, a text string. You define them once and reference them throughout your design. Change a variable and every place it is used updates automatically. Variables are the foundation of everything else.

**Option Group Presets** (called "Group Presets" in this plugin) are reusable bundles of style settings — typography, spacing, color assignments — that can be applied to multiple element types. They reference variables.

**Element Presets** are saved styles locked to a specific Divi module — a Button, a Heading, a Text module. They reference variables and Group Presets.

**Layouts, Pages, and Builder Templates** use the presets and variables defined below them. They are the outermost layer.

### Why the order matters for migrations

When you move a design system from one site to another, import the lower layers before the upper layers. Import Variables first, then Presets, then Layouts. If you import a Layout before its presets exist on the destination site, the layout will not render correctly.

The plugin's import analysis panel tells you exactly which dependencies are present, which are missing, and what you still need. It takes the guesswork out of migration.

### Design System Objects (DSOs)

The term **DSO** (Design System Object) appears in a few places in the plugin. It refers to Variables and Presets — the reusable building blocks of the design system. Layouts, Pages, and Templates are not DSOs; they are content that *uses* DSOs.

---

## 3. The Tabs

The plugin has three standard tabs: **Manage**, **Export**, and **Import**.

<div class="d5dsh-beta-doc-block">Three additional tabs are available when <strong>Beta Preview</strong> is enabled in Settings: <strong>Analysis</strong>, <strong>Style Guide</strong>, and <strong>Snapshots</strong>. The Analysis tab contains two sub-sections: <strong>Analysis</strong> (the health check) and <strong>Content Scan</strong>.</div>

---

## 4. Managing Variables and Presets (Manage Tab)

The **Manage** tab is the default view when you open the plugin. It shows a live table of your design system data — Variables, Group Presets, Element Presets, All Presets, Everything, and Categories.

### Section switcher

Use the section buttons at the top to switch between:
- **Variables** — all your design variables: Colors, Numbers, Fonts, Images, Text, and Links
- **Group Presets** — reusable style bundles
- **Element Presets** — per-module saved styles
- **All Presets** — a combined view of both preset types
- **Everything** — a combined view of all variables and all presets in one table
- <span class="d5dsh-beta-doc">**Categories** — create and assign color-coded categories to variables and presets (requires Beta Preview)</span>

### Filtering and searching

Click any filterable column header (those with a ▼ icon) to open a dropdown filter panel with checklist, contains, starts-with, and equals modes, plus sort controls. Click **↺ Clear Filters** to clear all active filters and sorts and return to the default view.

### Column widths

Drag any column border to resize. Your widths are saved automatically per-browser and persist across page loads. Columns marked as non-resizable (`#`, Notes, Deps) have a fixed width.

### Color references

Colors that use Divi's `$variable()$` syntax to reference another color variable are displayed as `→ Referenced Label` with the resolved color swatch shown inline. Hover to see the raw reference and the actual hex value it resolves to.

### View mode (default)

In View mode the table is read-only. Use it to:
- Audit your variable set — check for naming inconsistencies, verify values, find near-duplicates
- Locate a specific variable before editing it in the Divi builder
- Generate an exported reference copy

### Inline editing

<div class="d5dsh-beta-doc-block">This feature requires <strong>Beta Preview</strong> to be enabled in Settings (see <a href="#20-beta-features">Section 20</a>).</div>

Click on a **Label** or **Value** cell for any non-system, non-color variable to edit it directly in the table. Press Enter or click away to save, Escape to cancel. The **Status** column is an editable dropdown for all variables. Colors and Images are not inline-editable — use the Export/Import workflow for those types.

A **Save / Discard** bar appears above the table when you have unsaved changes, showing a count of modified items along with the source of the changes (e.g. "5 unsaved changes from inline editing on Mar 26 at 14:32"). Click **Save** to write the changes (a snapshot is taken first), or **Discard** to abandon them.

**Important:** You must save or discard all pending changes before starting a new editing operation (Bulk Label Change, Merge Variables), importing data, or exporting. The Export button on the Export tab is disabled while unsaved changes exist on the Manage tab.

### Bulk Label Change mode

<div class="d5dsh-beta-doc-block">This feature requires <strong>Beta Preview</strong> to be enabled in Settings (see <a href="#20-beta-features">Section 20</a>).</div>

Switch to Bulk Label Change mode using the toolbar button. This lets you rename multiple variables and presets at once using:
- **Add Prefix** — prepend text to all selected labels
- **Add Suffix** — append text to selected labels
- **Find & Replace** — replace a word or phrase across all selected labels
- **Normalize Case** — apply Title Case, UPPER CASE, lower case, snake_case, or camelCase consistently

This is particularly useful when you have inherited a site with inconsistent naming and want to standardise labels across your full set of variables and presets.

### Everything section

The **Everything** section combines all variables and all presets into a single table. Use it when you want to see your entire design system in one place, or when you need a single export that covers both variables and presets. The Everything Excel download produces two workbooks — one for presets and one for variables — since they have different column structures.

### Export CSV and Export Excel

The **⬇ CSV** button downloads the current filtered view as a CSV file. The **⬇ Excel** button downloads it as a formatted Excel workbook with color swatches. Both buttons are available in every section (Variables, Group Presets, Element Presets, All Presets, and Everything) and are useful for documentation, handoff, or review outside of WordPress.

---

## 5. Exporting to Excel

Navigate to **Divi → Design System Helper** and click the **Export** tab.

### Step 1 — Choose what to export

The checkbox tree lets you select exactly what you want.

- Check **Everything** to select all types.
- Check **All Variables** to select only Colors, Numbers, Fonts, Images, Text, and Links.
- Check **All Presets** to select only Element Presets and Group Presets.
- Or expand any group and check individual types.

The **Export Selected** button is grayed out until you check at least one type. It is also disabled if you have unsaved variable changes on the Manage tab — a warning message appears explaining this. Save or discard your changes first, then return to the Export tab.

### Step 2 — Choose the export format

Three formats are available:

**Excel (.xlsx)** — the main format for editing. Use this when you want to change values and import them back. Each variable type gets its own sheet. Colors include a visual swatch column. This is the format to use for day-to-day design system management.

**JSON** — Divi's native format. Use this when you want to move data from one site to another, or back up your entire design system. JSON export is available for all data types, including Layouts, Pages, Builder Templates, and Theme Customizer settings, which cannot be exported to Excel.

**DTCG (design-tokens.json)** — a standard format for design tokens. Use this when you need to connect your Divi variables to tools like Figma (via Tokens Studio), Style Dictionary, or other design tooling. See [Section 13](#13-dtcg-format-export-and-import) for a full explanation.

### Step 3 — Add project information (optional)

Expand **Additional Information** and fill in an owner name, customer, project name, version, and environment label. This information is written to the Info sheet of the Excel file. It is useful when passing files between team members or when you need to know later which site and project a file came from.

### Step 4 — Download

Click **Export Selected**. A single type downloads as one file. Multiple types download as a `.zip` archive containing one file per type.

---

## 6. Understanding the Excel File

The Excel workbook has one sheet per variable type, plus a few supporting sheets.

### The Info sheet

Contains the site URL, site name, export date, the exporting user's name, Divi version, WordPress version, and any project information you entered. This is for your reference and is not read during import.

### The variable sheets (Colors, Numbers, Fonts, Images, Text, Links)

Each sheet has one row per variable. The columns are:

| Column | What it is | Can you edit it? |
|---|---|---|
| Order | Position in the Divi interface | No |
| ID | Internal identifier used during import | No |
| Label | The name shown in the Divi builder | **Yes** |
| Swatch | Visual color preview (Colors sheet only) | No |
| Value | The actual value — hex color, CSS value, font name, etc. | **Yes** |
| Reference | If this color references another variable | No |
| Status | `active`, `archived`, or `inactive` | **Yes** |
| System | Whether this is a Divi built-in variable | No |
| Hidden | Whether the variable is hidden in Divi | No |

The Order, ID, Swatch, System, and Hidden columns are locked in the Excel file to prevent accidental edits. Focus on Label, Value, and Status.

### The Presets sheets (Element Presets, Group Presets)

One row per preset. The style settings are stored as a JSON string in a single cell. You can edit these, but only if you are confident with JSON syntax — a formatting error will cause the preset to import with missing or incorrect styles. In most cases, treat preset rows as read-only reference.

### The Config sheet (hidden)

This sheet contains metadata the plugin uses to validate the file and detect which site it came from. Do not edit or delete it. If the Config sheet is missing, the file cannot be imported.

---

## 7. Editing the Excel File

**Safe to edit:**

- **Label** — rename variables to match your naming conventions.
- **Value** — change a color hex, spacing value, font name, or text string. This is the most common reason to use the Excel workflow.
- **Status** — mark a variable as `archived` or `inactive`.

**Do not edit:**

- **Order, ID, Swatch, System, Hidden** — structural columns that are locked in the Excel file.
- **The Config sheet** — the plugin's validation depends on it.
- **Image placeholder cells** — variables that contain embedded image data show a placeholder text. Leave these cells untouched. If you delete or overwrite the placeholder, the image data will be lost on the next import.
- **Preset JSON strings** — edit only if you understand JSON and the Divi property names involved.

---

## 8. Importing from Excel

Navigate to the **Import** tab.

### Step 1 — Upload your file

Drag and drop, or click to browse. Upload a `.xlsx` file exported by this plugin, or a `.zip` archive from a multiple-type export.

Only files previously exported by this plugin are supported — the hidden Config sheet must be present and valid.

### Step 2 — Review the analysis

Once the file is uploaded, the plugin shows a breakdown of what is in the file and what will change:

- How many items of each type are present
- How many are new (will be added)
- How many are updates (will overwrite existing entries)
- Any dependency warnings

**Read the analysis before you click Import.** It takes a few seconds and tells you exactly what will happen.

### Step 3 — Import

Click **Import** to commit. Before writing anything, the plugin automatically takes a snapshot of the current state. It then applies the changes and shows a results summary.

Import is **non-destructive**: items that are in your database but not in the Excel file are left untouched. The file only needs to contain the items you want to add or update.

### Undo an import

If you need to reverse an import, go to the **Snapshots** tab. The snapshot taken immediately before the import is listed there and can be restored in one click.

---

## 9. Exporting and Importing JSON

The Export tab's **JSON** format option exports data in Divi's native format — the same format Divi's own export tool produces. This covers all data types: Variables, Presets, Layouts, Pages, Builder Templates, and Theme Customizer settings.

Use JSON when you want to move data between sites, or when you need a full backup of your design system.

### Importing JSON files

The **Import** tab accepts Divi-native JSON files and zip archives. Drag and drop your file into the drop zone. The file type is detected automatically. A per-file analysis card shows what the file contains before you commit.

After analysis, a **Convert to Excel** button appears on the file card. This converts the JSON into an Excel workbook so you can review and edit the data. The Excel file produced can be imported directly through the main Import panel.

### Editing labels during import

After analysis, each vars or presets file card includes an **Edit Labels** collapsible panel (click the summary to expand). This lets you rename DSOs — variables, global colors, or presets — before any data is written to the database.

**Why use this?**
- Rename variables to reflect their purpose on the destination site (e.g., add a `fs_` prefix for font-size variables).
- Normalize naming conventions from an external file to match your house style before importing.
- Identify the origin of DSOs when merging design systems from multiple sources.

**Bulk operations**

The toolbar at the top of the editor panel applies a transform to all labels at once:

| Operation | What it does |
|---|---|
| Add Prefix | Prepends text to every label |
| Add Suffix | Appends text to every label |
| Find & Replace | Replaces the first occurrence of a substring in each label |
| Normalize Case | Reformats every label to Title Case, UPPER, lower, snake_case, or camelCase |

Type values in the input fields and click **Apply**. The table updates immediately. Changes are client-side only until you click Import.

**Per-row editing**

Click directly inside any Label cell in the table to edit it. Press Tab or click away to move on.

**Reset**

Click **Reset** inside any file card to revert all labels in that file back to the values in the original file. On upload of a new file, all overrides are cleared automatically.

**Zip archives**

Each file card inside a zip analysis has its own independent label editor. Editing one file does not affect the others.

**How it works**

Label changes are transmitted to the server when you click Import and applied before the data is written. The original JSON file is never modified.

### Conflict resolution during import

> **Note:** This feature has not been exhaustively tested. It is functional but may have edge cases that have not yet been encountered. Please report any issues.

When the import analysis detects conflicts between the imported file and your existing data, a **Conflict Resolution** panel appears below the file card. There are two types of conflict:

**Label Changes** — an item in the import file has the same ID as an existing item but a different label. Without resolution, the import would silently rename the existing item.

**Duplicate Labels** — an item in the import file has a different ID but the same label as an existing item. Without resolution, both items would appear in the UI with the same name.

The Import button is disabled until every conflict is resolved. Each conflict row offers its own set of choices:

| Conflict type | Options |
|---|---|
| **Label Change** | Accept imported label · Keep current label · Rename (editable field, prefilled with "Imported — {label}") |
| **Duplicate Label** | Import as-is (accept duplicate) · Rename imported item (editable field) · Skip this item |

A status bar at the bottom shows how many conflicts have been resolved. Once all are resolved, the Import button re-enables. Alternatively, click **Reject Entire Import** to discard the file and return to idle.

**Resolution summary** — after import, the post-import results modal includes a **Conflict Resolutions** section listing each conflict, the action taken, and the resulting label. This log is included in the printable and saveable report for future reference.

**How it works** — resolutions are transmitted to the server alongside the import data. "Keep current" and "Rename" actions are applied as label overrides before writing. "Skip" actions exclude the item from the import entirely. "Accept import" actions proceed with the imported data as-is.

---

## 10. Understanding JSON File Types

When you import a JSON file, the plugin shows a type label on the file card — for example, "Variables", "Element Presets — Composite", or "Layouts — Presets — Composite". Here is what these mean.

### Standalone vs Composite

A **Standalone** file contains only its target type. For example, an Element Presets file that contains only presets, without the variables those presets reference. This works correctly when the destination site already has those variables.

A **Composite** file bundles the target type together with the lower-level objects it depends on. For example, an "Element Presets — Composite" file contains both presets and the variables they reference. Composite files are the right choice when migrating to a new site.

Composite does not mean complete. Only explicitly referenced dependencies are bundled. The import analysis panel tells you if anything is still missing on the destination site.

### Type label reference

| Type label | What it contains |
|---|---|
| Variables | Variables only |
| Group Presets | Group Presets only (no variables included) |
| Group Presets — Composite | Group Presets plus the variables they reference |
| Element Presets | Element Presets only |
| Element Presets — Composite | Element Presets plus variables |
| Presets | Both preset types (no variables) |
| Presets — Composite | Both preset types plus variables |
| Layouts | Layouts only |
| Layouts — Presets | Layouts plus bundled presets |
| Layouts — Presets — Composite | Layouts plus presets and variables |
| Pages / Pages — Presets — Composite | Same pattern as Layouts |
| Builder Templates / Builder Templates — Presets — Composite | Same pattern |
| Theme Customizer | Site-wide settings snapshot |
| Design Tokens (DTCG) | Variables in W3C DTCG format |

### Import order for site migrations

When setting up a new site, import in this order:
1. Variables
2. Group Presets (or Group Presets — Composite)
3. Element Presets (or Element Presets — Composite)
4. Layouts / Pages / Builder Templates

If your files are Composite, each file carries its own dependencies — but cross-file dependencies still need the right order.

---

## 11. Snapshots Tab

<div class="d5dsh-beta-doc-block">This tab requires <strong>Beta Preview</strong> to be enabled in Settings.</div>

The **Snapshots** tab shows a list of automatic backups of your design system, organized by data type. Only types that have at least one snapshot are shown.

A snapshot is taken automatically before every import, every export, and every direct edit made through this plugin. Every change is reversible.

### Restoring a snapshot

Click **Restore** next to any snapshot to revert to that state. Before restoring, the plugin takes a new snapshot of the current state — so the restore is itself reversible.

### Undo Last Import

The **Undo Last Import** button at the top of each type's snapshot list restores the most recent pre-import snapshot for that type. Use it as a quick undo after an import that did not produce the expected result.

### Deleting snapshots

Click **Delete** to permanently remove a snapshot. Up to 10 snapshots are kept per type. Older snapshots are removed automatically when the limit is reached.

---

## 12. Analysis Tab

<div class="d5dsh-beta-doc-block">This tab requires <strong>Beta Preview</strong> to be enabled in Settings.</div>

The **Analysis** tab runs health checks and content analysis against your active Divi design system. It has two sub-sections: **Analysis** and **Content Scan**.

### Analysis — Design System Health Check

The Analysis section runs a live audit of your design system. Choose **Simple Audit** (variables and presets only — no content scan required) or **Contextual Audit** (includes a content scan for 8 additional content-aware checks). Results are grouped in three tiers:

**Simple Audit** checks run against variables and presets only — no content scan required:

| Tier | Check key | What it tests |
|---|---|---|
| **Error** | `broken_variable_refs` | Presets that reference a variable ID not defined on this site |
| **Error** | `archived_vars_in_presets` | Archived variables still referenced by active presets |
| **Error** | `duplicate_labels` | Two or more DSOs that share the same label but have different values or types |
| **Warning** | `singleton_variables` | Variables used by only one preset — possible candidates for inlining |
| **Warning** | `near_duplicate_values` | Variables sharing identical normalized values — deduplication candidates |
| **Warning** | `preset_duplicate_names` | Presets of the same module type that share a name — ambiguous in the editor |
| **Warning** | `empty_label_variables` | Variables or colors with a blank label — undiscoverable in the editor |
| **Warning** | `unnamed_presets` | Presets with a missing or blank name |
| **Warning** | `similar_variable_names` | Variables whose labels normalize to the same token (e.g. "Primary Blue", "primary-blue", "PrimaryBlue") — naming inconsistency |
| **Warning** | `naming_convention_inconsistency` | Variables of the same type using mixed naming styles (Title Case vs kebab-case vs camelCase, etc.) |
| **Advisory** | `hardcoded_extraction_candidates` | Hardcoded hex colors appearing in 10+ presets — candidates for extraction into a variable |
| **Advisory** | `orphaned_variables` | Variables defined on the site but not referenced by any preset or content |
| **Advisory** | `preset_no_variable_refs` | Presets that contain no variable references — all values are hardcoded |
| **Advisory** | `variable_type_distribution` | Distribution of variables by type; flags any single type exceeding 60% of all variables. Also renders a bar chart in the report. |

**Contextual Audit** runs all Simple checks plus 8 additional content-dependent checks (requires a content scan, which is performed automatically):

| Tier | Check key | What it tests |
|---|---|---|
| **Error** | `archived_dsos_in_content` | Archived variables or presets referenced in published post content |
| **Error** | `broken_dso_refs_in_content` | Variable or preset IDs found in published content that do not exist on the site |
| **Warning** | `orphaned_presets` | Presets that exist on the site but are never used in any scanned content item |
| **Warning** | `high_impact_variables` | Variables used in 10 or more content items — high blast radius if value is changed |
| **Warning** | `preset_naming_convention` | Modules with 4+ presets that mix naming conventions (Title Case, snake_case, camelCase, kebab-case) |
| **Advisory** | `variables_bypassing_presets` | Variables used directly in content AND referenced inside preset attribute definitions — split usage pattern |
| **Advisory** | `singleton_presets` | Presets applied to exactly one content item — candidates for removal or consolidation |
| **Advisory** | `overlapping_presets` | Pairs of presets in the same module whose variable sets overlap ≥ 80% (smaller set ≥ 3 vars) — potential consolidation opportunity |

Each tier panel shows a sortable, filterable table of findings. Each row shows the affected DSO ID, label, and a description of the issue. When a Content Scan has been run in the same session, two additional columns appear automatically: **DSO Uses** (how many content items reference that DSO) and **Used In** (the titles of those content items).

**Exporting results** — an export bar appears at the top of the results when the audit has run. You can export the entire audit report, or use the per-tier export bar to export only Errors, only Warnings, or only Advisories. Export formats: Print, Excel (.xlsx), CSV.

### Content Scan

The Content Scan section scans all pages, posts, Divi Library layouts, and Theme Builder templates (all statuses, up to 1,000 items) for DSO usage. Click **Scan Content** to run the scan.

Results are shown in six collapsible sections. Hover any section title for a description of what that section contains.

**Active Content** — content items that contain at least one variable or preset reference. Columns: ID, title, status, last-modified date, Vars (direct variable references in the item), Presets, and a **Vars in Presets** column group showing Tot Vars and Uniq Vars — the total and distinct variable references found inside the preset definitions that item uses.

**Content Inventory** — a complete list of all scanned items, including those with no DSO references. Shows post type, status, last-modified date, Vars, Presets, and the same **Vars in Presets** group (Tot Vars / Uniq Vars). Theme Builder canvas sub-rows (Header, Body, Footer) are indented under their parent template row.

**DSO Usage Index** — a reverse index: for each variable or preset referenced on the site, shows every content item that uses it. Variables and Presets are listed in separate sub-tables. Use this before renaming or deleting a DSO to understand the full impact of the change.

**No-DSO Content** — content items with no variable or preset references at all. Useful for identifying pages or layouts that could benefit from DSO adoption.

**Content → DSO Map** — for each active content item, shows every DSO it references. Opens with a **Flat Reference Table** (every Content → DSO pairing in a sortable table) followed by a **DSO Tree** (collapsible per-item tree: expand a content item to see its direct variables and presets, then expand a preset to see which variables it embeds). Variable nodes display label, ID in parentheses, and variable type (Color, Number, Font, Image, Text, or Link).

**DSO → Usage Chain** — answers "where is this DSO used?" from three angles:
- **Variable → Usage Chain** — each variable, which content items use it, and whether they reach it directly or through a preset.
- **Preset → Variables** — each preset referenced in content, with the variables embedded in its definition.
- **Variable → Presets Containing It** — cross-reference: for each variable, the preset definitions that embed it.

**Export options** — each section has its own Print / Excel / CSV export bar in the section header. The toolbar also has whole-scan export buttons. The scan Excel file contains one sheet per section, plus an Info sheet and a customized Instructions sheet.

**Running both** — for the richest information, run the Content Scan first and then run the Audit. The DSO Usage columns in the Audit report are populated automatically from the scan data in the current session.

---

## 13. DTCG Format — Export and Import

### What is DTCG?

DTCG stands for **Design Tokens Community Group** — a W3C working group that has defined a standard JSON format for design tokens. A design token is essentially what Divi calls a variable: a named, reusable value like a color hex or a font name.

The DTCG format bridges Divi and external design tools. Use it to connect your Divi variables to Figma, Style Dictionary, or any other DTCG-compatible tool.

### DTCG Export

In the Export tab, select **DTCG (design-tokens.json)** as the format. The export covers Colors, Numbers, Fonts, and Text variables. Images and Links have no DTCG equivalent and are omitted. Color-reference variables are resolved to their actual hex values.

| Divi variable type | DTCG `$type` |
|---|---|
| Colors | `color` |
| Numbers with a CSS unit (e.g. `16px`, `1.5rem`) | `dimension` |
| Numbers without a unit | `number` |
| Fonts | `fontFamily` |
| Text / Strings | `string` |
| Images | omitted |
| Links | omitted |

The exported file includes `d5dsh:` extension keys that preserve the Divi variable ID, status, and system flag. These are ignored by tools that do not understand them.

### DTCG Import

The Import tab accepts DTCG JSON files. Detection is automatic: the plugin checks for a `$schema` field pointing to the DTCG specification URL (`designtokens.org`), or for DTCG-structured token groups with `$value` entries — so third-party DTCG files without a `$schema` field are also recognised.

Drop a `design-tokens.json` or any `.json` DTCG file onto the import drop zone. The file is detected as **Design Tokens (DTCG)** and imported as Divi variables.

| DTCG group | Divi variable type |
|---|---|
| `color` | Colors |
| `dimension` or `number` | Numbers |
| `fontFamily` | Fonts |
| `string` | Strings (Text) |

When importing a file previously exported by this plugin, the `d5dsh:id` extension key is used to match tokens back to existing Divi variables — ensuring a round-trip (Divi export → edit in Figma → import back) updates the correct variable in place. For third-party files, the token key becomes the variable ID.

Import is additive and non-destructive. A snapshot is taken before writing.

### Using DTCG with external tools

- **Tokens Studio for Figma:** Import `design-tokens.json` as a Figma token set. Edit tokens in Figma, export, and re-import here to update your Divi variables.
- **Style Dictionary:** Place `design-tokens.json` in your source directory and run `style-dictionary build` to generate CSS custom properties, Sass, Swift, or any other output format.
- **Manual review:** The file is plain JSON and can be opened in any text editor.

---

## 14. Limitations and Important Notes

**Excel covers Variables and Presets only.** Layouts, Pages, Builder Templates, and Theme Customizer settings can only be exported to JSON, not Excel.

**Image variables show a placeholder, not the image.** Variables that store embedded image data cannot be represented in a spreadsheet. A placeholder text is shown instead. Leave these cells untouched. If you delete or overwrite the placeholder, the image data will be lost on the next import.

**Preset style settings are raw JSON.** The style properties in preset rows are JSON strings. They can be edited, but a syntax error will cause the preset to import with missing or incorrect styles. Unless you are confident with JSON, treat preset rows as read-only.

**Content Scan limit is 1,000 items.** The scan processes up to 1,000 content items per run. A warning is shown if the limit is reached. Sites with very large content libraries may not see all items in one run.

**This is not a backup tool.** Snapshots cover operations performed through this plugin, but they are not a substitute for full-site database backups. Maintain regular backups through a separate backup solution.

**Always read the import analysis.** The preliminary analysis screen is your safety net before any commit. It takes a few seconds and tells you exactly what will change.

**Import does not delete.** Variables or presets that are in your database but absent from the uploaded file are left untouched. You cannot accidentally delete data by importing a partial file.

**Divi 5 must be installed and active.** This plugin reads and writes the WordPress options that Divi 5 uses to store its design system. It requires Divi 5.0 or later and will not work with earlier Divi versions.

---

## 15. Categories

<div class="d5dsh-beta-doc-block">This feature requires <strong>Beta Preview</strong> to be enabled in Settings. The Categories button in the Manage tab section switcher is only visible in beta mode.</div>

The **Categories** feature lets you organize your design system objects — both variables and presets — into named, color-coded groups. Each DSO can belong to multiple categories.

### Creating categories

1. Go to **Manage → Categories** (section switcher button after "Everything").
2. Enter a name and choose a color in the **Add Category** toolbar. Optionally add a comment/description.
3. Click **Add**. Your category appears in the list table with its color swatch, name, comment, DSO count, and a Delete button.
4. To rename or recolor a category, delete it and re-add it. Assignments for deleted categories are removed automatically.

The **# DSOs** column shows how many variables and presets are assigned to each category.

### Assigning variables and presets

The **Assign Variables & Presets** table below the category list shows every variable, group preset, and element preset. Each row has a **Categories** cell — click it to open an inline checkbox panel listing all defined categories with color swatches. Check one or more categories per row, then click **Save Assignments**.

A **Swatches** column to the right of Categories displays a colored dot for each assigned category. Hover over a dot to see the category name.

All columns in the assignment table (DSO Type, Type, ID, Label, Categories) support the standard column-header filter and sort controls, consistent with all other Manage tab tables.

### Category column in other sections

Once categories are assigned, a **Category** column appears in the Variables, Group Presets, Element Presets, and Everything tables. It shows colored category swatches (dots with tooltip). When no categories are assigned to a DSO, the column falls back to the DSO type label.

### Where categories are used

- **Style Guide** — the "Group by category" toggle groups colors and other variables by their assigned category (see [Section 18](#18-style-guide-generator))
- **Manage tab tables** — the Category column provides at-a-glance grouping across all sections
- Categories are preserved across exports

---

## 16. Merge Duplicates

<div class="d5dsh-beta-doc-block">This feature requires <strong>Beta Preview</strong> to be enabled in Settings. The Merge Variables button in the Manage tab toolbar is only visible in beta mode.</div>

The **Merge Variables** feature consolidates two redundant variables into one, updating all preset references automatically.

### When to use it

Use Merge when the Analysis tab flags two variables as near-duplicates (same or very similar values). The Analysis finding shows a **Merge…** button that pre-fills both sides.

### How it works

1. Open **Manage → Variables → Merge Variables** (mode button in the toolbar).
2. Select the variable to **Keep** (survivor) and the variable to **Retire** (replaced).
3. The **Impact Preview** table lists every preset that currently references the retiring variable.
4. Click **Merge — update N presets** to execute.

The merge replaces every occurrence of the retiring variable ID with the keeping variable ID across all preset `attrs`, `styleAttrs`, and `groupPresets` fields. A snapshot is taken before writing. The retiring variable is then set to `archived` status and hidden from normal views.

The swap button (⇄) between the cards switches the Keep and Retire roles.

---

## 17. Impact Modal — "What Breaks?"

The **Impact** button (**ℹ**) on every variable and preset row in the Manage table opens a modal that answers "what would break if I removed this DSO?"

A toolbar below the tab bar provides three actions:
- **Expand All** — opens every collapsible section at once
- **Collapse All** — closes them all
- **Print** — opens a print-friendly view of the active tab in a new window

### What Breaks? tab

- **Summary** — total number of content items affected, with a ⚠ warning if any published content would be affected.
- **Direct references** — content items that reference the DSO directly in their post content.
- **Via presets** — per-preset breakdown showing which content uses a preset that contains the variable. Each preset section is collapsible.

### Dependencies tab

A collapsible dependency tree showing the full chain:
- **Variable** → presets that embed it → content items using those presets
- **Preset** → variables it contains + content items that use it

All levels are expandable/collapsible. Use the **Expand All** button to open the full tree at once.

---

## 18. Style Guide Generator

<div class="d5dsh-beta-doc-block">This tab requires <strong>Beta Preview</strong> to be enabled in Settings.</div>

The **Style Guide** tab (positioned between Analysis and Snapshots in the top nav) generates a visual preview of your entire design system in one place.

### Generating the style guide

1. Click the **Style Guide** tab in the top nav.
2. Use the toggle options:
   - **System vars** — include Divi system variables (heading font, body font, primary/secondary colors)
   - **Group by category** — group colors and other variables by their assigned category
   - **Include presets** — append a presets table at the bottom
3. Click **Generate Style Guide**.

### Sections

| Section | What it shows |
|---|---|
| **Colors** | 4-column swatch grid: circle swatch + label + hex value + variable ID |
| **Typography** | Each font variable rendered as "The quick brown fox…" in that font |
| **Numbers / Spacing** | Left-aligned proportional bars scaled to the numeric value |
| **Other** | Images, links, strings — plain value list |
| **Presets** (optional) | Module presets and group presets with variable reference counts |

### Exporting

- **Download HTML** — saves a fully self-contained `.html` file with all styles inline. Open it in any browser with no internet connection required.
- **Print / Save as PDF** — opens the browser print dialog. The WP admin chrome is hidden automatically so the output is clean.

---

## 19. Settings

Click the **gear icon** (⚙) in the top-right corner of the plugin to open the Settings modal. Settings are organized in five tabs:

### General

| Setting | What it does |
|---|---|
| Report Header | Optional text shown at the top of printed reports and saved report files |
| Report Footer | Optional text shown in the footer of printed reports alongside the page number |
| Site Abbreviation | Short identifier used in all exported file names. Auto-generated from the site name if left blank |

### Appearance

| Setting | What it does |
|---|---|
| Alternating row shading | Enables alternating row colors (banding) in the Manage tab tables |

### Print

Controls which variable types are grouped together on the same worksheet when exporting to Excel. Deselecting a type places it on its own separate sheet. Checkboxes: Colors, Numbers, Fonts, Images, Text, Links (all checked by default). This setting is stored per-browser in localStorage.

### Advanced

| Setting | What it does |
|---|---|
| Debug mode | Shows detailed error information, writes to `d5dsh-logs/debug.log`, and displays a debug banner at the top of the page. Triggers a page reload when saved. |
| Enable Beta Preview | Shows the Analysis, Style Guide, and Snapshots tabs. Enables Bulk Label Change, Merge Variables, Categories, Import Audit, and blank import template downloads. Applies immediately without a page reload. See [Section 20](#20-beta-features). |

### About

Displays the plugin name, version, description, copyright, and a link to the GitHub repository. No interactive elements.

All settings except Print (localStorage) are saved to the WordPress database via AJAX when you click **Save Settings**. If you close the Settings modal without saving, all changes are reverted to their previous values.

---

## 20. Beta Features

Some features are gated behind a **Beta Preview** toggle in Settings. To enable beta features, click the gear icon (⚙) in the top-right corner of the plugin and check **Enable Beta Preview** in the Advanced tab. Changes apply immediately without a page reload.

When you turn Beta Preview **off**, the plugin automatically resets the Manage tab to View mode, discards any unsaved changes from beta-only features (inline editing, bulk label change, merge), and clears any persisted mode state. This prevents stale beta UI from appearing after the toggle is disabled.

Currently in beta:

**Tabs (hidden until beta is enabled):**
- **Analysis tab** — design system health check and content scan (see [Section 12](#12-analysis-tab))
- **Style Guide tab** — visual preview of colors, typography, spacing, and presets (see [Section 18](#18-style-guide-generator))
- **Snapshots tab** — automatic backup history with one-click restore (see [Section 11](#11-snapshots-tab))

**Features within the Manage tab:**
- **Inline editing** — click any Value cell or Status dropdown in the Variables table to edit directly (Colors and Images are not inline-editable). A Save/Discard bar tracks unsaved changes.
- **Bulk Label Change** — batch rename operations on variables and presets (prefix, suffix, find & replace, case normalization). Toolbar button visible in Variables, Group Presets, and Element Presets sections.
- **Merge Variables** — consolidate two duplicate variables and update all preset references in one operation. Toolbar button visible in Variables section (see [Section 16](#16-merge-duplicates)).
- **Scan button** — quick link from each Manage section to the Content Scan in the Analysis tab.
- **Categories section** — create color-coded categories and assign them to variables and presets (see [Section 15](#15-categories)).

**Features within the Manage tab (all sections):**
- **Export Excel** — download the current filtered view as an Excel workbook. Available in Variables, Group Presets, Element Presets, All Presets, and Everything.

**Features within the Import tab:**
- **Import Audit button** — generate an audit report directly from a JSON file uploaded to the Import tab.
- **Download blank import templates** — download blank `.xlsx` templates for manual data entry without exporting first.

Beta features are fully functional but may have rough edges or change before their final release.

---

## 21. Import Security and Sanitization

Every value that passes through the plugin's import pipeline — whether it comes from an Excel file, a JSON export, a DTCG token file, or a zip archive — is sanitized before it is written to the database. This applies to all import paths: the Import tab, JSON imports, Excel imports, and DTCG imports.

### What the plugin sanitizes

| Data type | Sanitization applied |
|---|---|
| Variable labels, preset names, post titles | HTML tags and special characters stripped (`sanitize_text_field`) |
| Variable and preset IDs, meta keys, status values | Restricted to lowercase alphanumeric, dashes, and underscores (`sanitize_key`) |
| Post slugs | Cleaned to URL-safe format (`sanitize_title`) |
| Post content (layouts, pages, templates) | Dangerous tags and attributes removed; safe HTML preserved (`wp_kses_post`) |
| Nested data structures (preset attrs, theme customizer arrays) | Every string value at every depth is recursively sanitized |

### Why this matters

Design system files are often shared between teams, clients, and sites. A JSON or Excel file may pass through multiple hands before it reaches your import panel. If any value in that file contains unexpected content — embedded scripts, HTML injection, or malformed data — the plugin catches it during import and cleans it automatically.

This is especially relevant when importing files you did not create yourself: files from a colleague, a client handoff, a marketplace theme, or any third-party source. The plugin treats every imported file as untrusted, regardless of its origin.

### The Sanitization Report

When the plugin modifies any value during import, a yellow **Sanitization Report** panel appears in the import results modal. The report shows:

| Column | What it tells you |
|---|---|
| **Context** | Which item was affected (e.g. "Variable primary-color", "Excel Layout #42") |
| **Field** | Which property was cleaned (e.g. "label", "value", "post_title", "meta_key") |
| **Original (excerpt)** | The raw value from the file, truncated to 200 characters, shown in red |
| **Cleaned** | The sanitized value that was actually written to the database, shown in green |

If no values were modified, the Sanitization Report does not appear — a clean import means the file contained no problematic content.

### What to do when the report shows issues

A few modified values are normal — extra whitespace, stray HTML entities, or minor formatting differences between tools. If the report shows many items or large differences between original and cleaned values, investigate the source file. It may indicate that the originating site had compromised content, or that the file was modified after export.

The plugin never silently drops data. Every modification is logged and shown to you. The cleaned values are what get written to your database — the original problematic content is never stored.

### Post content handling

Layout and page `post_content` fields receive `wp_kses_post` sanitization, which preserves the HTML structure that Divi needs (divs, spans, classes, inline styles) while stripping dangerous elements like `<script>`, `<iframe>`, `onclick` attributes, and other potential injection vectors. This is the same sanitization WordPress itself applies when saving post content through the editor.

---

## 22. Getting Help

Click the **?** button in the top-right corner of the plugin to open the built-in help panel. This guide is displayed there, organized by section, with full-text search powered by Fuse.js.

For bugs, feedback, or feature requests, use the **envelope icon** in the top-right corner, or open an issue on the plugin's GitHub repository.

---

*D5 Design System Helper is an independent plugin and is not affiliated with, endorsed by, or supported by Elegant Themes.*
