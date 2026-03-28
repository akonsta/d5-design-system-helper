# Component Tokens

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-03-16 | Initial document |
| 1.1 | 2026-03-27 | Cleaned for public release |

Component tokens are scoped to specific UI elements and reference semantic tokens. They enable precise control over individual component styling while maintaining design system consistency.

---

## Token Format

```
{component}-{property}-{variant}-{state}
```

Each token references a semantic token using the `{semantic-token}` syntax.

**Level Legend:**
- **S** = Starter (~50 tokens)
- **C** = Comprehensive (~150 tokens)
- **E** = Enterprise (~300+ tokens)

---

## Button Tokens

### Primary Button

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `button-background-primary` | `{color-primary}` | S | Primary button bg |
| `button-background-primary-hover` | `{color-primary-dark}` | S | Primary hover bg |
| `button-background-primary-active` | `{color-primary-dark}` | C | Primary active bg |
| `button-background-primary-disabled` | `{color-primary-light}` | C | Primary disabled bg |
| `button-text-primary` | `{color-text-on-primary}` | S | Primary button text |
| `button-text-primary-disabled` | `{color-text-inverse-secondary}` | E | Primary disabled text |
| `button-border-primary` | `{color-primary}` | C | Primary border |
| `button-border-primary-hover` | `{color-primary-dark}` | E | Primary hover border |

### Secondary Button

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `button-background-secondary` | `{color-surface-primary}` | S | Secondary button bg |
| `button-background-secondary-hover` | `{color-background-subtle}` | S | Secondary hover bg |
| `button-background-secondary-active` | `{color-background-muted}` | C | Secondary active bg |
| `button-background-secondary-disabled` | `{color-surface-disabled}` | C | Secondary disabled bg |
| `button-text-secondary` | `{color-text-primary}` | S | Secondary button text |
| `button-text-secondary-disabled` | `{color-text-disabled}` | C | Secondary disabled text |
| `button-border-secondary` | `{color-border-default}` | S | Secondary border |
| `button-border-secondary-hover` | `{color-border-strong}` | C | Secondary hover border |

### Tertiary/Ghost Button

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `button-background-tertiary` | `transparent` | C | Tertiary bg |
| `button-background-tertiary-hover` | `{color-background-subtle}` | C | Tertiary hover bg |
| `button-text-tertiary` | `{color-interactive-default}` | C | Tertiary text |
| `button-text-tertiary-hover` | `{color-interactive-hover}` | C | Tertiary hover text |

### Danger Button

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `button-background-danger` | `{color-danger}` | C | Danger button bg |
| `button-background-danger-hover` | `{color-danger-dark}` | C | Danger hover bg |
| `button-text-danger` | `{color-text-inverse}` | C | Danger button text |
| `button-border-danger` | `{color-danger}` | E | Danger border |

### Button Sizing

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `button-padding-x-sm` | `{space-inline-sm}` | C | Small horizontal padding |
| `button-padding-y-sm` | `{space-stack-xs}` | C | Small vertical padding |
| `button-padding-x` | `{space-inline-md}` | S | Default horizontal padding |
| `button-padding-y` | `{space-stack-sm}` | S | Default vertical padding |
| `button-padding-x-lg` | `{space-inline-lg}` | C | Large horizontal padding |
| `button-padding-y-lg` | `{space-stack-md}` | C | Large vertical padding |
| `button-font-size-sm` | `{font-size-sm}` | C | Small button text |
| `button-font-size` | `{font-size-md}` | S | Default button text |
| `button-font-size-lg` | `{font-size-lg}` | C | Large button text |
| `button-font-weight` | `{font-weight-semibold}` | S | Button text weight |
| `button-line-height` | `{line-height-tight}` | C | Button line height |
| `button-radius` | `{radius-button}` | S | Button border radius |
| `button-radius-sm` | `{radius-sm}` | E | Small button radius |
| `button-radius-lg` | `{radius-lg}` | E | Large button radius |
| `button-radius-full` | `{radius-full}` | C | Pill button radius |
| `button-min-width` | `{size-touch-target}` | E | Minimum button width |
| `button-min-height` | `{size-touch-target}` | C | Minimum button height |
| `button-gap` | `{space-inline-xs}` | C | Icon/text gap |
| `button-transition` | `{duration-fast}` | C | Transition duration |

