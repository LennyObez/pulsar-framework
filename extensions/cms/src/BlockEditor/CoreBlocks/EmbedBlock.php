<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_string;
use function preg_match;
use function sprintf;

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
        /** @var mixed $rawUrl */
        $rawUrl = $data['url'] ?? null;
        $url = htmlspecialchars(is_string($rawUrl) ? $rawUrl : '', ENT_QUOTES, 'UTF-8');
        /** @var mixed $html */
        $html = $data['html'] ?? null;

        // If custom HTML is provided, render in a fully sandboxed srcdoc iframe
        // Do NOT combine allow-scripts + allow-same-origin: this defeats the sandbox
        if (is_string($html) && $html !== '') {
            $escapedHtml = htmlspecialchars($html, ENT_QUOTES, 'UTF-8');

            return sprintf(
                '<div class="embed"><iframe srcdoc="%s" frameborder="0" sandbox="allow-scripts" title="Embed preview"></iframe></div>',
                $escapedHtml,
            );
        }

        $anchor = isset($data['anchor']) && is_string($data['anchor']) ? ' id="' . htmlspecialchars($data['anchor'], ENT_QUOTES, 'UTF-8') . '"' : '';
        $className = isset($data['className']) && is_string($data['className']) ? ' ' . htmlspecialchars($data['className'], ENT_QUOTES, 'UTF-8') : '';

        // Default: render URL as a sandboxed iframe (allow-scripts for functionality, no allow-same-origin)
        return sprintf(
            '<div class="embed%s"%s><iframe src="%s" frameborder="0" allowfullscreen sandbox="allow-scripts allow-popups" title="Embedded content"></iframe></div>',
            $className,
            $anchor,
            $url,
        );
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
