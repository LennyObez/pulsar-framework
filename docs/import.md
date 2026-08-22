# Import system

Pulsar provides a unified, idempotent, multi-mode import pipeline for CMS content, taxonomy, menu, and extension data. The same backend serves both the CLI command and the admin GUI.

## Import modes

### Single file

Import a single JSON file. The system auto-detects the content type by inspecting root keys:

```bash
pulsar cms:import site-content.json --execute
```

### Directory

Import all `.json` files from a directory. Files are processed in alphabetical order, and results are merged:

```bash
pulsar cms:import resources/import/ --execute
```

### Full site definition

Force the N.3 schema parser with the `--site-definition` flag:

```bash
pulsar cms:import site-definition.json --site-definition --execute
```

## Dry-run mode

All imports default to dry-run mode. Pass `--execute` to persist changes:

```bash
# Preview what would be imported
pulsar cms:import pages.json

# Actually persist
pulsar cms:import pages.json --execute
```

The admin GUI at `/admin/cms/tools/import` supports dry-run preview before committing.

## Idempotent imports with import_id

Every importable item can include a stable `import_id` field. This enables safe re-imports without duplicates:

- On import, if `import_id` exists in the database, the record is **updated**
- If `import_id` does not exist, a new record is **created**
- Records without `import_id` (user-created in admin) are never touched by imports
- Records not present in the import file are never deleted

### Supported tables

| Table                | Column                        | Index    |
| -------------------- | ----------------------------- | -------- |
| `cms_contents`       | `import_id VARCHAR(255) NULL` | `UNIQUE` |
| `cms_taxonomies`     | `import_id VARCHAR(255) NULL` | `UNIQUE` |
| `cms_taxonomy_terms` | `import_id VARCHAR(255) NULL` | `UNIQUE` |
| `cms_menus`          | `import_id VARCHAR(255) NULL` | `UNIQUE` |
| `cms_menu_items`     | `import_id VARCHAR(255) NULL` | `UNIQUE` |
| `cms_media_assets`   | `import_id VARCHAR(255) NULL` | `UNIQUE` |
| `forum_categories`   | `import_id VARCHAR(255) NULL` | `UNIQUE` |
| `analytics_sites`    | `import_id VARCHAR(255) NULL` | `UNIQUE` |
| `analytics_goals`    | `import_id VARCHAR(255) NULL` | `UNIQUE` |

### Naming conventions

Use a `type:slug` pattern for import IDs:

```json
{
  "import_id": "page:about",
  "import_id": "article:seo-guide-2026",
  "import_id": "tax:categories",
  "import_id": "term:php",
  "import_id": "menu:primary",
  "import_id": "menuitem:home"
}
```

## Unified bundle format

A single JSON file can contain all sections. The system processes them in dependency order (taxonomies before content, content before menus):

```json
{
  "version": "1.0",
  "site": {
    "name": "My Site",
    "url": "https://example.com",
    "locales": ["en", "fr"],
    "settings": {
      "site_title": "My Site",
      "timezone": "Europe/Brussels"
    }
  },
  "taxonomies": [
    {
      "slug": "categories",
      "name": "Categories",
      "import_id": "tax:categories",
      "hierarchical": true,
      "terms": [
        {
          "slug": "tutorials",
          "name": "Tutorials",
          "import_id": "term:tutorials"
        }
      ]
    }
  ],
  "media": [
    {
      "ref": "hero.jpg",
      "source": "https://cdn.example.com/hero.jpg",
      "filename": "hero.jpg",
      "mime_type": "image/jpeg",
      "import_id": "media:hero"
    }
  ],
  "content": [
    {
      "slug": "about",
      "title": "About Us",
      "content_type": "page",
      "template": "pages/about-with-team",
      "body": "<p>Welcome to our site</p>",
      "import_id": "page:about",
      "taxonomy_terms": ["categories:tutorials"],
      "meta_title": "About Us | My Site",
      "meta_description": "Learn about our team"
    }
  ],
  "menus": [
    {
      "location": "primary",
      "name": "Main Navigation",
      "import_id": "menu:primary",
      "items": [
        {
          "label": "Home",
          "url": "/",
          "import_id": "menuitem:home"
        },
        {
          "label": "About",
          "content_ref": "page:about",
          "import_id": "menuitem:about"
        }
      ]
    }
  ],
  "redirects": [
    {
      "from": "/old-about",
      "to": "/about",
      "status_code": 301
    }
  ],
  "seo": {
    "robots_txt": "User-agent: *\nAllow: /",
    "default_meta_title_suffix": " | My Site"
  }
}
```

