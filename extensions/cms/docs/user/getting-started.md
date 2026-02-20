# Getting started with Pulsar CMS

This guide walks you through installing and configuring the Pulsar CMS extension, creating your first content, and publishing it to your site.

## Prerequisites

- A working Pulsar Framework application (v1.0.0+)
- PHP 8.5 or later
- Composer installed
- A supported database (PostgreSQL recommended)

## Step 1: install the CMS extension

The CMS extension ships with the Pulsar framework. Enable it by registering it in your application's extension configuration.

Add the extension to your `config/extensions.php`:

```php
<?php

declare(strict_types=1);

return [
    'extensions' => [
        \Pulsar\Extension\Cms\CmsExtension::class,
    ],
];
```

## Step 2: create the configuration file

Create `config/cms.php` in your project's configuration directory:

```php
<?php

declare(strict_types=1);

return [
    'default_locale' => 'en',
    'supported_locales' => ['en'],
    'default_locale_in_url' => false,
    'editorial_workflow' => false,
    'event_sourcing' => false,
    'atomic_snapshots' => false,
    'max_hierarchy_depth' => 10,

    'cache' => [
        'page_cache_ttl_seconds' => 3600,
        'stampede_protection' => true,
    ],

    'media' => [
        'disk' => 'local',
        'max_upload_size' => 10_485_760,  // 10 MB
        'storage_path' => 'storage/cms/media',
    ],

    'comments' => [
        'enabled' => true,
        'auto_approve_authenticated' => false,
        'rate_limit_per_minute' => 5,
    ],

    'seo' => [
        'default_robots' => 'index, follow',
        'enable_structured_data' => true,
    ],

    'themes' => [
        'storage_path' => 'storage/cms/themes',
        'require_signed_themes' => true,
    ],

    'security' => [
        'ssrf_enabled' => true,
        'step_up_ttl_minutes' => 15,
    ],
];
```

### Configuration reference

| Key                     | Type     | Default  | Description                                       |
| ----------------------- | -------- | -------- | ------------------------------------------------- |
| `default_locale`        | string   | `'en'`   | BCP 47 default locale code                        |
| `supported_locales`     | string[] | `['en']` | All locale codes the CMS serves                   |
| `default_locale_in_url` | bool     | `false`  | Include default locale in URL paths               |
| `editorial_workflow`    | bool     | `false`  | Enable Draft/InReview/Approved/Published pipeline |
| `event_sourcing`        | bool     | `false`  | Enable append-only event log for content          |
| `atomic_snapshots`      | bool     | `false`  | Enable atomic content snapshots on publish        |
| `max_hierarchy_depth`   | int      | `10`     | Maximum page nesting depth                        |

## Step 3: run migrations

Run the CMS database migrations to create the required tables:

```bash
php bin/pulsar migrate
```

This creates all CMS tables including content, revisions, content blocks, taxonomies, menus, media, comments, redirects, themes, plugins, settings, and more.

## Step 4: access the admin panel

Navigate to the CMS admin dashboard at:

```
https://your-site.com/admin/cms
```

You must be authenticated with an account that has the `cms.admin` or `cms.contributor` role. See the [Security Model](../security/security-model.md) for role details.

## Step 5: create your first content

1. Navigate to **Admin > CMS > Content** (`/admin/cms/content`).
2. Click **Create New**.
3. Fill in the required fields:

- **Content type**: Select `article` or `page`.
- **Title**: Enter your article or page title.
- **Slug**: Auto-generated from the title, or set a custom URL slug.
- **Body**: Write your content using the editor. HTML is sanitized through the SafeHtmlPolicy.

4. Set optional fields:

- **Template**: Override the theme template for this content.
- **Parent page**: For pages, choose a parent to create hierarchy.
- **Comment policy**: Choose `inherit`, `open`, or `closed`.
- **Data classification**: Set to `public`, `internal`, or `confidential`.

5. Click **Save as Draft**.

## Step 6: publish your content

From the content detail page (`/admin/cms/content/{id}`):

1. Review your content in the preview.
2. Click **Publish** to make it live immediately.
3. Alternatively, click **Schedule** to set a future publication date.

### Publishing workflow states

| Status    | Description                                                         |
| --------- | ------------------------------------------------------------------- |
| Draft     | Content is being authored, not publicly visible                     |
| In Review | Submitted for editorial review (when editorial workflow is enabled) |
| Approved  | Approved by a reviewer (when editorial workflow is enabled)         |
| Scheduled | Queued for automatic publication at a future date                   |
| Published | Live and publicly visible                                           |
| Archived  | Removed from public view, preserved for records                     |

### Standard mode

In standard mode (`editorial_workflow: false`), content transitions directly:

```
Draft --> Published
Draft --> Scheduled --> Published
Published --> Archived
Archived --> Draft
```

### Editorial workflow mode

When `editorial_workflow: true`, content passes through review:

```
Draft --> In Review --> Approved --> Published
Draft --> In Review --> Draft (rejected)
Approved --> Scheduled --> Published
```

## Step 7: view your published content

Your published content is accessible at:

- Default locale (no prefix): `https://your-site.com/{slug}`
- Other locales: `https://your-site.com/{locale}/{slug}`

For example, if your article has the slug `hello-world`:

- English (default): `https://your-site.com/hello-world`
- French: `https://your-site.com/fr/bonjour-le-monde`

## Multi-locale setup

To serve content in multiple languages, update your configuration:

```php
'supported_locales' => ['en', 'fr', 'de', 'es'],
'default_locale' => 'en',
'default_locale_in_url' => false,
```

Then add translations for each content item at **Admin > CMS > Content > {id} > Translations**.

## Enabling regulated features

For regulated environments (banking, healthcare, legal), enable the full compliance stack:

```php
'editorial_workflow' => true,   // Enforces review before publish
'event_sourcing' => true,       // Immutable content audit trail
'atomic_snapshots' => true,     // All-locale atomic snapshots on publish
```

These features ensure a complete audit trail and prevent unauthorized content changes.

## Next steps

- [Content Management Guide](content-management.md) - Detailed content editing and workflow
- [Media Library Guide](media-library.md) - Uploading and managing media assets
- [Theme Management](theme-management.md) - Installing and customizing themes
- [Settings Reference](settings-reference.md) - Complete configuration reference
- [Security Model](../security/security-model.md) - Roles, permissions, and security features
