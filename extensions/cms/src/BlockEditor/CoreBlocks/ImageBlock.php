<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\ResponsiveImageRenderer;

use function htmlspecialchars;
use function in_array;
use function is_string;

use const ENT_QUOTES;

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
        $src = htmlspecialchars((string) ($data['src'] ?? ''), ENT_QUOTES, 'UTF-8');
        $alt = htmlspecialchars((string) ($data['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
        $caption = $data['caption'] ?? null;
        $alignment = $data['alignment'] ?? null;

        $figureStyle = '';

        if (is_string($alignment) && in_array($alignment, self::VALID_ALIGNMENTS, true)) {
            $figureStyle = " style=\"text-align:{$alignment}\"";
        }

        // Use responsive renderer if available and a MediaAsset is provided
        /** @var MediaAsset|null $asset */
        $asset = $data['_asset'] ?? null;
        /** @var list<\Pulsar\Extension\Cms\Media\ImageVariant> $variants */
        $variants = $data['_variants'] ?? [];

        if ($this->responsiveRenderer !== null && $asset instanceof MediaAsset && $variants !== []) {
            $imgHtml = $this->responsiveRenderer->render($asset, $variants);
        } else {
            $imgHtml = "<img src=\"{$src}\" alt=\"{$alt}\" loading=\"lazy\">";
        }

        $html = "<figure{$figureStyle}>{$imgHtml}";

        if (is_string($caption) && $caption !== '') {
            $html .= '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</figcaption>';
        }

        return $html . '</figure>';
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
