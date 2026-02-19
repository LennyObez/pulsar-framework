<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\LiveCss;

use Pulsar\Api\Api;

/**
 * Configuration for the Live CSS editor subsystem.
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            maxCssLength: (int) ($data['max_css_length'] ?? 100_000),
            allowExternalFonts: (bool) ($data['allow_external_fonts'] ?? false),
        );
    }
}
