# Import/Export Guide

This guide covers exporting content bundles, importing data, AI-compatible site definitions, backup and restore operations, and PII handling in Pulsar CMS.

## Overview

Pulsar CMS provides a comprehensive import/export system for migrating content between environments, creating backups, and bootstrapping new sites from structured definitions. All operations include PII controls and audit logging.

## Exporting Content

### Export Options

Navigate to **Admin > CMS > Export** (`/admin/cms/export`) to configure an export.

#### Scope

Select which entity types to include:

| Scope        | Description                                         |
| ------------ | --------------------------------------------------- |
| `content`    | Articles, pages, and their translations             |
| `taxonomies` | Taxonomy definitions and terms                      |
| `menus`      | Menu structures and items                           |
| `settings`   | CMS settings and configuration                      |
| `media_refs` | Media asset metadata (references, not binary files) |

#### Locale Filter

Optionally limit the export to specific locales:

- **All locales**: Exports translations for every configured locale
- **Specific locales**: Select one or more BCP 47 codes (e.g., `en`, `fr`)

#### PII Control

The export system includes PII-aware controls:

| Setting     | Default | Description                                            |
| ----------- | ------- | ------------------------------------------------------ |
| Include PII | `false` | Whether to include personally identifiable information |

When PII is excluded:

- Author IDs are anonymized
- Comment author emails and IPs are stripped
- User-identifiable fields are replaced with placeholders

### Running an Export

1. Configure scope, locale, and PII settings.
2. Click **Download Export**.
3. A JSON bundle file is generated and downloaded.

```
POST /admin/cms/export/download
Content-Type: application/json

{
    "scope": ["content", "taxonomies", "menus"],
    "locales": ["en", "fr"],
    "include_pii": false
}
```

### Export Bundle Format

The `ExportBundleGenerator` produces a JSON file with this structure:

```json
{
  "meta": {
    "exported_at": "2026-02-19T10:00:00+00:00",
    "pulsar_version": "1.0.0",
    "scope": ["content", "taxonomies"],
    "locales": ["en", "fr"]
  },
  "content": [...],
  "taxonomies": [...],
  "menus": [...],
  "settings": {...},
  "media_refs": [...]
}
```

## Importing Content

### Import Process

Navigate to **Admin > CMS > Import** (`/admin/cms/import`).

1. Upload an export bundle JSON file.
2. The system validates the file:
   - Maximum import size: 50 MB (configurable via `import.max_import_size_bytes`)
   - JSON structure validation
   - Version compatibility check
3. Choose import mode.

### Dry Run

Before executing an import, run a dry run to preview changes:

```
POST /admin/cms/import/dry-run
Content-Type: multipart/form-data

file: [export-bundle.json]
```

The dry run reports:

- Number of items to create, update, or skip
- Conflicts with existing content (slug collisions, ID conflicts)
- Validation errors
- Estimated import duration

By default, imports start in dry-run mode (`import.dry_run_default: true`).

### Executing the Import

After reviewing the dry run results:

```
POST /admin/cms/import/execute
Content-Type: multipart/form-data

file: [export-bundle.json]
```

The `ImportExportService` processes the bundle:

1. Parse and validate the import file
2. Resolve conflicts (skip, overwrite, or merge)
3. Import entities in dependency order (taxonomies before content)
4. Download external media if enabled and referenced
5. Generate an `ImportResult` report

### Import Result

The `ImportResult` includes:

| Field       | Description                           |
| ----------- | ------------------------------------- |
| Total items | Number of items in the bundle         |
| Created     | Number of new items created           |
| Updated     | Number of existing items updated      |
| Skipped     | Number of items skipped (conflicts)   |
| Errors      | Number of items that failed to import |
| Details     | Per-item status with error messages   |

### External Media Download

When `import.allow_external_media_download` is `true`, the importer downloads media files from URLs referenced in the bundle. All downloads use the `SafeHttpClient` with SSRF protection.

## Site Definitions

Site definitions provide a complete, AI-compatible format for bootstrapping an entire CMS site from a single JSON document.

### Site Definition Format

```json
{
  "version": "1.0",
  "site": {
    "name": "My Company Site",
    "default_locale": "en",
    "supported_locales": ["en", "fr"],
    "settings": {
      "seo": {
        "title_suffix": " | My Company"
      }
    }
  },
  "taxonomies": [
    {
      "slug": "categories",
      "translations": {
        "en": { "name": "Categories" },
        "fr": { "name": "Categories" }
      },
      "terms": [
        {
          "slug": "technology",
          "translations": {
            "en": { "name": "Technology" },
            "fr": { "name": "Technologie" }
          }
        }
      ]
    }
  ],
  "content": [
    {
      "type": "page",
      "slug": "about",
      "translations": {
        "en": {
          "title": "About Us",
          "body": "<p>Our company story...</p>",
          "meta_description": "Learn about our company"
        }
      }
    }
  ],
  "menus": [
    {
      "location": "main",
      "items": [
        { "label": { "en": "Home" }, "url": "/" },
        { "label": { "en": "About" }, "content_slug": "about" }
      ]
    }
  ],
  "media": [],
  "redirects": [],
  "seo": {
    "robots_extra_rules": []
  }
}
```

