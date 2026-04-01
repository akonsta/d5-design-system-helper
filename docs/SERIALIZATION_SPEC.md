# Divi 5 Serialization Specification

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-03-19 | Initial document |
| 1.1 | 2026-03-20 | Added ABNF grammar; refactored DiviBlocParser to multi-strategy dispatch |
| 1.2 | 2026-03-20 | Expanded change-impact matrix; added §8 |
| 1.3 | 2026-03-27 | Cleaned for public release |
| 1.4 | 2026-03-30 | Added §15 (Divi native export JSON), §16 (D5DSH plugin export JSON), §17 (DTCG export JSON); updated §14 revision history |

**Document version:** 1.4
**Applies to:** Divi 5.x (confirmed through 5.0.0-public-beta.3 and later)
**Status:** Normative — `DiviBlocParser.php` is the authoritative implementation of this spec

---

## 1. Purpose and Scope

This document describes how Divi 5 serializes Design System Objects (DSOs) — specifically
variable references and preset references — into the WordPress database. It serves two
purposes:

1. **Human reference** — anyone reading or debugging plugin code can understand what
   patterns to expect without spelunking through the codebase.
2. **Change management** — if Divi changes its serialization format, this document
   identifies exactly what needs updating and which kind of change requires a code
   rewrite vs. a constant update.

**In scope:**
- Variable reference tokens embedded in field values
- Preset reference keys embedded in block markup
- The JSON payload structures inside those tokens
- WordPress option key paths where DSO data is stored
- Quote encoding variations and why they exist
- D5 Design System Helper plugin storage keys

**Out of scope:**
- Divi Visual Builder's in-memory JavaScript data structures
- REST API request/response payloads
- Divi 4 (Classic) serialization (entirely different format)

---

## 2. Background: What Gets Serialized and Where

Divi 5 stores its design system in WordPress option rows. There are three distinct
storage locations:

| What | WordPress Option | Path inside option |
|---|---|---|
| Non-color variables | `et_divi_global_variables` | `type → id → entry` |
| Colors (user-defined + system) | `et_divi` | `[et_global_data][global_colors]` |
| System font variables | `et_divi` | `[heading_font]`, `[body_font]` |
| System color variables | `et_divi` | `[accent_color]`, `[secondary_accent_color]`, `[header_color]`, `[font_color]`, `[link_color]` |
| Element Presets + Group Presets | `et_divi_builder_global_presets_d5` | `[module|group][divi/module-name][items][id]` |
| Page / layout post content | `wp_posts.post_content` | Full HTML block markup |

Variable references do not appear in the option rows that _define_ variables. They
appear inside the option rows that define _presets_ (inside `attrs` and `styleAttrs`
fields), and inside `post_content` strings for published pages, layouts, and templates.

---

## 3. Variable Reference Token

### 3.1 Token Overview

When a Divi 5 preset or page module has a CSS property value that points to a global
variable, Divi serializes that reference as a **variable reference token** — a
specially-formatted string that replaces what would otherwise be a literal CSS value.

Example — a button's background color pointing to the site's Primary Color variable:

```
$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{}}})$
```

The token is designed to be recognizable in any string context: it starts and ends with
`$`, contains the word `variable`, and wraps a JSON payload in parentheses.

### 3.2 Token Structure

```
$variable( <JSON-PAYLOAD> )$
```

- The dollar signs (`$`) are literal ASCII dollar signs, not regex anchors.
- There is no whitespace between `$variable(` and the payload, or between the payload
  and `)$`.
- The payload is always a JSON object (curly braces).

### 3.3 JSON Payload Fields

```jsonc
{
  "type":  "<token-type>",     // Required. See §3.4.
  "value": {
    "name":     "<id>",        // Required. The variable ID (gcid-* or gvid-*).
    "settings": { ... }        // Optional. Modifier overrides. See §3.5.
  }
}
```

All three top-level paths (`type`, `value`, `value.name`) are required for a token to
be usable. The `settings` key may be absent or an empty object `{}`.

### 3.4 Token Type Values

| `type` value | Meaning | ID prefix |
|---|---|---|
| `"color"` | References a Global Color variable | `gcid-` |
| `"content"` | References a non-color Global Variable (numbers, fonts, images, strings, links) | `gvid-` |

The `type` field is informational — Divi uses it for rendering hints. For dependency
analysis purposes, `value.name` is the authoritative identifier regardless of type.

### 3.5 Settings Object

The `settings` object carries per-use modifier overrides that apply on top of the
referenced variable's base value.

Currently documented modifier:

| Key | Type | Meaning |
|---|---|---|
| `opacity` | integer 0–100 | Percentage opacity applied to the resolved color value |

Example — Primary Color at 86% opacity:
```json
{"type":"color","value":{"name":"gcid-primary-color","settings":{"opacity":86}}}
```

The `settings` object may be empty (`{}`), meaning no overrides. It may also be absent
entirely from some older or third-party presets — treat absence as equivalent to `{}`.

Unknown keys inside `settings` should be preserved and passed through unchanged.

### 3.6 Variable ID Format

Variable IDs follow a fixed-prefix, random-suffix format:

```
gcid-[a-z0-9]{10}    Global Color variable
gvid-[a-z0-9]{10}    Global Variable (non-color)
```

The suffix is a 10-character lowercase alphanumeric string generated at creation time.
It is opaque — no semantic meaning is encoded in the characters.

Exception: Divi ships a small set of built-in system colors with well-known IDs:

```
gcid-primary-color
gcid-secondary-color
gcid-heading-color
gcid-body-color
gcid-link-color
```

These have human-readable suffixes rather than random ones. They are always present
on any Divi 5 site and should never be flagged as missing or orphaned.

There is also at least one known built-in non-color variable:

```
gvid-r41n4b9xo4     Divi internal spacing/layout default (referenced by built-in presets)
```

### 3.7 Quote Encoding Variations

The JSON payload inside a variable token may use three different quote encodings,
depending on the context in which the token appears:

| Context | Encoding | Example |
|---|---|---|
| WordPress block markup (`post_content`) | `\u0022` (Unicode escape) | `{\u0022type\u0022:\u0022color\u0022,...}` |
| JSON re-encode artefact (plugin processes attrs and re-encodes) | `\"` (backslash-escaped) | `{\"type\":\"color\",...}` |
| Raw preset attrs / JSON export files | `"` (plain) | `{"type":"color",...}` |

**Why `\u0022` in post_content:**
WordPress stores Gutenberg / block editor content as HTML comments of the form
`<!-- wp:divi/module { "key": "value" } /-->`. When Divi writes the block comment,
it encodes the inner JSON using Unicode escapes for safety inside an HTML comment.
This is WordPress block editor behavior, not Divi-specific.

**Why `\"` appears:**
When the plugin reads preset `attrs` (an associative PHP array), then calls
`json_encode()` to convert it to a string for scanning, PHP escapes any `"` characters
it finds inside string values. If those string values already contained `$variable(...)$`
tokens with normal `"` quotes, those quotes become `\"` in the encoded output.

A correct parser **must normalize** all three quote forms to plain `"` before attempting
`json_decode()` on the payload. The normalization order does not matter; both `\u0022`
and `\"` map to `"`.

---

## 4. Preset References

Preset references appear in WordPress `post_content` block markup. They tell Divi
which saved preset(s) to apply to a given module at render time.

### 4.1 Element Preset Reference

An **Element Preset** applies full module-level styling.

```
"modulePreset": ["<preset-id>"]
```

- The value is always a JSON array containing exactly one preset ID string.
- The key is always `"modulePreset"` (camelCase, no alternatives observed).
- Appears at the top level of a module's block attributes JSON object.

### 4.2 Group Preset (Option Group Preset) Reference

A **Group Preset** applies styling to a named attribute slot within a module.

```json
"groupPreset": {
  "<slot-name>": {
    "presetId": ["<preset-id>"],
    "groupName": "<group-type>"
  }
}
```

- `groupPreset` is the container key at the module attribute level.
- Each child key is a slot name (e.g., `"contentBackground"`, `"designSpacing"`).
- `presetId` is always a JSON array with exactly one string.
- `groupName` identifies the preset type (e.g., `"divi/background"`, `"divi/spacing"`).
- A single module may reference multiple group presets via different slot names.

When scanning for group preset references, match the `"presetId"` key anywhere in the
document rather than navigating the `groupPreset` container — the container structure
could change in future Divi versions, but the `presetId` leaf is the stable extraction
point.

### 4.3 Preset ID Format

```
[a-z0-9]{10}    10-character lowercase alphanumeric string
```

Preset IDs have no prefix (unlike variable IDs). They are opaque random strings.

---

## 5. Block Markup Format

WordPress `post_content` for Divi 5 pages uses the standard WordPress block editor
format with `divi/*` block namespaces.

### 5.1 Block Comment Structure

```
<!-- wp:divi/<block-type> <json-attrs> -->
  <!-- wp:divi/<block-type> <json-attrs> /-->
<!-- /wp:divi/<block-type> -->
```

Leaf blocks (no children) use the self-closing form with ` /-->`.

### 5.2 Module Names (block types)

Divi uses the `divi/` namespace prefix. Examples: `divi/section`, `divi/row`,
`divi/column`, `divi/button`, `divi/heading`, `divi/text`, `divi/image`.

### 5.3 Attribute JSON

The `<json-attrs>` in a block comment is a standard WordPress block attributes JSON
object with the `\u0022` quote encoding described in §3.7.

---

## 6. Preset Attribute Structure

Each preset stored under `et_divi_builder_global_presets_d5` has the following shape.

### 6.1 Preset Entry

```jsonc
{
  "id":          "<preset-id>",           // opaque 10-char ID
  "name":        "<display-name>",        // human-readable
  "moduleName":  "divi/<module>",         // Element Presets only
  "groupName":   "divi/<group>",          // Group Presets only
  "groupId":     "<group-id>",            // Group Presets only
  "version":     "5.0.0-...",             // Divi version at creation
  "type":        "module|group",
  "created":     <unix-ms>,               // Unix timestamp in milliseconds
  "updated":     <unix-ms>,
  "attrs":       { ... },                 // Full responsive attribute tree
  "styleAttrs":  { ... },                 // Style-property subset (may be absent)
  "renderAttrs": { ... }                  // Render-time projection (may be absent)
}
```

For dependency analysis (finding which variables a preset uses), always scan `attrs`
first, then `styleAttrs` as a supplement. Do not rely on `renderAttrs`.

### 6.2 Attribute Tree Structure

The `attrs` object uses a four-level hierarchy:

```
element → property-group → property → sub-property → breakpoint-map
```

Breakpoint map:
```jsonc
{
  "desktop": { "value": "<css-value-or-token>" },
  "tablet":  { "value": "..." },   // optional; absent if same as desktop
  "phone":   { "value": "..." }    // optional; absent if same as tablet
}
```

Hover state:
```jsonc
{
  "desktop": { "value": "...", "hover": "..." }
}
```

The leaf `value` (and `hover`) strings are either literal CSS values or variable
reference tokens as described in §3.

---

