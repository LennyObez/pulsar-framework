<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\FullSiteEditor;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A reusable template part (header, footer, sidebar) composed of blocks.
 *
 * Template parts are the building blocks of full-site editing. Each part
 * can be independently edited and is rendered within template layouts.
 *
 * @psalm-api Public DTO returned from TemplatePartRepositoryInterface;
 *            consumed by template rendering and admin editor.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TemplatePart
{
    /**
     * @param string $id Unique identifier
     * @param string $slug URL-safe slug (e.g., 'header', 'footer', 'sidebar')
     * @param TemplatePartArea $area Which area this part occupies
     * @param string $name Human-readable display name
     * @param string $content Serialized block content (JSON)
     * @param string $themeId Optional theme association
     */
    public function __construct(
        public string $id,
        public string $slug,
        public TemplatePartArea $area,
        public string $name,
        public string $content,
        public string $themeId = '',
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {}
}
