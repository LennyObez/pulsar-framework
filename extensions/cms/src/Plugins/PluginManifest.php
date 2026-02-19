<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

use Pulsar\Api\Api;

/**
 * Parsed plugin manifest (plugin.json) with all declared metadata.
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
            capabilities: (array) ($data['capabilities'] ?? []),
            dependencies: (array) ($data['dependencies'] ?? []),
            entryPoint: isset($data['entry_point']) ? (string) $data['entry_point'] : null,
            settings: (array) ($data['settings'] ?? []),
        );
    }
}