## 7. ABNF Grammar — Reference Tokens

The following ABNF (per RFC 5234) formally defines the Divi 5 serialization tokens.
ABNF is chosen over EBNF because it is unambiguous regarding string matching and
is widely used in RFC-style protocol specifications.

Character set: UTF-8. All string literals are case-sensitive unless noted.

```abnf
; ─────────────────────────────────────────────────────────────────────────────
; DIVI 5 SERIALIZATION — ABNF GRAMMAR
; RFC 5234 compliant. String literals are case-sensitive.
; ─────────────────────────────────────────────────────────────────────────────


; ── §A1: Variable Reference Token ────────────────────────────────────────────

; A variable reference token may appear anywhere a CSS property value appears.
; It replaces a literal CSS value with a pointer to a stored global variable.

variable-token          = "$variable(" variable-payload ")$"

; The payload is a JSON object. Quotes within it may be encoded three ways.
; All three forms are semantically identical.

variable-payload        = "{" ws
                            dq "type" dq ":" ws token-type "," ws
                            dq "value" dq ":" ws variable-value-obj
                          ws "}"

variable-value-obj      = "{" ws
                            dq "name" dq ":" ws dq variable-id dq
                            [ "," ws dq "settings" dq ":" ws settings-obj ]
                          ws "}"

token-type              = dq ( "color" / "content" ) dq

variable-id             = color-var-id / content-var-id / builtin-color-id / builtin-content-id

; Random-suffix IDs (generated at creation time)
color-var-id            = "gcid-" 1*( ALPHA / DIGIT )
content-var-id          = "gvid-" 1*( ALPHA / DIGIT )

; Divi built-in well-known IDs (static, always present on any Divi 5 site)
builtin-color-id        = "gcid-primary-color"
                        / "gcid-secondary-color"
                        / "gcid-heading-color"
                        / "gcid-body-color"
                        / "gcid-link-color"

builtin-content-id      = "gvid-r41n4b9xo4"   ; internal spacing/layout default

settings-obj            = "{" ws [ settings-members ] ws "}"
settings-members        = settings-pair *( "," ws settings-pair )
settings-pair           = dq settings-key dq ":" ws settings-value

; Currently documented settings key
settings-key            = "opacity" / unknown-key
settings-value          = opacity-value / json-value

; Opacity is an integer percentage 0–100
opacity-value           = "0" / ( %x31-39 *1DIGIT ) / "100"

; Unknown settings keys must be preserved (forward-compatible)
unknown-key             = 1*( ALPHA / DIGIT / "-" / "_" )
json-value              = json-string / json-number / json-object / json-array
                        / "true" / "false" / "null"


; ── §A2: Quote Encoding Variants ─────────────────────────────────────────────

; "dq" stands for "double-quote, in any of the three allowed encodings".
; A parser MUST accept all three forms and normalize them before processing.

dq                      = DQUOTE          ; Plain: "
                        / u0022-escape    ; Unicode escape (post_content context)
                        / backslash-quote ; JSON re-encode artefact

u0022-escape            = "\u0022"        ; 6-char literal: backslash u 0 0 2 2
backslash-quote         = "\""            ; 2-char literal: backslash double-quote

; Note: in a real PHP/regex context these are matched as literal byte sequences,
; not as JSON or URI escape codes. The backslash is a literal ASCII 0x5C.


; ── §A3: Element Preset Reference ────────────────────────────────────────────

; Appears inside block markup JSON attributes (post_content or export JSON).
; The value is always a single-element JSON array.

element-preset-ref      = DQUOTE "modulePreset" DQUOTE ":" "[" DQUOTE preset-id DQUOTE "]"

preset-id               = 1*( ALPHA / DIGIT )   ; 10-char alphanumeric in practice


; ── §A4: Group Preset Reference ──────────────────────────────────────────────

; Appears inside a "groupPreset" container in block markup JSON attributes.
; Multiple group preset references may share the same parent "groupPreset" object.
; The stable extraction target is the "presetId" key (container structure may evolve).

group-preset-ref        = DQUOTE "presetId" DQUOTE ":" "[" DQUOTE preset-id DQUOTE "]"

; Full container structure (informational — do not depend on nesting depth for parsing)
group-preset-container  = DQUOTE "groupPreset" DQUOTE ":"
                          "{" ws 1*( slot-name ":" ws slot-def [ "," ws ] ) ws "}"

slot-name               = DQUOTE 1*( ALPHA / DIGIT / "-" / "_" ) DQUOTE
slot-def                = "{" ws
                            DQUOTE "presetId" DQUOTE ":" ws
                              "[" DQUOTE preset-id DQUOTE "]" ","
                            ws DQUOTE "groupName" DQUOTE ":" ws DQUOTE group-type DQUOTE
                          ws "}"

group-type              = "divi/" 1*( ALPHA / DIGIT / "-" / "_" )


; ── §A5: Block Markup ─────────────────────────────────────────────────────────

; WordPress block editor format with Divi namespace.
; Only the structural elements relevant to DSO extraction are defined here.
;
; Divi 5 block markup appears in post_content of the following post types:
;   page              Standard WordPress pages
;   post              Standard WordPress blog posts
;   et_pb_layout      Divi Library saved layouts
;   et_template       Theme Builder template records (holds layout IDs, not block markup)
;   et_header_layout  Theme Builder header canvas (contains block markup)
;   et_body_layout    Theme Builder body canvas (contains block markup)
;   et_footer_layout  Theme Builder footer canvas (contains block markup)

content-source          = "page" / "post" / "et_pb_layout"
                        / "et_header_layout" / "et_body_layout" / "et_footer_layout"
                        ; "et_template" records contain layout IDs in post meta,
                        ; not block markup — scan the canvas post types instead.

block-doc               = *block
block                   = leaf-block / container-block

leaf-block              = "<!-- wp:" block-name ws block-attrs ws "/-->"
container-block         = "<!-- wp:" block-name ws block-attrs ws "-->"
                          *block
                          "<!-- /wp:" block-name ws "-->"

block-name              = "divi/" module-name
module-name             = 1*( ALPHA / DIGIT / "-" )

block-attrs             = "{" *( VCHAR / WSP ) "}"   ; JSON object; see §A3 and §A4 for DSO patterns


; ── §A6: Variable and Color ID Namespaces ────────────────────────────────────

; Used for quick type discrimination without full payload decoding.

var-id                  = color-var-id / content-var-id
                        / builtin-color-id / builtin-content-id

; Summary:
;   Prefix "gcid-"  → Global Color variable
;   Prefix "gvid-"  → Global Variable (non-color: numbers, fonts, images, strings, links)
;   No prefix       → Preset ID (context-dependent: always in modulePreset / presetId arrays)


; ── §A7: Core Terminals ──────────────────────────────────────────────────────

ws                      = *( SP / HTAB )
ALPHA                   = %x41-5A / %x61-7A          ; A-Z / a-z
DIGIT                   = %x30-39                    ; 0-9
DQUOTE                  = %x22                       ; "
SP                      = %x20
HTAB                    = %x09
VCHAR                   = %x21-7E                    ; visible ASCII
WSP                     = SP / HTAB

; JSON terminals (abbreviated; full JSON grammar per RFC 8259)
json-string             = DQUOTE *json-char DQUOTE
json-char               = %x20-21 / %x23-5B / %x5D-10FFFF / json-escape
json-escape             = "\" ( DQUOTE / "\" / "/" / "b" / "f" / "n" / "r" / "t" / unicode-escape )
unicode-escape          = "u" 4HEXDIG
HEXDIG                  = DIGIT / "A" / "B" / "C" / "D" / "E" / "F"
                        / "a" / "b" / "c" / "d" / "e" / "f"
json-number             = [ "-" ] integer [ frac ] [ exp ]
integer                 = "0" / ( %x31-39 *DIGIT )
frac                    = "." 1*DIGIT
exp                     = ( "e" / "E" ) [ "+" / "-" ] 1*DIGIT
json-object             = "{" ws [ json-members ] ws "}"
json-members            = json-pair *( "," ws json-pair )
json-pair               = json-string ":" ws json-value
json-array              = "[" ws [ json-value *( "," ws json-value ) ] ws "]"
```

---

## 8. ABNF Grammar — DSO Storage Structures

This section defines the on-disk (WordPress database) shape of Design System Objects.
The rules below describe what is _stored_, not what is _referenced_. Reference token
grammar is in §7 (§A1–§A4).

All structures are stored in `wp_options` via PHP's `serialize()`/`unserialize()`. The
ABNF below describes the logical JSON-equivalent shape after deserialization; field
names and types match the actual PHP arrays written and read by the Repository classes.

Character set: UTF-8. All string literals are case-sensitive.

