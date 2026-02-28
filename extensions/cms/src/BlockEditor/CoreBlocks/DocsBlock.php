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
 * Embeds a reference/link card to a documentation page.
 *
 * Renders a placeholder card with data attributes that the frontend
 * or server-side enrichment layer populates with title, section,
 * difficulty badge, and reading time.
 */
#[Internal(reason: 'CMS block type — implementation detail')]
final readonly class DocsBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'docs-reference';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'docSlug' => ['type' => 'string'],
                'showSection' => ['type' => 'boolean', 'default' => true],
                'showDifficulty' => ['type' => 'boolean', 'default' => true],
                'showReadingTime' => ['type' => 'boolean', 'default' => true],
            ],
            'required' => ['docSlug'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $rawSlug = $data['docSlug'] ?? '';
        $slug = htmlspecialchars(is_string($rawSlug) ? $rawSlug : '', ENT_QUOTES, 'UTF-8');
        $showSection = ($data['showSection'] ?? true) ? 'true' : 'false';
        $showDifficulty = ($data['showDifficulty'] ?? true) ? 'true' : 'false';
        $showReadingTime = ($data['showReadingTime'] ?? true) ? 'true' : 'false';

        return '<div class="cms-docs-card"'
            . " data-doc-slug=\"$slug\""
            . " data-show-section=\"$showSection\""
            . " data-show-difficulty=\"$showDifficulty\""
            . " data-show-reading-time=\"$showReadingTime\">"
            . "<span class=\"cms-docs-card__slug\">$slug</span>"
            . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['docSlug']) || !is_string($data['docSlug'])) {
            $errors[] = 'docSlug is required and must be a string';
        } elseif ($data['docSlug'] === '') {
            $errors[] = 'docSlug must not be empty';
        }

        return $errors;
    }
}
