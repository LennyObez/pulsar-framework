<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use DirectoryIterator;
use JsonException;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Core\Version;
use Pulsar\Extensibility\Exception\DependencyException;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\Exception\ManifestException;

use function class_exists;
use function count;
use function dirname;
use function file_exists;
use function file_get_contents;
use function in_array;
use function is_a;
use function json_decode;
use function json_validate;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Discovers and validates extension manifests.
 */
#[Internal]
final class ExtensionLoader
{
    private const string MANIFEST_FILENAME = 'pulsar.json';

    /**
     * Discover extension manifests in the given paths.
     *
     * Each path may be:
     *   - A parent directory containing extension subdirectories (e.g. `extensions/`)
     *   - An individual extension directory containing a `pulsar.json` directly
     *
     * @param list<string> $paths Directories to scan for extensions
     * @return list<ExtensionManifest>
     * @throws ManifestException If a manifest is invalid
     */
    public function discover(array $paths): array
    {
        $manifests = [];
        $seen = [];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            // If the path itself contains a manifest, load it directly
            $directManifest = $path . DIRECTORY_SEPARATOR . self::MANIFEST_FILENAME;

            if (file_exists($directManifest)) {
                $manifest = $this->readManifest($directManifest);

                if (!isset($seen[$manifest->name])) {
                    $manifests[] = $manifest;
                    $seen[$manifest->name] = true;
                }

                continue;
            }

            // Otherwise scan for extension subdirectories
            foreach ($this->scanDirectory($path) as $manifest) {
                if (!isset($seen[$manifest->name])) {
                    $manifests[] = $manifest;
                    $seen[$manifest->name] = true;
                }
            }
        }

