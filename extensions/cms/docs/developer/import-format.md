# Import Format Specification

The Pulsar CMS supports a structured JSON format for full-site imports. This format is designed to be AI-compatible, enabling automated site generation from structured data.

## Schema Overview

A site definition is a JSON document conforming to version `1.0` of the import schema. It is parsed by `SiteDefinition::fromJson()` and processed by `SiteDefinitionParser`.

### Top-Level Structure

```json
{
  "version": "1.0",
  "site": { ... },
  "taxonomies": [ ... ],
  "content": [ ... ],
  "menus": [ ... ],
  "media": [ ... ],
  "redirects": [ ... ],
  "seo": { ... }
}
```

### Required Keys

| Key       | Type   | Description                      |
| --------- | ------ | -------------------------------- |
| `version` | string | Schema version. Must be `"1.0"`. |
| `site`    | object | Site-level configuration.        |

### Optional Keys

| Key          | Type   | Default | Description                                 |
| ------------ | ------ | ------- | ------------------------------------------- |
| `taxonomies` | array  | `[]`    | Taxonomy definitions with terms.            |
| `content`    | array  | `[]`    | Content items with translations and blocks. |
| `menus`      | array  | `[]`    | Menu definitions with items.                |
| `media`      | array  | `[]`    | Media asset references with source URLs.    |
| `redirects`  | array  | `[]`    | URL redirect definitions.                   |
| `seo`        | object | `{}`    | SEO configuration.                          |

## Section: `site`

Site-level configuration and metadata.

```json
{
  "site": {
    "name": "Acme Corporation",
    "url": "https://example.com",
    "base_url": "https://example.com",
    "default_locale": "en",
    "supported_locales": ["en", "fr", "de"],
    "tenant_id": null,
    "timezone": "America/New_York",
    "tagline": "Building the future, today."
  }
}
```

| Field               | Type        | Description                                    |
| ------------------- | ----------- | ---------------------------------------------- |
| `name`              | string      | Site name                                      |
| `url`               | string      | Primary site URL (used for sitemap generation) |
| `base_url`          | string      | Alternative key for `url`                      |
| `default_locale`    | string      | BCP 47 default locale                          |
| `supported_locales` | list        | All supported locale codes                     |
| `tenant_id`         | string/null | Tenant ID for multi-tenant deployments         |
| `timezone`          | string      | Default timezone                               |
| `tagline`           | string      | Site tagline or description                    |

## Section: `taxonomies`

Taxonomies are processed first (no dependencies) and produce a term map for content association.

```json
{
  "taxonomies": [
    {
      "slug": "category",
      "name": "Categories",
      "description": "Content categories",
      "locale": "en",
      "hierarchical": true,
      "tenant_id": null,
      "terms": [
        {
          "slug": "technology",
          "name": "Technology",
          "description": "Technology articles",
          "sort_order": 0
        },
        {
          "slug": "business",
          "name": "Business",
          "description": "Business articles",
          "sort_order": 1
        }
      ]
    },
    {
      "slug": "tag",
      "name": "Tags",
      "description": "Content tags",
      "locale": "en",
      "hierarchical": false,
      "terms": [
        {
          "slug": "php",
          "name": "PHP",
          "sort_order": 0
        },
        {
          "slug": "performance",
          "name": "Performance",
          "sort_order": 1
        }
      ]
    }
  ]
}
```

### Taxonomy Fields

| Field          | Type        | Required | Description                                                          |
| -------------- | ----------- | -------- | -------------------------------------------------------------------- |
| `slug`         | string      | Yes      | URL-safe taxonomy identifier                                         |
| `name`         | string      | No       | Human-readable name (used for translation)                           |
| `description`  | string      | No       | Taxonomy description                                                 |
| `locale`       | string      | No       | Locale for the name/description translation (default: `"en"`)        |
| `hierarchical` | bool        | No       | Whether terms can have parent-child relationships (default: `false`) |
| `tenant_id`    | string/null | No       | Tenant scope                                                         |
| `terms`        | array       | No       | List of term definitions                                             |

### Taxonomy Term Fields

| Field         | Type   | Required | Description                                     |
| ------------- | ------ | -------- | ----------------------------------------------- |
| `slug`        | string | Yes      | URL-safe term identifier                        |
| `name`        | string | No       | Human-readable term name                        |
| `description` | string | No       | Term description                                |
| `locale`      | string | No       | Locale for translation (inherits from taxonomy) |
| `sort_order`  | int    | No       | Display order (default: `0`)                    |

