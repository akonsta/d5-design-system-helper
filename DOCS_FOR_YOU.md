# D5 Design System Helper — Documentation Index

| Version | Date | Notes |
|---------|------|-------|
| 0.1.0 | 2026-03-27 | Initial public release |

This index lists all documentation included in this repository.

---

## Plugin Documentation (Root)

| Title | Filename | Type | Version |
|-------|----------|------|---------|
| D5 Design System Helper | README.md | Overview | 0.1.0 |
| Changelog | CHANGELOG.md | Release notes | 0.1.0 |
| Installation Guide | INSTALLATION\_GUIDE.md | Guide | 0.1.0 |
| User Guide | PLUGIN\_USER\_GUIDE.md | Guide | 0.1.0 |
| Roadmap | ROADMAP.md | Planning | 0.1.0 |
| Contributing | CONTRIBUTING.md | Community | 0.1.0 |
| Code of Conduct | CODE\_OF\_CONDUCT.md | Community | 0.1.0 |
| Security Policy | SECURITY.md | Policy | 0.1.0 |
| Notice | NOTICE.md | Legal | 0.1.0 |

<details>
<summary>README.md — D5 Design System Helper</summary>

The starting point for anyone arriving at this repository. Covers the background story of how the plugin came to exist, what it does, the data model Divi 5 uses to store your design system, the full list of supported data types, and quick installation steps. If you want to understand what the plugin is for and whether it solves your problem, start here.

</details>

<details>
<summary>CHANGELOG.md — Changelog</summary>

A record of every public release: what changed, what was added, and what was fixed. Follows Semantic Versioning. Useful if you are upgrading from a previous version and want to know what is new, or if you are evaluating the project's activity and maintenance history.

</details>

<details>
<summary>INSTALLATION\_GUIDE.md — Installation Guide</summary>

Step-by-step installation instructions written for site owners and web designers. Covers minimum requirements (WordPress 6.2+, PHP 8.1+, Divi 5.0+), how to download the correct release asset from GitHub, how to install through the WordPress admin, and how to verify the plugin is running correctly. Also includes a troubleshooting section for edge cases like missing PHP extensions.

</details>

<details>
<summary>PLUGIN\_USER\_GUIDE.md — User Guide</summary>

The full reference guide to every feature in the plugin. Covers all tabs (Manage, Export, Import, Audit, Analysis, Style Guide, Snapshots, Help), explains the Divi 5 data model in plain language, and walks through real-world workflows — migrating a design system between sites, auditing for problems, bulk-renaming variables, importing from a spreadsheet, and more. If you are using the plugin and want to understand what a specific feature does or why it works the way it does, this is the document to read.

</details>

<details>
<summary>ROADMAP.md — Roadmap</summary>

Describes what is available in the current release and what is planned for future versions. Covers near-term features (annotation round-trip, Excel metadata persistence) and longer-term ideas (multi-site federation, WP-CLI integration, a potential Pro tier). Useful if you are evaluating the plugin for a project and want to know where it is headed, or if you have a feature request and want to see whether it is already on the list.

</details>

<details>
<summary>CONTRIBUTING.md — Contributing</summary>

Everything you need to know to contribute to the project: how to report bugs, how to request features, how to set up a local development environment, coding standards, how to write and run tests, and how to submit a pull request. Also covers commit message conventions and the pull request review process.

</details>

<details>
<summary>CODE\_OF\_CONDUCT.md — Code of Conduct</summary>

The community standards for this project, based on the Contributor Covenant. Covers expected behaviour, unacceptable behaviour, and how to report violations. Applies to everyone who interacts with the project — in issues, pull requests, discussions, or any other forum.

</details>

<details>
<summary>SECURITY.md — Security Policy</summary>

The security policy for the project. Lists which versions currently receive security updates, explains how to report a vulnerability responsibly (do not open a public issue — email security@me2we.com instead), and describes the response timeline and what you can expect after submitting a report.

</details>

<details>
<summary>NOTICE.md — Notice</summary>

