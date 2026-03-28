# Security Policy

| Version | Date | Notes |
|---------|------|-------|
| 0.1.0 | 2026-03-27 | Initial public release |

## Supported Versions

We actively maintain security updates for the following versions:

| Version | Supported          |
| ------- | ------------------ |
| 0.1.x   | :white_check_mark: |

Once version 1.0.0 is released, we will maintain security updates for the current major version and one prior major version.

---

## Reporting a Vulnerability

We take security vulnerabilities seriously. If you discover a security issue, please report it responsibly.

### How to Report

**Do NOT open a public GitHub issue for security vulnerabilities.**

Instead, please email us directly at:

**security@me2we.com**

Include the following information:
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Any suggested remediation (optional)

### What to Expect

| Timeframe | Action |
|-----------|--------|
| 24-48 hours | Acknowledgment of your report |
| 7 days | Initial assessment and severity classification |
| 30 days | Target resolution for critical/high severity issues |
| 90 days | Target resolution for medium/low severity issues |

We will:
- Keep you informed of our progress
- Credit you in the security advisory (unless you prefer to remain anonymous)
- Not take legal action against good-faith security researchers

### Scope

The following are in scope for security reports:
- The D5 Design System Helper plugin code
- WordPress admin interface vulnerabilities
- Data handling and storage issues
- Authentication and authorization bypasses

Out of scope:
- Vulnerabilities in WordPress core
- Vulnerabilities in the Divi theme/plugin
- Vulnerabilities in PHP or server configuration
- Social engineering attacks
- Denial of service (unless caused by a specific code flaw)

---

## Security Measures

### Authentication & Authorization

- **Administrator-only access**: All plugin functionality requires the `manage_options` capability (WordPress Administrator role)
- **Nonce verification**: Every form submission and AJAX request is protected with WordPress nonces to prevent CSRF attacks
- **Capability checks**: Every endpoint verifies user capabilities before processing

### Input Validation & Sanitization

All user input is sanitized using WordPress core functions:
- `sanitize_text_field()` for text input
- `sanitize_key()` for option keys and identifiers
- `sanitize_file_name()` for uploaded filenames
- `wp_kses_post()` for HTML content

### Output Escaping

All dynamic output is escaped using appropriate WordPress functions:
- `esc_html()` for plain text
- `esc_attr()` for HTML attributes
- `esc_url()` for URLs
- `wp_json_encode()` for JSON data

### SQL Injection Prevention

- No direct SQL queries with user input concatenation
- All database queries use `$wpdb->prepare()` with parameterized placeholders
- LIKE queries use `$wpdb->esc_like()` for proper escaping

### File Upload Security

- File uploads are validated using `is_uploaded_file()`
- Only `.json`, `.xlsx`, and `.zip` file extensions are accepted
- MIME types are validated before processing
- Zip file contents are validated (no hidden files, no path traversal)
- Temporary files use unique, user-scoped directories

### Data Privacy

**No external data transmission**: This plugin does not:
- Send data to external servers
- Include analytics or tracking
- Make "phone home" requests
- Load remote assets

**One exception**: When exporting variables, the plugin may optionally validate image URLs using HTTP HEAD requests to check if images exist. This:
- Only contacts URLs already stored in your Divi configuration
- Does not transmit any identifying information
- Can be avoided by not using image URL validation

### Data Storage

The plugin reads and writes the following WordPress options:
- `et_divi_global_variables` — Divi 5 global variables
- `et_divi_builder_global_presets_d5` — Divi 5 presets
- `theme_mods_Divi` — Theme customizer settings
- `d5dsh_snap_*` — Plugin snapshots (auto-created backups)
- `d5dsh_backup_*` — Manual backups

All data is stored locally in your WordPress database. No data is transmitted externally.

### Automatic Backups

Before any write operation, the plugin automatically creates a snapshot of the affected data. This provides:
- Audit trail of changes
- One-click restoration of previous states
- Protection against accidental data loss

### Clean Uninstall

When the plugin is deleted via WordPress admin:
- All plugin-specific options (`d5dsh_*`) are removed
- All plugin transients are removed
- **Divi design system data is intentionally preserved** (it belongs to Divi, not this plugin)

---

## Security Best Practices for Users

### Recommended

