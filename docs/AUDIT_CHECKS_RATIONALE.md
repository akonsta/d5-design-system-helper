# Audit Checks — Rationale

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-03-22 | Initial document — proposed contextual checks |
| 1.1 | 2026-03-27 | Updated to reflect implemented state; added Simple Audit checks; noted deferred items |

**Applies to:** `includes/Admin/AuditEngine.php`
**See also:** `docs/CONTENT_SCANNER_NOTES.md` — how content scan data is produced for contextual checks

---

## Overview

The audit system has two modes:

- **Simple Audit** — 14 checks against variables and presets only; no content scan required
- **Contextual Audit** — all Simple checks plus 8 additional checks that require content scan data

This document records the rationale for each check: what problem it solves, why it is placed at its severity tier, and (for deferred checks) why it was not implemented.

---

## Simple Audit checks

### Errors

**`broken_variable_refs`**
Presets that reference a variable ID not defined on this site. Results in broken rendering. Highest-priority fix.

**`archived_vars_in_presets`**
Archived variables still referenced by active presets. The variable is marked as retired but still in use — a silent inconsistency that will cause problems if the variable is ever deleted.

**`duplicate_labels`**
Two or more DSOs sharing the same label but having different IDs, values, or types. Creates ambiguity in the Divi builder UI where variables are selected by label.

### Warnings

**`singleton_variables`**
Variables referenced by only one preset. These are candidates for inlining — the variable adds no reuse value if it has only one consumer. Low-noise: not every singleton is wrong, but patterns of them suggest over-engineering.

**`near_duplicate_values`**
Variables sharing identical normalized values (e.g. `#1a1a1a` and `#1A1A1A`, or `16px` and `16px`). Candidates for consolidation.

**`preset_duplicate_names`**
Presets of the same module type sharing a name. Creates ambiguous choices in the Divi editor's preset picker.

**`empty_label_variables`**
Variables or global colors with a blank label. Undiscoverable in the Divi builder — the user cannot select what they cannot find.

**`unnamed_presets`**
Presets with a missing or blank name. Same discoverability problem as empty labels.

**`similar_variable_names`**
Variables whose labels normalize to the same token (e.g. "Primary Blue", "primary-blue", "PrimaryBlue"). Indicates naming inconsistency rather than true duplication.

**`naming_convention_inconsistency`**
Variables of the same type using mixed naming styles (Title Case vs kebab-case vs camelCase). Flags the site as a whole if conventions are not applied consistently within a type group.

### Advisories

**`hardcoded_extraction_candidates`**
Hardcoded hex colors appearing in 10+ presets. If a color value recurs that often, it should be a variable. This check surfaces the most actionable extraction candidates.

**`orphaned_variables`**
Variables defined on the site but not referenced by any preset. May be unused or may be used directly in content — the contextual audit distinguishes these cases.

**`preset_no_variable_refs`**
Presets containing no variable references — all values are hardcoded. These presets cannot benefit from design token updates and are candidates for refactoring.

**`variable_type_distribution`**
Distribution of variables by type; flags any single type exceeding 60% of all variables. Also renders a bar chart in the report for visual reference.

---

## Contextual Audit checks

These require a content scan to run first. The scan data provides the `dso_usage` reverse index.

### Errors

**`archived_dsos_in_content`**
Archived variables or presets referenced in published post content. Unlike `archived_vars_in_presets` (which looks at preset definitions), this catches archived DSOs used directly in live pages — a breakage risk.

**`broken_dso_refs_in_content`**
Variable or preset IDs found in published content that do not exist on the site. These elements will render broken or with fallback styling on live pages.

### Warnings

**`orphaned_presets`**
Presets that exist on the site but are never used in any scanned content. Complements the Simple Audit's `orphaned_variables` check; helps identify dead presets accumulated over time.

**`high_impact_variables`**
Variables used in 10 or more content items (threshold: `HIGH_IMPACT_THRESHOLD = 10`). Flags these as "change carefully" — editing their value affects widespread content. The inverse of the singleton warning.

**`preset_naming_convention`**
Modules with 4+ presets that mix naming conventions (Title Case, snake_case, camelCase, kebab-case). The threshold of 4 avoids false positives on modules with only a handful of presets.

### Advisories

**`variables_bypassing_presets`**
Variables used directly in content AND referenced inside preset attribute definitions — a split usage pattern. Indicates the design system is being partially bypassed; variables are being applied inline rather than through presets.

**`singleton_presets`**
Presets applied to exactly one content item. Analogous to `singleton_variables`. These may be one-offs that were created instead of using inline values, or legitimate reusable presets that simply haven't been adopted yet.

**`overlapping_presets`**
Pairs of presets in the same module whose variable sets overlap ≥ 80% (where the smaller set has ≥ 3 variables). High overlap with substantial variable counts suggests the presets could be consolidated. Threshold: `OVERLAP_RATIO_THRESHOLD = 0.8`.

---

## Deferred checks — not implemented

The following checks were proposed but deferred due to complexity or low signal-to-noise ratio:

**`dso_heavy_content` (proposed A7)**
Content items referencing an unusually large number of distinct variables (proposed threshold ≥ 20). Deferred: the threshold is difficult to calibrate without real-world data, and the finding is hard to act on without more context.

**`variable_type_mismatch` (proposed A8)**
Variables whose values do not match the pattern expected for their type (e.g. a "spacing" variable with no CSS unit). Deferred: requires per-type validation rules and produces high noise on sites with informal naming. Revisit when naming convention enforcement is more mature.

---

*Source: [github.com/akonsta/d5-design-system-helper](https://github.com/akonsta/d5-design-system-helper)*
