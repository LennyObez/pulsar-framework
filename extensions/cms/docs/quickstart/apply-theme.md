# Quickstart: apply a theme

**Estimated time: 5 minutes**

This quickstart walks you through downloading a theme archive, uploading it to the CMS, previewing it, and activating it for your site.

## Prerequisites

- CMS admin access (the `cms.admin` role with theme permissions)
- A theme archive (`.zip` file) conforming to the Pulsar CMS theme format

## Step 1: prepare your theme archive

A valid theme archive must contain a `theme.json` manifest at the root. Example:

```json
{
  "slug": "modern-business",
  "display_name": "Modern Business",
  "version": "1.0.0",
  "description": "A clean, modern theme for business websites",
  "author_name": "Your Company",
  "license": "MIT",
  "regions": ["header", "content", "sidebar", "footer"],
  "supported_content_types": ["article", "page"],
  "settings": {
    "primary_color": "#2563eb",
    "font_family": "Inter, sans-serif"
  },
  "assets": {
    "style": "assets/css/main.css",
    "script": "assets/js/main.js"
  }
}
```

The archive should also contain the referenced template and asset files.

## Step 2: upload the theme

1. Navigate to **Admin > CMS > Themes** (`/admin/cms/themes`).
2. Click **Install New Theme**.
3. Select your `.zip` file and upload it.

<!-- Screenshot: Theme upload form -->

The CMS validates:

- Archive size (max 50 MB by default)
- File count (max 10,000 files)
- Manifest presence and required fields
- Signature (if `require_signed_themes` is enabled)
- Path traversal safety

After successful validation, the theme appears in the installed themes list.

## Step 3: preview the theme

Before activating, preview how the theme looks:

1. In the theme list, find **Modern Business**.
2. Click **Preview**.

```
POST /admin/cms/themes/{id}/preview
```

3. A preview session starts. You see the site rendered with the new theme.
4. Only your browser session shows the preview - public visitors still see the current active theme.

<!-- Screenshot: Site rendered with preview theme -->

Browse several pages to verify the theme renders correctly:

- Check the home page layout
- Verify article and page rendering
- Test navigation menus
- Check mobile responsiveness

## Step 4: activate the theme

Satisfied with the preview? Activate the theme:

1. Return to **Admin > CMS > Themes**.
2. Click **Activate** on **Modern Business**.
3. Confirm the activation.

```
POST /admin/cms/themes/{id}/activate
```

The theme is now live for all visitors.

<!-- Screenshot: Theme list showing "Modern Business" as active -->

## Step 5: verify

Open a new browser tab (or incognito window) and visit your site. Confirm the new theme is rendering correctly for public visitors.

## Rolling back

If something is wrong, quickly revert:

1. Go to **Admin > CMS > Themes**.
2. Click **Activate** on the previous theme.
3. The old theme is restored immediately.

If the theme causes a critical error preventing admin access, Pulsar CMS will automatically enter safe mode with a minimal fallback template.

## Done

Your site is now running with the new theme. Theme assets are deployed to the public directory, and all pages render with the new templates.

## Next steps

- [Theme Management Guide](../user/theme-management.md) - Inheritance, safe mode, and provenance
- [Live CSS Guide](../user/live-css-guide.md) - Customize theme styles without editing files
