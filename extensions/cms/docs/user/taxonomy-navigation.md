# Taxonomy & Navigation Guide

This guide covers creating and managing taxonomies (categories and tags), building menus, configuring breadcrumbs, and organizing your site's navigation structure.

## Taxonomies

Taxonomies are classification systems for organizing content. Pulsar CMS supports an unlimited number of custom taxonomies, each with a hierarchical tree of terms.

### Creating a Taxonomy

1. Navigate to **Admin > CMS > Taxonomies** (`/admin/cms/taxonomies`).
2. Click **Create New Taxonomy**.
3. Fill in the fields:
   - **Slug**: URL-safe identifier (e.g., `categories`, `tags`, `topics`)
   - **Name**: Human-readable name per locale
   - **Description**: Optional description per locale
4. Save the taxonomy.

### Via API

```
POST /admin/cms/taxonomies
Content-Type: application/json

{
    "slug": "categories",
    "translations": {
        "en": { "name": "Categories", "description": "Content categories" },
        "fr": { "name": "Categories", "description": "Categories de contenu" }
    }
}
```

### Managing Taxonomy Terms

Each taxonomy contains terms (e.g., "Technology", "Business", "Health").

1. Open a taxonomy at **Admin > CMS > Taxonomies > {slug}** (`/admin/cms/taxonomies/{slug}`).
2. Add terms with:
   - **Slug**: URL-safe identifier
   - **Name**: Translated display name per locale
   - **Description**: Optional translated description
   - **Parent term**: For hierarchical taxonomies, select a parent
3. Drag and drop terms to reorder them within their hierarchy level.

### Hierarchical Terms

Terms can be nested to create hierarchical structures:

```
Technology
  |-- Software
  |   |-- Web Development
  |   |-- Mobile Development
  |-- Hardware
  |-- AI & Machine Learning
Business
  |-- Startups
  |-- Finance
```

Each term has a `sort_order` that controls its position among siblings.

### Translations

Taxonomy names and term names support full multi-locale translation:

| Locale | Taxonomy Name | Term Example |
| ------ | ------------- | ------------ |
| `en`   | Categories    | Technology   |
| `fr`   | Categories    | Technologie  |
| `de`   | Kategorien    | Technologie  |

### Assigning Content to Terms

When editing content, select taxonomy terms from the available taxonomies. Content can belong to multiple terms across multiple taxonomies.

## Menus

Menus define navigation structures for your site's header, footer, sidebar, and other regions.

### Creating a Menu

1. Navigate to **Admin > CMS > Menus** (`/admin/cms/menus`).
2. Click **Create New Menu**.
3. Fill in the fields:
   - **Location**: Identifier for where the menu appears (e.g., `main`, `footer`, `sidebar`)
   - **Name**: Translated display name per locale
4. Save the menu.

### Via API

```
POST /admin/cms/menus
Content-Type: application/json

{
    "location": "main",
    "translations": {
        "en": { "name": "Main Navigation" },
        "fr": { "name": "Navigation Principale" }
    }
}
```

### Adding Menu Items

1. Open a menu at **Admin > CMS > Menus > {location}** (`/admin/cms/menus/{location}`).
2. Add items with:
   - **Label**: Translated display text per locale
   - **URL or Content ID**: Link destination (absolute URL, relative path, or CMS content reference)
   - **Link Target**: `_self` (same window) or `_blank` (new window)
   - **Parent Item**: For nested navigation, select a parent
   - **CSS Class**: Optional CSS class for styling
3. Drag and drop items to reorder.

### Menu Item Types

| Type          | Description                      | Example                        |
| ------------- | -------------------------------- | ------------------------------ |
| Content Link  | Links to a CMS content item      | Points to article ID `abc-123` |
| Custom URL    | Links to any URL                 | `https://external-site.com`    |
| Relative Path | Links to a path on the same site | `/about/team`                  |

Content links automatically update when the linked content's slug changes.

### Nested Menus

Menu items support nesting for dropdown/flyout navigation:

```
Home
About
  |-- Our Team
  |-- History
  |-- Contact
Products
  |-- Software
  |-- Services
Blog
```

### Menu Translations

Each menu item's label is translatable per locale:

