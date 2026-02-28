<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_string;
use function preg_match;
use function strip_tags;

use const ENT_QUOTES;

#[Internal]
final readonly class EmbedBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'embed';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'format' => 'uri'],
                'type' => ['type' => 'string'],
                'html' => ['type' => 'string'],
            ],
            'required' => ['url'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $url = htmlspecialchars((string) ($data['url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $html = $data['html'] ?? null;

        // If custom HTML is provided, sanitize it (strip all tags except iframe)
        if (is_string($html) && $html !== '') {
            $sanitized = strip_tags($html, '<iframe>');

            return "<div class=\"embed\">$sanitized</div>";
        }

        // Default: render as a sandboxed iframe
        return "<div class=\"embed\"><iframe src=\"$url\" frameborder=\"0\" allowfullscreen sandbox=\"allow-scripts allow-same-origin\"></iframe></div>";
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['url']) || !is_string($data['url'])) {
            $errors[] = 'url is required and must be a string';
        } elseif (preg_match('#^https?://#i', $data['url']) !== 1) {
            $errors[] = 'url must be a valid HTTP or HTTPS URL';
        }

        return $errors;
    }
}
