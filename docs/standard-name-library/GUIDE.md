# Standard Name Library — User Guide

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-03-16 | Initial guide |
| 1.1 | 2026-03-27 | Cleaned for public release |

A practical guide to using the Standard Name Library for consistent, scalable variable naming in your Divi 5 design system.

---

## What Is This Library?

The Standard Name Library is a curated collection of **recommended variable names** for design tokens. It provides a consistent naming system based on the [W3C Design Tokens Community Group (DTCG)](https://www.designtokens.org/) conventions, adapted specifically for Divi 5.

### What's Included

| Document | What It Contains |
|----------|------------------|
| `primitive-tokens.md` | Raw values: color palettes, spacing scales, font sizes |
| `semantic-tokens.md` | Purpose-based names: `color-primary`, `space-lg`, `font-heading` |
| `component-tokens.md` | Component-specific: `button-background`, `card-padding`, `input-border` |
| `token-library.json` | Machine-readable version for tooling integration |
| `naming-conventions.md` | The rules and patterns behind the names |

---

## Why Use Standard Names?

### 1. Future-Proof Your Design System

**Bad:** `blue-500`, `red-button`, `big-heading`

**Good:** `color-primary`, `color-danger`, `font-size-display`

When your brand color changes from blue to green, you update one token. With presentational names like "blue-500", you'd need to find and update every reference — or live with confusing names that no longer match reality.

### 2. Team Communication

Standard names create a shared vocabulary. When a designer says "use the primary color," everyone knows they mean `color-primary`. No ambiguity about whether that's `brand-blue`, `main-color`, or `theme-primary`.

### 3. Scalability

Starting with 20 variables is easy. Managing 200 without a system is chaos. The three-tier architecture (Primitive → Semantic → Component) scales gracefully:

```
Primitive:     color-blue-500         (raw value: #3b82f6)
     ↓
Semantic:      color-primary          (references color-blue-500)
     ↓
Component:     button-background      (references color-primary)
```

Change the brand color once at the semantic level, and every component updates automatically.

### 4. Consistency Checking

With standard names, the plugin can identify inconsistencies and suggest improvements. Non-standard names make automated analysis impossible.

---

## Choosing Your Level

The library organizes tokens into three adoption levels:

| Level | Tokens | Best For |
|-------|--------|----------|
| **Starter (S)** | ~80 | Simple websites, getting started |
| **Comprehensive (C)** | ~150 | Most websites, typical needs |
| **Enterprise (E)** | ~300+ | Large sites, multi-brand systems |

### Starter Level

Use Starter if you're:
- Building a simple marketing site
- New to design systems
- Working solo or with a small team

Starter covers the essentials: primary/secondary colors, basic spacing, core typography.

### Comprehensive Level

Use Comprehensive if you're:
- Building a content-rich website
- Working with a design team
- Need multiple button styles, form states, or navigation patterns

Comprehensive adds variants (hover states, light/dark versions), additional components, and more granular spacing.

### Enterprise Level

Use Enterprise if you're:
- Managing multiple brand sites
- Building a component library
- Need detailed tokens for every edge case

Enterprise includes everything: every color shade, size variant, and component state.

**Recommendation:** Start with Starter, add Comprehensive tokens as needed, and only reach for Enterprise when you have a specific requirement.

---

## Examples

### Example 1: Setting Up Brand Colors

You've chosen blue as your primary brand color and purple as secondary.

**Step 1: Create primitive tokens (raw values)**
```
color-blue-500    →  #3b82f6
color-blue-600    →  #2563eb
color-blue-700    →  #1d4ed8
color-purple-500  →  #a855f7
```

**Step 2: Create semantic tokens (purpose)**
```
color-primary       →  references color-blue-500
color-primary-dark  →  references color-blue-700
color-secondary     →  references color-purple-500
```

**Step 3: Use in designs**

Instead of applying `#3b82f6` directly to elements, use `color-primary`. When the brand evolves, update the semantic token reference, and everything updates.

---

### Example 2: Spacing System

You want consistent spacing throughout your site.

**Define the primitive scale:**
```
space-4   →  8px
space-6   →  12px
space-8   →  16px
space-12  →  24px
space-16  →  32px
```

**Create semantic spacing tokens:**
```
space-xs  →  references space-4   (8px - tight spacing)
space-sm  →  references space-6   (12px - small elements)
space-md  →  references space-8   (16px - default)
space-lg  →  references space-12  (24px - generous)
space-xl  →  references space-16  (32px - sections)
```

**Apply to components:**
```
button-padding-x   →  references space-md  (16px horizontal)
button-padding-y   →  references space-sm  (12px vertical)
card-padding       →  references space-lg  (24px all sides)
section-spacing    →  references space-xl  (32px between sections)
```

Now "small spacing" means the same thing everywhere. Need more breathing room? Increase `space-sm` from 12px to 14px, and all small-spaced elements update.

---

### Example 3: Button Styles

You need primary, secondary, and danger buttons.

**Semantic colors (already defined):**
```
color-primary           →  #3b82f6
color-primary-dark      →  #1d4ed8
color-text-on-primary   →  #ffffff
color-danger            →  #ef4444
color-danger-dark       →  #b91c1c
```

**Component tokens for buttons:**

Primary Button:
```
button-background-primary        →  color-primary
button-background-primary-hover  →  color-primary-dark
button-text-primary              →  color-text-on-primary
```

Secondary Button:
```
button-background-secondary       →  color-surface-primary (white)
button-background-secondary-hover →  color-background-subtle (light gray)
button-text-secondary             →  color-text-primary
button-border-secondary           →  color-border-default
```

Danger Button:
```
button-background-danger       →  color-danger
button-background-danger-hover →  color-danger-dark
button-text-danger             →  color-text-on-primary
```

**Shared button tokens:**
```
button-padding-x    →  space-inline-md (16px)
button-padding-y    →  space-stack-sm (12px)
button-radius       →  radius-md (8px)
button-font-weight  →  font-weight-semibold (600)
```

Every button shares the same padding, radius, and font weight. Only colors differ by variant.

---

### Example 4: Form Inputs

You need text inputs with different states.

**Default state:**
```
input-background   →  color-surface-primary (white)
input-border       →  color-border-default (#e5e7eb)
input-text         →  color-text-primary (#111827)
input-placeholder  →  color-text-muted (#9ca3af)
```

**Focus state:**
```
input-border-focus  →  color-border-focus (#3b82f6)
```

**Error state:**
```
input-border-error  →  color-danger (#ef4444)
input-error-text    →  color-danger-text (#b91c1c)
```

**Disabled state:**
```
input-background-disabled  →  color-surface-disabled (#f3f4f6)
input-text-disabled        →  color-text-disabled (#d1d5db)
```

**Layout:**
```
input-padding-x   →  space-inline-md (16px)
input-padding-y   →  space-stack-sm (12px)
input-radius      →  radius-input (8px)
input-font-size   →  font-size-md (16px)
```

The same pattern applies to textareas, selects, and other form controls.

---

### Example 5: Card Component

A standard content card with image, title, and description.

**Surface & borders:**
```
card-background   →  color-surface-primary (white)
card-border       →  color-border-subtle (#f3f4f6)
card-shadow       →  shadow-sm (subtle elevation)
card-shadow-hover →  shadow-md (lifts on hover)
```

**Spacing:**
```
card-padding      →  space-inset-lg (24px all sides)
card-gap          →  space-stack-md (16px between elements)
card-radius       →  radius-card / radius-lg (12px)
```

**Typography (uses global text tokens):**
```
Title:       font-size-lg, font-weight-semibold, color-text-heading
Description: font-size-md, font-weight-normal, color-text-secondary
```

---

### Example 6: Migrating Existing Variables

You have existing variables with inconsistent names. Here's how to migrate:

**Current (problematic):**
```
myBlue         →  #3b82f6
darkBlue       →  #1d4ed8
mainTextColor  →  #111827
bigPadding     →  24px
btnRadius      →  8px
```

**Mapped to standard names:**

| Old Name | New Name | Tier |
|----------|----------|------|
| `myBlue` | `color-primary` | Semantic |
| `darkBlue` | `color-primary-dark` | Semantic |
| `mainTextColor` | `color-text-primary` | Semantic |
| `bigPadding` | `space-lg` | Semantic |
| `btnRadius` | `button-radius` | Component |

**Migration approach:**
1. Create new tokens with standard names
2. Update references in your designs
3. Delete old tokens
4. (Future) Use the plugin's Smart Rename tool when available

---

## Quick Reference

### Color Naming Pattern
```
color-{concept}-{variant}-{state}

Examples:
color-primary
color-primary-light
color-text-secondary
color-background-subtle
color-border-focus
color-success-text
```

### Spacing Naming Pattern
```
space-{size}           →  General spacing (xs, sm, md, lg, xl)
space-inline-{size}    →  Horizontal spacing
space-stack-{size}     →  Vertical spacing
space-inset-{size}     →  Padding (all sides)
```

### Typography Naming Pattern
```
font-{family}              →  font-sans, font-serif, font-mono
font-size-{size}           →  font-size-sm, font-size-lg
font-size-heading-{level}  →  font-size-heading-1, font-size-heading-2
font-weight-{weight}       →  font-weight-bold, font-weight-normal
line-height-{density}      →  line-height-tight, line-height-relaxed
```

### Component Naming Pattern
```
{component}-{property}-{variant}-{state}

Examples:
button-background-primary
button-background-primary-hover
card-padding
input-border-error
modal-shadow
```

---

## Common Mistakes to Avoid

| Mistake | Problem | Fix |
|---------|---------|-----|
| `blue-button` | Color might change | `button-primary` |
| `fontSize18` | Value in name | `font-size-lg` |
| `padding1` | Meaningless | `space-sm` |
| `headerBG` | Abbreviation + mixed case | `color-header-background` |
| `newRed` | Temporal reference | `color-accent` |
| `mainColor` | Vague | `color-primary` |

---

## Further Reading

For complete token lists and detailed specifications, see:

- **[naming-conventions.md](naming-conventions.md)** — Full naming rules and validation patterns
- **[primitive-tokens.md](primitive-tokens.md)** — Complete primitive token reference
- **[semantic-tokens.md](semantic-tokens.md)** — Complete semantic token reference
- **[component-tokens.md](component-tokens.md)** — Complete component token reference
- **[implementation-plan.md](implementation-plan.md)** — Roadmap for plugin integration

---

## Getting Help

If you're unsure which token to use:

1. **Check the category** — Is it a color, spacing, typography, or other?
2. **Check the tier** — Do you need a raw value (primitive), purpose (semantic), or component-specific?
3. **Check the level** — Start with Starter (S) tokens, expand as needed
4. **Search the library** — Use `token-library.json` or the markdown files

When in doubt, prefer:
- Semantic over primitive (purpose over value)
- Shorter over longer (avoid unnecessary specificity)
- Starter over Enterprise (simpler is better)

---

_Last updated: 2026-03-16_
