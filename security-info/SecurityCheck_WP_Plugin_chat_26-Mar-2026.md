# WordPress Plugin Security Review Checklist

*Prepared from chat guidance on March 26, 2026*

This document captures the WordPress-specific security review process
discussed in chat for a WordPress/Divi plugin written in PHP,
JavaScript, and CSS. The goal is a practical review method that combines
automated scanning, manual code review, privilege testing, and
remediation.

## Review Structure

• Treat the work as a structured review rather than a single scan.

• Use four phases: automated static analysis, manual review of
WordPress-specific attack surfaces, runtime testing, and remediation
with re-test.

• Anchor the review to core WordPress principles: validate input,
sanitize where needed, escape on output, enforce capability checks, use
nonces for intent verification, and prefer WordPress APIs over custom
security logic.

## Step 1: Inventory Entry Points

• Create a list of every place untrusted data can enter the plugin.

• Typical entry points include admin settings pages, AJAX actions, REST
routes, shortcode or module attributes, block settings, URL parameters,
form posts, file uploads, remote API responses, and any value read from
the database and later rendered.

## Step 2: Ask Three Questions for Every Handler

• Who is allowed to perform this action?

• Was the request intentionally made from the plugin's UI?

• Is every piece of data validated or sanitized before use and escaped
at output?

## Core Manual Review Areas

## A. Authorization and Capability Checks

• Any feature that changes settings, writes data, performs an action, or
reveals non-public information should verify the correct capability on
the server side.

• Common checks include current_user_can(\'manage_options\') or a
narrower capability that matches the action.

• Do not rely on the admin UI being hidden; endpoints must be protected
even if a menu item is not visible.

## B. CSRF Protection with Nonces

• State-changing actions in admin or authenticated workflows should
include nonce generation and nonce verification.

• Use wp_nonce_field() when rendering forms and check_admin_referer() or
an equivalent verification method when handling the request.

• Nonces verify request intent; they do not replace capability checks.

## C. Input Handling: Validation First, Sanitization as Needed

• Prefer strict validation where possible, such as allowlists for fixed
options and integer checks for numeric values.

• Review direct use of \$\_GET, \$\_POST, \$\_REQUEST, and \$\_FILES.

• Normalize shortcode attributes, REST parameters, and option values
before use or storage.

## D. Output Escaping

• Escape late, at the point of output, using the correct function for
the context.

• Typical mappings are esc_html() for text nodes, esc_attr() for
attributes, esc_url() for URLs, esc_textarea() for textareas, and
wp_kses() or wp_kses_post() for intentionally limited HTML.

• Flag any raw echo of variables in templates, builder previews, admin
pages, or inline JavaScript.

## E. SQL Safety

• Review any direct database access for proper parameterization.

• Use \$wpdb-\>prepare(), \$wpdb-\>insert(), \$wpdb-\>update(), and
related helpers instead of concatenating user-controlled values into
SQL.

• Pay special attention to LIKE queries and ensure esc_like() is used
where appropriate before prepare().

## F. REST API Review

• Confirm custom routes are registered on rest_api_init.

• Each route should have a real permission_callback that matches the
sensitivity of the action or data.

• Review each argument for validation and sanitization, and watch for
accidental public exposure of settings, logs, tokens, or other internal
data.

## G. AJAX and admin-post Handlers

• Review all wp_ajax\_\*, wp_ajax_nopriv\_\*, and admin_post\_\*
handlers for capability checks, nonce checks, request validation, and
safe response formatting.

• These handlers are a common vulnerability area because they can often
be called directly.

## H. File Handling

• If the plugin uploads, imports, exports, writes, deletes, or reads
files, verify strict file type checks, destination path safety, and
access controls.

• Avoid arbitrary file write or delete behavior and do not allow
executable or unsafe files through upload or import workflows.

## I. External Requests and Secrets

• Use fixed or allowlisted remote endpoints whenever possible.

• Do not trust data returned by third-party APIs without validation.

• Ensure API keys, tokens, and other secrets are not exposed in admin
HTML, REST responses, JavaScript globals, or logs.

## Automated Checks to Run

• Run PHPCS with WordPress Coding Standards. This is particularly useful
for escaping issues, nonce handling, and direct superglobal access.

• Run a general static analysis tool such as Semgrep or SonarQube Cloud
to supplement the WordPress-focused review.

• Use dependency scanning for vulnerable packages and libraries.

## Runtime Testing

• Create at least four test states: logged out, lowest-privileged user,
editor-level user, and administrator.

• Test each privileged feature through the UI, by directly calling the
AJAX endpoint, by directly calling the REST route, by omitting the
nonce, by replaying the request as a lower-privileged user, and by
sending malformed or hostile input.

• Look for privilege escalation, CSRF, stored XSS, reflected XSS,
unauthorized data access, insecure direct object access, unsafe file
actions, and SQL injection.

## Suggested Findings Log

• Document issues in a simple table with columns for area, file or
function, risk, evidence, and recommended fix.

• This makes remediation and retesting much easier.

## Minimum Release Standard

• Before outside testing, all request data should be validated or
sanitized, all output should be escaped in context, all state-changing
actions should use both capability checks and nonces, REST routes should
have reviewed permission callbacks, SQL should be parameterized, PHPCS
or WPCS should be run clean or with justified exceptions, and
low-privilege manual testing should be complete.

## Divi-Specific Practical Focus

• For a WordPress/Divi plugin, give extra attention to admin settings
pages, AJAX handlers, REST routes, shortcode or module attributes,
builder-preview rendering, and stored option values later echoed into
HTML, JavaScript, or CSS.

• These are the places where WordPress plugins most commonly fail.

## Sample Findings Table

  ----------------------------------------------------------------------------------------------------------
  **Area**       **File / Function**               **Risk**       **Evidence**          **Fix**
  -------------- --------------------------------- -------------- --------------------- --------------------
  Capability     admin-save.php::save_settings()   High           A lower-privileged    Add a server-side
  check missing                                                   user can submit the   current_user_can()
                                                                  endpoint directly and check before
                                                                  change settings.      processing.

  Output not     views/settings-page.php           High           Stored script content Escape in context
  escaped                                                         executes in admin     using esc_html(),
                                                                  when an option value  esc_attr(), or the
                                                                  is rendered.          correct alternative.

  REST route too register_routes()                 High           permission_callback   Replace with a
  open                                                            effectively allows    capability-aware
                                                                  public access to      permission callback
                                                                  protected data.       and validate
                                                                                        arguments.
  ----------------------------------------------------------------------------------------------------------

---

*Source: [github.com/akonsta/d5-design-system-helper](https://github.com/akonsta/d5-design-system-helper)*
