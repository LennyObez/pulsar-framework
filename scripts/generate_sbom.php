<?php

declare(strict_types=1);

/**
 * Generate a CycloneDX 1.5 SBOM (Software Bill of Materials).
 *
 * Usage:
 *   php scripts/generate_sbom.php [output-path]
 *
 * Default output: sbom.json in the project root.
 *
 * This is the documented entry point (docs/compliance-ccf.md); the CI provenance
 * workflow calls tools/sbom/generate-sbom.php. Both now run the same code.
 *
 * They did not. This script used to carry its own 200-line CycloneDX builder that
 * read composer.lock by hand, while the framework already owned
 * {@see \Pulsar\SupplyChain\SbomGenerator} — reachable only through the other
 * entry point. Two independent implementations of one artefact is not redundancy,
 * it is a supply-chain hazard: the SBOM a human generates from the documentation
 * and the SBOM CI attests differ, and nothing compares them. The duplicate also
 * omitted the JavaScript dependency tree entirely, so the documented command
 * produced a materially incomplete bill of materials, and its unchecked
 * `(string) $package['name']` casts would have written the literal text "Array"
 * into a component name rather than failing.
 */

use Pulsar\SupplyChain\SbomGenerator;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Read a JSON object, failing loudly rather than handing null to the generator.
 *
 * Deliberately not Pulsar\Tooling\Support\JsonDocument, which the sibling dev
 * scripts use: that class lives under autoload-dev, and this command is documented
 * for compliance operators (docs/compliance-ccf.md, DORA Art.28 / NIS2 Art.21(d)).
 * Running it against a production checkout installed with --no-dev must work, so it
 * cannot reach for a dev-only class. SbomGenerator itself is in src/ and is fine.
 *
 * @return array<string, mixed>
 */
$readJsonObject = static function (string $path): array {
    $contents = file_get_contents($path);

    if ($contents === false) {
        fwrite(STDERR, "Cannot read {$path}\n");

        exit(1);
    }

    /** @var mixed $decoded */
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        fwrite(STDERR, "{$path} did not decode to a JSON object\n");

        exit(1);
    }

    $object = [];

    /** @var mixed $value */
    foreach ($decoded as $key => $value) {
        if (is_string($key)) {
            $object[$key] = $value;
        }
    }

    return $object;
};

(static function (array $arguments) use ($readJsonObject): void {
    $projectRoot = dirname(__DIR__);

    // $arguments[0] is the script itself; the first positional argument is the
    // output path, per the documented usage.
    $outputPath = $projectRoot . '/sbom.json';

    foreach (array_slice($arguments, 1) as $argument) {
        if (is_string($argument) && !str_starts_with($argument, '-')) {
            $outputPath = $argument;

            break;
        }
    }

    $composerJsonPath = $projectRoot . '/composer.json';
    $composerLockPath = $projectRoot . '/composer.lock';
    $pnpmLockPath = $projectRoot . '/pnpm-lock.yaml';

    if (!is_file($composerLockPath)) {
        fwrite(STDERR, "composer.lock not found at: {$composerLockPath}\n");

        exit(1);
    }

    $composerJson = is_file($composerJsonPath)
        ? $readJsonObject($composerJsonPath)
        : null;

    $composerLock = $readJsonObject($composerLockPath);

    $pnpmLockContents = is_file($pnpmLockPath)
        ? (string) file_get_contents($pnpmLockPath)
        : '';

    $sbom = new SbomGenerator()->generate($composerJson, $composerLock, $pnpmLockContents);

    $json = json_encode($sbom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    file_put_contents($outputPath, $json . "\n");

    $components = $sbom['components'] ?? [];
    fprintf(STDOUT, "SBOM generated: %s (%d components)\n", $outputPath, is_array($components) ? count($components) : 0);
})($argv ?? []);