### Term Map

After processing, a term map is produced: `"taxonomy_slug:term_slug" => term_id`. This map is used to associate content items with taxonomy terms.

## Section: `media`

Media entries define assets to be imported. External sources can be downloaded automatically when enabled.

```json
{
  "media": [
    {
      "ref": "hero-image.jpg",
      "filename": "hero-image.jpg",
      "source": "https://images.example.com/hero.jpg",
      "mime_type": "image/jpeg",
      "uploader_id": "system",
      "tenant_id": null
    },
    {
      "ref": "company-logo.svg",
      "filename": "company-logo.svg",
      "source": "https://brand.example.com/logo.svg",
      "mime_type": "image/svg+xml"
    }
  ]
}
```

### Media Fields

| Field         | Type        | Required | Description                                                          |
| ------------- | ----------- | -------- | -------------------------------------------------------------------- |
| `ref`         | string      | Yes      | Reference key for linking media to content. Alternative: `filename`. |
| `filename`    | string      | No       | Original filename (used if `ref` not provided)                       |
| `source`      | string      | No       | External URL to download the asset from                              |
| `mime_type`   | string      | No       | MIME type (auto-detected from response headers if not specified)     |
| `uploader_id` | string      | No       | User ID for audit trail (default: `"system"`)                        |
| `tenant_id`   | string/null | No       | Tenant scope                                                         |

### Media Download Behavior

| `allowExternalMediaDownload` | `source` present | Behavior                                                    |
| ---------------------------- | ---------------- | ----------------------------------------------------------- |
| `true`                       | Yes              | Downloads from URL, uploads through `MediaServiceInterface` |
| `true`                       | No               | Creates a placeholder media reference                       |
| `false`                      | Yes              | Skips with warning                                          |
| `false`                      | No               | Creates a placeholder media reference                       |

Downloaded media goes through the full validation pipeline (file type, size, SVG sanitization, PDF validation).

### Media Reference Map

After processing, a media reference map is produced: `"ref_key" => media_asset_id`. Content items reference media by the `ref` key.

## Section: `content`

Content items are processed after taxonomies and media (depends on both for references).

```json
{
  "content": [
    {
      "content_type": "page",
      "template": null,
      "parent_ref": null,
      "sort_order": 0,
      "comment_policy": "closed",
      "data_classification": "public",
      "tenant_id": null,
      "translations": {
        "en": {
          "title": "About Us",
          "slug_segment": "about",
          "body": "<h2>Our Story</h2><p>Founded in 2020...</p>",
          "excerpt": "Learn about our company",
          "meta_title": "About Us | Acme Corp",
          "meta_description": "Learn about Acme Corporation's mission and team.",
          "robots": "index, follow"
        },
        "fr": {
          "title": "A propos",
          "slug_segment": "a-propos",
          "body": "<h2>Notre histoire</h2><p>Fondee en 2020...</p>",
          "excerpt": "Decouvrez notre entreprise",
          "meta_title": "A propos | Acme Corp",
          "meta_description": "Decouvrez la mission et l'equipe d'Acme Corporation."
        }
      },
      "taxonomy_terms": ["category:business"],
      "featured_image": "hero-image.jpg"
    },
    {
      "content_type": "article",
      "template": null,
      "parent_ref": null,
      "sort_order": 0,
      "comment_policy": "moderated",
      "data_classification": "public",
      "translations": {
        "en": {
          "title": "Getting Started with Pulsar",
          "slug_segment": "getting-started",
          "body": "<p>Welcome to Pulsar...</p>",
          "excerpt": "Your first steps with the Pulsar framework"
        }
      }
    }
  ]
}
```

### Content Fields

| Field                 | Type        | Required | Description                                                               |
| --------------------- | ----------- | -------- | ------------------------------------------------------------------------- |
| `content_type`        | string      | No       | Content type: `"article"`, `"page"`, or custom type (default: `"page"`)   |
| `template`            | string/null | No       | Theme template override                                                   |
| `parent_ref`          | string/null | No       | Reference to parent content for hierarchy (by slug)                       |
| `sort_order`          | int         | No       | Sibling ordering (default: `0`)                                           |
| `comment_policy`      | string      | No       | `"open"`, `"moderated"`, `"closed"`, `"inherit"` (default: `"inherit"`)   |
| `data_classification` | string      | No       | `"public"`, `"internal"`, `"confidential"`, `"pii"` (default: `"public"`) |
| `tenant_id`           | string/null | No       | Tenant scope                                                              |
| `translations`        | object      | No       | Locale-keyed translation objects                                          |
| `taxonomy_terms`      | list        | No       | Term references in `"taxonomy_slug:term_slug"` format                     |
| `featured_image`      | string      | No       | Media reference key for featured/OG image                                 |

