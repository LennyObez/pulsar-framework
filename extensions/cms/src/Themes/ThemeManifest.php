<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use Pulsar\Api\Api;

use function is_array;
use function is_scalar;
use function is_string;

/**
 * Parsed theme manifest (theme.json) with all declared metadata.
 *
 * @psalm-api Public DTO produced from theme.json parsing; consumed by
 *            ThemeManager and ThemeManifestValidator.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ThemeManifest
{
    /**
     * @param string $slug URL-safe theme identifier
     * @param string $displayName Human-readable theme name
     * @param string $version SemVer version string
     * @param string|null $description Theme description
     * @param string|null $authorName Theme author name
     * @param string|null $authorUrl Theme author URL
     * @param string|null $license SPDX license identifier
     * @param string|null $pulsarVersionConstraint Required Pulsar framework version constraint
     * @param string|null $parentTheme Slug of the parent theme for inheritance
     * @param list<string> $regions Template regions declared by this theme
     * @param list<string> $supportedContentTypes Content types this theme provides templates for
     * @param array<string, mixed> $settings Theme-specific configurable settings
     * @param array<string, string> $assets Asset path mapping (logical name => relative path)
     */
    public function __construct(
        public string $slug,
        public string $displayName,
        public string $version,
        public ?string $description = null,
        public ?string $authorName = null,
        public ?string $authorUrl = null,
        public ?string $license = null,
        public ?string $pulsarVersionConstraint = null,
        public ?string $parentTheme = null,
        public array $regions = [],
        public array $supportedContentTypes = [],
        public array $settings = [],
        public array $assets = [],
    ) {}

    /**
     * @param array{
     *     slug?: string,
     *     display_name?: string,
     *     name?: string,
     *     version?: string,
     *     description?: string|null,
     *     author_name?: string|null,
     *     author_url?: string|null,
     *     license?: string|null,
     *     pulsar_version?: string|null,
     *     parent_theme?: string|null,
     *     regions?: list<string>,
     *     supported_content_types?: list<string>,
     *     settings?: array<string, mixed>,
     *     assets?: array<string, string>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            slug: $data['slug'] ?? '',
            displayName: $data['display_name'] ?? $data['name'] ?? '',
            version: $data['version'] ?? '0.0.0',
            description: $data['description'] ?? null,
            authorName: $data['author_name'] ?? null,
            authorUrl: $data['author_url'] ?? null,
            license: $data['license'] ?? null,
            pulsarVersionConstraint: $data['pulsar_version'] ?? null,
            parentTheme: $data['parent_theme'] ?? null,
            regions: self::toStringList($data['regions'] ?? []),
            supportedContentTypes: self::toStringList($data['supported_content_types'] ?? []),
            settings: self::toStringKeyedArray($data['settings'] ?? []),
            assets: self::toStringMap($data['assets'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private static function toStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        /** @var mixed $item */
        foreach ($value as $item) {
            $result[] = is_string($item) ? $item : (is_scalar($item) ? (string) $item : '');
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private static function toStringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        /** @var mixed $item */
        foreach ($value as $key => $item) {
            $strKey = is_string($key) ? $key : (string) $key;
            $result[$strKey] = is_string($item) ? $item : (is_scalar($item) ? (string) $item : '');
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function toStringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        /** @var mixed $item */
        foreach ($value as $key => $item) {
            $result = [...$result, (string) $key => $item];
        }

        return $result;
    }
}
