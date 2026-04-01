**D5 Design System Helper**

**Import Security Analysis**

Version: 1.1

Date: 26 March 2026

Plugin version: 0.6.16

Author: Andrew Konstantaras with Claude Code AI

**1. Executive Summary**

This document analyses every import pathway in the D5 Design System
Helper WordPress plugin for potential security vulnerabilities. The
plugin imports data from Excel (.xlsx), JSON, DTCG (design-tokens.json),
and ZIP files and writes it to the WordPress database (wp_options and
wp_posts).

Every field in every import pathway is now sanitized, including fields
from third-party files (e.g. Elegant Themes native exports). A
sanitization logging system records every instance where a value was
modified during import, and this log is displayed to the user in the
import results modal. This applies to all JSON, Excel, DTCG, and ZIP
imports without exception.

Five high-priority attack surfaces were identified and all have been
mitigated as of v0.6.16. The analysis covers stored XSS via
post_content, stored XSS via post_meta, stored XSS via preset
attributes, stored XSS via theme customizer settings, and file path
traversal.

**2. Scope**

The analysis covers all import entry points:

> • JSON import (SimpleImporter.php) --- Variables, Presets, Layouts,
> Pages, Builder Templates, Theme Customizer, Divi native format
>
> • Excel import --- Variables (VariablesImporter.php), Presets
> (PresetsImporter.php), Layouts/Pages (LayoutsImporter.php), Builder
> Templates (BuilderTemplatesImporter.php), Theme Customizer
> (ThemeCustomizerImporter.php)
>
> • DTCG import (SimpleImporter.php) --- design-tokens.json format
>
> • ZIP import --- delegates to the above after extraction

Out of scope: the export pathway (read-only, no write to DB), admin UI
rendering (already uses esc_html()/wp_kses_post()), AJAX authentication
and nonce verification (already enforced on all endpoints).

**3. Threat Model**

The primary threat actor is an authenticated WordPress administrator who
uploads a crafted import file. While admins already have full database
access, the plugin should not introduce new XSS vectors that could
persist beyond the admin session or affect site visitors.

Secondary threat: a non-admin user who gains access to an admin\'s
import file could inject payloads before the admin uploads it.

Attack surface: any string value in an import file that is written to
the database and later rendered in HTML output (admin pages, frontend
pages, or the WordPress REST API).

**4. Findings and Mitigations**

**4.1 JSON Import --- SimpleImporter.php**

Six import methods handle different data types from JSON files. Each was
audited for unsanitized writes.

  ------------------------------------------------------------------------------------------------------------
  **\#**   **Attack       **Severity**   **Data Path**                     **Mitigation Applied**
           Vector**                                                        
  -------- -------------- -------------- --------------------------------- -----------------------------------
  1        Stored XSS via High           import_json_posts() and           Applied wp_kses_post() to all
           post_content                  import_json_builder_templates()   post_content values before
                                         write post_content to wp_posts    wp_insert_post() /
                                                                           wp_update_post(). This strips
                                                                           \<script\>, \<iframe\>, event
                                                                           handlers, and all non-allowlisted
                                                                           HTML while preserving Divi block
                                                                           markup and shortcodes.

  2        Stored XSS via High           import_json_posts() and           Added sanitize_meta_value() helper
           post_meta                     import_json_builder_templates()   that recursively sanitizes all
                                         write arbitrary meta keys and     string values with
                                         values via add_post_meta() /      sanitize_text_field(). Applied to
                                         update_post_meta()                every meta value before storage.

  3        Stored XSS via Medium         import_json_presets() and         Added sanitize_preset() helper that
           preset attrs /                import_json_et_native() write     sanitizes scalar fields (id, name,
           styleAttrs                    preset arrays to wp_options       moduleName, version, etc.) with
                                                                           sanitize_text_field() and
                                                                           deep-sanitizes attrs, styleAttrs,
                                                                           and groupPresets arrays
                                                                           recursively.

  4        Stored XSS via Medium         import_json_theme_customizer()    Applied sanitize_key() to all keys
           theme                         writes key-value pairs to         and sanitize_deep() to all values
           customizer                    theme_mods_Divi                   before storage. sanitize_deep()
           values                                                          recursively applies
                                                                           sanitize_text_field() to every
                                                                           string leaf in nested arrays.

  5        Stored XSS via Low            import_json_vars() writes         Already safe: variable labels are
           variable                      variable objects to               stored as plain strings in a
           labels                        et_divi_global_variables          structured array and rendered with
                                                                           esc_html() on output. Added
                                                                           sanitize_text_field() to label
                                                                           overrides as defense in depth.

  6        DTCG import    Low            import_dtcg() maps DTCG tokens to Already safe: DTCG values are
           payload                       Divi variables                    mapped to typed variable entries
           injection                                                       (color hex, number, font name) and
                                                                           stored in the structured
                                                                           et_divi_global_variables array.
                                                                           Output is always escaped.

  7        File path      Medium         ZIP extraction and file path      Already mitigated: realpath() +
           traversal                     handling in                       directory boundary check prevents
                                         validate_path_within()            traversal. Verified no bypass
                                                                           possible.
  ------------------------------------------------------------------------------------------------------------