Legal notices and attributions. Includes the project's GPL-2.0-or-later copyright statement, an AI assistance disclosure (this project was built with significant help from Claude Code), and full third-party library notices for bundled dependencies including Tabulator and PhpSpreadsheet.

</details>

---

## Technical Reference (`docs/`)

| Title | Filename | Type | Version |
|-------|----------|------|---------|
| Divi 5 Serialization Spec | SERIALIZATION\_SPEC.md | Technical reference | 1.3 |
| Divi 5 System Variables | DIVI5\_SYSTEM\_VARIABLES.md | Technical reference | 1.3 |
| Audit Checks — Rationale | AUDIT\_CHECKS\_RATIONALE.md | Technical reference | 1.1 |
| Content Scanner — Design Notes | CONTENT\_SCANNER\_NOTES.md | Design notes | 1.1 |
| DiviBlocParser — Design Notes | PARSER\_DESIGN\_NOTES.md | Design notes | 1.1 |
| Design System References | DESIGN\_SYSTEM\_REFERENCES.md | Reference | 1.1 |

<details>
<summary>SERIALIZATION\_SPEC.md — Divi 5 Serialization Specification</summary>

The normative specification for how Divi 5 serializes Design System Objects into the WordPress database. Covers variable reference tokens (the `$variable()$` format embedded in field values), preset reference keys embedded in block markup, the JSON payload structures inside those tokens, WordPress option key paths, and quote encoding variations. Written in ABNF (RFC 5234) for precision. `DiviBlocParser.php` is the authoritative implementation of this spec. If Divi ever changes its serialization format, this document identifies exactly what needs updating and whether a change requires a code rewrite or just a constant update. Essential reading for anyone extending the plugin or building tools that read Divi's database directly.

</details>

<details>
<summary>DIVI5\_SYSTEM\_VARIABLES.md — Divi 5 System Variables</summary>

A technical catalogue of Divi 5's variable storage architecture, discovered through live-site database inspection. Covers the two separate `wp_options` keys Divi uses (`et_divi` and `et_divi_global_variables`), the critical distinction between user-created color variables (stored in `et_divi`) and non-color variables (stored in `et_divi_global_variables`), and a complete list of hardwired system variables with their synthesised `gcid-` identifiers, backing storage keys, default values, and whether they can be renamed or deleted. Elegant Themes does not publish this information. Accurate as of Divi 5.0.0-public-beta.3. Useful for anyone debugging variable import/export behaviour or building tools that interact with Divi's data layer.

</details>

<details>
<summary>AUDIT\_CHECKS\_RATIONALE.md — Audit Checks Rationale</summary>

Documents the reasoning behind every check in the audit system. For each of the 22 checks (14 Simple + 8 Contextual), explains what problem it detects, why it sits at its severity tier (Error, Warning, or Info), how it is calculated, and what a developer or site owner should do about a positive result. Also covers deferred checks — audits that were considered but not implemented, with the reason why. Useful if you want to understand what the audit is actually measuring, or if you are contributing a new check and need to understand the conventions.

</details>

<details>
<summary>CONTENT\_SCANNER\_NOTES.md — Content Scanner Design Notes</summary>

Design notes for the content scanner component (`ContentScanner.php`). Explains the four data structures the scanner produces (`active_content`, `inventory`, `dso_usage`, and `meta`), how the 1,000-item scan limit works and what happens when it is reached, and how third-party Divi 5 content (Theme Builder templates with multiple canvases) is handled to avoid double-counting. Also covers the integration point between the scanner and the contextual audit — the `dso_usage` reverse index is the primary input for the 8 contextual checks. Useful for anyone debugging scan results or extending the scanner to support additional post types.

</details>

<details>
<summary>PARSER\_DESIGN\_NOTES.md — DiviBlocParser Design Notes</summary>

Design notes for `DiviBlocParser.php`, the component responsible for extracting variable and preset references from serialized Divi content. Explains the multi-strategy dispatch architecture (why the parser uses a strategy array rather than a single monolithic function), the rationale for using ABNF in the companion serialization spec, and how to extend the parser when Divi introduces new serialization formats. Includes code examples of the strategy pattern and an explanation of each existing strategy. Essential reading before modifying or extending the parser.

