<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use NoDiscard;
use Pulsar\Api\Api;

use function array_key_exists;
use function file_get_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

/**
 * Auto-discovers Pulsar extensions from Composer's installed packages.
 *
 * Scans "vendor/star/composer.json" for packages declaring the
 * "extra.pulsar.extension" key, which should map to an extension
 * class implementing ExtensionInterface.
 *
 * This enables zero-configuration extension registration: install a
 * Composer package and the framework discovers it automatically.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ExtensionAutoDiscovery
{
    public function __construct(
        private string $vendorPath,
    ) {}

    /**
     * Discover all Pulsar extensions declared in installed Composer packages.
     *
     * Reads the Composer installed.json manifest to avoid directory scanning.
     * Falls back to directory-based scanning if the manifest is unavailable.
     *
     * @return list<DiscoveredExtension>
     */
    #[NoDiscard]
    public function discover(): array
    {
        $installedJsonPath = $this->vendorPath . DIRECTORY_SEPARATOR . 'composer'
            . DIRECTORY_SEPARATOR . 'installed.json';

        if (is_file($installedJsonPath)) {
            return $this->discoverFromInstalledJson($installedJsonPath);
        }

        return $this->discoverFromDirectoryScan();
    }

    /**
     * Discover extensions from Composer's installed.json manifest.
     *
     * @return list<DiscoveredExtension>
     */
    private function discoverFromInstalledJson(string $installedJsonPath): array
    {
        $content = file_get_contents($installedJsonPath);

        if ($content === false) {
            return [];
        }

        /** @var mixed $data */
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        // Composer 2.x wraps packages in a "packages" key
        /** @var list<array<string, mixed>> $packages */
        $packages = [];
        if (is_array($data)) {
            $packagesValue = $data['packages'] ?? null;
            if (is_array($packagesValue)) {
                /** @var list<array<string, mixed>> $packages */
                $packages = array_values(array_filter($packagesValue, 'is_array'));
            } elseif (!isset($data['packages'])) {
                /** @var list<array<string, mixed>> $packages */
                $packages = array_values(array_filter($data, 'is_array'));
            }
        }

        $discovered = [];

        foreach ($packages as $package) {
            $extension = $this->extractExtensionFromPackage($package);

            if ($extension !== null) {
                $discovered[] = $extension;
            }
        }

        return $discovered;
    }

    /**
     * Discover extensions by scanning vendor directories for composer.json files.
     *
     * @return list<DiscoveredExtension>
     */
    private function discoverFromDirectoryScan(): array
    {
        $discovered = [];

        if (!is_dir($this->vendorPath)) {
            return [];
        }

        $vendors = scandir($this->vendorPath);

        if ($vendors === false) {
            return [];
        }

        foreach ($vendors as $vendor) {
            if ($vendor === '.' || $vendor === '..' || $vendor === 'composer' || $vendor === 'bin') {
                continue;
            }

            $vendorDir = $this->vendorPath . DIRECTORY_SEPARATOR . $vendor;

            if (!is_dir($vendorDir)) {
                continue;
            }

            $packages = scandir($vendorDir);

            if ($packages === false) {
                continue;
            }

            foreach ($packages as $packageName) {
                if ($packageName === '.' || $packageName === '..') {
                    continue;
                }

                $composerJson = $vendorDir . DIRECTORY_SEPARATOR . $packageName
                    . DIRECTORY_SEPARATOR . 'composer.json';

                if (!is_file($composerJson)) {
                    continue;
                }

                $content = file_get_contents($composerJson);

                if ($content === false) {
                    continue;
                }

                /** @var array<string, mixed> $data */
                $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

                $extension = $this->extractExtensionFromPackage($data);

                if ($extension !== null) {
                    $discovered[] = $extension;
                }
            }
        }

        return $discovered;
    }

    /**
     * Extract a DiscoveredExtension from a Composer package data array.
     *
     * @param array<string, mixed> $package
     */
    private function extractExtensionFromPackage(array $package): ?DiscoveredExtension
    {
        if (!isset($package['extra']) || !is_array($package['extra'])) {
            return null;
        }

        /** @var array<string, mixed> $extra */
        $extra = $package['extra'];

        if (!isset($extra['pulsar']) || !is_array($extra['pulsar'])) {
            return null;
        }

        /** @var array<string, mixed> $pulsar */
        $pulsar = $extra['pulsar'];

        if (!array_key_exists('extension', $pulsar) || !is_string($pulsar['extension'])) {
            return null;
        }

        /** @var string $packageName */
        $packageName = isset($package['name']) && is_string($package['name'])
            ? $package['name']
            : 'unknown/unknown';

        /** @var class-string<ExtensionInterface> $extensionClass */
        $extensionClass = $pulsar['extension'];

        return new DiscoveredExtension(
            packageName: $packageName,
            extensionClass: $extensionClass,
        );
    }
}