**4.2 Excel Import --- Individual Importer Classes**

Each Excel importer reads from PhpSpreadsheet and writes to the
database. The following table documents each importer and its
mitigation.

  -------------------------------------------------------------------------------------------------------------------
  **\#**   **Importer**                   **Attack Vector**    **Severity**   **Mitigation Applied**
  -------- ------------------------------ -------------------- -------------- ---------------------------------------
  8        PresetsImporter.php            Unsanitized preset   Medium         Applied
                                          attrs, styleAttrs,                  SimpleImporter::sanitize_preset() to
                                          scalar fields                       every module and group preset before
                                          written to                          storage. Sanitizes all scalar fields
                                          wp_options                          and recursively deep-sanitizes nested
                                                                              attribute arrays.

  9        LayoutsImporter.php            Raw post_meta JSON   High           Applied sanitize_key() to meta keys and
                                          values written via                  SimpleImporter::sanitize_meta_value()
                                          update_post_meta()                  to all meta values. Note: post_content
                                          without sanitization                is never imported from Excel (preserved
                                                                              from DB), so no XSS vector there.

  10       BuilderTemplatesImporter.php   Unsanitized          Medium         Applied sanitize_text_field() to
                                          \_et_description                    \_et_description. Applied
                                          meta value; raw                     sanitize_key() to layout meta keys and
                                          layout post_meta                    SimpleImporter::sanitize_meta_value()
                                          values                              to layout meta values. Template boolean
                                                                              and serialized array meta are already
                                                                              safe (hardcoded keys, maybe_serialize()
                                                                              on arrays).

  11       ThemeCustomizerImporter.php    Raw keys and values  Medium         Applied sanitize_key() to all keys and
                                          from Excel written                  SimpleImporter::sanitize_deep() to all
                                          directly to                         values before storage.
                                          theme_mods_Divi                     

  12       VariablesImporter.php          Variable labels,     Low            Already safe: imports only update
                                          values, and status                  existing variable entries (matched by
                                          from Excel                          ID). Values are typed (hex colors,
                                                                              numbers, font names). Labels rendered
                                                                              with esc_html() on output.
  -------------------------------------------------------------------------------------------------------------------

**5. Sanitization Helpers Added**

Three reusable static methods were added to SimpleImporter.php and are
used by all importers:

**sanitize_deep( mixed \$data ): mixed**

Recursively walks arrays and applies sanitize_text_field() to every
string leaf. Non-string, non-array values (int, float, bool, null) pass
through unchanged.

**sanitize_meta_value( mixed \$value ): mixed**

Wrapper for post_meta values. Delegates arrays to sanitize_deep() and
strings to sanitize_text_field().

**sanitize_preset( array \$preset ): array**

Sanitizes a complete preset entry: sanitize_text_field() on scalar keys
(id, name, moduleName, version, type, groupName, groupID),
sanitize_deep() on nested structures (attrs, styleAttrs, groupPresets).

**6. Pre-existing Security Controls**

The following security controls were already in place before this audit:

> • All AJAX endpoints require manage_options capability (Administrator
> only)
>
> • Nonces verified on all form submissions and AJAX requests
>
> • File uploads restricted to .xlsx files; validated via hidden Config
> sheet
>
> • ZIP extraction uses realpath() + directory boundary check to prevent
> path traversal
>
> • All admin UI output escaped with esc_html() / wp_kses_post()
>
> • JavaScript output uses escHtml() helper consistently
>
> • No external HTTP calls --- plugin never phones home or loads remote
> assets
>
> • Excel file validation checks SHA-256 hash and source metadata

