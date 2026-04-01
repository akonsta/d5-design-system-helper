**D5 Design System Helper --- Security Test Report**

Applies to: D5 Design System Helper plugin import/export

Status: D5DSH plugin tests complete (§5, §6). Pending: render tests,
export-carrier re-run, follow-up items.

**Version History**

  -----------------------------------------------------------------------------
  **Version**   **Date**     **Summary**
  ------------- ------------ --------------------------------------------------
  0.6           2026-03-31   D5DSH plugin import tests completed via automated
                             Security Testing panel (v0.1.1). All 29 fixtures
                             run. 28/29 PASS; 1 exception (TypeError in
                             structural fixture --- non-string value passed to
                             sanitize_and_log). 42 fields sanitized across the
                             full run. Full D5DSH results in §5. Bug fix
                             applied: sanitize_and_log now guards against
                             array/null values.

  0.7           2026-03-31   Added SAFE_DEFAULT result code to §3 taxonomy to
                             distinguish structural normalization
                             (null/missing/wrong-type fields) from BLOCKED
                             (payload detected and stripped) and PASSED
                             (payload stored verbatim).

  0.8           2026-04-01   Report revised to cover D5DSH plugin tests only.
                             Sections covering third-party product behavior
                             removed.
  -----------------------------------------------------------------------------

**1. Purpose and Scope**

This document records security tests conducted against the D5 Design
System Helper (D5DSH) plugin import/export function. The goal is to
determine:

> 1\. **What D5DSH sanitizes** when importing payloads via the plugin\'s
> Import tab.
>
> 2\. **What gaps remain** in the plugin\'s sanitization that could allow
> malicious content to reach the database or be rendered in a browser.

Tests are conducted on a local WordPress site (Local by Flywheel) and do
not affect any production environment. All test fixture files are stored
in the project repository under tests/fixtures/security/.

**2. Test Environment**

  -----------------------------------------------------------------------
  **Item**           **Detail**
  ------------------ ----------------------------------------------------
  WordPress          Local by Flywheel --- local development environment

  D5DSH version      0.1.1 (Security Testing panel, automated fixture
                     runner)

  Test fixture       tests/fixtures/security/
  directory

  Clean baseline     test-vars-clean.json
  file

  Database reset     Local by Flywheel database reset between each test
  method
  -----------------------------------------------------------------------

**3. Test Methodology**

Each test follows this sequence:

> 1\. **Reset:** Restore the database to a clean known state.
>
> 2\. **Import:** Import a single poison test file via the D5DSH Import
> tab.
>
> 3\. **Observe:** Check for errors or unexpected behavior.
>
> 4\. **Review sanitization log:** Inspect the D5DSH sanitization report
> for fields modified during import.

Each poison file is isolated to a single attack category so that the
culprit of any failure can be pinpointed.

**Result codes used in tables:**

  -----------------------------------------------------------------------
  **Code**    **Meaning**
  ----------- -----------------------------------------------------------
  BLOCKED     Value was stripped to empty string --- attack neutralized

  PARTIAL     Some payload was removed but the variable entry still
              exists with residual content

  PASSED      Value stored exactly as imported --- no sanitization
              applied; assess whether this represents a risk in context

  ENCODED     Value was URL-encoded rather than stripped --- quote
              neutralized but payload survives in encoded form

  SAFE_DEFAULT  Structural edge case (missing field, null, wrong type)
                was safely normalized to an empty string or type-
                appropriate default. Not a sanitization action --- no
                malicious payload was present. Distinct from BLOCKED,
                which means a payload was detected and stripped.

  EXCEPTION   Import raised an unhandled exception --- logged and fixed
  -----------------------------------------------------------------------

**4. Attack Taxonomy**

