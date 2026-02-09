# Template Directive Reference

The Pulsar CMS provides template-level rendering services through its public API classes. These services are injected into the template rendering context by the `ContentController` and are available to all theme templates.

## Content Rendering

### Content Fields

Content fields from `ContentTranslation` are available as template variables:

| Variable       | Source                                    | Description                                           |
| -------------- | ----------------------------------------- | ----------------------------------------------------- |
| `title`        | `ContentTranslation::$title`              | Content title (plain text, max 500 chars)             |
| `body`         | `ContentTranslation::$body`               | Safe HTML body content                                |
| `excerpt`      | `ContentTranslation::$excerpt`            | Optional summary text                                 |
| `slug`         | `ContentTranslation::$slugSegment`        | URL slug segment                                      |
| `path`         | `ContentTranslation::$path`               | Full computed URL path (e.g., `docs/getting-started`) |
| `locale`       | `ContentTranslation::$locale`             | BCP 47 locale code                                    |
| `reading_time` | `ContentTranslation::$readingTimeMinutes` | Estimated reading time in minutes                     |

### Content Metadata

| Variable              | Source                         | Description                                               |
| --------------------- | ------------------------------ | --------------------------------------------------------- |
| `content_type`        | `Content::$contentType`        | Content type enum value (`article`, `page`)               |
| `status`              | `Content::$status`             | Publishing status                                         |
| `published_at`        | `Content::$publishedAt`        | Publication timestamp                                     |
| `created_at`          | `Content::$createdAt`          | Creation timestamp                                        |
| `updated_at`          | `Content::$updatedAt`          | Last update timestamp                                     |
| `author_id`           | `Content::$authorId`           | UUIDv7 of the author                                      |
| `parent_id`           | `Content::$parentId`           | UUIDv7 of the parent page (for hierarchy)                 |
| `template`            | `Content::$template`           | Theme template override                                   |
| `comment_policy`      | `Content::$commentPolicy`      | Comment policy (`open`, `moderated`, `closed`, `inherit`) |
| `data_classification` | `Content::$dataClassification` | Data classification level                                 |

### Example: Article Template

```html
<article>
  <h1>{{ title }}</h1>
  <div class="article-meta">
    <time datetime="{{ published_at }}">{{ published_at | date('F j, Y') }}</time>
    <span class="reading-time">{{ reading_time }} min read</span>
  </div>
  <div class="article-body">{{ body | raw }}</div>
</article>
```

## SEO Meta Tags

The `SeoServiceInterface` generates meta tags as a `MetaTagCollection` that renders to HTML.

### `MetaTagCollection::toHtml()`

Generates a complete set of SEO meta tags including:

- `<title>` tag (from `metaTitle` override or content title)
- `<meta name="description">` (from `metaDescription`)
- `<link rel="canonical">` (canonical URL)
- `<meta name="robots">` (robots directive)
- Open Graph tags (`og:title`, `og:description`, `og:image`, `og:url`, `og:type`)
- Twitter Card tags (`twitter:card`, `twitter:title`, `twitter:description`)
- Hreflang alternate links (for multi-locale content)

### Usage in Templates

```html
<head>
  {{ seo_meta_tags | raw }}
</head>
```

### Generated Output Example

```html
<title>Getting Started with Pulsar | Acme Corp</title>
<meta name="description" content="Learn how to build your first Pulsar application." />
<link rel="canonical" href="https://example.com/en/docs/getting-started" />
<meta name="robots" content="index, follow" />
<meta property="og:title" content="Getting Started with Pulsar" />
<meta property="og:description" content="Learn how to build your first Pulsar application." />
<meta property="og:url" content="https://example.com/en/docs/getting-started" />
<meta property="og:type" content="article" />
<meta name="twitter:card" content="summary_large_image" />
<link rel="alternate" hreflang="en" href="https://example.com/en/docs/getting-started" />
<link rel="alternate" hreflang="fr" href="https://example.com/fr/docs/demarrage" />
```

