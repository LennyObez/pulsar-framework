<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\LiveCss;

use Pulsar\Api\Api;

/**
 * Describes a single editable CSS custom property (design token) exposed by a theme.
 */
#[Api(since: '1.0.0')]
final readonly class ThemeToken
{
    /**
     * @param string $name CSS custom property name (e.g. "--color-primary")
     * @param string $type Token type: "color", "font", "size", or "string"
     * @param string $default Default value when no override is set
     * @param string $label Human-readable label for the editor UI
     * @param string $group Logical group for editor organization (e.g. "Colors", "Typography")
     * @param array<string, mixed> $constraints Validation constraints (min, max, allowed_values)
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $default,
        public string $label,
        public string $group,
        public array $constraints = [],
    ) {}
}
