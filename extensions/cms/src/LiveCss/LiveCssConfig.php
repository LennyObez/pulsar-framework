<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\LiveCss;

use Pulsar\Api\Api;

/**
 * Configuration for the Live CSS editor subsystem.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php and
 *            consumed by LiveCssService and the admin editor.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class LiveCssConfig
{
    /**
     * @param bool $enabled Whether the Live CSS editor is enabled
     * @param int $maxCssLength Maximum allowed CSS content length in characters
     * @param bool $allowExternalFonts Whether @font-face with external URLs is permitted
     */
    public function __construct(
        public bool $enabled = true,
        public int $maxCssLength = 100_000,
        public bool $allowExternalFonts = false,
    ) {}

    /**
     * @param array{
     *     enabled?: bool,
     *     max_css_length?: int,
     *     allow_external_fonts?: bool,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? true,
            maxCssLength: $data['max_css_length'] ?? 100_000,
            allowExternalFonts: $data['allow_external_fonts'] ?? false,
        );
    }
}
