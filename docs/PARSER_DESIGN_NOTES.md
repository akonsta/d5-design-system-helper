# DiviBlocParser — Design Notes

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-03-20 | Initial document — extracted from session 30 design notes |
| 1.1 | 2026-03-27 | Cleaned for public release; session framing removed |

**Applies to:** `includes/Util/DiviBlocParser.php`
**See also:** `docs/SERIALIZATION_SPEC.md` — the normative spec this parser implements

---

## Purpose

This document explains the architectural decisions behind `DiviBlocParser.php` — specifically the multi-strategy dispatch pattern and how to extend it when Divi introduces new serialization formats.

---

## Why ABNF for the serialization spec

The companion `SERIALIZATION_SPEC.md` uses ABNF (RFC 5234) rather than EBNF. The reasons:

- RFC 5234 is precise about string matching, case sensitivity, and character ranges
- ABNF has a single standardised meta-notation; EBNF has several competing variants
- Divi's token format is purely structural — no optional blocks or recursion where EBNF's `{...}` / `[...]` shorthands would shine — so ABNF's rule-based notation is cleaner

---

## Multi-strategy dispatch architecture

`DiviBlocParser` uses a strategy array rather than a single monolithic parser:

```php
private const VARIABLE_REF_STRATEGIES = [
    'extract_variable_refs_divi5_dollar_token',
    // Future: 'extract_variable_refs_divi6_html_element',
];

private const PRESET_REF_STRATEGIES = [
    'extract_preset_refs_divi5_module_preset',
    'extract_preset_refs_divi5_group_preset',
];
```

`extract_variable_refs()` and `extract_preset_refs()` iterate all registered strategies, merge results, and deduplicate before returning. Deduplication is by `name` field for variables, by value for presets.

### Why method name strings, not closures or interfaces

The strategies are always static methods on `DiviBlocParser` itself. Method name strings resolved via `self::$method($arg)` keep dispatch zero-dependency and zero-allocation — no closure objects, no interface implementations needed.

### Why deduplicate by `name` (not by `[type, name]` pair)

If the same variable ID appears via two strategies — once in `$variable(...)$` format and once in a future alternate format — it collapses to one entry with first-strategy-wins for the `type` field. This is conservative: it avoids reporting the same variable twice.

---

## Adding a new Divi serialization format

When Divi introduces a new token format, follow these four steps — no callers need to change:

1. **Add a pattern constant** — define the new regex or match pattern as a `private const`
2. **Add a private static method** — name it `extract_variable_refs_<descriptive_name>()` or `extract_preset_refs_<descriptive_name>()`
3. **Register in the strategy array** — append the method name string to `VARIABLE_REF_STRATEGIES` or `PRESET_REF_STRATEGIES`
4. **Update `SERIALIZATION_SPEC.md`** — document the new format and add it to the ABNF grammar

---

## Change-impact tiers

When Divi changes its serialization, the effort required depends on the type of change. The full matrix is in `SERIALIZATION_SPEC.md` §8, but the summary:

| Tier | Example change | Required action |
|------|---------------|-----------------|
| Constant update only | Token wrapper changes from `$variable(...)$` to `$$variable[...]$$` | Update `VARIABLE_TOKEN_PATTERN` |
| Logic update | Inner JSON gains a new key alongside `value.name` | Update `decode_variable_payload()` |
| New strategy added | Divi introduces a second token format | Add const + method + register in strategy array |
| Full rewrite | Token IDs replaced by UUIDs needing a lookup service | Rewrite parser layer |

---

## The spec is normative

`SERIALIZATION_SPEC.md` is the specification; `DiviBlocParser.php` is the implementation. If the spec says something the code does not do, the code is wrong — not the spec. When fixing a parsing bug, update the spec first, then the code.

---

*Source: [github.com/akonsta/d5-design-system-helper](https://github.com/akonsta/d5-design-system-helper)*
