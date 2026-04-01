**D5 Design System Helper --- Security Test Report**

Applies to: Divi 5 import/export and D5 Design System Helper plugin
import/export

Status: Divi native tests complete (§5, §8). D5DSH plugin tests complete
(§9). Pending: render tests, export-carrier re-run, follow-up items.

**Version History**

  -----------------------------------------------------------------------------
  **Version**   **Date**     **Summary**
  ------------- ------------ --------------------------------------------------
  0.1           2026-03-30   Initial report: Divi 5 native import/export tests
                             for strings, links, images, and number variables
                             (split-file phase). D5DSH plugin tests pending.

  0.2           2026-03-31   Added Divi native results for colors, fonts,
                             links-extra, images-extra, numbers-extra,
                             structural edge cases, size/volume, and
                             CSS-injection split-payload chain. Updated key
                             findings and next steps.

  0.3           2026-03-31   Created 9 new fixture files for pending tests: 3
                             preset poison files (names, attrs, structural),
                             precision deletion, 2 import-over-existing, 2 size
                             scale-up (500/5000 entries), and export
                             carrier/cross-site spread. §8 updated to describe
                             each test and its methodology.

  0.4           2026-03-31   Phase 3 results recorded in §8: presets poison
                             names/attrs/structural, precision deletion
                             (confirmed silent 3-entry removal),
                             import-over-existing (results inconclusive on
                             first run --- DB was reset), size scale-up to
                             5,000 entries (no limit found), export carrier
                             (blocked by CSS break payload). Key new findings:
                             presets attrs importer performs stricter schema
                             validation than variables importer; presets
                             structural malformed entry triggers save failure.

  0.5           2026-03-31   Re-tested §8.5 (import over existing) without DB
                             reset. CONFIRMED: Divi import is merge not replace
                             --- all 9 baseline vars not in the import file
                             survived. UNEXPECTED: color values were NOT
                             overwritten even though variable values were ---
                             colors appear append-only. Color override behavior
                             flagged for follow-up test.

  0.6           2026-03-31   D5DSH plugin import tests completed via automated
                             Security Testing panel (v0.1.1). All 29 fixtures
                             run. 28/29 PASS; 1 exception (TypeError in
                             structural fixture --- non-string value passed to
                             sanitize_and_log). 42 fields sanitized across the
                             full run. Full D5DSH results in §9. Bug fix
                             applied: sanitize_and_log now guards against
                             array/null values.

  0.7           2026-03-31   Responded to 5-point external critique of §7.1
                             findings. Agreed on 4 of 5 points; defended 1.
                             Updated §7.1: reclassified template injection,
                             serialised PHP, and path traversal from Low to
                             Medium (WordPress shared-data-layer / PHP Object
                             Injection risk); added second-order SQL injection
                             language and upgraded SQL risk from Low to
                             Low-Medium; added WAF-evasion framing to
                             double-encoded path traversal finding; downgraded
                             CSS expression() to informational and added
                             modern-browser note. Added SAFE_DEFAULT result
                             code to §3 taxonomy to distinguish structural
                             normalization (null/missing/wrong-type fields)
                             from BLOCKED (payload detected and stripped) and
                             PASSED (payload stored verbatim). Recoded four
                             §5.10 rows accordingly.
  -----------------------------------------------------------------------------

**1. Purpose and Scope**

This document records security tests conducted against the Divi 5 native
import/export function and the D5 Design System Helper (D5DSH) plugin
import/export function. The goal is to determine:

> 1\. **What Divi 5 sanitizes** when importing global variables via its
> own import/export UI.
>
> 2\. **What D5DSH sanitizes** when importing the same payloads via the
> plugin\'s Import tab.
>
> 3\. **What gaps remain** in either system that could allow malicious
> content to reach the database or be rendered in a browser.

Tests are conducted on a local WordPress site (Local by Flywheel) and do
not affect any production environment. All test fixture files are stored
in the project repository under tests/fixtures/security/.

**2. Test Environment**

  -----------------------------------------------------------------------
  **Item**           **Detail**
  ------------------ ----------------------------------------------------
  WordPress          Local by Flywheel --- local development environment

  Divi version       5.x (current at time of testing)

  D5DSH version      0.1.1 (Security Testing panel, automated fixture
                     runner)

  Test fixture       tests/fixtures/security/
  directory          

  Clean baseline     test-vars-clean.json
  file               

  Database reset     Local by Flywheel database reset between each test
  method             

  Export after each  Exported via Divi Theme Options → Export immediately
  test               after import
  -----------------------------------------------------------------------

**3. Test Methodology**

Each test follows this sequence:

> 1\. **Reset:** Restore the database to a clean known state.
>
> 2\. **Import:** Import a single poison test file via the importer
> under test.
>
> 3\. **Observe:** Check for errors, blank screens, or other visible
> failures.
>
> 4\. **Export:** Export all global variables immediately after import.
>
> 5\. **Compare:** Compare the exported file against the imported file
> to determine what survived sanitization.

Each poison file is isolated to a single attack category so that the
culprit of any failure can be pinpointed. Where a test causes a crash,
the database is reset before proceeding.

**Result codes used in tables:**

  -----------------------------------------------------------------------
  **Code**    **Meaning**
  ----------- -----------------------------------------------------------
  BLOCKED     Value was stripped to empty string --- attack neutralized

  PARTIAL     Some payload was removed but the variable entry still
              exists with residual content

  PASSED      Value stored exactly as imported --- potential
              vulnerability, no sanitization applied

  ENCODED     Value was URL-encoded rather than stripped --- quote
              neutralized but payload survives in encoded form

  SAFE_DEFAULT  Structural edge case (missing field, null, wrong type)
                was safely normalized to an empty string or type-
                appropriate default. Not a sanitization action --- no
                malicious payload was present. Distinct from BLOCKED,
                which means a payload was detected and stripped.

  CRASH       Import caused the Divi builder to go blank or database to
              become corrupted

  UNTESTED    Test not yet run
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
  Request Forgery               (AWS metadata endpoint, localhost) that Divi
                                or WP might fetch server-side

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

**5. Divi 5 Native Import/Export Tests**

These tests were conducted using the Divi Theme Options import/export
UI. The import file was uploaded directly through Divi\'s own interface,
not through the D5DSH plugin.

**Note:** Divi rejected files containing extra top-level JSON keys (such
as \_meta, \_structural_attacks) as invalid. All test files were
therefore formatted using only the standard Divi export envelope: the
context, data, presets, global_colors, global_variables, canvases,
images, and thumbnails keys.

**Note:** The null byte test (null bytes embedded in variable id, label,
and value fields) was tested separately. It caused the Divi builder to
go blank on next page load and required a full database reset. It is
documented in §5.1 as CRASH and excluded from subsequent combined tests.

**5.1 String Variables (test-vars-poison-text.json)**

