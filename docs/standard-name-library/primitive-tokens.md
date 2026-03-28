# Primitive Tokens

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-03-16 | Initial document |
| 1.1 | 2026-03-27 | Cleaned for public release |

Primitive tokens are the foundational values of a design system. They are named by their **value or scale**, not by purpose. Semantic tokens reference these primitives.

---

## Color Primitives

### Neutral Scale

| Token | Level | Example Value | Notes |
|-------|-------|---------------|-------|
| `color-white` | Starter | `#ffffff` | Pure white |
| `color-black` | Starter | `#000000` | Pure black |
| `color-gray-50` | Starter | `#f9fafb` | Lightest gray |
| `color-gray-100` | Starter | `#f3f4f6` | |
| `color-gray-200` | Comprehensive | `#e5e7eb` | |
| `color-gray-300` | Comprehensive | `#d1d5db` | |
| `color-gray-400` | Comprehensive | `#9ca3af` | |
| `color-gray-500` | Starter | `#6b7280` | Mid gray |
| `color-gray-600` | Comprehensive | `#4b5563` | |
| `color-gray-700` | Comprehensive | `#374151` | |
| `color-gray-800` | Starter | `#1f2937` | |
| `color-gray-900` | Starter | `#111827` | Darkest gray |
| `color-gray-950` | Enterprise | `#030712` | Near black |

### Blue Scale (Primary)

| Token | Level | Example Value |
|-------|-------|---------------|
| `color-blue-50` | Comprehensive | `#eff6ff` |
| `color-blue-100` | Comprehensive | `#dbeafe` |
| `color-blue-200` | Comprehensive | `#bfdbfe` |
| `color-blue-300` | Comprehensive | `#93c5fd` |
| `color-blue-400` | Comprehensive | `#60a5fa` |
| `color-blue-500` | Starter | `#3b82f6` |
| `color-blue-600` | Starter | `#2563eb` |
| `color-blue-700` | Comprehensive | `#1d4ed8` |
| `color-blue-800` | Comprehensive | `#1e40af` |
| `color-blue-900` | Comprehensive | `#1e3a8a` |

### Green Scale (Success)

| Token | Level | Example Value |
|-------|-------|---------------|
| `color-green-50` | Comprehensive | `#f0fdf4` |
| `color-green-100` | Comprehensive | `#dcfce7` |
| `color-green-200` | Enterprise | `#bbf7d0` |
| `color-green-300` | Enterprise | `#86efac` |
| `color-green-400` | Enterprise | `#4ade80` |
| `color-green-500` | Starter | `#22c55e` |
| `color-green-600` | Starter | `#16a34a` |
| `color-green-700` | Comprehensive | `#15803d` |
| `color-green-800` | Enterprise | `#166534` |
| `color-green-900` | Enterprise | `#14532d` |

### Red Scale (Danger)

| Token | Level | Example Value |
|-------|-------|---------------|
| `color-red-50` | Comprehensive | `#fef2f2` |
| `color-red-100` | Comprehensive | `#fee2e2` |
| `color-red-200` | Enterprise | `#fecaca` |
| `color-red-300` | Enterprise | `#fca5a5` |
| `color-red-400` | Enterprise | `#f87171` |
| `color-red-500` | Starter | `#ef4444` |
| `color-red-600` | Starter | `#dc2626` |
| `color-red-700` | Comprehensive | `#b91c1c` |
| `color-red-800` | Enterprise | `#991b1b` |
| `color-red-900` | Enterprise | `#7f1d1d` |

### Yellow/Amber Scale (Warning)

| Token | Level | Example Value |
|-------|-------|---------------|
| `color-yellow-50` | Comprehensive | `#fefce8` |
| `color-yellow-100` | Comprehensive | `#fef9c3` |
| `color-yellow-200` | Enterprise | `#fef08a` |
| `color-yellow-300` | Enterprise | `#fde047` |
| `color-yellow-400` | Comprehensive | `#facc15` |
| `color-yellow-500` | Starter | `#eab308` |
| `color-yellow-600` | Comprehensive | `#ca8a04` |
| `color-yellow-700` | Enterprise | `#a16207` |
| `color-yellow-800` | Enterprise | `#854d0e` |
| `color-yellow-900` | Enterprise | `#713f12` |

### Additional Color Scales (Enterprise)

<details>
<summary>Purple Scale</summary>

