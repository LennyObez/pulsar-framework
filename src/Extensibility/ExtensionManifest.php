<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use JsonException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extensibility\Exception\ManifestException;
use Pulsar\Extensibility\Manifest\ProvidesConfig;
use Pulsar\Extensibility\Manifest\PulsarVersionConfig;
use Pulsar\Extensibility\Manifest\RequiresConfig;

use function count;
use function dirname;
use function is_string;
use function json_validate;

/**
 * Readonly DTO representing an extension's pulsar.json manifest.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ExtensionManifest
{
    public function __construct(
        public string $name,
        public string $version,
        public string $extensionClass,
        public string $path,
        public string $description = '',
        public PulsarVersionConfig $pulsar = new PulsarVersionConfig('0.0.0'),
        public ProvidesConfig $provides = new ProvidesConfig(),
        public RequiresConfig $requires = new RequiresConfig(),
        public TrustTier $requestedTrustTier = TrustTier::Community,
    ) {}

    /**
     * Load manifest from a file path.
     *
     * @throws ManifestException If the file cannot be read or parsed
     */
    #[NoDiscard]
    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw ManifestException::fileNotFound($path);
        }

        $content = file_get_contents($path)
            ?: throw ManifestException::fileNotFound($path);

        if (!json_validate($content)) {
            throw ManifestException::invalidJson($path, 'Invalid JSON');
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ManifestException::invalidJson($path, $e->getMessage());
        }

        return self::fromArray($data, dirname($path));
    }

    /**
     * Create manifest from array data.
     *
     * @param array{
     *     name?: string,
     *     version?: string,
     *     extension_class?: string,
     *     description?: string,
     *     pulsar?: array{min_version?: string, max_version?: string},
     *     provides?: array<string, mixed>,
     *     requires?: array<string, string>,
     *     trust_tier?: string,
     * } $data
     * @throws ManifestException If required fields are missing or invalid
     */
    #[NoDiscard]
    public static function fromArray(array $data, string $basePath = ''): self
    {
        if (!isset($data['name']) || !is_string($data['name'])) {
            throw ManifestException::missingField('name', $basePath);
        }

        if (!isset($data['version']) || !is_string($data['version'])) {
            throw ManifestException::missingField('version', $basePath);
        }

        if (!isset($data['extension_class']) || !is_string($data['extension_class'])) {
            throw ManifestException::missingField('extension_class', $basePath);
        }

        if (!preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/', $data['version'])) {
            throw ManifestException::invalidVersion($data['version'], $basePath);
        }

        return new self(
            name: $data['name'],
            version: $data['version'],
            extensionClass: $data['extension_class'],
            path: $basePath,
            description: $data['description'] ?? '',
            pulsar: PulsarVersionConfig::fromArray($data['pulsar'] ?? []),
            provides: ProvidesConfig::fromArray($data['provides'] ?? []),
            requires: RequiresConfig::fromArray($data['requires'] ?? []),
            requestedTrustTier: TrustTier::tryFrom($data['trust_tier'] ?? '') ?? TrustTier::Community,
        );
    }

    /**
     * Get the short name (without vendor prefix).
     */
    public function shortName(): string
    {
        $parts = explode('/', $this->name);
        return end($parts) ?: $this->name;
    }

    /**
     * Get the vendor name.
     */
    public function vendor(): string
    {
        $parts = explode('/', $this->name);
        return count($parts) > 1 ? $parts[0] : '';
    }

    /**
     * Check if the manifest is compatible with the current Pulsar version.
     */
    public function isCompatibleWithCurrentVersion(): bool
    {
        return $this->pulsar->isSatisfiedByCurrent();
    }

    /**
     * Check if this extension has dependencies on other extensions.
     */
    public function hasDependencies(): bool
    {
        return $this->requires->hasDependencies();
    }

    /**
     * Get the list of required extension names.
     *
     * @return list<string>
     */
    public function getDependencies(): array
    {
        return $this->requires->getExtensionNames();
    }

    /**
     * F3.11: return the version constraint declared for a specific
     * dependency in `requires.extensions[name]`. Returns null when
     * the extension is not declared as a dependency at all, or when
     * the dependency has no version constraint (historical "any
     * version" default).
     */
    public function getDependencyVersionConstraint(string $extensionName): ?string
    {
        return $this->requires->getVersionConstraint($extensionName);
    }
}
