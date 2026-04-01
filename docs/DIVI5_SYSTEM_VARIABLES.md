# Divi 5 System Variables — Technical Reference

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-03-14 | Initial document — discovered through live-site database inspection |
| 1.1 | 2026-03-20 | Added system color storage details and gvid-/gcid- prefix documentation |
| 1.2 | 2026-03-26 | Updated for confirmed beta.3 behaviour |
| 1.3 | 2026-03-27 | Cleaned for public release |

**Last updated:** 2026-03-27
**Applies to:** Divi 5.x (confirmed through 5.0.0-public-beta.3 and later)

**Disclaimer:** Elegant Themes does not publish the technical details below. This information was discovered through live-site database inspection, the Elegant Themes GitHub extension issue tracker, and the D5 Design System Helper codebase. It is accurate as of the date above but may change in future Divi releases.

---

## Storage Architecture

Divi 5 uses two separate `wp_options` keys for what the Variable Manager presents as a unified list:

| `wp_options` key | What lives there |
|---|---|
| `et_divi` | System colors, system fonts, all user-created color variables, all Theme Customizer settings |
| `et_divi_global_variables` | All non-color user-created variables (numbers, fonts, images, strings, links) |
| `et_divi_builder_global_presets_d5` | Element Presets and Option Group Presets |

**Critical distinction:** User-created color variables are stored at `et_divi['et_global_data']['global_colors']` — a nested sub-key inside `et_divi` — not in `et_divi_global_variables`. Non-color variables go into `et_divi_global_variables`. System color variables are stored as flat strings at the top level of `et_divi`, not in the `global_colors` dict at all.

---

## Complete List of Hardwired System Variables

### A. System Color Variables (5 confirmed)

These are stored as plain hex strings in top-level keys of `et_divi`. They are **not** entries in the `global_colors` dict. The `gcid-` identifiers are synthesised — they match the IDs used by Divi internally in `$variable()$` expressions, but the backing storage is a plain `et_divi` key.

| UI Label | Synthesised gcid | `et_divi` key | Default value | Can delete | Can rename |
|---|---|---|---|---|---|
| Primary Color | `gcid-primary-color` | `accent_color` | `#2ea3f2` | No | No |
| Secondary Color | `gcid-secondary-color` | `secondary_accent_color` | *(none set)* | No | No |
| Heading Text Color | `gcid-heading-color` | `header_color` | `#666666` | No | No |
| Body Text Color | `gcid-body-color` | `font_color` | `#666666` | No | No |
| Link Color | `gcid-link-color` | `link_color` | inherits `accent_color` | No | No |

**Notes:**
- Values are fully editable (any valid hex); only ID and label are locked.
- The first four are formally excluded from deletion by Divi's core `Portability.php` (`$excluded_colors` array). `link_color` has no corresponding entry in `global_colors` at all — it is a pure Customizer key.

