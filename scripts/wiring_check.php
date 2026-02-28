#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Wiring Check Script — verifies PHP classes are actually referenced.
 *
 * Complements boundary_check.php by catching the opposite problem: classes that
 * exist on disk but are never imported or used anywhere, indicating incomplete
 * integration (the "exists but not wired" failure mode).
 *
 * Usage:
 *   php scripts/wiring_check.php                         # Full scan
 *   php scripts/wiring_check.php --diff-base=origin/main # Only check new files
 *   php scripts/wiring_check.php --generate-baseline     # Generate baseline file
 *   php scripts/wiring_check.php --json                  # JSON output
 */

// ---------------------------------------------------------------------------
// WiringAnalyzer — core analysis logic (testable independently)
// ---------------------------------------------------------------------------

final class WiringAnalyzer
{
    /** FQCNs that are composition roots — they wire others, aren't wired themselves. */
    private const array COMPOSITION_ROOTS = [
        'Pulsar\\Core\\Kernel',
        'Pulsar\\Console\\Application',
        'Pulsar\\Console\\Command\\OptimizeCommand',
        'Pulsar\\Console\\Command\\BuildCommand',
    ];

    /** Namespace prefixes for composition roots. */
    private const array COMPOSITION_ROOT_NAMESPACES = [
        'Pulsar\\Core\\Wiring\\',
    ];

    /** Path segments that indicate exempt files. */
    private const array EXEMPT_PATH_SEGMENTS = [
        '/Migration/', // Migrations execute by naming convention
    ];

    /** Class name suffixes that are exempt (discovered by manifest/convention). */
    private const array EXEMPT_SUFFIXES = [
        'ServiceProvider', // Discovered via pulsar.json manifest
    ];

    /** @var array<string, list<string>> FQCN => list of absolute file paths that reference it */
    private array $referenceIndex = [];

    /**
     * Build the reference index by scanning PHP files for all Pulsar/App references.
     *
     * @param list<string> $files Absolute paths to scan for references
     */
    public function buildReferenceIndex(array $files): void
    {
        foreach ($files as $file) {
            $references = $this->extractReferences($file);

            foreach ($references as $fqcn) {
                $this->referenceIndex[$fqcn][] = $file;
            }
        }
    }

    /**
     * Check if a class is referenced by any file other than its own definition.
     */
    public function isWired(string $fqcn, string $definingFile): bool
    {
        if (!isset($this->referenceIndex[$fqcn])) {
            return false;
        }

        $normalizedDefining = str_replace('\\', '/', $definingFile);

        return array_any(
            $this->referenceIndex[$fqcn],
            static fn(string $ref): bool => str_replace('\\', '/', $ref) !== $normalizedDefining,
        );
    }

    /**
     * Check if a class's short name is used in other files within the same directory.
     *
     * Same-namespace classes don't require use statements, so FQCN-based reference
     * tracking misses them. This secondary check catches those references.
     */
    public function isUsedInSameNamespace(string $fqcn, string $definingFile): bool
    {
        $shortName = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
        $directory = dirname($definingFile);

        if (!is_dir($directory)) {
            return false;
        }

        $files = glob($directory . '/*.php');
        if ($files === false) {
            return false;
        }

        $normalizedDefining = str_replace('\\', '/', $definingFile);

        foreach ($files as $file) {
            if (str_replace('\\', '/', $file) === $normalizedDefining) {
                continue;
            }

            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            if (preg_match('/\b' . preg_quote($shortName, '/') . '\b/', $code) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a class/file is exempt from wiring checks.
     */
    public function isExempt(string $fqcn, string $relativePath): bool
    {
        // Composition root classes
        if (in_array($fqcn, self::COMPOSITION_ROOTS, true)) {
            return true;
        }

        // Composition root namespaces
        if (array_any(self::COMPOSITION_ROOT_NAMESPACES, static fn(string $p): bool => str_starts_with($fqcn, $p))) {
            return true;
        }

        // Path segments (migrations, etc.)
        $normalized = str_replace('\\', '/', $relativePath);
        if (array_any(self::EXEMPT_PATH_SEGMENTS, static fn(string $s): bool => str_contains($normalized, $s))) {
            return true;
        }

        // Class name suffixes (ServiceProvider, etc.)
        return array_any(self::EXEMPT_SUFFIXES, static fn(string $s): bool => str_ends_with($fqcn, $s));
    }

    /**
     * Extract the FQCN declared in a PHP file (first class/interface/enum/trait).
     */
    public function extractDeclaredFqcn(string $filePath): ?string
    {
        $code = file_get_contents($filePath);
        if ($code === false) {
            return null;
        }

        // Extract namespace
        if (preg_match('/namespace\s+([^;{]+)/', $code, $matches) !== 1) {
            return null;
        }

        $namespace = trim($matches[1]);

        // Extract type name via tokenizer
        $tokens = token_get_all($code);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] !== T_CLASS && $token[0] !== T_INTERFACE && $token[0] !== T_ENUM && $token[0] !== T_TRAIT) {
                continue;
            }

            // Walk forward past whitespace and modifiers to find the name
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    return $namespace . '\\' . $tokens[$j][1];
                }

                // Stop if we hit something unexpected
                if (is_array($tokens[$j])
                    && $tokens[$j][0] !== T_WHITESPACE
                    && $tokens[$j][0] !== T_ABSTRACT
                    && $tokens[$j][0] !== T_FINAL
                    && $tokens[$j][0] !== T_READONLY
                ) {
                    break;
                }
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Reference extraction
    // -----------------------------------------------------------------------

    /**
     * Extract all Pulsar/App FQCNs referenced in a PHP file.
     *
     * @return list<string>
     */
    private function extractReferences(string $filePath): array
    {
        $code = file_get_contents($filePath);
        if ($code === false) {
            return [];
        }

        $tokens = token_get_all($code);
        $count = count($tokens);
        $references = [];
        $seen = [];
        $nestingLevel = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '{') {
                $nestingLevel++;

                continue;
            }

            if ($token === '}') {
                $nestingLevel--;

                continue;
            }

            // Fully-qualified name: \Pulsar\Foo\Bar or Pulsar\Foo\Bar::class
            if (is_array($token) && $token[0] === T_NAME_FULLY_QUALIFIED) {
                $name = ltrim($token[1], '\\');
                if ((str_starts_with($name, 'Pulsar\\') || str_starts_with($name, 'App\\'))
                    && !isset($seen[$name])
                ) {
                    $references[] = $name;
                    $seen[$name] = true;
                }

                continue;
            }

            // Use statements (namespace level only)
            if (is_array($token) && $token[0] === T_USE && $nestingLevel <= 1) {
                $parsed = $this->parseUseStatement($tokens, $i, $count);

                foreach ($parsed as $fqcn) {
                    if ((str_starts_with($fqcn, 'Pulsar\\') || str_starts_with($fqcn, 'App\\'))
                        && !isset($seen[$fqcn])
                    ) {
                        $references[] = $fqcn;
                        $seen[$fqcn] = true;
                    }
                }
            }
        }

