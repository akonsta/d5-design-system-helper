# Semantic Tokens

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-03-16 | Initial document |
| 1.1 | 2026-03-27 | Cleaned for public release |

Semantic tokens are purpose-based aliases that reference primitive tokens. They form the primary design language used in your design system.

---

## Token Format

```
{category}-{concept}-{property}-{variant}-{state}
```

Each token references a primitive using the `{primitive-token}` syntax.

**Level Legend:**
- **S** = Starter (~50 tokens)
- **C** = Comprehensive (~150 tokens)
- **E** = Enterprise (~300+ tokens)

---

## Color Tokens

### Brand Colors

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `color-primary` | `{color-blue-500}` | S | Main brand color |
| `color-primary-light` | `{color-blue-300}` | C | Lighter primary variant |
| `color-primary-dark` | `{color-blue-700}` | C | Darker primary variant |
| `color-primary-subtle` | `{color-blue-50}` | E | Very light primary tint |
| `color-secondary` | `{color-purple-500}` | S | Supporting brand color |
| `color-secondary-light` | `{color-purple-300}` | C | Lighter secondary variant |
| `color-secondary-dark` | `{color-purple-700}` | C | Darker secondary variant |
| `color-secondary-subtle` | `{color-purple-50}` | E | Very light secondary tint |
| `color-accent` | `{color-amber-500}` | S | Highlight/emphasis color |
| `color-accent-light` | `{color-amber-300}` | C | Lighter accent variant |
| `color-accent-dark` | `{color-amber-700}` | C | Darker accent variant |

### Text Colors

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `color-text-primary` | `{color-gray-900}` | S | Main body text |
| `color-text-secondary` | `{color-gray-600}` | S | Supporting text |
| `color-text-muted` | `{color-gray-400}` | S | De-emphasized text |
| `color-text-disabled` | `{color-gray-300}` | C | Disabled state text |
| `color-text-inverse` | `{color-white}` | S | Text on dark backgrounds |
| `color-text-inverse-secondary` | `{color-gray-200}` | E | Secondary text on dark |
| `color-text-heading` | `{color-gray-900}` | S | Heading text color |
| `color-text-body` | `{color-gray-700}` | S | Body text color |
| `color-text-link` | `{color-blue-600}` | S | Link text color |
| `color-text-link-hover` | `{color-blue-700}` | C | Link hover state |
| `color-text-link-visited` | `{color-purple-600}` | E | Visited link color |
| `color-text-on-primary` | `{color-white}` | S | Text on primary color |
| `color-text-on-secondary` | `{color-white}` | C | Text on secondary color |
| `color-text-on-accent` | `{color-gray-900}` | C | Text on accent color |
| `color-text-placeholder` | `{color-gray-400}` | C | Placeholder text |

### Background Colors

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `color-background-page` | `{color-white}` | S | Page background |
| `color-background-subtle` | `{color-gray-50}` | S | Subtle background tint |
| `color-background-muted` | `{color-gray-100}` | C | Muted background |
| `color-background-emphasis` | `{color-gray-200}` | E | Emphasized sections |
| `color-background-inverse` | `{color-gray-900}` | S | Dark background |
| `color-background-inverse-subtle` | `{color-gray-800}` | E | Subtle dark background |
| `color-background-overlay` | `{color-black-alpha-50}` | C | Modal overlay |
| `color-background-overlay-light` | `{color-black-alpha-25}` | E | Light overlay |
| `color-background-primary` | `{color-primary}` | C | Primary brand background |
| `color-background-secondary` | `{color-secondary}` | C | Secondary brand background |
| `color-background-accent` | `{color-accent}` | C | Accent background |

### Surface Colors

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `color-surface-primary` | `{color-white}` | S | Primary surface (cards) |
| `color-surface-secondary` | `{color-gray-50}` | C | Secondary surface |
| `color-surface-tertiary` | `{color-gray-100}` | E | Tertiary surface |
| `color-surface-elevated` | `{color-white}` | C | Elevated elements |
| `color-surface-sunken` | `{color-gray-100}` | E | Recessed elements |
| `color-surface-inverse` | `{color-gray-800}` | C | Dark mode surface |
| `color-surface-disabled` | `{color-gray-100}` | C | Disabled surface |

### Border Colors

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `color-border-default` | `{color-gray-200}` | S | Default border |
| `color-border-subtle` | `{color-gray-100}` | C | Subtle border |
| `color-border-strong` | `{color-gray-400}` | C | Strong/emphasis border |
| `color-border-inverse` | `{color-gray-700}` | E | Border on dark |
| `color-border-focus` | `{color-blue-500}` | S | Focus ring color |
| `color-border-disabled` | `{color-gray-200}` | C | Disabled border |
| `color-border-primary` | `{color-primary}` | C | Primary colored border |
| `color-border-divider` | `{color-gray-200}` | C | Divider lines |