| Token | Level | Example Value |
|-------|-------|---------------|
| `color-purple-50` | Enterprise | `#faf5ff` |
| `color-purple-100` | Enterprise | `#f3e8ff` |
| `color-purple-200` | Enterprise | `#e9d5ff` |
| `color-purple-300` | Enterprise | `#d8b4fe` |
| `color-purple-400` | Enterprise | `#c084fc` |
| `color-purple-500` | Enterprise | `#a855f7` |
| `color-purple-600` | Enterprise | `#9333ea` |
| `color-purple-700` | Enterprise | `#7e22ce` |
| `color-purple-800` | Enterprise | `#6b21a8` |
| `color-purple-900` | Enterprise | `#581c87` |

</details>

<details>
<summary>Pink Scale</summary>

| Token | Level | Example Value |
|-------|-------|---------------|
| `color-pink-50` | Enterprise | `#fdf2f8` |
| `color-pink-100` | Enterprise | `#fce7f3` |
| `color-pink-200` | Enterprise | `#fbcfe8` |
| `color-pink-300` | Enterprise | `#f9a8d4` |
| `color-pink-400` | Enterprise | `#f472b6` |
| `color-pink-500` | Enterprise | `#ec4899` |
| `color-pink-600` | Enterprise | `#db2777` |
| `color-pink-700` | Enterprise | `#be185d` |
| `color-pink-800` | Enterprise | `#9d174d` |
| `color-pink-900` | Enterprise | `#831843` |

</details>

<details>
<summary>Orange Scale</summary>

| Token | Level | Example Value |
|-------|-------|---------------|
| `color-orange-50` | Enterprise | `#fff7ed` |
| `color-orange-100` | Enterprise | `#ffedd5` |
| `color-orange-200` | Enterprise | `#fed7aa` |
| `color-orange-300` | Enterprise | `#fdba74` |
| `color-orange-400` | Enterprise | `#fb923c` |
| `color-orange-500` | Enterprise | `#f97316` |
| `color-orange-600` | Enterprise | `#ea580c` |
| `color-orange-700` | Enterprise | `#c2410c` |
| `color-orange-800` | Enterprise | `#9a3412` |
| `color-orange-900` | Enterprise | `#7c2d12` |

</details>

<details>
<summary>Teal Scale</summary>

| Token | Level | Example Value |
|-------|-------|---------------|
| `color-teal-50` | Enterprise | `#f0fdfa` |
| `color-teal-100` | Enterprise | `#ccfbf1` |
| `color-teal-200` | Enterprise | `#99f6e4` |
| `color-teal-300` | Enterprise | `#5eead4` |
| `color-teal-400` | Enterprise | `#2dd4bf` |
| `color-teal-500` | Enterprise | `#14b8a6` |
| `color-teal-600` | Enterprise | `#0d9488` |
| `color-teal-700` | Enterprise | `#0f766e` |
| `color-teal-800` | Enterprise | `#115e59` |
| `color-teal-900` | Enterprise | `#134e4a` |

</details>

---

## Spacing Primitives

### Numeric Scale (Base: 4px)

| Token | Level | Value | Notes |
|-------|-------|-------|-------|
| `space-0` | Starter | `0` | Zero spacing |
| `space-px` | Comprehensive | `1px` | Hairline |
| `space-0-5` | Comprehensive | `0.125rem` | 2px |
| `space-1` | Starter | `0.25rem` | 4px |
| `space-1-5` | Comprehensive | `0.375rem` | 6px |
| `space-2` | Starter | `0.5rem` | 8px |
| `space-2-5` | Comprehensive | `0.625rem` | 10px |
| `space-3` | Starter | `0.75rem` | 12px |
| `space-3-5` | Comprehensive | `0.875rem` | 14px |
| `space-4` | Starter | `1rem` | 16px (base) |
| `space-5` | Comprehensive | `1.25rem` | 20px |
| `space-6` | Starter | `1.5rem` | 24px |
| `space-7` | Comprehensive | `1.75rem` | 28px |
| `space-8` | Starter | `2rem` | 32px |
| `space-9` | Comprehensive | `2.25rem` | 36px |
| `space-10` | Comprehensive | `2.5rem` | 40px |
| `space-11` | Enterprise | `2.75rem` | 44px |
| `space-12` | Starter | `3rem` | 48px |
| `space-14` | Comprehensive | `3.5rem` | 56px |
| `space-16` | Starter | `4rem` | 64px |
| `space-20` | Comprehensive | `5rem` | 80px |
| `space-24` | Comprehensive | `6rem` | 96px |
| `space-28` | Enterprise | `7rem` | 112px |
| `space-32` | Comprehensive | `8rem` | 128px |
| `space-36` | Enterprise | `9rem` | 144px |
| `space-40` | Enterprise | `10rem` | 160px |
| `space-44` | Enterprise | `11rem` | 176px |
| `space-48` | Enterprise | `12rem` | 192px |
| `space-52` | Enterprise | `13rem` | 208px |
| `space-56` | Enterprise | `14rem` | 224px |
| `space-60` | Enterprise | `15rem` | 240px |
| `space-64` | Enterprise | `16rem` | 256px |
| `space-72` | Enterprise | `18rem` | 288px |
| `space-80` | Enterprise | `20rem` | 320px |
| `space-96` | Enterprise | `24rem` | 384px |