Ten string-type global variables were imported, each containing a
different payload. The export after import was compared field by field.

  -----------------------------------------------------------------------------------------------------
  **Variable ID**            **Attack**   **Imported Value (abbreviated)**   **Exported    **Result**
                                                                             Value**       
  -------------------------- ------------ ---------------------------------- ------------- ------------
  gvid-str-script-tag        XSS-1        \<script\>alert(\...)\</script\>   Empty string  BLOCKED

  gvid-str-attr-break        XSS-2 / ATTR \" onmouseover=\"alert(\...)       Value         PASSED
                                                                             unchanged     

  gvid-str-template-inject   Template     {{7\*7}}                           Value         PASSED
                             injection                                       unchanged     

  gvid-str-el-inject         EL injection \${7\*7}                           Value         PASSED
                                                                             unchanged     

  gvid-str-serialized        SER-1        O:8:\"stdClass\":1:{\...}          Value         PASSED
                                                                             unchanged     

  gvid-str-path-traversal    PATH-1       ../../wp-config.php                Value         PASSED
                                                                             unchanged     

  gvid-str-sqli              SQLI-1       1234 Main St\'; DROP TABLE         Value         PASSED
                                          wp_options; \--                    unchanged     

  gvid-str-php-tag           PHP-1        \<?php system(\'id\'); ?\>         Empty string  BLOCKED

  gvid-str-iframe            XSS-3        \<iframe src=\'javascript:\...\'\> Empty string  BLOCKED

  gvid-str-css-break         CSS-1        Read More\</style\>\<script\>\...  Read More     PARTIAL
                                                                             (suffix       
                                                                             stripped)     

  gvid-null-byte             NULL         Null bytes in id, label, value     Builder blank CRASH
                                                                             on next load  
  -----------------------------------------------------------------------------------------------------

Summary: Divi blocks bare HTML tags (script, iframe, PHP open tags). It
does not sanitize attribute-break quotes, template/EL injection syntax,
serialized PHP, path traversal, or SQL injection. The CSS break was
partially mitigated. Null bytes caused a crash.

**5.2 Link Variables (test-vars-poison-links.json)**

  --------------------------------------------------------------------------------------------------
  **Variable ID**           **Attack**   **Imported Value (abbreviated)** **Exported    **Result**
                                                                          Value**       
  ------------------------- ------------ -------------------------------- ------------- ------------
  gvid-lnk-js-uri           XSS-4        javascript:alert(\...)           Empty string  BLOCKED

  gvid-lnk-data-html        XSS-5        data:text/html,\<script\>\...    Empty string  BLOCKED

  gvid-lnk-data-svg         XSS-6        data:image/svg+xml,\<svg         Empty string  BLOCKED
                                         onload=\...                                    

  gvid-lnk-vbscript         XSS-7        vbscript:msgbox(1)               Empty string  BLOCKED

  gvid-lnk-open-redirect    REDIR        //evil.example.com/steal?c=      Value         PASSED
                                                                          unchanged     

  gvid-lnk-ssrf-metadata    SSRF-1       http://169.254.169.254/\...      Value         PASSED
                                                                          unchanged     

  gvid-lnk-ssrf-localhost   SSRF-2       http://localhost/wp-admin/\...   Value         PASSED
                                                                          unchanged     

  gvid-lnk-file-uri         PATH-2       file:///etc/passwd               Empty string  BLOCKED

  gvid-lnk-attr-break       ATTR         https://example.com\"            URL-encoded   ENCODED
                                         onclick=\...                     (quote → %20) 
  --------------------------------------------------------------------------------------------------

Summary: Divi blocks javascript:, vbscript:, data:, and file:// schemes
in link variables. It does not block protocol-relative open redirects or
SSRF URLs (AWS metadata, localhost). Attribute-break quotes were
URL-encoded (payload survives in encoded form).

**5.3 Image Variables (test-vars-poison-images.json)**

  ----------------------------------------------------------------------------------------------------------
  **Variable ID**           **Attack**   **Imported Value (abbreviated)** **Exported Value**    **Result**
  ------------------------- ------------ -------------------------------- --------------------- ------------
  gvid-img-data-html        XSS-5        data:text/html,\<script\>\...    data:text/html,       PARTIAL
                                                                          (payload stripped,    
                                                                          scheme kept)          

  gvid-img-data-svg         XSS-6        data:image/svg+xml,\<svg         data:image/svg+xml,   PARTIAL
                                         onload=\...                      (payload stripped,    
                                                                          scheme kept)          

  gvid-img-js-uri           XSS-4        javascript:alert(\...)           Value unchanged       PASSED

  gvid-img-ssrf-metadata    SSRF-1       http://169.254.169.254/\...      Value unchanged       PASSED

  gvid-img-ssrf-localhost   SSRF-2       http://localhost/wp-admin/\...   Value unchanged       PASSED

  gvid-img-file-uri         PATH-2       file:///etc/passwd               Value unchanged       PASSED

  gvid-img-path-traversal   PATH-1       ../../wp-config.php              Value unchanged       PASSED

  gvid-img-attr-break       ATTR         photo.jpg\"                      Value unchanged       PASSED
                                         onerror=\"alert(\...)                                  

  gvid-img-data-b64-svg     XSS-6b       data:image/svg+xml;base64,\...   Value unchanged ---   PASSED
                                                                          executes JS on load   
  ----------------------------------------------------------------------------------------------------------

Summary: Images are the weakest point in Divi\'s sanitization. The
javascript: URI is blocked in links but passes unmodified in images.
Base64-encoded SVG data URIs (which execute JavaScript on load in all
modern browsers) pass through completely. Divi partially strips data:
URIs by removing the payload after the comma, but keeps the scheme ---
this is inconsistent and incomplete. SSRF, file://, path traversal, and
attribute-break payloads all pass.

**5.4 Number Variables --- Individual Test Results**

The combined numbers poison file caused the Divi builder to go blank on
next page load, requiring a full database reset. Tests were re-run using
six individual files, one payload per file. All six tests completed with
results as follows.

  ---------------------------------------------------------------------------------------------------------------------------
  **File**          **Attack**   **Payload (abbreviated)**                                    **Exported       **Result**
                                                                                              Value**          
  ----------------- ------------ ------------------------------------------------------------ ---------------- --------------
  num-css-break     CSS-2        16px; } body { display:none; } .evil {                       Builder blank    CRASH
                                                                                              --- database     
                                                                                              reset required   

  num-clamp-expr    CSS-3        clamp(1px, expression(alert(\...)), 100px)                   Value unchanged  PASSED

  num-calc-break    CSS-4        calc(8px + 0) !important;                                    Value unchanged  PASSED
                                 background:url(javascript:alert(1))                                           

  num-var-chain     CHAIN        Number: var(\--gvid-str-xss-payload, 1px) --- String:        Number:          PARTIAL ---
                                 \</style\>\<script\>\...                                     unchanged /      chain broken
                                                                                              String: empty    by string
                                                                                                               sanitization

  num-min-inject    CSS-5        min(4vw, 60px); } .injected { color: red                     Value unchanged  PASSED

  num-style-break   XSS-8        1.8em\</style\>\<script\>alert(\'style-break\')\</script\>   1.8em --- script PARTIAL
                                                                                              tag stripped,    
                                                                                              CSS value kept   
  ---------------------------------------------------------------------------------------------------------------------------

**Confirmed crash culprit:** test-vars-poison-num-css-break.json. The
payload is a plain CSS block break: a semicolon followed by a closing
brace (}) with no CSS function wrapping. Divi injects number variable
values directly into a \<style\> block without CSS-grammar escaping. The
bare } closes the current CSS rule, corrupting the style block Divi uses
to initialize the Visual Builder. The builder cannot recover and renders
a blank screen.

