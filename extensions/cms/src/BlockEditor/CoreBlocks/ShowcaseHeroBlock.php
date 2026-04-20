<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_string;

use const ENT_QUOTES;

/**
 * Single featured showcase project displayed as a full-width hero section.
 *
 * Renders a semantic `<section>` element with data attributes for client-side
 * enrichment. Supports optional overlay text and CTA button.
 */
#[Internal(reason: 'CMS block type; implementation detail')]
final readonly class ShowcaseHeroBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'showcase-hero';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'projectSlug' => ['type' => 'string'],
                'overlayText' => ['type' => 'string'],
                'ctaLabel' => ['type' => 'string'],
                'ctaUrl' => ['type' => 'string', 'format' => 'uri'],
            ],
            'required' => ['projectSlug'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $rawSlug */
        $rawSlug = $data['projectSlug'] ?? '';
        $slug = htmlspecialchars(is_string($rawSlug) ? $rawSlug : '', ENT_QUOTES, 'UTF-8');

        $html = "<section class=\"cms-showcase-hero\" data-project-slug=\"$slug\">";

        /** @var mixed $overlayText */
        $overlayText = $data['overlayText'] ?? null;

        if (is_string($overlayText) && $overlayText !== '') {
            $escapedText = htmlspecialchars($overlayText, ENT_QUOTES, 'UTF-8');
            $html .= "<h2 class=\"cms-showcase-hero__title\">$escapedText</h2>";
        }

        /** @var mixed $ctaLabel */
        $ctaLabel = $data['ctaLabel'] ?? null;
        /** @var mixed $ctaUrl */
        $ctaUrl = $data['ctaUrl'] ?? null;

        if (is_string($ctaLabel) && $ctaLabel !== '' && is_string($ctaUrl) && $ctaUrl !== '') {
            $escapedLabel = htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8');
            $escapedUrl = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');
            $html .= "<a href=\"$escapedUrl\" class=\"cms-showcase-hero__cta\">$escapedLabel</a>";
        }

        return $html . '</section>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['projectSlug']) || !is_string($data['projectSlug'])) {
            $errors[] = 'projectSlug is required and must be a string';
        } elseif ($data['projectSlug'] === '') {
            $errors[] = 'projectSlug must not be empty';
        }

        if (isset($data['overlayText']) && !is_string($data['overlayText'])) {
            $errors[] = 'overlayText must be a string';
        }

        if (isset($data['ctaLabel']) && !is_string($data['ctaLabel'])) {
            $errors[] = 'ctaLabel must be a string';
        }

        if (isset($data['ctaUrl']) && !is_string($data['ctaUrl'])) {
            $errors[] = 'ctaUrl must be a string';
        }

        return $errors;
    }
}
