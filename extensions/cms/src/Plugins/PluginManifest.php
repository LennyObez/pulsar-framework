<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

use Pulsar\Api\Api;

use function is_array;
use function is_scalar;
use function is_string;

/**
 * Parsed plugin manifest (plugin.json) with all declared metadata.
 *
 * @psalm-api Public DTO produced from plugin.json parsing; consumed by
 *            CmsPluginManager and PluginManifestValidator.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PluginManifest
{
    /**
     * @param string $slug URL-safe plugin identifier
     * @param string $displayName Human-readable plugin name
     * @param string $version SemVer version string
     * @param string|null $description Plugin description
     * @param string|null $authorName Plugin author name
     * @param string|null $authorUrl Plugin author URL
     * @param string|null $license SPDX license identifier
     * @param string|null $pulsarVersionConstraint Required Pulsar framework version constraint
     * @param list<string> $capabilities Plugin capabilities
     * @param array<string, string> $dependencies Plugin slug => version constraint
     * @param string|null $entryPoint Class name implementing CmsPluginInterface
     * @param array<string, mixed> $settings Plugin-specific configurable settings
     * @param array<string, array<string, string>>|null $autoload PSR-4 autoload mappings
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
        public array $capabilities = [],
        public array $dependencies = [],
        public ?string $entryPoint = null,
        public array $settings = [],
        public ?array $autoload = null,
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
     *     capabilities?: list<string>,
     *     dependencies?: array<string, string>,
     *     entry_point?: string|null,
     *     settings?: array<string, mixed>,
     *     autoload?: array<string, array<string, string>>|null,
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
            capabilities: self::toStringList($data['capabilities'] ?? []),
            dependencies: self::toStringMap($data['dependencies'] ?? []),
            entryPoint: $data['entry_point'] ?? null,
            settings: self::toStringKeyedArray($data['settings'] ?? []),
            autoload: isset($data['autoload']) ? self::toStringKeyedStringMap($data['autoload']) : null,
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
            $strKey = is_string($key) ? $key : (string) $key;
            $result = [...$result, $strKey => $item];
        }

        return $result;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function toStringKeyedStringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        /** @var mixed $item */
        foreach ($value as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $inner = [];

            /** @var mixed $v */
            foreach ($item as $k => $v) {
                $innerKey = is_string($k) ? $k : (string) $k;
                $inner[$innerKey] = is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
            }

            $outerKey = is_string($key) ? $key : (string) $key;
            $result[$outerKey] = $inner;
        }

        return $result;
    }
}
