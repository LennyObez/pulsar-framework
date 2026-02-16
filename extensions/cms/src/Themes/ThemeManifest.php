<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use Pulsar\Api\Api;

use function is_array;
use function is_scalar;
use function is_string;

/**
 * Parsed theme manifest (theme.json) with all declared metadata.
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            slug: is_string($data['slug'] ?? null) ? $data['slug'] : '',
            displayName: is_string($data['display_name'] ?? null) ? $data['display_name'] : (is_string($data['name'] ?? null) ? $data['name'] : ''),
            version: is_string($data['version'] ?? null) ? $data['version'] : '0.0.0',
            description: isset($data['description']) ? (is_string($data['description']) ? $data['description'] : '') : null,
            authorName: isset($data['author_name']) ? (is_string($data['author_name']) ? $data['author_name'] : '') : null,
            authorUrl: isset($data['author_url']) ? (is_string($data['author_url']) ? $data['author_url'] : '') : null,
            license: isset($data['license']) ? (is_string($data['license']) ? $data['license'] : '') : null,
            pulsarVersionConstraint: isset($data['pulsar_version']) ? (is_string($data['pulsar_version']) ? $data['pulsar_version'] : '') : null,
            parentTheme: isset($data['parent_theme']) ? (is_string($data['parent_theme']) ? $data['parent_theme'] : '') : null,
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

        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }
}