        return $manifests;
    }

    /**
     * Scan a directory for extension manifests.
     *
     * Checks each subdirectory for a pulsar.json manifest. If a subdirectory
     * does not contain a manifest, it is scanned recursively (one level) to
     * support grouped layouts like extensions/compliance/dora/.
     *
     * @return list<ExtensionManifest>
     */
    private function scanDirectory(string $directory): array
    {
        $manifests = [];
        $iterator = new DirectoryIterator($directory);

        foreach ($iterator as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            $manifestPath = $item->getPathname() . DIRECTORY_SEPARATOR . self::MANIFEST_FILENAME;

            if (file_exists($manifestPath)) {
                $manifests[] = $this->readManifest($manifestPath);
            } else {
                // Recurse into subdirectory groups (e.g., extensions/compliance/)
                $manifests = [...$manifests, ...$this->scanDirectory($item->getPathname())];
            }
        }

        return $manifests;
    }

    /**
     * Validate that a manifest is compatible with the current framework version.
     *
     * @throws ManifestException If incompatible
     */
    public function validateCompatibility(ExtensionManifest $manifest): void
    {
        if (!$manifest->pulsar->isSatisfiedByCurrent()) {
            throw ManifestException::incompatibleFrameworkVersion(
                $manifest->name,
                $manifest->pulsar->minVersion,
                $manifest->pulsar->maxVersion,
                Version::short(),
            );
        }
    }

    /**
     * Validate that the extension class exists and implements ExtensionInterface.
     *
     * @throws ExtensionException If the class is invalid
     */
    public function validateExtensionClass(ExtensionManifest $manifest): void
    {
        $class = $manifest->extensionClass;

        if (!class_exists($class)) {
            throw ManifestException::extensionClassNotFound($class, $manifest->path);
        }

        if (!is_subclass_of($class, ExtensionInterface::class)) {
            throw ExtensionException::invalidExtensionClass($class);
        }
    }

    /**
     * Resolve the load order for extensions based on dependencies.
     *
     * Returns manifests sorted in dependency order (dependencies first).
     *
     * @param list<ExtensionManifest> $manifests
     * @return list<ExtensionManifest>
     * @throws DependencyException If dependencies cannot be resolved
     */
    public function resolveDependencies(array $manifests): array
    {
        // Build lookup map
        $byName = [];
        foreach ($manifests as $manifest) {
            $byName[$manifest->name] = $manifest;
        }

        // Validate every dependency twice — first that the required
        // extension is present, then that its version satisfies the
        // constraint declared in the requiring manifest's
        // `requires.extensions` map. Presence alone is not enough: an
        // extension declaring `requires: { auth: ^1.0 }` would load
        // happily against `auth: 2.0` and BC-breaking changes would
        // sneak through. Constraints are parsed with Composer's Semver
        // so the syntax matches what manifest authors already know.
        /** @var list<string> $skipped */
        $skipped = [];
        $manifests = array_filter($manifests, function (ExtensionManifest $manifest) use ($byName, &$skipped): bool {
            foreach ($manifest->getDependencies() as $dependency) {
                if (!isset($byName[$dependency])) {
                    $skipped[] = $manifest->name . ' (requires ' . $dependency . ')';

                    return false;
                }

                $constraint = $manifest->getDependencyVersionConstraint($dependency);

                if ($constraint !== null && $constraint !== '*' && $constraint !== '') {
                    $providedVersion = $byName[$dependency]->version;

                    if (!\Composer\Semver\Semver::satisfies($providedVersion, $constraint)) {
                        $skipped[] = sprintf(
                            '%s (requires %s %s, found %s)',
                            $manifest->name,
                            $dependency,
                            $constraint,
                            $providedVersion,
                        );

                        return false;
                    }
                }
            }

            return true;
        });

        // Rebuild lookup after filtering
        $byName = [];
        foreach ($manifests as $manifest) {
            $byName[$manifest->name] = $manifest;
        }

        if ($skipped !== []) {
            error_log('Extensions skipped due to missing dependencies: ' . implode(', ', $skipped));
        }

        // Topological sort using Kahn's algorithm
        return $this->topologicalSort(array_values($manifests), $byName);
    }

    /**
     * Perform topological sort on manifests.
     *
     * @param list<ExtensionManifest> $manifests
     * @param array<string, ExtensionManifest> $byName
     * @return list<ExtensionManifest>
     * @throws DependencyException If circular dependency detected
     */
    private function topologicalSort(array $manifests, array $byName): array
    {
        // Calculate in-degrees (number of dependencies)
        $inDegree = [];
        $dependents = []; // Map of extension -> extensions that depend on it

        foreach ($manifests as $manifest) {
            $name = $manifest->name;
            $inDegree[$name] ??= 0;
            $dependents[$name] ??= [];

            foreach ($manifest->getDependencies() as $dependency) {
                $inDegree[$name]++;
                $dependents[$dependency][] = $name;
            }
        }

        // Start with extensions that have no dependencies
        $queue = [];
        foreach ($manifests as $manifest) {
            if ($inDegree[$manifest->name] === 0) {
                $queue[] = $manifest->name;
            }
        }

        $sorted = [];
        $visited = 0;

        while ($queue !== []) {
            $current = array_shift($queue);
            $sorted[] = $byName[$current];
            $visited++;

            foreach ($dependents[$current] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        // If we didn't visit all nodes, there's a cycle
        if ($visited !== count($manifests)) {
            // Find the cycle for error reporting
            $remaining = array_values(array_filter(
                array_keys($inDegree),
                fn(string $name) => !in_array($byName[$name], $sorted, true),
            ));
            throw DependencyException::circularDependency($remaining);
        }

        return $sorted;
    }

    /**
     * Load and instantiate an extension from its manifest.
     *
     * @throws ExtensionException If instantiation fails
     */
    public function instantiate(ExtensionManifest $manifest): ExtensionInterface
    {
        $class = $manifest->extensionClass;

        if (!class_exists($class) || !is_a($class, ExtensionInterface::class, true)) {
            throw ExtensionException::registrationFailed(
                $manifest->name,
                'Extension class does not exist or does not implement ExtensionInterface: ' . $class,
            );
        }

        /** @var class-string<ExtensionInterface> $class */
        return new $class();
    }

    /**
     * Read a manifest from a pulsar.json on disk.
     *
     * Lives here rather than on ExtensionManifest. A manifest is a value — every
     * field a string, an enum or a nested config value — and it was only ever
     * classified as a service because a static factory on it read a file. Loading
     * is this class's job; parsing an array the manifest already knew how to do.
     *
     * @throws ManifestException If the file cannot be read or parsed
     */
    #[NoDiscard]
    public function readManifest(string $path): ExtensionManifest
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

        return ExtensionManifest::fromArray($data, dirname($path));
    }
}
