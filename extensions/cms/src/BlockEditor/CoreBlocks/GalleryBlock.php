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
use function is_array;
use function is_int;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class GalleryBlock implements BlockTypeInterface
{
    public function __construct(
        private ?ResponsiveImageRenderer $responsiveRenderer = null,
    ) {}

    #[Override]
    public function type(): string
    {
        return 'gallery';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'images' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'src' => ['type' => 'string'],
                            'alt' => ['type' => 'string'],
                            'caption' => ['type' => 'string'],
                        ],
                        'required' => ['src', 'alt'],
                    ],
                ],
                'columns' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['images'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<mixed> $images */
        $images = $data['images'] ?? [];
        $columns = is_int($data['columns'] ?? null) ? $data['columns'] : 3;

        if ($columns < 1) {
            $columns = 3;
        }

        $html = "<div class=\"gallery\" data-gallery style=\"display:grid;grid-template-columns:repeat($columns,1fr);gap:1rem\">";

        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }

            $src = htmlspecialchars(is_string($image['src'] ?? null) ? $image['src'] : '', ENT_QUOTES, 'UTF-8');
            $alt = htmlspecialchars(is_string($image['alt'] ?? null) ? $image['alt'] : '', ENT_QUOTES, 'UTF-8');
            $caption = $image['caption'] ?? null;
            $category = $image['category'] ?? null;

            /** @var MediaAsset|null $asset */
            $asset = $image['_asset'] ?? null;
            /** @var list<ImageVariant> $variants */
            $variants = $image['_variants'] ?? [];

            $categoryAttr = is_string($category) ? ' data-category="' . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . '"' : '';

            $html .= "<figure$categoryAttr>";

            if ($this->responsiveRenderer !== null && $asset instanceof MediaAsset && $variants !== []) {
                $html .= $this->responsiveRenderer->render($asset, $variants);
            } else {
                $html .= "<img src=\"$src\" alt=\"$alt\" loading=\"lazy\">";
            }

            if (is_string($caption) && $caption !== '') {
                $html .= '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</figcaption>';
            }

            $html .= '</figure>';
        }

        return $html . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['images']) || !is_array($data['images'])) {
            $errors[] = 'images is required and must be an array';

            return $errors;
        }

        foreach ($data['images'] as $index => $image) {
            if (!is_array($image)) {
                $errors[] = "images[$index] must be an object";

                continue;
            }

            if (!isset($image['src']) || !is_string($image['src'])) {
                $errors[] = "images[$index].src is required and must be a string";
            }

            if (!isset($image['alt']) || !is_string($image['alt'])) {
                $errors[] = "images[$index].alt is required and must be a string";
            }
        }

        if (isset($data['columns']) && (!is_int($data['columns']) || $data['columns'] < 1)) {
            $errors[] = 'columns must be a positive integer';
        }

        return $errors;
    }
}