The following categories of malicious content were tested. These
represent the standard OWASP web attack surface as it applies to a field
that accepts free-form design token values and is later rendered in a
browser or processed server-side.

  ----------------------------------------------------------------------------
  **Category**       **Code**   **Description**
  ------------------ ---------- ----------------------------------------------
  Cross-Site         XSS        HTML/JS injection via script tags, event
  Scripting                     handlers, SVG onload, iframe src, data: URIs,
                                javascript: URIs, CSS context breaks

  SQL Injection      SQLI       SQL fragments injected into field values,
                                exploitable if values are ever interpolated
                                into a raw database query

  PHP Code Execution PHP        PHP opening tags (\<?php, \<?=) that execute
                                if a value is written to a cached PHP file

  Path Traversal     PATH       Directory traversal sequences (../../) that
                                could be used to read or write files outside
                                the intended directory

  Server-Side        SSRF       URLs pointing to internal network resources
  Request Forgery               (AWS metadata endpoint, localhost)

  CSS Injection      CSS        CSS property injection and expression()
                                payloads that execute in older browsers or
                                break page styling

  Serialization      SER        PHP serialized object/array strings that
  Attack                        execute if passed to unserialize()

  Null Byte          NULL       Null bytes (\\u0000) that truncate strings at
  Injection                     the C layer and can bypass length-based
                                validators

  Structural /       STRUCT     JSON keys (\_\_proto\_\_, constructor) that
  Prototype                     pollute JavaScript object prototypes; deeply
  Pollution                     nested objects; type confusion

  Open Redirect      REDIR      Protocol-relative URLs (//evil.com) used as
                                link values to redirect users to
                                attacker-controlled sites

  Attribute Break    ATTR       Quote characters in values designed to break
                                out of HTML attribute context and inject event
                                handlers

  Split-Payload      CHAIN      A number variable using var() to reference a
  Chain                         string variable, where the string carries the
                                actual XSS payload --- neither variable looks
                                dangerous in isolation
  ----------------------------------------------------------------------------

**5. D5DSH Plugin Import Tests --- Actual Results**

All 29 fixture files were run through the D5DSH importer on 2026-03-31
using the automated Security Testing panel (plugin v0.1.1, WP 6.9.4, PHP
8.2.27). Each fixture was isolated: the database was snapshotted and
restored between each run. Results are grouped below by fixture
category.

  ---------------------------------------------------------------------------------------------------------------------------------------
  **Fixture**                                    **Status**   **New**   **Updated**   **Sanitized**   **Notes**
  ---------------------------------------------- ------------ --------- ------------- --------------- -----------------------------------
  test-vars-poison-strings.json                  PASS         10        0             4               script tag → empty; PHP tag →
                                                                                                      empty; iframe → empty; CSS break →
                                                                                                      partial (\"Read More\" kept, script
                                                                                                      stripped)

  test-vars-poison.json                          PASS         20        2             8               PHP tags in labels → empty; null
                                                                                                      byte stripped; all 4 XSS color
                                                                                                      labels/values → empty

  test-vars-poison-no-null-byte.json             PASS         20        0             11              All XSS/PHP in variable labels and
                                                                                                      values → empty; data: URI payloads
                                                                                                      stripped to scheme only; SVG onload
                                                                                                      in color label → \"TEST\" (text
                                                                                                      kept)

  test-vars-poison-colors.json                   PASS         8         0             1               XSS label → empty; all other color
                                                                                                      values (CSS expr, broken varref,
                                                                                                      overlong, attr-break, javascript:,
                                                                                                      ZWS) → PASSED unchanged

  test-vars-poison-fonts.json                    PASS         7         0             2               Script tag in font value → empty;
                                                                                                      XSS label → empty; all others (CSS
                                                                                                      break, url(javascript:), HTML
                                                                                                      entity, path traversal) → PASSED

  test-vars-poison-links.json                    PASS         9         0             2               data:text/html and
                                                                                                      data:image/svg+xml payloads →
                                                                                                      scheme only; javascript: URI,
                                                                                                      vbscript:, file://, SSRF, open
                                                                                                      redirect → PASSED

  test-vars-poison-links-extra.json              PASS         8         0             3               Double-encoded path: %% decoded
                                                                                                      (ENCODED); tab-split javascript: →
                                                                                                      spaces inserted (PARTIAL, not
                                                                                                      blocked); XSS label → empty; BiDi,
                                                                                                      IDN, null-in-URL → PASSED

  test-vars-poison-images.json                   PASS         9         0             2               data:text/html and
                                                                                                      data:image/svg+xml → scheme only;
                                                                                                      javascript:, SSRF, file://, path
                                                                                                      traversal, attr-break, base64-SVG →
                                                                                                      PASSED

  test-vars-poison-images-extra.json             PASS         6         0             3               SVG foreignObject stripped to
                                                                                                      scheme; SVG use-href HTML-encoded
                                                                                                      (PARTIAL); XSS label → empty;
                                                                                                      overlong base64, content-sniff,
                                                                                                      CSS-break-URL → PASSED

  test-vars-poison-numbers.json                  PASS         8         0             2               XSS payload in string chain var →
                                                                                                      empty; CSS style-break → \"1.8em\"
                                                                                                      (suffix stripped, PARTIAL); all CSS
                                                                                                      function payloads → PASSED

  test-vars-poison-numbers-extra.json            PASS         7         0             1               XSS label → empty; all numeric/CSS
                                                                                                      edge cases → PASSED

  test-vars-poison-num-css-break.json            PASS         1         0             0               CSS block break stored verbatim ---
                                                                                                      NO CRASH (plugin imports without
                                                                                                      rendering)

  test-vars-poison-num-clamp-expr.json           PASS         1         0             0               CSS expression() stored verbatim
                                                                                                      --- PASSED

  test-vars-poison-num-calc-break.json           PASS         1         0             0               calc() with !important and
                                                                                                      javascript: URL → PASSED

  test-vars-poison-num-min-inject.json           PASS         1         0             0               min() with CSS injection → PASSED

  test-vars-poison-num-style-break.json          PASS         1         0             1               \"1.8em\</style\>\<script\>\...\" →
                                                                                                      \"1.8em\" --- script suffix
                                                                                                      stripped, PARTIAL

  test-vars-poison-num-var-chain.json            PASS         2         0             1               XSS payload in string side of chain
                                                                                                      → empty; number var() reference →
                                                                                                      PASSED --- chain broken on string
                                                                                                      side

  test-vars-poison-num-css-chain.json            PASS         2         0             0               Both chain vars stored verbatim ---
                                                                                                      split-payload chain survives D5DSH
                                                                                                      import

  test-vars-poison-structural.json               EXCEPTION    0         0             0               TypeError: sanitize_and_log()
                                                                                                      expects string, array given ---
                                                                                                      triggered by
                                                                                                      gvid-struct-array-value. Bug fixed
                                                                                                      in v0.1.1 patch.

  test-vars-poison-size.json                     PASS         50        0             0               50 entries imported cleanly

  test-vars-poison-size-500.json                 PASS         500       0             0               500 entries --- no limit reached

  test-vars-poison-size-5000.json                PASS         5000      0             0               5,000 entries --- no limit reached

  test-vars-clean.json                           PASS         0         21            0               Clean baseline --- all 21 updated
                                                                                                      cleanly

  test-vars-import-over-existing-same-ids.json   PASS         0         7             0               Merge confirmed

  test-vars-import-over-existing-partial.json    PASS         0         2             0               Partial import merges correctly

  test-vars-precision-deletion.json              PASS         4         6             0               Unknown type and invalid prefix
                                                                                                      entries silently dropped

  test-vars-export-carrier.json                  PASS         11        0             0               All 11 carrier entries imported ---
                                                                                                      CSS break stored verbatim (no crash
                                                                                                      because no rendering occurs during
                                                                                                      import)

  test-presets-poison-names.json                 PASS         7         0             1               XSS in preset name → empty;
                                                                                                      attr-break, unicode, dupe names →
                                                                                                      PASSED (stored verbatim)

  test-presets-poison-structural.json            PASS         7         0             0               All 7 structural presets imported
                                                                                                      without error
  ---------------------------------------------------------------------------------------------------------------------------------------