### Status Colors

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `color-success` | `{color-green-500}` | S | Success/positive |
| `color-success-light` | `{color-green-100}` | C | Success background |
| `color-success-dark` | `{color-green-700}` | E | Success emphasis |
| `color-success-text` | `{color-green-700}` | C | Success text |
| `color-warning` | `{color-amber-500}` | S | Warning/caution |
| `color-warning-light` | `{color-amber-100}` | C | Warning background |
| `color-warning-dark` | `{color-amber-700}` | E | Warning emphasis |
| `color-warning-text` | `{color-amber-700}` | C | Warning text |
| `color-danger` | `{color-red-500}` | S | Error/destructive |
| `color-danger-light` | `{color-red-100}` | C | Error background |
| `color-danger-dark` | `{color-red-700}` | E | Error emphasis |
| `color-danger-text` | `{color-red-700}` | C | Error text |
| `color-info` | `{color-blue-500}` | S | Informational |
| `color-info-light` | `{color-blue-100}` | C | Info background |
| `color-info-dark` | `{color-blue-700}` | E | Info emphasis |
| `color-info-text` | `{color-blue-700}` | C | Info text |

### Interactive Colors

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `color-interactive-default` | `{color-blue-600}` | C | Clickable elements |
| `color-interactive-hover` | `{color-blue-700}` | C | Hover state |
| `color-interactive-active` | `{color-blue-800}` | C | Active/pressed state |
| `color-interactive-focus` | `{color-blue-500}` | C | Focus state |
| `color-interactive-disabled` | `{color-gray-300}` | C | Disabled interactive |
| `color-interactive-visited` | `{color-purple-600}` | E | Visited state |

---

## Spacing Tokens

### General Spacing

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `space-none` | `{space-0}` | S | Zero spacing |
| `space-3xs` | `{space-1}` | E | Tiny spacing (2px) |
| `space-2xs` | `{space-2}` | C | Extra extra small (4px) |
| `space-xs` | `{space-4}` | S | Extra small (8px) |
| `space-sm` | `{space-6}` | S | Small (12px) |
| `space-md` | `{space-8}` | S | Medium (16px) |
| `space-lg` | `{space-12}` | S | Large (24px) |
| `space-xl` | `{space-16}` | S | Extra large (32px) |
| `space-2xl` | `{space-24}` | C | Extra extra large (48px) |
| `space-3xl` | `{space-32}` | C | Triple extra large (64px) |
| `space-4xl` | `{space-48}` | E | Quadruple extra large (96px) |
| `space-5xl` | `{space-64}` | E | Quintuple extra large (128px) |

### Directional Spacing

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `space-inline-xs` | `{space-xs}` | C | Horizontal tiny |
| `space-inline-sm` | `{space-sm}` | S | Horizontal small |
| `space-inline-md` | `{space-md}` | S | Horizontal medium |
| `space-inline-lg` | `{space-lg}` | S | Horizontal large |
| `space-inline-xl` | `{space-xl}` | C | Horizontal extra large |
| `space-stack-xs` | `{space-xs}` | C | Vertical tiny |
| `space-stack-sm` | `{space-sm}` | S | Vertical small |
| `space-stack-md` | `{space-md}` | S | Vertical medium |
| `space-stack-lg` | `{space-lg}` | S | Vertical large |
| `space-stack-xl` | `{space-xl}` | C | Vertical extra large |

### Inset (Padding) Spacing

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `space-inset-xs` | `{space-xs}` | C | Internal padding tiny |
| `space-inset-sm` | `{space-sm}` | S | Internal padding small |
| `space-inset-md` | `{space-md}` | S | Internal padding medium |
| `space-inset-lg` | `{space-lg}` | S | Internal padding large |
| `space-inset-xl` | `{space-xl}` | C | Internal padding extra large |
| `space-inset-squish-sm` | `{space-2} {space-4}` | E | Squished padding small |
| `space-inset-squish-md` | `{space-4} {space-8}` | E | Squished padding medium |
| `space-inset-stretch-sm` | `{space-6} {space-4}` | E | Stretched padding small |
| `space-inset-stretch-md` | `{space-8} {space-6}` | E | Stretched padding medium |

