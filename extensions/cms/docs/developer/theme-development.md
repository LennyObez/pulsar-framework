# Theme Development Guide

This guide covers everything needed to build, package, and distribute themes for the Pulsar CMS.

## Theme Directory Structure

A theme is a ZIP archive containing the following directory structure:

```
my-theme/
  theme.json          # Theme manifest (required)
  templates/
    article.html      # Template for article content type
    page.html         # Template for page content type
    layout.html       # Base layout template
  assets/
    css/
      main.css        # Theme stylesheet
    js/
      main.js         # Theme JavaScript
    images/
      logo.svg        # Theme images
  tokens.json         # Editable CSS custom properties (optional)
```

## Theme Manifest (`theme.json`)

The manifest declares all theme metadata. It is parsed into a `ThemeManifest` DTO.

### Required Fields

| Field          | Type   | Description                                                                                                                    |
| -------------- | ------ | ------------------------------------------------------------------------------------------------------------------------------ |
| `slug`         | string | URL-safe identifier. Lowercase alphanumeric with hyphens, 1-200 characters. Must start and end with an alphanumeric character. |
| `display_name` | string | Human-readable theme name shown in the admin panel. Alternative key: `name`.                                                   |
| `version`      | string | SemVer version string (e.g., `1.0.0`, `2.1.3-beta.1`).                                                                         |

### Optional Fields

| Field                     | Type   | Description                                                                        |
| ------------------------- | ------ | ---------------------------------------------------------------------------------- |
| `description`             | string | Theme description for marketplace listing.                                         |
| `author_name`             | string | Theme author name for attribution.                                                 |
| `author_url`              | string | URL to the author's website or profile.                                            |
| `license`                 | string | SPDX license identifier (e.g., `MIT`, `GPL-3.0-only`).                             |
| `pulsar_version`          | string | Required Pulsar framework version constraint (e.g., `^1.0.0`).                     |
| `parent_theme`            | string | Slug of a parent theme for template inheritance.                                   |
| `regions`                 | list   | Template regions declared by this theme (e.g., `["header", "sidebar", "footer"]`). |
| `supported_content_types` | list   | Content types this theme provides templates for (e.g., `["article", "page"]`).     |
| `settings`                | object | Theme-specific configurable settings (key-value pairs).                            |
| `assets`                  | object | Asset path mapping: logical name to relative file path.                            |

### Example Manifest

```json
{
  "slug": "corporate-clean",
  "display_name": "Corporate Clean",
  "version": "1.0.0",
  "description": "A clean, professional theme for corporate websites.",
  "author_name": "Acme Themes",
  "author_url": "https://example.com",
  "license": "MIT",
  "pulsar_version": "^1.0.0",
  "parent_theme": null,
  "regions": ["header", "sidebar", "footer", "hero"],
  "supported_content_types": ["article", "page"],
  "settings": {
    "primary_color": "#1a73e8",
    "font_family": "Inter, sans-serif",
    "show_sidebar": true
  },
  "assets": {
    "stylesheet": "assets/css/main.css",
    "script": "assets/js/main.js",
    "logo": "assets/images/logo.svg"
  }
}
```

## Manifest Validation

The `ThemeManifestValidatorInterface` validates manifests against these rules:

**Errors (block installation):**

- `slug` must be present, 1-200 characters, matching pattern `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`
- `display_name` (or `name`) must be present and non-empty
- `version` must be present and follow SemVer format (`^\d+\.\d+\.\d+(?:-[\w.]+)?(?:\+[\w.]+)?$`)

**Warnings (non-blocking):**

- Missing `description` -- recommended for marketplace listing
- Missing `author_name` -- recommended for attribution
- Missing `license` -- recommended for compliance

## Template Conventions

Themes provide templates for each content type. The template file name must match the content type's machine identifier:

- `templates/article.html` -- Renders `ContentType::Article`
- `templates/page.html` -- Renders `ContentType::Page`
- `templates/layout.html` -- Base layout used by all content type templates

