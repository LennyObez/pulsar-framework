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
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

#[Internal]
final readonly class VideoBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'video';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'src' => ['type' => 'string'],
                'poster' => ['type' => 'string'],
                'caption' => ['type' => 'string'],
            ],
            'required' => ['src'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $rawSrcValue */
        $rawSrcValue = $data['src'] ?? null;
        $src = htmlspecialchars(is_string($rawSrcValue) ? $rawSrcValue : '', ENT_QUOTES, 'UTF-8');
        /** @var mixed $poster */
        $poster = $data['poster'] ?? null;
        /** @var mixed $caption */
        $caption = $data['caption'] ?? null;

        $posterAttr = '';

        if (is_string($poster) && $poster !== '') {
            $posterAttr = ' poster="' . htmlspecialchars($poster, ENT_QUOTES, 'UTF-8') . '"';
        }

        $anchor = isset($data['anchor']) && is_string($data['anchor']) ? ' id="' . htmlspecialchars($data['anchor'], ENT_QUOTES, 'UTF-8') . '"' : '';
        $className = isset($data['className']) && is_string($data['className']) ? ' ' . htmlspecialchars($data['className'], ENT_QUOTES, 'UTF-8') : '';

        $html = "<figure class=\"video-block$className\"$anchor><video controls src=\"$src\"$posterAttr></video>";

        if (is_string($caption) && $caption !== '') {
            $html .= '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</figcaption>';
        }

        $html .= '</figure>';

        // VideoObject structured data for SEO
        $rawSrc = is_string($rawSrcValue) ? $rawSrcValue : '';

        if ($rawSrc !== '') {
            $structuredData = [
                '@context' => 'https://schema.org',
                '@type' => 'VideoObject',
                'contentUrl' => $rawSrc,
            ];

            if (is_string($caption) && $caption !== '') {
                $structuredData['name'] = $caption;
                $structuredData['description'] = $caption;
            }

            if (is_string($poster) && $poster !== '') {
                $structuredData['thumbnailUrl'] = $poster;
            }

            $html .= '<script type="application/ld+json">';
            $html .= json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $html .= '</script>';
        }

        return $html;
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['src']) || !is_string($data['src'])) {
            $errors[] = 'src is required and must be a string';
        }

        return $errors;
    }
}
