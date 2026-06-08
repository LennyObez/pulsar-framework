<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

use function file_get_contents;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function sprintf;

/**
 * Verifies that installed Composer packages match composer.lock.
 *
 * Detects unauthorized modifications to vendor/ without updating
 * the lock file: a supply chain integrity control required by
 * DORA Art.28 and NIS2 Art.21(d).
 */
#[Internal]
final readonly class DependencyIntegrityCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'dependency-integrity';

    public function __construct(
        private string $projectRoot,
    ) {}

    #[Override]
    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Verifies installed packages match composer.lock checksums';
    }

    #[Override]
    public function check(string $environment): CheckResult
    {
        $lockPath = $this->projectRoot . '/composer.lock';

        if (!is_file($lockPath)) {
            return CheckResult::error(
                self::CHECK_NAME,
                'composer.lock not found',
                ['Run "composer install" to generate the lock file.'],
            );
        }

        $lockContents = file_get_contents($lockPath);

        if ($lockContents === false) {
            return CheckResult::error(
                self::CHECK_NAME,
                'Unable to read composer.lock',
                ['Check file permissions on composer.lock.'],
            );
        }

        /** @var array<string, mixed>|null $lockData */
        $lockData = json_decode($lockContents, true);

        if (!is_array($lockData)) {
            return CheckResult::error(
                self::CHECK_NAME,
                'Invalid composer.lock format',
                ['Re-run "composer install" to regenerate the lock file.'],
            );
        }

        $vendorDir = $this->projectRoot . '/vendor';

        if (!is_dir($vendorDir)) {
            return CheckResult::error(
                self::CHECK_NAME,
                'vendor/ directory not found',
                ['Run "composer install" to install dependencies.'],
            );
        }

        $installedPath = $vendorDir . '/composer/installed.json';

        if (!is_file($installedPath)) {
            return CheckResult::error(
                self::CHECK_NAME,
                'vendor/composer/installed.json not found',
                ['Run "composer install" to restore the vendor directory.'],
            );
        }

        $installedContents = file_get_contents($installedPath);

        if ($installedContents === false) {
            return CheckResult::error(
                self::CHECK_NAME,
                'Unable to read vendor/composer/installed.json',
                ['Check file permissions on vendor/composer/installed.json.'],
            );
        }

        /** @var array<string, mixed>|null $installedData */
        $installedData = json_decode($installedContents, true);

        if (!is_array($installedData)) {
            return CheckResult::error(
                self::CHECK_NAME,
                'Invalid vendor/composer/installed.json format',
                ['Run "composer install" to restore the vendor directory.'],
            );
        }

        $mismatches = $this->comparePackages($lockData, $installedData);

        if ($mismatches === []) {
            return CheckResult::pass(
                self::CHECK_NAME,
                'All installed packages match composer.lock',
            );
        }

        $mismatchList = implode(', ', $mismatches);

        return match ($environment) {
            'production' => CheckResult::error(
                self::CHECK_NAME,
                sprintf('Package integrity mismatch: %s', $mismatchList),
                [
                    'Run "composer install --no-dev" to restore packages from the lock file.',
                    'Investigate whether vendor/ was modified outside of Composer.',
                    'This may indicate a supply chain compromise.',
                ],
            ),
            default => CheckResult::warning(
                self::CHECK_NAME,
                sprintf('Package integrity mismatch: %s', $mismatchList),
                [
                    'Run "composer install" to restore packages from the lock file.',
                ],
            ),
        };
    }

    /**
     * Compare locked packages against installed packages.
     *
     * @param array<string, mixed> $lockData
     * @param array<string, mixed> $installedData
     * @return list<string> Names of mismatched packages
     */
    public function comparePackages(array $lockData, array $installedData): array
    {
        $lockedPackages = $this->extractLockedVersions($lockData);
        $installedPackages = $this->extractInstalledVersions($installedData);

        $mismatches = [];

        foreach ($lockedPackages as $name => $version) {
            if (!isset($installedPackages[$name])) {
                $mismatches[] = sprintf('%s (missing)', $name);
            } elseif ($installedPackages[$name] !== $version) {
                $mismatches[] = sprintf('%s (locked: %s, installed: %s)', $name, $version, $installedPackages[$name]);
            }
        }

        return $mismatches;
    }

    /**
     * @param array<string, mixed> $lockData
     * @return array<string, string> package name → version
     */
    private function extractLockedVersions(array $lockData): array
    {
        $versions = [];

        /** @var mixed $packagesRaw */
        $packagesRaw = $lockData['packages'] ?? null;
        $packages = is_array($packagesRaw) ? $packagesRaw : [];

        /** @var mixed $package */
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            /** @var mixed $name */
            $name = $package['name'] ?? null;
            /** @var mixed $version */
            $version = $package['version'] ?? null;

            if (is_string($name) && is_string($version)) {
                $versions[$name] = $version;
            }
        }

        return $versions;
    }

    /**
     * @param array<string, mixed> $installedData
     * @return array<string, string> package name → version
     */
    private function extractInstalledVersions(array $installedData): array
    {
        $versions = [];

        // Composer 2 format: { "packages": [...], "dev": true, ... }
        /** @var mixed $packagesRaw */
        $packagesRaw = $installedData['packages'] ?? null;
        $packages = is_array($packagesRaw) ? $packagesRaw : $installedData;

        /** @var mixed $package */
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            /** @var mixed $name */
            $name = $package['name'] ?? null;
            /** @var mixed $version */
            $version = $package['version'] ?? null;

            if (is_string($name) && is_string($version)) {
                $versions[$name] = $version;
            }
        }

        return $versions;
    }
}