```abnf
; ─────────────────────────────────────────────────────────────────────────────
; DIVI 5 DSO STORAGE — ABNF GRAMMAR
; Describes the deserialized PHP-array structure of each DSO option row.
; RFC 5234 compliant. String literals are case-sensitive.
; ─────────────────────────────────────────────────────────────────────────────


; ── §B1: Non-Color Variables (et_divi_global_variables) ──────────────────────

; wp_options key: "et_divi_global_variables"
; Top-level: associative array keyed by variable type.

vars-option             = "{" ws
                            [ vars-type-map *( "," ws vars-type-map ) ]
                          ws "}"

vars-type-map           = vars-type-key ":" ws vars-type-dict

vars-type-key           = DQUOTE ( "numbers" / "fonts" / "images"
                                  / "strings" / "links" ) DQUOTE

; Each type dict is keyed by variable ID (gvid-*).
vars-type-dict          = "{" ws
                            [ vars-entry *( "," ws vars-entry ) ]
                          ws "}"

vars-entry              = DQUOTE content-var-id DQUOTE ":" ws vars-record

vars-record             = "{" ws
                            DQUOTE "id"     DQUOTE ":" ws DQUOTE content-var-id DQUOTE "," ws
                            DQUOTE "label"  DQUOTE ":" ws json-string                   "," ws
                            DQUOTE "value"  DQUOTE ":" ws json-string                   "," ws
                            DQUOTE "status" DQUOTE ":" ws var-status
                          ws "}"

; "links" type entries use the same vars-record shape.
; "images" type entries use the same vars-record shape; value is a URL or base64 data URI.
; "fonts" type entries use the same vars-record shape; value is a font-family CSS string.
; "numbers" type entries use the same vars-record shape; value is a CSS dimension or bare number.

var-status              = DQUOTE ( "active" / "archived" / "inactive" ) DQUOTE


; ── §B2: User Color Variables (et_divi[et_global_data][global_colors]) ───────

; wp_options key: "et_divi" — a large flat options array.
; The global_colors dict lives at: et_divi["et_global_data"]["global_colors"]
; Top-level path: et_divi → et_global_data → global_colors

colors-option-path      = DQUOTE "et_global_data" DQUOTE ":" ws et-global-data-obj

et-global-data-obj      = "{" ws
                            DQUOTE "global_colors" DQUOTE ":" ws colors-dict
                            [ "," ws json-members ]          ; other et_global_data keys
                          ws "}"

colors-dict             = "{" ws
                            [ color-entry *( "," ws color-entry ) ]
                          ws "}"

color-entry             = DQUOTE color-var-id DQUOTE ":" ws color-record

color-record            = "{" ws
                            DQUOTE "id"          DQUOTE ":" ws DQUOTE color-var-id DQUOTE "," ws
                            DQUOTE "label"       DQUOTE ":" ws json-string                   "," ws
                            DQUOTE "color"       DQUOTE ":" ws color-value                   "," ws
                            DQUOTE "order"       DQUOTE ":" ws json-number                   "," ws
                            DQUOTE "status"      DQUOTE ":" ws var-status
                            [ "," ws DQUOTE "lastUpdated"  DQUOTE ":" ws json-string ]
                            [ "," ws DQUOTE "folder"       DQUOTE ":" ws json-string ]
                            [ "," ws DQUOTE "usedInPosts"  DQUOTE ":" ws json-array  ]
                          ws "}"

; "color" field: hex literal or a variable reference token (§A1)
color-value             = DQUOTE ( hex-color / variable-token-str ) DQUOTE

hex-color               = "#" 3*8HEXDIG
variable-token-str      = "$variable(" *( %x20-28 / %x2A-10FFFF ) ")$"
                          ; excludes ")" (0x29) inside the payload; encoding per §A2


; ── §B3: System Color Variables (et_divi top-level keys) ─────────────────────

; wp_options key: "et_divi"
; System colors are plain hex strings at the top level of the et_divi array.
; They are NOT stored in global_colors. IDs and labels are fixed (cannot be changed).

; Known system color keys and their synthesized DSO IDs:
;   "accent_color"           → gcid-primary-color
;   "secondary_accent_color" → gcid-secondary-color
;   "header_color"           → gcid-heading-color
;   "font_color"             → gcid-body-color
;   "link_color"             → gcid-link-color

system-color-key        = "accent_color" / "secondary_accent_color"
                        / "header_color" / "font_color" / "link_color"

system-color-entry      = DQUOTE system-color-key DQUOTE ":" ws DQUOTE hex-color DQUOTE


; ── §B4: System Font Variables (et_divi top-level keys) ──────────────────────

; wp_options key: "et_divi"
; System fonts are plain font-family strings at the top level of the et_divi array.
; IDs and labels are fixed. Only the font-family value is editable.

; Known system font keys and their synthesized DSO IDs:
;   "heading_font" → --et_global_heading_font
;   "body_font"    → --et_global_body_font

system-font-key         = "heading_font" / "body_font"

system-font-entry       = DQUOTE system-font-key DQUOTE ":" ws DQUOTE font-family DQUOTE

font-family             = 1*( %x20-21 / %x23-10FFFF )  ; any string; typically CSS font-family


; ── §B5: Element Presets and Group Presets (et_divi_builder_global_presets_d5) ─

; wp_options key: "et_divi_builder_global_presets_d5"
; Top-level: two keys: "module" (Element Presets) and "group" (Group Presets).
; Both have the same structural shape; they differ only in the fields inside
; each preset-record (see §B5.2 and §B5.3).

presets-option          = "{" ws
                            DQUOTE "module" DQUOTE ":" ws module-presets-dict "," ws
                            DQUOTE "group"  DQUOTE ":" ws group-presets-dict
                          ws "}"

; ── §B5.1: Per-Module Container ───────────────────────────────────────────────

; Both module and group containers share this shape.
; The outer key is the Divi module/group identifier (e.g. "divi/button").

module-presets-dict     = "{" ws
                            [ module-preset-container *( "," ws module-preset-container ) ]
                          ws "}"

group-presets-dict      = "{" ws
                            [ group-preset-container *( "," ws group-preset-container ) ]
                          ws "}"

module-preset-container = DQUOTE divi-module-id DQUOTE ":" ws preset-bucket
group-preset-container  = DQUOTE divi-group-id  DQUOTE ":" ws preset-bucket

divi-module-id          = "divi/" module-name       ; e.g. "divi/button"
divi-group-id           = "divi/" group-type-name   ; e.g. "divi/background"

module-name             = 1*( ALPHA / DIGIT / "-" )
group-type-name         = 1*( ALPHA / DIGIT / "-" )

preset-bucket           = "{" ws
                            DQUOTE "default" DQUOTE ":" ws DQUOTE preset-id DQUOTE "," ws
                            DQUOTE "items"   DQUOTE ":" ws items-dict
                          ws "}"

items-dict              = "{" ws
                            [ preset-item *( "," ws preset-item ) ]
                          ws "}"

preset-item             = DQUOTE preset-id DQUOTE ":" ws ( element-preset-record / group-preset-record )

; ── §B5.2: Element Preset Record ─────────────────────────────────────────────

element-preset-record   = "{" ws
                            DQUOTE "id"         DQUOTE ":" ws DQUOTE preset-id        DQUOTE "," ws
                            DQUOTE "name"       DQUOTE ":" ws json-string                         "," ws
                            DQUOTE "moduleName" DQUOTE ":" ws DQUOTE divi-module-id   DQUOTE "," ws
                            DQUOTE "version"    DQUOTE ":" ws json-string                         "," ws
                            DQUOTE "type"       DQUOTE ":" ws DQUOTE "module"         DQUOTE "," ws
                            DQUOTE "created"    DQUOTE ":" ws unix-ms                             "," ws
                            DQUOTE "updated"    DQUOTE ":" ws unix-ms
                            [ "," ws DQUOTE "attrs"       DQUOTE ":" ws attrs-obj ]
                            [ "," ws DQUOTE "styleAttrs"  DQUOTE ":" ws attrs-obj ]
                            [ "," ws DQUOTE "renderAttrs" DQUOTE ":" ws attrs-obj ]
                          ws "}"

; ── §B5.3: Group Preset Record ────────────────────────────────────────────────

group-preset-record     = "{" ws
                            DQUOTE "id"        DQUOTE ":" ws DQUOTE preset-id       DQUOTE "," ws
                            DQUOTE "name"      DQUOTE ":" ws json-string                        "," ws
                            DQUOTE "groupName" DQUOTE ":" ws DQUOTE divi-group-id   DQUOTE "," ws
                            DQUOTE "groupId"   DQUOTE ":" ws DQUOTE preset-id       DQUOTE "," ws
                            DQUOTE "version"   DQUOTE ":" ws json-string                        "," ws
                            DQUOTE "type"      DQUOTE ":" ws DQUOTE "group"         DQUOTE "," ws
                            DQUOTE "created"   DQUOTE ":" ws unix-ms                            "," ws
                            DQUOTE "updated"   DQUOTE ":" ws unix-ms
                            [ "," ws DQUOTE "attrs"       DQUOTE ":" ws attrs-obj ]
                            [ "," ws DQUOTE "styleAttrs"  DQUOTE ":" ws attrs-obj ]
                            [ "," ws DQUOTE "renderAttrs" DQUOTE ":" ws attrs-obj ]
                          ws "}"

; ── §B5.4: Attrs Object (shared by element and group presets) ─────────────────

; The attrs object is a 4-level deep hierarchy:
;   element → property-group → property → sub-property → breakpoint-map
; Leaf values are either literal CSS strings or variable-token-str (§B2 / §A1).

attrs-obj               = json-object   ; deep nested; leaf values contain variable tokens

; Breakpoint map (leaf of attrs hierarchy)
breakpoint-map          = "{" ws
                            DQUOTE "desktop" DQUOTE ":" ws breakpoint-entry
                            [ "," ws DQUOTE "tablet" DQUOTE ":" ws breakpoint-entry ]
                            [ "," ws DQUOTE "phone"  DQUOTE ":" ws breakpoint-entry ]
                          ws "}"

breakpoint-entry        = "{" ws
                            DQUOTE "value" DQUOTE ":" ws DQUOTE css-or-token DQUOTE
                            [ "," ws DQUOTE "hover" DQUOTE ":" ws DQUOTE css-or-token DQUOTE ]
                          ws "}"

css-or-token            = *( %x20-10FFFF )   ; literal CSS value or variable-token-str


; ── §B6: Theme Customizer (theme_mods_Divi) ──────────────────────────────────

; wp_options key: "theme_mods_Divi"
; Flat associative array of Divi Customizer settings.
; The full set of keys is open-ended; only DSO-relevant keys are documented here.
; Values are plain strings (colors, font names, sizes) or booleans.
; No DSO reference tokens appear here — values are always literal.

theme-mods-option       = "{" ws
                            [ theme-mods-pair *( "," ws theme-mods-pair ) ]
                          ws "}"

theme-mods-pair         = DQUOTE theme-mods-key DQUOTE ":" ws theme-mods-value

theme-mods-key          = 1*( ALPHA / DIGIT / "_" / "-" )  ; open-ended key namespace

theme-mods-value        = json-string / json-number / "true" / "false" / "null"


; ── §B7: Builder Template Post Record (et_template in wp_posts) ──────────────

; Builder templates are stored as WordPress posts, not as wp_options entries.
; The template record itself (post_type = "et_template") stores layout IDs in
; post meta — it does NOT contain block markup in post_content.
; Block markup lives in the three canvas posts (et_header_layout, et_body_layout,
; et_footer_layout) whose IDs are stored as meta on the template.

template-post-meta      = "{" ws
                            [ template-meta-pair *( "," ws template-meta-pair ) ]
                          ws "}"

template-meta-pair      = DQUOTE template-meta-key DQUOTE ":" ws template-meta-value

; Known post meta keys on et_template records
template-meta-key       = "_et_header_layout_id"    ; int post ID of et_header_layout canvas
                        / "_et_body_layout_id"       ; int post ID of et_body_layout canvas
                        / "_et_footer_layout_id"     ; int post ID of et_footer_layout canvas
                        / "_et_use_on"               ; serialized array of condition strings
                        / "_et_exclude_from"         ; serialized array of condition strings
                        / "_et_default"              ; "1" if this is the default template
                        / "_et_enabled"              ; "1" if the template is active
                        / 1*( ALPHA / DIGIT / "_" / "-" )  ; any other meta key

template-meta-value     = json-string / json-number / "true" / "false" / serialized-php

; PHP serialization is used for "_et_use_on" and "_et_exclude_from" arrays.
; This grammar does not describe the PHP serialization format; treat as opaque string.
serialized-php          = json-string   ; stored as a raw PHP-serialized string in the DB


; ── §B8: Divi Library Layout Post Record (et_pb_layout in wp_posts) ──────────

; Layouts saved in the Divi Library are stored as et_pb_layout posts.
; post_content contains Divi 5 block markup (§A5) and may contain DSO references.

layout-post             = "{" ws
                            DQUOTE "post_type"    DQUOTE ":" ws DQUOTE "et_pb_layout" DQUOTE "," ws
                            DQUOTE "post_title"   DQUOTE ":" ws json-string                        "," ws
                            DQUOTE "post_status"  DQUOTE ":" ws post-status                        "," ws
                            DQUOTE "post_content" DQUOTE ":" ws DQUOTE block-doc DQUOTE
                            [ "," ws DQUOTE "is_global" DQUOTE ":" ws ( "true" / "false" ) ]
                          ws "}"

post-status             = DQUOTE ( "publish" / "draft" / "pending"
                                  / "private" / "future" / "trash" ) DQUOTE


; ── §B9: Terminals and ID Formats ────────────────────────────────────────────

; Variable ID formats (repeated here for cross-reference)
color-var-id            = "gcid-" 1*( ALPHA / DIGIT )         ; user-created color
content-var-id          = "gvid-" 1*( ALPHA / DIGIT )         ; non-color variable

; System variable IDs (fixed, always present on any Divi 5 site)
builtin-color-id        = "gcid-primary-color" / "gcid-secondary-color"
                        / "gcid-heading-color" / "gcid-body-color"
                        / "gcid-link-color"

builtin-font-id         = "--et_global_heading_font" / "--et_global_body_font"

; Preset ID format
preset-id               = 1*( ALPHA / DIGIT )   ; 10-char lowercase alphanumeric in practice

; Unix timestamp in milliseconds
unix-ms                 = 1*DIGIT

; Core terminals (RFC 5234)
ws                      = *( SP / HTAB )
ALPHA                   = %x41-5A / %x61-7A
DIGIT                   = %x30-39
HEXDIG                  = DIGIT / "A" / "B" / "C" / "D" / "E" / "F"
                        / "a" / "b" / "c" / "d" / "e" / "f"
DQUOTE                  = %x22
SP                      = %x20
HTAB                    = %x09
VCHAR                   = %x21-7E
WSP                     = SP / HTAB
json-string             = DQUOTE *json-char DQUOTE
json-char               = %x20-21 / %x23-5B / %x5D-10FFFF / json-escape
json-escape             = "\" ( DQUOTE / "\" / "/" / "b" / "f" / "n" / "r" / "t" / unicode-escape )
unicode-escape          = "u" 4HEXDIG
json-number             = [ "-" ] int-part [ frac ] [ exp ]
int-part                = "0" / ( %x31-39 *DIGIT )
frac                    = "." 1*DIGIT
exp                     = ( "e" / "E" ) [ "+" / "-" ] 1*DIGIT
json-object             = "{" ws [ json-members ] ws "}"
json-members            = json-pair *( "," ws json-pair )
json-pair               = json-string ":" ws json-value
json-array              = "[" ws [ json-value *( "," ws json-value ) ] ws "]"
json-value              = json-string / json-number / json-object / json-array
                        / "true" / "false" / "null"
```