        return $references;
    }

    /**
     * Parse a use statement starting at the T_USE token position.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<string>
     */
    private function parseUseStatement(array $tokens, int &$i, int $count): array
    {
        $i++;

        // Skip whitespace
        while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            $i++;
        }

        // Skip "use function" and "use const"
        if ($i < $count && is_array($tokens[$i])) {
            if ($tokens[$i][0] === T_FUNCTION || $tokens[$i][0] === T_CONST) {
                while ($i < $count && (!is_string($tokens[$i]) || $tokens[$i] !== ';')) {
                    $i++;
                }

                return [];
            }
        }

        $prefix = '';
        $names = [];
        $current = '';
        $inGroup = false;

        while ($i < $count && $tokens[$i] !== ';') {
            $token = $tokens[$i];

            if ($token === '{') {
                $prefix = $current;
                $current = '';
                $inGroup = true;
                $i++;

                continue;
            }

            if ($token === '}') {
                if ($current !== '') {
                    $names[] = $prefix . $current;
                    $current = '';
                }
                $inGroup = false;
                $i++;

                continue;
            }

            if ($token === ',') {
                if ($current !== '') {
                    $names[] = $inGroup ? $prefix . $current : $current;
                    $current = '';
                }
                $i++;

                continue;
            }

            if (is_array($token) && $token[0] === T_AS) {
                if ($current !== '') {
                    $names[] = $inGroup ? $prefix . $current : $current;
                    $current = '';
                }
                $i++;
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }
                if ($i < $count && is_array($tokens[$i])
                    && ($tokens[$i][0] === T_STRING || $tokens[$i][0] === T_NAME_QUALIFIED)
                ) {
                    $i++;
                }

                continue;
            }

            if (is_array($token)) {
                if ($token[0] === T_NAME_QUALIFIED || $token[0] === T_STRING || $token[0] === T_NS_SEPARATOR) {
                    $current .= $token[1];
                }
            }

            $i++;
        }

        if ($current !== '') {
            $names[] = $inGroup ? $prefix . $current : $current;
        }

        return $names;
    }
}

// ---------------------------------------------------------------------------
// CLI entry point
// ---------------------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    return;
}

$scriptFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
$thisFile = realpath(__FILE__);
if ($scriptFile !== false && $thisFile !== false && $scriptFile !== $thisFile) {
    return;
}

$rootDir = dirname(__DIR__);
$baselinePath = $rootDir . '/tools/php/wiring-baseline.json';

// Parse CLI arguments
$jsonOutput = in_array('--json', $argv, true);
$generateBaseline = in_array('--generate-baseline', $argv, true);
$diffBase = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--diff-base=')) {
        $diffBase = substr($arg, strlen('--diff-base='));
    }
}