Custom content types registered by plugins also need corresponding template files. If a content type has no matching template, the CMS falls back to the parent theme (if declared) or the default rendering.

### Template Override via Content

Individual content items can override the theme template using the `template` field on the `Content` entity. When set (e.g., `"landing-page"`), the CMS looks for `templates/landing-page.html` in the active theme before falling back to the content type template.

## Editable Tokens (CSS Custom Properties)

Themes can expose CSS custom properties that editors can modify through the Live CSS editor without touching theme source files.

### Defining Tokens

Create a `tokens.json` file in the theme root:

```json
{
  "tokens": [
    {
      "name": "--primary-color",
      "label": "Primary Color",
      "type": "color",
      "default": "#1a73e8"
    },
    {
      "name": "--font-family",
      "label": "Body Font",
      "type": "font",
      "default": "Inter, sans-serif"
    },
    {
      "name": "--border-radius",
      "label": "Border Radius",
      "type": "dimension",
      "default": "8px"
    },
    {
      "name": "--content-max-width",
      "label": "Content Max Width",
      "type": "dimension",
      "default": "1200px"
    }
  ]
}
```

### Token Types

| Type        | Description       | Example Values                                       |
| ----------- | ----------------- | ---------------------------------------------------- |
| `color`     | CSS color value   | `#1a73e8`, `rgb(26, 115, 232)`, `hsl(217, 80%, 51%)` |
| `font`      | Font family stack | `Inter, sans-serif`                                  |
| `dimension` | CSS length value  | `8px`, `1rem`, `1200px`                              |

### Using Tokens in CSS

Reference tokens as CSS custom properties in your theme stylesheet:

```css
:root {
  --primary-color: #1a73e8;
  --font-family: Inter, sans-serif;
  --border-radius: 8px;
  --content-max-width: 1200px;
}

body {
  font-family: var(--font-family);
  color: var(--text-color);
}

.btn-primary {
  background: var(--primary-color);
  border-radius: var(--border-radius);
}

.container {
  max-width: var(--content-max-width);
}
```

When an editor saves token overrides through the Live CSS editor, the CMS generates an inline `<style>` block that overrides the `:root` custom properties:

```html
<style>
  :root {
    --primary-color: #e91e63;
    --font-family: 'Roboto', sans-serif;
  }
</style>
```

The `ThemeTokenResolverInterface` resolves available tokens from the active theme, and `LiveCssServiceInterface` manages the override lifecycle with full version history.

## Asset Packaging

### Directory Structure

Place all static assets under the `assets/` directory:

```
assets/
  css/
    main.css         # Primary stylesheet
    components/      # Component styles (optional)
  js/
    main.js          # Primary script
  images/
    logo.svg
    favicon.ico
  fonts/
    custom-font.woff2
```

### Asset Resolution

The `ThemeAssetResolverInterface` resolves logical asset names (declared in `theme.json` under `assets`) to their physical paths within the theme's storage directory. The resolution respects the `assetDeployMode` setting:

- **`copy`** (default) -- Assets are copied to the public web directory during installation.
- **`symlink`** -- Assets are symlinked from the theme storage to the public web directory.

### CSS Bundling

Theme stylesheets should be self-contained. Use CSS `@import` statements for modular organization:

```css
@import 'components/buttons.css';
@import 'components/cards.css';
@import 'components/navigation.css';
```

### JavaScript

Theme scripts are loaded after the page content. Use standard DOM APIs or the Pulsar JavaScript utilities provided by the framework.

## Safety Contracts

### What Themes Can Do

- Provide HTML templates for content rendering
- Define CSS stylesheets and JavaScript
- Expose editable CSS tokens
- Declare template regions for widget placement
- Inherit from a parent theme
- Include static assets (images, fonts, icons)

### What Themes Cannot Do

- Execute server-side PHP code
- Access the database directly
- Modify other themes or plugins
- Access the container or framework internals
- Include executable binaries
- Make outbound network requests