</details>

<details>
<summary>DESIGN\_SYSTEM\_REFERENCES.md — Design System References</summary>

A curated reading list for anyone working with Divi 5's design system — whether using this plugin, building tools on top of Divi's data layer, or thinking about how to structure a design system from scratch. Covers official Elegant Themes documentation on Design Variables, Global Variables, and Presets; W3C Design Tokens Community Group (DTCG) specification links; popular design token tooling (Tokens Studio for Figma, Style Dictionary, Theo); and CSS custom properties resources. Not plugin-specific — useful as a general reference for the broader Divi 5 and design token ecosystem.

</details>

---

## Standard Name Library (`docs/standard-name-library/`)

| Title | Filename | Type | Version |
|-------|----------|------|---------|
| Standard Name Library | README.md | Overview | 1.1 |
| User Guide | GUIDE.md | Guide | 1.1 |
| Naming Conventions | naming-conventions.md | Reference | 1.1 |
| Primitive Tokens | primitive-tokens.md | Reference | 1.1 |
| Semantic Tokens | semantic-tokens.md | Reference | 1.1 |
| Component Tokens | component-tokens.md | Reference | 1.1 |

<details>
<summary>README.md — Standard Name Library Overview</summary>

Overview of the Standard Name Library: what it is, why it exists, and how it is structured. The library provides a comprehensive set of recommended variable names for Divi 5 design systems, aligned with W3C DTCG conventions and organised into three tiers (Primitive, Semantic, Component). Also describes the three coverage levels (Starter ~50 tokens, Comprehensive ~150 tokens, Enterprise ~300+) so you can adopt the subset that matches the scale of your project.

</details>

<details>
<summary>GUIDE.md — Standard Name Library User Guide</summary>

A practical guide to using the Standard Name Library in your own Divi 5 design system. Explains the difference between presentational names (fragile, hard to maintain) and semantic names (purpose-based, survives rebranding), how to map library names to Divi 5 variable types, a suggested adoption workflow (start with Starter tokens, expand incrementally), and how the plugin uses the library to power naming suggestions and consistency checks. The right starting point if you want to adopt the library for a real project.

</details>

<details>
<summary>naming-conventions.md — Naming Conventions</summary>

The rules behind the names. Covers the core `{category}-{concept}-{property}-{variant}-{state}` hierarchy, when to use each level, the principle of semantic over presentational naming, casing rules (kebab-case throughout), abbreviation standards, and examples of good and bad token names at each tier. Reading this document will help you create new token names that fit naturally alongside the library's existing names, and avoid common naming mistakes that make a design system harder to maintain over time.

</details>

<details>
<summary>primitive-tokens.md — Primitive Tokens</summary>

The catalogue of raw-value tokens: color palettes (neutral grays, blues, greens, reds, purples, and more), spacing scales, font size scales, line height scales, border radius values, and shadow definitions. Primitives are named by value or scale position, not by purpose — `color-gray-500`, `space-4`, `font-size-lg`. They form the foundation that semantic tokens reference. Tagged by coverage level so you can see which ones belong in a minimal Starter system versus a full Enterprise palette.

</details>

<details>
<summary>semantic-tokens.md — Semantic Tokens</summary>

The catalogue of purpose-based tokens that reference primitives. Brand colors (`color-primary`, `color-secondary`), text colors (`color-text-primary`, `color-text-muted`), background colors, interactive state colors, spacing aliases (`space-inline-sm`, `space-stack-lg`), and typography tokens (`font-family-heading`, `font-size-display`). These are the tokens you use day-to-day when building in Divi — assigning them to variables means a brand color change updates everywhere at once. Tagged by coverage level.

</details>

<details>
<summary>component-tokens.md — Component Tokens</summary>

The catalogue of component-scoped tokens that reference semantic tokens. Covers the most common Divi module types: buttons (primary, secondary, ghost variants with hover/active/disabled states), cards, inputs, navigation, headings, and more. Component tokens give you precise control over individual elements while keeping them connected to the design system — changing `color-primary` still cascades through to `button-background-primary`. Tagged by coverage level.

</details>
