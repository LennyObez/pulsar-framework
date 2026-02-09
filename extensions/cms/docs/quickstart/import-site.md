# Quickstart: import a site definition

**Estimated time: 10 minutes**

This quickstart walks you through preparing a JSON site definition, importing it through the admin panel, running a dry run, executing the import, and verifying the imported site.

## Prerequisites

- CMS admin access with import permissions
- The CMS extension installed and configured
- Database migrations completed

## Step 1: prepare the site definition

Create a JSON file named `my-site.json` with the following structure:

```json
{
  "version": "1.0",
  "site": {
    "name": "Acme Corporation",
    "default_locale": "en",
    "supported_locales": ["en", "fr"]
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
          "slug": "news",
          "translations": {
            "en": { "name": "News" },
            "fr": { "name": "Actualites" }
          }
        },
        {
          "slug": "products",
          "translations": {
            "en": { "name": "Products" },
            "fr": { "name": "Produits" }
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
          "body": "<h2>Our Story</h2><p>Acme Corporation was founded in 2020 with a mission to build great software.</p>",
          "meta_description": "Learn about Acme Corporation's mission and team."
        },
        "fr": {
          "title": "A Propos",
          "body": "<h2>Notre Histoire</h2><p>Acme Corporation a ete fondee en 2020 avec la mission de creer d'excellents logiciels.</p>",
          "meta_description": "Decouvrez la mission et l'equipe d'Acme Corporation."
        }
      }
    },
    {
      "type": "page",
      "slug": "contact",
      "translations": {
        "en": {
          "title": "Contact",
          "body": "<h2>Get in Touch</h2><p>Email us at <a href=\"mailto:hello@acme.example.com\">hello@acme.example.com</a>.</p>",
          "meta_description": "Contact Acme Corporation."
        },
        "fr": {
          "title": "Contact",
          "body": "<h2>Nous Contacter</h2><p>Ecrivez-nous a <a href=\"mailto:hello@acme.example.com\">hello@acme.example.com</a>.</p>",
          "meta_description": "Contactez Acme Corporation."
        }
      }
    },
    {
      "type": "article",
      "slug": "welcome",
      "taxonomy_terms": ["news"],
      "translations": {
        "en": {
          "title": "Welcome to Acme",
          "body": "<p>We are excited to launch our new website powered by Pulsar CMS.</p>",
          "meta_description": "Acme Corporation launches its new website."
        },
        "fr": {
          "title": "Bienvenue chez Acme",
          "body": "<p>Nous sommes ravis de lancer notre nouveau site web propulse par Pulsar CMS.</p>",
          "meta_description": "Acme Corporation lance son nouveau site web."
        }
      }
    }
  ],
  "menus": [
    {
      "location": "main",
      "translations": {
        "en": { "name": "Main Navigation" },
        "fr": { "name": "Navigation Principale" }
      },
      "items": [
        {
          "label": { "en": "Home", "fr": "Accueil" },
          "url": "/"
        },
        {
          "label": { "en": "About", "fr": "A Propos" },
          "content_slug": "about"
        },
        {
          "label": { "en": "Blog", "fr": "Blog" },
          "url": "/news"
        },
        {
          "label": { "en": "Contact", "fr": "Contact" },
          "content_slug": "contact"
        }
      ]
    }
  ],
  "media": [],
  "redirects": [],
  "seo": {
    "title_suffix": " | Acme Corporation"
  }
}
```

### Key requirements

- `version` must be `"1.0"`
- `site` object is required with at least a name
- All other top-level keys (`taxonomies`, `content`, `menus`, `media`, `redirects`, `seo`) are optional

## Step 2: navigate to site import

1. Log in to the CMS admin panel.
2. Navigate to **Admin > CMS > Site Import** (`/admin/cms/site-import`).

<!-- Screenshot: Site import form -->

## Step 3: run a dry run

Before importing, preview what will happen:

1. Upload or paste your `my-site.json` file.
2. Click **Dry Run**.

```
POST /admin/cms/site-import/dry-run
Content-Type: application/json

{ ... your site definition ... }
```

The dry run report shows:

| Action                 | Count |
| ---------------------- | ----- |
| Taxonomies to create   | 1     |
| Terms to create        | 2     |
| Pages to create        | 2     |
| Articles to create     | 1     |
| Translations to create | 6     |
| Menus to create        | 1     |
| Menu items to create   | 4     |
| Conflicts              | 0     |
| Errors                 | 0     |

<!-- Screenshot: Dry run results -->

Review the report to confirm everything looks correct.

### Handling conflicts

If the dry run reports conflicts (e.g., a slug already exists), you can:

- Rename the conflicting slug in the definition
- Delete the existing content first
- Let the import skip conflicting items

## Step 4: execute the import

After reviewing the dry run:

1. Click **Execute Import**.

```
POST /admin/cms/site-import/execute
Content-Type: application/json

{ ... your site definition ... }
```

The import processes entities in dependency order:

1. Site settings
2. Taxonomies and terms
3. Content items and translations
4. Menus and items
5. Media references
6. Redirects
7. SEO configuration

Each step is validated before proceeding. If an error occurs, previously imported items are preserved, and the error is reported.

## Step 5: verify the imported site

### Check content

1. Navigate to **Admin > CMS > Content** (`/admin/cms/content`).
2. Verify all 3 content items are present:

- About Us (page)
- Contact (page)
- Welcome to Acme (article)

3. Open each item and confirm translations are correct for both `en` and `fr`.

<!-- Screenshot: Content list showing imported items -->

### Check taxonomies

1. Navigate to **Admin > CMS > Taxonomies** (`/admin/cms/taxonomies`).
2. Verify the "Categories" taxonomy with "News" and "Products" terms.

### Check menus

1. Navigate to **Admin > CMS > Menus** (`/admin/cms/menus`).
2. Verify the "Main Navigation" menu with 4 items.

### Check public site

Open your site in a browser:

- `https://your-site.com/about` -- English about page
- `https://your-site.com/fr/a-propos` -- French about page
- `https://your-site.com/welcome` -- English welcome article
- `https://your-site.com/fr/bienvenue-chez-acme` -- French welcome article

### Publish content

Imported content starts in **Draft** status. To make it public:

1. Open each content item.
2. Click **Publish**.

Or publish all items through the content list using bulk actions.

## Done

Your site is bootstrapped with content, taxonomies, menus, and translations from a single JSON definition. This approach is ideal for:

- Bootstrapping new sites from templates
- Migrating content between environments
- AI-assisted site generation (generate the JSON, then import)
- Reproducible site setups for testing

## Next steps

- [Import/Export Guide](../user/import-export-guide.md) - Full import/export documentation
- [Content Management Guide](../user/content-management.md) - Editing imported content
- [Settings Reference](../user/settings-reference.md) - All configuration options
