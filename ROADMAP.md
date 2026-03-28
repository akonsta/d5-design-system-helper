# D5 Design System Helper — Roadmap

| Version | Date | Notes |
|---------|------|-------|
| 0.1.0 | 2026-03-27 | Initial public release |

This document describes what is currently available and what is planned for future releases. Features are prioritised based on the real problems Divi 5 site owners face when managing and migrating design systems.

---

## What's Available Now

The current release includes a fully functional set of tools for managing, exporting, importing, and backing up your Divi 5 design system.

**Export to Excel** — pull Colors, Numbers, Fonts, Images, Text, and Links into a structured spreadsheet. Colors include a visual swatch column. Edit in Excel, import back through the plugin.

**Import from Excel** — upload a modified spreadsheet and preview every change before committing. Import is non-destructive: variables absent from the file are left untouched.

**JSON export and import** — move any type of Divi data between sites: Variables, Presets, Layouts, Pages, Builder Templates, and Theme Customizer settings.

**DTCG export** — export your variables in the W3C Design Tokens standard format for use with Figma (via Tokens Studio), Style Dictionary, and other design tooling.

**Manage tab** — browse, search, and filter your variables and presets in one place. Rename variables inline. Bulk-rename using prefix, suffix, find-replace, and case normalisation. Export as CSV. Print a style reference.

**Snapshots** — every import and every edit triggers an automatic backup. Restore any previous state in one click, including an Undo Last Import shortcut.

**Import analysis** — before any import commits, the plugin shows you exactly what is in the file, what will be added, what will be updated, and which dependencies are missing on the destination site.

**Audit / Analysis** — Simple Audit (14 checks) runs against your variable and preset data. Contextual Audit (+8 content-aware checks) runs a full content scan first to identify broken references, orphaned presets, high-impact variables, and more. Exportable as Excel.

**Content Scan** — reverse-index of which pages, posts, layouts, and templates use which variables and presets. Two views: Content → DSO Map and DSO → Usage Chain.

**Style Guide** — visual preview of your design system (colors, typography, spacing, presets). Printable and exportable as HTML/PDF.

**Impact Analysis** — click the Impact button on any variable or preset to see what content would break if it were deleted, plus a full dependency tree.

**Categories** — create color-coded categories and assign variables and presets. Filter exports and style guide by category.

**Snapshots** — automatic backup before every write operation. Restore any previous state in one click, including Undo Last Import.

**In-plugin help** — the full user guide is accessible via the ? button in the top right of every page in the plugin.

---

## What's Coming Next

### Near-term: Annotation Round-Trip

**The problem:** The Excel file is a working document. When you add notes, categories, or friendly names to rows in Excel, those annotations disappear on the next export. There is no way to persist custom metadata alongside your variables.

**The plan:** A dedicated annotation store (saved in WordPress, keyed by variable ID) that persists user-created metadata across export and import cycles. The first version will include:

- A **Notes / Comments column** — free-form per-variable annotation for team documentation and handoff
- **Round-trip preservation** — annotations written in Excel are detected on import and stored; they reappear on the next export with a distinct column style so they are clearly distinguished from system columns
- **Additional Information round-trip** — project metadata entered in the Additional Information section (owner, customer, project, environment) is read back from the Info sheet on re-export so it does not need to be re-entered each time

This feature is the single highest-value addition for teams who use the Excel file as a shared working document.

---

### Near-term: Create Variables

**The problem:** You can edit and import variables, but you cannot create new ones through this plugin. Creating variables requires going into the Divi builder.

**The plan:** A simple variable creation form directly in the Manage tab. Add a new color, number, font, or other variable type with a name and value — the variable is immediately available in the Divi builder on the next page load. No Divi builder required for routine design token work.

---

### Medium-term: Site Migration Assistant

**The problem:** Moving a design system from one site to another requires knowing the right import order, checking for missing dependencies, and often doing multiple passes. The analysis panel helps, but the process is still manual.

**The plan:** A guided migration flow that bundles your full design system into a single transferable package, validates it against a destination site (via an endpoint check), and walks you through the import sequence in the right order — Variables, then Presets, then Layouts — surfacing each dependency check before the next step begins.

---

### Longer-term: Dev / Staging / Production Modes

**The problem:** Design system changes are often developed on a staging site before being pushed to production. There is currently no way to mark your installation as dev, staging, or production, or to enforce appropriate restrictions on each.

**The plan:** An environment mode setting that changes plugin behavior based on the current environment:
- **Development** — all features enabled, debug mode available, no restrictions
- **Staging** — imports enabled, exports enabled, snapshot history extended
- **Production** — import restricted (require confirmation + snapshot), audit runs on schedule, no experimental features

---

### Longer-term: WP-CLI Support

For developers managing multiple Divi sites, WP-CLI commands to export, import, snapshot, and audit without a browser session:

```bash
wp d5dsh export --type=vars --format=xlsx --output=./exports/
wp d5dsh import ./exports/vars.xlsx --dry-run
wp d5dsh snapshot list
wp d5dsh audit --format=csv --output=./audit.csv
```

---

## What Is Not On This Roadmap

The following are explicitly out of scope for the free Helper plugin:

- Full-site backup and restore (use a dedicated backup plugin)
- Editing Divi page content through this plugin
- Any feature requiring a connection to an external service
- Multi-user real-time collaboration

---

## Pro Version

A commercial **D5 Design System Manager Pro** tier is planned, extending the free Helper with features suited to agencies, larger teams, and multi-site workflows.

- Unlimited snapshot history with diff-only storage
- Scheduled audit scans with email reports
- Branded PDF style guide export
- Category tags for variables (for filtered exports and filtered style guide views)
- Multi-environment workflow support
- Cross-site design system sync
- Role-based access control

All core management, naming, import, and export features will remain free in the Helper.

---

*Roadmap reflects current thinking and is subject to change based on user feedback. Feature suggestions welcome via the feedback button in the plugin (envelope icon, top right).*
