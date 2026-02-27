<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\LiveCss;

use Pulsar\Api\Api;

use function is_bool;
use function is_int;

/**
 * Configuration for the Live CSS editor subsystem.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php and
 *            consumed by LiveCssService and the admin editor.
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
            enabled: is_bool($data['enabled'] ?? null) ? $data['enabled'] : true,
            maxCssLength: is_int($data['max_css_length'] ?? null) ? $data['max_css_length'] : 100_000,
            allowExternalFonts: is_bool($data['allow_external_fonts'] ?? null) ? $data['allow_external_fonts'] : false,
        );
    }
}