### Required Keys

| Key       | Required | Description              |
| --------- | -------- | ------------------------ |
| `version` | Yes      | Must be `"1.0"`          |
| `site`    | Yes      | Site-level configuration |

### Optional Keys

| Key          | Description                             |
| ------------ | --------------------------------------- |
| `taxonomies` | Taxonomy and term definitions           |
| `content`    | Content items with translations         |
| `menus`      | Menu structures                         |
| `media`      | Media asset references with source URLs |
| `redirects`  | URL redirect definitions                |
| `seo`        | SEO configuration                       |

### Importing a Site Definition

1. Navigate to **Admin > CMS > Site Import** (`/admin/cms/site-import`).
2. Upload or paste the JSON site definition.
3. Run a **Dry Run** to preview what will be created:

```
POST /admin/cms/site-import/dry-run
Content-Type: application/json

{ ... site definition ... }
```

4. Review the dry run report.
5. Execute the import:

```
POST /admin/cms/site-import/execute
Content-Type: application/json

{ ... site definition ... }
```

The `SiteDefinitionParser` validates the document against the N.3 schema, and the import service creates all entities in the correct order.

## Backups

### Creating a Backup

1. Navigate to **Admin > CMS > Backups** (`/admin/cms/backups`).
2. Click **Create Backup**.
3. Select the backup scope:

| Scope           | Description                                  |
| --------------- | -------------------------------------------- |
| `full`          | All CMS data including media files           |
| `content_only`  | Content, translations, revisions, and blocks |
| `configuration` | Settings, themes, and plugin configuration   |

4. Click **Create**.

```
POST /admin/cms/backups
Content-Type: application/json

{
    "scope": "full"
}
```

The `BackupService` generates a complete backup with a unique ID and timestamp.

### Listing Backups

```
GET /admin/cms/backups
```

Each backup shows:

| Field      | Description                 |
| ---------- | --------------------------- |
| ID         | Unique backup identifier    |
| Scope      | What was included           |
| Size       | Backup file size            |
| Created At | When the backup was created |
| Created By | The user who initiated it   |

### Restoring from Backup

1. Navigate to **Admin > CMS > Backups**.
2. Click **Restore** on the desired backup.
3. Confirm the restore operation.

```
POST /admin/cms/backups/{id}/restore
```

The `RestoreResult` reports:

- Number of entities restored
- Conflicts resolved
- Any errors encountered

Restoring a backup is a destructive operation that replaces current data. Always create a fresh backup before restoring.

### Deleting Backups

```
DELETE /admin/cms/backups/{id}
```

## PII Handling

### What Constitutes PII

In the CMS context, PII includes:

| Data            | Where                              |
| --------------- | ---------------------------------- |
| Author IDs      | Content, comments, revisions       |
| Email addresses | Comments (guest commenters), users |
| IP addresses    | Comments, audit logs               |
| User names      | Comments, content attribution      |

### Export PII Controls

When exporting without PII (`include_pii: false`):

- Author IDs are replaced with anonymized tokens
- Email addresses are redacted
- IP addresses are removed
- Display names are generalized

### GDPR Data Export

For GDPR data subject access requests, use the dedicated GDPR export tool:

```
POST /admin/cms/tools/gdpr/export
Content-Type: application/json

{
    "user_id": "user-uuid"
}
```

This exports all data associated with a specific user.

### GDPR Data Erasure

For GDPR right-to-erasure requests:

```
POST /admin/cms/tools/gdpr/erase
Content-Type: application/json

{
    "user_id": "user-uuid"
}
```

This anonymizes or deletes all personally identifiable data for the specified user while preserving content structure for operational continuity.

## Import Configuration

| Key                                    | Type | Default    | Description                       |
| -------------------------------------- | ---- | ---------- | --------------------------------- |
| `import.max_import_size_bytes`         | int  | `52428800` | Max import file size (50 MB)      |
| `import.allow_external_media_download` | bool | `true`     | Download media from external URLs |
| `import.dry_run_default`               | bool | `true`     | Default to dry-run mode           |

## Permissions

| Permission           | Role  | Description          |
| -------------------- | ----- | -------------------- |
| `cms.export`         | Admin | Export CMS data      |
| `cms.import`         | Admin | Import CMS data      |
| `cms.backup.create`  | Admin | Create backups       |
| `cms.backup.restore` | Admin | Restore from backups |

## Next Steps

- [Settings Reference](settings-reference.md) -- Import/export configuration
- [Compliance Guide](../security/compliance-guide.md) -- GDPR and data handling
- [Security Model](../security/security-model.md) -- Permissions for import/export