### SEO Fields on ContentTranslation

Per-locale SEO overrides are stored on `ContentTranslation`:

| Field                     | Max Length | Description                                           |
| ------------------------- | ---------- | ----------------------------------------------------- |
| `metaTitle`               | 70 chars   | SEO title override (falls back to `title`)            |
| `metaDescription`         | 170 chars  | Meta description                                      |
| `ogImageId`               | UUIDv7     | Open Graph image (media asset reference)              |
| `robots`                  | --         | Robots directive override (e.g., `noindex, nofollow`) |
| `structuredDataOverrides` | JSON       | Per-page JSON-LD overrides                            |

## Structured Data (JSON-LD)

The `SeoServiceInterface` generates JSON-LD structured data via `JsonLdCollection`.

### `JsonLdCollection::toScript()`

Renders all structured data items as a single `<script type="application/ld+json">` block. When multiple items exist, they are wrapped in a `@graph` container.

### Usage in Templates

```html
<head>
  {{ seo_meta_tags | raw }} {{ structured_data | raw }}
</head>
```

### Generated Output Example

For an article:

```html
<script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": "Getting Started with Pulsar",
    "datePublished": "2026-01-15T10:00:00+00:00",
    "dateModified": "2026-02-01T14:30:00+00:00",
    "author": { "@type": "Person", "name": "Jane Author" },
    "publisher": { "@type": "Organization", "name": "Acme Corp" }
  }
</script>
```

### Supported Structured Data Types

The CMS includes two built-in generators:

| Generator                        | Schema.org Type | Content Types          |
| -------------------------------- | --------------- | ---------------------- |
| `ArticleStructuredDataGenerator` | `Article`       | Articles, blog posts   |
| `WebPageStructuredDataGenerator` | `WebPage`       | Pages, general content |

## Breadcrumb Navigation

The `BreadcrumbGeneratorInterface` generates breadcrumb trails based on content hierarchy.

### `BreadcrumbGeneratorInterface::generate(Content, locale): list<BreadcrumbItem>`

Returns an ordered list of `BreadcrumbItem` objects from the site root to the current page.

### `BreadcrumbItem` Properties

| Property    | Type   | Description                     |
| ----------- | ------ | ------------------------------- |
| `label`     | string | Display text for the breadcrumb |
| `url`       | string | URL the breadcrumb links to     |
| `isCurrent` | bool   | Whether this is the active page |

### Usage in Templates

```html
<nav aria-label="Breadcrumb">
  <ol>
    {% for crumb in breadcrumbs %}
    <li>
      {% if crumb.isCurrent %}
      <span aria-current="page">{{ crumb.label }}</span>
      {% else %}
      <a href="{{ crumb.url }}">{{ crumb.label }}</a>
      {% endif %}
    </li>
    {% endfor %}
  </ol>
</nav>
```

### Breadcrumb JSON-LD

The `SeoServiceInterface::generateBreadcrumbJsonLd()` method generates Schema.org `BreadcrumbList` structured data:

```html
<script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    "itemListElement": [
      {
        "@type": "ListItem",
        "position": 1,
        "name": "Home",
        "item": "https://example.com/"
      },
      {
        "@type": "ListItem",
        "position": 2,
        "name": "Documentation",
        "item": "https://example.com/en/docs"
      },
      {
        "@type": "ListItem",
        "position": 3,
        "name": "Getting Started"
      }
    ]
  }
</script>
```

## Navigation Menus

Menus are defined by location (e.g., `primary`, `footer`, `sidebar`) and contain translated menu items.

### Menu Properties

| Property    | Type              | Description              |
| ----------- | ----------------- | ------------------------ |
| `id`        | string            | UUIDv7 identifier        |
| `tenantId`  | string/null       | Tenant scope             |
| `location`  | string            | Menu location identifier |
| `createdAt` | DateTimeImmutable | Creation timestamp       |

### MenuItem Properties