---

## Typography Primitives

### Font Size Scale

| Token | Level | Value | Use Case |
|-------|-------|-------|----------|
| `font-size-2xs` | Enterprise | `0.625rem` | 10px, fine print |
| `font-size-xs` | Starter | `0.75rem` | 12px, captions |
| `font-size-sm` | Starter | `0.875rem` | 14px, secondary text |
| `font-size-base` | Starter | `1rem` | 16px, body text |
| `font-size-lg` | Starter | `1.125rem` | 18px, lead text |
| `font-size-xl` | Starter | `1.25rem` | 20px, h6 |
| `font-size-2xl` | Starter | `1.5rem` | 24px, h5 |
| `font-size-3xl` | Starter | `1.875rem` | 30px, h4 |
| `font-size-4xl` | Comprehensive | `2.25rem` | 36px, h3 |
| `font-size-5xl` | Comprehensive | `3rem` | 48px, h2 |
| `font-size-6xl` | Comprehensive | `3.75rem` | 60px, h1 |
| `font-size-7xl` | Enterprise | `4.5rem` | 72px, display |
| `font-size-8xl` | Enterprise | `6rem` | 96px, hero |
| `font-size-9xl` | Enterprise | `8rem` | 128px, jumbo |

### Font Weight Scale

| Token | Level | Value |
|-------|-------|-------|
| `font-weight-thin` | Enterprise | `100` |
| `font-weight-extralight` | Enterprise | `200` |
| `font-weight-light` | Comprehensive | `300` |
| `font-weight-normal` | Starter | `400` |
| `font-weight-medium` | Starter | `500` |
| `font-weight-semibold` | Starter | `600` |
| `font-weight-bold` | Starter | `700` |
| `font-weight-extrabold` | Comprehensive | `800` |
| `font-weight-black` | Enterprise | `900` |

### Line Height Scale

| Token | Level | Value |
|-------|-------|-------|
| `line-height-none` | Comprehensive | `1` |
| `line-height-tight` | Starter | `1.25` |
| `line-height-snug` | Comprehensive | `1.375` |
| `line-height-normal` | Starter | `1.5` |
| `line-height-relaxed` | Starter | `1.625` |
| `line-height-loose` | Comprehensive | `2` |

### Letter Spacing Scale

| Token | Level | Value |
|-------|-------|-------|
| `letter-spacing-tighter` | Comprehensive | `-0.05em` |
| `letter-spacing-tight` | Starter | `-0.025em` |
| `letter-spacing-normal` | Starter | `0` |
| `letter-spacing-wide` | Starter | `0.025em` |
| `letter-spacing-wider` | Comprehensive | `0.05em` |
| `letter-spacing-widest` | Comprehensive | `0.1em` |

---

## Border Radius Primitives

| Token | Level | Value |
|-------|-------|-------|
| `radius-none` | Starter | `0` |
| `radius-sm` | Starter | `0.125rem` |
| `radius-default` | Starter | `0.25rem` |
| `radius-md` | Starter | `0.375rem` |
| `radius-lg` | Starter | `0.5rem` |
| `radius-xl` | Comprehensive | `0.75rem` |
| `radius-2xl` | Comprehensive | `1rem` |
| `radius-3xl` | Comprehensive | `1.5rem` |
| `radius-full` | Starter | `9999px` |

---

## Shadow Primitives

