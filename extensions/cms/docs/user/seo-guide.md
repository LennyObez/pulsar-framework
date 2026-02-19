# SEO Guide

This guide covers search engine optimization features in Pulsar CMS, including meta tags, structured data, sitemaps, redirect management, link health checks, and robots.txt configuration.

## Overview

Pulsar CMS includes a comprehensive SEO system that generates meta tags, structured data (JSON-LD), XML sitemaps, and manages URL redirects. The system is designed to work automatically with sensible defaults while providing full control for SEO professionals.

## Meta Tags

### Per-Content Meta Tags

Each content item and translation supports SEO meta fields:

| Field            | Description               | HTML Output                               |
| ---------------- | ------------------------- | ----------------------------------------- | ------------------ |
| Meta Title       | Custom `<title>` tag      | `<title>Meta Title                        | Site Name</title>` |
| Meta Description | Search result snippet     | `<meta name="description" content="...">` |
| Robots           | Per-page robots directive | `<meta name="robots" content="...">`      |

Set these fields when editing content at **Admin > CMS > Content > {id}**.

### Title Suffix

All page titles have a configurable suffix appended:

```php
'seo' => [
    'title_suffix' => ' | My Site Name',
],
```

This ensures consistent branding across all pages.

### Default Robots Directive

The site-wide default robots directive is configurable:

```php
'seo' => [
    'default_robots' => 'index, follow',
],
```

Individual content items can override this with their own robots value.

### SEO Headers Middleware

The `CmsSeoHeadersMiddleware` automatically adds SEO-related HTTP headers to every response:

- `X-Robots-Tag` header (mirrors the meta robots value)
- Canonical URL headers
- `hreflang` link headers for multi-locale content

## Structured Data (JSON-LD)

Pulsar CMS generates JSON-LD structured data for search engine rich results.

### Article Structured Data

For content type `article`, the `ArticleStructuredDataGenerator` produces:

```json
{
  "@context": "https://schema.org",
  "@type": "Article",
  "headline": "Article Title",
  "datePublished": "2026-02-19T10:00:00+00:00",
  "dateModified": "2026-02-19T14:30:00+00:00",
  "author": {
    "@type": "Person",
    "name": "Author Name"
  }
}
```

### Web Page Structured Data

For content type `page`, the `WebPageStructuredDataGenerator` produces:

```json
{
  "@context": "https://schema.org",
  "@type": "WebPage",
  "name": "Page Title",
  "description": "Page meta description",
  "url": "https://your-site.com/page-slug"
}
```

### Breadcrumb Structured Data

Breadcrumbs are included as a `BreadcrumbList` in the JSON-LD output, helping search engines understand your site structure.

### Configuration

```php
'seo' => [
    'enable_structured_data' => true,
],
```

Set to `false` to disable automatic structured data generation.

## Sitemaps

### XML Sitemap Generation

The `SitemapGenerator` produces XML sitemaps for search engines at `/sitemap.xml`.

Features:

- Automatic inclusion of all published content
- Per-content-type `changefreq` and `priority` values
- Multi-locale support with `hreflang` annotations
- Media sitemap entries (when `enable_media_sitemap` is `true`)
- Sitemap index for large sites (automatic splitting)

### Configuration

```php
'seo' => [
    'sitemap_changefreq' => [
        'article' => 'weekly',
        'page' => 'monthly',
    ],
    'sitemap_priority' => [
        'page' => 0.8,
        'article' => 0.6,
    ],
    'enable_media_sitemap' => true,
],
```

### Regeneration

Sitemaps are regenerated automatically when content is published or unpublished. The `SitemapRegenerated` event is dispatched after each regeneration.

### Admin Panel

Manage sitemaps at **Admin > CMS > SEO > Sitemap** routes registered under the SitemapController.

## Redirect Manager

The redirect manager handles URL changes gracefully, preserving SEO value.

### Automatic Redirects

When a content item's slug changes, the CMS automatically creates a 301 redirect from the old URL to the new URL. This includes:

- Direct slug changes on the content item
- Path recomputation when a parent page's slug changes (all descendants get redirects)

### Manual Redirects

Create custom redirects at the redirect management admin:

| Field       | Description                                |
| ----------- | ------------------------------------------ |
| Source Path | The old URL path (e.g., `/old-page`)       |
| Target Path | The new URL path (e.g., `/new-page`)       |
| Status Code | HTTP status (301 permanent, 302 temporary) |
| Locale      | Optional locale scope                      |

### Redirect Processing

The `CmsSlugRedirectMiddleware` processes redirects on every request:

1. Check if the request path matches a known redirect source
2. If matched, send the appropriate HTTP redirect response
3. If not matched, continue to content resolution

