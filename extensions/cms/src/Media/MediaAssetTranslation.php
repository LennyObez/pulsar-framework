<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use Pulsar\Api\Api;

/**
 * Per-locale translation fields for a media asset.
 *
 * Provides localized alt text, caption, and title for accessibility
 * and SEO across multiple languages.
 *
 * @psalm-api Public DTO returned from MediaRepositoryInterface; consumed by
 *            ResponsiveImageRenderer and admin views.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MediaAssetTranslation
{
    /**
     * @param string $mediaAssetId UUIDv7 FK media_assets
     * @param string $locale BCP 47 locale code
     * @param string|null $altText Localized alt text for accessibility
     * @param string|null $caption Localized caption for display
     * @param string|null $title Localized title for tooltips/SEO
     */
    public function __construct(
        public string $mediaAssetId,
        public string $locale,
        public ?string $altText,
        public ?string $caption,
        public ?string $title,
    ) {}
}