### Translation Fields

| Field              | Type   | Required | Description                                                                                            |
| ------------------ | ------ | -------- | ------------------------------------------------------------------------------------------------------ |
| `title`            | string | Yes      | Content title (max 500 chars)                                                                          |
| `slug_segment`     | string | Yes      | URL slug segment. Pattern: `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`, max 200 chars, no consecutive hyphens. |
| `body`             | string | Yes      | HTML body content                                                                                      |
| `excerpt`          | string | No       | Summary text                                                                                           |
| `meta_title`       | string | No       | SEO title override (max 70 chars)                                                                      |
| `meta_description` | string | No       | SEO meta description (max 170 chars)                                                                   |
| `robots`           | string | No       | Robots directive override                                                                              |

### Content Reference Map

After processing, a content reference map is produced: `"content_type:slug" => content_id`. Menu items reference content by this key.

## Section: `menus`

Menus are processed after content (depends on content references for internal links).

```json
{
  "menus": [
    {
      "location": "primary",
      "tenant_id": null,
      "translations": {
        "en": { "label": "Main Navigation" }
      },
      "items": [
        {
          "content_ref": "page:about",
          "link_target": "_self",
          "sort_order": 0,
          "css_class": null,
          "translations": {
            "en": { "label": "About Us" },
            "fr": { "label": "A propos" }
          },
          "children": []
        },
        {
          "external_url": "https://blog.example.com",
          "link_target": "_blank",
          "sort_order": 1,
          "css_class": "external-link",
          "translations": {
            "en": { "label": "Blog" }
          }
        }
      ]
    },
    {
      "location": "footer",
      "items": [
        {
          "content_ref": "page:privacy-policy",
          "sort_order": 0,
          "translations": {
            "en": { "label": "Privacy Policy" }
          }
        },
        {
          "content_ref": "page:terms",
          "sort_order": 1,
          "translations": {
            "en": { "label": "Terms of Service" }
          }
        }
      ]
    }
  ]
}
```

### Menu Fields

| Field          | Type        | Required | Description                                                           |
| -------------- | ----------- | -------- | --------------------------------------------------------------------- |
| `location`     | string      | Yes      | Menu location identifier (e.g., `"primary"`, `"footer"`, `"sidebar"`) |
| `tenant_id`    | string/null | No       | Tenant scope                                                          |
| `translations` | object      | No       | Locale-keyed menu labels                                              |
| `items`        | array       | No       | Menu item definitions                                                 |

### Menu Item Fields

| Field          | Type        | Required | Description                                                       |
| -------------- | ----------- | -------- | ----------------------------------------------------------------- |
| `content_ref`  | string      | No       | Content reference in `"type:slug"` format                         |
| `external_url` | string      | No       | External URL (mutually exclusive with `content_ref`)              |
| `link_target`  | string      | No       | `"_self"`, `"_blank"`, `"_parent"`, `"_top"` (default: `"_self"`) |
| `sort_order`   | int         | No       | Display order (default: `0`)                                      |
| `css_class`    | string/null | No       | Custom CSS class                                                  |
| `translations` | object      | No       | Locale-keyed item labels                                          |
| `children`     | array       | No       | Nested child menu items (same structure)                          |

## Section: `redirects`

URL redirect definitions for SEO preservation.

```json
{
  "redirects": [
    {
      "source_path": "/old-about-page",
      "target_path": "/en/about",
      "status_code": 301,
      "locale": "en"
    },
    {
      "source_path": "/legacy/blog",
      "target_path": "/en/articles",
      "status_code": 302
    }
  ]
}
```

### Redirect Fields

| Field         | Type   | Required | Description                                |
| ------------- | ------ | -------- | ------------------------------------------ |
| `source_path` | string | Yes      | Source URL path to redirect from           |
| `target_path` | string | Yes      | Target URL path to redirect to             |
| `status_code` | int    | No       | HTTP redirect status code (default: `301`) |
| `locale`      | string | No       | Locale scope for the redirect              |

## Section: `seo`

