#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Boundary Enforcement Script — #[Api] targeting for cross-module imports.
 *
 * Complements Deptrac (structural namespace fences) with semantic API surface
 * enforcement: cross-module imports must target classes marked with #[Api].
 *
 * Usage:
 *   php scripts/boundary_check.php                         # Full scan, warnings only
 *   php scripts/boundary_check.php --diff-base=origin/main # Errors for changed files
 *   php scripts/boundary_check.php --generate-baseline     # Generate baseline file
 *   php scripts/boundary_check.php --strict                # All violations are errors
 *   php scripts/boundary_check.php --json                  # JSON output
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Pulsar\Api\Api;

// ---------------------------------------------------------------------------
// BoundaryAnalyzer — core analysis logic (testable independently)
// ---------------------------------------------------------------------------

final class BoundaryAnalyzer
{
    /** FQCNs that are composition roots — exempt from cross-module rules. */
    private const array COMPOSITION_ROOTS = [
        'Pulsar\\Core\\Kernel',
        'Pulsar\\Console\\Application',
    ];

    /** The Api/Internal attribute FQCNs are always accessible. */
    private const array ALWAYS_ACCESSIBLE = [
        'Pulsar\\Api\\Api',
        'Pulsar\\Api\\Internal',
    ];

    /** @var array<string, true> Indexed set of #[Api] class FQCNs */
    private array $apiClasses = [];

    /** @var array<string, true> Indexed set of #[Internal] class FQCNs */
    private array $internalClasses = [];

    /** @var array<string, true> Reflection cache for classes not in snapshot */
    private array $reflectionCache = [];

