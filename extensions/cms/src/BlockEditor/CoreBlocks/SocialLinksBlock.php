<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function array_key_exists;
use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class SocialLinksBlock implements BlockTypeInterface
{
    private const array PLATFORM_LABELS = [
        'facebook' => 'Facebook',
        'twitter' => 'Twitter',
        'linkedin' => 'LinkedIn',
        'instagram' => 'Instagram',
        'youtube' => 'YouTube',
        'github' => 'GitHub',
        'tiktok' => 'TikTok',
        'mastodon' => 'Mastodon',
        'bluesky' => 'Bluesky',
        'email' => 'Email',
    ];

    private const array VALID_STYLES = ['icons', 'text', 'both'];

    private const array VALID_SIZES = ['sm', 'md', 'lg'];

    #[Override]
    public function type(): string
    {
        return 'social-links';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'links' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'platform' => ['type' => 'string'],
                            'url' => ['type' => 'string', 'format' => 'uri'],
                        ],
                        'required' => ['platform', 'url'],
                    ],
                ],
                'style' => ['type' => 'string', 'enum' => self::VALID_STYLES],
                'size' => ['type' => 'string', 'enum' => self::VALID_SIZES],
            ],
            'required' => ['links'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<mixed> $links */
        $links = $data['links'] ?? [];
        /** @var mixed $rawStyle */
        $rawStyle = $data['style'] ?? null;
        /** @var mixed $rawSize */
        $rawSize = $data['size'] ?? null;
        $style = is_string($rawStyle) ? $rawStyle : 'both';
        $size = is_string($rawSize) ? $rawSize : 'md';

        $html = "<nav class=\"social-links social-links--$style social-links--$size\" aria-label=\"Social media links\">";

        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }

            /** @var mixed $rawPlatform */
            $rawPlatform = $link['platform'] ?? null;
            /** @var mixed $rawUrl */
            $rawUrl = $link['url'] ?? null;
            $platform = is_string($rawPlatform) ? $rawPlatform : '';
            $url = htmlspecialchars(is_string($rawUrl) ? $rawUrl : '', ENT_QUOTES, 'UTF-8');
            $escapedPlatform = htmlspecialchars($platform, ENT_QUOTES, 'UTF-8');
            $label = self::PLATFORM_LABELS[$platform] ?? $escapedPlatform;

            $html .= "<a href=\"$url\" class=\"social-link social-link--$escapedPlatform\" rel=\"noopener noreferrer\" target=\"_blank\">$label</a>";
        }

        return $html . '</nav>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['links']) || !is_array($data['links'])) {
            $errors[] = 'links is required and must be an array';

            return $errors;
        }

        foreach ($data['links'] as $index => $link) {
            if (!is_array($link)) {
                $errors[] = "links[$index] must be an object";

                continue;
            }

            if (!isset($link['platform']) || !is_string($link['platform'])) {
                $errors[] = "links[$index].platform is required and must be a string";
            } elseif (!array_key_exists($link['platform'], self::PLATFORM_LABELS)) {
                $errors[] = "links[$index].platform '{$link['platform']}' is not a known platform";
            }

            if (!isset($link['url']) || !is_string($link['url'])) {
                $errors[] = "links[$index].url is required and must be a string";
            }
        }

        if (isset($data['style']) && !in_array($data['style'], self::VALID_STYLES, true)) {
            $errors[] = 'style must be one of: icons, text, both';
        }

        if (isset($data['size']) && !in_array($data['size'], self::VALID_SIZES, true)) {
            $errors[] = 'size must be one of: sm, md, lg';
        }

        return $errors;
    }
}