SEO configuration applied as site settings.

```json
{
  "seo": {
    "robots_txt": "User-agent: *\nAllow: /\nSitemap: https://example.com/sitemap.xml",
    "default_robots": "index, follow",
    "structured_data": {
      "organization": {
        "name": "Acme Corporation",
        "url": "https://example.com",
        "logo": "https://example.com/logo.png"
      }
    }
  }
}
```

## Processing Order

The `SiteDefinitionParser` processes entities in strict dependency order:

```
1. Taxonomies (no dependencies) --> term_map
2. Media (no dependencies)      --> ref_map
3. Content (depends on 1, 2)    --> content_ref_map
4. Menus (depends on 3)
5. Settings (from site + seo)
6. Redirects (no dependencies)
7. Sitemap regeneration
```

This ensures that references between entities are resolvable at each step.

## Import Result

The import operation returns an `ImportResult` with detailed metrics:

```json
{
  "created": {
    "taxonomies": 4,
    "media": 10,
    "content": 25,
    "menus": 2,
    "settings": 8,
    "redirects": 5
  },
  "updated": {},
  "skipped": {},
  "warnings": ["External media download disabled, skipped: hero-banner.jpg"],
  "errors": [],
  "dry_run": false
}
```

## Dry-Run Mode

All import operations support dry-run mode, which validates the entire definition and reports what would be created without persisting any data.

```
POST /admin/cms/site-import/dry-run   -- Preview results
POST /admin/cms/site-import/execute   -- Execute import
```

The `ImportConfig` controls default behavior:

| Setting                      | Default | Description                                  |
| ---------------------------- | ------- | -------------------------------------------- |
| `maxImportSizeBytes`         | 50 MB   | Maximum allowed import file size             |
| `allowExternalMediaDownload` | `true`  | Whether to download media from external URLs |
| `dryRunDefault`              | `true`  | Whether imports default to dry-run mode      |

## Validation Rules

### Schema Validation

- `version` must be exactly `"1.0"`
- `site` must be an object
- All optional sections must be arrays (except `seo`, which is an object)

### Entity Validation

- Taxonomy slugs must be non-empty strings
- Content translations must have valid `slug_segment` values (pattern: `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`, max 200 chars, no consecutive hyphens)
- Media references must have a `ref` or `filename` key
- Menu locations must be non-empty strings

### Error Handling

- Invalid JSON throws `InvalidArgumentException` with the JSON error message
- Missing required keys throw `InvalidArgumentException` listing the missing keys
- Unsupported version throws `InvalidArgumentException`
- Per-entity errors are collected in the `warnings` list rather than aborting the entire import

## Complete Example

```json
{
  "version": "1.0",
  "site": {
    "name": "Acme Documentation",
    "url": "https://docs.example.com",
    "default_locale": "en",
    "supported_locales": ["en"],
    "tagline": "Technical documentation for Acme products"
  },
  "taxonomies": [
    {
      "slug": "product",
      "name": "Products",
      "hierarchical": false,
      "terms": [
        { "slug": "widget", "name": "Widget" },
        { "slug": "gadget", "name": "Gadget" }
      ]
    }
  ],
  "media": [
    {
      "ref": "widget-screenshot.png",
      "source": "https://assets.example.com/widget-v2.png",
      "mime_type": "image/png"
    }
  ],
  "content": [
    {
      "content_type": "page",
      "translations": {
        "en": {
          "title": "Widget Documentation",
          "slug_segment": "widget",
          "body": "<h2>Overview</h2><p>The Widget is our flagship product...</p>",
          "meta_description": "Complete documentation for the Acme Widget."
        }
      },
      "taxonomy_terms": ["product:widget"],
      "featured_image": "widget-screenshot.png"
    }
  ],
  "menus": [
    {
      "location": "primary",
      "items": [
        {
          "content_ref": "page:widget",
          "sort_order": 0,
          "translations": {
            "en": { "label": "Widget Docs" }
          }
        }
      ]
    }
  ],
  "redirects": [
    {
      "source_path": "/old-widget-docs",
      "target_path": "/en/widget",
      "status_code": 301
    }
  ],
  "seo": {
    "default_robots": "index, follow"
  }
}
```

## Related Documentation

- [API Endpoint Reference](api-reference.md) -- Import/export endpoints
- [Architecture Overview](architecture.md) -- Tools module and processing pipeline
- [Content Type API](content-type-api.md) -- Custom content type definitions