---

## Card Tokens

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `card-background` | `{color-surface-primary}` | S | Card background |
| `card-background-hover` | `{color-surface-secondary}` | C | Card hover bg |
| `card-background-selected` | `{color-primary-subtle}` | E | Selected card bg |
| `card-border` | `{color-border-subtle}` | C | Card border color |
| `card-border-hover` | `{color-border-default}` | E | Card hover border |
| `card-border-selected` | `{color-border-primary}` | E | Selected card border |
| `card-border-width` | `1px` | C | Card border width |
| `card-padding` | `{space-inset-lg}` | S | Card padding |
| `card-padding-sm` | `{space-inset-md}` | C | Small card padding |
| `card-padding-lg` | `{space-inset-xl}` | E | Large card padding |
| `card-radius` | `{radius-card}` | S | Card border radius |
| `card-shadow` | `{shadow-card}` | S | Card shadow |
| `card-shadow-hover` | `{shadow-card-hover}` | C | Card hover shadow |
| `card-header-padding` | `{space-inset-md}` | C | Header padding |
| `card-body-padding` | `{space-inset-md}` | C | Body padding |
| `card-footer-padding` | `{space-inset-md}` | E | Footer padding |
| `card-gap` | `{space-stack-md}` | C | Internal spacing |

---

## Input Tokens

### Text Input

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `input-background` | `{color-surface-primary}` | S | Input background |
| `input-background-hover` | `{color-surface-primary}` | E | Input hover bg |
| `input-background-focus` | `{color-surface-primary}` | E | Input focus bg |
| `input-background-disabled` | `{color-surface-disabled}` | C | Input disabled bg |
| `input-background-error` | `{color-danger-light}` | E | Input error bg |
| `input-text` | `{color-text-primary}` | S | Input text |
| `input-text-placeholder` | `{color-text-placeholder}` | S | Placeholder text |
| `input-text-disabled` | `{color-text-disabled}` | C | Disabled text |
| `input-border` | `{color-border-default}` | S | Input border |
| `input-border-hover` | `{color-border-strong}` | C | Input hover border |
| `input-border-focus` | `{color-border-focus}` | S | Input focus border |
| `input-border-disabled` | `{color-border-disabled}` | C | Disabled border |
| `input-border-error` | `{color-danger}` | S | Error border |
| `input-border-success` | `{color-success}` | C | Success border |
| `input-border-width` | `1px` | C | Border width |
| `input-padding-x` | `{space-inline-md}` | S | Horizontal padding |
| `input-padding-y` | `{space-stack-sm}` | S | Vertical padding |
| `input-font-size` | `{font-size-md}` | S | Input text size |
| `input-line-height` | `{line-height-normal}` | C | Input line height |
| `input-radius` | `{radius-input}` | S | Input border radius |
| `input-min-height` | `{size-touch-target}` | C | Min input height |
| `input-focus-ring` | `{shadow-focus}` | C | Focus ring shadow |
| `input-transition` | `{duration-fast}` | E | Transition duration |

### Label & Helper Text

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `input-label-text` | `{color-text-primary}` | S | Label color |
| `input-label-font-size` | `{font-size-sm}` | S | Label size |
| `input-label-font-weight` | `{font-weight-medium}` | C | Label weight |
| `input-label-margin` | `{space-stack-xs}` | C | Label spacing |
| `input-helper-text` | `{color-text-secondary}` | C | Helper text color |
| `input-helper-font-size` | `{font-size-xs}` | C | Helper text size |
| `input-error-text` | `{color-danger-text}` | S | Error text color |
| `input-success-text` | `{color-success-text}` | C | Success text color |

