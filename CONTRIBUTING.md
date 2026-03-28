# Contributing to D5 Design System Helper

| Version | Date | Notes |
|---------|------|-------|
| 0.1.0 | 2026-03-27 | Initial public release |

Thank you for your interest in contributing. Please read this guide before opening issues or submitting pull requests.

---

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [How to Report a Bug](#how-to-report-a-bug)
- [How to Request a Feature](#how-to-request-a-feature)
- [Development Setup](#development-setup)
- [Pull Request Process](#pull-request-process)
- [Coding Standards](#coding-standards)
- [Tests](#tests)
- [Commit Messages](#commit-messages)

---

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md). By participating you agree to abide by its terms.

---

## How to Report a Bug

1. Search [existing issues](../../issues) first — the bug may already be reported.
2. Open a new issue using the **Bug Report** template.
3. Include:
   - Plugin version
   - WordPress version, PHP version, Divi version
   - Steps to reproduce
   - Expected vs. actual behaviour
   - Any relevant error messages or screenshots

---

## How to Request a Feature

1. Check [ROADMAP.md](ROADMAP.md) — it may already be planned.
2. Open an issue using the **Feature Request** template.
3. Describe the problem you're solving, not just the solution you have in mind.

---

## Development Setup

### Requirements

- PHP 8.1+
- Composer
- WordPress 6.2+ with Divi 5 installed (for manual testing)

### Steps

```bash
git clone https://github.com/[OWNER]/d5-design-system-helper.git
cd d5-design-system-helper
composer install        # includes dev dependencies (PHPUnit)
```

> **Do not** use `--no-dev` for development. The `--no-dev` flag is used only when building release zips.

### Running Tests

```bash
./vendor/bin/phpunit
```

All tests must pass before submitting a pull request.

---

## Pull Request Process

1. Fork the repository and create a branch from `main`.
2. Name branches descriptively: `fix/preset-count-fatal`, `feature/dashboard-tab`.
3. Keep PRs focused — one bug fix or one feature per PR.
4. Add or update tests for any changed behaviour.
5. Ensure all tests pass (`./vendor/bin/phpunit`).
6. Update `CHANGELOG.md` under `[Unreleased]`.
7. Submit the PR and fill in the pull request template.

---

## Coding Standards

**PHP:** Follow [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
- Tabs for indentation (not spaces)
- Yoda conditions
- Spaces inside parentheses for control structures
- DocBlocks on all public methods

**JavaScript:** Follow [WordPress JavaScript Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/javascript/).
- Single quotes for strings
- Semicolons required

**General:**
- No trailing whitespace
- UNIX line endings (LF)
- All PHP files must pass `php -l` with no errors

---

## Tests

- Tests live in `tests/Unit/`
- Bootstrap is `tests/bootstrap.php` — WordPress function stubs are in `tests/Stubs/`
- Use PHPUnit 10.x (`composer.json` specifies the version)
- Private methods under test: use `ReflectionMethod` (see `tests/Unit/Admin/ValidatorTest.php` for the pattern)
- Do not commit `.xlsx` or other generated test fixtures — use `sys_get_temp_dir()` helpers instead

---

## Commit Messages

Use the imperative mood: "Fix fatal error", not "Fixed" or "Fixes".

Structure:
```
Short summary (72 chars max)

Optional longer body explaining why, not what.
```

Reference issues where relevant: `Closes #42`.

---

## Questions?

Open a [Discussion](../../discussions) or email [konsta@me2we.com](mailto:konsta@me2we.com) for questions that don't fit an issue.
