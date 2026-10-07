<?php

declare(strict_types=1);

/**
 * SBOM Generator CLI — CycloneDX 1.5 JSON format.
 *
 * Reads composer.json, composer.lock, and pnpm-lock.yaml to produce
 * a Software Bill of Materials in CycloneDX 1.5 JSON format.
 *
 * Required by DORA Art.28 and NIS2 Art.21(d).
 *
 * Usage:
 *   php tools/sbom/generate-sbom.php [--output=path/to/sbom.json]
 *
 * @internal This is a build-time tool, not part of the framework runtime.
 */

use Pulsar\SupplyChain\SbomGenerator;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

(static function (array $argv): void {
    $projectRoot = dirname(__DIR__, 2);

    $outputPath = $projectRoot . '/sbom.cdx.json';

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--output=')) {
            $outputPath = substr($arg, 9);
        }
    }

    $composerJsonPath = $projectRoot . '/composer.json';
    $composerLockPath = $projectRoot . '/composer.lock';
    $pnpmLockPath = $projectRoot . '/pnpm-lock.yaml';

    /** @var array<string, mixed>|null $composerJson */
    $composerJson = is_file($composerJsonPath)
        ? json_decode((string) file_get_contents($composerJsonPath), true)
        : null;

    /** @var array<string, mixed>|null $composerLock */
    $composerLock = is_file($composerLockPath)
        ? json_decode((string) file_get_contents($composerLockPath), true)
        : null;

    $pnpmLockContents = is_file($pnpmLockPath)
        ? (string) file_get_contents($pnpmLockPath)
        : '';

    $generator = new SbomGenerator();
    $sbom = $generator->generate($composerJson, $composerLock, $pnpmLockContents);

    $json = json_encode($sbom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    file_put_contents($outputPath, $json . "\n");

    $components = $sbom['components'] ?? [];
    $count = is_array($components) ? count($components) : 0;
    fprintf(STDOUT, "SBOM generated: %s (%d components)\n", $outputPath, $count);
})($argv ?? []);