### 8.1 Storage Summary Table

| DSO type | wp_options key | Top-level structure | §B rule |
|---|---|---|---|
| Non-color variables (numbers, fonts, images, strings, links) | `et_divi_global_variables` | `{ type → { id → entry } }` | §B1 |
| User color variables | `et_divi` → `[et_global_data][global_colors]` | `{ gcid-xxx → color-record }` | §B2 |
| System color variables | `et_divi` top-level keys | plain hex string per key | §B3 |
| System font variables | `et_divi` top-level keys | plain font-family string per key | §B4 |
| Element Presets | `et_divi_builder_global_presets_d5` → `[module]` | `{ divi/module → { default, items } }` | §B5.2 |
| Group Presets (Option Group Presets) | `et_divi_builder_global_presets_d5` → `[group]` | `{ divi/group → { default, items } }` | §B5.3 |
| Theme Customizer settings | `theme_mods_Divi` | flat key → value map | §B6 |
| Builder Templates | `wp_posts` (post_type `et_template`) | post + meta (layout IDs) | §B7 |
| Builder Template canvas | `wp_posts` (`et_header_layout`, `et_body_layout`, `et_footer_layout`) | post_content = block markup | §A5 |
| Divi Library layouts | `wp_posts` (post_type `et_pb_layout`) | post_content = block markup | §B8 |
| Pages / Posts | `wp_posts` (post_type `page` / `post`) | post_content = block markup | §A5 |

---

## 9. ABNF Grammar — Plugin Storage (D5 Design System Helper)

This section defines the WordPress option keys and transients owned by the D5 Design
System Helper plugin itself. These are **not** part of Divi's serialization — they are
plugin-internal storage. They are documented here so that third-party tools, migration
scripts, and uninstall routines know exactly what the plugin writes to the database.

All plugin-owned option keys use the `d5dsh_` prefix. All are stored with
`autoload = false` to avoid loading into memory on every WordPress page load.

```abnf
; ─────────────────────────────────────────────────────────────────────────────
; D5 DESIGN SYSTEM HELPER — PLUGIN STORAGE ABNF GRAMMAR
; Describes the plugin's own wp_options entries and transients.
; RFC 5234 compliant. String literals are case-sensitive.
; ─────────────────────────────────────────────────────────────────────────────


; ── §C1: Plugin Settings (d5dsh_settings) ────────────────────────────────────

; wp_options key: "d5dsh_settings"
; Stores all user-configurable plugin settings as a flat associative array.
; Written by the Settings modal; read on plugin init and throughout admin pages.

settings-option         = "{" ws
                            [ DQUOTE "debug_mode"    DQUOTE ":" ws bool-value ]
                            [ "," ws DQUOTE "beta_preview"  DQUOTE ":" ws bool-value ]
                            [ "," ws DQUOTE "report_header" DQUOTE ":" ws json-string ]
                            [ "," ws DQUOTE "report_footer" DQUOTE ":" ws json-string ]
                            [ "," ws DQUOTE "site_abbr"     DQUOTE ":" ws json-string ]
                            [ "," ws DQUOTE "row_banding"   DQUOTE ":" ws bool-value ]
                          ws "}"

bool-value              = "true" / "false" / DQUOTE "1" DQUOTE / DQUOTE "0" DQUOTE
                        / DQUOTE "" DQUOTE


; ── §C2: Categories (d5dsh_var_categories + d5dsh_var_category_map) ──────────

; wp_options key: "d5dsh_var_categories"
; Array of user-defined category objects.

categories-option       = "[" ws
                            [ category-obj *( "," ws category-obj ) ]
                          ws "]"

category-obj            = "{" ws
                            DQUOTE "id"      DQUOTE ":" ws DQUOTE category-id DQUOTE "," ws
                            DQUOTE "label"   DQUOTE ":" ws json-string                   "," ws
                            DQUOTE "color"   DQUOTE ":" ws DQUOTE hex-color  DQUOTE
                            [ "," ws DQUOTE "comment" DQUOTE ":" ws json-string ]
                          ws "}"

category-id             = "cat-" 1*( ALPHA / DIGIT / "-" )
                          ; UUID-based: "cat-" followed by a wp_generate_uuid4() string

; wp_options key: "d5dsh_var_category_map"
; Maps DSO identifiers to arrays of category IDs.
; Key format uses a type prefix: "var:", "gp:", or "ep:".

category-map-option     = "{" ws
                            [ category-assignment *( "," ws category-assignment ) ]
                          ws "}"

category-assignment     = DQUOTE dso-map-key DQUOTE ":" ws
                          "[" ws category-id *( "," ws category-id ) ws "]"

dso-map-key             = dso-map-prefix dso-id
dso-map-prefix          = "var:" / "gp:" / "ep:"
dso-id                  = 1*( ALPHA / DIGIT / "-" / "_" )
                          ; variable IDs (gcid-*, gvid-*) or preset IDs

; Legacy format (transparently migrated on first read):
;   { "gcid-xxx": "cat-id" }  →  { "var:gcid-xxx": ["cat-id"] }


; ── §C3: Notes (d5dsh_notes) ─────────────────────────────────────────────────

; wp_options key: "d5dsh_notes"
; Persistent notes, tags, and audit-check suppression per entity.

notes-option            = "{" ws
                            [ notes-entry *( "," ws notes-entry ) ]
                          ws "}"

notes-entry             = DQUOTE notes-key DQUOTE ":" ws notes-value

notes-key               = notes-key-prefix notes-key-id
notes-key-prefix        = "var:" / "preset:" / "post:" / "check:"
notes-key-id            = 1*( ALPHA / DIGIT / "-" / "_" )

notes-value             = "{" ws
                            DQUOTE "note"     DQUOTE ":" ws json-string   "," ws
                            DQUOTE "tags"     DQUOTE ":" ws json-array    "," ws
                            DQUOTE "suppress" DQUOTE ":" ws json-array
                          ws "}"


; ── §C4: Snapshots (d5dsh_snap_{type}_meta + d5dsh_snap_{type}_{index}) ──────

; Snapshot system: up to 10 automatic backups per data type.
; Each type has one meta key and up to 10 data keys.

; Meta key pattern: d5dsh_snap_{type}_meta
; Data key pattern: d5dsh_snap_{type}_{index}

snapshot-type           = "vars" / "presets" / "layouts"
                        / "pages" / "theme_customizer" / "builder_templates"

; Meta: array of snapshot descriptors, newest first (index 0 = newest).
snapshot-meta-option    = "[" ws
                            [ snapshot-descriptor *( "," ws snapshot-descriptor ) ]
                          ws "]"

snapshot-descriptor     = "{" ws
                            DQUOTE "timestamp"   DQUOTE ":" ws json-number "," ws
                            DQUOTE "trigger"     DQUOTE ":" ws snapshot-trigger "," ws
                            DQUOTE "entry_count" DQUOTE ":" ws json-number "," ws
                            DQUOTE "description" DQUOTE ":" ws json-string
                          ws "}"

snapshot-trigger        = DQUOTE ( "export" / "import" / "edit" / "merge" / "restore" ) DQUOTE

; Data key: stores the raw DB data (serialized PHP array) for the snapshot.
; Index 0–9; index 0 is the newest snapshot.
snapshot-index          = DIGIT   ; 0–9

; Full key construction:
;   meta key: "d5dsh_snap_" snapshot-type "_meta"
;   data key: "d5dsh_snap_" snapshot-type "_" snapshot-index


; ── §C5: Legacy Backup Keys (d5dsh_backup_{type}_{timestamp}) ────────────────

; Legacy backup system (predates snapshots). Keys follow a timestamped pattern.
; These keys may still exist in older installations. They are not created by
; current code but are not cleaned up automatically either.

backup-key-prefix       = "d5dsh_backup_vars_"
                        / "d5dsh_backup_presets_"
                        / "d5dsh_backup_customizer_"
                        / "d5dsh_backup_layouts_"
                        / "d5dsh_backup_builder_"

; Full key: backup-key-prefix YYYYMMDD "_" HHMMSS
; Example:  "d5dsh_backup_vars_20260315_143022"


; ── §C6: Transients (temporary session data) ─────────────────────────────────

; Import session (per-user). TTL: 10 minutes.
; Key: "d5dsh_si_{user_id}"
; Stores: { type, format, tmp_path, display_name }
import-session-key      = "d5dsh_si_" 1*DIGIT

; Help content cache (per-version). TTL: 7 days.
; Key: "d5dsh_help_html_{version}" and "d5dsh_help_idx_{version}"
; Stores: parsed HTML of PLUGIN_USER_GUIDE.md and Fuse.js search index.
help-cache-key          = "d5dsh_help_html_" version-string
                        / "d5dsh_help_idx_" version-string
version-string          = 1*( DIGIT / "." )

; Import result (per-user). TTL: 5 minutes.
; Key: "d5dsh_import_result_{user_id}"
; Stores: { updated, skipped, new }
import-result-key       = "d5dsh_import_result_" 1*DIGIT

; Dry-run diff (per-user). TTL: 10 minutes.
; Key: "d5dsh_dry_run_result_{user_id}"
; Stores: changes array from import preview.
dry-run-result-key      = "d5dsh_dry_run_result_" 1*DIGIT


; ── §C7: Plugin Storage Terminals ────────────────────────────────────────────

; Reuses terminals from §A7 / §B9.
; hex-color, json-string, json-number, json-array, json-object, ALPHA, DIGIT,
; DQUOTE, ws — all as previously defined.
```

