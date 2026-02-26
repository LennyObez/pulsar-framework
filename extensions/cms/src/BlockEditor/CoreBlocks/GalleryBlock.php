<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_array;
use function is_int;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class GalleryBlock implements BlockTypeInterface
{
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
        /** @var list<array{src: string, alt: string, caption?: string}> $images */
        $images = $data['images'] ?? [];
        $columns = (int) ($data['columns'] ?? 3);

        if ($columns < 1) {
            $columns = 3;
        }

        $html = "<div class=\"gallery\" style=\"display:grid;grid-template-columns:repeat({$columns},1fr);gap:1rem\">";

        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }

            $src = htmlspecialchars((string) ($image['src'] ?? ''), ENT_QUOTES, 'UTF-8');
            $alt = htmlspecialchars((string) ($image['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
            $caption = $image['caption'] ?? null;

            $html .= "<figure><img src=\"{$src}\" alt=\"{$alt}\">";

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
                $errors[] = "images[{$index}] must be an object";

                continue;
            }

            if (!isset($image['src']) || !is_string($image['src'])) {
                $errors[] = "images[{$index}].src is required and must be a string";
            }

            if (!isset($image['alt']) || !is_string($image['alt'])) {
                $errors[] = "images[{$index}].alt is required and must be a string";
            }
        }

        if (isset($data['columns']) && (!is_int($data['columns']) || $data['columns'] < 1)) {
            $errors[] = 'columns must be a positive integer';
        }

        return $errors;
    }
}
