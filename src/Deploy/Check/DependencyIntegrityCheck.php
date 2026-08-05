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
 * Compares composer.lock against vendor/composer/installed.json.
 *
 * For every locked package the check compares the version string, the
 * `dist.reference` (the exact commit the archive was cut from) and the
 * `dist.shasum`. That detects a vendor tree installed from a different lock
 * revision, and an upstream re-tag that keeps the version string while moving
 * the commit behind it — a supply chain integrity signal required by DORA
 * Art.28 and NIS2 Art.21(d). Composer leaves `shasum` empty for VCS dists, so
 * the reference is usually the field that carries the comparison.
 *
 * It does not re-hash the files on disk. Both sides of the comparison are
 * Composer's own records, so an edit made directly to a file under vendor/
 * leaves both unchanged and passes this check; that class of tampering is what
 * the integrity manifest covers. Path repositories record neither field and are
 * compared by version only.
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
        return 'Compares composer.lock versions, dist references and checksums against vendor/composer/installed.json';
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
        /** @var mixed $lockedRaw */
        $lockedRaw = $lockData['packages'] ?? null;
        $lockedPackages = $this->extractPackages(is_array($lockedRaw) ? $lockedRaw : []);

        // Composer 2 format: { "packages": [...], "dev": true, ... }
        /** @var mixed $installedRaw */
        $installedRaw = $installedData['packages'] ?? null;
        $installedPackages = $this->extractPackages(is_array($installedRaw) ? $installedRaw : $installedData);

        $mismatches = [];

        foreach ($lockedPackages as $name => $locked) {
            if (!isset($installedPackages[$name])) {
                $mismatches[] = sprintf('%s (missing)', $name);
                continue;
            }

            $installed = $installedPackages[$name];

            if ($installed['version'] !== $locked['version']) {
                $mismatches[] = sprintf(
                    '%s (locked: %s, installed: %s)',
                    $name,
                    $locked['version'],
                    $installed['version'],
                );
                continue;
            }

            // A re-tagged upstream release keeps its version string while the
            // commit and the archive behind it change, so the version
            // comparison alone cannot see it. Compared only when both sides
            // carry the field: Composer leaves `shasum` empty for VCS dists and
            // records neither field for path repositories.
            foreach (['reference' => 'dist reference', 'shasum' => 'dist checksum'] as $field => $label) {
                // isset() rather than a null comparison: both keys are optional, so a path
                // repository — which records neither — reached the comparison through an
                // undefined index and warned twice per package before answering.
                if (
                    isset($locked[$field], $installed[$field])
                    && $locked[$field] !== $installed[$field]
                ) {
                    $mismatches[] = sprintf(
                        '%s (%s differs: locked %s, installed %s)',
                        $name,
                        $label,
                        $locked[$field],
                        $installed[$field],
                    );
                }
            }
        }

        return $mismatches;
    }

    /**
     * @param array<mixed> $packages Raw package list from a lock or installed file
     * @return array<string, array{version: string, reference: string|null, shasum: string|null}>
     */
    private function extractPackages(array $packages): array
    {
        $extracted = [];

        /** @var mixed $package */
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            /** @var mixed $name */
            $name = $package['name'] ?? null;
            /** @var mixed $version */
            $version = $package['version'] ?? null;

            if (!is_string($name) || !is_string($version)) {
                continue;
            }

            /** @var mixed $dist */
            $dist = $package['dist'] ?? null;
            $dist = is_array($dist) ? $dist : [];

            /** @var mixed $reference */
            $reference = $dist['reference'] ?? null;
            /** @var mixed $shasum */
            $shasum = $dist['shasum'] ?? null;

            $extracted[$name] = [
                'version' => $version,
                'reference' => is_string($reference) && $reference !== '' ? $reference : null,
                'shasum' => is_string($shasum) && $shasum !== '' ? $shasum : null,
            ];
        }

        return $extracted;
    }
}