### 9.1 Plugin Storage Summary Table

| Key / Pattern | Type | What it stores | Autoload |
|---|---|---|---|
| `d5dsh_settings` | `wp_options` | Plugin settings (debug, beta, report header/footer, site abbreviation, row banding) | no |
| `d5dsh_var_categories` | `wp_options` | Category definitions: `[{ id, label, color, comment }]` | no |
| `d5dsh_var_category_map` | `wp_options` | DSO → category assignments: `{ "var:id": ["cat-id"], "gp:id": [...] }` | no |
| `d5dsh_notes` | `wp_options` | Per-entity notes, tags, and audit suppression flags | no |
| `d5dsh_snap_{type}_meta` | `wp_options` | Snapshot metadata array (up to 10 entries per type) | no |
| `d5dsh_snap_{type}_{0-9}` | `wp_options` | Raw snapshot data (serialized DB state) | no |
| `d5dsh_backup_{type}_{timestamp}` | `wp_options` | Legacy backup data (pre-snapshot system) | no |
| `d5dsh_si_{user_id}` | transient | Import session state (10 min TTL) | — |
| `d5dsh_help_html_{version}` | transient | Parsed help panel HTML (7 day TTL) | — |
| `d5dsh_help_idx_{version}` | transient | Help panel search index (7 day TTL) | — |
| `d5dsh_import_result_{user_id}` | transient | Import result summary (5 min TTL) | — |
| `d5dsh_dry_run_result_{user_id}` | transient | Dry-run diff preview (10 min TTL) | — |

### 9.2 Uninstall Behavior

When the plugin is deleted via WordPress Admin (Plugins → Delete), all `d5dsh_*` option
keys and transients listed above are removed. No Divi data (`et_divi_*`,
`theme_mods_Divi`, `et_pb_layout` posts, etc.) is touched during uninstall.

---

## 10. What Requires Only a Constant Update vs. a Code Rewrite

### 10.1 Constant-Update Changes (DiviBlocParser.php only)

These changes require editing only the pattern constants at the top of
`DiviBlocParser.php`. No logic changes needed elsewhere.

| Change | Which constant | What to do |
|---|---|---|
| Token wrapper changes (`$variable(` → e.g. `$$var(`) | `VARIABLE_TOKEN_PATTERN`, `VARIABLE_STRIP_PATTERN`, `VARIABLE_TOKEN_PATTERN_NONGREEDY` | Update the literal prefix/suffix strings in the patterns |
| Element preset key renames (`modulePreset` → e.g. `elementPreset`) | `ELEMENT_PRESET_PATTERN` | Update the literal key string in the pattern |
| Group preset key renames (`presetId` → e.g. `groupPresetId`) | `GROUP_PRESET_PATTERN` | Update the literal key string in the pattern |
| Token delimiters change but structure is preserved | All three variable patterns | Update delimiter literals |

### 10.2 Logic-Update Changes (DiviBlocParser methods only)

These changes require updating method bodies inside `DiviBlocParser.php` but no
changes to callers (`AuditEngine`, `SimpleImporter`, `VarsExporter`, etc.).

| Change | Which method | What to do |
|---|---|---|
| Payload JSON structure changes (e.g., `value.name` moves to `value.id`) | `decode_variable_payload()` | Update the key path extraction |
| Additional quote encoding variant introduced | `decode_variable_payload()` | Add to the `str_replace()` normalization array |
| `settings` object gains semantically-important new keys | `decode_variable_payload()` | Add extraction of the new key to the returned array |
| Token type values change (`"color"` → `"globalColor"`) | `decode_variable_payload()` | Update any code that reads the `type` field from refs |
| ID prefix changes (`gcid-` → something else) | `extract_variable_refs()` | No regex change needed (pattern matches any name), but downstream code using prefix tests must update |
| Preset reference changes to non-array form (`["id"]` → `"id"`) | `extract_preset_refs()` | Update patterns to match new syntax |

### 10.3 Caller Changes Required (multiple files)

These changes affect the data shape returned by DiviBlocParser, requiring updates
in `AuditEngine.php`, `SimpleImporter.php`, `VarsExporter.php`, or other callers.

| Change | Impact |
|---|---|
| `extract_variable_refs()` return shape changes (e.g., adds a third field) | All callers that destructure `['type', 'name']` |
| `extract_preset_refs()` returns objects instead of strings | All callers iterating the returned array |
| `preset_attrs_to_string()` needs to scan additional preset fields (e.g., a new `themeAttrs` key) | Update method; callers unchanged (they just get a longer string) |

### 10.4 Full Rewrite Required

These changes are so fundamental that DiviBlocParser's design itself must change.

| Scenario | Why rewrite is needed |
|---|---|
| Divi switches to a non-regex-parseable format (e.g., binary encoding, protobuf) | Regex patterns cannot handle binary or length-prefixed formats |
| Token appears in structured XML/HTML with significant nesting (`<variable id="..." />`) | A streaming or DOM parser would be more appropriate than regex |
| Variable references use a token ID instead of a human-readable variable ID (e.g., a UUID lookup table) | The parser would need to call a lookup service, not just extract a string |
| Divi 5 is replaced by Divi 6 with a completely new data model | New option keys, new structures — full rewrite of all Repository and Parser classes |

---

## 11. Adding Alternative Serialization Formats

`DiviBlocParser` is designed to support multiple serialization strategies. If Divi
introduces a second way to express variable references (e.g., an HTML element syntax
alongside the existing `$variable(...)$` token), the parser can try each strategy in
sequence and merge the results.

See `DiviBlocParser.php` for the `$strategies` array and the `extract_variable_refs()`
multi-strategy dispatch. Each strategy is a callable with the signature:

```php
function(string $raw): array   // returns [ ['type' => string, 'name' => string], ... ]
```

To add a new format:
1. Add a new `const` for the new pattern.
2. Implement a private static `extract_variable_refs_<format>(string $raw): array` method.
3. Register the method in `VARIABLE_REF_STRATEGIES`.

Results from all strategies are merged and deduplicated on `name` before being returned
to callers.

---

## 12. Known Limitations and Open Questions

| Issue | Impact | Status |
|---|---|---|
| `renderAttrs` relationship to `attrs` is not fully documented by Elegant Themes | May miss variable refs if only `renderAttrs` is available | Mitigated: plugin always scans `attrs` + `styleAttrs`; `renderAttrs` is supplementary |
| Group preset slot names (`contentBackground`, etc.) are not enumerated by Divi | Cannot validate slot names; unknown slots are silently scanned | Low risk: slot name is not needed for dependency extraction |
| `settings.opacity` behavior for non-color variables is undocumented | Unknown if opacity applies to fonts/numbers/etc. | Low risk: opacity is only meaningful for colors |
| Whether preset `version` field affects parsing | Unknown; assumed informational only | No evidence of format-by-version switching observed |
| Maximum depth of variable-to-variable reference chains | Theoretical: unlimited; practical: shallow (1–2 hops) | Plugin resolves up to 10 hops as a safety limit |

---

## 13. Content Scanner — Scope, Limitations, and Assumptions

The `ContentScanner` class (`includes/Admin/ContentScanner.php`) uses `DiviBlocParser`
to scan live site content for DSO references. This section documents its scan scope.

### 13.1 What the Scanner Covers

| Post Type | Description |
|---|---|
| `page` | Standard WordPress pages |
| `post` | Standard WordPress blog posts |
| `et_pb_layout` | Divi Library saved layouts |
| `et_template` | Theme Builder templates (expanded with canvas children) |
| `et_header_layout` | Theme Builder header canvas |
| `et_body_layout` | Theme Builder body canvas |
| `et_footer_layout` | Theme Builder footer canvas |

All six post statuses are included: `publish`, `draft`, `pending`, `private`,
`future`, `trash`. Up to **1,000 posts** are loaded per scan (configurable via
`ContentScanner::CONTENT_LIMIT`).

### 13.2 Known Gaps

| Gap | Detail |
|---|---|
| Custom post types (CPTs) | Only the seven post types listed above are queried. A CPT built with Divi will be invisible. Future Pro version: auto-detect CPTs using `et_builder`, run separate segmented scans. |
| ACF / meta fields | Only `post_content` is scanned. Third-party plugins that store Divi block markup in custom meta fields will not be found. |
| Divi 4 / Classic Builder content | The `$variable(...)$` token did not exist in Divi 4. Legacy blocks produce zero DSO hits, which is correct — they are not part of the design system. |
| Preset → variable links | Variable references inside preset `attrs` are not shown in the ContentScanner content rows. Those links are reported separately by `AuditEngine`. |
| Performance ceiling | Sites with more than `CONTENT_LIMIT` posts will be partially scanned. The `meta.limit_reached` flag in the report indicates this condition. |
| Trashed template canvases | Canvas posts whose parent template is in trash may appear independently in the inventory rather than nested. |

### 13.3 Assumptions

- `post_content` is the authoritative source for DSO references in Divi 5 page/post content.
- The `$variable(...)$` token format and preset key format are as described in §3 and §4 of this specification.
- Canvas posts (`et_header_layout` etc.) are associated to their parent template via
  `_et_header_layout_id` / `_et_body_layout_id` / `_et_footer_layout_id` post meta on the template record.
- Third-party Divi 5-compliant modules that use the standard token API are transparent — the scanner has no namespace, author, or origin filtering.

---

## 14. Revision History

| Version | Date | Changes |
|---|---|---|
| 1.0 | 2026-03-20 | Initial specification extracted from codebase; ABNF grammar added |
| 1.1 | 2026-03-20 | Added §13 ContentScanner scope, limitations, and assumptions |
| 1.2 | 2026-03-23 | Added §8 DSO Storage Structures ABNF (§B1–§B9); updated §A5 to enumerate all known content post types with new `content-source` rule; renumbered old §8–§12 to §9–§13 |
| 1.3 | 2026-03-26 | Added §9 Plugin Storage ABNF (§C1–§C7) documenting all `d5dsh_*` option keys and transients; verified ABNF against current `DiviBlocParser.php` patterns; added `VCHAR` and `WSP` terminals to §A7; renumbered old §9–§13 to §10–§14; moved from `archive/` to `docs/` as public-facing reference |
| 1.4 | 2026-03-30 | Added §15 (Divi native export JSON format), §16 (D5DSH plugin export JSON format), §17 (DTCG export JSON format); all three confirmed against live exporter code and real Divi 5 sample files |
| 1.5 | 2026-03-31 | No structural changes; document current as of plugin v0.1.1.2 |

