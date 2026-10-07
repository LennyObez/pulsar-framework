<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Media\Watermark\WatermarkPosition;
use Pulsar\Support\Coerce;

use function max;
use function min;

/**
 * Configuration for image watermarking.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php; consumed
 *            by image processing jobs that produce watermarked variants.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WatermarkConfig
{
    /**
     * @param bool $enabled Whether watermarking is active
     * @param string|null $imagePath Absolute path to watermark image file
     * @param string|null $text Text watermark content (used when imagePath is null)
     * @param WatermarkPosition $position Watermark placement on the 9-point grid
     * @param int $opacity Opacity percentage (0 = fully transparent, 100 = fully opaque)
     * @param int $scale Scale as percentage of the target image's shortest dimension
     * @param int $margin Margin in pixels from the nearest edge
     * @param string $fontPath Path to TTF font file for text watermarks
     * @param int $fontSize Font size in points for text watermarks
     * @param string $fontColor Hex color for text watermarks (e.g., "#FFFFFF")
     * @param array<string, bool> $perVariant Per-variant override: variant name => apply watermark
     */
    public function __construct(
        public bool $enabled = false,
        public ?string $imagePath = null,
        public ?string $text = null,
        public WatermarkPosition $position = WatermarkPosition::BottomRight,
        public int $opacity = 50,
        public int $scale = 20,
        public int $margin = 10,
        public string $fontPath = '',
        public int $fontSize = 24,
        public string $fontColor = '#FFFFFF',
        public array $perVariant = [],
    ) {}

    /**
     * @param array{
     *     enabled?: bool,
     *     image_path?: string|null,
     *     text?: string|null,
     *     position?: string,
     *     opacity?: int,
     *     scale?: int,
     *     margin?: int,
     *     font_path?: string,
     *     font_size?: int,
     *     font_color?: string,
     *     per_variant?: array<string, bool>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? false,
            imagePath: $data['image_path'] ?? null,
            text: $data['text'] ?? null,
            position: WatermarkPosition::tryFrom($data['position'] ?? '') ?? WatermarkPosition::BottomRight,
            opacity: min(100, max(0, $data['opacity'] ?? 50)),
            scale: min(100, max(1, $data['scale'] ?? 20)),
            margin: max(0, $data['margin'] ?? 10),
            fontPath: $data['font_path'] ?? '',
            fontSize: max(1, $data['font_size'] ?? 24),
            fontColor: $data['font_color'] ?? '#FFFFFF',
            perVariant: Coerce::mapOfBool($data['per_variant'] ?? null),
        );
    }

    /**
     * Whether watermarking should be applied to the given variant.
     */
    public function shouldApplyToVariant(string $variantName): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->perVariant === []) {
            return true;
        }

        return $this->perVariant[$variantName] ?? true;
    }
}