// Determine new files (added in diff) — wiring check only targets new files in PR mode
$newFiles = [];
if ($diffBase !== null) {
    $command = sprintf(
        'git -C %s diff --name-only --diff-filter=A %s...HEAD 2>/dev/null',
        escapeshellarg($rootDir),
        escapeshellarg($diffBase),
    );
    $output = [];
    exec($command, $output, $exitCode);
    if ($exitCode === 0) {
        $newFiles = array_filter($output, static fn(string $f): bool => str_ends_with($f, '.php'));
        $newFiles = array_values($newFiles);
    } else {
        fwrite(STDERR, "Warning: Could not resolve diff-base '$diffBase'. Running full scan.\n");
        $diffBase = null;
    }
}

// Load baseline
$baseline = [];
if (!$generateBaseline && file_exists($baselinePath)) {
    $data = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($data['unwired'] ?? [] as $entry) {
        $baseline[$entry['fqcn']] = true;
    }
}

// ---------------------------------------------------------------------------
// Discover PHP files
// ---------------------------------------------------------------------------

/** @return list<string> */
function discoverPhpFiles(string $directory): array
{
    $files = [];

    if (!is_dir($directory)) {
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Skip test fixtures
        if (str_contains($file->getPathname(), 'Fixture')) {
            continue;
        }

        $files[] = $file->getPathname();
    }

    return $files;
}

// Phase 1: Discover all PHP files for the reference index
// (scan everything — src, extensions, tests, config, scripts — so test references count)
$indexDirectories = [
    $rootDir . '/src',
    $rootDir . '/extensions',
    $rootDir . '/tests',
    $rootDir . '/config',
    $rootDir . '/scripts',
];

$allFiles = [];
foreach ($indexDirectories as $dir) {
    $allFiles = [...$allFiles, ...discoverPhpFiles($dir)];
}

// Also include bin/pulsar if it exists
$binPulsar = $rootDir . '/bin/pulsar';
if (file_exists($binPulsar)) {
    $allFiles[] = $binPulsar;
}

// Phase 2: Build reference index
$analyzer = new WiringAnalyzer();
$analyzer->buildReferenceIndex($allFiles);

// Phase 3: Scan production files and check wiring
$productionDirectories = [
    $rootDir . '/src',
    $rootDir . '/extensions',
];

$productionFiles = [];
foreach ($productionDirectories as $dir) {
    $productionFiles = [...$productionFiles, ...discoverPhpFiles($dir)];
}

$normalizedRoot = str_replace('\\', '/', $rootDir) . '/';
$unwired = [];
$scannedClasses = 0;

foreach ($productionFiles as $filePath) {
    $normalizedPath = str_replace('\\', '/', $filePath);
    $relativePath = str_starts_with($normalizedPath, $normalizedRoot)
        ? substr($normalizedPath, strlen($normalizedRoot))
        : $normalizedPath;

    // In diff-base mode, only check new files
    if ($diffBase !== null) {
        $isNew = false;
        foreach ($newFiles as $newFile) {
            if (str_replace('\\', '/', $newFile) === $relativePath) {
                $isNew = true;

                break;
            }
        }
        if (!$isNew) {
            continue;
        }
    }

    $fqcn = $analyzer->extractDeclaredFqcn($filePath);
    if ($fqcn === null) {
        continue;
    }

    $scannedClasses++;

    // Check exemptions
    if ($analyzer->isExempt($fqcn, $relativePath)) {
        continue;
    }

    // Check baseline
    if (isset($baseline[$fqcn])) {
        continue;
    }

    // Check wiring: FQCN-based (use statements, FQ references), then same-namespace
    if (!$analyzer->isWired($fqcn, $filePath) && !$analyzer->isUsedInSameNamespace($fqcn, $filePath)) {
        $unwired[] = [
            'file' => $relativePath,
            'fqcn' => $fqcn,
        ];
    }
}

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

// Generate baseline mode
if ($generateBaseline) {
    // Sort for deterministic output
    usort($unwired, static fn(array $a, array $b): int => $a['fqcn'] <=> $b['fqcn']);

    $baselineData = [
        'generated' => date('c'),
        'unwired' => $unwired,
    ];

    $json = json_encode($baselineData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    file_put_contents($baselinePath, $json);

    $count = count($unwired);
    echo "Baseline generated: $baselinePath\n";
    echo "  Unwired classes baselined: $count\n";
    echo "  Classes scanned: $scannedClasses\n";
    exit(0);
}

$unwiredCount = count($unwired);

// JSON output
if ($jsonOutput) {
    echo json_encode([
        'scanned' => $scannedClasses,
        'unwired' => $unwiredCount,
        'details' => $unwired,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($unwiredCount > 0 ? 1 : 0);
}

// Human-readable output
foreach ($unwired as $entry) {
    echo "\n";
    echo "UNWIRED CLASS [ERROR]\n";
    echo "  File:  {$entry['file']}\n";
    echo "  Class: {$entry['fqcn']}\n";
    echo "  Fix:   Import this class where it's used, or add to wiring baseline if intentional\n";
}

if ($unwiredCount > 0) {
    echo "\n";
}

echo "Found $unwiredCount unwired class(es) in $scannedClasses classes scanned.\n";

exit($unwiredCount > 0 ? 1 : 0);
