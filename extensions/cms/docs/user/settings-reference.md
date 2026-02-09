# Settings reference

This document provides a complete reference for every Pulsar CMS setting, organized by group. Settings are configured in `config/cms.php` and can be managed at runtime through the admin panel at **Admin > CMS > Settings > {group}** (`/admin/cms/settings/{group}`).

## Settings admin

| Method | Route                         | Description                 |
| ------ | ----------------------------- | --------------------------- |
| GET    | `/admin/cms/settings/{group}` | View settings for a group   |
| PUT    | `/admin/cms/settings/{group}` | Update settings for a group |

Settings changes are audit-logged with the event `cms.settings.updated`.

## General settings

Top-level CMS configuration from the `CmsConfig` DTO.

| Key                     | Type     | Default  | Description                                                                                                                      |
| ----------------------- | -------- | -------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `default_locale`        | string   | `'en'`   | BCP 47 default locale code                                                                                                       |
| `supported_locales`     | string[] | `['en']` | All locale codes the CMS serves                                                                                                  |
| `default_locale_in_url` | bool     | `false`  | Include default locale in URL paths. When `false`, the default locale is served at `/{path}`; when `true`, at `/{locale}/{path}` |
| `editorial_workflow`    | bool     | `false`  | Enable Draft/InReview/Approved/Published pipeline                                                                                |
| `event_sourcing`        | bool     | `false`  | Enable append-only event log for content mutations                                                                               |
| `atomic_snapshots`      | bool     | `false`  | Enable all-locale atomic content snapshots on publish                                                                            |
| `max_hierarchy_depth`   | int      | `10`     | Maximum page nesting depth (prevents cycles)                                                                                     |

## Cache settings

Full-page caching configuration from `CmsCacheConfig`.

| Key                                | Type | Default | Description                                                              |
| ---------------------------------- | ---- | ------- | ------------------------------------------------------------------------ |
| `cache.page_cache_ttl_seconds`     | int  | `3600`  | Full-page cache TTL (1 hour)                                             |
| `cache.stampede_protection`        | bool | `true`  | Enable probabilistic early recomputation + lock-based single-flight      |
| `cache.early_recompute_beta`       | int  | `10`    | XFetch aggressiveness parameter (higher = more aggressive recomputation) |
| `cache.stale_grace_period_seconds` | int  | `300`   | Seconds expired entries are retained for stale serving                   |
| `cache.lock_timeout_seconds`       | int  | `5`     | Maximum wait time for single-flight lock                                 |

## Media settings

Media upload and processing configuration from `MediaConfig`.

| Key                        | Type     | Default               | Description                                   |
| -------------------------- | -------- | --------------------- | --------------------------------------------- |
| `media.disk`               | string   | `'local'`             | Storage disk name                             |
| `media.max_upload_size`    | int      | `10485760`            | Maximum upload size in bytes (10 MB)          |
| `media.allowed_mime_types` | string[] | See below             | Permitted MIME types for upload               |
| `media.allowed_extensions` | string[] | See below             | Permitted file extensions                     |
| `media.max_image_width`    | int      | `16384`               | Maximum image width in pixels                 |
| `media.max_image_height`   | int      | `16384`               | Maximum image height in pixels                |
| `media.max_pixel_count`    | int      | `100000000`           | Maximum total pixel count (width x height)    |
| `media.preserve_exif`      | bool     | `false`               | Whether to preserve EXIF metadata on images   |
| `media.webp_quality`       | int      | `80`                  | WebP conversion quality (0-100)               |
| `media.avif_quality`       | int      | `60`                  | AVIF conversion quality (0-100)               |
| `media.avif_enabled`       | bool     | `true`                | Whether AVIF derivative generation is enabled |
| `media.storage_path`       | string   | `'storage/cms/media'` | Base storage path for media files             |

### Default allowed MIME types

```
image/jpeg, image/png, image/webp, image/avif, image/gif, image/svg+xml, application/pdf
```

### Default allowed extensions

```
jpg, jpeg, png, webp, avif, gif, svg, pdf
```

## Comment settings

Comments system configuration from `CommentsConfig`.

| Key                                   | Type   | Default         | Description                                        |
| ------------------------------------- | ------ | --------------- | -------------------------------------------------- |
| `comments.enabled`                    | bool   | `true`          | Master switch for the comment system               |
| `comments.auto_approve_authenticated` | bool   | `false`         | Auto-approve comments from authenticated users     |
| `comments.edit_window_minutes`        | int    | `15`            | Minutes after posting during which author can edit |
| `comments.max_nesting_depth`          | int    | `3`             | Maximum reply nesting depth                        |
| `comments.rate_limit_per_minute`      | int    | `5`             | Maximum comments per minute per user/IP            |
| `comments.rate_limit_per_hour`        | int    | `30`            | Maximum comments per hour per user/IP              |
| `comments.guest_comments_allowed`     | bool   | `true`          | Whether unauthenticated users can comment          |
| `comments.require_email`              | bool   | `false`         | Whether guest commenters must provide an email     |
| `comments.max_body_length`            | int    | `10000`         | Maximum comment body length in characters          |
| `comments.max_links_per_comment`      | int    | `3`             | Maximum number of links allowed per comment        |
| `comments.honeypot_field_name`        | string | `'website_url'` | Hidden field name for bot detection                |

