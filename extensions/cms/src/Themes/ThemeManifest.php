<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use Pulsar\Api\Api;

use function is_array;

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
            slug: (string) ($data['slug'] ?? ''),
            displayName: (string) ($data['display_name'] ?? $data['name'] ?? ''),
            version: (string) ($data['version'] ?? '0.0.0'),
            description: isset($data['description']) ? (string) $data['description'] : null,
            authorName: isset($data['author_name']) ? (string) $data['author_name'] : null,
            authorUrl: isset($data['author_url']) ? (string) $data['author_url'] : null,
            license: isset($data['license']) ? (string) $data['license'] : null,
            pulsarVersionConstraint: isset($data['pulsar_version']) ? (string) $data['pulsar_version'] : null,
            parentTheme: isset($data['parent_theme']) ? (string) $data['parent_theme'] : null,
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
            $result[] = (string) $item;
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
            $result[(string) $key] = (string) $item;
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
            $result[(string) $key] = $item;
        }

        return $result;
    }
}
