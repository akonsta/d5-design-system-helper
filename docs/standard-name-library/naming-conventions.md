# Naming Conventions

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-03-16 | Initial document |
| 1.1 | 2026-03-27 | Cleaned for public release |

This document defines the naming rules and patterns for the Standard Name Library, aligned with W3C DTCG conventions.

---

## Core Principles

### 1. Semantic Over Presentational

**Do:** Name tokens by purpose and usage
**Don't:** Name tokens by appearance or value

| Bad | Good | Why |
|-----|------|-----|
| `red-500` | `color-danger` | Purpose survives rebrand |
| `24px-spacing` | `space-lg` | Value can change |
| `blue-button` | `color-button-primary` | Semantic intent |
| `big-heading` | `font-size-display` | Scalable naming |

### 2. Consistent Structure

All tokens follow a hierarchical pattern:

```
{category}-{concept}-{property}-{variant}-{state}
```

Not all levels are required. Use only what's needed:

| Pattern | Example |
|---------|---------|
| category | `color` (too vague, avoid) |
| category-concept | `color-primary` |
| category-concept-property | `color-text-primary` |
| category-concept-variant | `color-primary-light` |
| category-concept-state | `color-primary-hover` |
| full pattern | `color-button-background-primary-hover` |

### 3. Kebab-Case Only

- All lowercase
- Words separated by hyphens
- No underscores, camelCase, or spaces

```
✓ color-text-primary
✓ font-size-body-lg
✗ colorTextPrimary
✗ color_text_primary
✗ Color-Text-Primary
```

---

## Naming Levels

### Level 1: Category (Required)

The category identifies the token type:

| Category | Description | Divi Type |
|----------|-------------|-----------|
| `color` | All color values | colors |
| `space` | Spacing (padding, margin, gap) | numbers |
| `size` | Dimensions (width, height) | numbers |
| `radius` | Border radius | numbers |
| `border` | Border width | numbers |
| `shadow` | Box shadows | numbers |
| `opacity` | Opacity values | numbers |
| `font` | Font families | fonts |
| `font-size` | Typography sizes | numbers |
| `font-weight` | Typography weights | numbers |
| `line-height` | Line heights | numbers |
| `letter-spacing` | Letter spacing | numbers |
| `duration` | Animation timing | numbers |
| `easing` | Animation curves | numbers |
| `z-index` | Stacking order | numbers |
| `breakpoint` | Responsive breakpoints | numbers |
| `image` | Image assets | images |
| `url` | Link URLs | links |
| `content` | Text content | text |

### Level 2: Concept (Recommended)

The concept describes the semantic purpose:

**Color Concepts:**
| Concept | Usage |
|---------|-------|
| `primary` | Main brand color |
| `secondary` | Supporting brand color |
| `accent` | Highlight/emphasis |
| `neutral` | Grays, backgrounds |
| `success` | Positive feedback |
| `warning` | Caution states |
| `danger` | Error/destructive |
| `info` | Informational |
| `text` | Text colors |
| `background` | Background fills |
| `border` | Border colors |
| `surface` | Card/panel fills |
| `overlay` | Modal/overlay fills |
| `interactive` | Clickable elements |

**Space Concepts:**
| Concept | Usage |
|---------|-------|
| `inline` | Horizontal spacing |
| `stack` | Vertical spacing |
| `inset` | Internal padding |
| `gutter` | Grid gutters |
| `section` | Section spacing |

**Typography Concepts:**
| Concept | Usage |
|---------|-------|
| `display` | Large headlines |
| `heading` | Section headings |
| `body` | Body text |
| `caption` | Small labels |
| `code` | Monospace text |

### Level 3: Property (Optional)

Further specifies the application:

| Property | Usage |
|----------|-------|
| `background` | Background application |
| `foreground` | Foreground/text |
| `border` | Border application |
| `fill` | Icon/SVG fill |
| `stroke` | Icon/SVG stroke |

### Level 4: Variant (Optional)

Scale or intensity modifiers:

**Numeric Scale:**
```
color-neutral-50   (lightest)
color-neutral-100
color-neutral-200
...
color-neutral-900  (darkest)
```

**T-Shirt Sizes:**
```
space-3xs, space-2xs, space-xs
space-sm, space-md, space-lg
space-xl, space-2xl, space-3xl
```