### Trust Tiers

| Tier         | Requirement                         | Protections                                                                            |
| ------------ | ----------------------------------- | -------------------------------------------------------------------------------------- |
| **Unsigned** | `requireSignedThemes: false`        | Manifest validation, Zip Slip protection, file count/size limits                       |
| **Signed**   | `requireSignedThemes: true`         | All unsigned protections + Ed25519 signature verification against trusted public keys  |
| **Verified** | Signature + integrity check on boot | All signed protections + runtime file integrity verification on every application boot |

Configuration in `config/cms.php`:

```php
return [
    'themes' => [
        'require_signed_themes' => true,
        'trusted_public_keys' => [
            'base64-encoded-ed25519-public-key',
        ],
        'integrity_check_on_boot' => true,
        'max_archive_size' => 52_428_800,  // 50 MB
        'max_file_count' => 10_000,
    ],
];
```

## Theme Lifecycle

### Installation

```
Upload archive --> Extract (Zip Slip protected) --> Validate manifest
  --> Verify provenance (if required) --> Store files --> Create DB record
  --> ThemeInstalled event dispatched
```

### Activation

```
activate(themeId) --> Deactivate current theme --> Set new theme as active
  --> ThemeActivated event dispatched
```

Only one theme can be active at a time per tenant.

### Preview

```
preview(themeId) --> Create PreviewSession with token and expiration
  --> Theme rendered only for the previewing user
```

Preview sessions are time-limited and bound to a specific user.

### Rollback

```
rollback() --> Reactivate previously active theme
  --> ThemeActivated event dispatched for the restored theme
```

### Deletion

```
delete(themeId) --> Verify theme is not active --> Soft-delete record
  --> Remove theme files --> ThemeDeleted event dispatched
```

Active themes cannot be deleted. Deactivate first, then delete.

## Complete Minimal Theme Example

### `theme.json`

```json
{
  "slug": "minimal-starter",
  "display_name": "Minimal Starter",
  "version": "1.0.0",
  "description": "A minimal starter theme for Pulsar CMS.",
  "author_name": "Your Name",
  "license": "MIT",
  "pulsar_version": "^1.0.0",
  "regions": ["header", "footer"],
  "supported_content_types": ["article", "page"],
  "assets": {
    "stylesheet": "assets/css/main.css"
  }
}
```

### `templates/layout.html`

```html
<!doctype html>
<html lang="{{ locale }}">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ meta_title ?? title }} | {{ site_name }}</title>
    <link rel="stylesheet" href="{{ asset('stylesheet') }}" />
    {{ seo_meta_tags }}
  </head>
  <body>
    <header>{{ region('header') }}</header>
    <main>{{ content }}</main>
    <footer>{{ region('footer') }}</footer>
  </body>
</html>
```

### `templates/article.html`

```html
{{ extend('layout') }}

<article>
  <h1>{{ title }}</h1>
  <time datetime="{{ published_at }}">{{ published_at | date }}</time>
  <div class="article-body">{{ body }}</div>
</article>
```

### `templates/page.html`

```html
{{ extend('layout') }}

<div class="page-content">
  <h1>{{ title }}</h1>
  <div class="page-body">{{ body }}</div>
</div>
```

### `assets/css/main.css`

```css
:root {
  --primary-color: #333;
  --font-family: system-ui, sans-serif;
  --content-max-width: 960px;
}

body {
  font-family: var(--font-family);
  color: var(--primary-color);
  margin: 0;
  padding: 0;
}

main {
  max-width: var(--content-max-width);
  margin: 0 auto;
  padding: 2rem;
}

header,
footer {
  background: #f5f5f5;
  padding: 1rem 2rem;
}
```

## Related Documentation

- [Plugin Development Guide](plugin-development.md) -- Plugins can register custom content types that themes must template
- [Content Type API](content-type-api.md) -- Field definitions drive admin form generation
- [Architecture Overview](architecture.md) -- Module boundaries and integration map
