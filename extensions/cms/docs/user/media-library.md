# Media Library Guide

This guide covers uploading, managing, and serving media assets in Pulsar CMS, including image processing, derivative generation, SVG and PDF handling, and CDN configuration.

## Overview

The Pulsar CMS media library provides secure file management with automatic image optimization, format conversion, and locale-aware alt text. All uploads pass through validation, sanitization, and malware scanning before storage.

## Uploading Media

### Via Admin Panel

<!-- Screenshot: Media library upload interface -->

1. Navigate to **Admin > CMS > Content** and open the content editor.
2. In the media section, click **Upload** or drag files into the upload area.
3. Select one or more files from your computer.
4. The system validates each file and reports any rejections.

### Accepted File Types

| Format | MIME Type         | Extensions      | Notes                         |
| ------ | ----------------- | --------------- | ----------------------------- |
| JPEG   | `image/jpeg`      | `.jpg`, `.jpeg` | Photos, compressed images     |
| PNG    | `image/png`       | `.png`          | Lossless images, transparency |
| WebP   | `image/webp`      | `.webp`         | Modern compressed format      |
| AVIF   | `image/avif`      | `.avif`         | Next-gen compressed format    |
| GIF    | `image/gif`       | `.gif`          | Animated and static           |
| SVG    | `image/svg+xml`   | `.svg`          | Vector graphics (sanitized)   |
| PDF    | `application/pdf` | `.pdf`          | Documents (validated)         |

### Upload Limits

| Setting              | Default        | Config Key               |
| -------------------- | -------------- | ------------------------ |
| Maximum file size    | 10 MB          | `media.max_upload_size`  |
| Maximum image width  | 16,384 px      | `media.max_image_width`  |
| Maximum image height | 16,384 px      | `media.max_image_height` |
| Maximum pixel count  | 100,000,000 px | `media.max_pixel_count`  |

These limits prevent decompression bombs and excessive memory usage during processing.

## Image Derivatives

When you upload a raster image (JPEG, PNG, GIF), the CMS automatically generates optimized derivatives.

### WebP Derivatives

WebP derivatives are always generated for uploaded images:

- **Quality**: Configurable via `media.webp_quality` (default: 80)
- **Purpose**: Smaller file sizes with comparable visual quality
- **Serving**: Automatically served to browsers that support WebP via content negotiation

### AVIF Derivatives

AVIF derivatives provide even better compression:

- **Quality**: Configurable via `media.avif_quality` (default: 60)
- **Enabled by default**: Control via `media.avif_enabled`
- **Serving**: Served to browsers with AVIF support

### How Derivatives Work

When a browser requests an image, the CMS checks the `Accept` header and serves the best available format:

1. AVIF (if supported and available)
2. WebP (if supported and available)
3. Original format (fallback)

This transparent optimization reduces bandwidth without any changes to your HTML.

## SVG Handling

SVG files receive special security treatment because they can contain executable code.

### SVG Sanitization

The `SvgSanitizer` processes all SVG uploads:

- Removes `<script>` elements and event handler attributes (`onclick`, `onload`, etc.)
- Strips external resource references that could enable tracking
- Removes embedded fonts and stylesheets with external URLs
- Validates the SVG structure

SVG files are stored in their sanitized form. The original unsanitized file is never persisted.

### SVG Limitations

- No automatic derivative generation (SVGs are resolution-independent)
- No pixel dimension validation (vector format)
- Maximum file size still applies

## PDF Handling

PDF uploads are validated by the `PdfValidator`:

- File header verification (must start with `%PDF-`)
- Structural validation of the PDF object tree
- JavaScript detection and rejection (PDFs with embedded JS are blocked)
- File size validation against upload limits

PDFs are stored as-is after validation. No thumbnail generation is performed.

## Alt Text and Locale-Aware Metadata

Each media asset supports locale-specific alt text and metadata.

### Setting Alt Text

1. Click on a media asset in the library.
2. For each locale, enter:
  - **Alt text**: Descriptive text for accessibility (screen readers)
  - **Title**: Optional hover text
3. Save the metadata.

Alt text is essential for accessibility compliance. Search engines also use it for image indexing.

### Per-Locale Alt Text

In a multi-locale site, each translation can have its own alt text:

| Locale | Alt Text                                        |
| ------ | ----------------------------------------------- |
| `en`   | "Company headquarters building at sunset"       |
| `fr`   | "Batiment du siege social au coucher du soleil" |
| `de`   | "Firmenzentrale bei Sonnenuntergang"            |

The correct alt text is automatically served based on the content's locale context.

## Media Visibility

Each media asset has a visibility setting:

| Visibility | Description                       |
| ---------- | --------------------------------- |
| `public`   | Accessible to all visitors        |
| `private`  | Requires authentication to access |

Private media assets are served through an authenticated endpoint that validates the user's session before delivering the file.

## Filename Sanitization

All uploaded filenames are processed by the `FilenameSanitizer`:

- Non-ASCII characters are transliterated to ASCII
- Special characters are replaced with hyphens
- Multiple consecutive hyphens are collapsed
- The filename is lowercased
- A unique suffix is appended to prevent collisions

For example: `My Photo (Final).JPG` becomes `my-photo-final-a1b2c3d4.jpg`.

## EXIF Data

By default, EXIF metadata is stripped from uploaded images for privacy. EXIF can contain GPS coordinates, camera serial numbers, and other sensitive information.

To preserve EXIF data (for professional photography sites):

```php
'media' => [
    'preserve_exif' => true,
],
```

When EXIF is stripped, only the image orientation data is preserved to ensure correct display.

## Storage Configuration

### Local Disk

The default storage uses the local filesystem:

```php
'media' => [
    'disk' => 'local',
    'storage_path' => 'storage/cms/media',
],
```

Files are organized in subdirectories by date: `storage/cms/media/2026/02/filename.jpg`.

### CDN Configuration

To serve media through a CDN:

1. Configure your CDN to pull from your origin server's media path.
2. Set the CDN base URL in your CMS settings at **Admin > CMS > Settings > Media** (`/admin/cms/settings/media`).
3. All media URLs in rendered content will use the CDN prefix.

The CMS generates cache-busting URLs using content hashes, so CDN caching works safely with long TTLs.

## Permissions

| Permission         | Role           | Description         |
| ------------------ | -------------- | ------------------- |
| `cms.media.view`   | Contributor+   | View media library  |
| `cms.media.upload` | Media Manager+ | Upload new media    |
| `cms.media.delete` | Media Manager+ | Delete media assets |

## Troubleshooting

### Upload Rejected: File Too Large

Increase `media.max_upload_size` in `config/cms.php`. Also check your PHP `upload_max_filesize` and `post_max_size` directives.

### Upload Rejected: Invalid MIME Type

The CMS validates MIME types using file content detection, not just the file extension. Ensure the file is a genuine image or PDF, not a renamed file.

### Derivatives Not Generated

- Check that the GD or Imagick PHP extension is installed.
- For AVIF, ensure your image library supports AVIF encoding.
- Check storage directory permissions.

## Next Steps

- [Content Management Guide](content-management.md) - Embedding media in content
- [SEO Guide](seo-guide.md) - Image alt text and media sitemaps
- [Settings Reference](settings-reference.md) - All media configuration options
