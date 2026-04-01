# Design System Reference Material

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-03-16 | Initial document |
| 1.1 | 2026-03-27 | Cleaned for public release |

A curated reference for anyone working with Divi 5's design system — whether using this plugin, building tools, or structuring a design system from scratch.

---

## 1. Divi 5 Official Documentation

Official help articles and blog posts from Elegant Themes covering Divi 5's design token-like systems: Design Variables, Global Variables, Presets, and the overall design system workflow.

| URL | Title / Description |
|-----|---------------------|
| https://help.elegantthemes.com/en/articles/11027601-design-variables-in-divi-5 | **Design Variables in Divi 5** — Core reference for Divi 5's variable system: types, scopes, and the visual variable editor. |
| https://help.elegantthemes.com/en/articles/13348842-global-variables-in-divi-5 | **Global Variables in Divi 5** — Covers the distinction between local and global variable scopes and how global variables propagate across a site. |
| https://www.elegantthemes.com/blog/divi-resources/how-to-import-export-design-variables-in-divi-5 | **How To Import & Export Design Variables in Divi 5** — Step-by-step guide to the native import/export workflow for Design Variables. |
| https://www.elegantthemes.com/blog/divi-resources/the-ultimate-guide-to-presets-in-divi-5-including-new-features | **The Ultimate Guide To Presets In Divi 5** — Comprehensive guide to Divi 5's Preset system. Essential for understanding the semantic layer that presets provide on top of raw variables. |
| https://www.elegantthemes.com/blog/divi-resources/mastering-divi-5s-design-system | **Mastering Divi 5's Design System: How To Approach New Builds** — Strategic guidance on structuring a design system within Divi 5 from the ground up. |
| https://www.elegantthemes.com/blog/divi-resources/how-to-build-your-own-design-system-with-nested-stacked-presets-in-divi-5 | **How To Build Your Own Design System With Nested + Stacked Presets In Divi 5** — Advanced methodology for layering presets to achieve a scalable design system architecture. |
| https://www.elegantthemes.com/blog/divi-resources/using-design-variables-with-presets-in-divi-5 | **Using Design Variables With Presets In Divi 5** — Shows how variables and presets interact: binding variable values into preset definitions for consistent theming. |
| https://www.elegantthemes.com/blog/divi-resources/mastering-global-colors-in-divi-5 | **Mastering Global Colors In Divi 5** — Deep dive into Divi 5's Global Color system. Covers palette management and global color inheritance. |
| https://www.elegantthemes.com/blog/divi-resources/divi-5-launch-gift-design-system | **Divi 5 Launch Gift: Free Design System** — Includes a downloadable design system package from Elegant Themes. A practical reference implementation. |

---

## 2. W3C Design Tokens Community Group (DTCG)

The W3C Design Tokens Community Group defines a cross-tool, vendor-neutral specification for design tokens.

### Key Links

| URL | Description |
|-----|-------------|
| https://www.w3.org/community/design-tokens/ | **W3C DTCG Home** — The official W3C Community Group page. |
| https://www.designtokens.org/ | **DTCG Community Site** — News, tooling, and adoption updates. |
| https://github.com/design-tokens/community-group | **DTCG Specification Repository** — The canonical GitHub repository with the JSON format definition. |

### Key Facts

- The Design Tokens Specification reached its **first stable version (2025.10)** in October 2025.
- DTCG uses **JSON format** with `$`-prefixed properties (e.g., `$type`, `$value`, `$description`, `$extensions`).
- The specification formally supports **13 token types**: `color`, `dimension`, `font-family`, `font-weight`, `font-style`, `number`, `duration`, `cubic-bezier`, `shadow`, `gradient`, `typography`, `transition`, `border`.

### Relevance to Divi 5

Divi 5's variable system is conceptually aligned with DTCG **primitive tokens**. The mapping is:

| Divi 5 Variable Type | Nearest DTCG Token Type |
|----------------------|-------------------------|
| Color | `color` |
| Number / Dimension (px, rem, %) | `number` / `dimension` |
| Font Family | `font-family` |
| String | No direct equivalent (widely used but outside the 13 formal types) |
| URL (image/asset) | No direct equivalent |

D5 Design System Helper supports DTCG-compliant export and import via the `design-tokens.json` format. See the [README](../README.md) for details.

---

## 3. Naming Conventions Resources

Consistent, semantic naming is the single most impactful practice for making a design token system maintainable at scale.

| URL | Title / Description |
|-----|---------------------|
| https://www.smashingmagazine.com/2024/05/naming-best-practices/ | **Best Practices For Naming Design Tokens, Components And Variables** (Smashing Magazine) — Authoritative 2024 overview. |
| https://www.netguru.com/blog/design-token-naming-best-practices | **Design Token Naming Best Practices** (Netguru) — Practical guide with real examples. |
| https://medium.com/eightshapes-llc/naming-tokens-in-design-systems-9e86c7444676 | **Naming Tokens in Design Systems** by Nathan Curtis (EightShapes) — Classic foundational reference. |
| https://nordhealth.design/naming/ | **Nord Design System: Naming Conventions** — Practical real-world example. |
| https://thedesignsystem.guide/design-tokens | **The Design System Guide: Design Tokens** — Accessible introduction to design token concepts. |
| https://www.uxpin.com/studio/blog/design-system-naming-conventions/ | **Design System Naming Conventions** (UXPin) — Covers naming at both the token and component level. |

### Key Principle

> Token names should describe **purpose and usage**, not values.

- Use a **category-subcategory-state** pattern (e.g., `color-background-interactive-hover`).
- Avoid encoding the value in the name: `color-primary` is better than `blue-500`.
- Names should remain stable even if the underlying value changes.

See the [Standard Name Library](standard-name-library/) for a complete set of recommended Divi 5 variable names following these conventions.

---

## 4. Design System Methodology Resources

Broader methodology references covering design token evolution, structural approaches, and Divi-specific workflows.

| URL | Title / Description |
|-----|---------------------|
| https://www.designsystemscollective.com/the-evolution-of-design-system-tokens-a-2025-deep-dive-into-next-generation-figma-structures-969be68adfbe | **The Evolution of Design System Tokens: A 2025 Deep Dive** (Design Systems Collective) — Multi-tier token architectures (primitive → semantic → component). |
| https://zeroheight.com/blog/whats-new-in-the-design-tokens-spec/ | **What's New in the Design Tokens Spec** (zeroheight) — Recent changes to the DTCG specification. |
| https://join.divistylistacademy.com/design-system/ | **Divi 5 Design System in 7 Steps** (Divi Stylist Academy) — Practical Divi-specific methodology. |

---

## 5. Related Documentation in This Repository

| Document | Description |
|---|---|
| [SERIALIZATION_SPEC.md](SERIALIZATION_SPEC.md) | Divi 5 serialization format with formal ABNF grammar |
| [DIVI5_SYSTEM_VARIABLES.md](DIVI5_SYSTEM_VARIABLES.md) | All built-in system variable IDs, storage paths, and editability |
| [Standard Name Library](standard-name-library/) | DTCG-aligned naming convention system for Divi 5 variables |

---

*Source: [github.com/akonsta/d5-design-system-helper](https://github.com/akonsta/d5-design-system-helper)*
