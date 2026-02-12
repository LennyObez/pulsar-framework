<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_int;
use function is_string;
use function max;
use function min;

use const ENT_QUOTES;

/**
 * Showcase project grid block for the block editor.
 *
 * Renders a container element with data attributes that the frontend
 * populates with a filterable grid of showcase/portfolio projects.
 */
#[Internal(reason: 'CMS block type — implementation detail')]
final readonly class ShowcaseBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'showcase';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'industry' => ['type' => 'string', 'description' => 'Filter by industry'],
                'limit' => ['type' => 'integer', 'default' => 6, 'minimum' => 1, 'maximum' => 50],
                'showTechnologies' => ['type' => 'boolean', 'default' => true],
                'featuredOnly' => ['type' => 'boolean', 'default' => false],
            ],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $limit = min(50, max(1, is_int($data['limit'] ?? null) ? $data['limit'] : 6));
        $showTech = ($data['showTechnologies'] ?? true) ? 'true' : 'false';
        $featuredOnly = ($data['featuredOnly'] ?? false) ? 'true' : 'false';

        $industryAttr = '';
        $industry = $data['industry'] ?? null;

        if (is_string($industry) && $industry !== '') {
            $escaped = htmlspecialchars($industry, ENT_QUOTES, 'UTF-8');
            $industryAttr = " data-industry=\"{$escaped}\"";
        }

        return '<div class="cms-showcase-grid"'
            . $industryAttr
            . " data-limit=\"{$limit}\""
            . " data-show-tech=\"{$showTech}\""
            . " data-featured-only=\"{$featuredOnly}\">"
            . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (isset($data['industry']) && !is_string($data['industry'])) {
            $errors[] = 'industry must be a string';
        }

        if (isset($data['limit'])) {
            if (!is_int($data['limit'])) {
                $errors[] = 'limit must be an integer';
            } elseif ($data['limit'] < 1 || $data['limit'] > 50) {
                $errors[] = 'limit must be between 1 and 50';
            }
        }

        return $errors;
    }
}
