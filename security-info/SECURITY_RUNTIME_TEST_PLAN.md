# D5 Design System Helper -- Runtime Security Test Plan

Version 1.0 -- March 2026

This plan covers Phase 3 (Runtime Testing) of the WordPress Plugin Security
Review Checklist. Every test must be executed against a live WordPress instance
with the plugin active.

---

## Prerequisites

1. WordPress site with D5 Design System Helper activated.
2. Four user accounts:

   | Role        | Username (suggested) | Capability tested        |
   |-------------|----------------------|--------------------------|
   | None        | (logged out)         | No cookie / no session   |
   | Subscriber  | `sec_subscriber`     | `read` only              |
   | Editor      | `sec_editor`         | `edit_others_posts`      |
   | Admin       | `sec_admin`          | `manage_options`         |

3. WP-CLI available on the server (for user creation and cookie extraction).
4. `curl` for direct HTTP requests.

---

## Setup Script (WP-CLI)

```bash
# Create test users (run once)
wp user create sec_subscriber sub@test.local --role=subscriber --user_pass=TestPass123!
wp user create sec_editor    ed@test.local  --role=editor     --user_pass=TestPass123!
# Admin account already exists

# Get nonces for the admin user (use for baseline / positive tests)
ADMIN_COOKIE=$(wp eval 'wp_set_current_user(1); echo wp_generate_auth_cookie(1, time()+3600);')
```

---

## Test Matrix

Each test below must be run for all four user states. Expected result for
non-admin states is a 403 JSON error or a wp_die message.

### A. AJAX Endpoint Tests

For every endpoint, issue a POST to `admin-ajax.php` with the action name.
Vary the user cookie and nonce to cover the matrix.

```bash
SITE="https://your-site.test"
AJAX="$SITE/wp-admin/admin-ajax.php"

# Template for each endpoint:
# 1. No cookie, no nonce           -> expect 403 or -1
# 2. Subscriber cookie, no nonce   -> expect 403
# 3. Subscriber cookie, valid sub nonce -> expect 403 (wrong cap)
# 4. Editor cookie, valid ed nonce -> expect 403 (wrong cap)
# 5. Admin cookie, NO nonce        -> expect 403 (nonce fail)
# 6. Admin cookie, WRONG nonce     -> expect 403 (nonce fail)
# 7. Admin cookie, valid nonce     -> expect 200 (positive test)
```

#### Endpoints to test

| # | Action | Nonce action string | Method | Body |
|---|--------|---------------------|--------|------|
| 1 | `d5dsh_manage_load` | `d5dsh_manage` | GET (nonce in URL) | `action=d5dsh_manage_load&nonce=NONCE` |
| 2 | `d5dsh_manage_save` | `d5dsh_manage` | POST (JSON body) | `{"action":"d5dsh_manage_save","nonce":"NONCE","items":[]}` |
| 3 | `d5dsh_manage_xlsx` | `d5dsh_manage` | POST (JSON body) | `{"action":"d5dsh_manage_xlsx","nonce":"NONCE"}` |
| 4 | `d5dsh_presets_manage_load` | `d5dsh_presets_manage` | GET | `action=d5dsh_presets_manage_load&nonce=NONCE` |
| 5 | `d5dsh_presets_manage_save` | `d5dsh_presets_manage` | POST (JSON body) | `{"action":"d5dsh_presets_manage_save","nonce":"NONCE","items":[]}` |
| 6 | `d5dsh_presets_manage_xlsx` | `d5dsh_presets_manage` | POST (JSON body) | `{"action":"d5dsh_presets_manage_xlsx","nonce":"NONCE"}` |
| 7 | `d5dsh_categories_load` | `d5dsh_manage` | GET | `action=d5dsh_categories_load&nonce=NONCE` |
| 8 | `d5dsh_categories_save` | `d5dsh_manage` | POST (JSON body) | `{"action":"d5dsh_categories_save","nonce":"NONCE","categories":[]}` |
| 9 | `d5dsh_categories_assign` | `d5dsh_manage` | POST (JSON body) | `{"action":"d5dsh_categories_assign","nonce":"NONCE","assignments":{}}` |
| 10 | `d5dsh_notes_save` | `d5dsh_notes_nonce` | POST | `action=d5dsh_notes_save&nonce=NONCE&key=test&text=hello` |
| 11 | `d5dsh_notes_delete` | `d5dsh_notes_nonce` | POST | `action=d5dsh_notes_delete&nonce=NONCE&key=test` |
| 12 | `d5dsh_notes_get_all` | `d5dsh_notes_nonce` | POST | `action=d5dsh_notes_get_all&nonce=NONCE` |
| 13 | `d5dsh_save_settings` | `d5dsh_settings` | POST (JSON body) | `{"action":"d5dsh_save_settings","nonce":"NONCE","settings":{}}` |
| 14 | `d5dsh_validate` | `d5dsh-validate` | POST (FormData) | `action=d5dsh_validate&nonce=NONCE` |
| 15 | `d5dsh_audit_run` | `d5dsh_audit_nonce` | GET | `action=d5dsh_audit_run&nonce=NONCE` |
| 16 | `d5dsh_audit_run_full` | `d5dsh_audit_nonce` | POST (JSON body) | `{"action":"d5dsh_audit_run_full","nonce":"NONCE","dso_usage":{}}` |
| 17 | `d5dsh_audit_xlsx` | `d5dsh_audit_nonce` | POST (JSON body) | `{"action":"d5dsh_audit_xlsx","nonce":"NONCE"}` |
| 18 | `d5dsh_scan_xlsx` | `d5dsh_audit_nonce` | POST (JSON body) | `{"action":"d5dsh_scan_xlsx","nonce":"NONCE"}` |
| 19 | `d5dsh_content_scan` | `d5dsh_audit_nonce` | POST | `action=d5dsh_content_scan&nonce=NONCE` |
| 20 | `d5dsh_impact_analyze` | `d5dsh_audit_nonce` | POST (JSON body) | `{"action":"d5dsh_impact_analyze","nonce":"NONCE","dso_type":"color","dso_id":"xxx"}` |
| 21 | `d5dsh_simple_analyze` | `d5dsh_simple_import` | POST (FormData + file) | multipart with `action`, `nonce`, `file` |
| 22 | `d5dsh_simple_execute` | `d5dsh_simple_import` | POST (JSON body) | `{"action":"d5dsh_simple_execute","nonce":"NONCE"}` |
| 23 | `d5dsh_simple_json_to_xlsx` | `d5dsh_simple_import` | POST (JSON body) | `{"action":"d5dsh_simple_json_to_xlsx","nonce":"NONCE"}` |
| 24 | `d5dsh_merge_preview` | `d5dsh_manage` | POST (JSON body) | `{"action":"d5dsh_merge_preview","nonce":"NONCE"}` |
| 25 | `d5dsh_merge_vars` | `d5dsh_manage` | POST (JSON body) | `{"action":"d5dsh_merge_vars","nonce":"NONCE"}` |
| 26 | `d5dsh_styleguide_data` | `d5dsh_manage` | POST | `action=d5dsh_styleguide_data&nonce=NONCE` |
| 27 | `d5dsh_help_content` | _(read-only)_ | POST | `action=d5dsh_help_content&slug=overview` |

