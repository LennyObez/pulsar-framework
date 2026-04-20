<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;
use Pulsar\Extension\Cms\Media\ImageVariant;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\ResponsiveImageRenderer;

use function htmlspecialchars;
use function in_array;
use function is_string;
use function json_encode;

use const ENT_QUOTES;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

#[Internal]
final readonly class ImageBlock implements BlockTypeInterface
{
    private const array VALID_ALIGNMENTS = ['left', 'center', 'right'];

    public function __construct(
        private ?ResponsiveImageRenderer $responsiveRenderer = null,
    ) {}

    #[Override]
    public function type(): string
    {
        return 'image';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'src' => ['type' => 'string'],
                'alt' => ['type' => 'string'],
                'caption' => ['type' => 'string'],
                'alignment' => ['type' => 'string', 'enum' => self::VALID_ALIGNMENTS],
            ],
            'required' => ['src', 'alt'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $rawSrc */
        $rawSrc = $data['src'] ?? null;
        /** @var mixed $rawAlt */
        $rawAlt = $data['alt'] ?? null;
        $src = htmlspecialchars(is_string($rawSrc) ? $rawSrc : '', ENT_QUOTES, 'UTF-8');
        $alt = htmlspecialchars(is_string($rawAlt) ? $rawAlt : '', ENT_QUOTES, 'UTF-8');
        /** @var mixed $caption */
        $caption = $data['caption'] ?? null;
        /** @var mixed $alignment */
        $alignment = $data['alignment'] ?? null;

        $figureStyle = '';

        if (is_string($alignment) && in_array($alignment, self::VALID_ALIGNMENTS, true)) {
            $figureStyle = " style=\"text-align:$alignment\"";
        }

        // Use responsive renderer if available and a MediaAsset is provided
        /** @var MediaAsset|null $asset */
        $asset = $data['_asset'] ?? null;
        /** @var list<ImageVariant> $variants */
        $variants = $data['_variants'] ?? [];

        if ($this->responsiveRenderer !== null && $asset instanceof MediaAsset && $variants !== []) {
            $imgHtml = $this->responsiveRenderer->render($asset, $variants);
        } else {
            $imgHtml = "<img src=\"$src\" alt=\"$alt\" loading=\"lazy\">";
        }

        $anchor = isset($data['anchor']) && is_string($data['anchor']) ? ' id="' . htmlspecialchars($data['anchor'], ENT_QUOTES, 'UTF-8') . '"' : '';
        $className = isset($data['className']) && is_string($data['className']) ? ' ' . htmlspecialchars($data['className'], ENT_QUOTES, 'UTF-8') : '';

        if ($className !== '') {
            $figureStyle .= ($figureStyle !== '' ? '' : '') . " class=\"image-block$className\"";
        }

        $html = "<figure$figureStyle$anchor>$imgHtml";

        if (is_string($caption) && $caption !== '') {
            $html .= '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</figcaption>';
        }

        $html .= '</figure>';

        // ImageObject structured data for SEO
        $rawSrc = is_string($data['src'] ?? null) ? $data['src'] : '';
        $rawAlt = is_string($data['alt'] ?? null) ? $data['alt'] : '';

        if ($rawSrc !== '') {
            $structuredData = [
                '@context' => 'https://schema.org',
                '@type' => 'ImageObject',
                'contentUrl' => $rawSrc,
                'description' => $rawAlt,
            ];

            if (is_string($caption) && $caption !== '') {
                $structuredData['caption'] = $caption;
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

        if (!isset($data['alt']) || !is_string($data['alt'])) {
            $errors[] = 'alt is required and must be a string';
        }

        if (isset($data['alignment']) && !in_array($data['alignment'], self::VALID_ALIGNMENTS, true)) {
            $errors[] = 'alignment must be one of: left, center, right';
        }

        return $errors;
    }
}
