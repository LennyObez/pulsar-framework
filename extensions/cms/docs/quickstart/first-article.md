# Quickstart: Publish Your First Article

**Estimated time: 5 minutes**

This quickstart walks you through installing the CMS extension, creating an article, publishing it, and viewing it on your site.

## Prerequisites

- A working Pulsar Framework application
- PHP 8.5+
- Database configured and accessible
- An admin account

## Step 1: Enable the CMS Extension

Add the CMS extension to `config/extensions.php`:

```php
<?php

declare(strict_types=1);

return [
    'extensions' => [
        \Pulsar\Extension\Cms\CmsExtension::class,
    ],
];
```

## Step 2: Create the Configuration

Create `config/cms.php`:

```php
<?php

declare(strict_types=1);

return [
    'default_locale' => 'en',
    'supported_locales' => ['en'],
];
```

## Step 3: Run Migrations

```bash
php bin/pulsar migrate
```

## Step 4: Log In to the Admin Panel

Open your browser and navigate to:

```
https://your-site.com/admin/cms
```

Log in with your admin credentials. You should see the CMS dashboard.

<!-- Screenshot: CMS dashboard after first login -->

## Step 5: Create an Article

1. Click **Content** in the admin sidebar, or go to `/admin/cms/content`.
2. Click **Create New**.
3. Fill in the form:
   - **Content Type**: `article`
   - **Title**: `Welcome to Our Site`
   - **Slug**: `welcome-to-our-site` (auto-generated)
   - **Body**:
     ```html
     <h2>Hello, World!</h2>
     <p>This is our first article published with Pulsar CMS.</p>
     <p>
       The CMS provides full content management with editorial workflows, multi-locale support, and
       compliance features.
     </p>
     ```
4. Click **Save as Draft**.

<!-- Screenshot: Content creation form with fields filled in -->

## Step 6: Publish the Article

1. On the content detail page, click **Publish**.
2. The article status changes from **Draft** to **Published**.

<!-- Screenshot: Content detail page showing Published status -->

## Step 7: View Your Article

Open a new browser tab and navigate to:

```
https://your-site.com/welcome-to-our-site
```

Your article is now live and publicly accessible.

<!-- Screenshot: Published article on the public site -->

## What Happened

1. The CMS extension loaded configuration from `config/cms.php`.
2. Database migrations created the CMS tables.
3. You created a content item of type `article` with a title, slug, and body.
4. The HTML body was sanitized through the SafeHtmlPolicy.
5. Publishing transitioned the content from Draft to Published status.
6. The public route `/{path}` resolved the slug and rendered the content.

## Next Steps

- [Content Management Guide](../user/content-management.md) -- Learn about translations, revisions, and workflows
- [Media Library Guide](../user/media-library.md) -- Add images to your articles
- [SEO Guide](../user/seo-guide.md) -- Optimize your content for search engines
