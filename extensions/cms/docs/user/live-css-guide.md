# Live CSS Editor Guide

This guide covers the Pulsar CMS Live CSS editor, including editing theme tokens, writing custom CSS, previewing changes, saving with audit reasons, rollback, and CSP considerations.

## Overview

The Live CSS editor allows administrators to customize the site's appearance without editing theme files directly. Changes are stored as CSS overrides in the database, versioned with reasons, and served to visitors with appropriate Content Security Policy (CSP) hashes.

## Accessing the Live CSS Editor

<!-- Screenshot: Live CSS editor interface -->

1. Navigate to **Admin > CMS > Live CSS** (`/admin/cms/live-css`).
2. The editor opens with:
  - The current active CSS override (if any)
  - A list of available theme tokens
  - A live preview panel

## Theme Tokens

Theme tokens are CSS custom properties defined by the active theme. They provide a structured way to customize the theme's appearance.

### Available Tokens

The `ThemeTokenResolver` reads tokens from the active theme's manifest settings. Common tokens include:

| Token               | Example Value              | Description            |
| ------------------- | -------------------------- | ---------------------- |
| `--primary-color`   | `#003366`                  | Primary brand color    |
| `--secondary-color` | `#667788`                  | Secondary accent color |
| `--font-family`     | `'Inter', sans-serif`      | Base font family       |
| `--heading-font`    | `'Montserrat', sans-serif` | Heading font family    |
| `--border-radius`   | `4px`                      | Default border radius  |
| `--spacing-unit`    | `8px`                      | Base spacing unit      |

### Editing Tokens

1. In the Live CSS editor, locate the **Theme Tokens** panel.
2. Modify token values using the provided inputs.
3. The preview updates in real time.
4. Token changes are compiled into CSS custom property overrides.

## Writing Custom CSS

Beyond theme tokens, you can write arbitrary custom CSS in the editor.

### Editor Features

The CSS editor provides:

- Syntax highlighting
- Auto-completion for CSS properties
- Real-time validation
- Error highlighting for invalid CSS

### Example

```css
/* Override heading styles */
.content-body h2 {
  border-bottom: 2px solid var(--primary-color);
  padding-bottom: 0.5em;
}

/* Custom card component */
.feature-card {
  background: var(--secondary-color);
  border-radius: var(--border-radius);
  padding: calc(var(--spacing-unit) * 3);
}
```

### CSS Validation

The `CssValidator` checks all CSS before saving:

- Syntax validation (the CSS must parse correctly)
- Property validation (no dangerous properties like `-moz-binding`)
- URL validation (external URLs must be HTTPS)
- `@import` rules are blocked to prevent external stylesheet injection
- External font loading via `@font-face` is controlled by configuration

### Size Limit

The maximum CSS content length is configurable:

```php
'live_css' => [
    'max_css_length' => 100000,  // 100,000 characters
],
```

## Preview

### Live Preview

As you type in the CSS editor, the preview panel updates in real time:

1. The preview renders a sample page with the active theme.
2. Your CSS changes are injected into the preview frame.
3. Changes in the preview do not affect the live site until you save.

### Full-Page Preview

Click **Full Preview** to open a new browser tab showing the entire site with your proposed changes applied. This preview is scoped to your session only.

## Saving Changes

### Save with Reason

When saving CSS changes, you must provide a reason:

<!-- Screenshot: Save dialog with reason field -->

1. Click **Save** in the editor.
2. Enter a descriptive reason for the change (e.g., "Updated brand colors for Q1 campaign").
3. Confirm the save.

The reason is stored with the CSS override record for audit purposes.

### CSS Override Record

Each saved override creates a `CssOverride` record:

| Field       | Description                             |
| ----------- | --------------------------------------- |
| ID          | Unique identifier                       |
| CSS Content | The full CSS text                       |
| Author ID   | The user who made the change            |
| Reason      | Human-readable change description       |
| Created At  | Timestamp                               |
| Active      | Whether this override is currently live |

## Version History

### Viewing History

Navigate to **Admin > CMS > Live CSS > History** (`/admin/cms/live-css/history`).

The history shows all saved CSS overrides with:

- Author name
- Reason for the change
- Timestamp
- Whether the override is currently active

### Comparing Versions

Select two versions to see a diff of the CSS changes between them.

## Rollback

### Rolling Back to a Previous Version

1. Navigate to **Admin > CMS > Live CSS > History**.
2. Find the version you want to restore.
3. Click **Rollback** on that version.
4. Confirm the rollback.

```
POST /admin/cms/live-css/{id}/rollback
```

The selected version becomes the active override, and a new history entry is created recording the rollback action.

### Removing All Overrides

To remove all custom CSS and revert to the pure theme styles:

1. Navigate to the Live CSS editor.
2. Clear all CSS content.
3. Save with a reason (e.g., "Reverted to default theme styles").

## CSP Considerations

### Content Security Policy

Pulsar CMS enforces a Content Security Policy (CSP) that restricts inline styles. The Live CSS system integrates with CSP through hash-based allowlisting.

### How CSP Hashes Work

The `CspHashComputer` generates SHA-256 hashes of the active CSS override:

1. When a CSS override is saved, its SHA-256 hash is computed.
2. The hash is included in the CSP `style-src` directive.
3. The browser allows the inline `<style>` block because its hash matches the CSP allowlist.

This approach:

- Avoids `'unsafe-inline'` in the CSP policy
- Each CSS version has a unique hash
- Old hashes are automatically invalidated when CSS changes

### CSP Header Example

```
Content-Security-Policy: style-src 'self' 'sha256-abc123...' ...
```

### External Fonts

By default, external font loading is blocked for security:

```php
'live_css' => [
    'allow_external_fonts' => false,
],
```

When enabled, `@font-face` rules with external URLs (HTTPS only) are permitted. The CSP `font-src` directive is updated accordingly.

## CSS Injection into Pages

The `LiveCssInjector` handles serving the active CSS to visitors:

1. On each page request, the injector checks for an active CSS override.
2. If present, the CSS is injected as a `<style>` block in the page `<head>`.
3. The corresponding CSP hash is added to the response headers.
4. The CSS is cached for performance (invalidated when the override changes).

## Configuration Reference

| Key                             | Type | Default  | Description                         |
| ------------------------------- | ---- | -------- | ----------------------------------- |
| `live_css.enabled`              | bool | `true`   | Enable the Live CSS editor          |
| `live_css.max_css_length`       | int  | `100000` | Maximum CSS content length          |
| `live_css.allow_external_fonts` | bool | `false`  | Allow @font-face with external URLs |

## Permissions

| Permission             | Role  | Description                        |
| ---------------------- | ----- | ---------------------------------- |
| `cms.livecss.view`     | Admin | View the Live CSS editor           |
| `cms.livecss.edit`     | Admin | Edit and save CSS overrides        |
| `cms.livecss.rollback` | Admin | Roll back to previous CSS versions |

## API Reference

| Method | Route                               | Description             |
| ------ | ----------------------------------- | ----------------------- |
| GET    | `/admin/cms/live-css`               | Open the editor         |
| POST   | `/admin/cms/live-css`               | Save a new CSS override |
| POST   | `/admin/cms/live-css/{id}/rollback` | Rollback to a version   |
| GET    | `/admin/cms/live-css/history`       | View version history    |

## Next Steps

- [Theme Management](theme-management.md) - Understanding theme tokens and structure
- [Settings Reference](settings-reference.md) - Live CSS configuration
- [Security Model](../security/security-model.md) - CSP policy details
