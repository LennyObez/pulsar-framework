<?php

declare(strict_types=1);

/**
 * Preloading Optimizer — generates an opcache.preload file.
 *
 * Analyzes OPcache status to identify hot-path classes loaded on >80%
 * of requests. Generates an optimized preload script that includes
 * only these high-frequency classes to maximize memory efficiency.
 *
 * Usage:
 *   php tools/php/generate-preload.php [--threshold=80] [--output=var/cache/preload.php] [--base-path=.]
 *
 * Options:
 *   --threshold  Minimum inclusion percentage (default: 80)
 *   --output     Output file path (default: var/cache/preload.php)
 *   --base-path  Project root for resolving relative paths (default: .)
 *   --dry-run    Print class list without writing file
 */

$options = getopt('', ['threshold:', 'output:', 'base-path:', 'dry-run']);

$threshold = (int) ($options['threshold'] ?? 80);
$outputPath = $options['output'] ?? 'var/cache/preload.php';
$basePath = realpath($options['base-path'] ?? '.') ?: '.';
$dryRun = isset($options['dry-run']);

if (!function_exists('opcache_get_status')) {
    fwrite(STDERR, "OPcache extension is not loaded.\n");
    fwrite(STDERR, "Falling back to static analysis of src/ directory.\n\n");

    $classes = generateFromStaticAnalysis($basePath, $threshold);
} else {
    $status = opcache_get_status(true);

    if ($status === false || !isset($status['scripts']) || $status['scripts'] === []) {
        fwrite(STDERR, "OPcache has no cached scripts. Falling back to static analysis.\n\n");
        $classes = generateFromStaticAnalysis($basePath, $threshold);
    } else {
        $classes = generateFromOpcacheStatus($status, $basePath, $threshold);
    }
}

if ($classes === []) {
    fwrite(STDERR, "No classes met the threshold. Try lowering --threshold.\n");
    exit(1);
}

sort($classes);

fprintf(STDOUT, "Found %d classes for preloading (threshold: %d%%)\n", count($classes), $threshold);

if ($dryRun) {
    foreach ($classes as $file) {
        fprintf(STDOUT, "  %s\n", $file);
    }

    exit(0);
}

$content = generatePreloadFile($classes, $basePath);

$dir = dirname($outputPath);

if (!is_dir($dir)) {
    mkdir($dir, 0o755, true);
}

file_put_contents($outputPath, $content);
fprintf(STDOUT, "Written to %s\n", $outputPath);

// --- Functions ---

/**
 * @param array{scripts: array<string, array{hits: int, timestamp: int}>} $status
 * @return list<string>
 */
function generateFromOpcacheStatus(array $status, string $basePath, int $threshold): array
{
    $scripts = $status['scripts'];
    $totalHits = 0;
    $maxHits = 0;

    foreach ($scripts as $script) {
        $totalHits += $script['hits'];

        if ($script['hits'] > $maxHits) {
            $maxHits = $script['hits'];
        }
    }

    if ($maxHits === 0) {
        return [];
    }

    $hotFiles = [];
    $hitThreshold = (int) ($maxHits * ($threshold / 100.0));

    foreach ($scripts as $path => $script) {
        if ($script['hits'] < $hitThreshold) {
            continue;
        }

        // Only include project files, not vendor
        $realPath = realpath($path);

        if ($realPath === false) {
            continue;
        }

        if (!str_starts_with($realPath, $basePath . DIRECTORY_SEPARATOR . 'src')) {
            continue;
        }

        $hotFiles[] = $realPath;
    }

    return $hotFiles;
}

/**
 * @return list<string>
 */
function generateFromStaticAnalysis(string $basePath, int $threshold): array
{
    $srcDir = $basePath . DIRECTORY_SEPARATOR . 'src';

    if (!is_dir($srcDir)) {
        return [];
    }

    $hotPaths = [
        'Http' . DIRECTORY_SEPARATOR,
        'Routing' . DIRECTORY_SEPARATOR,
        'Container' . DIRECTORY_SEPARATOR,
        'Config' . DIRECTORY_SEPARATOR,
        'Core' . DIRECTORY_SEPARATOR,
        'Security' . DIRECTORY_SEPARATOR . 'Session' . DIRECTORY_SEPARATOR,
        'Security' . DIRECTORY_SEPARATOR . 'Csrf' . DIRECTORY_SEPARATOR,
        'Database' . DIRECTORY_SEPARATOR,
        'View' . DIRECTORY_SEPARATOR,
        'Api' . DIRECTORY_SEPARATOR,
    ];

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relativePath = str_replace($srcDir . DIRECTORY_SEPARATOR, '', $file->getRealPath());

        // Include files in hot-path directories
        foreach ($hotPaths as $hotPath) {
            if (str_starts_with($relativePath, $hotPath)) {
                // Skip Internal namespace files — they're implementation details
                // that may not be loaded on every request
                if (str_contains($relativePath, DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR)) {
                    continue 2;
                }

                $files[] = $file->getRealPath();

                continue 2;
            }
        }
    }

    return $files;
}

/**
 * @param list<string> $files
 */
function generatePreloadFile(array $files, string $basePath): string
{
    $lines = ["<?php\n"];
    $lines[] = "declare(strict_types=1);\n";
    $lines[] = '';
    $lines[] = '// Auto-generated by Pulsar preload optimizer. Do not edit.';
    $lines[] = '// Generated: ' . date('Y-m-d H:i:s');
    $lines[] = '// Files: ' . count($files);
    $lines[] = '';

    foreach ($files as $file) {
        $relative = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        $lines[] = "require_once __DIR__ . '/../../{$relative}';";
    }

    $lines[] = '';

    return implode("\n", $lines);
}