Key finding --- CSS grammar vs. HTML tag detection: Divi partially
handles the \</style\> HTML tag (stripping the script content in
num-style-break) but has no awareness of CSS grammar. A bare } inside a
number value is structurally more dangerous than \</style\> and goes
completely unchecked. Similarly, CSS functions (clamp, calc, min) and
!important pass through unmodified.

Key finding --- split-payload chain: The var() reference in the number
variable survived (PASSED) but the XSS payload in the companion string
variable was blocked because it contained a \<script\> tag. The chain is
broken by Divi\'s HTML-tag filtering on the string side --- but only
because the payload used HTML syntax. A string payload using pure CSS
injection (no HTML tags) would survive and complete the chain via the
var() reference.

**5.5 Color Variables (test-vars-poison-colors.json)**

Eight color entries were imported. Colors are stored in a separate
option (et_divi\[et_global_data\]\[global_colors\]) as \[id, {color,
status, label}\] pairs. All eight entries survived import. Findings:

  -----------------------------------------------------------------------------------------------------------------------
  **Variable ID**                 **Attack**   **Imported Value / Label**                    **Exported      **Result**
                                                                                             Value / Label** 
  ------------------------------- ------------ --------------------------------------------- --------------- ------------
  gcid-test-homoglyph             SPOOF        Label: \"Рrimary\" (Cyrillic Р U+0420)        Label:          PASSED
                                                                                             \"Рrimary\" --- 
                                                                                             unchanged       

  gcid-test-css-expression        CSS-6        Color: rgba(0,0,0);                           Color: stored   PASSED
                                               expression(alert(document.cookie))            verbatim        

  gcid-test-broken-varref         STRUCT       Color:                                        Stored verbatim PASSED
                                               \$variable({\...gcid-does-not-exist\...})\$   --- broken ref  
                                                                                             preserved in DB 

  gcid-test-overlong              SIZE         Color: \"#\" + 262 \"a\" chars                Stored verbatim PASSED
                                                                                             --- no length   
                                                                                             cap             

  gcid-test-color-xss-label       XSS          Label: \<script\>alert(\'NUM-EX-1: XSS in     Label: exported PARTIAL
                                               color label\')\</script\>                     as \"#0000ff\"  
                                                                                             (color value    
                                                                                             used as label   
                                                                                             --- script      
                                                                                             stripped)       

  gcid-test-color-attr-break      ATTR         Label: Normal\"                               Label: stored   PASSED
                                               onmouseover=\"alert(\'COLOR-2\')              verbatim        
                                                                                             including       
                                                                                             injected        
                                                                                             attribute       
                                                                                             string          

  gcid-test-color-js-value        XSS          Color: javascript:alert(\'COLOR-3\')          Color: stored   PASSED
                                                                                             verbatim        

  gcid-test-color-null-stripped   UNICODE      Label with four U+200B zero-width spaces      Label: stored   PASSED
                                                                                             verbatim        
                                                                                             including all   
                                                                                             U+200B chars    
  -----------------------------------------------------------------------------------------------------------------------

Summary: Color values receive virtually no sanitization. CSS
expression() payloads, javascript: URIs, broken variable references,
overlong values, attribute-break quotes, and Unicode tricks all pass
through. The XSS label was partially handled --- the script tag was
stripped but the label field was replaced with the color value, which is
an unexpected and undocumented behavior. Zero-width spaces survive,
enabling silent Unicode spoofing of color names.

**5.6 Font Variables (test-vars-poison-fonts.json)**

Seven font-type variables were imported. Font variables store a font
family name string. Results:

  -----------------------------------------------------------------------------------------------------------------------------------------------
  **Variable ID**              **Attack**   **Imported Value**                              **Exported Value**                       **Result**
  ---------------------------- ------------ ----------------------------------------------- ---------------------------------------- ------------
  gvid-font-css-string-break   CSS-7        \'; font-family: inherit; content: \'           Value unchanged                          PASSED

  gvid-font-url-inject         XSS          Arial, url(javascript:alert(\...))              Value unchanged                          PASSED

  gvid-font-html-entity        XSS          Arial&lt;script&gt;alert(\...)&lt;/script&gt;   Stored as                                PARTIAL
                                                                                            Arial&lt;script&gt;\...&lt;/script&gt;   
                                                                                            --- HTML-encoded, not stripped           

  gvid-font-script-tag         XSS          Impact\<script\>alert(\'FONT-4: script in font  Stored as empty string --- script tag    BLOCKED
                                            value\')\</script\>                             stripped, font name discarded            

  gvid-font-css-break          CSS-8        Georgia; } body { display:none; } .evil {       Value unchanged --- same CSS block break PASSED
                                                                                            pattern as numbers crash, but no crash   
                                                                                            here                                     

  gvid-font-xss-label          XSS          Label: \<script\>alert(\'FONT-6: XSS in font    Label: empty string --- script stripped  BLOCKED
                                            label\')\</script\>                                                                      

  gvid-font-path-traversal     PATH         ../../wp-content/uploads/evil.ttf               Value unchanged                          PASSED
  -----------------------------------------------------------------------------------------------------------------------------------------------

Summary: Font values containing bare script tags are stripped to empty.
HTML-entity-encoded payloads are stored as-is (double-decode risk if
rendered via innerHTML). The CSS block break (Georgia; } body {\...})
passed through for fonts --- the same pattern that caused a crash in
number variables. This inconsistency suggests the crash was specific to
how number variables are emitted in the style block, not a general
CSS-grammar crash. The javascript: url() injection and CSS string-break
also passed through. Path traversal survived.

**Notable:** The CSS block break payload (Georgia; } body { display:none
}) passes in font variables but causes a crash in number variables. Both
values are injected into the same style block --- the difference in
behavior needs further investigation. It may be related to variable
rendering order or how Divi prefixes number vs. font variable CSS
declarations.

**5.7 Link Variables --- Additional
(test-vars-poison-links-extra.json)**

Eight additional link attack vectors were tested, targeting evasion
techniques not covered in §5.2.

  ----------------------------------------------------------------------------------------------------------------------------------------
  **Variable ID**           **Attack**   **Imported Value**                                    **Exported Value**             **Result**
  ------------------------- ------------ ----------------------------------------------------- ------------------------------ ------------
  gvid-lnk-bidi-override    SPOOF        URL with U+202E (BiDi RIGHT-TO-LEFT OVERRIDE)         Stored with U+202E intact ---  PASSED
                                                                                               spoofed URL direction          
                                                                                               preserved                      

  gvid-lnk-idn-homograph    SPOOF        https://evil.сom/steal (Cyrillic с)                   Stored verbatim --- homograph  PASSED
                                                                                               domain preserved               

  gvid-lnk-double-encoded   PATH         https://example.com%252F..%252Fwp-config.php          Stored verbatim ---            PASSED
                                                                                               double-encoding preserved      

  gvid-lnk-data-b64-html    XSS          data:text/html;base64,\... (base64 script)            Empty string --- data: scheme  BLOCKED
                                                                                               blocked                        

  gvid-lnk-tab-newline      XSS          java\\tscript:alert(\...) (tab-split scheme)          Empty string --- blocked       BLOCKED
                                                                                               despite tab evasion            

  gvid-lnk-unicode-js       XSS          ⓙavascript:alert(\...) (Unicode ⓙ = j)                Empty string --- blocked       BLOCKED
                                                                                               despite Unicode evasion        

  gvid-lnk-null-in-url      NULL         https://example.com/page.php\\u0000../../etc/passwd   https://example.com/page.php   PARTIAL
                                                                                               --- null byte stripped, URL    
                                                                                               truncated                      

  gvid-lnk-xss-label        XSS          Label: \<script\>alert(\'LNK-EX-3: XSS in link        Label: empty string --- script BLOCKED
                                         label\')\</script\>                                   stripped                       
  ----------------------------------------------------------------------------------------------------------------------------------------

Summary: Divi\'s javascript: filter is robust --- it handles tab-split
schemes and Unicode character substitutions (circled j ⓙ). The data:
block is also robust for link variables. BiDi override characters, IDN
homograph domains, and double-encoded paths all pass unmodified,
enabling phishing and URL-spoofing attacks. The null byte was cleanly
stripped in the URL context, truncating the path traversal tail. The
double-encoded path (%252F) is a WAF evasion technique: a single-pass
decoder sees no `../` and passes the request; the application decodes
%2F → / on a second pass, reconstructing the traversal sequence. Storing
%252F verbatim (as Divi does) means any downstream URL-processing code
that decodes it will reconstruct the slash.

**5.8 Image Variables --- Additional
(test-vars-poison-images-extra.json)**

  ------------------------------------------------------------------------------------------------------------------------------------------------
  **Variable ID**               **Attack**   **Imported Value**                                          **Exported Value**           **Result**
  ----------------------------- ------------ ----------------------------------------------------------- ---------------------------- ------------
  gvid-img-svg-foreignobject    XSS          data:image/svg+xml,\<svg\>\<foreignObject\>\<script\>\...   data:image/svg+xml, --- SVG  PARTIAL
                                                                                                         payload stripped, scheme     
                                                                                                         kept                         

  gvid-img-overlong-b64         SIZE         data:image/png;base64, + 900+ char string                   Stored verbatim in full ---  PASSED
                                                                                                         no length limit applied      

  gvid-img-svg-use-href         XSS          data:image/svg+xml,\<svg\>\<use href=\"data:\...#x\"/\>     HTML-encoded SVG kept:       PARTIAL
                                                                                                         data:image/svg+xml,&lt;use   
                                                                                                         href=\...                    

  gvid-img-content-type-sniff   SSRF         https://example.com/image.php?file=evil.js                  Stored verbatim              PASSED

  gvid-img-xss-label            XSS          Label: \<script\>alert(\'IMG-EX-3: XSS in image             Label: empty string          BLOCKED
                                             label\')\</script\>                                                                      

  gvid-img-css-break-url        CSS          https://example.com/img.jpg\');                             Stored verbatim --- CSS      PASSED
                                             background:url(javascript:alert(\...);//                    break in URL value passes    
  ------------------------------------------------------------------------------------------------------------------------------------------------

Summary: SVG content inside data: URIs is partially stripped (script and
foreignObject payload removed, scheme preserved) but the SVG use-href
skeleton is HTML-encoded and stored --- not fully blocked. There is no
length limit on image values. The CSS break in a URL value (closing a
CSS url() call and injecting a new background rule) passes through
completely. Content-type sniff URLs are not validated.

**5.9 Number Variables --- Additional
(test-vars-poison-numbers-extra.json)**

  ----------------------------------------------------------------------------------------------------
  **Variable ID**              **Attack**   **Imported Value**            **Exported      **Result**
                                                                          Value**         
  ---------------------------- ------------ ----------------------------- --------------- ------------
  gvid-num-negative-overflow   CSS          -999999                       Stored verbatim PASSED

  gvid-num-huge-value          SIZE         999999999px                   Stored verbatim PASSED

  gvid-num-scientific          CSS          1e9px                         Stored verbatim PASSED

  gvid-num-unit-confusion      CSS          100% important                Stored verbatim PASSED

  gvid-num-css-comment         CSS-9        1px /\* } body { display:none Stored verbatim PASSED
                                            } \*/                         --- CSS comment 
                                                                          hiding a block  
                                                                          break survives  

  gvid-num-unicode-escape      CSS          1\\65 x (CSS unicode escape   Stored verbatim PASSED
                                            for \"ex\")                                   

  gvid-num-xss-label           XSS          Label:                        Label: empty    BLOCKED
                                            \<script\>alert(\'NUM-EX-1:   string          
                                            XSS in number                                 
                                            label\')\</script\>                           
  ----------------------------------------------------------------------------------------------------

Summary: All numeric edge cases pass through. A CSS comment wrapping a
block break (/\* } body { \... } \*/) survives --- this is a more subtle
version of the confirmed CSS-break crash vector. The comment syntax may
prevent the immediate crash while preserving an injection path in
browsers that support CSS comments in custom property values. Scientific
notation, huge values, negative values, and Unicode CSS escapes all pass
unmodified. XSS in labels is blocked.

**5.10 Structural Edge Cases (test-vars-poison-structural.json)**

  -------------------------------------------------------------------------------------------------------------------
  **Variable ID**                  **Attack**   **Scenario**                           **Exported        **Result**
                                                                                       Result**          
  -------------------------------- ------------ -------------------------------------- ----------------- ------------
  gvid-struct-10k-entries-anchor   STRUCT       Anchor --- present to confirm 10k test Present in export PASSED
                                                loaded                                                   

  gvid-struct-dupe-id (×2)         STRUCT       Two entries with identical ID ---      Only second       PASSED
                                                first: \"first\", second: \"Second     definition        
                                                Definition --- duplicate ID\"          exported ---      
                                                                                       last-write-wins   
                                                                                       confirmed         

  gvid-struct-unknown-type         STRUCT       Unknown variableType: \"exploit\"      Silently dropped  BLOCKED
  (\"exploit\")                                                                        --- not present   
                                                                                       in export         

  gcid-wrong-prefix-for-gvid       STRUCT       gcid- prefix on a non-color variable   Silently dropped  BLOCKED
                                                (type: strings)                        --- not present   
                                                                                       in export         

  totally-wrong-prefix-xyz         STRUCT       No valid prefix (neither gcid- nor     Silently dropped  BLOCKED
                                                gvid-)                                 --- not present   
                                                                                       in export         

  gvid-struct-missing-fields       STRUCT       Missing value field entirely           Imported with     SAFE_DEFAULT
                                                                                       value: \"\" ---
                                                                                       empty string
                                                                                       default applied

  gvid-struct-null-value           STRUCT       value: null                            Imported with     SAFE_DEFAULT
                                                                                       value: \"\" ---
                                                                                       null coerced to
                                                                                       empty string

  gvid-struct-array-value          STRUCT       value:                                 Imported with     SAFE_DEFAULT
                                                \[\"this\",\"is\",\"an\",\"array\"\]   value: \"\" ---
                                                                                       array coerced to
                                                                                       empty string

  gvid-struct-integer-value        STRUCT       value: 42 (integer)                    Imported with     SAFE_DEFAULT
                                                                                       value: \"42\" ---
                                                                                       integer coerced
                                                                                       to string
  -------------------------------------------------------------------------------------------------------------------

Summary: Divi validates prefix and type on import. Entries with unknown
types or invalid ID prefixes are silently dropped --- Divi does not warn
the user. Duplicate IDs result in last-write-wins behavior (the final
entry with that ID survives). Missing, null, and wrong-type values are
safely normalized to empty strings or the appropriate type (SAFE_DEFAULT)
--- these are structural edge cases, not attack vectors, and the
normalization behavior is correct. The silent dropping of unknown-type
entries means an attacker could use a plausible but mismatched type to
cause a variable to disappear after import without triggering any visible
error.

**5.11 Size / Volume Test (test-vars-poison-size.json)**

Fifty number variables with clean values (10px each) were imported to
test volume handling. All 50 entries were imported and exported
successfully with no errors, no truncation, and no performance
degradation visible in the UI. There is no apparent per-import entry
count limit at 50 entries.

Next step: expand to 500 and 10,000 entries to establish where (if
anywhere) Divi\'s importer hits a limit.

**5.12 CSS-Injection Split-Payload Chain
(test-vars-poison-num-css-chain.json)**

This test verifies the critical split-payload attack variant identified
in §5.4: a number variable referencing a string variable via var(),
where the string carries a pure CSS injection payload (no HTML tags that
Divi would strip).

  -------------------------------------------------------------------------------------------
  **Variable ID**          **Type**   **Imported Value**            **Exported   **Result**
                                                                    Value**      
  ------------------------ ---------- ----------------------------- ------------ ------------
  gvid-num-css-chain-ref   numbers    var(\--gvid-str-css-poison,   Stored       PASSED
                                      1px)                          verbatim     

  gvid-str-css-poison      strings    1px; } body { background:     Stored       PASSED
                                      red; display: block; } .et-db verbatim     
                                      #et-boc .et-l .et-pb-section               
                                      {                                          
  -------------------------------------------------------------------------------------------

Both variables survived import completely unchanged. When Divi renders
these as CSS custom properties, the behavior is:

> 1\. **Divi emits:** \--gvid-str-css-poison: 1px; } body { background:
> red; display: block; } .et-db #et-boc .et-l .et-pb-section {
>
> 2\. **Then emits:** \--gvid-num-css-chain-ref:
> var(\--gvid-str-css-poison, 1px);

At CSS parse time, the var() reference resolves to the string value,
which contains an unescaped closing brace. Whether this triggers the
same crash as the direct CSS block break (§5.4) depends on when the
custom property is resolved --- at definition time (which would crash)
or at use time (which CSS custom properties defer until inherited).
Further testing is needed to confirm whether rendering this combination
crashes the builder or executes the CSS injection silently.

**Critical finding:** This attack cannot be detected by inspecting
either variable in isolation. The number variable looks like a
legitimate var() reference. The string variable looks like a malformed
but innocuous CSS value. Only by tracing the var() dependency graph can
the combined effect be identified. Neither Divi nor D5DSH currently
performs this cross-variable analysis.

**6. D5 Design System Helper Plugin Import Tests --- Predictions**

This section records the pre-test predictions made from code review
before any D5DSH tests were run. Actual results are in §9.

The D5DSH plugin uses WordPress\'s sanitize_text_field() function for
all variable label, value, and status fields, and sanitize_key() for id
fields. Based on code review, the expected behaviour differs from
Divi\'s in the following ways:

  ------------------------------------------------------------------------
  **Attack**         **Expected   **Reason**
                     D5DSH        
                     result**     
  ------------------ ------------ ----------------------------------------
  XSS --- bare       BLOCKED      sanitize_text_field() strips HTML tags
  script/iframe tags              

  XSS ---            PARTIAL      sanitize_text_field() encodes special
  attribute-break                 chars but does not validate as
  quotes                          URL/attribute context

  PHP tags (\<?php)  BLOCKED      sanitize_text_field() strips angle
                                  brackets

  SQL injection in   PASSED       No SQL-specific validation; values
  string fields                   stored as-is via update_option() with WP
                                  serialization --- low DB risk

  Path traversal     PASSED       No path validation for string/image/link
  (../../)                        values

  javascript: URI in PASSED       No URL scheme validation implemented for
  links                           link-type variables

  javascript: URI in PASSED       No URL scheme validation implemented for
  images                          image-type variables

  SSRF URLs          PASSED       No SSRF protection; URLs are stored as
  (metadata,                      strings not fetched
  localhost)                      

  file:// URI        PASSED       No URL scheme validation

  Open redirect      PASSED       No URL scheme validation
  (//evil.com)                    

  Serialized PHP in  PASSED       Stored as a plain string; only dangerous
  values                          if passed to unserialize()

  Base64 SVG data    PASSED       No content-type validation for image
  URI                             fields

  CSS break in       PASSED       Number values run through
  number values                   sanitize_text_field() --- not CSS-aware

  Null bytes         BLOCKED      sanitize_text_field() strips null bytes
  ------------------------------------------------------------------------

**Important:** The above are predictions based on code review only.
Actual test results are in §9. Discrepancies between predictions and
results are noted there.

**7. Key Findings to Date**

**7.1 Divi Native Sanitization Gaps**

The following attack vectors pass through Divi\'s native import without
sanitization and reach the database:

> • Attribute-break quotes in string and color label values (e.g.,
> Normal\" onmouseover=\"alert(1)\")
>
> • Template injection syntax ({{7\*7}}, \${7\*7}) --- **Medium risk**:
> harmless unless a template engine (Twig, Blade, etc.) is active, but
> WordPress plugins that apply Divi variable values to templates would
> make Divi the carrier for server-side template injection without any
> change to the import file
>
> • Serialized PHP objects and arrays --- **Medium risk**: `wp_options`
> is shared across all plugins and themes; any plugin that calls
> `unserialize()` on a Divi option row can trigger PHP Object Injection
> from a payload that Divi stored verbatim. The risk is not limited to
> Divi's own rendering path.
>
> • Path traversal sequences (../../wp-config.php) --- **Medium risk**:
> harmless unless another plugin reads a Divi variable value and uses it
> to build a file path (e.g. a file-include or template-loader plugin),
> making Divi the delivery mechanism for the traversal payload
>
> • SQL injection fragments --- **Low--Medium risk**: WordPress
> `wpdb::prepare()` prevents first-order injection at the point Divi
> writes the value, but this is a second-order SQL injection vector ---
> if any other plugin reads the stored value via `get_option()` and
> interpolates it into a raw query string, the payload executes with no
> further obstacle. Divi is the carrier; the exploit fires in another
> plugin's code.
>
> • Protocol-relative open redirect URLs (//evil.com) in link variables
>
> • SSRF-capable URLs (AWS instance metadata endpoint, localhost) in
> link and image variables
>
> • javascript: URI in image variables (blocked in links but missed in
> images)
>
> • file:// URI in image variables (blocked in links but missed in
> images)
>
> • javascript: URI as a color value --- color variables receive no
> URL-scheme validation
>
> • CSS expression() in color values --- passes verbatim. **Note:**
> `expression()` is a Microsoft CSS extension removed in IE8 (2009); it
> does not execute in any modern browser and poses negligible risk
> compared to the base64 SVG vector (§5.8), which executes JavaScript in
> all current browsers. This finding is retained for completeness but
> should not be treated as a current-threat priority.
>
> • Broken \$variable()\$ references in color values --- dangling
> references silently stored
>
> • Overlong color values --- no length cap enforced
>
> • Zero-width space (U+200B) and BiDi override (U+202E) Unicode tricks
> in labels --- enable visual spoofing
>
> • IDN homograph domains in link values --- Cyrillic lookalike
> characters pass through
>
> • Double-encoded path traversal in link values (%252F) --- **WAF
> evasion technique**: `%252F` is URL-decoded to `%2F` on the first pass
> through a WAF or input filter that only decodes once; the WAF sees no
> `../` pattern and passes the payload. The application then decodes
> `%2F` → `/` on the second pass, reconstructing the traversal sequence.
> This is a standard evasion pattern, not merely an obscure encoding
> inconsistency.
>
> • CSS string break in font values (\`\'; font-family: inherit;
> content: \'\`) --- passes through
>
> • url(javascript:\...) in font stack values --- passes through
>
> • CSS block break in font values (Georgia; } body { display:none })
> --- passes through for fonts (does not crash, unlike numbers)
>
> • Path traversal in font file URLs (../../wp-content/uploads/evil.ttf)
>
> • onerror attribute break in image URLs
>
> • Base64-encoded SVG data URIs (data:image/svg+xml;base64,\...) ---
> executes JS in all modern browsers when used as img src
>
> • CSS break inside a CSS url() context in image values (\`img.jpg\');
> background:url(javascript:alert(\...))\`)
>
> • Plain CSS block breaks in number variable values (e.g. 16px; } body
> { display:none }) --- confirmed to crash the Divi Visual Builder
>
> • CSS comment wrapping a block break in number values (1px /\* } body
> { display:none } \*/) --- subtler variant, passes without crash
>
> • CSS function payloads in number variables: clamp() with
> expression(), calc() with !important and javascript: URL, min() with
> CSS injection --- all pass without sanitization
>
> • Negative, huge, scientific notation, and unit-confusion number
> values --- all pass without validation
>
> • CSS-injection split-payload chain: var() reference in number + CSS
> block break in string (no HTML tags) --- both sides pass,
> cross-variable attack undetectable in isolation

**7.2 Divi Native Sanitization Strengths**

Divi correctly blocks the following:

> • Bare \<script\> tags in string, font, color label, and number label
> values
>
> • \<iframe\> tags with javascript: src
>
> • PHP open tags (\<?php, \<?=)
>
> • javascript: URI scheme in link variables
>
> • vbscript: URI scheme in link variables
>
> • data: URI scheme in link variables (fully blocked)
>
> • file:// URI scheme in link variables
>
> • Tab-split and Unicode-substituted javascript: evasion attempts in
> link variables
>
> • Entries with unknown variableType values --- silently dropped on
> import
>
> • Entries with invalid ID prefixes (neither gcid- nor gvid-) ---
> silently dropped on import
>
> • Null bytes in URLs --- stripped cleanly in link context

**7.3 Inconsistency Between Variable Types**

Divi applies materially different sanitization rules to different
variable types. Key inconsistencies confirmed by testing:

> • javascript: is blocked in link variables but passes unmodified in
> image and color variables
>
> • file:// is blocked in link variables but passes unmodified in image
> variables
>
> • data: URIs are fully blocked in link variables but only partially
> stripped in image variables (scheme survives, payload stripped)
>
> • CSS block break (} body { display:none }) causes a crash in number
> variables but passes silently in font variables --- same value,
> different behavior
>
> • Script tags are stripped from string and font values but color value
> fields receive no HTML-tag filtering at all

This inconsistency is systematically exploitable: an attacker selects
the variable type that applies the least sanitization for their chosen
payload.

**7.4 CSS-Injection Split-Payload Chain --- Confirmed Viable**

Both sides of the CSS-injection chain survived import intact (§5.12):

> • Number variable: var(\--gvid-str-css-poison, 1px) --- stored
> verbatim
>
> • String variable: 1px; } body { background: red; display: block; }
> .et-db #et-boc .et-l .et-pb-section { --- stored verbatim

Neither variable is individually dangerous. The attack assembles at CSS
render time when Divi emits both as custom properties in the same style
block. This attack bypasses all of Divi\'s HTML-tag-based filtering
because it uses no HTML syntax. Cross-variable analysis (tracing var()
dependency graphs) is required to detect it --- a capability not
currently present in Divi or D5DSH.

**7.5 Structural Behaviors Confirmed**

> • Duplicate IDs: last-write-wins --- earlier definition silently
> overwritten
>
> • Unknown type values: silently dropped --- no user warning
>
> • Invalid ID prefixes: silently dropped --- no user warning
>
> • Missing value field: coerced to empty string
>
> • Null, array, and integer values: coerced to empty string or string
> representation

The silent dropping of entries (unknown type, wrong prefix) means a
malicious import could selectively remove variables from a design system
without generating any visible error. A user importing a file that
silently deletes three variables would have no indication unless they
manually counted entries before and after.

**8. Divi Native Results --- Phase 3**

**8.1 Presets --- Poison Names (test-presets-poison-names.json)**

Result: IMPORTED SUCCESSFULLY. Export file: export
test-presets-poison-names.json.

Note: Divi\'s export of a preset-only import returns presets as an empty
list. The preset import and export surface appear to be separate from
the variables export context. Preset names were accepted without
modification by the importer UI.

  -------------------------------------------------------------------------------
  **Preset ID**            **Attack**             **Result**
  ------------------------ ---------------------- -------------------------------
  preset-xss-name          XSS --- script tag in  UNTESTED --- export showed
                           name                   empty presets array; cannot
                                                  confirm whether name survived
                                                  or was stripped

  preset-attr-break-name   ATTR --- quote break   UNTESTED --- same: export
                           in name                returned empty presets

  preset-dupe-name-a/b     STRUCT --- duplicate   UNTESTED --- same
                           names                  

  preset-unicode-name      SPOOF --- Cyrillic     UNTESTED --- same
                           homoglyph              
  -------------------------------------------------------------------------------

Finding: Divi exports presets and variables from different menus. To
observe what the importer stores in preset name fields, export via the
Presets-specific export context (not the Global Variables export
context).

**8.2 Presets --- Poison Attribute Values
(test-presets-poison-attrs.json)**

Result: FAILED --- Divi rejected the file with: \"Sorry, you are not
allowed to upload this file type.\"

This is the same file-type rejection seen when extra top-level keys are
added to the envelope, or when the JSON structure fails schema
validation before Divi\'s sanitization layer is reached. The
attrs/styleAttrs fields with embedded CSS injection payloads triggered a
schema or MIME validation check before any sanitization was applied.
This is a stronger defense than the variables importer --- Divi\'s
preset importer appears to validate the attribute object structure
before accepting the file.

Finding: The preset attrs importer performs stricter structural
validation than the variables importer. The exact rejection criterion
(MIME type, JSON schema depth, or specific field value) is not yet
determined. This warrants a follow-up test with a structurally clean
attrs object to confirm the importer works at all, then introducing
payloads one field at a time.

**8.3 Presets --- Structural Edge Cases
(test-presets-poison-structural.json)**

Result: PARTIAL --- file was accepted by the importer, but a modal
appeared immediately after import:

\"Save of Global Presets Has Failed --- An error has occurred while
saving the Global Presets settings. Various problems can cause a save to
fail, such as a lack of server resources, firewall blockages or plugin
conflicts or server misconfiguration.\"

The importer accepted the file but the subsequent save to the database
failed. The export file (export test-presets-poison-structural.json)
showed an empty presets list --- consistent with a failed save. The
error message is generic and does not identify which structural entry
triggered the failure.

  --------------------------------------------------------------------------------
  **Entry**             **Likely     **Reasoning**
                        culprit?**   
  --------------------- ------------ ---------------------------------------------
  preset-null-name      Possible     null where PHP expects a string ---
                                     serialize() may fail or DB write may reject

  preset-integer-name   Possible     Integer type coercion may break Divi\'s
                                     internal preset registry

  preset-array-attrs    Likely       Array where Divi expects an object in attrs
                                     --- most likely to cause a PHP-level save
                                     failure

  preset-dupe-id (×2)   Possible     JSON duplicate keys: second definition
                                     silently overwrites first before PHP receives
                                     it

  preset-unknown-type   Less likely  Divi may simply ignore unknown type values
                                     rather than failing on them
  --------------------------------------------------------------------------------

Recommended follow-up: split into individual fixture files (one entry
per file) to isolate the crash culprit, the same method used to identify
the CSS block break in the numbers test.

**8.4 Precision Variable Deletion (test-vars-precision-deletion.json)**

Result: IMPORTED SUCCESSFULLY. Export file: export
test-vars-precision-deletion.json.

Imported: 10 entries (7 clean, 3 drop-inducing). Exported: 14 entries
total (7 from the test file + 7 pre-existing site variables from the
test environment).

  -----------------------------------------------------------------------------------
  **Entry ID**                       **Type used**    **Survived?**
  ---------------------------------- ---------------- -------------------------------
  gvid-test-spacing-xs/sm/md/lg/xl   numbers (valid)  YES --- all 5 survived

  gvid-test-radius-sm                numbers (valid)  YES

  gvid-anchor-precision-deletion     strings (valid)  YES

  gvid-target-for-deletion-1         type:            DROPPED silently --- no warning
                                     \"exploit\"      
                                     (unknown)        

  gcid-wrong-prefix-target           strings with     DROPPED silently --- no warning
                                     gcid- prefix     

  totally-invalid-prefix-xyz         strings, no      DROPPED silently --- no warning
                                     valid prefix     
  -----------------------------------------------------------------------------------

CONFIRMED: All 3 malicious entries were silently deleted. All 7 clean
entries survived. No user-visible warning was generated. This confirms
that a carefully crafted import file can surgically remove any target
variables from a design system without the site owner being aware.

**8.5 Import Over Existing Data --- Merge Behavior Confirmed
(test-vars-import-over-existing-same-ids.json)**

Result: IMPORTED SUCCESSFULLY. Re-test export file: \"export vars clean
and import over existing same ids.json\".

Test procedure: imported test-vars-clean.json first (13 vars, 8 colors),
then WITHOUT resetting the database imported
test-vars-import-over-existing-same-ids.json (5 vars, 2 colors with
changed values). Export showed 29 vars and 13 colors.

  -----------------------------------------------------------------------
  **Finding**           **Detail**
  --------------------- -------------------------------------------------
  CONFIRMED: Divi       All 9 baseline variables not present in the
  import is MERGE, not  import file SURVIVED in the export with their
  replace               original values intact

  Variable overwrite    gvid-test-spacing-xs: 4px → 999px;
  accepted              gvid-test-spacing-sm: 8px → 999px;
                        gvid-test-font-body: Inter → Comic Sans MS;
                        gvid-test-site-name: Acme Corp → HACKED

  New entry added       gvid-new-entry-not-in-baseline = \"I did not
                        exist before\" --- present in export

  UNEXPECTED: Color     gcid-test-brand-primary: import set #ff0000 but
  overwrite did NOT     export shows original #1a73e8.
  apply                 gcid-test-brand-secondary: import set #00ff00 but
                        export shows original #e8470a. Colors were NOT
                        overwritten even though variables were.

  All 8 baseline colors All TEST-prefixed colors retained their original
  survived              values regardless of import content
  -----------------------------------------------------------------------

Key finding: Divi\'s variable importer merges --- existing entries not
present in the import file are preserved. This is consistent with the
behavior observed when importing Divi\'s own Freebie files. However,
there is a notable inconsistency: variable values are overwritten on
import but color values are NOT. Colors appear to be treated as
append-only (new entries added, existing entries left untouched).

**8.6 Import Over Existing Data --- Partial Import
(test-vars-import-over-existing-partial.json)**

Result: IMPORTED SUCCESSFULLY. Export file: export
test-vars-import-over-existing-partial.json.

NOTE: This test was run on a reset database, so merge vs. replace was
not testable. What is confirmed: the 2 imported variables were stored
correctly, and Divi\'s built-in system defaults (gcid-primary-color
etc.) survived, consistent with merge. A re-test without DB reset (same
method as §8.5) is pending to confirm full merge behavior for partial
imports.

**8.7 Scale-Up Size Tests**

  -------------------------------------------------------------------------------------------
  **File**                          **Entries**   **Result**      **Anchor in export?**
  --------------------------------- ------------- --------------- ---------------------------
  test-vars-poison-size-500.json    500           IMPORTED        YES --- all 500 entries
                                                  SUCCESSFULLY    present in export (507
                                                                  total including
                                                                  pre-existing)

  test-vars-poison-size-5000.json   5,000         IMPORTED        YES --- all 5,000 entries
                                                  SUCCESSFULLY    present in export (5,007
                                                                  total)
  -------------------------------------------------------------------------------------------

Finding: Divi imposes no practical entry-count limit up to 5,000
variables. The wp_options row storing 5,000 entries at \~100 bytes each
is approximately 500 KB --- within WordPress\'s autoload threshold but
not negligible. Sites with very large variable sets may experience
autoload performance degradation on every page load, though no error was
triggered during the test.

**8.8 Export Poisoning / Cross-Site Spread
(test-vars-export-carrier.json)**

Result: FAILED --- blank screen (same failure mode as
test-vars-poison-num-css-break.json in Phase 2).

The export carrier file includes a number variable with the CSS block
break payload (16px; } body { display:none; } .evil {), which was the
confirmed crash culprit from §5.4. This file intentionally included that
payload to test cross-site spread of crash-inducing content. The blank
screen confirms: (1) the CSS block break crashes Divi\'s builder
reliably regardless of what file it originates from, and (2) the export
carrier test as designed cannot proceed while this payload is present.

Recommended fix for next run: remove the CSS block break entry from
test-vars-export-carrier.json and retest. The remaining 7 carrier
entries (javascript: in images, CSS expression in colors, attr-break in
labels, font CSS break, and the CSS-injection chain) should all survive
import and export, establishing whether they spread across the
export→import chain.

**9. D5 Design System Helper Plugin Import Tests --- Actual Results**

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
                                                                                                      rendering, so Divi builder crash
                                                                                                      does not apply)

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

  test-vars-import-over-existing-same-ids.json   PASS         0         7             0               Merge confirmed --- same behavior
                                                                                                      as Divi native

  test-vars-import-over-existing-partial.json    PASS         0         2             0               Partial import merges correctly

  test-vars-precision-deletion.json              PASS         4         6             0               Same behavior as Divi native ---
                                                                                                      unknown type and invalid prefix
                                                                                                      entries silently dropped

  test-vars-export-carrier.json                  PASS         11        0             0               All 11 carrier entries imported ---
                                                                                                      CSS break stored verbatim (no crash
                                                                                                      because no rendering occurs during
                                                                                                      import)

  test-presets-poison-names.json                 PASS         7         0             1               XSS in preset name → empty;
                                                                                                      attr-break, unicode, dupe names →
                                                                                                      PASSED (stored verbatim)

  test-presets-poison-structural.json            PASS         7         0             0               All 7 structural presets imported
                                                                                                      without error --- no save failure
                                                                                                      (contrast: Divi native crashed on
                                                                                                      this file)
  ---------------------------------------------------------------------------------------------------------------------------------------

**9.1 D5DSH vs. Divi Native --- Key Differences**

D5DSH sanitizes using WordPress\'s sanitize_text_field() on all variable
labels and values. This produces consistent but context-unaware
sanitization. Key differences from Divi native:

  ---------------------------------------------------------------------------------------------------
  **Attack / Scenario**                 **Divi Native**   **D5DSH     **Notes**
                                                          Plugin**    
  ------------------------------------- ----------------- ----------- -------------------------------
  Script tags in string/font/label      BLOCKED           BLOCKED     Both strip to empty ---
  values                                                              consistent

  PHP open tags (\<?php, \<?=)          BLOCKED           BLOCKED     Both strip to empty ---
                                                                      consistent

  iframe with javascript: src           BLOCKED           BLOCKED     Both strip to empty ---
                                                                      consistent

  CSS style-break                       PARTIAL           PARTIAL     Both keep \"1.8em\", strip the
  (\"1.8em\</style\>\<script\>\...\")                                 script suffix --- consistent

  Attribute-break quotes in             PASSED            PASSED      sanitize_text_field() does not
  labels/values                                                       strip quote characters in value
                                                                      context

  javascript: URI in                    PASSED            PASSED      No URL scheme validation in
  links/images/colors                   (images/colors)               D5DSH --- same gap as Divi for
                                                                      images/colors

  data: URI payloads                    PARTIAL (scheme   PARTIAL     Both strip inner payload, keep
                                        kept)             (scheme     data: prefix --- consistent
                                                          kept)       

  CSS block break in number values      CRASH             PASSED (no  D5DSH imports without rendering
                                                          crash)      --- the crash occurs in Divi\'s
                                                                      builder, not the importer. The
                                                                      value is stored verbatim and
                                                                      will crash Divi when rendered.

  Split-payload CSS chain (var() + CSS  PASSED            PASSED      Both sides stored verbatim ---
  block break in string)                                              cross-variable chain not
                                                                      detected by either system

  Structural preset save failure        CRASH/FAIL        PASS        D5DSH handled null names,
                                                                      integer names, and array attrs
                                                                      without exception after bug fix

  Array value in variable field         N/A               EXCEPTION → TypeError in v0.1.1; bug fixed
                                                          FIXED       --- array now coerced to empty
                                                                      string

  Tab-split javascript: evasion in      BLOCKED           PARTIAL     Divi blocks entirely; D5DSH
  links                                                   (tab →      converts tab to space ---
                                                          space)      payload survives as \"java
                                                                      script:\"

  Double-encoded path traversal (%252F) PASSED            ENCODED (%% WAF-evasion technique:
  [WAF evasion]                                           decoded)    single-pass decoders miss the
                                                                      traversal; D5DSH partial-decodes
                                                                      via sanitize_text_field (%25→%)
                                                                      leaving %2F as literal slash;
                                                                      neither system fully neutralises

  Null bytes                            BLOCKED           BLOCKED     sanitize_text_field() strips
                                                                      null bytes --- consistent

  Unknown variableType / invalid ID     DROPPED (silent)  DROPPED     Both drop silently ---
  prefix                                                  (silent)    consistent

  5,000 variable import                 PASS (no limit)   PASS (no    Both handle large imports
                                                          limit)      without errors --- consistent
  ---------------------------------------------------------------------------------------------------

**9.2 D5DSH-Specific Findings**

> • The CSS block break crash is a Divi rendering issue, not an import
> issue. D5DSH stores the dangerous value verbatim. Any site using D5DSH
> to import design system data that contains a CSS block break will have
> that value in the database --- and Divi\'s builder will crash when it
> renders the variable.
>
> • The split-payload CSS-injection chain (§5.12) survives D5DSH import
> in full. The number var() reference and the CSS block break string are
> both stored unchanged. D5DSH does not perform cross-variable
> dependency analysis.
>
> • The tab-split javascript: evasion is only partially mitigated:
> sanitize_text_field() converts the tab to a space, producing \'java
> script:alert()\' --- not a valid URI scheme but also not fully
> neutralized as Divi achieves.
>
> • Double-encoded path traversal (%252F) is decoded by
> sanitize_text_field(), converting %25 → %, which then leaves %2F as a
> literal slash. This is a WAF evasion technique: a WAF doing single-pass
> URL-decode sees no `../` pattern and passes the request; the second
> decode happens at the application layer, reconstructing the traversal
> sequence. D5DSH's result (partial decode) differs from Divi (stored
> verbatim) but neither fully neutralises the payload.
>
> • The structural preset fixture that caused Divi\'s builder to
> save-fail (test-presets-poison-structural.json) imported cleanly in
> D5DSH. This is because D5DSH uses its own preset import path which
> does not pass through Divi\'s preset registry save callback.
>
> • SVG onload in a color label produced \'TEST\' (the text content of
> the SVG was kept) rather than empty string --- sanitize_text_field()
> strips tags but keeps text nodes.

**10. Next Steps**

> 1\. **Divi native tests --- Phase 1, 2, and 3:** COMPLETE. Results in
> §5 (Phase 1--2) and §8 (Phase 3).
>
> 2\. **D5DSH plugin import tests:** COMPLETE. Results in §9. 28/29
> PASS; 1 exception fixed.
>
> 3\. **Fix test-vars-export-carrier.json** --- remove
> gvid-carrier-num-css-break, re-run to confirm the remaining carrier
> entries spread via the export→import→export chain. (The CSS block
> break entry stored in DB will crash Divi\'s builder when rendered ---
> this is the vector to test.)
>
> 4\. **Isolate preset structural crash culprit (Divi native)** ---
> split test-presets-poison-structural.json into individual one-entry
> files to identify which entry triggers Divi\'s save failure. D5DSH
> handled all entries without error.
>
> 5\. **Presets attrs follow-up (Divi native)** ---
> test-presets-poison-attrs.json was rejected by Divi outright. Create a
> structurally clean version with known-good attr values, confirm it
> imports, then add payloads one at a time to find the exact validation
> trigger.
>
> 6\. **Presets names export (Divi native)** --- re-import
> test-presets-poison-names.json and export via the Presets-specific
> context to observe what survives in name fields.
>
> 7\. **CSS-injection chain render test** --- SETUP REQUIRED. Import
> test-vars-poison-num-css-chain.json, apply gvid-num-css-chain-ref on a
> Divi module, inspect the rendered \<style\> block to confirm whether
> the var() resolution triggers the CSS block break at render time.
>
> 8\. **Investigate color overwrite behavior** --- §8.5 confirmed
> variable values are overwritten but color values are NOT
> (append-only). Run targeted test to confirm and characterize the exact
> rule.
>
> 9\. **Implement structural filtering in D5DSH** --- add: URL scheme
> validation (links, images, colors), CSS value validation for
> number/font fields (block bare } or ; outside CSS functions), var()
> cross-variable dependency detection, and user-facing warnings with
> entry counts before/after import.
>
> 10\. **Document SERIALIZATION_SPEC.md** --- expand to cover Divi\'s
> export JSON envelope format for vars, presets, layouts, pages, and
> theme customizer.