### Select & Dropdown

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `select-background` | `{input-background}` | C | Select background |
| `select-border` | `{input-border}` | C | Select border |
| `select-icon` | `{color-text-secondary}` | C | Dropdown icon color |
| `select-option-background` | `{color-surface-primary}` | C | Option background |
| `select-option-background-hover` | `{color-background-subtle}` | C | Option hover bg |
| `select-option-background-selected` | `{color-primary-subtle}` | E | Selected option bg |
| `select-option-text` | `{color-text-primary}` | C | Option text |
| `select-option-text-selected` | `{color-primary}` | E | Selected option text |

### Checkbox & Radio

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `checkbox-background` | `{color-surface-primary}` | C | Unchecked bg |
| `checkbox-background-checked` | `{color-primary}` | C | Checked bg |
| `checkbox-border` | `{color-border-default}` | C | Unchecked border |
| `checkbox-border-checked` | `{color-primary}` | C | Checked border |
| `checkbox-checkmark` | `{color-text-inverse}` | C | Checkmark color |
| `checkbox-size` | `{size-20}` | C | Checkbox size |
| `checkbox-radius` | `{radius-sm}` | E | Checkbox radius |
| `radio-size` | `{size-20}` | C | Radio size |
| `radio-dot` | `{color-text-inverse}` | E | Radio dot color |

### Switch/Toggle

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `switch-background-off` | `{color-background-muted}` | C | Off state bg |
| `switch-background-on` | `{color-primary}` | C | On state bg |
| `switch-thumb` | `{color-surface-primary}` | C | Thumb color |
| `switch-width` | `{size-44}` | E | Switch width |
| `switch-height` | `{size-24}` | E | Switch height |
| `switch-thumb-size` | `{size-20}` | E | Thumb size |

---

## Navigation Tokens

### Navbar

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `navbar-background` | `{color-surface-primary}` | S | Navbar background |
| `navbar-background-scrolled` | `{color-surface-primary}` | E | Scrolled state bg |
| `navbar-border` | `{color-border-subtle}` | C | Bottom border |
| `navbar-text` | `{color-text-primary}` | S | Nav text color |
| `navbar-text-active` | `{color-primary}` | C | Active nav text |
| `navbar-height` | `{size-64}` | C | Navbar height |
| `navbar-height-mobile` | `{size-56}` | E | Mobile nav height |
| `navbar-padding-x` | `{space-page-margin}` | C | Horizontal padding |
| `navbar-shadow` | `{shadow-sm}` | C | Navbar shadow |
| `navbar-z-index` | `{z-index-sticky}` | C | Navbar z-index |

### Menu/Dropdown

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `menu-background` | `{color-surface-primary}` | C | Menu background |
| `menu-border` | `{color-border-subtle}` | C | Menu border |
| `menu-shadow` | `{shadow-dropdown}` | C | Menu shadow |
| `menu-radius` | `{radius-md}` | C | Menu radius |
| `menu-padding` | `{space-inset-xs}` | C | Menu padding |
| `menu-item-padding-x` | `{space-inline-md}` | C | Item h-padding |
| `menu-item-padding-y` | `{space-stack-sm}` | C | Item v-padding |
| `menu-item-background-hover` | `{color-background-subtle}` | C | Item hover bg |
| `menu-item-background-active` | `{color-primary-subtle}` | E | Item active bg |
| `menu-item-text` | `{color-text-primary}` | C | Item text |
| `menu-item-text-hover` | `{color-text-primary}` | E | Item hover text |
| `menu-item-text-active` | `{color-primary}` | E | Item active text |
| `menu-divider` | `{color-border-divider}` | E | Divider color |

### Tabs

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `tabs-background` | `transparent` | C | Tabs container bg |
| `tabs-border` | `{color-border-subtle}` | C | Tabs bottom border |
| `tab-text` | `{color-text-secondary}` | C | Tab text |
| `tab-text-hover` | `{color-text-primary}` | C | Tab hover text |
| `tab-text-active` | `{color-primary}` | C | Active tab text |
| `tab-indicator` | `{color-primary}` | C | Active indicator |
| `tab-padding-x` | `{space-inline-md}` | C | Tab h-padding |
| `tab-padding-y` | `{space-stack-sm}` | C | Tab v-padding |
| `tab-gap` | `{space-inline-sm}` | E | Tab spacing |