---

## 15. Divi Native Export JSON Format

This section documents the JSON format produced by Divi's own export UI (Theme Options →
Import & Export, or the Divi Library export). This is **not** the same as the wp_options
database storage format described in §8. It is a presentation format designed for
portability between Divi sites.

**Confirmed against:** `Divi-5-Launch-Freebie_Global-Variables.json` (Elegant Themes
official sample, Divi 5.x).

### 15.1 Top-Level Envelope

A Divi native export file is always a single JSON object. The presence and meaning of
top-level keys depends on the `context` field.

```jsonc
{
  "context":        "<context-string>",   // Required. Identifies export type. See §15.2.
  "data":           {},                   // Layout/page data dict (keyed by post ID). May be [].
  "presets":        {},                   // Preset data. Structure per §15.5. May be [].
  "global_colors":  [],                   // Array of color pairs. See §15.3.
  "global_variables": [],                 // Flat array of variable objects. See §15.4.
  "canvases":       [],                   // Theme Builder canvas data. May be [].
  "images":         [],                   // Embedded image data. May be [].
  "thumbnails":     []                    // Thumbnail data. May be [].
}
```

**Key characteristic:** A single Divi export file is **omnibus** — it may contain
variables, colors, presets, and layout/page data all in one file, governed by `context`.
This is the primary structural difference from our plugin's format (§16), which exports
each data type as a separate file.

### 15.2 Context Values

| `context` value | What the file contains |
|---|---|
| `"et_builder"` | Global variables + colors + presets (design system export) |
| `"et_builder_layouts"` | Layout/page post data in `data` dict |
| `"et_divi_mods"` | Theme Customizer settings |
| `"et_template"` | Theme Builder template records |

### 15.3 Global Colors — `global_colors`

**Format:** An ordered array of two-element tuples `[id, record]`. The array order
determines display order in the Divi color palette.

```jsonc
[
  [
    "gcid-primary-color",         // Element 0: the color ID string (gcid-* prefix)
    {
      "color":  "#2176ff",        // Hex or $variable(...)$ reference token (§3)
      "status": "active",         // "active" | "inactive"
      "label":  "Primary Color"   // Display name
    }
  ],
  [ "gcid-s0kqi6v11w", { "color": "#000000", "status": "active", "label": "Black" } ],
  ...
]
```

**Notes:**
- The tuple format (array-of-pairs rather than a keyed object) preserves insertion order
  in all JSON parsers, including those that do not guarantee object key order.
- The `color` field holds the value, not `value`. This differs from non-color variables
  (§15.4) which use `value`.
- The system colors (`gcid-primary-color`, `gcid-secondary-color`, `gcid-heading-color`,
  `gcid-body-color`) are included in this array alongside user-created colors.
- Colors that reference other colors use the `$variable(...)$` token (§3) in the `color`
  field, e.g.: `"$variable({\"type\":\"color\",\"value\":{\"name\":\"gcid-y43rzvjcdl\",\"settings\":{\"opacity\":90}}})$"`

### 15.4 Global Variables — `global_variables`

**Format:** A flat array of variable objects. Each object represents one non-color
variable regardless of type.

```jsonc
[
  {
    "id":           "gvid-3ycvkww27b",        // gvid-* prefixed ID
    "label":        "Button Top & Bottom Padding",
    "value":        "16px",                    // CSS value, font name, URL, string, etc.
    "order":        "",                        // Display order integer or "" if unset
    "status":       "archived",               // "active" | "archived" | "inactive"
    "lastUpdated":  "2025-09-24T12:45:51.649Z", // ISO 8601 timestamp
    "variableType": "numbers",                 // Primary type discriminator
    "type":         "numbers"                  // Duplicate of variableType (always same value)
  },
  ...
]
```

**Variable type values** (`variableType` / `type`):

| Value | Meaning |
|---|---|
| `"numbers"` | CSS dimension or number (px, em, rem, %, clamp(), calc(), etc.) |
| `"fonts"` | Font family name string |
| `"strings"` | Plain text string |
| `"images"` | URL or `data:image/...;base64,...` data URI |
| `"links"` | URL string |

**Notes:**
- `variableType` and `type` always carry the same value. Both are present for
  compatibility — our importer accepts either (`$item['variableType'] ?? $item['type']`).
- `order` is a string-encoded integer or an empty string `""` when unordered.
- `lastUpdated` is present in Divi exports but is not written to the DB by our importer
  (we do not persist it).
- Colors are **not** in `global_variables` — they are in `global_colors` (§15.3).
- System fonts (`--et_global_heading_font`, `--et_global_body_font`) appear in
  `global_variables` with IDs `"--et_global_heading_font"` and `"--et_global_body_font"`,
  `variableType: "fonts"`. They are treated as regular entries in the export format even
  though they have special storage in the DB (§B4).

### 15.5 Presets — `presets`

In the Divi native export, the `presets` key holds the same nested structure as the
`et_divi_builder_global_presets_d5` wp_options value described in §B5. There is no
structural transformation — it is the raw DB value embedded directly.

```jsonc
{
  "module": {
    "divi/button": {
      "default": "<preset-id>",
      "items": {
        "<preset-id>": { /* element-preset-record per §B5.2 */ }
      }
    }
  },
  "group": {
    "divi/background": {
      "default": "<preset-id>",
      "items": {
        "<preset-id>": { /* group-preset-record per §B5.3 */ }
      }
    }
  }
}
```

### 15.6 Layout / Page Data — `data`

When `context` is `"et_builder_layouts"`, the `data` key holds a dict of post records
keyed by post ID (as a string):

```jsonc
{
  "<post-id>": {
    "post_title":   "My Layout",
    "post_name":    "my-layout",
    "post_status":  "publish",
    "post_type":    "et_pb_layout",
    "post_date":    "2025-10-01 12:00:00",
    "post_content": "<!-- wp:divi/section ... -->",
    "post_meta":    { "_et_pb_use_builder": "on", ... },
    "terms":        []
  }
}
```

### 15.7 ABNF Grammar — Divi Native Export JSON

```abnf
; ─────────────────────────────────────────────────────────────────────────────
; §D: DIVI NATIVE EXPORT JSON FORMAT
; Describes the top-level shape of files produced by Divi's own export UI.
; ─────────────────────────────────────────────────────────────────────────────

divi-export-file        = "{" ws
                            DQUOTE "context"          DQUOTE ":" ws divi-context          "," ws
                            DQUOTE "data"             DQUOTE ":" ws ( divi-data-dict / empty-array ) "," ws
                            DQUOTE "presets"          DQUOTE ":" ws ( presets-option / empty-array ) "," ws
                            DQUOTE "global_colors"    DQUOTE ":" ws divi-global-colors    "," ws
                            DQUOTE "global_variables" DQUOTE ":" ws divi-global-variables "," ws
                            DQUOTE "canvases"         DQUOTE ":" ws json-array            "," ws
                            DQUOTE "images"           DQUOTE ":" ws json-array            "," ws
                            DQUOTE "thumbnails"       DQUOTE ":" ws json-array
                          ws "}"

divi-context            = DQUOTE ( "et_builder"
                                 / "et_builder_layouts"
                                 / "et_divi_mods"
                                 / "et_template" ) DQUOTE

; global_colors: ordered array of [id, record] tuples
divi-global-colors      = "[" ws
                            [ divi-color-pair *( "," ws divi-color-pair ) ]
                          ws "]"

divi-color-pair         = "[" ws
                            DQUOTE color-var-id DQUOTE "," ws
                            divi-color-record
                          ws "]"

divi-color-record       = "{" ws
                            DQUOTE "color"  DQUOTE ":" ws color-value  "," ws
                            DQUOTE "status" DQUOTE ":" ws var-status   "," ws
                            DQUOTE "label"  DQUOTE ":" ws json-string
                          ws "}"

; global_variables: flat array of variable objects (all types except colors)
divi-global-variables   = "[" ws
                            [ divi-var-obj *( "," ws divi-var-obj ) ]
                          ws "]"

divi-var-obj            = "{" ws
                            DQUOTE "id"           DQUOTE ":" ws DQUOTE content-var-id DQUOTE "," ws
                            DQUOTE "label"        DQUOTE ":" ws json-string                   "," ws
                            DQUOTE "value"        DQUOTE ":" ws json-string                   "," ws
                            DQUOTE "order"        DQUOTE ":" ws divi-order                    "," ws
                            DQUOTE "status"       DQUOTE ":" ws var-status                    "," ws
                            DQUOTE "lastUpdated"  DQUOTE ":" ws json-string                   "," ws
                            DQUOTE "variableType" DQUOTE ":" ws divi-var-type                 "," ws
                            DQUOTE "type"         DQUOTE ":" ws divi-var-type
                          ws "}"

; Note: variableType and type always carry the same value.
; Note: system font entries use IDs "--et_global_heading_font" / "--et_global_body_font"
;       which do not match content-var-id pattern; parser must accept any non-empty string.

divi-var-type           = DQUOTE ( "numbers" / "fonts" / "images"
                                 / "strings" / "links" ) DQUOTE

; order is either a quoted integer string or an empty string
divi-order              = DQUOTE *DIGIT DQUOTE

; Layout/page data dict (context = et_builder_layouts)
divi-data-dict          = "{" ws
                            [ divi-post-entry *( "," ws divi-post-entry ) ]
                          ws "}"

divi-post-entry         = DQUOTE 1*DIGIT DQUOTE ":" ws divi-post-record

divi-post-record        = "{" ws
                            DQUOTE "post_title"   DQUOTE ":" ws json-string "," ws
                            DQUOTE "post_name"    DQUOTE ":" ws json-string "," ws
                            DQUOTE "post_status"  DQUOTE ":" ws post-status "," ws
                            DQUOTE "post_type"    DQUOTE ":" ws json-string "," ws
                            DQUOTE "post_date"    DQUOTE ":" ws json-string "," ws
                            DQUOTE "post_content" DQUOTE ":" ws json-string "," ws
                            DQUOTE "post_meta"    DQUOTE ":" ws json-object "," ws
                            DQUOTE "terms"        DQUOTE ":" ws json-array
                          ws "}"

empty-array             = "[" ws "]"
```

---

## 16. D5 Design System Helper Plugin Export JSON Format

This section documents the JSON format produced by this plugin's own export functions
(`JsonExporter.php`). Unlike Divi's omnibus format (§15), the plugin exports **one data
type per file**. Each file has a type-specific top-level envelope key plus a `_meta`
block added by the plugin.

**Confirmed against:** `JsonExporter.php` `build_export_data()` and all type builder
methods; `SimpleImporter.php` import handlers; `VarsRepository.php` `get_raw()`.

### 16.1 Design Principles