**Summary: 28/29 PASS. 1 EXCEPTION (fixed in v0.1.1). 42 fields sanitized
across the full run.**

**6. D5DSH-Specific Findings**

**6.1 What D5DSH Sanitizes (confirmed by test)**

> • Bare \<script\>, \<iframe\>, and PHP open tags (\<?php, \<?=) in all
> string, font, and label fields --- stripped to empty string
>
> • Null bytes --- stripped by sanitize_text_field()
>
> • data:text/html and data:image/svg+xml URI payloads --- inner payload
> stripped, scheme kept (PARTIAL)
>
> • CSS style-break in format \"value\</style\>\<script\>...\" --- script
> suffix stripped, leading value kept
>
> • XSS in preset names --- stripped to empty

**6.2 Known Limitations (PASSED in testing)**

The following attack categories are stored verbatim by D5DSH because
sanitize_text_field() does not provide context-aware validation for
these types. They represent areas for future hardening:

> • Attribute-break quotes in labels and values (e.g., Normal\"
> onmouseover=\"alert(1)\") --- sanitize_text_field() does not strip
> quote characters in value context
>
> • javascript: URI in image and color variable fields --- no URL scheme
> validation implemented
>
> • SSRF-capable URLs (AWS metadata endpoint, localhost) --- URLs stored
> as strings and not fetched, so direct SSRF risk is negligible; stored
> values could theoretically be consumed by another plugin
>
> • Open redirect URLs (//evil.com) in link variables
>
> • file:// URI in image variables
>
> • Path traversal sequences (../../) in image and font fields --- stored
> as strings, not used as file paths by D5DSH
>
> • CSS block break in number values (e.g. 16px; } body { display:none })
> --- D5DSH stores verbatim without rendering; the value does not crash
> the importer but would affect rendering downstream
>
> • Split-payload CSS-injection chain: var() reference in number +
> CSS block break in string --- both sides stored verbatim; cross-variable
> analysis is not performed
>
> • Double-encoded path traversal (%252F) --- sanitize_text_field()
> partial-decodes (%25→%), leaving %2F; neither stored form is
> immediately exploitable via D5DSH's own code paths
>
> • CSS function payloads in number variables: clamp(), calc(), min() ---
> stored verbatim
>
> • tab-split javascript: evasion --- tab converted to space by
> sanitize_text_field(), producing \"java script:\" (not fully
> neutralized)
>
> • Serialized PHP strings in value fields --- stored as plain strings;
> not passed to unserialize() by D5DSH
>
> • BiDi override (U+202E), IDN homograph domains, zero-width spaces in
> labels --- Unicode spoofing; stored verbatim