### Layout Spacing

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `space-gutter` | `{space-md}` | S | Grid gutter |
| `space-gutter-sm` | `{space-sm}` | C | Small grid gutter |
| `space-gutter-lg` | `{space-lg}` | C | Large grid gutter |
| `space-section` | `{space-3xl}` | S | Section spacing |
| `space-section-sm` | `{space-2xl}` | C | Small section spacing |
| `space-section-lg` | `{space-4xl}` | E | Large section spacing |
| `space-page-margin` | `{space-lg}` | C | Page margins |
| `space-page-margin-mobile` | `{space-md}` | E | Mobile page margins |

---

## Size Tokens

### Icon Sizes

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `size-icon-xs` | `{size-12}` | C | Extra small icons |
| `size-icon-sm` | `{size-16}` | S | Small icons |
| `size-icon-md` | `{size-24}` | S | Medium icons |
| `size-icon-lg` | `{size-32}` | S | Large icons |
| `size-icon-xl` | `{size-48}` | C | Extra large icons |
| `size-icon-2xl` | `{size-64}` | E | Hero icons |

### Avatar Sizes

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `size-avatar-xs` | `{size-24}` | E | Extra small avatar |
| `size-avatar-sm` | `{size-32}` | C | Small avatar |
| `size-avatar-md` | `{size-48}` | S | Medium avatar |
| `size-avatar-lg` | `{size-64}` | C | Large avatar |
| `size-avatar-xl` | `{size-96}` | E | Extra large avatar |
| `size-avatar-2xl` | `{size-128}` | E | Profile avatar |

### Touch Target

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `size-touch-target` | `{size-44}` | S | Minimum touch target |
| `size-touch-target-sm` | `{size-36}` | C | Small touch target |
| `size-touch-target-lg` | `{size-56}` | E | Large touch target |

### Container Widths

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `size-container-xs` | `{size-480}` | E | Extra small container |
| `size-container-sm` | `{size-640}` | C | Small container |
| `size-container-md` | `{size-768}` | S | Medium container |
| `size-container-lg` | `{size-1024}` | S | Large container |
| `size-container-xl` | `{size-1280}` | C | Extra large container |
| `size-container-2xl` | `{size-1440}` | E | Max container |

---

## Border Radius Tokens

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `radius-none` | `{radius-0}` | S | No radius |
| `radius-xs` | `{radius-2}` | E | Extra small radius |
| `radius-sm` | `{radius-4}` | S | Small radius |
| `radius-md` | `{radius-8}` | S | Medium radius |
| `radius-lg` | `{radius-12}` | S | Large radius |
| `radius-xl` | `{radius-16}` | C | Extra large radius |
| `radius-2xl` | `{radius-24}` | E | Double extra large |
| `radius-full` | `{radius-9999}` | S | Fully round (pills) |
| `radius-button` | `{radius-md}` | C | Button corners |
| `radius-card` | `{radius-lg}` | C | Card corners |
| `radius-input` | `{radius-md}` | C | Input field corners |
| `radius-modal` | `{radius-xl}` | C | Modal corners |
| `radius-badge` | `{radius-full}` | C | Badge/pill corners |
| `radius-avatar` | `{radius-full}` | C | Avatar corners |
| `radius-tooltip` | `{radius-sm}` | E | Tooltip corners |

---

## Shadow Tokens

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `shadow-none` | `{shadow-0}` | S | No shadow |
| `shadow-xs` | `{shadow-1}` | E | Extra subtle shadow |
| `shadow-sm` | `{shadow-2}` | S | Subtle shadow |
| `shadow-md` | `{shadow-3}` | S | Medium shadow |
| `shadow-lg` | `{shadow-4}` | S | Large shadow |
| `shadow-xl` | `{shadow-5}` | C | Extra large shadow |
| `shadow-2xl` | `{shadow-6}` | E | Dramatic shadow |
| `shadow-inner` | `{shadow-inner}` | C | Inset shadow |
| `shadow-card` | `{shadow-sm}` | C | Card shadow |
| `shadow-card-hover` | `{shadow-md}` | C | Card hover shadow |
| `shadow-dropdown` | `{shadow-lg}` | C | Dropdown shadow |
| `shadow-modal` | `{shadow-xl}` | C | Modal shadow |
| `shadow-tooltip` | `{shadow-md}` | E | Tooltip shadow |
| `shadow-focus` | `{shadow-focus-ring}` | C | Focus ring shadow |

---

## Typography Tokens

### Font Family

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `font-sans` | `{font-inter}` | S | Primary sans-serif |
| `font-serif` | `{font-georgia}` | C | Serif fallback |
| `font-mono` | `{font-fira-code}` | S | Monospace |
| `font-display` | `{font-inter}` | E | Display headings |
| `font-heading` | `{font-sans}` | S | Headings |
| `font-body` | `{font-sans}` | S | Body text |
| `font-ui` | `{font-sans}` | E | UI elements |