| Locale | Menu Item |
| ------ | --------- |
| `en`   | About Us  |
| `fr`   | A Propos  |
| `de`   | Uber Uns  |

The correct label is rendered based on the visitor's active locale.

### Link Targets

| Target   | Behavior                                |
| -------- | --------------------------------------- |
| `_self`  | Opens in the same browser tab (default) |
| `_blank` | Opens in a new browser tab              |

External links opened in `_blank` automatically receive `rel="noopener noreferrer"` for security.

## Breadcrumbs

Breadcrumbs show the visitor's position in the site hierarchy.

### How Breadcrumbs Work

The `BreadcrumbGenerator` automatically builds breadcrumb trails based on:

1. **Page hierarchy**: For pages with a parent, the breadcrumb follows the parent chain
2. **Locale awareness**: Breadcrumb labels use the correct locale translation
3. **Configuration**: The home page label and separator are configurable

### Example Breadcrumb

For a page at `/products/software/enterprise`:

```
Home > Products > Software > Enterprise Edition
```

### Breadcrumb Configuration

Breadcrumbs are configured through the CMS config and work with the theme's template engine. The `BreadcrumbGeneratorInterface` can be injected into any controller or template to render breadcrumbs.

The generator:

- Walks the content parent chain from the current page to the root
- Resolves locale-specific titles for each ancestor
- Includes the current page as the final, non-linked item
- Respects the configured `max_hierarchy_depth` to prevent infinite loops

## Drag-and-Drop Reordering

Both taxonomy terms and menu items support drag-and-drop reordering in the admin panel.

### Reordering Taxonomy Terms

<!-- Screenshot: Taxonomy term drag-and-drop interface -->

1. Open a taxonomy at **Admin > CMS > Taxonomies > {slug}**.
2. Grab a term by its drag handle.
3. Drag it to the desired position (above/below siblings, or nested under a parent).
4. Release to save the new order.

The `sort_order` values are automatically recalculated.

### Reordering Menu Items

<!-- Screenshot: Menu item drag-and-drop interface -->

1. Open a menu at **Admin > CMS > Menus > {location}**.
2. Grab a menu item by its drag handle.
3. Drag to reorder among siblings or nest under a parent item.
4. Release to save.

### Keyboard Accessibility

For users who cannot use a mouse, reordering is also available via:

- Arrow keys to move items up/down
- Tab to navigate between items
- Enter to confirm position
- Escape to cancel the move

## Permissions

| Permission            | Role         | Description                               |
| --------------------- | ------------ | ----------------------------------------- |
| `cms.taxonomy.view`   | Contributor+ | View taxonomies and terms                 |
| `cms.taxonomy.manage` | Editor+      | Create, edit, delete taxonomies and terms |
| `cms.menus.view`      | Contributor+ | View menus and items                      |
| `cms.menus.manage`    | Editor+      | Create, edit, delete menus and items      |

## API Reference

### Taxonomies

| Method | Route                          | Description                |
| ------ | ------------------------------ | -------------------------- |
| GET    | `/admin/cms/taxonomies`        | List all taxonomies        |
| POST   | `/admin/cms/taxonomies`        | Create a taxonomy          |
| GET    | `/admin/cms/taxonomies/{slug}` | View a taxonomy with terms |
| PUT    | `/admin/cms/taxonomies/{slug}` | Update a taxonomy          |
| DELETE | `/admin/cms/taxonomies/{slug}` | Delete a taxonomy          |

### Menus

| Method | Route                         | Description                 |
| ------ | ----------------------------- | --------------------------- |
| GET    | `/admin/cms/menus`            | List all menus              |
| POST   | `/admin/cms/menus`            | Create a menu               |
| GET    | `/admin/cms/menus/{location}` | View a menu with items      |
| PUT    | `/admin/cms/menus/{location}` | Update a menu and its items |
| DELETE | `/admin/cms/menus/{location}` | Delete a menu               |

## Next Steps

- [Content Management Guide](content-management.md) -- Assigning content to taxonomy terms
- [Theme Management](theme-management.md) -- Rendering menus and breadcrumbs in themes
- [SEO Guide](seo-guide.md) -- Structured data for breadcrumbs