### B. admin_post Handler Tests

These use form submissions to `admin-post.php`. Test with each role.

| # | Action | Nonce field | Method |
|---|--------|-------------|--------|
| 1 | `d5dsh_export` | `d5dsh_export` (via wp_nonce_field) | POST form |
| 2 | `d5dsh_import` | `d5dsh_import` (via wp_nonce_field) | POST multipart (file) |
| 3 | `d5dsh_snapshot_restore` | `d5dsh_snapshot` | POST form |
| 4 | `d5dsh_snapshot_delete` | `d5dsh_snapshot` | POST form |
| 5 | `d5dsh_dl_template` | `d5dsh_dl_template_{type}` (in URL) | GET |

---

## Hostile Input Tests (Admin Role, Valid Nonce)

Run these as admin with a valid nonce to test input sanitization.

### XSS Payloads

For every text field that accepts user input, submit:

```
<script>alert('xss')</script>
"><img src=x onerror=alert(1)>
'onmouseover='alert(1)
javascript:alert(1)
```

Target fields:
- Settings: all text inputs in `d5dsh_save_settings`
- Notes: `text` field in `d5dsh_notes_save`
- Categories: category `name` in `d5dsh_categories_save`
- Labels: label values in `d5dsh_manage_save`
- Export metadata: owner, customer, company, project, version_tag, comments

After submission, reload the admin page and inspect the rendered HTML for
unescaped script content. Check both the page source and the DOM.

### SQL Injection Payloads

For string parameters, submit:

```
' OR '1'='1
'; DROP TABLE wp_options; --
1 UNION SELECT user_pass FROM wp_users
```

Target: any parameter that might reach a database query (DSO IDs, types, keys).
Expected: the plugin should treat these as literal strings; no SQL error or
unexpected data returned.

### Path Traversal (Import)

For file import endpoints, attempt:
```
filename: ../../../wp-config.php
filename: ....//....//....//etc/passwd
```

Expected: rejected by `validate_path_within()` or `sanitize_file_name()`.

### Malformed JSON

Send broken JSON to all endpoints that read `php://input`:
```
{invalid json
{"action":"d5dsh_manage_save","nonce":"VALID","items":null}
{"action":"d5dsh_manage_save","nonce":"VALID","items":"not-an-array"}
```

Expected: graceful error response, no PHP fatal.

---

## Recording Results

Use the findings table format from the security checklist:

| Area | File / Function | Risk | Evidence | Fix |
|------|-----------------|------|----------|-----|
| ... | ... | ... | ... | ... |

Record every test with PASS/FAIL and the HTTP status code received.

---

## Completion Criteria

All of the following must be true before the plugin passes runtime testing:

- [ ] Every AJAX endpoint returns 403 for logged-out, subscriber, and editor
- [ ] Every AJAX endpoint returns 403 when nonce is missing or wrong (even for admin)
- [ ] Every admin_post handler returns 403 or wp_die for non-admin roles
- [ ] No XSS payload is rendered unescaped in any admin page
- [ ] No SQL injection payload produces an error or unexpected data
- [ ] Path traversal attempts are rejected
- [ ] Malformed JSON produces a clean error response (no PHP fatal/warning)
- [ ] All findings recorded in the findings table

---

*Source: [github.com/akonsta/d5-design-system-helper](https://github.com/akonsta/d5-design-system-helper)*