| Property       | Type        | Description                                |
| -------------- | ----------- | ------------------------------------------ |
| `id`           | string      | UUIDv7 identifier                          |
| `menuId`       | string      | Parent menu ID                             |
| `parentItemId` | string/null | Parent item for nested menus               |
| `contentId`    | string/null | Linked content item ID                     |
| `externalUrl`  | string/null | External URL (when not linking to content) |
| `linkTarget`   | LinkTarget  | `_self`, `_blank`, `_parent`, `_top`       |
| `sortOrder`    | int         | Display order                              |
| `cssClass`     | string/null | Custom CSS class                           |

### Usage in Templates

```html
<nav>
  <ul>
    {% for item in menu('primary') %}
    <li class="{{ item.cssClass }}">
      <a href="{{ item.url }}" target="{{ item.linkTarget }}"> {{ item.label }} </a>
      {% if item.children %}
      <ul>
        {% for child in item.children %}
        <li>
          <a href="{{ child.url }}">{{ child.label }}</a>
        </li>
        {% endfor %}
      </ul>
      {% endif %}
    </li>
    {% endfor %}
  </ul>
</nav>
```

## Live CSS Injection

The Live CSS system generates inline `<style>` blocks that override theme CSS custom properties.

### How It Works

1. The `ThemeTokenResolverInterface` reads editable tokens from the active theme's `tokens.json`
2. The `LiveCssServiceInterface` retrieves the current `CssOverride` for the active theme
3. The `LiveCssInjector` generates a `<style>` block combining token overrides and custom CSS
4. The `CspHashComputerInterface` computes a SHA-256 hash for CSP compliance

### Generated Style Block

```html
<style>
  :root {
    --primary-color: #e91e63;
    --font-family: 'Roboto', sans-serif;
  }
  .custom-banner {
    background: linear-gradient(135deg, var(--primary-color), #9c27b0);
  }
</style>
```

### CSP Integration

The style block's SHA-256 hash is used to generate a Content Security Policy directive:

```
style-src 'self' 'sha256-<base64-hash>'
```

This allows the inline style to load without requiring `unsafe-inline` in the CSP.

### Usage in Templates

```html
<head>
  <link rel="stylesheet" href="{{ asset('stylesheet') }}" />
  {{ live_css | raw }}
</head>
```

## Media Assets

Media assets managed through the `MediaServiceInterface` include automatic derivative generation.

### Image Derivatives

The `ImageProcessorInterface` generates optimized derivatives:

| Format | Quality           | Purpose                        |
| ------ | ----------------- | ------------------------------ |
| WebP   | 80 (configurable) | Primary optimized format       |
| AVIF   | 60 (configurable) | Next-gen format (when enabled) |

### Usage in Templates

```html
<picture>
  <source srcset="{{ media_url(image_id, 'avif') }}" type="image/avif" />
  <source srcset="{{ media_url(image_id, 'webp') }}" type="image/webp" />
  <img
    src="{{ media_url(image_id) }}"
    alt="{{ image_alt }}"
    width="{{ image_width }}"
    height="{{ image_height }}"
    loading="lazy"
  />
</picture>
```

## Fragment Caching

Content fragments can be cached using Pulsar's tag-based cache system:

### Usage in Templates

```html
{% cache 'sidebar-widgets' tags=['widgets', 'content-type:article'] ttl=3600 %}
<aside>
  <div class="widget">{{ render_widget('recent_posts') }}</div>
  <div class="widget">{{ render_widget('categories') }}</div>
</aside>
{% endcache %}
```

Cache tags enable targeted invalidation:

- `content:{id}` -- Invalidated when a specific content item changes
- `content-type:{type}` -- Invalidated when any content of that type changes
- `widgets` -- Invalidated when widget configuration changes

## Related Documentation

- [Theme Development Guide](theme-development.md) -- Building themes that use these directives
- [Architecture Overview](architecture.md) -- Cache invalidation flow
- [API Endpoint Reference](api-reference.md) -- REST API for content and media