## SEO settings

SEO and link health configuration from `SeoConfig`.

| Key                              | Type   | Default                            | Description                                 |
| -------------------------------- | ------ | ---------------------------------- | ------------------------------------------- |
| `seo.default_robots`             | string | `'index, follow'`                  | Default robots meta tag value               |
| `seo.title_suffix`               | string | `''`                               | Suffix appended to every page title         |
| `seo.sitemap_changefreq`         | map    | `{article: weekly, page: monthly}` | Per-content-type sitemap changefreq values  |
| `seo.sitemap_priority`           | map    | `{page: 0.8, article: 0.6}`        | Per-content-type sitemap priority values    |
| `seo.enable_structured_data`     | bool   | `true`                             | Whether to generate JSON-LD structured data |
| `seo.enable_media_sitemap`       | bool   | `true`                             | Whether to include media in sitemaps        |
| `seo.link_health_check_schedule` | string | `'0 3 * * 0'`                      | Cron expression for link health check runs  |

## Theme settings

Theme system configuration from `ThemesConfig`.

| Key                              | Type     | Default                | Description                                         |
| -------------------------------- | -------- | ---------------------- | --------------------------------------------------- |
| `themes.storage_path`            | string   | `'storage/cms/themes'` | Base storage path for installed themes              |
| `themes.asset_deploy_mode`       | string   | `'copy'`               | How theme assets are deployed (`copy` or `symlink`) |
| `themes.require_signed_themes`   | bool     | `true`                 | Whether theme packages must have valid signatures   |
| `themes.trusted_public_keys`     | string[] | `[]`                   | Ed25519 public keys trusted for theme verification  |
| `themes.integrity_check_on_boot` | bool     | `true`                 | Verify theme file integrity on application boot     |
| `themes.max_archive_size`        | int      | `52428800`             | Maximum theme archive size in bytes (50 MB)         |
| `themes.max_file_count`          | int      | `10000`                | Maximum number of files allowed in a theme archive  |

## Security settings

CMS security configuration from `CmsSecurityConfig`.

| Key                                 | Type     | Default             | Description                                           |
| ----------------------------------- | -------- | ------------------- | ----------------------------------------------------- |
| `security.ssrf_enabled`             | bool     | `true`              | SSRF protection for outbound HTTP requests            |
| `security.blocked_ip_ranges`        | string[] | RFC 1918 + loopback | CIDR ranges blocked for outbound requests             |
| `security.additional_blocked_ips`   | string[] | `[]`                | Additional IP addresses to block                      |
| `security.allowed_outbound_ports`   | int[]    | `[80, 443]`         | Ports allowed for outbound HTTP requests              |
| `security.max_redirects`            | int      | `3`                 | Maximum number of redirects to follow                 |
| `security.connect_timeout_seconds`  | int      | `5`                 | TCP connect timeout                                   |
| `security.total_timeout_seconds`    | int      | `15`                | Total request timeout including response body         |
| `security.max_response_bytes`       | int      | `10485760`          | Maximum response body size (10 MB)                    |
| `security.oembed_allowed_providers` | string[] | `[]`                | Allowlisted oEmbed provider domains                   |
| `security.trusted_proxies`          | string[] | `[]`                | IP addresses of trusted reverse proxies               |
| `security.forwarded_for_header`     | string   | `'X-Forwarded-For'` | Header for forwarded-for IP resolution                |
| `security.real_ip_header`           | string   | `'X-Real-IP'`       | Header for real IP resolution                         |
| `security.cloudflare_mode`          | bool     | `false`             | Enable CF-Connecting-IP header trust                  |
| `security.step_up_ttl_minutes`      | int      | `15`                | Step-up authentication validity in minutes            |
| `security.trusted_public_keys`      | string[] | `[]`                | Ed25519 public keys for plugin signature verification |
| `security.require_signed_plugins`   | bool     | `false`             | Whether plugin packages must have valid signatures    |
| `security.integrity_check_on_boot`  | bool     | `true`              | Verify plugin file integrity on application boot      |
| `security.ipv6_subnet_mask`         | int      | `64`                | IPv6 subnet mask for fingerprinting                   |

## Commerce settings

Commerce subsystem configuration from `CommerceConfig`. Commerce is enabled when this section is present (non-null).

