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
     * Typed loosely because the input comes from json_decode of an untrusted
     * pulsar.json on disk; each access is validated below.
     *
     * @param array<string, mixed> $data
     * @throws ManifestException If required fields are missing or invalid
     */
    #[NoDiscard]
    public static function fromArray(array $data, string $basePath = ''): self
    {
        $name = $data['name'] ?? null;
        if (!is_string($name)) {
            throw ManifestException::missingField('name', $basePath);
        }

        $version = $data['version'] ?? null;
        if (!is_string($version)) {
            throw ManifestException::missingField('version', $basePath);
        }

        $extensionClass = $data['extension_class'] ?? null;
        if (!is_string($extensionClass)) {
            throw ManifestException::missingField('extension_class', $basePath);
        }

        if (!preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/', $version)) {
            throw ManifestException::invalidVersion($version, $basePath);
        }

        $description = $data['description'] ?? '';
        $pulsar = $data['pulsar'] ?? [];
        $provides = $data['provides'] ?? [];
        $requires = $data['requires'] ?? [];
        $trustTier = $data['trust_tier'] ?? '';

        return new self(
            name: $name,
            version: $version,
            extensionClass: $extensionClass,
            path: $basePath,
            description: is_string($description) ? $description : '',
            pulsar: PulsarVersionConfig::fromArray(is_array($pulsar) ? $pulsar : []),
            provides: ProvidesConfig::fromArray(is_array($provides) ? $provides : []),
            requires: RequiresConfig::fromArray(is_array($requires) ? $requires : []),
            requestedTrustTier: TrustTier::tryFrom(is_string($trustTier) ? $trustTier : '') ?? TrustTier::Community,
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