| Principle | Detail |
|---|---|
| One type per file | Each export covers exactly one of: vars, presets, layouts, pages, theme_customizer, builder_templates |
| DB-faithful | The envelope value is the raw wp_options array as stored, with no structural transformation (except layouts/pages which are re-shaped as a posts array) |
| Divi-compatible | The top-level envelope keys match Divi's own option key names (`et_divi_global_variables`, `et_divi_builder_global_presets_d5`, etc.), making files importable by Divi's native importer where Divi supports it |
| Plugin-identified | Every file includes a `_meta` block (§16.7). Divi ignores unknown top-level keys, so `_meta` is safe and does not break native import |
| Pretty-printed | `JSON_PRETTY_PRINT \| JSON_UNESCAPED_UNICODE \| JSON_UNESCAPED_SLASHES` — human-readable, no escaped slashes or Unicode escapes |

### 16.2 Variables Export (`type = "vars"`)

Envelope key: `et_divi_global_variables`

```jsonc
{
  "et_divi_global_variables": {
    "numbers": {
      "gvid-xxxxxxxx": {
        "id":     "gvid-xxxxxxxx",
        "label":  "Button Padding",
        "value":  "16px",
        "status": "active"
      }
    },
    "fonts":   { "gvid-xxxxxxxx": { "id": "...", "label": "...", "value": "...", "status": "..." } },
    "images":  { ... },
    "strings": { ... },
    "links":   { ... }
  },
  "_meta": { /* §16.7 */ }
}
```

**Structure:** Nested object `{ type → { id → record } }`. This is the raw value of
`et_divi_global_variables` from the database with no transformation.

**Variable record fields:**

| Field | Type | Notes |
|---|---|---|
| `id` | string | `gvid-*` prefixed ID |
| `label` | string | Display name |
| `value` | string | CSS value, font name, URL, or plain string |
| `status` | string | `"active"` \| `"archived"` \| `"inactive"` |

**What is NOT included:**
- Colors — stored separately in `et_divi[et_global_data][global_colors]`; colors do not
  appear in `et_divi_global_variables` and are not in this export file
- System fonts (`--et_global_heading_font` / `--et_global_body_font`) — stored in
  `et_divi` top-level keys, not in `et_divi_global_variables`
- System colors (`gcid-primary-color`, etc.) — stored as plain hex in `et_divi` top-level
  keys

**Importer behaviour:** `SimpleImporter::import_json_vars()`. Reads
`et_divi_global_variables`, merges additively into the DB. Does not touch colors or
system fonts/colors.

### 16.3 Presets Export (`type = "presets"`)

Envelope key: `et_divi_builder_global_presets_d5`

```jsonc
{
  "et_divi_builder_global_presets_d5": {
    "module": {
      "divi/button": {
        "default": "<preset-id>",
        "items": {
          "<preset-id>": { /* element-preset-record per §B5.2 */ }
        }
      }
    },
    "group": {
      "divi/background": {
        "default": "<preset-id>",
        "items": {
          "<preset-id>": { /* group-preset-record per §B5.3 */ }
        }
      }
    }
  },
  "_meta": { /* §16.7 */ }
}
```

**Structure:** Raw `et_divi_builder_global_presets_d5` value from the database. Identical
to the `presets` key in a Divi native export (§15.5).

### 16.4 Layouts / Pages Export (`type = "layouts"` or `"pages"`)

Envelope key: `posts`

```jsonc
{
  "posts": [
    {
      "ID":          12345,
      "post_title":  "My Layout",
      "post_name":   "my-layout",
      "post_status": "publish",
      "post_type":   "et_pb_layout",
      "post_date":   "2025-10-01 12:00:00",
      "menu_order":  0,
      "post_parent": 0,
      "post_meta":   { "_et_pb_use_builder": "on", ... },
      "terms":       []
    }
  ],
  "_meta": { /* §16.7 */ }
}
```

**Note:** `post_content` is **not included** in layouts/pages exports (unlike Divi's
native format). It is excluded because of size concerns and because `post_content`
contains block markup that may hold DSO references which should be managed separately.

### 16.5 Theme Customizer Export (`type = "theme_customizer"`)

Envelope key: `theme_mods_Divi`

```jsonc
{
  "theme_mods_Divi": {
    "accent_color":           "#2176ff",
    "secondary_accent_color": "#ff5700",
    "heading_font":           "Inter",
    "body_font":              "Inter",
    ...
  },
  "_meta": { /* §16.7 */ }
}
```

**Structure:** Raw `theme_mods_Divi` value from the database (flat key → value map).

### 16.6 Builder Templates Export (`type = "builder_templates"`)

Envelope keys: `et_template`, `layouts`

```jsonc
{
  "et_template": [
    {
      "post_title":  "My Template",
      "post_status": "publish",
      "post_type":   "et_template",
      "post_meta":   {
        "_et_header_layout_id": "100",
        "_et_body_layout_id":   "101",
        "_et_footer_layout_id": "102",
        "_et_use_on":           "a:1:{i:0;s:2:\"on\";}",
        "_et_enabled":          "1"
      }
    }
  ],
  "layouts": {
    "100": {
      "post_title":   "Header Canvas",
      "post_type":    "et_header_layout",
      "post_content": "<!-- wp:divi/section ... -->",
      ...
    },
    "101": { ... },
    "102": { ... }
  },
  "_meta": { /* §16.7 */ }
}
```

**Note:** Unlike layouts/pages (§16.4), builder templates **do include** `post_content`
because Divi needs the full block markup to restore templates via native import.

### 16.7 The `_meta` Block

Every plugin export file includes a `_meta` top-level key. It is a D5DSH addition;
Divi's native importer ignores unknown top-level keys, so its presence does not affect
Divi compatibility.

```jsonc
"_meta": {
  "exported_by": "D5 Design System Helper",
  "version":     "0.1.0",
  "type":        "vars",
  "exported_at": "2026-03-30T12:00:00+00:00",
  "site_url":    "https://example.com"
}
```

| Field | Type | Notes |
|---|---|---|
| `exported_by` | string | Always `"D5 Design System Helper"` |
| `version` | string | Plugin version at time of export (`D5DSH_VERSION` constant) |
| `type` | string | One of: `vars`, `presets`, `layouts`, `pages`, `theme_customizer`, `builder_templates` |
| `exported_at` | string | ISO 8601 timestamp (`gmdate('c')`) in UTC |
| `site_url` | string | WordPress site URL (`get_site_url()`) |

**Importer use:** The `_meta.type` field is used by `SimpleImporter` as a fallback type
detector when the top-level envelope key is ambiguous.

### 16.8 Format Comparison: Plugin vs. Divi Native (Variables)

| Aspect | Divi native (§15) | Plugin format (§16) |
|---|---|---|
| File scope | Omnibus — all types in one file | One type per file |
| Colors | `global_colors` array of tuples | Not included (separate DB path) |
| Non-color vars | `global_variables` flat array | `et_divi_global_variables` nested dict |
| Var record fields | `id`, `label`, `value`, `order`, `status`, `lastUpdated`, `variableType`, `type` | `id`, `label`, `value`, `status` |
| Order field | Present as string-encoded int or `""` | Not present (DB insertion order) |
| `lastUpdated` | Present (ISO 8601) | Not present |
| Color value field | `color` | N/A (colors not in vars export) |
| Presets | Embedded in same file under `presets` | Separate file, `et_divi_builder_global_presets_d5` |
| Plugin metadata | Not present | `_meta` block |
| System fonts | Included as `global_variables` entries | Not included (separate DB path) |
| Import handler | `import_json_et_native()` | `import_json_vars()` |

### 16.9 Type Detection

`SimpleImporter` detects which format and type a JSON file represents by inspecting
top-level keys in this priority order:

1. `et_divi_global_variables` → plugin vars format
2. `et_divi_builder_global_presets_d5` → plugin presets format
3. `posts` → plugin layouts/pages format
4. `theme_mods_Divi` → plugin theme customizer format
5. `et_template` → plugin builder templates format
6. `context` (value `"et_builder"`, `"et_builder_layouts"`, etc.) → Divi native format
7. `global_variables` or `global_colors` present → Divi native format (fallback)
8. `$schema` containing `"designtokens.org"` or top-level token groups → DTCG format (§17)
9. `_meta.type` → plugin format, type from `_meta` value

### 16.10 ABNF Grammar — Plugin Export JSON

```abnf
; ─────────────────────────────────────────────────────────────────────────────
; §E: D5DSH PLUGIN EXPORT JSON FORMAT
; One rule per export type; all share the _meta block.
; ─────────────────────────────────────────────────────────────────────────────

; ── §E1: Variables export ─────────────────────────────────────────────────────

plugin-vars-export      = "{" ws
                            DQUOTE "et_divi_global_variables" DQUOTE ":" ws vars-option "," ws
                            DQUOTE "_meta"                    DQUOTE ":" ws plugin-meta
                          ws "}"

; vars-option per §B1

; ── §E2: Presets export ───────────────────────────────────────────────────────

plugin-presets-export   = "{" ws
                            DQUOTE "et_divi_builder_global_presets_d5" DQUOTE ":" ws presets-option "," ws
                            DQUOTE "_meta"                             DQUOTE ":" ws plugin-meta
                          ws "}"

; presets-option per §B5

; ── §E3: Layouts / Pages export ───────────────────────────────────────────────

plugin-layouts-export   = "{" ws
                            DQUOTE "posts"  DQUOTE ":" ws plugin-posts-array "," ws
                            DQUOTE "_meta"  DQUOTE ":" ws plugin-meta
                          ws "}"

plugin-posts-array      = "[" ws
                            [ plugin-post-record *( "," ws plugin-post-record ) ]
                          ws "]"

plugin-post-record      = "{" ws
                            DQUOTE "ID"          DQUOTE ":" ws json-number "," ws
                            DQUOTE "post_title"  DQUOTE ":" ws json-string "," ws
                            DQUOTE "post_name"   DQUOTE ":" ws json-string "," ws
                            DQUOTE "post_status" DQUOTE ":" ws post-status "," ws
                            DQUOTE "post_type"   DQUOTE ":" ws json-string "," ws
                            DQUOTE "post_date"   DQUOTE ":" ws json-string "," ws
                            DQUOTE "menu_order"  DQUOTE ":" ws json-number "," ws
                            DQUOTE "post_parent" DQUOTE ":" ws json-number "," ws
                            DQUOTE "post_meta"   DQUOTE ":" ws json-object "," ws
                            DQUOTE "terms"       DQUOTE ":" ws json-array
                          ws "}"
                          ; NOTE: post_content is intentionally absent in layouts/pages exports

; ── §E4: Theme Customizer export ──────────────────────────────────────────────

plugin-customizer-export = "{" ws
                             DQUOTE "theme_mods_Divi" DQUOTE ":" ws theme-mods-option "," ws
                             DQUOTE "_meta"           DQUOTE ":" ws plugin-meta
                           ws "}"

; theme-mods-option per §B6

; ── §E5: Builder Templates export ─────────────────────────────────────────────

plugin-templates-export = "{" ws
                            DQUOTE "et_template" DQUOTE ":" ws plugin-templates-array "," ws
                            DQUOTE "layouts"     DQUOTE ":" ws plugin-layouts-dict     "," ws
                            DQUOTE "_meta"       DQUOTE ":" ws plugin-meta
                          ws "}"

plugin-templates-array  = "[" ws
                            [ plugin-template-record *( "," ws plugin-template-record ) ]
                          ws "]"

plugin-template-record  = "{" ws
                            DQUOTE "post_title"  DQUOTE ":" ws json-string "," ws
                            DQUOTE "post_status" DQUOTE ":" ws post-status "," ws
                            DQUOTE "post_type"   DQUOTE ":" ws json-string "," ws
                            DQUOTE "post_meta"   DQUOTE ":" ws json-object
                          ws "}"

; layouts dict: keyed by post ID string, value includes post_content (block markup)
plugin-layouts-dict     = "{" ws
                            [ plugin-canvas-entry *( "," ws plugin-canvas-entry ) ]
                          ws "}"

plugin-canvas-entry     = DQUOTE 1*DIGIT DQUOTE ":" ws plugin-canvas-record

plugin-canvas-record    = "{" ws
                            DQUOTE "post_title"   DQUOTE ":" ws json-string "," ws
                            DQUOTE "post_type"    DQUOTE ":" ws json-string "," ws
                            DQUOTE "post_content" DQUOTE ":" ws json-string "," ws
                            [ json-members ]
                          ws "}"

; ── §E6: _meta block ──────────────────────────────────────────────────────────

plugin-meta             = "{" ws
                            DQUOTE "exported_by" DQUOTE ":" ws DQUOTE "D5 Design System Helper" DQUOTE "," ws
                            DQUOTE "version"     DQUOTE ":" ws json-string "," ws
                            DQUOTE "type"        DQUOTE ":" ws plugin-export-type "," ws
                            DQUOTE "exported_at" DQUOTE ":" ws json-string "," ws
                            DQUOTE "site_url"    DQUOTE ":" ws json-string
                          ws "}"

plugin-export-type      = DQUOTE ( "vars" / "presets" / "layouts" / "pages"
                                 / "theme_customizer" / "builder_templates" ) DQUOTE
```