## Per-file format

Each section can live in its own file. The system detects the content type by inspecting root keys:

```
resources/import/
  01-taxonomies.json   (has "taxonomies" key)
  02-media.json        (has "media" key)
  03-pages.json        (has "content" key)
  04-articles.json     (has "content" key)
  05-menus.json        (has "menus" key)
  06-forum.json        (has "forum" key)
  07-analytics.json    (has "analytics" key)
  08-booking.json      (has "booking" key)
```

### Pages file example

```json
{
  "content": [
    {
      "slug": "pricing",
      "title": "Pricing",
      "content_type": "page",
      "template": "pages/pricing-with-calculator",
      "body": "<p>Choose your plan</p>",
      "import_id": "page:pricing"
    }
  ]
}
```

### Forum file example

```json
{
  "forum": {
    "categories": [
      {
        "slug": "general",
        "import_id": "forum-cat:general",
        "translations": {
          "en": { "name": "General Discussion", "description": "Talk about anything" },
          "fr": { "name": "Discussion g\u00e9n\u00e9rale", "description": "Parlez de tout" }
        }
      }
    ]
  }
}
```

### Analytics file example

```json
{
  "analytics": {
    "sites": [
      {
        "domain": "example.com",
        "import_id": "analytics-site:main"
      }
    ],
    "goals": [
      {
        "name": "Newsletter Signup",
        "type": "event",
        "import_id": "analytics-goal:newsletter"
      }
    ]
  }
}
```

### Booking file example

```json
{
  "booking": {
    "services": [
      {
        "name": "Consultation",
        "duration_minutes": 60,
        "import_id": "booking-svc:consultation"
      }
    ]
  }
}
```

## Section detection

The auto-detection logic inspects root keys in this order:

| Root key           | Handler                                        |
| ------------------ | ---------------------------------------------- |
| `version` + `site` | Full SiteDefinition parser (N.3 schema)        |
| `providers`        | Multi-provider bundle (keyed by provider name) |
| `content`          | CMS ImportParser                               |
| `taxonomies`       | CMS ImportParser                               |
| `menus`            | CMS ImportParser                               |
| `settings`         | CMS ImportParser                               |
| `media`            | CMS ImportParser                               |
| `forum`            | ForumImportExportProvider                      |
| `booking`          | BookingImportExportProvider                    |
| `analytics`        | AnalyticsImportExportProvider                  |

Multiple sections can coexist in a single file. CMS sections are processed by the CMS ImportParser; extension sections are delegated to their registered ImportExportProvider.

## Extension providers

Extensions register import capabilities via `ImportExportProviderInterface`. The CMS import system delegates to providers from the central `ImportExportRegistry` when it encounters extension-specific sections.

### Registering a provider

In your extension's `postBoot()` method:

```php
$registry = $container->get(ImportExportRegistry::class);
$registry->register(new MyExtensionImportExportProvider(/* deps */));
```

### Provider interface

```php
interface ImportExportProviderInterface
{
    public function name(): string;          // e.g., 'forum'
    public function label(): string;         // e.g., 'Forum'
    public function supportedFormats(): array; // e.g., ['json']
    public function export(ExportRequest $request): ExportResult;
    public function import(ImportRequest $request): ImportResult;
    public function schema(): array;
}
```

## Import result

Both CLI and GUI receive a structured `ImportResult` with:

- **created**: count of new entities per type
- **updated**: count of updated entities per type (via import_id)
- **skipped**: count of skipped entities per type
- **warnings**: non-fatal issues (missing refs, skipped items)
- **errors**: fatal issues that prevented operations

## Content field aliases

The import parser accepts multiple field names for common content properties. This avoids errors when source data uses slightly different naming conventions.

### Author

Both `author_id` and `author` are accepted for specifying the content author. The parser checks `author_id` first, then falls back to `author`. If neither is present, the value defaults to `"system"`.

```json
{ "author_id": "usr_abc123" }
{ "author": "usr_abc123" }
```

### Slug

Translation entries accept both `slug_segment` and `slug` for the URL slug. The parser checks `slug_segment` first, then `slug`, then falls back to the root-level slug of the content item.

## Template support

Content items support a `template` field that specifies which Pulse template the CMS should use for rendering:

```json
{
  "slug": "pricing",
  "title": "Pricing",
  "template": "pages/pricing-with-calculator",
  "import_id": "page:pricing"
}
```

If no template is specified, the CMS uses its default template resolution.