    public function __construct(string $snapshotPath)
    {
        if (file_exists($snapshotPath)) {
            $data = json_decode(
                (string) file_get_contents($snapshotPath),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            foreach (array_keys($data['api_classes'] ?? []) as $fqcn) {
                $this->apiClasses[$fqcn] = true;
            }

            foreach ($data['internal_classes'] ?? [] as $fqcn) {
                $this->internalClasses[$fqcn] = true;
            }
        }
    }

    // ---------------------------------------------------------------
    // Classification functions
    // ---------------------------------------------------------------

    /**
     * Extract the module boundary from a FQCN.
     *
     * Returns module identity: "Auth", "Extension\\Payments", "App", or null.
     */
    public function extractModule(string $fqcn): ?string
    {
        // App namespace
        if (str_starts_with($fqcn, 'App\\') || str_starts_with($fqcn, 'Pulsar\\App\\')) {
            return 'App';
        }

        // Extension: Pulsar\Extension\{Name}\...
        if (preg_match('/^Pulsar\\\\Extension\\\\([^\\\\]+)/', $fqcn, $matches) === 1) {
            return 'Extension\\' . $matches[1];
        }

        // Core: Pulsar\{Module}\...
        if (preg_match('/^Pulsar\\\\([^\\\\]+)/', $fqcn, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check if a file path points to a composition root.
     */
    public function isCompositionRoot(string $fqcn): bool
    {
        return in_array($fqcn, self::COMPOSITION_ROOTS, true);
    }

    /**
     * Check if a FQCN is in an \Internal\ namespace.
     */
    public function isInternalNamespace(string $fqcn): bool
    {
        return str_contains($fqcn, '\\Internal\\');
    }

    /**
     * Check if a class has the #[Api] attribute.
     *
     * Primary: snapshot lookup. Fallback: reflection. Null if unresolvable.
     */
    public function hasApiAttribute(string $fqcn): ?bool
    {
        // Snapshot lookup (O(1))
        if (isset($this->apiClasses[$fqcn])) {
            return true;
        }

        if (isset($this->internalClasses[$fqcn])) {
            return false;
        }

        // Check reflection cache
        if (array_key_exists($fqcn, $this->reflectionCache)) {
            return $this->reflectionCache[$fqcn];
        }

        // Reflection fallback
        if (class_exists($fqcn, false) || interface_exists($fqcn, false) || enum_exists($fqcn, false)) {
            try {
                $ref = new ReflectionClass($fqcn);
                $hasApi = $ref->getAttributes(Api::class) !== [];
                $this->reflectionCache[$fqcn] = $hasApi;

                return $hasApi;
            } catch (Throwable) {
                $this->reflectionCache[$fqcn] = null;

                return null;
            }
        }

        // Try autoloading
        try {
            if (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn)) {
                $ref = new ReflectionClass($fqcn);
                $hasApi = $ref->getAttributes(Api::class) !== [];
                $this->reflectionCache[$fqcn] = $hasApi;

                return $hasApi;
            }
        } catch (Throwable) {
            // Cannot load class
        }

        $this->reflectionCache[$fqcn] = null;

        return null;
    }

    /**
     * Check if a FQCN belongs to an extension.
     */
    public function isExtension(string $fqcn): bool
    {
        return str_starts_with($fqcn, 'Pulsar\\Extension\\');
    }

    /**
     * Check if a FQCN is App code.
     */
    public function isAppCode(string $fqcn): bool
    {
        return str_starts_with($fqcn, 'App\\') || str_starts_with($fqcn, 'Pulsar\\App\\');
    }

    /**
     * Check if a file path is under extensions/.
     */
    public function isExtensionFile(string $relativePath): bool
    {
        $normalized = str_replace('\\', '/', $relativePath);

        return str_starts_with($normalized, 'extensions/');
    }

    /**
     * Check if a file is in the changed files list.
     *
     * @param list<string> $changedFiles
     */
    public function isChangedFile(string $relativePath, array $changedFiles): bool
    {
        $normalized = str_replace('\\', '/', $relativePath);

        foreach ($changedFiles as $changed) {
            $changedNormalized = str_replace('\\', '/', $changed);
            if ($normalized === $changedNormalized) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------
    // Scanning
    // ---------------------------------------------------------------

    /**
     * Scan a PHP file for boundary violations.
     *
     * @param list<string> $changedFiles Files changed since diff-base
     * @param array<string, true> $baseline Keyed by "file|import" for suppression
     * @return list<array{file: string, line: int, import: string, rule: string, severity: string, fix: string}>
     */
    public function scanFile(
        string $filePath,
        string $rootDir,
        array $changedFiles,
        array $baseline,
        bool $strict,
        bool $hasDiffBase,
    ): array {
        $violations = [];

        $code = file_get_contents($filePath);
        if ($code === false) {
            return [];
        }

        $normalizedRoot = str_replace('\\', '/', $rootDir) . '/';
        $normalizedPath = str_replace('\\', '/', $filePath);
        $relativePath = str_starts_with($normalizedPath, $normalizedRoot)
            ? substr($normalizedPath, strlen($normalizedRoot))
            : $normalizedPath;

        // Extract source namespace + class for module identification
        $sourceNamespace = $this->extractNamespaceFromCode($code);
        if ($sourceNamespace === null) {
            return [];
        }

        $sourceClass = $sourceNamespace . '\\' . basename($filePath, '.php');

        // Composition roots are exempt
        if ($this->isCompositionRoot($sourceClass)) {
            return [];
        }

        $sourceModule = $this->extractModule($sourceClass);
        if ($sourceModule === null) {
            return [];
        }

        // Extract all Pulsar class references
        $references = $this->extractReferences($filePath);

        foreach ($references as [$ref, $line]) {
            // Same module — always allowed
            $targetModule = $this->extractModule($ref);
            if ($targetModule === null || $targetModule === $sourceModule) {
                continue;
            }

            // Api/Internal attributes are always accessible
            if (in_array($ref, self::ALWAYS_ACCESSIBLE, true)) {
                continue;
            }

            // Rule 1: Cross-module \Internal\ namespace import
            if ($this->isInternalNamespace($ref)) {
                $baselineKey = $relativePath . '|' . $ref;
                if (isset($baseline[$baselineKey])) {
                    continue;
                }

                $violations[] = [
                    'file' => $relativePath,
                    'line' => $line,
                    'import' => $ref,
                    'rule' => 'cross_module_internal',
                    'severity' => 'error',
                    'fix' => 'Depend on the module\'s public interface instead of \\Internal\\ implementation',
                ];

                continue;
            }

            // Rule 3: Extension → App dependency
            if ($this->isExtension($sourceClass) && $this->isAppCode($ref)) {
                $baselineKey = $relativePath . '|' . $ref;
                if (isset($baseline[$baselineKey])) {
                    continue;
                }

                $violations[] = [
                    'file' => $relativePath,
                    'line' => $line,
                    'import' => $ref,
                    'rule' => 'extension_depends_on_app',
                    'severity' => 'error',
                    'fix' => 'Extensions must not depend on App code — use interfaces or events',
                ];

                continue;
            }

            // Rule 4: Cross-extension internal import
            // ($targetModule !== $sourceModule is guaranteed by the early continue on line 269)
            if ($this->isExtension($sourceClass) && $this->isExtension($ref)) {
                // Cross-extension imports should target #[Api] classes
                $hasApi = $this->hasApiAttribute($ref);
                if ($hasApi === false || $hasApi === null) {
                    $baselineKey = $relativePath . '|' . $ref;
                    if (isset($baseline[$baselineKey])) {
                        continue;
                    }

                    $violations[] = [
                        'file' => $relativePath,
                        'line' => $line,
                        'import' => $ref,
                        'rule' => 'cross_extension_non_api',
                        'severity' => 'error',
                        'fix' => 'Cross-extension imports must target #[Api] classes',
                    ];

                    continue;
                }
            }

            // Rule 2: Cross-module import of non-#[Api] class
            $hasApi = $this->hasApiAttribute($ref);
            if ($hasApi === true) {
                continue; // #[Api] — allowed
            }

            if ($hasApi === null) {
                continue; // Unresolvable — skip, don't false-positive
            }

            // Determine severity
            $baselineKey = $relativePath . '|' . $ref;
            if (isset($baseline[$baselineKey])) {
                continue; // Suppressed by baseline
            }

            if ($strict) {
                $severity = 'error';
            } elseif ($this->isExtensionFile($relativePath)) {
                $severity = 'error'; // Extensions always get errors
            } elseif ($hasDiffBase && $this->isChangedFile($relativePath, $changedFiles)) {
                $severity = 'error'; // Changed files get errors
            } else {
                $severity = 'warning'; // Legacy files get warnings
            }

            $violations[] = [
                'file' => $relativePath,
                'line' => $line,
                'import' => $ref,
                'rule' => 'cross_module_non_api',
                'severity' => $severity,
                'fix' => 'Add #[Api] to ' . $ref . ', or move the import within the same module',
            ];
        }

        return $violations;
    }

    /**
     * Extract namespace declaration from PHP code.
     */
    private function extractNamespaceFromCode(string $code): ?string
    {
        if (preg_match('/namespace\s+([^;{]+)/', $code, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extract all Pulsar class references from a PHP file with line numbers.
     *
     * @return list<array{0: string, 1: int}> [fqcn, line]
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

            // Fully-qualified name: \Pulsar\Foo\Bar
            if (is_array($token) && $token[0] === T_NAME_FULLY_QUALIFIED) {
                $name = ltrim($token[1], '\\');
                if (str_starts_with($name, 'Pulsar\\') || str_starts_with($name, 'App\\')) {
                    $key = $name;
                    if (!isset($seen[$key])) {
                        $references[] = [$name, $token[2]];
                        $seen[$key] = true;
                    }
                }

                continue;
            }

            // Use statements (namespace level only)
            if (is_array($token) && $token[0] === T_USE && $nestingLevel <= 1) {
                $useLine = $token[2];
                $parsed = $this->parseUseStatement($tokens, $i, $count);

                foreach ($parsed as $fqcn) {
                    if (str_starts_with($fqcn, 'Pulsar\\') || str_starts_with($fqcn, 'App\\')) {
                        $key = $fqcn;
                        if (!isset($seen[$key])) {
                            $references[] = [$fqcn, $useLine];
                            $seen[$key] = true;
                        }
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
                    && ($tokens[$i][0] === T_STRING || $tokens[$i][0] === T_NAME_QUALIFIED)) {
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

// Only run CLI when this script is the main entry point
$scriptFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
$thisFile = realpath(__FILE__);
if ($scriptFile !== false && $thisFile !== false && $scriptFile !== $thisFile) {
    return;
}

$rootDir = dirname(__DIR__);
$snapshotPath = $rootDir . '/tools/api/public-api.snapshot.json';
$baselinePath = $rootDir . '/tools/php/boundary-baseline.json';

// Parse CLI arguments
$jsonOutput = in_array('--json', $argv, true);
$strict = in_array('--strict', $argv, true);
$generateBaseline = in_array('--generate-baseline', $argv, true);
$diffBase = null;
$hasDiffBase = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--diff-base=')) {
        $diffBase = substr($arg, strlen('--diff-base='));
        $hasDiffBase = true;
    }
}

// Determine changed files
$changedFiles = [];
if ($diffBase !== null) {
    $command = sprintf(
        'git -C %s diff --name-only --diff-filter=ACMR %s...HEAD 2>/dev/null',
        escapeshellarg($rootDir),
        escapeshellarg($diffBase),
    );
    $output = [];
    exec($command, $output, $exitCode);
    if ($exitCode === 0) {
        $changedFiles = array_filter($output, static fn (string $f): bool => str_ends_with($f, '.php'));
        $changedFiles = array_values($changedFiles);
    } else {
        fwrite(STDERR, "Warning: Could not resolve diff-base '$diffBase'. Running full scan.\n");
        $hasDiffBase = false;
    }
}

// Load baseline
$baseline = [];
if (!$generateBaseline && file_exists($baselinePath)) {
    $data = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($data['violations'] ?? [] as $entry) {
        $key = $entry['file'] . '|' . $entry['import'];
        $baseline[$key] = true;
    }
}

// Initialize analyzer
$analyzer = new BoundaryAnalyzer($snapshotPath);

// Discover PHP files
$directories = [
    $rootDir . '/src',
    $rootDir . '/extensions',
];

$allViolations = [];
$scannedFiles = 0;

foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $filePath = $file->getPathname();

        // Skip test fixtures
        if (str_contains($filePath, 'Fixture')) {
            continue;
        }

        $scannedFiles++;

        $fileViolations = $analyzer->scanFile(
            $filePath,
            $rootDir,
            $changedFiles,
            $baseline,
            $strict,
            $hasDiffBase,
        );

        foreach ($fileViolations as $v) {
            $allViolations[] = $v;
        }
    }
}

// Generate baseline mode
if ($generateBaseline) {
    $baselineData = [
        'generated' => date('c'),
        'violations' => array_map(
            static fn (array $v): array => [
                'file' => $v['file'],
                'import' => $v['import'],
                'rule' => $v['rule'],
            ],
            $allViolations,
        ),
    ];

    // Deduplicate by file+import
    $seen = [];
    $unique = [];
    foreach ($baselineData['violations'] as $entry) {
        $key = $entry['file'] . '|' . $entry['import'];
        if (!isset($seen[$key])) {
            $unique[] = $entry;
            $seen[$key] = true;
        }
    }
    $baselineData['violations'] = $unique;

    // Sort for deterministic output
    usort($baselineData['violations'], static function (array $a, array $b): int {
        return ($a['file'] <=> $b['file']) ?: ($a['import'] <=> $b['import']);
    });

    $json = json_encode($baselineData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    file_put_contents($baselinePath, $json);

    $count = count($baselineData['violations']);
    echo "Baseline generated: $baselinePath\n";
    echo "  Violations baselined: $count\n";
    echo "  Files scanned: $scannedFiles\n";
    exit(0);
}

// Count by severity
$errors = array_filter($allViolations, static fn (array $v): bool => $v['severity'] === 'error');
$warnings = array_filter($allViolations, static fn (array $v): bool => $v['severity'] === 'warning');
$errorCount = count($errors);
$warningCount = count($warnings);

// JSON output
if ($jsonOutput) {
    echo json_encode([
        'scanned' => $scannedFiles,
        'errors' => $errorCount,
        'warnings' => $warningCount,
        'violations' => $allViolations,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($errorCount > 0 ? 1 : 0);
}

// Human-readable output
$ruleLabels = [
    'cross_module_internal' => 'Cross-module internal namespace import',
    'cross_module_non_api' => 'Cross-module import of non-#[Api] class',
    'extension_depends_on_app' => 'Extension depends on App code',
    'cross_extension_non_api' => 'Cross-extension import of non-#[Api] class',
];

foreach ($allViolations as $v) {
    $severityLabel = strtoupper($v['severity']);
    $ruleLabel = $ruleLabels[$v['rule']] ?? $v['rule'];

    echo "\n";
    echo "BOUNDARY VIOLATION [$severityLabel]\n";
    echo "  File:   {$v['file']}:{$v['line']}\n";
    echo "  Import: {$v['import']}\n";
    echo "  Rule:   $ruleLabel\n";
    echo "  Fix:    {$v['fix']}\n";
}

if ($errorCount > 0 || $warningCount > 0) {
    echo "\n";
}

echo "Found $errorCount error(s) and $warningCount warning(s) in $scannedFiles files scanned.\n";

exit($errorCount > 0 ? 1 : 0);
