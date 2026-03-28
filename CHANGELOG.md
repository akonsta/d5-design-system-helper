# Changelog

All notable changes to D5 Design System Helper are documented here.
Versions follow [Semantic Versioning](https://semver.org/).

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
