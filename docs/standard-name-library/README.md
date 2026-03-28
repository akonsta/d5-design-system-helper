# D5 Design System Helper — Standard Name Library

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-03-16 | Initial library |
| 1.1 | 2026-03-27 | Cleaned for public release |

A comprehensive library of recommended variable names following DTCG-aligned `category-subcategory-state` naming conventions for Divi 5 design systems.

---

## Overview

This library provides standardized naming patterns for design tokens that:
- Follow W3C Design Tokens Community Group (DTCG) conventions
- Map directly to Divi 5 variable types (colors, numbers, fonts, images, text, links)
- Enable consistency checking and naming suggestions in the plugin

## Token Tiers

The library uses a three-tier architecture aligned with industry best practices:

| Tier | Purpose | Example | Mutability |
|------|---------|---------|------------|
| **Primitive** | Raw values, no context | `blue-500`, `space-4` | Rarely changes |
| **Semantic** | Purpose-based aliases | `color-primary`, `space-inline-md` | References primitives |
| **Component** | Component-specific | `button-background`, `card-padding` | References semantic |

## Coverage Levels

Tokens are tagged by recommended adoption level:

| Level | Token Count | Use Case |
|-------|-------------|----------|
| **Starter** | ~50 | Minimum viable design system |
| **Comprehensive** | ~150 | Typical website needs |
| **Enterprise** | ~300+ | Large-scale, multi-brand systems |

## Directory Contents

| File | Description |
|------|-------------|
| `naming-conventions.md` | Naming rules, patterns, and syntax |
| `primitive-tokens.md` | Value-based tokens (scales, palettes) |
| `semantic-tokens.md` | Purpose-based alias tokens |
| `component-tokens.md` | Component-specific tokens |
| `token-library.json` | Machine-readable token definitions |
| `implementation-plan.md` | Feature implementation roadmap |

## Quick Reference

### Naming Pattern

```
{category}-{concept}-{property}-{variant}-{state}
```

### Categories

| Category | Divi Type | Examples |
|----------|-----------|----------|
| `color` | colors | `color-primary`, `color-text-muted` |
| `space` | numbers | `space-sm`, `space-inline-lg` |
| `size` | numbers | `size-icon-md`, `size-avatar-lg` |
| `radius` | numbers | `radius-sm`, `radius-full` |
| `shadow` | numbers | `shadow-sm`, `shadow-overlay` |
| `font` | fonts | `font-heading`, `font-mono` |
| `font-size` | numbers | `font-size-xs`, `font-size-display` |
| `font-weight` | numbers | `font-weight-normal`, `font-weight-bold` |
| `line-height` | numbers | `line-height-tight`, `line-height-relaxed` |
| `letter-spacing` | numbers | `letter-spacing-tight`, `letter-spacing-wide` |
| `duration` | numbers | `duration-fast`, `duration-slow` |
| `easing` | numbers | `easing-ease-out`, `easing-spring` |
| `z-index` | numbers | `z-index-modal`, `z-index-tooltip` |
| `breakpoint` | numbers | `breakpoint-sm`, `breakpoint-xl` |
| `image` | images | `image-logo`, `image-hero-default` |
| `url` | links | `url-home`, `url-social-twitter` |
| `content` | text | `content-cta-primary`, `content-error-generic` |

## Mapping to Divi 5

| DTCG Category | Divi 5 Type | ID Prefix |
|---------------|-------------|-----------|
| color-* | colors | `gcid-` |
| space-*, size-*, radius-*, shadow-*, font-size-*, font-weight-*, line-height-*, letter-spacing-*, duration-*, easing-*, z-index-*, breakpoint-* | numbers | `gvid-` |
| font-* | fonts | `gvid-` |
| image-* | images | `gvid-` |
| url-* | links | `gvid-` |
| content-* | strings/text | `gvid-` |

## Usage in Plugin

### Planned Features

1. **Name Suggestions**: When creating variables, suggest names from the library
2. **Consistency Check**: Scan existing variables and flag non-standard names
3. **Bulk Rename**: Apply library names to existing variables with mapping
4. **Export Mapping**: Generate DTCG-compatible JSON exports

### Consistency Warnings

The plugin will flag:
- Presentational names (`red-button` → suggest `color-button-danger`)
- Inconsistent casing (`primaryColor` vs `primary-color`)
- Missing category prefix (`large` → suggest `size-lg` or `space-lg`)
- Ambiguous names (`main` → clarify: `color-main` or `font-main`?)

---

## References

- [W3C Design Tokens Community Group](https://www.w3.org/community/design-tokens/)
- [DTCG Specification 2025.10](https://www.designtokens.org/)
- [Naming Tokens in Design Systems — Nathan Curtis](https://medium.com/eightshapes-llc/naming-tokens-in-design-systems-9e86c7444676)
- [Smashing Magazine — Best Practices for Naming](https://www.smashingmagazine.com/2024/05/naming-best-practices/)

---

_This library is part of the D5 Design System Helper plugin. Last updated: 2026-03-16._
