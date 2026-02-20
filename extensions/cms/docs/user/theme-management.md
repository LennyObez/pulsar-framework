# Theme management guide

This guide covers installing, previewing, activating, and managing themes in Pulsar CMS, including safe mode, provenance verification, and rollback capabilities.

## Overview

Themes control the visual presentation of your CMS site. Pulsar CMS supports a secure theme system with signature verification, preview mode, safe mode fallback, and provenance tracking.

## Theme structure

Every theme is a directory containing a `theme.json` manifest and template files.

### Theme manifest (theme.json)

```json
{
  "slug": "corporate-clean",
  "display_name": "Corporate Clean",
  "version": "1.2.0",
  "description": "A professional theme for corporate websites",
  "author_name": "Acme Themes",
  "author_url": "https://acme-themes.example.com",
  "license": "MIT",
  "pulsar_version": ">=1.0.0",
  "parent_theme": null,
  "regions": ["header", "sidebar", "footer", "content"],
  "supported_content_types": ["article", "page"],
  "settings": {
    "primary_color": "#003366",
    "logo_position": "left"
  },
  "assets": {
    "style": "assets/css/main.css",
    "script": "assets/js/main.js"
  }
}
```

### Manifest fields

| Field                     | Required | Description                                     |
| ------------------------- | -------- | ----------------------------------------------- |
| `slug`                    | Yes      | URL-safe theme identifier                       |
| `display_name`            | Yes      | Human-readable name                             |
| `version`                 | Yes      | SemVer version string                           |
| `description`             | No       | Theme description                               |
| `author_name`             | No       | Author name                                     |
| `author_url`              | No       | Author website URL                              |
| `license`                 | No       | SPDX license identifier                         |
| `pulsar_version`          | No       | Required Pulsar version constraint              |
| `parent_theme`            | No       | Slug of parent theme for inheritance            |
| `regions`                 | No       | Template regions declared by this theme         |
| `supported_content_types` | No       | Content types this theme provides templates for |
| `settings`                | No       | Theme-specific configurable settings            |
| `assets`                  | No       | Asset path mapping                              |

## Installing a theme

### Via admin panel

<!-- Screenshot: Theme installation upload form -->

1. Navigate to **Admin > CMS > Themes** (`/admin/cms/themes`).
2. Click **Install New Theme**.
3. Upload a theme archive (`.zip` file).
4. The system validates the archive:

- Archive size limit: 50 MB (configurable via `themes.max_archive_size`)
- File count limit: 10,000 files (configurable via `themes.max_file_count`)
- Manifest validation: checks `theme.json` for required fields
- Signature verification (if `themes.require_signed_themes` is enabled)

5. After validation, the theme appears in the installed themes list.

### Via API

```
POST /admin/cms/themes
Content-Type: multipart/form-data

file: [theme-archive.zip]
```

### Archive security

The `SafeArchiveExtractor` processes all theme archives with these protections:

- **Path traversal prevention**: Entries with `..` or absolute paths are rejected
- **Symlink rejection**: Symbolic links within archives are blocked
- **Size verification**: Total extracted size is bounded
- **File count limits**: Prevents zip bombs with excessive file counts

## Theme signature verification

For regulated environments, themes can require cryptographic signatures.

### How it works

1. Theme authors sign their archive with an Ed25519 private key.
2. The CMS verifies the signature against configured trusted public keys.
3. Unsigned or tampered themes are rejected when signature verification is enabled.

### Configuration

```php
'themes' => [
    'require_signed_themes' => true,
    'trusted_public_keys' => [
        'base64-encoded-ed25519-public-key-1',
        'base64-encoded-ed25519-public-key-2',
    ],
    'integrity_check_on_boot' => true,
],
```

### Provenance badges

<!-- Screenshot: Theme list with provenance badges -->

Each installed theme displays a provenance badge:

| Badge          | Meaning                                           |
| -------------- | ------------------------------------------------- |
| **Verified**   | Signature is valid against a trusted public key   |
| **Unverified** | No signature present or verification not required |
| **Tampered**   | Signature verification failed (do not activate)   |

## Preview mode

Preview mode lets you test a theme before making it live.

### Starting a preview

1. In the theme list at **Admin > CMS > Themes**, click **Preview** on an installed theme.
2. A preview session is created with a unique session token.
3. You are redirected to the site rendered with the preview theme.
4. Only your session sees the preview; other visitors see the active theme.

### Preview session

The `PreviewSession` tracks:

- The theme being previewed
- The user who initiated the preview
- An expiration timestamp (preview sessions are time-limited)

### Ending a preview