### Font Size

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `font-size-xs` | `{font-size-12}` | S | Extra small text |
| `font-size-sm` | `{font-size-14}` | S | Small text |
| `font-size-md` | `{font-size-16}` | S | Medium/body text |
| `font-size-lg` | `{font-size-18}` | S | Large text |
| `font-size-xl` | `{font-size-20}` | C | Extra large text |
| `font-size-2xl` | `{font-size-24}` | C | Heading 4 |
| `font-size-3xl` | `{font-size-30}` | C | Heading 3 |
| `font-size-4xl` | `{font-size-36}` | C | Heading 2 |
| `font-size-5xl` | `{font-size-48}` | E | Heading 1 |
| `font-size-6xl` | `{font-size-60}` | E | Display small |
| `font-size-7xl` | `{font-size-72}` | E | Display medium |
| `font-size-8xl` | `{font-size-96}` | E | Display large |
| `font-size-9xl` | `{font-size-128}` | E | Display hero |
| `font-size-body` | `{font-size-md}` | S | Body text size |
| `font-size-body-sm` | `{font-size-sm}` | C | Small body text |
| `font-size-body-lg` | `{font-size-lg}` | C | Large body text |
| `font-size-caption` | `{font-size-xs}` | C | Caption text |
| `font-size-label` | `{font-size-sm}` | C | Label text |
| `font-size-heading-1` | `{font-size-5xl}` | S | H1 size |
| `font-size-heading-2` | `{font-size-4xl}` | S | H2 size |
| `font-size-heading-3` | `{font-size-3xl}` | S | H3 size |
| `font-size-heading-4` | `{font-size-2xl}` | S | H4 size |
| `font-size-heading-5` | `{font-size-xl}` | C | H5 size |
| `font-size-heading-6` | `{font-size-lg}` | C | H6 size |
| `font-size-display` | `{font-size-6xl}` | C | Display text |
| `font-size-display-lg` | `{font-size-8xl}` | E | Large display |

### Font Weight

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `font-weight-thin` | `{font-weight-100}` | E | Thin weight |
| `font-weight-light` | `{font-weight-300}` | C | Light weight |
| `font-weight-normal` | `{font-weight-400}` | S | Normal/regular |
| `font-weight-medium` | `{font-weight-500}` | S | Medium weight |
| `font-weight-semibold` | `{font-weight-600}` | S | Semi-bold |
| `font-weight-bold` | `{font-weight-700}` | S | Bold weight |
| `font-weight-extrabold` | `{font-weight-800}` | C | Extra bold |
| `font-weight-black` | `{font-weight-900}` | E | Black weight |
| `font-weight-body` | `{font-weight-normal}` | C | Body text weight |
| `font-weight-heading` | `{font-weight-bold}` | C | Heading weight |

### Line Height

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `line-height-none` | `{line-height-100}` | S | No leading (1) |
| `line-height-tight` | `{line-height-125}` | S | Tight leading |
| `line-height-snug` | `{line-height-137}` | C | Snug leading |
| `line-height-normal` | `{line-height-150}` | S | Normal leading |
| `line-height-relaxed` | `{line-height-162}` | C | Relaxed leading |
| `line-height-loose` | `{line-height-200}` | C | Loose leading |
| `line-height-body` | `{line-height-normal}` | C | Body text leading |
| `line-height-heading` | `{line-height-tight}` | C | Heading leading |

### Letter Spacing

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `letter-spacing-tighter` | `{letter-spacing--2}` | C | Very tight |
| `letter-spacing-tight` | `{letter-spacing--1}` | S | Tight tracking |
| `letter-spacing-normal` | `{letter-spacing-0}` | S | Normal tracking |
| `letter-spacing-wide` | `{letter-spacing-1}` | S | Wide tracking |
| `letter-spacing-wider` | `{letter-spacing-2}` | C | Very wide |
| `letter-spacing-widest` | `{letter-spacing-4}` | E | Extra wide |
| `letter-spacing-heading` | `{letter-spacing-tight}` | C | Heading tracking |
| `letter-spacing-body` | `{letter-spacing-normal}` | C | Body tracking |
| `letter-spacing-caps` | `{letter-spacing-wide}` | C | Uppercase tracking |

---

## Animation Tokens

