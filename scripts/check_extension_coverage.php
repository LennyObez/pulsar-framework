<?php

declare(strict_types=1);

/**
 * CI gate: verify every extension with a src/ directory is listed in
 * PHPStan, Psalm, and composer.json autoload paths.
 *
 * Prevents tooling drift where new extensions skip static analysis.
 *
 * Usage: php scripts/check_extension_coverage.php [--root=<dir>]
 * Exit code: 0 = all covered, 1 = gaps found, 2 = invoked wrongly
 *
 * `--root=` exists so the gate can be pointed at a planted tree that has the gap on
 * purpose and observed refusing it. Without it, the only way to watch this script
 * fail would be to remove a real extension from phpstan.neon, and a gate nobody has
 * ever seen fail is indistinguishable from no gate.
 */

$root = dirname(__DIR__);

foreach (array_slice($argv ?? [], 1) as $argument) {
    if (is_string($argument) && str_starts_with($argument, '--root=')) {
        $root = rtrim(str_replace('\\', '/', substr($argument, strlen('--root='))), '/');

        continue;
    }

    fwrite(STDERR, sprintf("Unknown option: %s\n", is_string($argument) ? $argument : '<non-string>'));

    exit(2);
}

$extensionsDir = $root . '/extensions';

if (!is_dir($extensionsDir)) {
    fwrite(STDERR, "No extensions/ directory under {$root}. Nothing could be checked.\n");

    exit(2);
}

// ── Discover all extensions with src/ directories ──────────────────
// Supports both flat (extensions/foo/) and grouped (extensions/compliance/foo/) layouts.
$extensions = [];

/**
 * @return list<string>
 */
function discoverExtensions(string $baseDir, string $prefix = ''): array
{
    $found = [];
    $entries = scandir($baseDir);

    // scandir() returns false on an unreadable directory. Iterating that used to
    // be a silent no-op, which this gate would report as "no extensions here" —
    // exactly the answer that makes a missing extension look intentional.
    if ($entries === false) {
        fwrite(STDERR, "Cannot read directory: {$baseDir}\n");

        exit(1);
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $fullPath = $baseDir . '/' . $entry;

        if (!is_dir($fullPath)) {
            continue;
        }

        $relativeName = $prefix !== '' ? $prefix . '/' . $entry : $entry;

        if (is_dir($fullPath . '/src')) {
            $found[] = $relativeName;
        } else {
            // Recurse into subdirectory groups (e.g., extensions/compliance/)
            $found = [...$found, ...discoverExtensions($fullPath, $relativeName)];
        }
    }

    return $found;
}

$extensions = discoverExtensions($extensionsDir);
sort($extensions);

// Green by abstention is the failure this gate is most likely to suffer, because it
// is the one that looks like success. A discovery walk that returns nothing means the
// layout changed, the directory moved, or the walk was pointed somewhere wrong — and
// in every one of those cases the answer "all extensions are covered by static
// analysis" is true only because no extension was looked at.
if ($extensions === []) {
    fwrite(STDERR, sprintf(
        "No extension with a src/ directory was found under %s.\n\n"
        . "This is not a pass. Every extension in the repository has one, so an empty result means\n"
        . "the discovery walk found nothing to check — and reporting that as coverage would hide\n"
        . "exactly the drift this gate exists to catch.\n",
        $extensionsDir,
    ));

    exit(1);
}

// ── Check PHPStan ──────────────────────────────────────────────────
$phpstanConfig = file_get_contents($root . '/tools/php/phpstan.neon');

if ($phpstanConfig === false) {
    echo "ERROR: Cannot read tools/php/phpstan.neon\n";
    exit(1);
}

$phpstanMissing = [];

foreach ($extensions as $ext) {
    $pattern = 'extensions/' . $ext . '/src';

    if (strpos($phpstanConfig, $pattern) === false) {
        $phpstanMissing[] = $ext;
    }
}

// ── Check Psalm ────────────────────────────────────────────────────
$psalmConfig = file_get_contents($root . '/tools/php/psalm.xml');

if ($psalmConfig === false) {
    echo "ERROR: Cannot read tools/php/psalm.xml\n";
    exit(1);
}

$psalmMissing = [];

foreach ($extensions as $ext) {
    $pattern = 'extensions/' . $ext . '/src';

    if (strpos($psalmConfig, $pattern) === false) {
        $psalmMissing[] = $ext;
    }
}

// ── Check composer.json autoload ───────────────────────────────────
$composerJson = file_get_contents($root . '/composer.json');

if ($composerJson === false) {
    echo "ERROR: Cannot read composer.json\n";
    exit(1);
}

$composerMissing = [];

foreach ($extensions as $ext) {
    $pattern = 'extensions/' . $ext . '/src/';

    if (strpos($composerJson, $pattern) === false) {
        $composerMissing[] = $ext;
    }
}

// ── Report ─────────────────────────────────────────────────────────
$failed = false;

if ($phpstanMissing !== []) {
    echo "PHPStan missing extensions:\n";

    foreach ($phpstanMissing as $ext) {
        echo "  - extensions/{$ext}/src\n";
    }

    echo "\n";
    $failed = true;
}

if ($psalmMissing !== []) {
    echo "Psalm missing extensions:\n";

    foreach ($psalmMissing as $ext) {
        echo "  - extensions/{$ext}/src\n";
    }

    echo "\n";
    $failed = true;
}

if ($composerMissing !== []) {
    echo "composer.json autoload missing extensions:\n";

    foreach ($composerMissing as $ext) {
        echo "  - extensions/{$ext}/src/\n";
    }

    echo "\n";
    $failed = true;
}

if ($failed) {
    echo "FAIL: Extension coverage gaps detected. Add missing entries to the config files above.\n";
    exit(1);
}

echo 'OK: All ' . count($extensions) . " extensions covered by PHPStan, Psalm, and composer.json autoload.\n";
exit(0);