- Click **End Preview** in the admin bar.
- Or let the session expire naturally.
- The preview theme is never served to public visitors.

```
POST /admin/cms/themes/{id}/preview
```

## Activating a theme

After reviewing a theme in preview mode:

1. Navigate to **Admin > CMS > Themes** (`/admin/cms/themes`).
2. Click **Activate** on the desired theme.
3. Confirm the activation.

The theme becomes immediately active for all visitors.

```
POST /admin/cms/themes/{id}/activate
```

### Deactivating a theme

```
POST /admin/cms/themes/{id}/deactivate
```

Deactivating a theme reverts to the previously active theme or the system default.

## Rollback

If an activated theme causes issues:

1. Navigate to **Admin > CMS > Themes**.
2. Click **Activate** on the previously used theme.
3. The old theme is immediately restored.

The CMS tracks which theme was previously active, enabling quick rollback. If you cannot access the admin panel due to a broken theme, use Safe Mode.

## Safe mode

Safe mode disables the active theme and renders pages with a minimal, built-in fallback template.

### When safe mode activates

The `ThemeSafeMode` component activates automatically when:

- The active theme's template files are missing or corrupted
- A critical rendering error occurs during page generation

### Manual safe mode

Administrators can also enter safe mode manually to troubleshoot theme issues. Once in safe mode, you can:

1. Access the admin panel normally.
2. Fix or reinstall the problematic theme.
3. Activate a working theme.
4. Exit safe mode.

## Theme inheritance

Themes can extend a parent theme using the `parent_theme` field in the manifest:

```json
{
  "slug": "corporate-dark",
  "parent_theme": "corporate-clean",
  "version": "1.0.0"
}
```

Child themes:

- Inherit all templates and assets from the parent
- Can override specific templates by providing files with the same name
- Can add new templates and assets
- Must specify a valid, installed parent theme slug

## Theme assets

### Asset deploy modes

Theme assets (CSS, JS, images) are deployed to the public web directory:

| Mode      | Description                                                          |
| --------- | -------------------------------------------------------------------- |
| `copy`    | Files are copied to the public directory (default, works everywhere) |
| `symlink` | Symbolic links are created (faster deployment, requires OS support)  |

Configure in `config/cms.php`:

```php
'themes' => [
    'asset_deploy_mode' => 'copy',
],
```

### Asset resolution

The `ThemeAssetResolver` maps logical asset names from the manifest to public URLs:

```
theme.json: "assets": { "style": "assets/css/main.css" }
Public URL: /themes/corporate-clean/assets/css/main.css
```

## Integrity checks

When `integrity_check_on_boot` is enabled (default: `true`), the CMS verifies theme files on every application boot:

- File checksums are compared against the values recorded at installation
- Missing or modified files trigger a warning in the admin panel
- For signed themes, the signature is re-verified

This protects against unauthorized file modifications on the server.

## Deleting a theme

1. Navigate to **Admin > CMS > Themes** (`/admin/cms/themes`).
2. Click **Delete** on an inactive theme.
3. Confirm deletion.

You cannot delete the currently active theme. Deactivate it first, then delete.

```
DELETE /admin/cms/themes/{id}
```

## Permissions

| Permission           | Role  | Description                          |
| -------------------- | ----- | ------------------------------------ |
| `cms.themes.view`    | Admin | View installed themes                |
| `cms.themes.install` | Admin | Upload and install new themes        |
| `cms.themes.manage`  | Admin | Activate, deactivate, preview themes |
| `cms.themes.delete`  | Admin | Delete installed themes              |

## Configuration reference

| Key                              | Type     | Default                | Description                       |
| -------------------------------- | -------- | ---------------------- | --------------------------------- |
| `themes.storage_path`            | string   | `'storage/cms/themes'` | Theme storage directory           |
| `themes.asset_deploy_mode`       | string   | `'copy'`               | Asset deployment method           |
| `themes.require_signed_themes`   | bool     | `true`                 | Require cryptographic signatures  |
| `themes.trusted_public_keys`     | string[] | `[]`                   | Trusted Ed25519 public keys       |
| `themes.integrity_check_on_boot` | bool     | `true`                 | Verify file integrity on boot     |
| `themes.max_archive_size`        | int      | `52428800`             | Max archive size in bytes (50 MB) |
| `themes.max_file_count`          | int      | `10000`                | Max files per archive             |

## Next steps

- [Live CSS Guide](live-css-guide.md) - Customizing theme styles without editing files
- [Plugin Management](plugin-management.md) - Installing and managing CMS plugins
- [Settings Reference](settings-reference.md) - All theme configuration options