**6.3 Bugs Fixed During Testing**

> • **TypeError in sanitize_and_log()** (v0.1.1 patch) --- non-string
> values (arrays, null) passed to this method now coerced to an empty
> string rather than throwing a TypeError. Triggered by the structural
> fixture containing \"value\": \[\"this\",\"is\",\"an\",\"array\"\].

**6.4 Recommendations for Future Work**

> 1\. **URL scheme validation** for link, image, and color variable
> fields --- block javascript: and file:// at import time
>
> 2\. **CSS value validation** for number and font fields --- reject or
> strip bare \`}\` characters outside CSS function contexts
>
> 3\. **User-facing entry count warning** --- display before/after counts
> when unknown-type or invalid-prefix entries are silently dropped
>
> 4\. **Render test** --- import test-vars-poison-num-css-chain.json,
> apply the number variable on a Divi module, inspect the rendered
> \<style\> block to confirm whether the var() resolution triggers the
> CSS block break at render time
>
> 5\. **Export carrier re-test** --- remove the CSS block break entry from
> test-vars-export-carrier.json and re-run to confirm the remaining
> carrier entries (javascript: in images, CSS expression in colors,
> attr-break in labels, font CSS break, and the CSS-injection chain)
> spread via the export→import→export chain

**7. Version History of This Document**

See version table at the top of this file.