### Duration

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `duration-instant` | `{duration-0}` | S | No duration |
| `duration-fastest` | `{duration-50}` | E | 50ms |
| `duration-faster` | `{duration-100}` | C | 100ms |
| `duration-fast` | `{duration-150}` | S | 150ms |
| `duration-normal` | `{duration-200}` | S | 200ms default |
| `duration-slow` | `{duration-300}` | S | 300ms |
| `duration-slower` | `{duration-500}` | C | 500ms |
| `duration-slowest` | `{duration-700}` | E | 700ms |
| `duration-deliberate` | `{duration-1000}` | E | 1000ms |
| `duration-hover` | `{duration-fast}` | C | Hover transitions |
| `duration-modal` | `{duration-normal}` | C | Modal animations |
| `duration-page` | `{duration-slow}` | C | Page transitions |

### Easing

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `easing-linear` | `{easing-linear}` | S | Linear motion |
| `easing-ease` | `{easing-ease}` | S | Default ease |
| `easing-ease-in` | `{easing-ease-in}` | S | Accelerating |
| `easing-ease-out` | `{easing-ease-out}` | S | Decelerating |
| `easing-ease-in-out` | `{easing-ease-in-out}` | S | Smooth both |
| `easing-spring` | `{easing-spring}` | E | Spring physics |
| `easing-bounce` | `{easing-bounce}` | E | Bounce effect |
| `easing-enter` | `{easing-ease-out}` | C | Enter animations |
| `easing-exit` | `{easing-ease-in}` | C | Exit animations |
| `easing-move` | `{easing-ease-in-out}` | C | Movement |

---

## Z-Index Tokens

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `z-index-deep` | `{z-index--1}` | E | Behind base |
| `z-index-base` | `{z-index-0}` | S | Default layer |
| `z-index-raised` | `{z-index-1}` | C | Slightly raised |
| `z-index-dropdown` | `{z-index-1000}` | S | Dropdown menus |
| `z-index-sticky` | `{z-index-1100}` | C | Sticky elements |
| `z-index-banner` | `{z-index-1200}` | E | Banner overlays |
| `z-index-overlay` | `{z-index-1300}` | C | Overlays |
| `z-index-modal` | `{z-index-1400}` | S | Modal dialogs |
| `z-index-popover` | `{z-index-1500}` | C | Popovers |
| `z-index-tooltip` | `{z-index-1600}` | C | Tooltips |
| `z-index-toast` | `{z-index-1700}` | C | Toast messages |
| `z-index-max` | `{z-index-9999}` | E | Maximum layer |

---

## Breakpoint Tokens

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `breakpoint-xs` | `{breakpoint-375}` | E | Extra small devices |
| `breakpoint-sm` | `{breakpoint-640}` | S | Small devices |
| `breakpoint-md` | `{breakpoint-768}` | S | Medium devices |
| `breakpoint-lg` | `{breakpoint-1024}` | S | Large devices |
| `breakpoint-xl` | `{breakpoint-1280}` | S | Extra large devices |
| `breakpoint-2xl` | `{breakpoint-1536}` | C | 2x extra large |
| `breakpoint-mobile` | `{breakpoint-sm}` | C | Mobile breakpoint |
| `breakpoint-tablet` | `{breakpoint-md}` | C | Tablet breakpoint |
| `breakpoint-desktop` | `{breakpoint-lg}` | C | Desktop breakpoint |
| `breakpoint-wide` | `{breakpoint-xl}` | C | Wide breakpoint |

---

## Opacity Tokens

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `opacity-0` | `{opacity-0}` | S | Invisible |
| `opacity-5` | `{opacity-5}` | E | Nearly invisible |
| `opacity-10` | `{opacity-10}` | C | Very faint |
| `opacity-25` | `{opacity-25}` | C | Faint |
| `opacity-50` | `{opacity-50}` | S | Half opacity |
| `opacity-75` | `{opacity-75}` | C | Mostly visible |
| `opacity-90` | `{opacity-90}` | E | Nearly opaque |
| `opacity-100` | `{opacity-100}` | S | Fully opaque |
| `opacity-disabled` | `{opacity-50}` | S | Disabled state |
| `opacity-hover` | `{opacity-75}` | C | Hover state |
| `opacity-overlay` | `{opacity-50}` | C | Overlay opacity |
| `opacity-backdrop` | `{opacity-75}` | E | Backdrop opacity |

---

## Token Count by Level

| Level | Count | Cumulative |
|-------|-------|------------|
| Starter (S) | ~80 | ~80 |
| Comprehensive (C) | ~100 | ~180 |
| Enterprise (E) | ~70 | ~250 |

---

_Last updated: 2026-03-16_

---

*Source: [github.com/akonsta/d5-design-system-helper](https://github.com/akonsta/d5-design-system-helper)*
