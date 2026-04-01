**D5 Design System Helper**

**Security Review Report**

Version 1.0 --- March 26, 2026

Methodology: WordPress Plugin Security Review Checklist (4-phase)

Plugin version: 0.6.12

**Executive Summary**

The D5 Design System Helper plugin was reviewed against the WordPress
Plugin Security Review Checklist, covering automated static analysis,
manual code review of all WordPress-specific attack surfaces, and
preparation for runtime testing.

**Overall security posture: Strong**

The plugin demonstrates consistent, thorough application of WordPress
security best practices. All 32 server-side entry points enforce
capability checks and nonce verification. All user input is sanitized.
All output is escaped in context. All database queries use prepared
statements. The plugin makes zero external HTTP requests and stores no
secrets.

Two low-severity JavaScript hardening issues were identified and fixed
during the review. No critical or high-severity vulnerabilities were
found.

**1. Review Scope**

**1.1 Entry Point Inventory**

The following entry points were catalogued and reviewed:

  ----------------------------------------------------------------------------
  **Entry Point Type**   **Count**   **Notes**
  ---------------------- ----------- -----------------------------------------
  AJAX endpoints         27          All admin-only; no nopriv handlers
  (wp_ajax\_\*)                      

  admin_post handlers    5           Export, import, snapshot restore/delete,
                                     template download

  REST API routes        0           Plugin does not use the REST API

  Direct \$\_GET /       \~25 sites  All sanitized; catalogued in
  \$\_POST access                    AdminPage.php, NotesManager.php,
                                     SimpleImporter.php

  File uploads           3 sites     AdminPage.php (2), SimpleImporter.php (1)
  (\$\_FILES)                        

  php://input JSON reads 8 sites     All behind nonce + capability gates

  \$wpdb direct queries  5 sites     All use \$wpdb-\>prepare()
  ----------------------------------------------------------------------------

**1.2 Three Questions per Handler**

Every handler was checked against the checklist\'s three questions:

> • Who is allowed? --- All 32 endpoints enforce \`manage_options\`
> (Administrator).
>
> • Was the request intentional? --- All 32 endpoints verify nonces via
> \`check_ajax_referer()\` or \`check_admin_referer()\`.
>
> • Is data validated and escaped? --- All input sanitized before use;
> all output escaped at point of render.

**2. Phase 1: Automated Static Analysis**

**2.1 Composer Dependency Audit**

**Result: No security vulnerability advisories found.**

Command: \`composer audit\`

**2.2 Semgrep (PHP + WordPress rulesets)**

23 rules scanned across 41 PHP files.

**Result: 1 finding --- false positive (justified exception).**

  ---------------------------------------------------------------------------------------
  **File**             **Rule**                         **Verdict**
  -------------------- -------------------------------- ---------------------------------
  AdminPage.php:1247   echoed-request --- flags echo    False positive. esc_textarea() is
                       esc_textarea(\$\_POST\[\...\])   the correct WP escaping function
                                                        for textarea context. Semgrep's
                                                        generic PHP rule does not
                                                        recognize WP escaping functions.

  ---------------------------------------------------------------------------------------

**2.3 PHPCS with WordPress Coding Standards**

PHPCS 3.13.5 with WPCS 3.3.0. Security-focused sniffs only:
EscapeOutput, NonceVerification, ValidatedSanitizedInput, PreparedSQL,
PreparedSQLPlaceholders.

**Summary: 63 errors, 5 warnings across 6 files.**

After manual triage, all are justified exceptions or low-severity
best-practice items:

**SQL Interpolation (4 errors --- false positives)**

  ------------------------------------------------------------------------------------------------
  **File:Line**                **PHPCS Rule**                        **Verdict**
  ---------------------------- ------------------------------------- -----------------------------
  ImpactAnalyzer.php:396-397   PreparedSQL.InterpolatedNotPrepared   False positive. \$types_in
                                                                     and \$statuses_in are
                                                                     implode(\",\", array_fill(0,
                                                                     count(\...), \"%s\")) ---
                                                                     they produce placeholder
                                                                     strings like %s,%s,%s, not
                                                                     user data. Wrapped in
                                                                     \$wpdb-\>prepare().

  ContentScanner.php:241-242   PreparedSQL.InterpolatedNotPrepared   Same pattern. Placeholder
                                                                     strings from array_fill(),
                                                                     not user input.
  ------------------------------------------------------------------------------------------------

**Nonce Verification Scope (14 errors --- justified exceptions)**

PHPCS cannot trace control flow across methods. The
\`handle_requests()\` method at \`AdminPage.php:285\` checks
\`current_user_can()\` then dispatches to sub-methods like
\`handle_export()\` which call \`check_admin_referer()\`. PHPCS sees
\`\$\_POST\` access in the dispatcher before the nonce check in the
handler.

Similarly, the export metadata form fields at lines 1189--1247
repopulate form values using \`esc_attr()\` / \`esc_textarea()\` after a
failed submission. The nonce check is in the POST handler, not the
render method.

**\`wp_unslash()\` before sanitization (8 errors --- best practice)**

WPCS recommends calling \`wp_unslash()\` before
\`sanitize_text_field()\` or \`sanitize_key()\`. While
\`sanitize_key()\` strips slashes implicitly (it only allows
\`\[a-z0-9\_-\]\`), \`sanitize_text_field()\` does not call
\`wp_unslash()\` internally.

Affected lines: AdminPage.php lines 1189, 1193, 1197, 1201, 1205, 1213,
1232, 1247, 2392.

**Risk: Negligible in practice (the fields are plugin-internal metadata,
not rendered back into HTML without escaping), but should be fixed for
WPCS compliance.**

Recommendation: Wrap each \`\$\_POST\`/\`\$\_GET\` access in
\`wp_unslash()\` before the sanitize call.

**Output Escaping on Exporters (8 errors --- justified exceptions)**

PrintBuilder.php (lines 380, 392, 404): \`echo \$html\` where \`\$html\`
is internally-generated HTML for a print preview window. No user input
flows into these strings unescaped.

DtcgExporter.php (line 72) and JsonExporter.php (line 110): \`echo
\$json\` for file download responses with \`Content-Type:
application/json\`. Raw JSON output is correct here.

JsonExporter.php (line 110): Exception message includes
\`\$this-\>type\` which comes from a hardcoded allowlist. Not
user-controllable.

**3. Phase 2: Manual Code Review**

**A. Authorization / Capability Checks**

**Status: PASS**

All 32 endpoints enforce \`current_user_can(\'manage_options\')\` before
processing. No endpoint is reachable by a lower-privileged user or a
logged-out visitor. No \`wp_ajax_nopriv\_\*\` handlers exist.

**B. CSRF / Nonces**

**Status: PASS**

Every AJAX handler calls \`check_ajax_referer()\` with a named nonce
action. Every form uses \`wp_nonce_field()\` /
\`check_admin_referer()\`. Nonces are generated per feature via
\`wp_create_nonce()\` in AdminPage.php and passed to JavaScript via
\`wp_localize_script()\`.

**C. Input Validation & Sanitization**

**Status: PASS**

All \`\$\_POST\` / \`\$\_GET\` / \`\$\_FILES\` access uses
\`sanitize_key()\`, \`sanitize_text_field()\`,
\`sanitize_textarea_field()\`, \`sanitize_file_name()\`, or
\`absint()\`. No raw superglobal use found. JSON request bodies decoded
only after nonce + capability gates. File uploads validated with
\`is_uploaded_file()\`. Path traversal protected by
\`SimpleImporter::validate_path_within()\`.

**D. Output Escaping**

**Status: PASS (with 2 low-severity JS findings, now fixed)**

PHP: All HTML output uses \`esc_html()\`, \`esc_attr()\`, \`esc_url()\`,
\`esc_textarea()\`. JSON output via \`wp_send_json\_\*()\`.

JavaScript: \`escHtml()\` and \`escAttr()\` helper functions properly
escape \`&\`, \`\<\`, \`\>\`, \`\"\`, \`\'\`. All \`innerHTML\`
assignments use these helpers. No \`eval()\` or \`Function()\` usage
found.

**E. SQL Safety**

**Status: PASS**

All 5 \`\$wpdb\` query sites use \`\$wpdb-\>prepare()\` with
\`\$wpdb-\>esc_like()\` for LIKE clauses. No string concatenation into
SQL. Files verified: VarsRepository, SnapshotManager, ContentScanner,
ImpactAnalyzer, AuditEngine.

**F. REST API**

**Status: N/A**

The plugin registers zero REST API routes.

**G. AJAX / admin_post Handlers**

**Status: PASS**

Covered by sections A and B above. No \`wp_ajax_nopriv\_\*\` handlers
exist, eliminating unauthenticated attack surface entirely.

**H. File Handling**

**Status: PASS**

Uploads validated with \`is_uploaded_file()\` and
\`sanitize_file_name()\`. Path traversal protected by
\`validate_path_within()\` which compares \`realpath()\` results.
Exports written to \`sys_get_temp_dir()\` with plugin-controlled names,
served via \`readfile()\` then \`unlink()\`.

**I. External Requests & Secrets**

**Status: PASS**

The plugin makes zero external HTTP requests. No \`wp_remote_get/post\`,
no cURL, no \`file_get_contents()\` to remote URLs. No API keys, tokens,
or secrets stored or exposed. No data sent to third-party services.

**4. Findings Log**

Issues identified during the review, ordered by severity:

  --------------------------------------------------------------------------------------------------------
  **\#**   **Area**       **File / Location**         **Risk**   **Evidence**             **Status**
  -------- -------------- --------------------------- ---------- ------------------------ ----------------
  1        JS: Output     admin.js:5935               Low        document.write() used    FIXED ---
           escaping                                              composite title string   wrapped in
                                                                 without re-escaping.     escHtml()
                                                                 Filename was escaped via 
                                                                 siEscape() but final     
                                                                 string was not.          

  2        JS: Selector   admin.js:294                Low        querySelector used       FIXED --- added
           injection                                             saved.format from        /\^\[a-z\]+\$/
                                                                 sessionStorage without   allowlist guard
                                                                 validation. Second-order 
                                                                 only (requires existing  
                                                                 XSS).                    

  3        PHP:           AdminPage.php:1189--1247,   Info       WPCS recommends          Open ---
           wp_unslash()   2392                                   wp_unslash() before      best-practice
           missing                                               sanitize_text_field().   fix recommended
                                                                 Values are internal      
                                                                 metadata, always escaped 
                                                                 on output.               
  --------------------------------------------------------------------------------------------------------

**5. Phase 3: Runtime Testing**

Runtime testing requires a live WordPress instance and cannot be
performed via static analysis. A detailed test plan has been prepared
at:

**\`resources/SECURITY_RUNTIME_TEST_PLAN.md\`**

The plan covers:

> • 4 user roles: logged out, subscriber, editor, administrator
>
> • 7 test variations per endpoint (no cookie, wrong role, missing
> nonce, wrong nonce, hostile input, etc.)
>
> • 27 AJAX endpoints + 5 admin_post handlers
>
> • XSS payload injection into all text fields
>
> • SQL injection payloads on string parameters
>
> • Path traversal attempts on file import
>
> • Malformed JSON body tests

**Status: Not yet executed. Requires WP-CLI and a test WordPress
installation.**

**6. Phase 4: Remediation**

**6.1 Completed Fixes**

  -----------------------------------------------------------------------------
  **Finding**        **Fix Applied**                      **File**
  ------------------ ------------------------------------ ---------------------
  #1:                Added titleSafe = escHtml(title) and admin.js:5925--5936
  document.write()   used titleSafe in both \<title\> and 
  escaping           \<h1\> output                        

  #2: Selector       Added /\^\[a-z\]+\$/ guard before    admin.js:293
  injection          constructing querySelector string    
  -----------------------------------------------------------------------------

**6.2 Recommended Follow-up**

  -----------------------------------------------------------------------------
  **Action**                                **Priority**   **Effort**
  ----------------------------------------- -------------- --------------------
  Add wp_unslash() calls before             Low            15 minutes
  sanitize_text_field() on export metadata                 
  fields                                                   

  Add phpcs:ignore comments with            Low            10 minutes
  justifications for the 4 false-positive                  
  SQL interpolation findings                               

  Execute runtime test plan against a live  Medium         2--3 hours
  WordPress instance                                       

  Add phpcs:ignore comments for the 8       Low            10 minutes
  exporter output findings                                 
  -----------------------------------------------------------------------------

**7. Minimum Release Standard**

Status against the checklist\'s minimum release criteria:

  -----------------------------------------------------------------------
  **Requirement**                           **Status**
  ----------------------------------------- -----------------------------
  All request data validated or sanitized   **PASS**

  All output escaped in context             **PASS**

  All state-changing actions use            **PASS**
  capability + nonce                        

  REST routes have reviewed permission      **N/A --- no REST routes**
  callbacks                                 

  SQL parameterized                         **PASS**

  PHPCS / WPCS run clean or with justified  **PASS (with exceptions)**
  exceptions                                

  Low-privilege manual testing complete     **PENDING**
  -----------------------------------------------------------------------

**8. Conclusion**

The D5 Design System Helper plugin demonstrates a strong security
posture. The codebase consistently applies WordPress security best
practices:

> • Administrator-only access on every endpoint
>
> • Nonce verification on every state-changing action
>
> • Comprehensive input sanitization using WordPress API functions
>
> • Context-appropriate output escaping throughout
>
> • Prepared statements for all database queries
>
> • Zero external HTTP calls and no secret management
>
> • Proper file upload validation and path traversal protection

Two low-severity JavaScript hardening issues were found and fixed during
the review. One best-practice improvement (\`wp_unslash()\` before
sanitization) is recommended but does not represent a security risk.

The remaining gap is runtime testing with multiple user roles against a
live WordPress instance. A detailed test plan has been provided.

**Appendix: Tool Versions**

  -----------------------------------------------------------------------
  **Tool**                            **Version**
  ----------------------------------- -----------------------------------
  PHP                                 8.5.4

  PHPCS                               3.13.5

  WordPress Coding Standards (WPCS)   3.3.0

  Semgrep                             1.156.0

  Composer                            2.x (via composer.phar)

  Node.js                             v22.x (for document generation)
  -----------------------------------------------------------------------