### Breadcrumb

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `breadcrumb-text` | `{color-text-secondary}` | E | Breadcrumb text |
| `breadcrumb-text-current` | `{color-text-primary}` | E | Current page text |
| `breadcrumb-separator` | `{color-text-muted}` | E | Separator color |
| `breadcrumb-gap` | `{space-inline-xs}` | E | Item spacing |

### Pagination

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `pagination-background` | `{color-surface-primary}` | E | Page item bg |
| `pagination-background-hover` | `{color-background-subtle}` | E | Page hover bg |
| `pagination-background-active` | `{color-primary}` | E | Active page bg |
| `pagination-text` | `{color-text-primary}` | E | Page text |
| `pagination-text-active` | `{color-text-inverse}` | E | Active page text |
| `pagination-border` | `{color-border-default}` | E | Page border |
| `pagination-radius` | `{radius-sm}` | E | Page radius |
| `pagination-size` | `{size-touch-target-sm}` | E | Page button size |
| `pagination-gap` | `{space-inline-xs}` | E | Page spacing |

---

## Modal Tokens

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `modal-background` | `{color-surface-primary}` | S | Modal background |
| `modal-border` | `{color-border-subtle}` | E | Modal border |
| `modal-shadow` | `{shadow-modal}` | S | Modal shadow |
| `modal-radius` | `{radius-modal}` | S | Modal radius |
| `modal-padding` | `{space-inset-lg}` | S | Modal padding |
| `modal-width-sm` | `{size-480}` | C | Small modal width |
| `modal-width-md` | `{size-640}` | S | Medium modal width |
| `modal-width-lg` | `{size-768}` | C | Large modal width |
| `modal-width-xl` | `{size-1024}` | E | XL modal width |
| `modal-max-height` | `90vh` | E | Max modal height |
| `modal-header-padding` | `{space-inset-md}` | C | Header padding |
| `modal-body-padding` | `{space-inset-md}` | C | Body padding |
| `modal-footer-padding` | `{space-inset-md}` | C | Footer padding |
| `modal-footer-gap` | `{space-inline-sm}` | E | Footer button gap |
| `modal-overlay` | `{color-background-overlay}` | S | Overlay color |
| `modal-z-index` | `{z-index-modal}` | C | Modal z-index |
| `modal-transition` | `{duration-normal}` | C | Animation duration |

---

## Alert/Notification Tokens

### Alert

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `alert-padding` | `{space-inset-md}` | C | Alert padding |
| `alert-radius` | `{radius-md}` | C | Alert radius |
| `alert-border-width` | `1px` | E | Border width |
| `alert-gap` | `{space-inline-sm}` | E | Icon/text gap |
| `alert-info-background` | `{color-info-light}` | C | Info alert bg |
| `alert-info-border` | `{color-info}` | E | Info alert border |
| `alert-info-text` | `{color-info-text}` | C | Info alert text |
| `alert-info-icon` | `{color-info}` | E | Info icon color |
| `alert-success-background` | `{color-success-light}` | C | Success alert bg |
| `alert-success-border` | `{color-success}` | E | Success border |
| `alert-success-text` | `{color-success-text}` | C | Success text |
| `alert-success-icon` | `{color-success}` | E | Success icon |
| `alert-warning-background` | `{color-warning-light}` | C | Warning alert bg |
| `alert-warning-border` | `{color-warning}` | E | Warning border |
| `alert-warning-text` | `{color-warning-text}` | C | Warning text |
| `alert-warning-icon` | `{color-warning}` | E | Warning icon |
| `alert-danger-background` | `{color-danger-light}` | C | Danger alert bg |
| `alert-danger-border` | `{color-danger}` | E | Danger border |
| `alert-danger-text` | `{color-danger-text}` | C | Danger text |
| `alert-danger-icon` | `{color-danger}` | E | Danger icon |

### Toast

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `toast-background` | `{color-surface-inverse}` | C | Toast bg |
| `toast-text` | `{color-text-inverse}` | C | Toast text |
| `toast-padding` | `{space-inset-md}` | E | Toast padding |
| `toast-radius` | `{radius-md}` | E | Toast radius |
| `toast-shadow` | `{shadow-lg}` | E | Toast shadow |
| `toast-z-index` | `{z-index-toast}` | E | Toast z-index |
| `toast-max-width` | `{size-480}` | E | Max toast width |
| `toast-gap` | `{space-stack-sm}` | E | Toast spacing |

