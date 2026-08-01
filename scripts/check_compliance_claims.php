<?php

declare(strict_types=1);

/**
 * CI gate: verify that key compliance claims in docs/COMPLIANCE.md have
 * corresponding implementations in the codebase.
 *
 * Checks that interfaces and classes referenced in the "Pulsar Capability"
 * column actually exist. This prevents claims overstatement where a doc
 * references capabilities that don't exist in code.
 *
 * Usage: php scripts/check_compliance_claims.php
 * Exit code: 0 = all verified, 1 = missing implementations found
 */

$root = dirname(__DIR__);
$complianceFile = $root . '/docs/compliance-matrix.md';

if (!is_file($complianceFile)) {
    echo "ERROR: docs/compliance-matrix.md not found.\n";
    exit(1);
}

$content = file_get_contents($complianceFile);

if ($content === false) {
    echo "ERROR: Cannot read docs/compliance-matrix.md\n";
    exit(1);
}

// Extract backtick-quoted identifiers that look like PHP class/interface names
preg_match_all('/`([A-Z][A-Za-z0-9]+(?:Interface)?)`/', $content, $matches);
$identifiers = array_unique($matches[1]);
sort($identifiers);

// Known non-class identifiers to skip:
// - PHP builtins (WeakMap)
// - Module/namespace names used as references (Queue, Deploy, etc.)
// - Cookie attribute names (HttpOnly, SameSite, Secure)
// - Algorithm names (Argon2id, XSalsa20)
// - Test class references
$skipList = [
    'Argon2id',
    'Deploy',
    'FeatureFlag',
    'HttpOnly',
    'Idempotency',
    'Integrity',
    'PublicApiSnapshotTest',
    'Queue',
    'SameSite',
    'Secure',
    'Strict',
    'Tenancy',
    'WeakMap',
    'XSalsa20',
];

$srcDirs = [
    $root . '/src',
    $root . '/extensions',
];

/**
 * Recursively find all PHP files in a directory.
 *
 * @return list<string>
 */
function findPhpFiles(string $dir): array
{
    $files = [];

    if (!is_dir($dir)) {
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );

    // RecursiveIteratorIterator is typed as yielding mixed. An instanceof check
    // narrows it honestly, where an inline @var would only assert it.
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }

        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

// Build a set of all defined class/interface/enum/trait names in the codebase
$definedTypes = [];

foreach ($srcDirs as $dir) {
    foreach (findPhpFiles($dir) as $phpFile) {
        $fileContent = file_get_contents($phpFile);

        if ($fileContent === false) {
            continue;
        }

        // Match class, interface, enum, trait declarations
        if (preg_match_all(
            '/(?:class|interface|enum|trait)\s+([A-Z][A-Za-z0-9]+)/',
            $fileContent,
            $typeMatches,
        )) {
            foreach ($typeMatches[1] as $typeName) {
                $definedTypes[$typeName] = true;
            }
        }
    }
}

$missing = [];
$found = 0;

foreach ($identifiers as $identifier) {
    // Skip known non-class identifiers
    if (in_array($identifier, $skipList, true)) {
        continue;
    }

    // Skip identifiers that don't look like class names
    if (strlen($identifier) < 4) {
        continue;
    }

    if (isset($definedTypes[$identifier])) {
        $found++;
    } else {
        $missing[] = $identifier;
    }
}

// ── Report ─────────────────────────────────────────────────────────
if ($missing !== []) {
    echo "Compliance claims reference these types that were not found in src/ or extensions/:\n\n";

    foreach ($missing as $m) {
        echo "  - {$m}\n";
    }

    echo "\nFound: {$found} | Missing: " . count($missing) . "\n";
    echo "\nFAIL: Some compliance doc references have no matching implementation.\n";
    echo "Either implement the missing type or correct the docs/COMPLIANCE.md reference.\n";
    exit(1);
}

echo "OK: All " . $found . " compliance capability references verified in codebase.\n";
exit(0);