**7. Sanitization Logging and Reporting**

Every sanitization operation now uses a centralized logging system that
records when a value was modified. This provides complete transparency
to the administrator about what the sanitizer caught and cleaned during
import.

**7.1 How It Works**

> • A static sanitization log array accumulates entries during each
> import operation
>
> • The \`sanitize_and_log()\` method compares the raw and sanitized
> values; if they differ, an entry is logged with context, field name,
> original (truncated to 200 chars), and cleaned value
>
> • \`sanitize_deep()\`, \`sanitize_meta_value()\`, and
> \`sanitize_preset()\` all propagate context strings for accurate
> logging
>
> • The log is reset at the start of each import method to prevent
> cross-contamination
>
> • Each import method returns its sanitization_log alongside the
> standard results
>
> • The AJAX response aggregates logs from all file imports into a
> top-level \`sanitization_log\` array

**7.2 What Gets Logged**

  --------------------------------------------------------------------------------
  **Sanitization Method** **Applied To**         **What It Removes**
  ----------------------- ---------------------- ---------------------------------
  sanitize_text_field()   Labels, values, status HTML tags, octets, extra
                          fields, scalar preset  whitespace, line breaks
                          fields, meta values    

  sanitize_key()          Option keys, meta      Everything except lowercase
                          keys, status values,   alphanumeric, dashes, underscores
                          variable type keys     

  wp_kses_post()          post_content for       \<script\>, \<iframe\>, event
                          layouts, pages,        handlers, non-allowlisted HTML
                          builder templates      

  sanitize_title()        post_name (slug)       Non-URL-safe characters
                          fields                 
  --------------------------------------------------------------------------------

**7.3 User-Facing Report**

When any sanitization modifications occur during import, a yellow
\"Sanitization Report\" panel appears in the import results modal. It
shows:

> • The total count of issues cleaned
>
> • A description explaining what happened
>
> • A table with Context (which item), Field (which property), Original
> (excerpt in red), and Cleaned (result in green)

If no values were modified by sanitization, the panel does not appear
--- indicating a clean import.

**8. Risk Assessment Summary**

  ---------------------------------------------------------------------------------------
  **Category**     **Pre-Mitigation   **Post-Mitigation   **Notes**
                   Risk**             Risk**              
  ---------------- ------------------ ------------------- -------------------------------
  Stored XSS via   High               Low                 wp_kses_post() is the WordPress
  post_content                                            standard for content
                                                          sanitization

  Stored XSS via   High               Low                 Recursive sanitize_text_field()
  post_meta                                               on all values

  Stored XSS via   Medium             Low                 Deep sanitization of all nested
  preset attrs                                            attribute structures

  Stored XSS via   Medium             Low                 Key sanitization + recursive
  theme customizer                                        value sanitization

  Stored XSS via   Low                Negligible          Output escaping was already
  variable labels                                         present; input sanitization
                                                          added as defense in depth

  File path        Medium             Negligible          realpath() boundary check was
  traversal                                               already in place

  SQL injection    Negligible         Negligible          All DB operations use WordPress
                                                          API functions (update_option,
                                                          wp_insert_post, etc.) which use
                                                          prepared statements internally

  CSRF             Negligible         Negligible          Nonce verification on all
                                                          endpoints

  Privilege        Negligible         Negligible          manage_options capability check
  escalation                                              on all endpoints
  ---------------------------------------------------------------------------------------

**9. Recommendations for Future Work**

> • Add automated security regression tests that verify sanitization is
> applied to all import paths (planned as item 13b in the project
> priority list)
>
> • Consider Content Security Policy headers for the admin page to
> further mitigate XSS risk
>
> • Periodically audit new import features against this checklist when
> adding data types
>
> • Consider adding a file-level digital signature to exported files to
> detect tampering before import

**10. Version History**

  ------------------------------------------------------------------------------
  **Version**   **Date**    **Author**      **Summary**
  ------------- ----------- --------------- ------------------------------------
  1.0           26 Mar 2026 AGK + Claude    Initial security analysis; all
                            Code            import paths audited and mitigated

  1.1           26 Mar 2026 AGK + Claude    Comprehensive sanitization of every
                            Code            field in every import path (JSON,
                                            Excel, DTCG); sanitization logging
                                            and user-facing report; Excel
                                            VarsImporter sanitization added
  ------------------------------------------------------------------------------
