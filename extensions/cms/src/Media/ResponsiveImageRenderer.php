<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use Pulsar\Api\Api;

use function array_filter;
use function array_merge;
use function array_values;
use function htmlspecialchars;
use function implode;
use function in_array;

use const ENT_QUOTES;

/**
 * Renders responsive <picture> elements with WebP/AVIF sources and srcset.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ResponsiveImageRenderer
{
    private const array MODERN_FORMATS = ['webp', 'avif'];

    private const array FORMAT_TO_MIME = [
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
    ];

    private const array BREAKPOINTS = [
        '(max-width: 640px)' => '100vw',
        '(max-width: 1024px)' => '50vw',
    ];

    /**
     * Render a responsive image element.
     *
     * @param list<ImageVariant> $variants    Available image variants
     * @param array<string, string> $attributes Extra HTML attributes
     */
    public function render(
        MediaAsset $asset,
        array $variants = [],
        array $attributes = [],
    ): string {
        if ($variants === [] || !$asset->isImage()) {
            return $this->renderSimpleImg($asset, $attributes);
        }

        return $this->renderPicture($asset, $variants, $attributes);
    }

    /**
     * @param array<string, string> $attributes
     */
    private function renderSimpleImg(MediaAsset $asset, array $attributes): string
    {
        $src = self::escape($asset->storagePath);
        $alt = self::escape($asset->altTextDefault ?? '');

        $attrs = $this->buildAttributes($attributes, [
            'src' => $src,
            'alt' => $alt,
            'loading' => $attributes['loading'] ?? 'lazy',
        ]);

        return "<img $attrs>";
    }

    /**
     * @param list<ImageVariant> $variants
     * @param array<string, string> $attributes
     */
    private function renderPicture(MediaAsset $asset, array $variants, array $attributes): string
    {
        $html = '<picture>';

        // Group variants by format for <source> elements: modern formats first
        $modernVariants = $this->filterByFormats($variants, self::MODERN_FORMATS);

        foreach ($this->groupByFormat($modernVariants) as $format => $formatVariants) {
            $mimeType = self::FORMAT_TO_MIME[$format] ?? 'image/' . $format;
            $srcset = $this->buildSrcset($formatVariants);
            $sizes = $this->buildSizes();
            $escapedType = self::escape($mimeType);
            $html .= "<source type=\"$escapedType\" srcset=\"$srcset\" sizes=\"$sizes\">";
        }

        // Original format srcset
        $originalFormat = self::mimeToFormat($asset->mimeType);
        $originalVariants = $this->filterByFormats($variants, [$originalFormat]);

        if ($originalVariants !== []) {
            $srcset = $this->buildSrcset($originalVariants);
            $sizes = $this->buildSizes();
            $escapedType = self::escape($asset->mimeType);
            $html .= "<source type=\"$escapedType\" srcset=\"$srcset\" sizes=\"$sizes\">";
        }

        // Fallback <img>
        $src = self::escape($asset->storagePath);
        $alt = self::escape($asset->altTextDefault ?? '');

        $imgAttrs = $this->buildAttributes($attributes, [
            'src' => $src,
            'alt' => $alt,
            'loading' => $attributes['loading'] ?? 'lazy',
        ]);

        if ($asset->width !== null) {
            $imgAttrs .= ' width="' . $asset->width . '"';
        }

        if ($asset->height !== null) {
            $imgAttrs .= ' height="' . $asset->height . '"';
        }

        $html .= "<img $imgAttrs>";
        $html .= '</picture>';

        return $html;
    }

    /**
     * @param list<ImageVariant> $variants
     */
    private function buildSrcset(array $variants): string
    {
        $entries = [];

        foreach ($variants as $variant) {
            $src = self::escape($variant->path);
            $entries[] = "$src {$variant->width}w";
        }

        return implode(', ', $entries);
    }

    private function buildSizes(): string
    {
        $parts = [];

        foreach (self::BREAKPOINTS as $query => $size) {
            $parts[] = "$query $size";
        }

        $parts[] = '33vw';

        return implode(', ', $parts);
    }

    /**
     * @param list<ImageVariant> $variants
     * @param list<string> $formats
     * @return list<ImageVariant>
     */
    private function filterByFormats(array $variants, array $formats): array
    {
        return array_values(array_filter(
            $variants,
            static fn(ImageVariant $v): bool => in_array($v->format, $formats, true),
        ));
    }

    /**
     * @param list<ImageVariant> $variants
     * @return array<string, list<ImageVariant>>
     */
    private function groupByFormat(array $variants): array
    {
        $groups = [];

        foreach ($variants as $variant) {
            $groups[$variant->format][] = $variant;
        }

        return $groups;
    }

    /**
     * @param array<string, string> $overrides
     * @param array<string, string> $defaults
     */
    private function buildAttributes(array $overrides, array $defaults): string
    {
        $merged = array_merge($defaults, $overrides);
        $parts = [];

        foreach ($merged as $key => $value) {
            $parts[] = self::escape($key) . '="' . self::escape((string) $value) . '"';
        }

        return implode(' ', $parts);
    }

    private static function mimeToFormat(string $mimeType): string
    {
        return match ($mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/gif' => 'gif',
            default => 'jpeg',
        };
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