| Token | Level | Value |
|-------|-------|-------|
| `shadow-none` | Starter | `none` |
| `shadow-sm` | Starter | `0 1px 2px 0 rgb(0 0 0 / 0.05)` |
| `shadow-default` | Starter | `0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)` |
| `shadow-md` | Starter | `0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)` |
| `shadow-lg` | Comprehensive | `0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)` |
| `shadow-xl` | Comprehensive | `0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1)` |
| `shadow-2xl` | Enterprise | `0 25px 50px -12px rgb(0 0 0 / 0.25)` |
| `shadow-inner` | Comprehensive | `inset 0 2px 4px 0 rgb(0 0 0 / 0.05)` |

---

## Opacity Primitives

| Token | Level | Value |
|-------|-------|-------|
| `opacity-0` | Starter | `0` |
| `opacity-5` | Comprehensive | `0.05` |
| `opacity-10` | Comprehensive | `0.1` |
| `opacity-20` | Comprehensive | `0.2` |
| `opacity-25` | Starter | `0.25` |
| `opacity-30` | Comprehensive | `0.3` |
| `opacity-40` | Comprehensive | `0.4` |
| `opacity-50` | Starter | `0.5` |
| `opacity-60` | Comprehensive | `0.6` |
| `opacity-70` | Comprehensive | `0.7` |
| `opacity-75` | Starter | `0.75` |
| `opacity-80` | Comprehensive | `0.8` |
| `opacity-90` | Comprehensive | `0.9` |
| `opacity-95` | Comprehensive | `0.95` |
| `opacity-100` | Starter | `1` |

---

## Animation Primitives

### Duration

| Token | Level | Value |
|-------|-------|-------|
| `duration-0` | Enterprise | `0ms` |
| `duration-75` | Comprehensive | `75ms` |
| `duration-100` | Comprehensive | `100ms` |
| `duration-150` | Starter | `150ms` |
| `duration-200` | Starter | `200ms` |
| `duration-300` | Starter | `300ms` |
| `duration-500` | Comprehensive | `500ms` |
| `duration-700` | Comprehensive | `700ms` |
| `duration-1000` | Enterprise | `1000ms` |

### Easing

| Token | Level | Value |
|-------|-------|-------|
| `easing-linear` | Comprehensive | `linear` |
| `easing-ease` | Starter | `ease` |
| `easing-ease-in` | Starter | `ease-in` |
| `easing-ease-out` | Starter | `ease-out` |
| `easing-ease-in-out` | Starter | `ease-in-out` |
| `easing-sharp` | Comprehensive | `cubic-bezier(0.4, 0, 0.6, 1)` |
| `easing-spring` | Enterprise | `cubic-bezier(0.175, 0.885, 0.32, 1.275)` |

---

## Z-Index Primitives

| Token | Level | Value |
|-------|-------|-------|
| `z-index-auto` | Starter | `auto` |
| `z-index-0` | Starter | `0` |
| `z-index-10` | Starter | `10` |
| `z-index-20` | Comprehensive | `20` |
| `z-index-30` | Comprehensive | `30` |
| `z-index-40` | Comprehensive | `40` |
| `z-index-50` | Starter | `50` |
| `z-index-100` | Enterprise | `100` |
| `z-index-dropdown` | Comprehensive | `1000` |
| `z-index-sticky` | Comprehensive | `1020` |
| `z-index-fixed` | Comprehensive | `1030` |
| `z-index-modal-backdrop` | Enterprise | `1040` |
| `z-index-modal` | Starter | `1050` |
| `z-index-popover` | Comprehensive | `1060` |
| `z-index-tooltip` | Comprehensive | `1070` |
| `z-index-toast` | Enterprise | `1080` |
| `z-index-max` | Enterprise | `9999` |

---

## Breakpoint Primitives

| Token | Level | Value | Target |
|-------|-------|-------|--------|
| `breakpoint-xs` | Enterprise | `320px` | Small phones |
| `breakpoint-sm` | Starter | `640px` | Large phones |
| `breakpoint-md` | Starter | `768px` | Tablets |
| `breakpoint-lg` | Starter | `1024px` | Laptops |
| `breakpoint-xl` | Starter | `1280px` | Desktops |
| `breakpoint-2xl` | Comprehensive | `1536px` | Large screens |
| `breakpoint-3xl` | Enterprise | `1920px` | Extra large |

---

## Summary by Level

| Level | Token Count |
|-------|-------------|
| Starter | ~85 |
| Comprehensive | ~75 additional |
| Enterprise | ~140 additional |
| **Total** | **~300** |

---

_Last updated: 2026-03-16_