---

## Badge/Tag Tokens

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `badge-padding-x` | `{space-inline-xs}` | C | Badge h-padding |
| `badge-padding-y` | `{space-2}` | C | Badge v-padding |
| `badge-font-size` | `{font-size-xs}` | C | Badge text size |
| `badge-font-weight` | `{font-weight-medium}` | E | Badge weight |
| `badge-radius` | `{radius-badge}` | C | Badge radius |
| `badge-default-background` | `{color-background-muted}` | C | Default badge bg |
| `badge-default-text` | `{color-text-primary}` | C | Default badge text |
| `badge-primary-background` | `{color-primary-subtle}` | C | Primary badge bg |
| `badge-primary-text` | `{color-primary}` | C | Primary badge text |
| `badge-success-background` | `{color-success-light}` | C | Success badge bg |
| `badge-success-text` | `{color-success-text}` | C | Success badge text |
| `badge-warning-background` | `{color-warning-light}` | C | Warning badge bg |
| `badge-warning-text` | `{color-warning-text}` | C | Warning badge text |
| `badge-danger-background` | `{color-danger-light}` | C | Danger badge bg |
| `badge-danger-text` | `{color-danger-text}` | C | Danger badge text |

---

## Tooltip Tokens

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `tooltip-background` | `{color-surface-inverse}` | C | Tooltip bg |
| `tooltip-text` | `{color-text-inverse}` | C | Tooltip text |
| `tooltip-padding-x` | `{space-inline-sm}` | E | Tooltip h-padding |
| `tooltip-padding-y` | `{space-stack-xs}` | E | Tooltip v-padding |
| `tooltip-font-size` | `{font-size-sm}` | E | Tooltip text size |
| `tooltip-radius` | `{radius-tooltip}` | E | Tooltip radius |
| `tooltip-shadow` | `{shadow-tooltip}` | E | Tooltip shadow |
| `tooltip-z-index` | `{z-index-tooltip}` | E | Tooltip z-index |
| `tooltip-max-width` | `{size-320}` | E | Max tooltip width |

---

## Avatar Tokens

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `avatar-background` | `{color-background-muted}` | C | Placeholder bg |
| `avatar-text` | `{color-text-secondary}` | C | Initials color |
| `avatar-border` | `{color-border-default}` | E | Avatar border |
| `avatar-border-width` | `2px` | E | Border width |
| `avatar-radius` | `{radius-avatar}` | C | Avatar radius |
| `avatar-size-xs` | `{size-avatar-xs}` | E | XS avatar |
| `avatar-size-sm` | `{size-avatar-sm}` | C | Small avatar |
| `avatar-size-md` | `{size-avatar-md}` | S | Medium avatar |
| `avatar-size-lg` | `{size-avatar-lg}` | C | Large avatar |
| `avatar-size-xl` | `{size-avatar-xl}` | E | XL avatar |
| `avatar-font-size-sm` | `{font-size-xs}` | E | Small initials |
| `avatar-font-size-md` | `{font-size-md}` | E | Medium initials |
| `avatar-font-size-lg` | `{font-size-xl}` | E | Large initials |
| `avatar-group-overlap` | `-{space-xs}` | E | Group overlap |
| `avatar-status-size` | `{size-12}` | E | Status dot size |
| `avatar-status-online` | `{color-success}` | E | Online status |
| `avatar-status-offline` | `{color-background-muted}` | E | Offline status |

---