**Semantic Variants:**
```
color-primary-light
color-primary-default
color-primary-dark
```

### Level 5: State (Optional)

Interactive states:

| State | Usage |
|-------|-------|
| `default` | Normal state (often omitted) |
| `hover` | Mouse hover |
| `focus` | Keyboard focus |
| `active` | Being pressed |
| `disabled` | Inactive/unavailable |
| `selected` | Currently selected |
| `visited` | Visited link |

---

## Token Tier Rules

### Primitive Tokens

- Named by **value/scale**, not purpose
- Form the foundation palette
- Rarely referenced directly in code

```
// Colors: hue + scale
color-blue-50, color-blue-500, color-blue-900
color-gray-100, color-gray-500, color-gray-900

// Spacing: numeric scale
space-0, space-1, space-2, space-4, space-8, space-16

// Typography: numeric scale
font-size-12, font-size-14, font-size-16, font-size-24
```

### Semantic Tokens

- Named by **purpose**, not value
- Reference primitive tokens
- Primary tokens used in designs

```
// References primitive
color-primary: {color-blue-500}
color-text-primary: {color-gray-900}
color-background-page: {color-gray-50}

space-inline-sm: {space-2}
space-stack-md: {space-4}

font-size-body: {font-size-16}
font-size-heading-1: {font-size-32}
```

### Component Tokens

- Named by **component + property**
- Reference semantic tokens
- Scoped to specific UI elements

```
// Button
button-background-primary: {color-primary}
button-background-primary-hover: {color-primary-dark}
button-text-primary: {color-text-on-primary}
button-padding-x: {space-inline-md}
button-padding-y: {space-stack-sm}
button-radius: {radius-md}

// Card
card-background: {color-surface-primary}
card-border: {color-border-subtle}
card-padding: {space-inset-lg}
card-radius: {radius-lg}
card-shadow: {shadow-md}
```

---

## Divi 5 Specific Considerations

### System Variables

Divi 5 has built-in system variables with fixed names:

| Divi Name | Recommended Alias |
|-----------|-------------------|
| Primary Color | `color-primary` |
| Secondary Color | `color-secondary` |
| Heading Text Color | `color-text-heading` |
| Body Text Color | `color-text-body` |
| Link Color | `color-text-link` |
| Heading Font | `font-heading` |
| Body Font | `font-body` |

### ID vs Label

- **ID** (`gcid-xxx`, `gvid-xxx`): Auto-generated, immutable
- **Label**: User-visible name, follows these conventions

The Standard Name Library applies to **labels**, not IDs.

### Color References

When a color references another via `$variable()`, the label should indicate the relationship:

```
color-primary → #2563eb
color-primary-light → {color-primary} @ 20% lighter
color-primary-hover → {color-primary} @ 10% darker
```

---

## Anti-Patterns

### Avoid These Naming Mistakes

| Anti-Pattern | Problem | Fix |
|--------------|---------|-----|
| `myBlue` | Personal/arbitrary | `color-primary` |
| `color1` | Meaningless | `color-primary` |
| `newRed` | Temporal | `color-accent` |
| `headerBgColor` | Mixed conventions | `color-header-background` |
| `largePadding` | Vague scale | `space-lg` |
| `fontSize18` | Value in name | `font-size-body` |
| `btnPrimary` | Abbreviation | `button-primary` |
| `TEXT_COLOR` | Wrong case | `color-text` |

### Reserved Words

Avoid these as standalone names (too vague):
- `default`, `main`, `base`, `standard`
- `normal`, `regular`, `medium`
- `color`, `size`, `space` (without qualifier)

---

## Validation Rules

The plugin will validate names against these rules:

1. **Format**: Must be kebab-case (`^[a-z][a-z0-9-]*$`)
2. **Category**: Must start with a valid category prefix
3. **Length**: 3-50 characters
4. **No Numbers at Start**: `1-color` is invalid
5. **No Double Hyphens**: `color--primary` is invalid
6. **No Trailing Hyphens**: `color-primary-` is invalid

### Warning vs Error

| Severity | Condition |
|----------|-----------|
| Error | Invalid format (wrong case, special chars) |
| Error | Missing category prefix |
| Warning | Presentational name detected |
| Warning | Inconsistent with similar tokens |
| Info | Name not in standard library |

---

_Last updated: 2026-03-16_