**Sources:**
- [GitHub Issue #473 — elegantthemes/create-divi-extension](https://github.com/elegantthemes/create-divi-extension/issues/473) — confirms `gcid-` UUID format and PHP function `et_builder_get_global_color_info()`
- [GitHub Issue #450 — elegantthemes/create-divi-extension](https://github.com/elegantthemes/create-divi-extension/issues/450) — confirms `et_divi` option storage; references source file `class-et-global-settings.php`

---

### B. System Font Variables (2 confirmed)

Stored as plain strings in top-level keys of `et_divi`. Mirror the Theme Customizer's heading/body font settings. Not in `et_divi_global_variables`.

| UI Label | Synthesised ID | `et_divi` key | Default value | Can delete | Can rename |
|---|---|---|---|---|---|
| Heading | `--et_global_heading_font` | `heading_font` | `'none'` (Open Sans fallback) | No | No |
| Body | `--et_global_body_font` | `body_font` | `'none'` (Open Sans fallback) | No | No |

**Notes:**
- The IDs use a CSS custom-property naming convention. They are stable identifiers used internally.
- Changing the font in the Variable Manager writes back to `et_divi['heading_font']` / `et_divi['body_font']`.
- These are the same keys as the Theme Customizer "Heading Font" and "Body Font" settings — editing either location changes the same stored value.

**Source:** [How To Replace Fonts With Divi 5's Font Design Variables](https://www.elegantthemes.com/blog/divi-resources/how-to-replace-fonts-with-divi-5s-font-design-variables)

---

### C. The Built-In Internal Number Variable (1 confirmed, partially unknown)

| Internal ID | Type | Label | Value | Where stored |
|---|---|---|---|---|
| `gvid-r41n4b9xo4` | Number | *Unknown* | *Unknown* | Not in database — baked into Divi PHP source |

**What is known:**
- Referenced **104 times** across 24 presets in Elegant Themes' official Divi 5 Launch Freebie sample export pack.
- Does **not** appear in the exported `global_variables` list. It is not a database entry.
- Not documented anywhere publicly — zero results on Google, GitHub search, Reddit, or any blog as of 2026-03-16.
- Strong evidence it is a hardwired default value in Divi's PHP source code. Likely a default spacing, sizing, or layout value.

---

### D. Hidden Palette Colors (15 confirmed)

These exist inside `et_divi['et_global_data']['global_colors']` — the same dict as user-created colors — but are **not shown** in the Divi Builder's variable picker. They back the Divi color palette swatches in the color picker UI.

| Group | Labels (5 per group) | Count |
|---|---|---|
| Primary Qual | Primary Qual 1, Primary Qual 2, Primary Qual 3, Primary Qual 4, Primary Qual 5 | 5 |
| Secondary Qual | Secondary Qual 1, Secondary Qual 2, Secondary Qual 3, Secondary Qual 4, Secondary Qual 5 | 5 |
| Quant Color | Quant Color 1, Quant Color 2, Quant Color 3, Quant Color 4, Quant Color 5 | 5 |

**What is known:**
- Their `gcid-` values are random per-install (UUID-style, like all user colors) — there is no fixed ID for "Primary Qual 1" across sites.
- They have **no flag** in the database distinguishing them from visible user colors. Detection is by label pattern only: `/^(Primary Qual|Secondary Qual|Quant Color)\s+\d+$/i`
- They are fully editable via the database (value, label, status can all be changed). Divi does not lock them the way it locks system colors.
- Not documented anywhere publicly as of 2026-03-16.

---

### E. Theme Customizer Keys in `et_divi` (Not Exposed as Design Variables)

The following `et_divi` keys exist on all Divi sites but are **not** surfaced as Design Variables in the Variable Manager:

| `et_divi` key | Meaning | Default |
|---|---|---|
| `body_font_size` | Body text size | `14` (px) |
| `header_font_size` | Heading font size | `30` (px) |
| `content_width` | Max content area width | `1080` (px) |
| `body_font_height` | Body line height | — |
| `gutter_width` | Column gutter | — |

**Source:** [Divi Layout & Typography Customizer Settings — Elegant Themes Help Center](https://help.elegantthemes.com/en/articles/8625289-divi-layout-typography-customizer-settings)

---

### F. Divi-Injected CSS Custom Properties (Not Design Variables, Not in Database)

These CSS properties are computed and injected by Divi's theme engine at render time. They appear in preset attribute data but cannot be managed, exported, or imported as Design Variables.

| CSS property | Source |
|---|---|
| `var(--et_global_heading_font_weight)` | Found in ET sample presets |
| `var(--et_global_body_font_weight)` | Found in ET sample presets |
| `var(--gvid-XXXXXXXX)` | CSS output format for all user global variables at render time |

The `var(--gvid-xxx)` format was confirmed in the [Divi 5 Public Beta 5 Release Notes](https://www.elegantthemes.com/blog/divi-resources/divi-5-public-beta-5-release-notes).

---

## Summary Table — All Confirmed Hardwired Variables

| Variable name (UI) | Internal ID | `wp_options` location | Type | Hidden | Deletable | Value editable |
|---|---|---|---|---|---|---|
| Primary Color | `gcid-primary-color` | `et_divi['accent_color']` | Color | No | No | Yes |
| Secondary Color | `gcid-secondary-color` | `et_divi['secondary_accent_color']` | Color | No | No | Yes |
| Heading Text Color | `gcid-heading-color` | `et_divi['header_color']` | Color | No | No | Yes |
| Body Text Color | `gcid-body-color` | `et_divi['font_color']` | Color | No | No | Yes |
| Link Color | `gcid-link-color` | `et_divi['link_color']` | Color | No | No | Yes |
| Heading (font) | `--et_global_heading_font` | `et_divi['heading_font']` | Font | No | No | Yes |
| Body (font) | `--et_global_body_font` | `et_divi['body_font']` | Font | No | No | Yes |
| Primary Qual 1–5 | `gcid-XXXX` (random per site) | `et_divi['et_global_data']['global_colors']` | Color | **Yes** | Yes | Yes |
| Secondary Qual 1–5 | `gcid-XXXX` (random per site) | `et_divi['et_global_data']['global_colors']` | Color | **Yes** | Yes | Yes |
| Quant Color 1–5 | `gcid-XXXX` (random per site) | `et_divi['et_global_data']['global_colors']` | Color | **Yes** | Yes | Yes |
| *(unknown label)* | `gvid-r41n4b9xo4` | Not in database — baked into Divi PHP | Number | **Yes** | N/A | No |

**Total confirmed hardwired variables: 25**
(5 system colors + 2 system fonts + 15 hidden palette colors + 1 built-in gvid)

---

## `$variable()` Reference Syntax

Divi stores variable references inline in preset and layout `post_content` using this JSON-in-string syntax:

```
# Color variable reference:
$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{}}})$

# Color reference with opacity modifier:
$variable({"type":"color","value":{"name":"gcid-s0kqi6v11w","settings":{"opacity":86}}})$

# Non-color variable reference (number, font, string, image, link):
$variable({"type":"content","value":{"name":"gvid-r41n4b9xo4","settings":{}}})$
```

The JSON is frequently stored with Unicode-escaped quotes (`\u0022`) instead of literal `"`. Both encodings resolve to the same reference. Normalisation is required before `json_decode()`.

For the complete formal grammar, see [SERIALIZATION_SPEC.md](SERIALIZATION_SPEC.md).

---

## Bibliography

### Official Elegant Themes Documentation

| Source | URL |
|---|---|
| Design Variables in Divi 5 — ET Help Center | https://help.elegantthemes.com/en/articles/11027601-design-variables-in-divi-5 |
| Global Variables in Divi 5 — ET Help Center | https://help.elegantthemes.com/en/articles/13348842-global-variables-in-divi-5 |
| Mastering Global Colors In Divi 5 — ET Blog | https://www.elegantthemes.com/blog/divi-resources/mastering-global-colors-in-divi-5 |
| How To Replace Fonts With Divi 5's Font Design Variables — ET Blog | https://www.elegantthemes.com/blog/divi-resources/how-to-replace-fonts-with-divi-5s-font-design-variables |
| The Divi Color Management System — ET Help Center | https://help.elegantthemes.com/en/articles/8661797-the-divi-color-management-system |
| Relative Colors & HSL in Divi 5 — ET Help Center | https://help.elegantthemes.com/en/articles/11631084-relative-colors-hsl-in-divi-5 |
| How To Import & Export Design Variables In Divi 5 — ET Blog | https://www.elegantthemes.com/blog/divi-resources/how-to-import-export-design-variables-in-divi-5 |
| Divi 5 Public Beta 5 Release Notes — ET Blog | https://www.elegantthemes.com/blog/divi-resources/divi-5-public-beta-5-release-notes |
| Divi Layout & Typography Customizer Settings — ET Help Center | https://help.elegantthemes.com/en/articles/8625289-divi-layout-typography-customizer-settings |

### Elegant Themes GitHub Issue Tracker

| Source | URL |
|---|---|
| Global color returns wrong value — Issue #473 | https://github.com/elegantthemes/create-divi-extension/issues/473 |
| Change Global Colors Settings — Issue #450 | https://github.com/elegantthemes/create-divi-extension/issues/450 |

### W3C Design Tokens Community Group

| Source | URL |
|---|---|
| DTCG specification (v2025.10) | https://tr.designtokens.org/format/ |
| DTCG GitHub repository | https://github.com/design-tokens/community-group |

---

## Open Questions

1. **What is `gvid-r41n4b9xo4`?** Its label, value, and semantic meaning are unknown. Determining this requires access to Divi's PHP source or inspection of a fresh Divi 5 install's database immediately after activation before any user customisation.

2. **Are there other built-in `gvid-` values?** Only one has been confirmed. A larger corpus of exported preset data from other Divi 5 sites would be needed to find additional candidates.

3. **What are the actual hex values of the hidden palette colors on a fresh install?** The IDs are random per-site, but the default hex values for Primary Qual 1–5 etc. should be consistent on a fresh install. Not yet recorded.

4. **Are there hardwired preset IDs?** Certain preset IDs may also be baked into Divi, similar to how `gvid-r41n4b9xo4` is baked into the variable system.

5. **Does the `folder` field on global colors ever have a non-empty value?** On the tested site it was `''` for all 49 colors. If folder support exists, the hidden palette detection by label pattern may need to be reconsidered.

---

*Source: [github.com/akonsta/d5-design-system-helper](https://github.com/akonsta/d5-design-system-helper)*