### Via API

| Method | Route                                           | Description     |
| ------ | ----------------------------------------------- | --------------- |
| GET    | `/admin/cms/redirects` (via RedirectController) | List redirects  |
| POST   | `/admin/cms/redirects`                          | Create redirect |
| DELETE | `/admin/cms/redirects/{id}`                     | Delete redirect |

## Link Health Checks

The link health system monitors internal and external links for broken URLs.

### How It Works

The `LinkHealthChecker` periodically scans all published content for links and verifies their status:

1. Extract all URLs from content bodies and translations
2. Send HEAD requests to external URLs (with SSRF protection)
3. Verify internal URL paths against known content and redirects
4. Record results in the `LinkHealthCheck` table

### Check Schedule

Link health checks run on a cron schedule:

```php
'seo' => [
    'link_health_check_schedule' => '0 3 * * 0',  // Weekly on Sunday at 3 AM
],
```

### Check Results

Each check records:

| Field        | Description                              |
| ------------ | ---------------------------------------- |
| URL          | The checked URL                          |
| Content ID   | The content item containing the link     |
| HTTP Status  | The response status code (or error type) |
| Last Checked | Timestamp of the most recent check       |
| Status       | `healthy`, `broken`, `timeout`, `error`  |

### Admin Panel

View link health results at **Admin > CMS > SEO > Link Health** (`/admin/cms/link-health` via LinkHealthController).

<!-- Screenshot: Link health dashboard showing broken links -->

The dashboard shows:

- Total links monitored
- Number of broken links
- Links that need attention (timeouts, errors)
- Historical trend of link health

### Events

The `LinkHealthCheckCompleted` event fires after each check run, reporting the number of healthy and broken links found.

## Robots.txt

The `RobotsTxtGenerator` produces a `robots.txt` file served at `/robots.txt`.

### Default Output

```
User-agent: *
Allow: /
Disallow: /admin/
Sitemap: https://your-site.com/sitemap.xml
```

### Customization

The robots.txt content is configurable through CMS settings. The generator:

- Blocks access to admin paths
- References the sitemap URL
- Supports per-user-agent rules

## Hreflang Tags

For multi-locale sites, the `HreflangGenerator` produces `hreflang` link tags:

```html
<link rel="alternate" hreflang="en" href="https://site.com/article-slug" />
<link rel="alternate" hreflang="fr" href="https://site.com/fr/article-slug" />
<link rel="alternate" hreflang="x-default" href="https://site.com/article-slug" />
```

These tags are automatically generated for every content item that has translations, helping search engines serve the correct language version.

## RSS/Atom Feeds

The `FeedGenerator` produces syndication feeds:

- RSS 2.0 feed at a configurable path
- Atom feed at a configurable path

Feeds include the latest published articles with title, summary, author, publication date, and permalink.

## Search Analytics

The search analytics system tracks internal site search queries:

- Query terms and frequency
- Click-through rates
- Zero-result queries

View analytics at **Admin > CMS > SEO > Search Analytics** (`/admin/cms/search-analytics` via SearchAnalyticsController).

This data helps you understand what visitors are looking for and identify content gaps.

## SEO Best Practices

### Content Optimization

1. **Write descriptive titles**: Every page should have a unique, descriptive title
2. **Set meta descriptions**: Write compelling 150-160 character descriptions
3. **Use heading hierarchy**: Use h2-h6 headings in logical order
4. **Add alt text**: Every image should have descriptive alt text
5. **Internal linking**: Link between related content pages

### Technical SEO

1. **Check link health**: Monitor the link health dashboard regularly
2. **Manage redirects**: Use 301 redirects for permanently moved content
3. **Review sitemaps**: Verify the sitemap includes all important pages
4. **Structured data**: Ensure structured data is enabled and validated
5. **Multi-locale**: Use hreflang tags for multi-language content

### Performance

1. **Page caching**: Enable page caching for fast load times
2. **Image optimization**: Use WebP/AVIF derivatives for smaller file sizes
3. **CDN**: Configure a CDN for static asset delivery

## Permissions

| Permission       | Role         | Description                                  |
| ---------------- | ------------ | -------------------------------------------- |
| `cms.seo.view`   | SEO Manager+ | View SEO tools and reports                   |
| `cms.seo.manage` | SEO Manager+ | Manage redirects, sitemaps, and SEO settings |

## Next Steps

- [Content Management Guide](content-management.md) - Writing SEO-friendly content
- [Media Library Guide](media-library.md) - Image alt text and media sitemaps
- [Settings Reference](settings-reference.md) - Complete SEO configuration reference