| Key                                   | Type   | Default  | Description                                    |
| ------------------------------------- | ------ | -------- | ---------------------------------------------- |
| `commerce.currency`                   | string | `'EUR'`  | Default ISO 4217 currency code                 |
| `commerce.tax_required`               | bool   | `false`  | Whether tax calculation is mandatory           |
| `commerce.invoice_renderer`           | string | `'html'` | Invoice rendering format                       |
| `commerce.download_token_expiry_days` | int    | `30`     | Days until download tokens expire              |
| `commerce.max_downloads`              | int    | `5`      | Default maximum downloads per digital purchase |
| `commerce.taxRates`                   | array  | `[]`     | Tax rate rule configurations                   |

### Tax rate configuration

Each tax rate entry:

| Field      | Type   | Description                                |
| ---------- | ------ | ------------------------------------------ |
| `name`     | string | Human-readable tax rate name               |
| `rate`     | float  | Tax rate as a decimal (e.g., 0.20 for 20%) |
| `country`  | string | ISO 3166-1 country code                    |
| `category` | string | Tax category identifier                    |

## Live CSS settings

Live CSS editor configuration from `LiveCssConfig`.

| Key                             | Type | Default  | Description                                        |
| ------------------------------- | ---- | -------- | -------------------------------------------------- |
| `live_css.enabled`              | bool | `true`   | Whether the Live CSS editor is enabled             |
| `live_css.max_css_length`       | int  | `100000` | Maximum allowed CSS content length in characters   |
| `live_css.allow_external_fonts` | bool | `false`  | Whether @font-face with external URLs is permitted |

## Import/Export settings

Import configuration from `ImportConfig`.

| Key                                    | Type | Default    | Description                                  |
| -------------------------------------- | ---- | ---------- | -------------------------------------------- |
| `import.max_import_size_bytes`         | int  | `52428800` | Maximum import file size (50 MB)             |
| `import.allow_external_media_download` | bool | `true`     | Whether to download media from external URLs |
| `import.dry_run_default`               | bool | `true`     | Whether imports default to dry-run mode      |

## Complete configuration example

```php
<?php

declare(strict_types=1);

return [
    'default_locale' => 'en',
    'supported_locales' => ['en', 'fr', 'de'],
    'default_locale_in_url' => false,
    'editorial_workflow' => true,
    'event_sourcing' => true,
    'atomic_snapshots' => true,
    'max_hierarchy_depth' => 10,

    'cache' => [
        'page_cache_ttl_seconds' => 3600,
        'stampede_protection' => true,
        'early_recompute_beta' => 10,
        'stale_grace_period_seconds' => 300,
        'lock_timeout_seconds' => 5,
    ],

    'media' => [
        'disk' => 'local',
        'max_upload_size' => 10_485_760,
        'webp_quality' => 80,
        'avif_quality' => 60,
        'avif_enabled' => true,
        'preserve_exif' => false,
        'storage_path' => 'storage/cms/media',
    ],

    'comments' => [
        'enabled' => true,
        'auto_approve_authenticated' => false,
        'edit_window_minutes' => 15,
        'max_nesting_depth' => 3,
        'rate_limit_per_minute' => 5,
        'rate_limit_per_hour' => 30,
        'guest_comments_allowed' => true,
        'require_email' => false,
        'max_body_length' => 10_000,
        'max_links_per_comment' => 3,
        'honeypot_field_name' => 'website_url',
    ],

    'seo' => [
        'default_robots' => 'index, follow',
        'title_suffix' => ' | My Company',
        'sitemap_changefreq' => ['article' => 'weekly', 'page' => 'monthly'],
        'sitemap_priority' => ['page' => 0.8, 'article' => 0.6],
        'enable_structured_data' => true,
        'enable_media_sitemap' => true,
        'link_health_check_schedule' => '0 3 * * 0',
    ],

    'themes' => [
        'storage_path' => 'storage/cms/themes',
        'asset_deploy_mode' => 'copy',
        'require_signed_themes' => true,
        'trusted_public_keys' => [],
        'integrity_check_on_boot' => true,
        'max_archive_size' => 52_428_800,
        'max_file_count' => 10_000,
    ],

    'security' => [
        'ssrf_enabled' => true,
        'step_up_ttl_minutes' => 15,
        'require_signed_plugins' => true,
        'trusted_public_keys' => [],
        'integrity_check_on_boot' => true,
        'cloudflare_mode' => false,
    ],

    'commerce' => [
        'currency' => 'EUR',
        'tax_required' => true,
        'invoice_renderer' => 'html',
        'download_token_expiry_days' => 30,
        'max_downloads' => 5,
        'taxRates' => [
            ['name' => 'Standard VAT', 'rate' => 0.20, 'country' => 'FR', 'category' => 'standard'],
            ['name' => 'Reduced VAT', 'rate' => 0.055, 'country' => 'FR', 'category' => 'reduced'],
        ],
    ],

    'live_css' => [
        'enabled' => true,
        'max_css_length' => 100_000,
        'allow_external_fonts' => false,
    ],

    'import' => [
        'max_import_size_bytes' => 52_428_800,
        'allow_external_media_download' => true,
        'dry_run_default' => true,
    ],
];
```
