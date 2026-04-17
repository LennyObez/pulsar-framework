<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_string;
use function json_encode;

use const ENT_QUOTES;
use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

#[Internal]
final readonly class CtaBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'cta';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'url' => ['type' => 'string', 'format' => 'uri'],
                'style' => ['type' => 'string'],
                'anchor' => ['type' => 'string'],
                'className' => ['type' => 'string'],
            ],
            'required' => ['text', 'url'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $rawText = $data['text'] ?? null;
        $rawUrl = $data['url'] ?? null;
        $textStr = is_string($rawText) ? $rawText : '';
        $urlStr = is_string($rawUrl) ? $rawUrl : '';
        $text = htmlspecialchars($textStr, ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars($urlStr, ENT_QUOTES, 'UTF-8');
        /** @var mixed $style */
        $style = $data['style'] ?? null;

        $cssClass = 'cta-button';

        if (is_string($style) && $style !== '') {
            $cssClass .= ' cta-button--' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8');
        }

        if (isset($data['className']) && is_string($data['className'])) {
            $cssClass .= ' ' . htmlspecialchars($data['className'], ENT_QUOTES, 'UTF-8');
        }

        $anchor = isset($data['anchor']) && is_string($data['anchor']) ? ' id="' . htmlspecialchars($data['anchor'], ENT_QUOTES, 'UTF-8') . '"' : '';

        $rawUrl = $urlStr;
        $rawText = $textStr;
        $jsonLd = '';

        if ($rawUrl !== '') {
            // json_encode with JSON_HEX_TAG escapes <> inside JSON, preventing XSS in script context
            $jsonLd = '<script type="application/ld+json">' . json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'potentialAction' => [
                    '@type' => 'ViewAction',
                    'target' => $rawUrl,
                    'name' => $rawText,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';
        }

        return "<a href=\"$url\" class=\"$cssClass\"$anchor>$text</a>$jsonLd";
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['text']) || !is_string($data['text'])) {
            $errors[] = 'text is required and must be a string';
        }

        if (!isset($data['url']) || !is_string($data['url'])) {
            $errors[] = 'url is required and must be a string';
        }

        return $errors;
    }
}
