<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\FullSiteEditor;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Global styles configuration for full-site editing.
 *
 * Stores site-wide CSS custom properties (design tokens) that apply
 * across all template parts and content. Changes propagate immediately
 * to all pages without per-page edits.
 *
 * @psalm-api Public DTO persisted via GlobalStylesRepositoryInterface;
 *            consumed by full-site editor and theme rendering.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class GlobalStylesConfig
{
    /**
     * @param array<string, string> $colors Named color tokens (e.g., 'primary' => '#1e40af')
     * @param array<string, string> $typography Font-related tokens (e.g., 'heading-font' => '"Inter", sans-serif')
     * @param array<string, string> $spacing Spacing tokens in rem/px (e.g., 'section-gap' => '4rem')
     * @param array<string, string> $borders Border tokens (e.g., 'radius' => '0.5rem')
     * @param string $customCss Additional raw CSS appended to the token output
     */
    public function __construct(
        public array $colors = [],
        public array $typography = [],
        public array $spacing = [],
        public array $borders = [],
        public string $customCss = '',
    ) {}

    /**
     * @param array{
     *     colors?: array<string, string>,
     *     typography?: array<string, string>,
     *     spacing?: array<string, string>,
     *     borders?: array<string, string>,
     *     custom_css?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            colors: $data['colors'] ?? [],
            typography: $data['typography'] ?? [],
            spacing: $data['spacing'] ?? [],
            borders: $data['borders'] ?? [],
            customCss: $data['custom_css'] ?? '',
        );
    }

    /**
     * Compile all tokens into a CSS :root block.
     */
    #[NoDiscard]
    public function toCss(): string
    {
        $vars = [];

        foreach ($this->colors as $name => $value) {
            $vars[] = "  --color-{$name}: {$value};";
        }

        foreach ($this->typography as $name => $value) {
            $vars[] = "  --font-{$name}: {$value};";
        }

        foreach ($this->spacing as $name => $value) {
            $vars[] = "  --space-{$name}: {$value};";
        }

        foreach ($this->borders as $name => $value) {
            $vars[] = "  --border-{$name}: {$value};";
        }

        $rootBlock = $vars !== []
            ? ":root {\n" . implode("\n", $vars) . "\n}\n"
            : '';

        if ($this->customCss !== '') {
            $rootBlock .= "\n" . $this->customCss . "\n";
        }

        return $rootBlock;
    }

}
