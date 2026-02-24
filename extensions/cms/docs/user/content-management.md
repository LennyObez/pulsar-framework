# Content Management Guide

This guide covers creating and managing content in Pulsar CMS, including articles, pages, content blocks, translations, revisions, and the editorial workflow.

## Content Types

Pulsar CMS ships with two built-in content types:

| Type        | Use Case                                                 |
| ----------- | -------------------------------------------------------- |
| **Article** | Blog posts, news items, time-sensitive content           |
| **Page**    | Static pages, landing pages, hierarchical site structure |

CMS plugins can register additional custom content types through the `content_types` capability.

## Creating Content

### Via Admin Panel

1. Navigate to **Admin > CMS > Content** (`/admin/cms/content`).
2. Click **Create New** to open the content creation form.
3. Fill in the fields:

| Field               | Required | Description                                    |
| ------------------- | -------- | ---------------------------------------------- |
| Content Type        | Yes      | `article` or `page` (or a custom type)         |
| Title               | Yes      | The content title (per locale)                 |
| Slug                | Auto     | URL-safe identifier, auto-generated from title |
| Body                | Yes      | Rich-text content body (sanitized HTML)        |
| Template            | No       | Theme template override                        |
| Parent Page         | No       | For pages, creates hierarchy                   |
| Sort Order          | No       | Ordering among siblings (default: 0)           |
| Comment Policy      | No       | `inherit`, `open`, or `closed`                 |
| Data Classification | No       | `public`, `internal`, or `confidential`        |

4. Click **Save as Draft** to create the content in draft status.

### Via API

```
POST /admin/cms/content
Content-Type: application/json

{
    "content_type": "article",
    "title": "My First Article",
    "slug": "my-first-article",
    "body": "<p>Hello, world!</p>",
    "locale": "en",
    "comment_policy": "open",
    "data_classification": "public"
}
```

## Content Body and HTML Sanitization

All user-authored HTML is processed through the **SafeHtmlPolicy**, which implements a 7-step sanitization pipeline:

1. **Input canonicalization** -- UTF-8 conversion, null byte removal, BiDi control character stripping
2. **DOM parsing** -- Safe HTML parsing with external entity loading disabled
3. **Tree walk** -- Removes disallowed elements, unwraps their children
4. **Attribute filtering** -- Strips non-allowlisted attributes per element
5. **URL sanitization** -- Validates `href` and `src` attributes, enforces safe schemes
6. **Dangerous construct removal** -- Strips `on*` event handlers, `style`, `data-*` attributes
7. **Final validation** -- Regex scan for remaining dangerous patterns

### Allowed HTML Elements

The following elements are permitted in content bodies:

| Category | Elements                                                                        |
| -------- | ------------------------------------------------------------------------------- |
| Block    | `p`, `blockquote`, `pre`, `hr`, `details`, `summary`                            |
| Heading  | `h2`, `h3`, `h4`, `h5`, `h6`                                                    |
| List     | `ul`, `ol`, `li`, `dl`, `dt`, `dd`                                              |
| Inline   | `strong`, `em`, `mark`, `sub`, `sup`, `abbr`, `time`, `code`                    |
| Link     | `a` (with `href`, `rel`, `title`)                                               |
| Media    | `img` (with `src`, `alt`, `width`, `height`, `loading`), `figure`, `figcaption` |
| Table    | `table`, `thead`, `tbody`, `tr`, `th`, `td`                                     |

Links to external URLs automatically receive `rel="noopener noreferrer"`. Image sources must use HTTPS, a relative `/media/` path, or a safe data URI under 32 KB.

## Content Blocks

Content blocks are reusable content fragments that can be embedded in multiple pages.

### Managing Content Blocks

1. Navigate to the content editor for any article or page.
2. In the **Content Blocks** section, add blocks to compose your page layout.
3. Each block has its own type, locale-specific body, and sort order.

Blocks allow you to create modular page layouts with reusable sections like hero banners, call-to-action areas, or sidebar widgets.

## Translations

Pulsar CMS supports full multi-locale content with per-locale translations.

### Adding a Translation

1. Open a content item at **Admin > CMS > Content > {id}**.
2. Click **Add Translation**.
3. Select the target locale.
4. Enter the translated title, slug, body, and meta description.
5. Save the translation.

Each translation has its own:

- Title
- Slug (locale-specific URL path)
- Body content
- Meta title and meta description (for SEO)

### Via API

```
POST /admin/cms/content/{id}/translations
Content-Type: application/json

{
    "locale": "fr",
    "title": "Mon Premier Article",
    "slug": "mon-premier-article",
    "body": "<p>Bonjour le monde !</p>",
    "meta_title": "Mon Premier Article | Mon Site",
    "meta_description": "Un article d'introduction."
}
```

## Publishing Workflow

### Standard Mode

In standard mode, content follows a simple lifecycle:

```
Draft --> Published        (direct publish)
Draft --> Scheduled        (future publication)
Scheduled --> Published    (automatic at scheduled time)
Published --> Archived     (remove from public view)
Archived --> Draft         (restore for editing)
```

**Actions:**

| Action   | Route                                   | Permission            |
| -------- | --------------------------------------- | --------------------- |
| Publish  | `POST /admin/cms/content/{id}/publish`  | `cms.content.publish` |
| Schedule | `POST /admin/cms/content/{id}/schedule` | `cms.content.publish` |
| Archive  | `POST /admin/cms/content/{id}/archive`  | `cms.content.archive` |

### Editorial Workflow Mode

When `editorial_workflow` is enabled in `config/cms.php`, content must pass through review:

```
Draft --> In Review        (author submits for review)
In Review --> Approved     (reviewer approves)
In Review --> Draft        (reviewer rejects, with comments)
Approved --> Published     (editor publishes)
Approved --> Scheduled     (editor schedules)
```

**Additional actions:**

| Action            | Route                                        | Permission                  |
| ----------------- | -------------------------------------------- | --------------------------- |
| Submit for Review | `POST /admin/cms/content/{id}/submit-review` | `cms.content.submit_review` |
| Approve           | `POST /admin/cms/reviews/{id}/approve`       | `cms.content.approve`       |
| Reject            | `POST /admin/cms/reviews/{id}/reject`        | `cms.content.approve`       |

### Editorial Reviews

View pending reviews at **Admin > CMS > Reviews** (`/admin/cms/reviews`).

Each review records:

- The content item and its current revision
- The reviewer and their decision
- Comments or rejection reasons
- Timestamps for submission, decision, and any status changes

## Revisions

Every content edit creates a new revision, providing a complete change history.

### Viewing Revisions

1. Navigate to **Admin > CMS > Content > {id} > Revisions** (`/admin/cms/content/{contentId}/revisions`).
2. Each revision shows the author, timestamp, and a diff from the previous version.

### Restoring a Revision

To roll back to a previous version:

```
POST /admin/cms/content/{contentId}/revisions/{revisionId}/restore
```

This creates a new revision with the restored content, preserving the full history.

## Content Locking

Content locking prevents concurrent editing conflicts.

### Acquiring a Lock

When you begin editing content, a lock is automatically acquired:

```
POST /admin/cms/content/{id}/lock
```

The lock is scoped to a specific locale and records the user and timestamp.

### Releasing a Lock

Locks are released when you finish editing:

```
DELETE /admin/cms/content/{id}/lock
```

### Force Unlock

Editors with the `cms.content.force_unlock` permission can release locks held by other users. This action is audit-logged.

## Page Hierarchy

Pages support hierarchical nesting up to the configured `max_hierarchy_depth` (default: 10 levels).

### Setting a Parent Page

1. Edit a page at **Admin > CMS > Content > {id}**.
2. Select a **Parent Page** from the dropdown.
3. Set the **Sort Order** to control sibling ordering.
4. Save the page.

The URL path is computed from the hierarchy. For example, a page with slug `pricing` under a parent with slug `products` produces the path `/products/pricing`.

When a parent page's slug changes, all descendant paths are automatically recomputed, and redirects are created for the old URLs.

## Custom Fields

Custom content type fields extend the built-in content model.

### Managing Custom Fields

Navigate to **Admin > CMS > Fields > {contentType}** (`/admin/cms/fields/{contentType}`).

Available field types:

| Type       | Description                       |
| ---------- | --------------------------------- |
| `text`     | Single-line text input            |
| `textarea` | Multi-line text input             |
| `number`   | Numeric input                     |
| `boolean`  | Toggle / checkbox                 |
| `date`     | Date picker                       |
| `select`   | Dropdown selection                |
| `media`    | Media asset reference             |
| `relation` | Reference to another content item |

Each field has a machine name, display label, field type, validation rules, and sort order.

## Event Sourcing

When `event_sourcing` is enabled, every content mutation is recorded as an immutable event:

- Content created, updated, published, archived, restored, deleted
- Revision created
- Slug changed (with redirect creation)

Events include the actor ID, timestamp, and a serialized payload of the change.

## Atomic Snapshots

When `atomic_snapshots` is enabled, publishing a content item creates an atomic snapshot of all its locale translations at that point in time. This ensures a consistent reference point for audit and compliance.

## Data Classification

Each content item carries a `DataClassification` level:

| Level          | Description                           |
| -------------- | ------------------------------------- |
| `public`       | No access restrictions                |
| `internal`     | Organization-internal content         |
| `confidential` | Restricted access, audit-logged views |

The classification is set at creation time and can be updated by authorized users.

## Next Steps

- [Media Library Guide](media-library.md) - Managing media assets
- [Taxonomy & Navigation](taxonomy-navigation.md) - Categories, tags, and menus
- [Comment Moderation](comment-moderation.md) - Managing user comments
- [SEO Guide](seo-guide.md) - Optimizing content for search engines
