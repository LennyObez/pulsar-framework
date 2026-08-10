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
use function is_array;
use function is_string;
use function json_validate;

/**
 * Readonly DTO representing an extension's pulsar.json manifest.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ExtensionManifest
{
    /**
     * @param array<string, string> $autoload Explicit PSR-4 map (namespace prefix with
     *        trailing `\` => absolute source directory). Empty means "derive from
     *        extension_class + path"; see {@see self::autoloadMap()}.
     */
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
        public array $autoload = [],
        public ExtensionKind $kind = ExtensionKind::Infrastructure,
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

        /** @var mixed $description */
        $description = $data['description'] ?? '';
        /** @var mixed $pulsar */
        $pulsar = $data['pulsar'] ?? [];
        /** @var mixed $provides */
        $provides = $data['provides'] ?? [];
        /** @var mixed $requires */
        $requires = $data['requires'] ?? [];
        /** @var mixed $trustTier */
        $trustTier = $data['trust_tier'] ?? '';
        /** @var mixed $autoload */
        $autoload = $data['autoload'] ?? [];
        /** @var mixed $kind */
        $kind = $data['kind'] ?? null;

        return new self(
            name: $name,
            version: $version,
            extensionClass: $extensionClass,
            path: $basePath,
            description: is_string($description) ? $description : '',
            pulsar: PulsarVersionConfig::fromArray(self::ensureStringKeyed($pulsar)),
            provides: ProvidesConfig::fromArray(self::ensureStringKeyed($provides)),
            requires: RequiresConfig::fromArray(self::ensureStringKeyed($requires)),
            requestedTrustTier: TrustTier::tryFrom(is_string($trustTier) ? $trustTier : '') ?? TrustTier::Community,
            autoload: self::parseAutoload($autoload, $basePath),
            kind: ExtensionKind::fromManifest($kind),
        );
    }

    /**
     * Parse a manifest's `autoload.psr-4` map, resolving relative source
     * directories against the manifest's base path.
     *
     * @return array<string, string> Namespace prefix => absolute source directory
     */
    private static function parseAutoload(mixed $autoload, string $basePath): array
    {
        if (!is_array($autoload)) {
            return [];
        }

        /** @var mixed $psr4 */
        $psr4 = $autoload['psr-4'] ?? null;

        if (!is_array($psr4)) {
            return [];
        }

        $map = [];

        /** @var mixed $directory */
        foreach ($psr4 as $prefix => $directory) {
            if (!is_string($prefix) || !is_string($directory)) {
                continue;
            }

            $relative = rtrim($directory, '/\\');
            $resolved = $basePath !== ''
                ? $basePath . DIRECTORY_SEPARATOR . $relative
                : $relative;
            $map[$prefix] = $resolved;
        }

        return $map;
    }

    /**
     * Resolve the PSR-4 autoload map used to register this extension's classes.
     *
     * Returns the explicit `autoload.psr-4` map when the manifest declares one;
     * otherwise derives the conventional single mapping from the extension class
     * namespace to the extension's `src/` directory
     * (e.g. `Pulsar\Extension\Payments\` => `<path>/src`).
     *
     * @return array<string, string> Namespace prefix (trailing `\`) => absolute source directory
     */
    public function autoloadMap(): array
    {
        if ($this->autoload !== []) {
            return $this->autoload;
        }

        $separator = strrpos($this->extensionClass, '\\');

        if ($separator === false) {
            return [];
        }

        $namespace = substr($this->extensionClass, 0, $separator) . '\\';

        return [$namespace => $this->path . DIRECTORY_SEPARATOR . 'src'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ensureStringKeyed(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        /** @var mixed $val */
        foreach ($value as $key => $val) {
            if (is_string($key)) {
                $result = [...$result, $key => $val];
            }
        }

        return $result;
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
     * Return the version constraint declared for a specific dependency
     * in `requires.extensions[name]`. Returns null when the extension
     * is not declared as a dependency at all, and also when it is
     * declared without a constraint — both cases mean "any version",
     * so callers must not read null as "not a dependency".
     */
    public function getDependencyVersionConstraint(string $extensionName): ?string
    {
        return $this->requires->getVersionConstraint($extensionName);
    }
}