1. **Keep WordPress updated**: Always run the latest version of WordPress
2. **Keep Divi updated**: Ensure Divi 5 is up to date
3. **Use strong admin passwords**: The plugin requires admin access; protect those accounts
4. **Limit admin accounts**: Only grant `manage_options` capability to trusted users
5. **Use HTTPS**: Always run your WordPress site over HTTPS
6. **Regular backups**: While the plugin creates snapshots, maintain independent site backups

### File Permissions

Ensure proper WordPress file permissions:
- Directories: `755`
- Files: `644`
- `wp-config.php`: `600` or `640`

### Hosting Environment

- Use PHP 8.1 or higher (required by this plugin)
- Keep PHP updated with security patches
- Use a reputable hosting provider with security monitoring

---

## Known Security Considerations

### Design System Data Sensitivity

The data managed by this plugin (colors, fonts, spacing, presets) is **site configuration data**, not personal user data. However:

- Exported files may contain your site's design decisions
- Treat exported JSON/XLSX files as you would any site backup
- Do not share exports publicly if your design system is proprietary

### Administrator Trust Model

This plugin operates on a **trusted administrator model**:
- Any user with `manage_options` capability can modify your entire design system
- This is consistent with WordPress's security model for site configuration
- If you need finer-grained access control, consider a role management plugin

### Cross-Site Import Limitations

When importing design system data from another site:
- Variable IDs (`gcid-*`, `gvid-*`) are site-specific
- Importing from a different site may create orphaned references
- Always test imports on a staging site first

---

## Vulnerability Disclosure Policy

### Our Commitment

- We will acknowledge receipt of vulnerability reports within 48 hours
- We will provide regular updates on remediation progress
- We will publicly disclose vulnerabilities after a fix is available
- We will credit researchers who report vulnerabilities responsibly

### Coordinated Disclosure

We follow a coordinated disclosure process:
1. Researcher reports vulnerability privately
2. We acknowledge and assess the report
3. We develop and test a fix
4. We release the fix and notify affected users
5. We publish a security advisory with appropriate detail
6. Researcher may publish their findings after advisory

### Security Advisories

Security advisories will be published via:
- GitHub Security Advisories
- Plugin changelog
- Direct notification to users (for critical issues, if contact info available)

---

## Third-Party Dependencies

This plugin includes the following third-party libraries:

| Library | Version | Purpose | Security Notes |
|---------|---------|---------|----------------|
| [PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) | ^2.1 | Excel file handling | Actively maintained, security patches applied promptly |
| [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) | ^5.6 | Automatic updates | MIT licensed, widely used in WordPress ecosystem |

We monitor these dependencies for security updates and will release patches promptly when vulnerabilities are discovered.

---

## Security Audit

The plugin undergoes regular security review covering:

- [x] Authentication and authorization checks
- [x] Nonce verification on all endpoints
- [x] Input validation and sanitization
- [x] Output escaping
- [x] SQL injection prevention
- [x] File upload validation
- [x] Path traversal prevention
- [x] Data privacy compliance
- [x] Clean uninstall procedures

**Last audit**: 2026-03-27 (Version 0.1.0)
**Result**: No critical or high-severity issues identified

**Recent fixes**:
- 2026-03-16: Added explicit `realpath()` path traversal validation in zip file processing

---

## Compliance

### GDPR Considerations

This plugin:
- Does not collect personal data
- Does not use cookies
- Does not track users
- Does not transmit data to third parties

The design system data it manages (colors, fonts, presets) is site configuration data, not personal data subject to GDPR.

### WordPress Coding Standards

This plugin follows:
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- [WordPress Plugin Security Guidelines](https://developer.wordpress.org/plugins/security/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/) mitigation practices

---

## Contact

For security concerns:
- **Email**: security@me2we.com
- **Response time**: 24-48 hours

For general support:
- **GitHub Issues**: [d5-design-system-helper/issues](https://github.com/akonsta/d5-design-system-helper/issues)

---

## Acknowledgments

We thank the following individuals for responsibly disclosing security issues:

_(No security issues have been reported yet. Be the first to help us improve!)_

---

## Changelog

| Date | Change |
|------|--------|
| 2026-03-16 | Initial comprehensive security policy |
| 2026-03-16 | Added path traversal hardening in SimpleImporter.php (zip file processing) |

---

_This security policy follows best practices from the [GitHub Security Policy documentation](https://docs.github.com/en/code-security/getting-started/adding-a-security-policy-to-your-repository) and is adapted for WordPress plugin development._