## Table Tokens

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `table-background` | `{color-surface-primary}` | C | Table background |
| `table-border` | `{color-border-subtle}` | C | Table border |
| `table-radius` | `{radius-md}` | E | Table radius |
| `table-header-background` | `{color-background-subtle}` | C | Header row bg |
| `table-header-text` | `{color-text-primary}` | C | Header text |
| `table-header-font-weight` | `{font-weight-semibold}` | C | Header weight |
| `table-row-background` | `{color-surface-primary}` | C | Row background |
| `table-row-background-hover` | `{color-background-subtle}` | C | Row hover bg |
| `table-row-background-stripe` | `{color-background-subtle}` | E | Striped rows |
| `table-row-background-selected` | `{color-primary-subtle}` | E | Selected row |
| `table-cell-padding-x` | `{space-inline-md}` | C | Cell h-padding |
| `table-cell-padding-y` | `{space-stack-sm}` | C | Cell v-padding |
| `table-cell-text` | `{color-text-primary}` | C | Cell text |
| `table-divider` | `{color-border-divider}` | C | Row dividers |

---

## Progress/Loading Tokens

### Progress Bar

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `progress-background` | `{color-background-muted}` | C | Track background |
| `progress-fill` | `{color-primary}` | C | Progress fill |
| `progress-fill-success` | `{color-success}` | E | Success fill |
| `progress-fill-warning` | `{color-warning}` | E | Warning fill |
| `progress-fill-danger` | `{color-danger}` | E | Danger fill |
| `progress-height` | `{size-8}` | C | Bar height |
| `progress-height-sm` | `{size-4}` | E | Small bar |
| `progress-height-lg` | `{size-12}` | E | Large bar |
| `progress-radius` | `{radius-full}` | C | Bar radius |
| `progress-label-font-size` | `{font-size-xs}` | E | Label size |

### Spinner

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `spinner-color` | `{color-primary}` | C | Spinner color |
| `spinner-size-sm` | `{size-16}` | E | Small spinner |
| `spinner-size-md` | `{size-24}` | C | Medium spinner |
| `spinner-size-lg` | `{size-48}` | E | Large spinner |
| `spinner-width` | `2px` | E | Stroke width |
| `spinner-duration` | `{duration-slow}` | E | Spin duration |

### Skeleton

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `skeleton-background` | `{color-background-muted}` | E | Skeleton bg |
| `skeleton-highlight` | `{color-background-subtle}` | E | Shimmer highlight |
| `skeleton-radius` | `{radius-sm}` | E | Skeleton radius |
| `skeleton-text-height` | `{font-size-md}` | E | Text line height |

---

## Divider Tokens

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `divider-color` | `{color-border-divider}` | C | Divider color |
| `divider-width` | `1px` | C | Divider thickness |
| `divider-spacing` | `{space-lg}` | C | Vertical margin |
| `divider-spacing-sm` | `{space-md}` | E | Small margin |
| `divider-spacing-lg` | `{space-xl}` | E | Large margin |

---

## Footer Tokens

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `footer-background` | `{color-background-inverse}` | C | Footer bg |
| `footer-text` | `{color-text-inverse}` | C | Footer text |
| `footer-text-secondary` | `{color-text-inverse-secondary}` | E | Secondary text |
| `footer-link` | `{color-text-inverse}` | E | Footer link |
| `footer-link-hover` | `{color-primary-light}` | E | Link hover |
| `footer-padding-y` | `{space-section}` | C | Vertical padding |
| `footer-padding-x` | `{space-page-margin}` | E | Horizontal padding |
| `footer-border` | `{color-border-inverse}` | E | Footer border |

---

## Hero Section Tokens

| Token Name | References | Level | Description |
|------------|------------|-------|-------------|
| `hero-background` | `{color-background-page}` | E | Hero background |
| `hero-text` | `{color-text-heading}` | E | Hero text |
| `hero-padding-y` | `{space-section-lg}` | E | Vertical padding |
| `hero-min-height` | `{size-640}` | E | Minimum height |
| `hero-title-size` | `{font-size-display}` | E | Title size |
| `hero-subtitle-size` | `{font-size-xl}` | E | Subtitle size |
| `hero-gap` | `{space-lg}` | E | Content gap |

---

## Token Count by Level

| Level | Component Tokens | Cumulative Total |
|-------|------------------|------------------|
| Starter (S) | ~30 | ~30 |
| Comprehensive (C) | ~120 | ~150 |
| Enterprise (E) | ~110 | ~260 |

---

_Last updated: 2026-03-16_
