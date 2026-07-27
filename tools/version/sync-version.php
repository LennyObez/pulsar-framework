<?php

declare(strict_types=1);

/**
 * Regenerates the compile-time version fallback constants in
 * src/Core/Version.php from the single source of truth: composer.json "version".
 *
 * The runtime PRIMARY source stays Composer\InstalledVersions (see
 * Version::full()); these constants are only the fallback for environments
 * running without the Composer autoloader, and must be GENERATED, never
 * hand-edited, so they can never disagree with composer.json.
 *
 * Usage:
 *   php tools/version/sync-version.php            # rewrite the constants
 *   php tools/version/sync-version.php --check     # exit 1 on drift (CI guard)
 */

$root = dirname(__DIR__, 2);
$composerPath = $root . '/composer.json';
$versionPath = $root . '/src/Core/Version.php';

$composerRaw = file_get_contents($composerPath);

if ($composerRaw === false) {
    fwrite(STDERR, "Cannot read composer.json\n");
    exit(1);
}

/** @var array<string, mixed> $composer */
$composer = json_decode($composerRaw, true, 512, JSON_THROW_ON_ERROR);
$version = is_string($composer['version'] ?? null) ? $composer['version'] : '';

if (preg_match('/^(\d+)\.(\d+)\.(\d+)(-[0-9A-Za-z.-]+)?$/', $version, $m) !== 1) {
    fwrite(STDERR, "composer.json version '{$version}' is not a valid semver string\n");
    exit(1);
}

$major = (int) $m[1];
$minor = (int) $m[2];
$patch = (int) $m[3];
$suffix = $m[4] ?? '';

$source = file_get_contents($versionPath);

if ($source === false) {
    fwrite(STDERR, "Cannot read src/Core/Version.php\n");
    exit(1);
}

$updated = $source;
$updated = preg_replace('/public const int MAJOR = \d+;/', "public const int MAJOR = {$major};", $updated);
$updated = preg_replace('/public const int MINOR = \d+;/', "public const int MINOR = {$minor};", $updated);
$updated = preg_replace('/public const int PATCH = \d+;/', "public const int PATCH = {$patch};", $updated);
$updated = preg_replace(
    "/public const string PRERELEASE_SUFFIX = '[^']*';/",
    "public const string PRERELEASE_SUFFIX = '{$suffix}';",
    $updated,
);

if (!is_string($updated)) {
    fwrite(STDERR, "Failed to rewrite version constants\n");
    exit(1);
}

$check = in_array('--check', $argv, true);

if ($check) {
    if ($updated !== $source) {
        fwrite(
            STDERR,
            "src/Core/Version.php constants are out of sync with composer.json ({$version}). Run `composer version:sync`.\n",
        );
        exit(1);
    }

    echo "Version constants are in sync with composer.json ({$version}).\n";
    exit(0);
}

if ($updated === $source) {
    echo "Version constants already in sync with composer.json ({$version}).\n";
    exit(0);
}

if (file_put_contents($versionPath, $updated) === false) {
    fwrite(STDERR, "Cannot write src/Core/Version.php\n");
    exit(1);
}

echo "Synced src/Core/Version.php to {$version}.\n";
exit(0);
