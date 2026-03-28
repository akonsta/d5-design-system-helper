# Content Scanner — Design Notes

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-03-20 | Initial document — extracted from session 30 design notes |
| 1.1 | 2026-03-27 | Cleaned for public release; merged third-party scanning note |

**Applies to:** `includes/Admin/ContentScanner.php`, `includes/Admin/AuditEngine.php`
**See also:** `docs/AUDIT_CHECKS_RATIONALE.md` — rationale for the contextual audit checks that depend on scan data

---

## What the scanner produces

A single scan pass returns four objects:

**`active_content`** — posts with at least one DSO reference (`has_dso === true`), grouped by post type.

**`inventory`** — all scanned rows regardless of DSO presence. `et_template` rows are expanded with `canvases` children (Header, Body, Footer); canvas types are excluded from the top level to avoid double-counting.

**`dso_usage`** — reverse index:
```
{
  variables: { "gcid-primary": { count: 3, posts: [...] } },
  presets:   { "preset-abc":   { count: 1, posts: [...] } }
}
```
Each section is sorted by count descending. This is the primary input for contextual audit checks.

**`meta`** — `total_scanned`, `active_count`, `limit`, `limit_reached`, `by_type`, `by_status`, `ran_at`. The `limit_reached` flag triggers a warning in the UI when the 1,000-item ceiling is hit.

---

## Scan limit

`ContentScanner::CONTENT_LIMIT = 1000`. This covers the vast majority of Divi sites. Sites with very large content libraries will see a warning when the limit is reached; a future Pro tier will address this with segmented CPT scanning (see below).

---

## Third-party Divi 5 content

The scanner handles third-party Divi 5 content transparently, because:

1. **No `post_author` filtering** — the query does not filter by author ID
2. **No block namespace validation** — the scanner does not check `<!-- wp:divi/...` block comments
3. **Pure regex pattern matching** — `DiviBlocParser` runs regex against raw `post_content` strings; it cannot tell who created the content
4. **Standard API is standard** — third-party modules using the `$variable(...)$` token API emit the same token format that Divi's own modules use; they are indistinguishable

### What "Divi 5 compliant" means for the scanner

A third-party module is fully transparent to the scanner if it:
- Stores output in `post_content` (standard WordPress block storage)
- Uses `$variable({"type":"...","value":{"name":"gcid-..."}})$` tokens for variable references
- Uses `"modulePreset":["preset-id"]` or `"presetId":["preset-id"]` for preset references

### Edge cases

| Scenario | Scanner behaviour |
|----------|------------------|
| Third-party module using Divi's `$variable(...)$` API | Detected correctly — indistinguishable from built-in modules |
| Third-party module with its own proprietary reference format | Not detected — the scanner cannot know about formats it was not designed for |
| Divi 4 content that "still works" via compatibility layer | Zero DSO hits — correct, as Divi 4 content was not created with the design system |
| Content in ACF / custom meta fields | Not scanned — only `post_content` is queried |
| Custom post types built with Divi | Not scanned in current release — see CPT future direction below |

---

## CPT scanning — future Pro direction

The current scanner covers eight standard post types (`post`, `page`, `et_pb_layout`, `et_template`, and their canvas sub-types). Custom post types (CPTs) are explicitly out of scope for the initial release.

**Why not included now:**
- Auto-detection adds complexity; unknown CPTs could have very large result sets
- Eight standard types cover the vast majority of Divi content on typical sites
- Keeping the scan scoped avoids memory/timeout issues on live sites

**Design for a future Pro implementation:**

1. **Auto-detect CPTs with Divi content** — `get_post_types(['show_ui' => true, 'public' => true])` filtered by those with at least one post matching `post_content LIKE '%divi/%'`
2. **Segmented scan** — one `ContentScanner` instance per CPT, each with its own `CONTENT_LIMIT`, keeping individual scans within reasonable memory bounds
3. **Separate data objects** — each CPT scan returns the same `{ active_content, inventory, dso_usage, meta }` shape; the UI displays them as collapsible sections
4. **Optional merge** — a coordinator function merges results by summing counts and concatenating post lists

The `ContentScanner` class is already parameterisable — post types are a constant array; extending it to accept an injected list at construction is a small change. The effort to implement segmented CPT scanning is **medium**.

---

## Design decisions

- **No origin filtering is by design** — adding `post_author` or namespace filtering would produce false negatives. If a third-party module correctly uses the DSO API, it should be reported.
- **Scope documented in class docblock** — `ContentScanner.php` has an explicit limitations section listing what works and what does not, so users are not surprised when CPT content is absent.
- **`et_template` canvas expansion** — Theme Builder templates consist of Header, Body, and Footer canvases stored as child posts. The scanner expands these as sub-rows of the parent template rather than listing them independently, preserving the logical grouping.