---

## 17. DTCG Export JSON Format (W3C Design Tokens)

This section documents the W3C Design Tokens Community Group (DTCG) format produced by
`DtcgExporter.php`. This is an interoperability format — it allows Divi design tokens to
be consumed by third-party design tools (Figma, Style Dictionary, etc.) that support the
DTCG specification.

**Specification:** W3C DTCG format, version 2025.10
(`https://tr.designtokens.org/format/`)

**Confirmed against:** `DtcgExporter.php` `build_export_data()`.

### 17.1 Top-Level Envelope

```jsonc
{
  "$schema":    "https://tr.designtokens.org/format/",
  "color":      { /* color token group */ },
  "dimension":  { /* dimension token group (numbers with CSS units) */ },
  "number":     { /* number token group (bare numeric values) */ },
  "fontFamily": { /* font family token group */ },
  "string":     { /* string token group */ },
  "_meta":      { /* DTCG meta block (§17.4) */ }
}
```

Only groups that have at least one entry are included. An export with no font variables
will omit the `fontFamily` key.

### 17.2 Type Mapping

| Divi type | DTCG `$type` group key | Condition |
|---|---|---|
| `colors` | `color` | Always |
| `numbers` | `dimension` | Value ends with a CSS unit (px, rem, em, %, vh, vw, etc.) |
| `numbers` | `number` | Value is a bare numeric string (no unit) |
| `fonts` | `fontFamily` | Always |
| `strings` | `string` | Always |
| `images` | *(omitted)* | No DTCG equivalent |
| `links` | *(omitted)* | No DTCG equivalent |

### 17.3 Token Entry Structure

Each token is an object keyed by the Divi variable ID within its type group:

```jsonc
"color": {
  "gcid-primary-color": {
    "$type":        "color",
    "$value":       "#2176ff",
    "$description": "Primary Color",
    "extensions": {
      "d5dsh:id":     "gcid-primary-color",
      "d5dsh:status": "active",
      "d5dsh:system": false
    }
  },
  "gcid-s0kqi6v11w": {
    "$type":        "color",
    "$value":       "#000000",
    "$description": "Black",
    "extensions": {
      "d5dsh:id":     "gcid-s0kqi6v11w",
      "d5dsh:status": "active",
      "d5dsh:system": false
    }
  }
}
```

**Standard DTCG fields:**

| Field | DTCG spec | Value source |
|---|---|---|
| `$type` | Required | DTCG type string from §17.2 mapping |
| `$value` | Required | Divi variable `value` field (colors: resolved hex, not `$variable(...)$` reference) |
| `$description` | Optional | Divi variable `label` field |

**Plugin extension fields** (in `extensions` object, per DTCG §9):

| Field | Type | Notes |
|---|---|---|
| `d5dsh:id` | string | Original Divi variable ID (`gcid-*` or `gvid-*`) — enables round-trip import |
| `d5dsh:status` | string | `"active"` \| `"archived"` \| `"inactive"` |
| `d5dsh:system` | boolean | `true` for Divi built-in variables (id/label read-only) |

**Color value resolution:** For color variables whose Divi `value` is a
`$variable(...)$` reference token (§3), the exporter resolves one level of aliasing and
writes the resolved hex value to `$value`. If the reference cannot be resolved (target
not found), the raw token string is used as-is. This means DTCG color values are always
concrete hex values, not aliases — DTCG aliasing (`{color.primary}`) is not used.

### 17.4 DTCG `_meta` Block

```jsonc
"_meta": {
  "exported_by": "D5 Design System Helper",
  "version":     "0.1.0",
  "exported_at": "2026-03-30T12:00:00+00:00",
  "site_url":    "https://example.com",
  "dtcg_schema": "2025.10"
}
```

| Field | Notes |
|---|---|
| `exported_by` | Always `"D5 Design System Helper"` |
| `version` | Plugin version at export time |
| `exported_at` | ISO 8601 UTC timestamp |
| `site_url` | WordPress site URL |
| `dtcg_schema` | DTCG spec version (`"2025.10"`) |

### 17.5 Import Round-Trip

`SimpleImporter` can import DTCG files (detected by `$schema` containing
`"designtokens.org"`). The importer reads the following fields from each token:

| DTCG field | Maps to |
|---|---|
| `extensions.d5dsh:id` | Variable ID (preferred); falls back to token key if absent |
| `$description` | Variable label |
| `$value` | Variable value |
| `extensions.d5dsh:status` | Variable status |
| Token group key | Divi variable type (via reverse of §17.2 type map) |

**Reverse type map (import):**

| DTCG group key | Divi type |
|---|---|
| `color` | `colors` |
| `dimension` | `numbers` |
| `number` | `numbers` |
| `fontFamily` | `fonts` |
| `string` | `strings` |

### 17.6 ABNF Grammar — DTCG Export JSON

```abnf
; ─────────────────────────────────────────────────────────────────────────────
; §F: DTCG EXPORT JSON FORMAT
; W3C Design Tokens Community Group format, DTCG 2025.10.
; ─────────────────────────────────────────────────────────────────────────────

dtcg-export-file        = "{" ws
                            DQUOTE "$schema"    DQUOTE ":" ws DQUOTE dtcg-schema-url DQUOTE
                            [ "," ws DQUOTE "color"      DQUOTE ":" ws dtcg-token-group ]
                            [ "," ws DQUOTE "dimension"  DQUOTE ":" ws dtcg-token-group ]
                            [ "," ws DQUOTE "number"     DQUOTE ":" ws dtcg-token-group ]
                            [ "," ws DQUOTE "fontFamily" DQUOTE ":" ws dtcg-token-group ]
                            [ "," ws DQUOTE "string"     DQUOTE ":" ws dtcg-token-group ]
                            "," ws DQUOTE "_meta"        DQUOTE ":" ws dtcg-meta
                          ws "}"

dtcg-schema-url         = "https://tr.designtokens.org/format/"

dtcg-token-group        = "{" ws
                            [ dtcg-token-entry *( "," ws dtcg-token-entry ) ]
                          ws "}"

dtcg-token-entry        = DQUOTE var-id DQUOTE ":" ws dtcg-token-obj

dtcg-token-obj          = "{" ws
                            DQUOTE "$type"        DQUOTE ":" ws DQUOTE dtcg-type      DQUOTE "," ws
                            DQUOTE "$value"       DQUOTE ":" ws json-string                       "," ws
                            DQUOTE "$description" DQUOTE ":" ws json-string                       "," ws
                            DQUOTE "extensions"   DQUOTE ":" ws dtcg-extensions
                          ws "}"

dtcg-type               = "color" / "dimension" / "number" / "fontFamily" / "string"

dtcg-extensions         = "{" ws
                            DQUOTE "d5dsh:id"     DQUOTE ":" ws DQUOTE var-id        DQUOTE "," ws
                            DQUOTE "d5dsh:status" DQUOTE ":" ws var-status                        "," ws
                            DQUOTE "d5dsh:system" DQUOTE ":" ws ( "true" / "false" )
                          ws "}"

dtcg-meta               = "{" ws
                            DQUOTE "exported_by"  DQUOTE ":" ws json-string "," ws
                            DQUOTE "version"      DQUOTE ":" ws json-string "," ws
                            DQUOTE "exported_at"  DQUOTE ":" ws json-string "," ws
                            DQUOTE "site_url"     DQUOTE ":" ws json-string "," ws
                            DQUOTE "dtcg_schema"  DQUOTE ":" ws json-string
                          ws "}"
```

### 17.7 Format Summary Table

| Aspect | Divi native (§15) | Plugin JSON (§16) | DTCG (§17) |
|---|---|---|---|
| Consumer | Divi import UI | Our plugin import + Divi import | Design tools (Figma, Style Dictionary), our plugin |
| Scope | All types in one file | One type per file | Variables only (colors + numbers + fonts + strings) |
| Color representation | `global_colors` tuple array | Not in vars export | `color` group with resolved hex values |
| Number representation | `global_variables` flat array | `et_divi_global_variables` nested dict | `dimension` or `number` group |
| Variable ID preserved | Yes (`id` field) | Yes (`id` field) | Via `extensions.d5dsh:id` |
| Status preserved | Yes (`status` field) | Yes (`status` field) | Via `extensions.d5dsh:status` |
| `lastUpdated` | Yes | No | No |
| `order` | Yes (string int or `""`) | No | No |
| System variable flag | No | No | Via `extensions.d5dsh:system` |
| Color aliases | Stored as `$variable(...)$` token | N/A | Resolved to concrete hex on export |
| Images / links | Yes (in `global_variables`) | Yes (in `et_divi_global_variables`) | Omitted (no DTCG equivalent) |
| Plugin metadata | No | `_meta` block | `_meta` block + `$schema` + `dtcg_schema` version |

---

*Source: [github.com/akonsta/d5-design-system-helper](https://github.com/akonsta/d5-design-system-helper)*
